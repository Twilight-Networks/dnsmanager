<?php
/**
 * Datei: tests/ui/soa_consistency.php
 * Zweck: Regressionstests der SOA-MNAME-Konsistenz (PR B, D1–D6) gegen die Testkopie:
 *        A  Ausdrückliche Übernahme aus dem Master (D4): Anzeige nur bei Abweichung und nur für admin, normales
 *           Speichern ändert nichts, zoneadmin kann sie auch manipuliert nicht auslösen, veraltetes edit_version,
 *           Topologie-Lock, kein Publish, Zonendatei;
 *        M  Masterwechsel Forward/Reverse (D2), Zuweisungsänderung ohne Masterwechsel;
 *        S  Serverumbenennung (D3): nur Master-Zonen mit SOA = bisheriger Name (Groß-/Kleinschreibung, Punkt),
 *           abweichende Werte und Slave-Zonen unverändert, nur IP-Änderung ohne SOA-Änderung;
 *        F  Fehlerinjektion: vollständiges Rollback (Umbenennung, Masterwechsel, ungültiger Master-Name), keine
 *           offenen Sperren oder Transaktionen;
 *        K  Nebenläufigkeit (eigene Prozesse, soa_worker.php): Lock-Wartezeit auf der Zonenzeile nachgewiesen,
 *           sperrendes Lesen des SOA bei der Umbenennung, Topologie-Lock gegen Übernahme/Umbenennung/Publish,
 *           optimistische Versionsprüfung; anschließend Publish auf Master und Slave (Agent-Platzhalter).
 *        Web-Fälle (A, M, S, F) über die Actions; K mit der angegebenen Isolationsstufe.
 * Aufruf (über run.sh matrix): php soa_consistency.php RR|RC
 */
require __DIR__ . '/lib.php';
require APP . '/web/inc/deploy/bind_file_generator.php';

$iso = ($argv[1] ?? 'RR') === 'RC' ? 'RC' : 'RR';
$isoSql = $iso === 'RC' ? 'READ COMMITTED' : 'REPEATABLE READ';
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail, $iso) { $c ? $pass++ : $fail++; printf("%s %s [%s] %s\n", $c ? 'PASS' : 'FAIL', $id, $iso, $msg); };
$q = fn(string $sql) => $GLOBALS['root']->query(str_replace('DB.', '`' . DB . '`.', $sql));

function postF(string $path, array $pairs, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}
function pairs(array $form, array $override): array
{
    $out = [];
    foreach ($form['values'] as $n => $v) {
        if ($n === 'csrf_token' || array_key_exists($n, $override)) { continue; }
        foreach ((array)$v as $x) { $out[] = [is_array($v) ? $n . '[]' : $n, $x]; }
    }
    foreach ($override as $n => $v) {
        foreach ((array)$v as $x) { $out[] = [is_array($v) ? $n . '[]' : $n, $x]; }
    }
    return $out;
}
function zrow(int|string $idOrName): array|false
{
    $col = is_int($idOrName) ? 'id' : 'name';
    $s = $GLOBALS['root']->prepare('SELECT id, name, type, ttl, soa_ns, soa_serial, changed, description FROM `' . DB . "`.zones WHERE $col = ?");
    $s->execute([$idOrName]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function nsOf(int $zoneId): array
{
    $r = $GLOBALS['root']->query('SELECT content FROM `' . DB . "`.records WHERE zone_id = $zoneId AND type = 'NS' ORDER BY content")->fetchAll(PDO::FETCH_COLUMN);
    return $r;
}
function addZone(array &$c, string $type, string $prefix, array $servers, string $master, array $extra = []): int
{
    $page = 'pages/zones.php?add_new=1';
    if ($type !== 'forward') {
        $r = postF($page, [['type', $type], ['zone_prefix', $prefix], ['apply_type', '1'], ['soa_mail', 'hostmaster.u0.test.']], $c);
        $form = formFor($r['body'], 'actions/zone_add.php');
    } else {
        $form = formFor(cgi('GET', $page, '', $c)['body'], 'actions/zone_add.php');
    }
    postF('actions/zone_add.php', pairs($form, $extra + ['type' => $type, 'zone_prefix' => $prefix, 'soa_mail' => 'hostmaster.u0.test.', 'server_ids' => $servers, 'master_server_id' => $master]), $c);
    $name = $type === 'forward' ? $prefix : $prefix . ($type === 'reverse_ipv6' ? '.ip6.arpa' : '.in-addr.arpa');
    $z = zrow($name);
    return $z ? (int)$z['id'] : 0;
}
function editForm(array &$c, int $zoneId): ?array
{
    return formFor(cgi('GET', "pages/zones.php?edit_id=$zoneId", '', $c)['body'], 'actions/zone_update.php', "editForm_$zoneId");
}
/** SOA-MNAME der erzeugten Zonendatei, unabhängig ausgewertet (named-checkzone -D); null bei Fehler */
function fileMname(PDO $pdo, int $zoneId): ?string
{
    $z = zrow($zoneId);
    $dir = T . '/soa-c-zf';
    if (!is_dir($dir)) { mkdir($dir, 0700); }
    $r = generate_zone_file($zoneId, $dir, 'validate', $pdo);
    if (($r['status'] ?? '') === 'error') { return null; }
    // validate löscht die Datei: Inhalt neu erzeugen (gleiche Funktion, eigenes Verzeichnis) und auswerten
    $r = generate_zone_file($zoneId, $dir, 'publish', $pdo);
    $dump = (string)shell_exec('named-checkzone -q -D -o - ' . escapeshellarg($z['name']) . ' ' . escapeshellarg($r['path']) . ' 2>/dev/null');
    unlink($r['path']);
    foreach (explode("\n", $dump) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (($f[3] ?? '') === 'SOA') { return $f[4]; }
    }
    return null;
}
function locksFree(): bool
{
    return (int)$GLOBALS['root']->query("SELECT IS_FREE_LOCK('dnsmanager_publish_all')")->fetchColumn() === 1;
}
function openTrx(): int
{
    return (int)$GLOBALS['root']->query("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id <> CONNECTION_ID()")->fetchColumn();
}
function worker(string $iso, array $args): array
{
    $cmd = array_merge(['php', __DIR__ . '/soa_worker.php', $iso], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
}
function workerResult(array $w): array
{
    [$p, $pipes] = $w;
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); proc_close($p);
    return json_decode(trim($out), true) ?? ['ok' => false, 'codes' => ['worker: ' . trim($out . ' ' . $err)]];
}
/** Wartet, bis eine fremde Transaktion auf eine Sperre wartet (Nachweis der Lock-Wartezeit) */
function waitForLockWait(float $timeout = 10.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ((int)$GLOBALS['root']->query("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'")->fetchColumn() > 0) { return true; }
        usleep(100000);
    }
    return false;
}

/** Ausgangslage: Fixture + Server ns1.example.test (3), ns2.example.test. (4, mit Punkt gespeichert) */
function setup(): void
{
    resetDb();
    sqlx("INSERT INTO DB.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT 3, 'ns1.example.test', '192.0.2.21', '2001:db8::21', api_ip, api_token, 0, 1 FROM DB.servers WHERE id = 2");
    sqlx("INSERT INTO DB.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT 4, 'ns2.example.test.', '192.0.2.22', NULL, api_ip, api_token, 0, 1 FROM DB.servers WHERE id = 2");
}

$webCases = $iso === 'RR'; // Web-Fälle (Actions, Standard-Isolationsstufe der Anwendung) nur einmal

if ($webCases) {
    // ---------- A: ausdrückliche Übernahme (D4) ----------
    setup();
    $a = session('admin');
    $legacy = addZone($a, 'forward', 'legacy.test', ['3', '4'], '3');
    $good = addZone($a, 'forward', 'good.test', ['3', '4'], '3');
    sqlx("UPDATE DB.zones SET soa_ns = 'ns1.example.test.legacy.test.', changed = 0 WHERE id = $legacy"); // Altbestand nachbilden
    sqlx("UPDATE DB.zones SET changed = 0 WHERE id = $good");
    sqlx("INSERT INTO DB.user_zones (user_id, zone_id) VALUES (2, $legacy)");
    $f = editForm($a, $legacy);
    $body = cgi('GET', "pages/zones.php?edit_id=$legacy", '', $a)['body'];
    $ok($f !== null && in_array('soa_ns_from_master:checkbox', $f['fields'], true) && !array_key_exists('soa_ns', $f['values'])
        && str_contains($body, 'ns1.example.test.legacy.test.') && preg_match('#for="zf_soa_ns_from_master">[^<]*ns1\.example\.test\.<#', $body) === 1,
        'A01', 'abweichender SOA (Altbestand): admin sieht die Übernahme mit Ziel ns1.example.test., Anzeige des gespeicherten Werts, kein Feld soa_ns');
    $fg = editForm($a, $good);
    $ok($fg !== null && !in_array('soa_ns_from_master:checkbox', $fg['fields'], true), 'A02', 'übereinstimmender SOA: keine Übernahme angeboten');
    $z = session('zoneadmin');
    $fz = editForm($z, $legacy);
    $ok($fz !== null && !in_array('soa_ns_from_master:checkbox', $fz['fields'], true), 'A03', 'zoneadmin: keine Übernahme angeboten (auch bei Abweichung)');

    $before = zrow($legacy);
    postF('actions/zone_update.php', pairs(editForm($a, $legacy), ['ttl' => '7200']), $a);
    $after = zrow($legacy);
    $ok($after['soa_ns'] === $before['soa_ns'] && (int)$after['ttl'] === 7200, 'A04', "normales Speichern (TTL) ohne Übernahme: SOA unverändert '{$after['soa_ns']}'");

    $before = zrow($legacy);
    postF('actions/zone_update.php', pairs(editForm($z, $legacy), ['description' => 'zoneadmin', 'soa_ns_from_master' => '1', 'soa_ns' => 'evil.example.']), $z);
    $after = zrow($legacy);
    $ok($after['soa_ns'] === $before['soa_ns'] && $after['description'] === 'zoneadmin', 'A05', 'zoneadmin mit manipuliert gesendeter Übernahme und soa_ns: SOA unverändert, Beschreibung gespeichert');

    $stale = editForm($a, $legacy);
    postF('actions/zone_update.php', pairs(editForm($a, $legacy), ['description' => 'dazwischen']), $a);
    $before = zrow($legacy);
    cgi('GET', 'pages/zones.php', '', $a);
    postF('actions/zone_update.php', pairs($stale, ['soa_ns_from_master' => '1']), $a);
    $t = toasts($a);
    $ok(zrow($legacy) === $before && str_starts_with((string)($t[0] ?? ''), 'error'), 'A06', 'Übernahme mit veraltetem edit_version: abgelehnt, nichts geändert (' . ($t[0] ?? '-') . ')');

    $before = zrow($legacy);
    postF('actions/zone_update.php', pairs(editForm($a, $legacy), ['soa_ns_from_master' => 'yes']), $a);
    $ok(zrow($legacy)['soa_ns'] === $before['soa_ns'], 'A07', 'Übernahme mit Wert ≠ "1": vom Web-Adapter ignoriert');

    $GLOBALS['root']->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    $before = zrow($legacy);
    cgi('GET', 'pages/zones.php', '', $a);
    postF('actions/zone_update.php', pairs(editForm($a, $legacy), ['soa_ns_from_master' => '1']), $a);
    $t = toasts($a);
    $GLOBALS['root']->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
    $ok(zrow($legacy) === $before && str_starts_with((string)($t[0] ?? ''), 'warning'), 'A08', 'Übernahme bei belegtem Topologie-Lock: abgelehnt (' . ($t[0] ?? '-') . '), nichts geändert');

    $snapBefore = snap();
    $before = zrow($legacy);
    postF('actions/zone_update.php', pairs(editForm($a, $legacy), ['soa_ns_from_master' => '1']), $a);
    $after = zrow($legacy);
    $snapAfter = snap();
    $ok($after['soa_ns'] === 'ns1.example.test.' && (int)$after['changed'] === 1 && $after['soa_serial'] === $before['soa_serial']
        && $snapAfter['deployments'] === $snapBefore['deployments'] && $snapAfter['records'] === $snapBefore['records'] && $snapAfter['zone_servers'] === $snapBefore['zone_servers'],
        'A09', "Übernahme durch admin: SOA '{$before['soa_ns']}' → '{$after['soa_ns']}', Zone ausstehend, Serial unverändert, kein Publish, Records/Zuweisung unverändert");
    $ok(fileMname($pdo, $legacy) === 'ns1.example.test.', 'A10', 'Zonendatei nach Übernahme: MNAME ns1.example.test. (named-checkzone -D)');
    $fa = editForm($a, $legacy);
    $ok($fa !== null && !in_array('soa_ns_from_master:checkbox', $fa['fields'], true), 'A11', 'nach der Übernahme wird sie nicht mehr angeboten');

    // ---------- M: Masterwechsel (D2) ----------
    $before = zrow($good);
    postF('actions/zone_update.php', pairs(editForm($a, $good), ['master_server_id' => '4']), $a);
    $after = zrow($good);
    $ok($after['soa_ns'] === 'ns2.example.test.' && (int)$after['changed'] === 1 && $after['soa_serial'] === $before['soa_serial'] && nsOf($good) === ['ns1.example.test.', 'ns2.example.test.'],
        'M01', "Masterwechsel Forward: SOA '{$before['soa_ns']}' → '{$after['soa_ns']}' (kein doppelter Punkt), NS unverändert vollständig, Serial unverändert");
    $rev = addZone($a, 'reverse_ipv4', '30.10', ['3', '4'], '3', ['prefix_length' => '16']);
    $r0 = zrow($rev);
    postF('actions/zone_update.php', pairs(editForm($a, $rev), ['master_server_id' => '4']), $a);
    $r1 = zrow($rev);
    $ok($r0['soa_ns'] === 'ns1.example.test.' && $r1['soa_ns'] === 'ns2.example.test.' && fileMname($pdo, $rev) === 'ns2.example.test.', 'M02',
        "Reverse: Anlage SOA '{$r0['soa_ns']}', nach Masterwechsel '{$r1['soa_ns']}', Zonendatei entsprechend");
    sqlx("UPDATE DB.zones SET soa_ns = 'ns2.example.test.legacy.' WHERE id = $rev");
    postF('actions/zone_update.php', pairs(editForm($a, $rev), ['server_ids' => ['4']]), $a);
    $r2 = zrow($rev);
    $ok($r2['soa_ns'] === 'ns2.example.test.legacy.' && nsOf($rev) === ['ns2.example.test.'], 'M03', 'Zuweisungsänderung ohne Masterwechsel (Slave entfernt): abweichender SOA bleibt, NS neu aufgebaut');

    // ---------- S: Serverumbenennung (D3) ----------
    setup();
    $a = session('admin');
    $z1 = addZone($a, 'forward', 'r1.test', ['3', '4'], '3');                 // Master 3, SOA = Name
    $z2 = addZone($a, 'forward', 'r2.test', ['3', '4'], '3');                 // Master 3, SOA abweichend
    $z3 = addZone($a, 'forward', 'r3.test', ['3', '4'], '4');                 // 3 nur Slave
    $z4 = addZone($a, 'reverse_ipv6', '9.b.d.0.1.0.0.2', ['3'], '3', ['prefix_length' => '32']);
    $z5 = addZone($a, 'forward', 'r5.test', ['3'], '3');                      // SOA in anderer Schreibweise
    sqlx("UPDATE DB.zones SET soa_ns = 'ns1.example.test.r2.test.' WHERE id = $z2");
    sqlx("UPDATE DB.zones SET soa_ns = 'NS1.Example.Test.' WHERE id = $z5");
    sqlx('UPDATE DB.zones SET changed = 0');
    $sf = formFor(cgi('GET', 'pages/servers.php?edit_id=3', '', $a)['body'], 'actions/server_update.php');
    $r = postF('actions/server_update.php', pairs($sf, ['name' => 'ns1.renamed.test']), $a);
    $rows = array_map(fn($id) => zrow($id), [$z1, $z2, $z3, $z4, $z5]);
    $ok($q('SELECT name FROM DB.servers WHERE id = 3')->fetchColumn() === 'ns1.renamed.test', 'S01', "Umbenennung gespeichert (HTTP {$r['status']})");
    $ok($rows[0]['soa_ns'] === 'ns1.renamed.test.' && $rows[3]['soa_ns'] === 'ns1.renamed.test.' && $rows[4]['soa_ns'] === 'ns1.renamed.test.', 'S02',
        'Master-Zonen mit SOA = bisheriger Name (auch Reverse und andere Schreibweise): SOA = ns1.renamed.test.');
    $ok($rows[1]['soa_ns'] === 'ns1.example.test.r2.test.' && $rows[2]['soa_ns'] === 'ns2.example.test.', 'S03', 'abweichender SOA und Slave-Zone: unverändert');
    $nsOk = true;
    foreach ([$z1, $z2, $z3, $z4, $z5] as $zid) { $nsOk = $nsOk && in_array('ns1.renamed.test.', nsOf($zid), true) && !in_array('ns1.example.test.', nsOf($zid), true); }
    $ok($nsOk && array_sum(array_map(fn($x) => (int)$x['changed'], $rows)) === 5, 'S04', 'NS-Records aller Zonen des Servers auf den neuen Namen umgestellt, alle Zonen ausstehend');
    $ok(fileMname($pdo, $z1) === 'ns1.renamed.test.' && fileMname($pdo, $z4) === 'ns1.renamed.test.' && fileMname($pdo, $z2) === 'ns1.example.test.r2.test.', 'S05', 'Zonendateien: MNAME entsprechend (named-checkzone -D)');
    sqlx('UPDATE DB.zones SET changed = 0');
    $before = array_map(fn($id) => zrow($id)['soa_ns'], [$z1, $z2, $z3, $z4, $z5]);
    $sf = formFor(cgi('GET', 'pages/servers.php?edit_id=3', '', $a)['body'], 'actions/server_update.php');
    postF('actions/server_update.php', pairs($sf, ['dns_ip4' => '192.0.2.31']), $a);
    $ok(array_map(fn($id) => zrow($id)['soa_ns'], [$z1, $z2, $z3, $z4, $z5]) === $before, 'S06', 'nur IP-Änderung: kein SOA verändert');
    $sf = formFor(cgi('GET', 'pages/servers.php?edit_id=4', '', $a)['body'], 'actions/server_update.php');
    postF('actions/server_update.php', pairs($sf, ['name' => 'ns2.renamed.test']), $a);
    $ok(zrow($z3)['soa_ns'] === 'ns2.renamed.test.', 'S07', 'mit Punkt gespeicherter Servername umbenannt: SOA der Master-Zone ns2.renamed.test.');

    // ---------- F: Fehlerinjektion und Rollback ----------
    setup();
    $a = session('admin');
    $z1 = addZone($a, 'forward', 'f1.test', ['3', '4'], '3');
    $z2 = addZone($a, 'forward', 'f2.test', ['3'], '3');
    sqlx("INSERT INTO DB.records (zone_id, name, type, content, ttl, server_id) VALUES ($z1, 'ns9', 'CNAME', 'elsewhere.test.', 300, NULL)");
    $snapBefore = snap();
    $sf = formFor(cgi('GET', 'pages/servers.php?edit_id=3', '', $a)['body'], 'actions/server_update.php');
    cgi('GET', 'pages/servers.php', '', $a);
    postF('actions/server_update.php', pairs($sf, ['name' => 'ns9.f1.test']), $a);
    $t = toasts($a);
    $ok(snap() === $snapBefore && locksFree() && openTrx() === 0, 'F01',
        'Umbenennung in einen Namen, dessen Adressrecord mit einem CNAME kollidiert: vollständiges Rollback (Server, SOA aller Master-Zonen, Records), Sperren frei (' . ($t[0] ?? '-') . ')');
    sqlx("INSERT INTO DB.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT 5, 'ns9.f1.test', '192.0.2.25', NULL, api_ip, api_token, 0, 1 FROM DB.servers WHERE id = 2");
    $snapBefore = snap();
    cgi('GET', 'pages/zones.php', '', $a);
    postF('actions/zone_update.php', pairs(editForm($a, $z1), ['server_ids' => ['3', '4', '5'], 'master_server_id' => '5']), $a);
    $t = toasts($a);
    $ok(snap() === $snapBefore && locksFree() && openTrx() === 0, 'F02', 'Masterwechsel mit fehlschlagendem NS/Glue-Neuaufbau: Zuweisung und SOA unverändert, Sperren frei (' . ($t[0] ?? '-') . ')');
    sqlx("INSERT INTO DB.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT 6, 'bad_name.test', '192.0.2.26', NULL, api_ip, api_token, 0, 1 FROM DB.servers WHERE id = 2");
    $snapBefore = snap();
    cgi('GET', 'pages/zones.php', '', $a);
    postF('actions/zone_update.php', pairs(editForm($a, $z2), ['server_ids' => ['3', '6'], 'master_server_id' => '6']), $a);
    $t = toasts($a);
    $ok(snap() === $snapBefore && locksFree() && openTrx() === 0, 'F03', 'Masterwechsel auf ungültigen Master-Namen (Altbestand): abgelehnt, vollständiges Rollback (' . ($t[0] ?? '-') . ')');
}

// ---------- K: Nebenläufigkeit (eigene Prozesse, Isolationsstufe $iso) ----------
setup();
$a = session('admin');
$k1 = addZone($a, 'forward', 'k1.test', ['3', '4'], '3');
$k2 = addZone($a, 'forward', 'k2.test', ['3', '4'], '3');
sqlx("UPDATE DB.zones SET soa_ns = 'ns1.example.test.k2.test.' WHERE id = $k2");
sqlx('UPDATE DB.zones SET changed = 0');
$T = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$T->exec("SET SESSION TRANSACTION ISOLATION LEVEL $isoSql");

// K1: Umbenennung wartet auf die gesperrte Zonenzeile, die dazwischen einen abweichenden SOA erhält. Kein Lost Update:
//     entweder Erfolg mit erhaltenem SOA (sperrendes Lesen; RC und RR ohne Snapshot-Isolationsprüfung) oder – MariaDB
//     ab 11.6 unter RR mit innodb_snapshot_isolation = ON – Ablehnung (Fehler 1020) mit vollständigem Rollback.
$k1run = function (string $wIso, string $newName) use ($T, $k1, $q, $ok) {
    $srvBefore = $q('SELECT name FROM DB.servers WHERE id = 3')->fetchColumn();
    // Ausgangslage je Lauf: SOA = aktueller Master-FQDN (sonst greift die Bedingung der Umbenennung gar nicht)
    $GLOBALS['root']->prepare('UPDATE `' . DB . '`.zones SET soa_ns = ? WHERE id = ?')->execute([SoaName((string)$srvBefore), $k1]);
    $nsBefore = nsOf($k1);
    $snap0 = snap();
    $T->beginTransaction();
    $T->query("SELECT id FROM zones WHERE id = $k1 FOR UPDATE")->fetchAll();
    $w = worker($wIso, ['rename', '3', $newName]);
    $waited = waitForLockWait();
    usleep(1500000); // Leseansicht des wartenden Prozesses liegt sicher vor der Änderung (wie Zonen-Editor parallel)
    $manual = 'manuell.' . $newName . '.';
    $T->exec("UPDATE zones SET soa_ns = " . $T->quote($manual) . " WHERE id = $k1");
    $T->commit();
    $res = workerResult($w);
    $srv = $q('SELECT name FROM DB.servers WHERE id = 3')->fetchColumn();
    $soaKept = zrow($k1)['soa_ns'] === $manual;
    if ($res['ok']) {
        $ok($waited && $soaKept && $srv === $newName && in_array(SoaName($newName), nsOf($k1), true), "K1-$wIso",
            "Umbenennung wartet (LOCK WAIT), danach erfolgreich; zwischenzeitlich gesetzter SOA erhalten (kein Lost Update), NS umgestellt");
    } else {
        // Keine teilweise übernommene Änderung: alles wie vorher, nur der SOA von k1 trägt die Änderung der anderen Transaktion
        $snap1 = snap();
        $zonesExpected = array_map(fn($row) => str_starts_with($row, "$k1|") ? preg_replace('/^((?:[^|]*\|){6})[^|]*/', '${1}' . $manual, $row) : $row, $snap0['zones']);
        $unchanged = $snap1['servers'] === $snap0['servers'] && $snap1['records'] === $snap0['records'] && $snap1['zone_servers'] === $snap0['zone_servers']
            && $snap1['zones'] === $zonesExpected;
        $ok($waited && $soaKept && $srv === $srvBefore && nsOf($k1) === $nsBefore && $unchanged && str_contains((string)($res['detail'] ?? ''), '1020')
            && in_array('server.update.failed', $res['codes'], true) && locksFree() && openTrx() === 0, "K1-$wIso",
            'Umbenennung wartet (LOCK WAIT), dann Snapshot-Konflikt (1020, Code server.update.failed) → vollständiges Rollback ohne Teiländerung, '
            . 'nur die Änderung der anderen Transaktion bleibt, Sperren frei, keine offene Transaktion');
        // Erneute Ausführung nach Auflösung des Konflikts (keine automatische Wiederholung in der Anwendung)
        $retry = workerResult(worker($wIso, ['rename', '3', $newName]));
        $ok($retry['ok'] && $q('SELECT name FROM DB.servers WHERE id = 3')->fetchColumn() === $newName && zrow($k1)['soa_ns'] === $manual
            && in_array(SoaName($newName), nsOf($k1), true) && locksFree() && openTrx() === 0, "K1-$wIso-retry",
            'erneute Ausführung gelingt: Server umbenannt, NS umgestellt, abweichender SOA (andere Transaktion) bleibt erhalten, Sperren frei');
        return true;
    }
    return $res['ok'];
};
function SoaName(string $n): string { return rtrim($n, '.') . '.'; }
$k1run($iso, 'ns1.k.test');
if ($iso === 'RR') { $k1run('RR0', 'ns1.k0.test'); }

// K1W: derselbe Konflikt über den Web-Weg (server_update.php, Standard-Isolationsstufe der Anwendung): verständliche
//      Fehlermeldung, vollständiges Rollback, danach erfolgreiche erneute Ausführung über dieselbe Action
if ($webCases) {
    $srvBefore = (string)$q('SELECT name FROM DB.servers WHERE id = 3')->fetchColumn();
    $GLOBALS['root']->prepare('UPDATE `' . DB . '`.zones SET soa_ns = ? WHERE id = ?')->execute([SoaName($srvBefore), $k1]);
    $nsBefore = nsOf($k1);
    $snap0 = snap();
    cgi('GET', 'pages/servers.php', '', $a);
    $child = "require '" . __DIR__ . "/lib.php'; \$c = json_decode(\$argv[1], true);"
        . " \$f = formFor(cgi('GET', 'pages/servers.php?edit_id=3', '', \$c)['body'], 'actions/server_update.php');"
        . " \$p = []; foreach (\$f['values'] as \$n => \$v) { if (\$n === 'csrf_token' || \$n === 'name') continue; foreach ((array)\$v as \$x) \$p[] = [is_array(\$v) ? \$n . '[]' : \$n, \$x]; }"
        . " \$p[] = ['name', 'ns1.web.test']; \$p[] = ['csrf_token', csrfFromSession(\$c)];"
        . " \$r = cgi('POST', 'actions/server_update.php', implode('&', array_map(fn(\$x) => rawurlencode(\$x[0]) . '=' . rawurlencode(\$x[1]), \$p)), \$c);"
        . " echo json_encode(['status' => \$r['status'], 'toasts' => toasts(\$c)], JSON_UNESCAPED_UNICODE);";
    $T->beginTransaction();
    $T->query("SELECT id FROM zones WHERE id = $k1 FOR UPDATE")->fetchAll();
    $proc = proc_open(['php', '-r', $child, json_encode($a)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    $waited = waitForLockWait();
    usleep(1500000);
    $T->exec("UPDATE zones SET soa_ns = 'manuell.web.' WHERE id = $k1");
    $T->commit();
    $out = json_decode(trim(stream_get_contents($pp[1])), true) ?? []; $err = stream_get_contents($pp[2]); proc_close($proc);
    $t = $out['toasts'][0] ?? ('-' . trim($err));
    $snap1 = snap();
    $zonesExpected = array_map(fn($row) => str_starts_with($row, "$k1|") ? preg_replace('/^((?:[^|]*\|){6})[^|]*/', '${1}manuell.web.', $row) : $row, $snap0['zones']);
    $ok($waited && str_starts_with($t, 'error: Beim Speichern des Servers ist ein Fehler aufgetreten.') && $snap1['servers'] === $snap0['servers']
        && $snap1['records'] === $snap0['records'] && $snap1['zones'] === $zonesExpected && nsOf($k1) === $nsBefore && locksFree() && openTrx() === 0,
        'K1W', "Web-Weg unter dem Konflikt: Fehlermeldung „{$t}“, vollständiges Rollback, Sperren frei");
    $sf = formFor(cgi('GET', 'pages/servers.php?edit_id=3', '', $a)['body'], 'actions/server_update.php');
    postF('actions/server_update.php', pairs($sf, ['name' => 'ns1.web.test']), $a);
    $t = toasts($a);
    $ok($q('SELECT name FROM DB.servers WHERE id = 3')->fetchColumn() === 'ns1.web.test' && in_array('ns1.web.test.', nsOf($k1), true)
        && zrow($k1)['soa_ns'] === 'manuell.web.' && str_starts_with((string)($t[0] ?? ''), 'success') && locksFree(), 'K1W-retry',
        'erneute Ausführung über die Action gelingt (' . ($t[0] ?? '-') . '), abweichender SOA bleibt erhalten');
}
// aktueller Master-FQDN von Server 3 (je nach Ausgang von K1)
$m3 = SoaName((string)$q('SELECT name FROM DB.servers WHERE id = 3')->fetchColumn());

// K2: Topologie-Lock belegt → Übernahme und Umbenennung abgelehnt, nichts geändert; danach Übernahme erfolgreich
$T->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
$snapBefore = snap();
$r1 = workerResult(worker($iso, ['adopt', (string)$k2]));
$r2 = workerResult(worker($iso, ['rename', '3', 'ns1.k2.test']));
$ok(!$r1['ok'] && in_array('zone.update.topology_locked', $r1['codes'], true) && !$r2['ok'] && in_array('server.update.topology_locked', $r2['codes'], true) && snap() === $snapBefore,
    'K2', 'Topologie-Lock belegt: Übernahme und Umbenennung abgelehnt, nichts geändert: ' . json_encode([$r1['codes'], $r2['codes']]));
$T->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
$r3 = workerResult(worker($iso, ['adopt', (string)$k2]));
$ok($r3['ok'] && zrow($k2)['soa_ns'] === $m3, 'K3', "nach Freigabe: Übernahme setzt den aktuellen Master-FQDN $m3: " . json_encode($r3['codes']));

// K4: Übernahme wartet auf die Zonenzeile; Änderung dazwischen → optimistische Versionsprüfung lehnt ab
sqlx("UPDATE DB.zones SET soa_ns = 'alt.example.test.' WHERE id = $k2");
$T->beginTransaction();
$T->query("SELECT id FROM zones WHERE id = $k2 FOR UPDATE")->fetchAll();
$w = worker($iso, ['adopt', (string)$k2]);
$waited = waitForLockWait();
$T->exec("UPDATE zones SET ttl = 7200 WHERE id = $k2");
$T->commit();
$res = workerResult($w);
$ok($waited && !$res['ok'] && in_array('zone.update.conflict', $res['codes'], true) && zrow($k2)['soa_ns'] === 'alt.example.test.', 'K4',
    'Übernahme wartet (LOCK WAIT), Zone dazwischen geändert → Konflikt, SOA unverändert: ' . json_encode($res['codes']));
// K5: gleiche Lage ohne Änderung dazwischen → nach der Wartezeit erfolgreich
$T->beginTransaction();
$T->query("SELECT id FROM zones WHERE id = $k2 FOR UPDATE")->fetchAll();
$w = worker($iso, ['adopt', (string)$k2]);
$waited = waitForLockWait();
$T->commit();
$res = workerResult($w);
$ok($waited && $res['ok'] && zrow($k2)['soa_ns'] === $m3, 'K5', 'Übernahme wartet (LOCK WAIT) und gelingt danach: ' . json_encode($res['codes']));
// K6: Masterwechsel als eigener Prozess
$res = workerResult(worker($iso, ['master', (string)$k1, '4', '3,4']));
$ok($res['ok'] && zrow($k1)['soa_ns'] === 'ns2.example.test.' && locksFree() && openTrx() === 0, 'K6', 'Masterwechsel (eigener Prozess): SOA ns2.example.test., Sperren frei: ' . json_encode($res['codes']));

// K7: Publish bei belegtem Topologie-Lock abgelehnt; danach Publish auf Master und Slave mit den neuen SOA-Werten
if ($webCases) {
    $T->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    $snapBefore = snap();
    cgi('GET', 'pages/dashboard.php', '', $a);
    postF('actions/publish_all.php', [], $a);
    $t = toasts($a);
    $T->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
    $ok(snap()['zones'] === $snapBefore['zones'] && snap()['deployments'] === $snapBefore['deployments'], 'K7', 'Publish bei belegtem Topologie-Lock: nichts veröffentlicht (' . ($t[0] ?? '-') . ')');
    $before = [zrow($k1), zrow($k2)];
    cgi('GET', 'pages/dashboard.php', '', $a);
    postF('actions/publish_all.php', [], $a);
    $after = [zrow($k1), zrow($k2)];
    $ok((int)$after[0]['changed'] === 0 && (int)$after[1]['changed'] === 0 && $after[0]['soa_serial'] > $before[0]['soa_serial'] && $after[1]['soa_serial'] > $before[1]['soa_serial']
        && $after[0]['soa_ns'] === 'ns2.example.test.' && $after[1]['soa_ns'] === $m3, 'K8',
        'Publish danach: Zonen veröffentlicht (changed 0), Serial nach bisheriger Strategie erhöht, SOA-Werte erhalten (' . (toasts($a)[0] ?? '-') . ')');
}
printf("Ergebnis SOA-Konsistenz [%s]: %d bestanden, %d fehlgeschlagen\n", $iso, $pass, $fail);
exit($fail === 0 ? 0 : 1);
