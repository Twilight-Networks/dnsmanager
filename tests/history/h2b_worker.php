<?php
/**
 * Datei: tests/history/h2b_worker.php
 * Zweck: Hilfsprozess der Recorder-Tests (AP1/H2b) mit eigener Datenbankverbindung für Nebenläufigkeitsfälle.
 *        Ausgabe: eine JSON-Zeile. Synchronisation mit dem Test über Markendateien.
 *
 * Aufruf: php h2b_worker.php <App> <dnsmgr_t21_*> <RR|RC> <Operation> [Argumente …]
 *   hold-zone <zoneId> <Sekunden> <Marke> [legacy-insert]   Zone sperren (wie alter Code ohne Historie), optional
 *                                                             Record einfügen, Marke schreiben, warten, Commit
 *   hold-account <accountId> <Sekunden> <Marke>              DynDNS-Konto ohne Zonensperre sperren, warten, ändern, Commit
 *   snap-insert <Name> <Sekunden> <Marke> <commit|rollback>  synthetischen Snapshot speichern (ZoneSnapshotStore), warten
 *   ensure <zoneId>                                          ZoneHistoryRecorder::ensureHeads()
 *   ensure-forupdate <zoneId> <Marke> <Startmarke>           Gegenprobe E35: Head mit FOR UPDATE prüfen (Gap-Sperre),
 *                                                             Marke, auf Startmarke warten, Baseline einfügen
 *   rename-server <serverId> <Name>                          ServerService::updateServer
 *   record-create <zoneId> <Name> <IPv4>                     RecordService::createRecord (admin)
 *   api-update <IPv4>                                        DynDNS-API für Konto 1
 *   stress <Sekunden> <Startwert>                            zufällige Fachänderungen auf Zonen 1–4
 *   AP1/H2c:
 *   hold-server <serverId> <Sekunden> <Marke> <none|Spalte> [Wert]  Serverzeile sperren, optional Spalte ändern (wie
 *                                                             alter Code), Marke, warten, Commit
 *   server-local <serverId>                                  ServerService::updateServer: Server wird lokaler Server
 *   audit-stress <Sekunden> <Startwert>                      zufällige Verwaltungsvorgänge mit Audit-Ereignis
 */

require_once __DIR__ . '/h2b_lib.php';

[$app, $db, $iso, $op] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
if (preg_match('/^dnsmgr_t21_[a-z0-9_]+$/D', $db) !== 1 || !in_array($iso, ['RR', 'RC'], true)) {
    fwrite(STDERR, "Aufruf: php h2b_worker.php <App> <dnsmgr_t21_*> <RR|RC> <Operation> …\n");
    exit(2);
}
H2b::$app = rtrim($app, '/');
H2b::$db = $db;
H2b::$iso = $iso;
H2b::requireApp();
$a = array_slice($argv, 5);
$pdo = H2b::pdo();

function out(array $r): never
{
    echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
function res(ServiceResult $r): array
{
    return ['ok' => $r->ok, 'codes' => array_map(fn ($m) => $m->code, $r->messages),
        'detail' => implode(' | ', array_filter(array_map(fn ($m) => (string)($m->context['detail'] ?? $m->context['message'] ?? ''), $r->messages)))];
}
function synthetic(string $name): ZoneStateSnapshot
{
    return ZoneStateSnapshot::fromRows(['name' => $name, 'type' => 'forward', 'ttl' => 3600, 'prefix_length' => null, 'soa_ns' => 'ns1.h2b.test.',
        'soa_mail' => 'hostmaster.h2b.test.', 'soa_refresh' => 3600, 'soa_retry' => 1800, 'soa_expire' => 1209600, 'soa_minimum' => 86400], [], []);
}

try {
    switch ($op) {
        case 'hold-zone':
            [$zone, $sec, $mark] = [(int)$a[0], (float)$a[1], $a[2]];
            $pdo->beginTransaction();
            $pdo->query("SELECT id FROM zones WHERE id = $zone FOR UPDATE")->fetchAll();
            if (($a[3] ?? '') === 'legacy-insert') {
                $pdo->exec("INSERT INTO records (zone_id, name, type, content, ttl) VALUES ($zone, 'fremd', 'TXT', '\"legacy\"', 300)");
            }
            touch($mark);
            usleep((int)($sec * 1e6));
            $pdo->commit();
            out(['ok' => true]);

        case 'hold-account':
            [$acc, $sec, $mark] = [(int)$a[0], (float)$a[1], $a[2]];
            $pdo->beginTransaction();
            $pdo->query("SELECT id FROM dyndns_accounts WHERE id = $acc FOR UPDATE")->fetchAll();
            touch($mark);
            usleep((int)($sec * 1e6));
            $pdo->exec("UPDATE dyndns_accounts SET last_update = NOW() WHERE id = $acc");
            $pdo->commit();
            out(['ok' => true]);

        case 'snap-insert':
            [$name, $sec, $mark, $end] = [$a[0], (float)$a[1], $a[2], $a[3]];
            $pdo->beginTransaction();
            $new = (new ZoneSnapshotStore($pdo))->store(synthetic($name));
            touch($mark);
            usleep((int)($sec * 1e6));
            $end === 'commit' ? $pdo->commit() : $pdo->rollBack();
            out(['ok' => true, 'new' => $new]);

        case 'ensure':
            $done = ZoneHistoryRecorder::ensureHeads($pdo, [(int)$a[0]], HistoryContext::system('history.test', 'cli'));
            out(['ok' => true, 'done' => $done]);

        case 'ensure-forupdate':
            // Gegenprobe zu E35: Variante mit FOR UPDATE auf dem fehlenden Head (Gap-Sperre unter REPEATABLE READ)
            [$zone, $mark, $go] = [(int)$a[0], $a[1], $a[2]];
            $pdo->beginTransaction();
            $snap = (new ZoneSnapshotReader($pdo))->lockAndRead($zone);
            $pdo->query("SELECT head_revision_no FROM zone_history_state WHERE zone_id = $zone FOR UPDATE")->fetchAll();
            touch($mark);
            H2b::waitFile($go);
            HistoryWriter::baselineZone($pdo, $zone, $snap, HistoryContext::system('history.test', 'cli'));
            $pdo->commit();
            out(['ok' => true]);

        case 'rename-server':
            $id = (int)$a[0];
            $s = $pdo->query("SELECT name, dns_ip4, dns_ip6, api_ip, is_local, active FROM servers WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            $in = new ServerInput($a[1], (string)$s['dns_ip4'], (string)($s['dns_ip6'] ?? ''), (string)$s['api_ip'], '', (bool)$s['is_local'], (bool)$s['active']);
            out(res(H2b::servers($pdo)->updateServer(H2b::admin(), $id, $in)));

        case 'hold-server':
            [$sid, $sec, $mark, $col] = [(int)$a[0], (float)$a[1], $a[2], $a[3]];
            if (!in_array($col, ['none', 'name', 'active', 'api_ip', 'is_local'], true)) {
                out(['ok' => false, 'message' => 'Spalte nicht zulässig']);
            }
            $pdo->beginTransaction();
            $pdo->query("SELECT id FROM servers WHERE id = $sid FOR UPDATE")->fetchAll();
            if ($col !== 'none') {
                $pdo->prepare("UPDATE servers SET $col = ? WHERE id = ?")->execute([$a[4], $sid]);
            }
            touch($mark);
            usleep((int)($sec * 1e6));
            $pdo->commit();
            out(['ok' => true]);

        case 'server-local':
            $id = (int)$a[0];
            $s = $pdo->query("SELECT name, dns_ip4, dns_ip6, api_ip, active FROM servers WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            $in = new ServerInput((string)$s['name'], (string)$s['dns_ip4'], (string)($s['dns_ip6'] ?? ''), (string)$s['api_ip'], '', true, (bool)$s['active']);
            out(res(H2b::servers($pdo)->updateServer(H2b::admin(), $id, $in)));

        case 'audit-stress':
            [$sec, $seed] = [(float)$a[0], (int)$a[1]];
            if (!defined('PASSWORD_MIN_LENGTH')) {
                define('PASSWORD_MIN_LENGTH', 12);
            }
            mt_srand($seed);
            $stats = ['ok' => 0, 'fail' => 0, 'codes' => [], 'ops' => []];
            $end = microtime(true) + $sec;
            $i = 0;
            while (microtime(true) < $end) {
                $i++;
                $k = mt_rand(0, 4);
                $z = [1, 2, 4][mt_rand(0, 2)];
                $r = match ($k) {
                    0 => H2b::zones($pdo)->updateZone(H2b::admin(), $z, H2b::zoneInput($pdo, H2b::admin(), $z, ['description' => "s{$seed}-$i"])),
                    1 => (function () use ($pdo, $seed, $i): ServiceResult {
                        $s = H2b::all($pdo, 'SELECT * FROM servers WHERE id = 2')[0];
                        return H2b::servers($pdo)->updateServer(H2b::admin(), 2, new ServerInput((string)$s['name'], (string)$s['dns_ip4'], (string)($s['dns_ip6'] ?? ''),
                            '192.0.2.' . (100 + ($seed * 37 + $i) % 100), '', (bool)$s['is_local'], (bool)$s['active']));
                    })(),
                    2 => H2b::dyndns($pdo)->changePassword(H2b::admin(), 1, "Stress-Passwort-$seed-$i"),
                    3 => (new UserService($pdo))->changePassword(H2b::admin(), 22, "Stress-Benutzer-$seed-$i", null),
                    default => H2b::records($pdo)->createRecord(H2b::admin(), $z, H2b::record('TXT', "a{$seed}x$i", '"audit ' . $i . '"', 300)),
                };
                $r->ok ? $stats['ok']++ : $stats['fail']++;
                $stats['ops'][$k] = ($stats['ops'][$k] ?? 0) + ($r->ok ? 1 : 0);
                if (!$r->ok) {
                    $c = ($r->messages[count($r->messages) - 1] ?? null)?->code ?? '?';
                    $stats['codes'][$c] = ($stats['codes'][$c] ?? 0) + 1;
                }
            }
            out($stats);

        case 'record-create':
            out(res(H2b::records($pdo)->createRecord(H2b::admin(), (int)$a[0], H2b::record('A', $a[1], $a[2]))));

        case 'api-update':
            out(res(H2b::dyndnsApi($pdo)->update('h2b_dynkonto', H2b::DYN_PASSWORD, $a[0], null)));

        case 'stress':
            [$sec, $seed] = [(float)$a[0], (int)$a[1]];
            mt_srand($seed);
            $stats = ['ok' => 0, 'fail' => 0, 'codes' => []];
            $end = microtime(true) + $sec;
            $i = 0;
            while (microtime(true) < $end) {
                $i++;
                $zone = [1, 2, 4][mt_rand(0, 2)];
                $r = match (mt_rand(0, 4)) {
                    0, 1 => H2b::records($pdo)->createRecord(H2b::admin(), $zone, H2b::record('TXT', "s{$seed}x$i", '"stress ' . $i . '"', 300)),
                    2 => (function () use ($pdo, $zone): ServiceResult {
                        $id = H2b::one($pdo, "SELECT id FROM records WHERE zone_id = ? AND name LIKE 's%x%' AND server_id IS NULL ORDER BY RAND() LIMIT 1", [$zone]);
                        return $id === false ? new ServiceResult(true, []) : H2b::records($pdo)->deleteRecords(H2b::admin(), [(int)$id]);
                    })(),
                    3 => H2b::dyndnsApi($pdo)->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.' . mt_rand(100, 200), null),
                    default => H2b::zones($pdo)->updateZone(H2b::admin(), $zone, H2b::zoneInput($pdo, H2b::admin(), $zone, ['ttl' => (string)(3000 + mt_rand(0, 9) * 60)])),
                };
                $r->ok ? $stats['ok']++ : $stats['fail']++;
                if (!$r->ok) {
                    $c = ($r->messages[count($r->messages) - 1] ?? null)?->code ?? '?';
                    $stats['codes'][$c] = ($stats['codes'][$c] ?? 0) + 1;
                }
            }
            out($stats);
    }
} catch (Throwable $e) {
    out(['ok' => false, 'exception' => get_class($e), 'code' => $e instanceof PDOException ? ($e->errorInfo[1] ?? $e->getCode()) : $e->getCode(), 'message' => $e->getMessage()]);
}
fwrite(STDERR, "unbekannte Operation $op\n");
exit(2);
