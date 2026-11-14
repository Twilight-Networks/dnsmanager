<?php
/**
 * Datei: tests/ui/records/dyndns_flag.php
 * Zweck: Prüft RecordReadService::getZoneRecordView() → isDyndns (U4-Core, E3) gegen die Zuordnung des DynDNS-Schutzes:
 *        Für jeden A/AAAA-Record wird der Zustand berechnet, den dyndnsCheckRecordChange() verwendet (genau ein strikter
 *        Account, kein loser Treffer, kein Systemrecord, dyndnsAttributeAddressRecord() = MANAGED mit diesem Record),
 *        und mit isDyndns verglichen. Dazu Erwartungen je Szenario, Rechte (zoneadmin) und keine Account-Daten im Ergebnis.
 * Aufruf (über run.sh matrix): php dyndns_flag.php RR|RC
 */
require __DIR__ . '/../lib.php';
require APP . '/web/inc/app/Actor.php';
require APP . '/web/inc/app/ZoneAccess.php';
require APP . '/web/inc/app/records/RecordReadService.php';

$iso = ($argv[1] ?? 'RR') === 'RC' ? 'READ COMMITTED' : 'REPEATABLE READ';
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL $iso");
$svc = new RecordReadService($pdo, new ZoneAccess($pdo));
$admin = new Actor(1, 'u0admin', 'admin', '127.0.0.1', 'cli');
$zoneadmin = new Actor(2, 'u0zone', 'zoneadmin', '127.0.0.1', 'cli');
$zones = [1 => 'u0.test', 2 => 'other.u0.test'];

$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };

/** Zustand nach dem DynDNS-Schutz (wie dyndnsCheckRecordChange, Teil „Verlassen“) */
function guardManaged(PDO $pdo, int $zoneId, string $zoneName, array $rec): bool
{
    $pdo->beginTransaction();
    try {
        $accounts = dyndnsLoadZoneAccounts($pdo, $zoneId);
        $res = dyndnsFindOwnerReservations($pdo, $zoneId, $zoneName, (string)$rec['name'], $accounts);
        if (count($res['strict']) !== 1 || $res['loose'] !== [] || $rec['server_id'] !== null) { return false; }
        $acc = $res['strict'][0];
        $st = $pdo->prepare('SELECT id, name, type, content, server_id FROM records WHERE zone_id = ? AND type = ?');
        $st->execute([$zoneId, $rec['type']]);
        $attr = dyndnsAttributeAddressRecord($st->fetchAll(PDO::FETCH_ASSOC), $zoneName, (string)$acc['hostname'], $rec['type'],
            $rec['type'] === 'A' ? ($acc['current_ipv4'] ?? null) : ($acc['current_ipv6'] ?? null));
        return $attr['state'] === 'MANAGED' && (int)$attr['record']['id'] === (int)$rec['id'];
    } finally {
        $pdo->rollBack();
    }
}

/** Szenario: zurücksetzen, SQL anwenden, alle A/AAAA-Records beider Zonen gegen den Schutz prüfen */
function scenario(string $id, array $sql, array $expect, string $msg): void
{
    global $pdo, $svc, $admin, $zones, $ok;
    resetDb();
    foreach ($sql as $s) { sqlx($s); }
    $mismatch = []; $flags = [];
    foreach ($zones as $zid => $zname) {
        $view = $svc->getZoneRecordView($admin, $zid);
        $st = $pdo->prepare('SELECT id, name, type, content, server_id FROM records WHERE zone_id = ? AND type IN (\'A\', \'AAAA\')');
        $st->execute([$zid]);
        $byId = array_column($view['records'], null, 'id');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            $g = guardManaged($pdo, $zid, $zname, $rec);
            $f = $byId[(int)$rec['id']]['isDyndns'];
            if ($g !== $f) { $mismatch[] = "{$rec['id']}:{$rec['name']}/{$rec['type']} Schutz=" . var_export($g, true) . ' isDyndns=' . var_export($f, true); }
            if ($f) { $flags[] = (int)$rec['id']; }
        }
        foreach ($view['records'] as $r) {
            if ($r['isDyndns'] && !in_array($r['type'], ['A', 'AAAA'], true)) { $mismatch[] = "{$r['id']} Typ {$r['type']} gekennzeichnet"; }
        }
    }
    sort($flags);
    $ok($mismatch === [] && $flags === $expect, $id, $msg . ' – gekennzeichnet: [' . implode(',', $flags) . ']' . ($mismatch ? ' ABWEICHUNG: ' . implode('; ', $mismatch) : ' = Schutz'));
}

$ok($pdo->query('SELECT @@transaction_isolation')->fetchColumn() === str_replace(' ', '-', $iso), 'Y00', "Isolationsebene $iso");
scenario('Y01', [], [8], 'Fixture: dyn A 192.0.2.99 mit gleicher Account-IP → verwaltet');
scenario('Y02', ["UPDATE DB.dyndns_accounts SET current_ipv4 = '192.0.2.98' WHERE id = 1"], [], 'Account-IP weicht ab → nicht gekennzeichnet');
scenario('Y03', ["UPDATE DB.dyndns_accounts SET current_ipv4 = NULL WHERE id = 1"], [], 'keine gespeicherte Account-IP → nicht gekennzeichnet');
scenario('Y04', ["INSERT INTO DB.records (zone_id, name, type, content, ttl) VALUES (1, 'dyn', 'A', '192.0.2.97', 300)"], [], 'zweiter A-Record unter dem Hostnamen → mehrdeutig');
scenario('Y05', ["UPDATE DB.records SET name = 'DYN' WHERE id = 8"], [], 'abweichende Schreibweise (DYN) → mehrdeutig');
scenario('Y06', ["UPDATE DB.records SET name = 'dyn.u0.test.' WHERE id = 8"], [], 'absoluter Name → mehrdeutig');
scenario('Y07', ["UPDATE DB.records SET server_id = 1 WHERE id = 8"], [], 'Systemrecord unter dem Hostnamen → nicht gekennzeichnet');
scenario('Y08', ["INSERT INTO DB.dyndns_accounts (username, password_hash, zone_id, hostname, current_ipv4) VALUES ('u0dyn2', 'x', 1, 'dýn', '192.0.2.99')"], [],
    'weiterer Account trifft den Namen nur über die Collation (dýn) → nicht eindeutig');
scenario('Y09', ["INSERT INTO DB.dyndns_accounts (username, password_hash, zone_id, hostname, current_ipv4) VALUES ('u0dyn3', 'x', 1, 'dyn', '192.0.2.99')"], [],
    'zwei Accounts mit demselben Hostnamen → nicht eindeutig');
scenario('Y10', ["UPDATE DB.dyndns_accounts SET current_ipv6 = '2001:db8::99' WHERE id = 1",
    "INSERT INTO DB.records (id, zone_id, name, type, content, ttl) VALUES (900, 1, 'dyn', 'AAAA', '2001:db8:0:0::99', 300)"], [8, 900],
    'A und AAAA verwaltet (IPv6 in anderer Schreibweise gleich)');
scenario('Y11', ["INSERT INTO DB.dyndns_accounts (username, password_hash, zone_id, hostname, current_ipv4) VALUES ('u0app', 'x', 2, 'app', '192.0.2.81')"], [8, 10],
    'Account in Zone 2 kennzeichnet nur den Record in Zone 2; Zone 1 unverändert');
scenario('Y12', ["INSERT INTO DB.dyndns_accounts (username, password_hash, zone_id, hostname, current_ipv4) VALUES ('u0www', 'x', 2, 'www', '192.0.2.80')"], [8],
    'Account in Zone 2 für den Namen www (Record nur in Zone 1) → keine zonenübergreifende Kennzeichnung');
scenario('Y13', ["UPDATE DB.records SET content = '192.0.2.99', name = 'mail' WHERE id = 6", "UPDATE DB.dyndns_accounts SET hostname = 'mail' WHERE id = 1"], [6],
    'Hostname-Wechsel: mail verwaltet, dyn nicht mehr');

// Rechte und keine Account-Daten
resetDb();
sqlx("INSERT INTO DB.dyndns_accounts (username, password_hash, zone_id, hostname, current_ipv4) VALUES ('u0app', 'x', 2, 'app', '192.0.2.81')");
$za1 = $svc->getZoneRecordView($zoneadmin, 1);
$za2 = $svc->getZoneRecordView($zoneadmin, 2);
$flagsZa = array_values(array_map(fn($r) => $r['id'], array_filter($za1['records'] ?? [], fn($r) => $r['isDyndns'])));
$ok($za1 !== null && $flagsZa === [8] && $za2 === null, 'Y14', 'zoneadmin: eigene Zone mit Kennzeichen [8], fremde Zone (mit DynDNS-Account) null');
$json = json_encode([$svc->getZoneRecordView($admin, 1), $svc->getZoneRecordView($admin, 2)]);
$ok(!str_contains($json, 'u0dyn') && !str_contains($json, 'u0app') && !str_contains($json, 'current_ipv') && !str_contains($json, 'hostname') && !str_contains($json, 'password'),
    'Y15', 'Ergebnis enthält keine Account-Daten (Benutzername, Hostname-Feld, gespeicherte IP, Passwort)');
$keys = array_keys($svc->getZoneRecordView($admin, 1)['records'][0]); sort($keys);
$ok($keys === ['content', 'id', 'isDkim', 'isDyndns', 'isGlue', 'isProtectedNs', 'name', 'systemManaged', 'ttl', 'type'], 'Y16', 'Vertrag: bisherige Felder + isDyndns');

printf("== DynDNS-Kennzeichen [%s]: %d/%d bestanden\n", $iso, $pass, $pass + $fail);
exit($fail ? 1 : 0);
