<?php
/**
 * Datei: tests/history/as4b_test.php
 * Zweck: AS4b (Core-Integration der Agent-Sicherheit und L3-Verifikation) gegen eine isolierte Datenbank
 *        dnsmgr_t21_r<RUN>_as4b<rr|rc> unter REPEATABLE READ (innodb_snapshot_isolation = ON) bzw. READ COMMITTED.
 *        Agent-Antworten kommen aus As4bFakeAgent (kein Netzwerk, kein named); Generator und named-checkzone sind echt.
 *   F  Fencing: Domänenprüfung vor J6/J1 (fehlend, ungültig, abweichend ⇒ exception ohne Journal und Seiteneffekt);
 *      fence_token = Lauf-ID und fence_domain in jedem zone_sync, conf_sync und zone_cleanup; nie an system/status
 *   R  Restore-Abgleich (E81): Höchststand ≥ nächste Lauf-ID ⇒ kein Lauf; off/legacy/nicht erreichbar blockieren nicht;
 *      Admin-Dienst: init-domain nur einmal und nur passend zur Konfiguration, raise-runs nur anheben
 *   L  Lock-Verlust (E83): laufeigene Sperre bzw. Verbindung verloren ⇒ kein weiterer Agent-Request, Ziel run_lock_lost,
 *      Lauf exception, Flags 2 → 1
 *   P  Parser (rein): Protokoll, effect, installierter Stand mit R1, Verifikation mit Herabstufung, Cleanup-Zonen;
 *      Zonenidentität mit Normalisierung (R2), zone_verify: gültig gespeichert, ungültig verworfen (R3)
 *   J  Journal-Rohwerte und Matrix E77/J8: je Fall Journal, Flags und Beobachtung; R1 installierter Stand bei
 *      gescheitertem Reload als Rohwert; Mischbetrieb legacy/as2 im selben Lauf
 *   O  Ordnungsvertrag (K3) und Erhalt des positiven Nachweises
 *   C  Cleanup-Zuordnung (0/1/2 Kandidaten, zugewiesen) und served_serial
 *   M  Monitoring: Fähigkeit, Wasserstand unter dem Publish-Lock, belegter Lock, geänderter Wasserstand; ungültige
 *      Antworten (falsche/fehlende Zone, fehlerhafte Rohwerte) ändern weder last_* noch verified_* (R2/R3)
 *   E  Fehlerinjektion beim Schreiben der Beobachtung (Trigger) ⇒ operatives Ergebnis und Journal unverändert
 *   X  Code × Schema: AS4b strikt nach 007; Schema vor 007 ⇒ fail-closed; AS4a-Code auf dem Schema nach 007 ohne Befund
 *   S  statisch: nur ServerVerificationStore schreibt zone_server_verification
 *
 * Aufruf (root, Testsystem): php as4b_test.php <App-Verzeichnis> <RR|RC> <AS4a-App-Verzeichnis (Rückfallstand)>
 * Umgebung: AS4B_ONLY=F,R,… beschränkt auf Gruppen (Mutationsproben).
 */

require_once __DIR__ . '/h2b_lib.php';

[$app, $iso, $baseApp] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (!is_dir("$baseApp/web")) {
    fwrite(STDERR, "Aufruf: php as4b_test.php <App-Verzeichnis> <RR|RC> <AS4a-App-Verzeichnis>\n");
    exit(2);
}
H2b::init($app, $iso, 'as4b');
define('BIND_BASE_DIR', sys_get_temp_dir() . '/dnsmgr-as4b-bind');
foreach (['FenceContext', 'FenceAdminService', 'AgentResponseParser', 'ServerVerificationStore', 'PublishLedger', 'PublishTargetClassifier'] as $c) {
    require_once "$app/web/inc/app/publish/$c.php";
}
require_once "$app/web/inc/deploy/LegacyBindPublishBackend.php";
require_once "$app/web/inc/deploy/AgentMonitoringBackend.php";
require_once "$app/web/inc/app/monitoring/MonitoringService.php";
require_once "$app/web/inc/app/SchemaCheck.php";
require_once __DIR__ . '/as4b_fake_agent.php';

$only = array_filter(explode(',', (string)getenv('AS4B_ONLY')));
$group = static fn (string $g): bool => $only === [] || in_array($g, $only, true);
$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void {
    global $pass, $fail, $iso;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . "[$iso] $id" . ($detail !== '' ? ' – ' . str_replace("\n", ' | ', $detail) : '') . "\n";
}

const D = H2b::FENCE_DOMAIN;
const IP1 = '192.0.2.1';
const IP2 = '192.0.2.2';
$logs = [];
$svc = static function (PDO $p, As4bFakeAgent $a, ?string $domain = D) use (&$logs): PublishService {
    return new PublishService($p, new LegacyBindPublishBackend($p, $a), static function (string $l, string $m) use (&$logs): void {
        $logs[] = "$l $m";
    }, new FenceContext($domain));
};
$zoneReport = static fn (ServiceResult $r): array => $r->messages[array_key_last($r->messages)]->context;
$one = static fn (PDO $p, string $sql, array $a = []) => H2b::one($p, $sql, $a);
$changed = static fn (PDO $p, int $z): int => (int)H2b::one($p, 'SELECT changed FROM zones WHERE id = ?', [$z]);
$runs = static fn (PDO $p): int => (int)H2b::one($p, 'SELECT COUNT(*) FROM publish_runs');
$target = static fn (PDO $p, int $aid, int $sid): array => H2b::all($p, 'SELECT * FROM zone_publish_targets WHERE attempt_id = ? AND server_id = ?', [$aid, $sid])[0] ?? [];
$lastAttempt = static fn (PDO $p, int $z): int => (int)H2b::one($p, 'SELECT MAX(id) FROM zone_publish_attempts WHERE zone_id = ?', [$z]);
$zsv = static fn (PDO $p, int $z, int $s): ?array => H2b::all($p, 'SELECT * FROM zone_server_verification WHERE zone_id = ? AND server_id = ?', [$z, $s])[0] ?? null;
$publish = static function (PDO $p, As4bFakeAgent $a, int $zone, ?string $domain = D) use ($svc, $zoneReport): array {
    $p->exec("UPDATE zones SET changed = 1 WHERE id = $zone");
    return $zoneReport($svc($p, $a, $domain)->publishZone($zone, 0));
};

$pdo = H2b::reset(true);
echo "AS4b-Tests ($iso) – Datenbank " . H2b::$db . "\n";

// ---------------------------------------------------------------- F: Fencing-Domäne und Token
if ($group('F')) {
    foreach (['F01 Domäne in der Datenbank fehlt' => [null, D], 'F02 Domäne der Konfiguration fehlt' => [D, null],
        'F03 Domänen weichen ab' => [D, '00000000-0000-4000-8000-000000000000'], 'F04 Domäne der Konfiguration ungültig' => [D, 'NICHT-GUELTIG']] as $id => [$dbd, $cfg]) {
        $pdo->prepare('UPDATE system_status SET fence_domain = ? WHERE id = 1')->execute([$dbd]);
        $a = new As4bFakeAgent();
        $n0 = $runs($pdo);
        $r = $publish($pdo, $a, 2, $cfg);
        t("$id ⇒ exception vor J6/J1: kein Lauf, kein Agent-Aufruf, Zone ausstehend", $r['status'] === 'exception' && str_contains((string)$r['exception'], 'Fencing:')
            && $runs($pdo) === $n0 && $a->calls === [] && $changed($pdo, 2) === 1 && (int)$pdo->query("SELECT IS_FREE_LOCK('dnsmanager_publish_all')")->fetchColumn() === 1,
            (string)$r['exception']);
    }
    $pdo->prepare('UPDATE system_status SET fence_domain = ? WHERE id = 1')->execute([D]);
    $a = new As4bFakeAgent();
    $pdo->exec('UPDATE zones SET changed = 1 WHERE id IN (1, 2)');
    $res = $svc($pdo, $a)->publishAll(H2b::admin());
    $runId = (int)H2b::one($pdo, 'SELECT MAX(id) FROM publish_runs');
    $writes = $a->writes();
    $eps = array_count_values(array_column($writes, 'endpoint'));
    $allFenced = $writes !== [] && array_reduce($writes, static fn (bool $c, array $w): bool => $c && ($w['payload']['fence_token'] ?? null) === $runId
        && ($w['payload']['fence_domain'] ?? null) === D, true);
    t('F05 fence_token = Lauf-ID und fence_domain in jedem schreibenden Aufruf', $res->ok && $allFenced && ($eps['/zones/zone_sync.php'] ?? 0) >= 3
        && ($eps['/zones/conf_sync.php'] ?? 0) >= 3 && ($eps['/zones/zone_cleanup.php'] ?? 0) === 2, json_encode($eps));
    t('F06 kein Token außerhalb der schreibenden Endpunkte', !array_filter($a->calls, static fn (array $c): bool => $c['endpoint'] === '/system/status.php' && isset($c['payload']['fence_token'])));
}

// ---------------------------------------------------------------- R: Restore-Abgleich und Admin-Dienst
if ($group('R')) {
    $next = FenceContext::nextRunId($pdo);
    $a = new As4bFakeAgent();
    $a->status[IP1] = ['status' => 'ok', 'fencing' => ['mode' => 'observe', 'high' => $next + 5]];
    $n0 = $runs($pdo);
    $r = $publish($pdo, $a, 2);
    t('R01 Höchststand ≥ nächste Lauf-ID ⇒ kein Lauf (exception), kein schreibender Aufruf', $r['status'] === 'exception' && str_contains((string)$r['exception'], 'Abgleich nach Datenbank-Restore')
        && $runs($pdo) === $n0 && $a->writes() === [], (string)$r['exception']);
    $a = new As4bFakeAgent();
    $a->status[IP1] = ['status' => 'ok', 'fencing' => ['mode' => 'observe', 'high' => $next - 1]];
    $a->status[IP2] = ['status' => 'ok'];
    $r = $publish($pdo, $a, 2);
    $rest = $r['journal']['fencing']['restore']['servers'] ?? [];
    t('R02 Höchststand < nächste Lauf-ID ⇒ Lauf; Agent ohne Höchststand (off) im Bericht, nicht blockierend', $r['status'] === 'published'
        && str_starts_with($rest[1] ?? '', 'ok') && str_starts_with($rest[2] ?? '', 'kein Höchststand'), json_encode($rest));
    $a = new As4bFakeAgent();
    $a->status[IP1] = ['status' => 'ok', 'fencing' => ['mode' => 'enforce', 'high' => $next + 100]];
    $admin = new FenceAdminService($pdo, new FenceContext(D), D, new LegacyBindPublishBackend($pdo, $a));
    t('R03 Admin-Status meldet den Konflikt', !str_starts_with($admin->status()['restore'], 'ok'));
    try {
        $after = $admin->raiseRuns($next + 100);
        t('R04 raise-runs hebt die nächste Lauf-ID über den Höchststand', $after === $next + 101 && FenceContext::nextRunId($pdo) === $next + 101 && $admin->status()['restore'] === 'ok', (string)$after);
    } catch (Throwable $e) {
        t('R04 raise-runs hebt die nächste Lauf-ID über den Höchststand', false, $e->getMessage());
    }
    try {
        $admin->raiseRuns($next);
        t('R05 raise-runs senkt nie', false);
    } catch (RuntimeException $e) {
        t('R05 raise-runs senkt nie', FenceContext::nextRunId($pdo) === $next + 101, $e->getMessage());
    }
    try {
        $admin->initDomain(D);
        t('R06 init-domain bei bereits gesetzter Domäne abgelehnt', false);
    } catch (RuntimeException $e) {
        t('R06 init-domain bei bereits gesetzter Domäne abgelehnt', str_contains($e->getMessage(), 'bereits gesetzt'));
    }
    $pdo->exec('UPDATE system_status SET fence_domain = NULL WHERE id = 1');
    try {
        (new FenceAdminService($pdo, new FenceContext(D), D))->initDomain('11111111-1111-4111-8111-111111111111');
        t('R07 init-domain mit abweichender Domäne abgelehnt', false);
    } catch (RuntimeException $e) {
        t('R07 init-domain mit abweichender Domäne abgelehnt', H2b::one($pdo, 'SELECT fence_domain FROM system_status') === null);
    }
    (new FenceAdminService($pdo, new FenceContext(D), D))->initDomain(D);
    t('R08 init-domain setzt die konfigurierte Domäne', H2b::one($pdo, 'SELECT fence_domain FROM system_status') === D);
}

// ---------------------------------------------------------------- L: Lock-Verlust vor einem Agent-Aufruf
if ($group('L')) {
    $a = new As4bFakeAgent();
    $a->hook = static function (string $ep, ?string $ip) use ($pdo): void {
        if ($ep === '/zones/zone_sync.php' && $ip === IP1) {
            $lock = (string)$pdo->query("SELECT CONCAT('dnsmanager_run_', SUBSTRING(SHA2(DATABASE(), 256), 1, 12), '_', MAX(id)) FROM publish_runs")->fetchColumn();
            $pdo->query("SELECT RELEASE_LOCK('$lock')")->fetchColumn();
        }
    };
    $r = $publish($pdo, $a, 1);   // alpha: Server 1 und 2
    $aid = $lastAttempt($pdo, 1);
    $w = array_column($a->writes(), 'endpoint');
    $t2 = $target($pdo, $aid, 2);
    t('L01 laufeigene Sperre nach dem ersten Aufruf verloren ⇒ kein weiterer Request', $w === ['/zones/zone_sync.php'], json_encode($w));
    t('L02 nächstes Ziel als sicher nicht gesendet (run_lock_lost), Lauf exception, Zone ausstehend', ($t2['zone_sync'] ?? null) === 'failed'
        && ($t2['zone_error_code'] ?? null) === 'run_lock_lost' && $r['status'] === 'exception' && $changed($pdo, 1) === 1, json_encode([$r['status'], $t2['zone_sync'] ?? null]));
    $pdo->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();

    $p2 = H2b::pdo();
    $killer = H2b::pdo();
    $a = new As4bFakeAgent();
    $a->hook = static function (string $ep) use ($p2, $killer): void {
        if ($ep === '/zones/zone_sync.php') {
            $killer->exec('KILL ' . (int)$p2->query('SELECT CONNECTION_ID()')->fetchColumn());
        }
    };
    $p2->exec('UPDATE zones SET changed = 1 WHERE id = 2');
    try {
        $r = $zoneReport($svc($p2, $a)->publishZone(2, 0));
    } catch (Throwable $e) {
        $r = ['status' => 'throw'];
    }
    t('L03 Verbindung des Laufs beendet (KILL) ⇒ kein weiterer Request', array_column($a->writes(), 'endpoint') === ['/zones/zone_sync.php'] && in_array($r['status'], ['exception', 'throw'], true),
        json_encode(array_column($a->writes(), 'endpoint')));
    $p = H2b::pdo();
    t('L04 keine Sperre und keine offene Transaktion zurückgeblieben', (int)$p->query("SELECT IS_FREE_LOCK('dnsmanager_publish_all')")->fetchColumn() === 1 && !H2b::hasOpenTrx($pdo));
    $pdo = H2b::pdo();
    $pdo->exec('UPDATE zones SET changed = 0');
}

// ---------------------------------------------------------------- P: Parser (rein)
if ($group('P')) {
    $R = static fn (int $h, array $b): array => As4bFakeAgent::resp($h, $b);
    $h = str_repeat('a', 64);
    $cOk = ['status' => 'ok'];
    $cUnk = ['status' => 'unknown'];
    $cases = [
        'P01 kein Agent-JSON (Timeout)' => ['zone_sync', As4bFakeAgent::transport(28, 'timeout'), $cUnk, ['protocol' => null]],
        'P02 legacy' => ['zone_sync', $R(200, ['status' => 'success']), $cOk, ['protocol' => 'legacy', 'effect' => null, 'installedSerial' => null]],
        'P03 ungültiges effect ⇒ Protokoll unbestimmt' => ['zone_sync', $R(200, ['status' => 'success', 'effect' => 'bogus']), $cUnk, ['protocol' => null]],
        'P04 as2 ok mit installiertem Stand' => ['zone_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'reload' => ['status' => 'ok'], 'installed' => ['serial' => 7, 'sha256' => $h]]), $cOk,
            ['protocol' => 'as2', 'installedSerial' => 7, 'installedHash' => $h, 'reload' => 'ok']],
        'P05 R1: gescheiterter Reload mit installiertem Stand (unknown)' => ['zone_sync', $R(500, ['status' => 'error', 'effect' => 'applied', 'reload' => ['status' => 'failed'], 'installed' => ['serial' => '4294967295', 'sha256' => $h]]), $cUnk,
            ['installedSerial' => 4294967295, 'reload' => 'failed']],
        'P06 installierter Stand bei effect none verworfen' => ['zone_sync', $R(409, ['status' => 'error', 'effect' => 'none', 'reload' => ['status' => 'failed'], 'installed' => ['serial' => 7, 'sha256' => $h]]), ['status' => 'rejected'], ['installedSerial' => null]],
        'P07 installierter Stand ohne Reload-Bericht verworfen' => ['zone_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'installed' => ['serial' => 7, 'sha256' => $h]]), $cOk, ['installedSerial' => null]],
        'P08 installierte Serial außerhalb 32 Bit verworfen' => ['zone_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'reload' => ['status' => 'ok'], 'installed' => ['serial' => 4294967296, 'sha256' => $h]]), $cOk, ['installedSerial' => null, 'installedHash' => null]],
        'P09 verified gültig' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h]]), $cOk, ['verify' => ['state' => 'verified', 'reason' => null, 'fileSerial' => 9, 'fileHash' => $h, 'servedSerial' => 9]]],
        'P10 verified mit abweichenden Serials ⇒ unknown' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 8, 'file_sha256' => $h]]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P11 verified ohne Hash ⇒ unknown' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 9]]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P12 verified bei Einordnung unknown ⇒ unknown' => ['conf_sync', $R(500, ['status' => 'error', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h]]), $cUnk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P13 serial_differs gültig' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'serial_differs', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 8, 'reason' => 'x']]), $cOk, ['verify' => ['state' => 'serial_differs', 'reason' => 'x', 'fileSerial' => 9, 'fileHash' => null, 'servedSerial' => 8]]],
        'P14 legacy ohne Verifikation' => ['conf_sync', $R(200, ['status' => 'success', 'verification' => ['state' => 'verified']]), $cOk, ['protocol' => 'legacy', 'verify' => null]],
        'P15 Cleanup-Zonen' => ['zone_cleanup', $R(200, ['status' => 'success', 'effect' => 'applied', 'removed_conf' => [], 'removed_zone_files' => [], 'zones' => [
            ['zone' => 'a', 'name' => 'a.test', 'served' => 'absent', 'served_reason' => 'refused_control_ok'],
            ['zone' => 'b', 'name' => 'b.test', 'served' => 'still_served', 'served_reason' => null, 'served_serial' => 5],
            ['zone' => 'c', 'name' => null, 'served' => 'unknown', 'served_reason' => 'zone_name_unresolved']]]), $cOk,
            ['zones' => [['name' => 'a.test', 'served' => 'absent', 'reason' => 'refused_control_ok', 'servedSerial' => null], ['name' => 'b.test', 'served' => 'still_served', 'reason' => null, 'servedSerial' => 5], ['name' => null, 'served' => 'unknown', 'reason' => 'zone_name_unresolved', 'servedSerial' => null]]]],
        // R2: Zonenidentität (angefragt z.test)
        'P17 verified, Zone in anderer Schreibweise (Normalisierung) ⇒ verified' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'zone' => 'Z.Test.', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h]]), $cOk, ['verify' => ['state' => 'verified', 'reason' => null, 'fileSerial' => 9, 'fileHash' => $h, 'servedSerial' => 9]]],
        'P18 verified ohne Zone ⇒ unknown (verification_invalid)' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h]]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P19 verified einer anderen Zone ⇒ unknown (verification_invalid)' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'zone' => 'andere.test', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h]]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P20 verified mit ungültigem Zonennamen ⇒ unknown (verification_invalid)' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'verified', 'zone' => 'z..test', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h]]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P21 serial_differs einer anderen Zone ⇒ unknown (verification_invalid)' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'serial_differs', 'zone' => 'andere.test', 'file_serial' => 9, 'served_serial' => 8]]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P22 unknown der richtigen Zone ⇒ unknown mit Grund des Agenten' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'unknown', 'zone' => 'z.test', 'reason' => 'dns_timeout']]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'dns_timeout', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
        'P23 unknown mit fehlerhaft geformter Serial ⇒ verification_invalid (R3)' => ['conf_sync', $R(200, ['status' => 'success', 'effect' => 'applied', 'verification' => ['state' => 'unknown', 'zone' => 'z.test', 'served_serial' => '007']]), $cOk, ['verify' => ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null]]],
    ];
    foreach ($cases as $id => [$kind, $r, $c, $want]) {
        $p = AgentResponseParser::parse($kind, $r, $c, 'z.test');
        $got = array_intersect_key($p, $want);
        t($id, $got == $want, json_encode($got));
    }
    $zv = static function (array $resp, string $zone = 'z.test'): array {
        $v = AgentResponseParser::parseZoneVerify($resp, $zone, $why);
        return [$v, $why];
    };
    [$v1, $w1] = $zv($R(409, ['status' => 'error', 'code' => 'verification_disabled']));
    [$v2, $w2] = $zv($R(404, ['status' => 'error']));
    t('P16 Zone-verify: 409 (off) und 404 (alter Agent) ⇒ keine Beobachtung (no_result)', $v1 === null && $v2 === null && $w1 === 'no_result' && $w2 === 'no_result');
    $ok = static fn (array $v): array => $R(200, ['status' => 'success', 'verification' => $v]);
    [$v, $w] = $zv($ok(['state' => 'verified', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h]));
    t('P24 Zone-verify gültig, richtige Zone ⇒ verified', ($v['state'] ?? null) === 'verified' && $w === null, json_encode([$v, $w]));
    [$v, $w] = $zv($ok(['state' => 'unknown', 'zone' => 'z.test', 'reason' => 'last_round_unknown']));
    t('P25 Zone-verify gültig mit state unknown ⇒ Beobachtung unknown (wird gespeichert)', ($v['state'] ?? null) === 'unknown' && $v['reason'] === 'last_round_unknown' && $w === null, json_encode([$v, $w]));
    $bad = [
        'P26 falsche Zone' => ['state' => 'verified', 'zone' => 'andere.test', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h],
        'P27 fehlende Zone' => ['state' => 'verified', 'file_serial' => 9, 'served_serial' => 9, 'file_sha256' => $h],
        'P28 unknown einer anderen Zone' => ['state' => 'unknown', 'zone' => 'andere.test', 'reason' => 'dns_timeout'],
        'P29 unvollständig (verified ohne Hash)' => ['state' => 'verified', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 9],
        'P30 widersprüchlich (verified, Serials verschieden)' => ['state' => 'verified', 'zone' => 'z.test', 'file_serial' => 9, 'served_serial' => 8, 'file_sha256' => $h],
        'P31 unbekannter Zustand' => ['state' => 'gut', 'zone' => 'z.test'],
        'P32 fehlerhaft geformte Serial bei unknown' => ['state' => 'unknown', 'zone' => 'z.test', 'file_serial' => -1],
    ];
    foreach ($bad as $id => $obj) {
        [$v, $w] = $zv($ok($obj));
        t("$id ⇒ Zone-verify verworfen (invalid), nicht herabgestuft", $v === null && $w === 'invalid', json_encode([$v, $w]));
    }
    [$v, $w] = $zv($R(200, ['status' => 'success', 'verification' => 'kaputt']));
    t('P33 Verifikation kein Objekt ⇒ verworfen (invalid)', $v === null && $w === 'invalid');
}

// ---------------------------------------------------------------- J: Journal-Rohwerte, Matrix E77/J8, R1, Mischbetrieb
if ($group('J')) {
    $pdo->exec('DELETE FROM zone_server_verification');
    $R = static fn (int $h, array $b): array => As4bFakeAgent::resp($h, $b);
    // Matrix je conf_sync-Antwort für beta (Server 1): [Antwort, Journal conf_sync, conf_protocol, verify, changed, Beobachtung]
    $matrix = [
        'J01 nicht zugestellt' => [As4bFakeAgent::transport(7, 'refused'), 'failed', null, null, 1, null],
        'J02 Zeitüberschreitung' => [As4bFakeAgent::transport(28, 'timeout'), 'unknown', null, null, 1, ['unknown', 'agent_timeout']],
        'J03 Ablehnung stale_fence' => [$R(409, ['status' => 'error', 'code' => 'stale_fence', 'effect' => 'none', 'fence' => ['mode' => 'enforce', 'decision' => 'reject', 'code' => 'stale_fence']]), 'rejected', 'as2', null, 1, null],
        'J04 Reload gescheitert' => [$R(500, ['status' => 'error', 'code' => 'reload_failed', 'effect' => 'applied', 'reload' => ['status' => 'failed'], 'verification' => ['state' => 'unknown', 'zone' => 'beta.h2b.test', 'reason' => 'reload_failed']]), 'unknown', 'as2', 'unknown', 1, ['unknown', 'reload_failed']],
        'J05 legacy' => [$R(200, ['status' => 'success']), 'ok', 'legacy', null, 0, null],
        'J06 as2 ohne Verifikation (AS2a)' => [$R(200, ['status' => 'success', 'effect' => 'applied', 'reload' => ['status' => 'ok']]), 'ok', 'as2', null, 0, null],
        'J09 serial_differs' => [static fn (array $pl, As4bFakeAgent $a) => $R(200, ['status' => 'success', 'effect' => 'applied', 'reload' => ['status' => 'ok'], 'verification' => ['state' => 'serial_differs', 'zone' => 'beta.h2b.test', 'file_serial' => $a->files[IP1 . '|beta.h2b.test']['serial'], 'served_serial' => 1, 'file_sha256' => $a->files[IP1 . '|beta.h2b.test']['sha']]]), 'ok', 'as2', 'serial_differs', 0, ['serial_differs', null]],
        'J10 verification unknown' => [$R(200, ['status' => 'success', 'effect' => 'applied', 'reload' => ['status' => 'ok'], 'verification' => ['state' => 'unknown', 'zone' => 'beta.h2b.test', 'reason' => 'last_round_unknown:dns_timeout']]), 'ok', 'as2', 'unknown', 0, ['unknown', 'last_round_unknown:dns_timeout']],
    ];
    foreach ($matrix as $id => [$resp, $jConf, $proto, $vstate, $chg, $obs]) {
        $a = new As4bFakeAgent();
        $a->modes[IP1] = 'as2';
        $a->queue['/zones/conf_sync.php|' . IP1] = [$resp];
        $before = $zsv($pdo, 2, 1);
        $r = $publish($pdo, $a, 2);
        $t = $target($pdo, $lastAttempt($pdo, 2), 1);
        $after = $zsv($pdo, 2, 1);
        $obsOk = $obs === null ? $after == $before : ($after !== null && $after['last_state'] === $obs[0] && $after['last_reason'] === $obs[1] && $after['last_method'] === 'conf_sync');
        t("$id: Journal, Flags und Beobachtung nach Matrix", ($t['conf_sync'] ?? null) === $jConf && ($t['conf_protocol'] ?? null) === $proto
            && ($t['conf_verify_state'] ?? null) === $vstate && $changed($pdo, 2) === $chg && $obsOk && ($after['last_state'] ?? null) !== 'verified',
            json_encode([$r['status'], $t['conf_sync'] ?? null, $t['conf_protocol'] ?? null, $t['conf_verify_state'] ?? null, $changed($pdo, 2), $after['last_state'] ?? null, $after['last_reason'] ?? null]));
        $pdo->exec('UPDATE zones SET changed = 0 WHERE id = 2');
    }
    // J07: verified mit Hash des Versuchs ⇒ Beobachtung und positiver Nachweis
    $a = new As4bFakeAgent();
    $a->modes[IP1] = 'as2';
    $r = $publish($pdo, $a, 2);
    $aid = $lastAttempt($pdo, 2);
    $att = H2b::all($pdo, 'SELECT run_id, file_hash, soa_serial FROM zone_publish_attempts WHERE id = ?', [$aid])[0];
    $t = $target($pdo, $aid, 1);
    $z = $zsv($pdo, 2, 1);
    t('J07 verified mit Datei des Versuchs: Journal-Rohwerte, Beobachtung und positiver Nachweis (Lauf, Versuch)', $r['status'] === 'published'
        && $t['zone_protocol'] === 'as2' && (int)$t['zone_installed_serial'] === (int)$att['soa_serial'] && $t['zone_installed_hash'] === $att['file_hash']
        && $t['conf_verify_state'] === 'verified' && $t['conf_verify_file_hash'] === $att['file_hash'] && $t['zone_fence_decision'] === 'accept'
        && $z['last_state'] === 'verified' && (int)$z['verified_serial'] === (int)$att['soa_serial'] && $z['verified_file_hash'] === $att['file_hash']
        && (int)$z['verified_run_id'] === (int)$att['run_id'] && (int)$z['last_attempt_id'] === $aid && $changed($pdo, 2) === 0, json_encode($z));
    // J08: verified, aber andere Datei (Hash ≠ Versuch) ⇒ Fakt über die verwaltete Datei, Versuch nicht verifiziert
    $a = new As4bFakeAgent();
    $a->modes[IP1] = 'as2';
    $other = str_repeat('b', 64);
    $a->queue['/zones/conf_sync.php|' . IP1] = [static fn (array $pl, As4bFakeAgent $ag) => $R(200, ['status' => 'success', 'effect' => 'applied', 'reload' => ['status' => 'ok'],
        'verification' => ['state' => 'verified', 'zone' => 'beta.h2b.test', 'file_serial' => 123, 'served_serial' => 123, 'file_sha256' => $other]])];
    $publish($pdo, $a, 2);
    $aid = $lastAttempt($pdo, 2);
    $fh = H2b::one($pdo, 'SELECT file_hash FROM zone_publish_attempts WHERE id = ?', [$aid]);
    $z = $zsv($pdo, 2, 1);
    t('J08 verified mit fremdem Datei-Hash: gespeichert als Fakt, ungleich dem file_hash des Versuchs', $z['last_state'] === 'verified' && $z['verified_file_hash'] === $other && $other !== $fh);
    // J13/J14 R2: verified für eine andere bzw. ohne Zone ⇒ kein positiver Nachweis in Journal und zone_server_verification
    foreach (['J13 falsche Zone' => 'gamma.h2b.test', 'J14 fehlende Zone' => null] as $id => $wrongZone) {
        $before = $zsv($pdo, 2, 1);
        $a = new As4bFakeAgent();
        $a->modes[IP1] = 'as2';
        $a->queue['/zones/conf_sync.php|' . IP1] = [static fn (array $pl, As4bFakeAgent $ag) => $R(200, ['status' => 'success', 'effect' => 'applied', 'reload' => ['status' => 'ok'],
            'verification' => array_filter(['state' => 'verified', 'zone' => $wrongZone, 'file_serial' => $ag->files[IP1 . '|beta.h2b.test']['serial'],
                'served_serial' => $ag->files[IP1 . '|beta.h2b.test']['serial'], 'file_sha256' => $ag->files[IP1 . '|beta.h2b.test']['sha']], static fn ($x) => $x !== null)])];
        $r = $publish($pdo, $a, 2);
        $t = $target($pdo, $lastAttempt($pdo, 2), 1);
        $z = $zsv($pdo, 2, 1);
        $proofKeys = array_flip(['verified_serial', 'verified_file_hash', 'verified_run_id', 'verified_at']);
        t("$id: Journal unknown/verification_invalid, Beobachtung unknown, positiver Nachweis unverändert, operativ veröffentlicht (E77)",
            $t['conf_sync'] === 'ok' && $t['conf_verify_state'] === 'unknown' && $t['conf_verify_reason'] === 'verification_invalid'
            && $t['conf_verify_file_hash'] === null && $z['last_state'] === 'unknown' && $z['last_reason'] === 'verification_invalid'
            && array_intersect_key($z, $proofKeys) == array_intersect_key($before, $proofKeys) && $r['status'] === 'published' && $changed($pdo, 2) === 0,
            json_encode([$t['conf_verify_state'], $t['conf_verify_reason'], $z['last_state'], $z['verified_run_id'], $before['verified_run_id'] ?? null]));
    }
    // J11 R1: zone_sync mit gescheitertem Reload ⇒ installierter Stand als Rohwert, Ziel unknown, Zone ausstehend
    $a = new As4bFakeAgent();
    $a->modes[IP1] = 'as2';
    $a->queue['/zones/zone_sync.php|' . IP1] = [static fn (array $pl, As4bFakeAgent $ag) => $R(500, ['status' => 'error', 'code' => 'reload_failed', 'effect' => 'applied', 'rollback' => 'not_attempted',
        'reload' => ['status' => 'failed', 'exit_code' => 1], 'installed' => ['serial' => $ag->files[IP1 . '|beta.h2b.test']['serial'], 'sha256' => $ag->files[IP1 . '|beta.h2b.test']['sha']]])];
    $r = $publish($pdo, $a, 2);
    $t = $target($pdo, $lastAttempt($pdo, 2), 1);
    t('J11 R1: installierter Stand bei gescheitertem Reload gespeichert, Ziel unknown, Zone ausstehend (kein Nachweis)', $t['zone_sync'] === 'unknown'
        && $t['zone_reload'] === 'failed' && $t['zone_installed_serial'] !== null && $t['zone_installed_hash'] !== null && $changed($pdo, 2) === 1, json_encode([$t['zone_sync'], $t['zone_reload'], $r['status']]));
    $pdo->exec('UPDATE zones SET changed = 0');
    // J12 Mischbetrieb: alpha auf Server 1 (as2) und Server 2 (legacy) im selben Lauf
    $a = new As4bFakeAgent();
    $a->modes = [IP1 => 'as2', IP2 => 'legacy'];
    $r = $publish($pdo, $a, 1);
    $aid = $lastAttempt($pdo, 1);
    $t1 = $target($pdo, $aid, 1);
    $t2 = $target($pdo, $aid, 2);
    t('J12 Mischbetrieb: as2 mit Nachweis, legacy ohne AS2-Felder und ohne Beobachtung', $r['status'] === 'published' && $t1['conf_protocol'] === 'as2' && $t2['conf_protocol'] === 'legacy'
        && $t2['zone_installed_serial'] === null && $zsv($pdo, 1, 1)['last_state'] === 'verified' && $zsv($pdo, 1, 2) === null, json_encode([$t1['conf_protocol'], $t2['conf_protocol']]));
    $pdo->exec('UPDATE zones SET changed = 0');
}

// ---------------------------------------------------------------- O: Ordnungsvertrag und positiver Nachweis
if ($group('O')) {
    $row = static fn (int $k, string $m): array => ['last_run_id' => $k ?: null, 'last_method' => $m];
    $cases = [
        'O01 keine Zeile' => [null, 'conf_sync', 5, true], 'O02 größerer Schlüssel' => [$row(4, 'monitoring'), 'conf_sync', 5, true],
        'O03 kleinerer Schlüssel (veraltet)' => [$row(6, 'conf_sync'), 'monitoring', 5, false],
        'O04 conf_sync → cleanup desselben Laufs' => [$row(5, 'conf_sync'), 'cleanup', 5, true],
        'O05 cleanup → conf_sync desselben Laufs' => [$row(5, 'cleanup'), 'conf_sync', 5, false],
        'O06 Publish → Monitoring mit W = R' => [$row(5, 'conf_sync'), 'monitoring', 5, true],
        'O07 Monitoring W → Publish R = W' => [$row(5, 'monitoring'), 'conf_sync', 5, false],
        'O08 Monitoring → Monitoring gleicher W' => [$row(5, 'monitoring'), 'monitoring', 5, true],
        'O09 conf_sync → conf_sync desselben Laufs' => [$row(5, 'conf_sync'), 'conf_sync', 5, false],
        'O10 Monitoring ohne Lauf (W = 0) nach Zeile ohne Lauf' => [$row(0, 'monitoring'), 'monitoring', 0, true],
    ];
    foreach ($cases as $id => [$r, $m, $k, $want]) {
        t("$id ⇒ " . ($want ? 'annehmen' : 'ablehnen'), ServerVerificationStore::accepts($r, $m, $k) === $want);
    }
    $store = new ServerVerificationStore($pdo);
    $pdo->exec('DELETE FROM zone_server_verification');
    $h1 = str_repeat('c', 64);
    $h2 = str_repeat('d', 64);
    $W = (int)H2b::one($pdo, 'SELECT MAX(id) FROM publish_runs');
    $put = static fn (int $w, array $obs): array => $store->recordMonitoring($w, [['zoneId' => 3, 'zoneName' => '2.0.192.in-addr.arpa', 'serverId' => 1, 'serverName' => 'ns1.h2b.test', 'obs' => $obs]]);
    $put($W, ['state' => 'verified', 'served' => 10, 'fileSerial' => 10, 'fileHash' => $h1]);
    $proof = static fn (): array => array_intersect_key($zsv($pdo, 3, 1), array_flip(['verified_serial', 'verified_file_hash', 'verified_run_id', 'verified_at']));
    $p0 = $proof();
    foreach (['unknown' => ['state' => 'unknown', 'reason' => 'dns_timeout'], 'serial_differs' => ['state' => 'serial_differs', 'served' => 9, 'fileSerial' => 11],
        'still_served' => ['state' => 'still_served', 'served' => 9], 'absent' => ['state' => 'absent']] as $st => $obs) {
        $put($W, $obs);
        t("O11 Beobachtung $st lässt den positiven Nachweis unverändert", $zsv($pdo, 3, 1)['last_state'] === $st && $proof() == $p0);
    }
    $n = $put($W - 1, ['state' => 'verified', 'served' => 99, 'fileSerial' => 99, 'fileHash' => $h2]);
    t('O12 veraltete Monitoring-Beobachtung (W kleiner) abgelehnt, nichts geändert', $n['stale'] === 1 && $zsv($pdo, 3, 1)['last_state'] === 'absent' && $proof() == $p0);
    $put($W, ['state' => 'verified', 'served' => 12, 'fileSerial' => 12, 'fileHash' => $h2]);
    $z = $zsv($pdo, 3, 1);
    t('O13 neue verified-Beobachtung ersetzt den positiven Nachweis (verified_run_id NULL beim Monitoring)', (int)$z['verified_serial'] === 12 && $z['verified_file_hash'] === $h2
        && $z['verified_run_id'] === null && $z['verified_at'] === $z['last_observed_at'] && $z['verified_at'] >= $p0['verified_at']);
    // Stresslauf: zufällige Beobachtungen, Invariante "verified_* ändert sich nur bei verified"
    $okInv = true;
    for ($i = 0; $i < 60; $i++) {
        $before = $proof();
        $st = ['unknown', 'serial_differs', 'still_served', 'absent', 'verified'][random_int(0, 4)];
        $s = random_int(1, 50);
        $obs = match ($st) {
            'verified' => ['state' => 'verified', 'served' => $s, 'fileSerial' => $s, 'fileHash' => str_repeat(dechex(random_int(0, 15)), 64)],
            'serial_differs' => ['state' => 'serial_differs', 'served' => $s, 'fileSerial' => $s + 1],
            'still_served' => ['state' => 'still_served', 'served' => $s],
            'absent' => ['state' => 'absent'],
            default => ['state' => 'unknown'],
        };
        $put($W, $obs);
        $okInv = $okInv && ($st === 'verified' || $proof() == $before) && ($st !== 'verified' || (int)$proof()['verified_serial'] === $s);
    }
    t('O14 Stresslauf (60 Beobachtungen): positiver Nachweis ändert sich ausschließlich bei verified', $okInv);
}

// ---------------------------------------------------------------- C: Cleanup-Zuordnung und served_serial
if ($group('C')) {
    $pdo->exec('DELETE FROM zone_server_verification');
    $ins = static fn (int $z, int $s, string $name) => $pdo->exec("INSERT INTO zone_server_verification (zone_id, server_id, zone_name, server_name, last_state, last_method, last_observed_at)"
        . " VALUES ($z, $s, '$name', 'ns$s.h2b.test', 'unknown', 'monitoring', NOW(6))");
    $ins(901, 1, 'weg.h2b.test');            // eindeutig, nicht zugewiesen
    $ins(902, 1, 'doppelt.h2b.test');
    $ins(903, 1, 'doppelt.h2b.test');        // zwei Kandidaten
    $ins(2, 1, 'beta.h2b.test');             // zugewiesen
    $ins(904, 1, 'serial.h2b.test');
    $ins(905, 1, 'ohneserial.h2b.test');
    $a = new As4bFakeAgent();
    $a->modes = [IP1 => 'as2', IP2 => 'as2'];
    $a->queue['/zones/zone_cleanup.php|' . IP1] = [As4bFakeAgent::resp(200, ['status' => 'success', 'effect' => 'applied', 'removed_conf' => ['x'], 'removed_zone_files' => ['y'], 'zones' => [
        ['zone' => 'w', 'name' => 'weg.h2b.test', 'served' => 'absent', 'served_reason' => 'refused_control_ok'],
        ['zone' => 'd', 'name' => 'doppelt.h2b.test', 'served' => 'absent', 'served_reason' => null],
        ['zone' => 'b', 'name' => 'beta.h2b.test', 'served' => 'absent', 'served_reason' => null],
        ['zone' => 's', 'name' => 'serial.h2b.test', 'served' => 'still_served', 'served_reason' => 'still_configured', 'served_serial' => 77],
        ['zone' => 'o', 'name' => 'ohneserial.h2b.test', 'served' => 'still_served', 'served_reason' => null],
        ['zone' => 'u', 'name' => null, 'served' => 'unknown', 'served_reason' => 'zone_name_unresolved'],
        ['zone' => 'f', 'name' => 'fremd.h2b.test', 'served' => 'absent', 'served_reason' => null]]])];
    $pdo->exec('UPDATE zones SET changed = 1 WHERE id = 2');
    $res = $svc($pdo, $a)->publishAll(H2b::admin());
    $run = (int)H2b::one($pdo, 'SELECT MAX(id) FROM publish_runs');
    $pc = H2b::all($pdo, 'SELECT * FROM publish_cleanup_results WHERE run_id = ? AND server_id = 1', [$run])[0] ?? [];
    $state = static fn (int $z): ?string => H2b::one($pdo, 'SELECT last_state FROM zone_server_verification WHERE zone_id = ? AND server_id = 1', [$z]);
    t('C01 eindeutig zuordenbar: absent gespeichert (Quelle cleanup, Lauf)', $state(901) === 'absent' && (int)H2b::one($pdo, 'SELECT last_run_id FROM zone_server_verification WHERE zone_id = 901') === $run);
    t('C02 zwei Kandidaten gleichen Namens: nicht gespeichert', $state(902) === 'unknown' && $state(903) === 'unknown');
    t('C03 noch zugewiesene Zone: nicht gespeichert', H2b::one($pdo, "SELECT last_method FROM zone_server_verification WHERE zone_id = 2 AND server_id = 1") !== 'cleanup');
    t('C04 still_served mit served_serial gespeichert', $state(904) === 'still_served' && (int)H2b::one($pdo, 'SELECT last_served_serial FROM zone_server_verification WHERE zone_id = 904') === 77);
    t('C05 still_served ohne served_serial ⇒ unknown (still_served_no_serial), kein erfundener Wert', $state(905) === 'unknown'
        && H2b::one($pdo, 'SELECT last_reason FROM zone_server_verification WHERE zone_id = 905') === 'still_served_no_serial');
    t('C06 Journal-Zähler: absent 4, still 2, unknown 1, nicht zuordenbar 4 (keine Neuanlage)', $res->ok && $pc['cleanup_protocol'] === 'as2' && (int)$pc['served_absent_count'] === 4
        && (int)$pc['served_still_count'] === 2 && (int)$pc['served_unknown_count'] === 1 && (int)$pc['unassigned_count'] === 4
        && (int)H2b::one($pdo, "SELECT COUNT(*) FROM zone_server_verification WHERE zone_name = 'fremd.h2b.test'") === 0, json_encode(array_intersect_key($pc, array_flip(['served_absent_count', 'served_still_count', 'served_unknown_count', 'unassigned_count']))));
    $pdo->exec('DELETE FROM zone_server_verification WHERE zone_id >= 900');
    $pdo->exec('UPDATE zones SET changed = 0');
}

// ---------------------------------------------------------------- M: Monitoring (Wasserstand, Fähigkeit, Konkurrenz)
if ($group('M')) {
    $pdo->exec('DELETE FROM zone_server_verification');
    $mk = static function () {
        $a = new As4bFakeAgent();
        $a->modes = [IP1 => 'as2', IP2 => 'as2'];
        $a->status[IP1] = ['status' => 'ok', 'capabilities' => ['fencing' => 1, 'zone_verify' => 1], 'fencing' => ['mode' => 'observe']];
        $a->status[IP2] = ['status' => 'ok'];   // off: keine Fähigkeit
        foreach (['alpha.h2b.test', 'beta.h2b.test', 'gamma.h2b.test', '2.0.192.in-addr.arpa'] as $zn) {
            $a->files[IP1 . '|' . $zn] = ['serial' => 2026112201, 'sha' => str_repeat('e', 64)];
        }
        return $a;
    };
    $notifier = new class implements MonitoringNotifier {
        public function send(string $subject, string $body): array { return ['status' => 'ok']; }
    };
    $mon = static function (As4bFakeAgent $a) use ($pdo, $notifier): array {
        $m = (new MonitoringService($pdo, new AgentMonitoringBackend($a, false), $notifier, false))->run()->messages;
        return $m[array_key_last($m)]->context;
    };
    $a = $mk();
    $W = (int)H2b::one($pdo, 'SELECT COALESCE(MAX(id), 0) FROM publish_runs');
    $r = $mon($a);
    $verify = array_filter($a->calls, static fn (array $c): bool => $c['endpoint'] === '/zones/zone_verify.php');
    $rows = H2b::all($pdo, "SELECT * FROM zone_server_verification WHERE last_method = 'monitoring'");
    t('M01 zone_verify nur an Server mit Fähigkeit (observe), nicht an off', $verify !== [] && !array_filter($verify, static fn (array $c): bool => $c['ip'] !== IP1), (string)count($verify));
    t('M02 Beobachtungen mit Wasserstand W als Ordnungsschlüssel, verified_run_id NULL', ($r['l3']['status'] ?? null) === 'ok' && $rows !== []
        && !array_filter($rows, static fn (array $z): bool => (int)$z['last_run_id'] !== $W || $z['verified_run_id'] !== null || (int)$z['server_id'] !== 1), json_encode($r['l3'] ?? null));
    $holder = H2b::pdo();
    $holder->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    $pdo->exec('DELETE FROM zone_server_verification');
    $a = $mk();
    $r = $mon($a);
    t('M03 Publish-Lock zu Beginn belegt ⇒ keine Abfrage, keine Beobachtung', ($r['l3']['status'] ?? null) === 'publish_active'
        && !array_filter($a->calls, static fn (array $c): bool => $c['endpoint'] === '/zones/zone_verify.php') && (int)H2b::one($pdo, 'SELECT COUNT(*) FROM zone_server_verification') === 0);
    $holder->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
    $a = $mk();
    $other = H2b::pdo();
    $done = false;
    $a->hook = static function (string $ep) use ($other, &$done): void {
        if ($ep === '/zones/zone_verify.php' && !$done) {
            $done = true;   // zwischenzeitlicher Lauf: neue Lauf-ID während der Abfragen
            $other->exec("INSERT INTO publish_runs (kind, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id, status, result, finished_at)"
                . " VALUES ('all', 0, 1, 0, 'system', 'cli', '33333333-3333-4333-8333-333333333333', 'finished', 'success', NOW(6))");
        }
    };
    $r = $mon($a);
    t('M04 Wasserstand während der Abfragen geändert ⇒ alle Beobachtungen verworfen', ($r['l3']['status'] ?? null) === 'discarded' && (int)H2b::one($pdo, 'SELECT COUNT(*) FROM zone_server_verification') === 0, json_encode($r['l3'] ?? null));
    $holder2 = H2b::pdo();
    $a = $mk();
    $a->hook = static function (string $ep) use ($holder2): void {
        if ($ep === '/zones/zone_verify.php') {
            $holder2->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();   // Lauf beginnt während der Abfragen
        }
    };
    $r = $mon($a);
    $holder2->query('SELECT RELEASE_ALL_LOCKS()')->fetchColumn();   // GET_LOCK je zone_verify-Aufruf: verschachtelt
    t('M05 Publish-Lock bei der Speicherung belegt ⇒ verworfen', ($r['l3']['status'] ?? null) === 'discarded' && (int)H2b::one($pdo, 'SELECT COUNT(*) FROM zone_server_verification') === 0);
    // M07–M09 R2/R3: gültige Beobachtung speichern, ungültige (falsche/fehlende Zone, unvollständig, widersprüchlich) verwerfen
    $pdo->exec('DELETE FROM zone_server_verification');
    $mon($mk());   // Ausgangsstand: alle vier Zonen auf Server 1 verified
    $snapRows = static fn (): array => array_column(H2b::all($pdo, 'SELECT zone_name, last_state, last_reason, last_observed_at, verified_serial, verified_file_hash, verified_at FROM zone_server_verification WHERE server_id = 1'), null, 'zone_name');
    $s0 = $snapRows();
    $a = $mk();
    $hh = str_repeat('e', 64);
    $answers = [
        'beta.h2b.test' => ['state' => 'verified', 'zone' => 'gamma.h2b.test', 'file_serial' => 2026112202, 'served_serial' => 2026112202, 'file_sha256' => $hh],
        'alpha.h2b.test' => ['state' => 'verified', 'file_serial' => 2026112202, 'served_serial' => 2026112202, 'file_sha256' => $hh],
        'gamma.h2b.test' => ['state' => 'unknown', 'zone' => 'gamma.h2b.test', 'served_serial' => 'abc'],
        '2.0.192.in-addr.arpa' => ['state' => 'unknown', 'zone' => '2.0.192.in-addr.arpa', 'reason' => 'dns_timeout'],
    ];
    $a->queue['/zones/zone_verify.php|' . IP1] = array_fill(0, 4, static fn (array $pl) => As4bFakeAgent::resp(200, ['status' => 'success', 'verification' => $answers[$pl['zone_name']]]));
    $r = $mon($a);
    $s1 = $snapRows();
    t('M07 falsche Zone (beta), fehlende Zone (alpha), fehlerhafte Serial (gamma) ⇒ verworfen: last_* und verified_* unverändert',
        $s1['beta.h2b.test'] == $s0['beta.h2b.test'] && $s1['alpha.h2b.test'] == $s0['alpha.h2b.test'] && $s1['gamma.h2b.test'] == $s0['gamma.h2b.test'], json_encode([$s0['beta.h2b.test'] ?? null, $s1['beta.h2b.test'] ?? null]));
    $rev0 = $s0['2.0.192.in-addr.arpa'];
    $rev1 = $s1['2.0.192.in-addr.arpa'];
    t('M08 gültiges unknown (richtige Zone) gespeichert, positiver Nachweis erhalten', $rev1['last_state'] === 'unknown' && $rev1['last_reason'] === 'dns_timeout'
        && $rev1['verified_serial'] === $rev0['verified_serial'] && $rev1['verified_at'] === $rev0['verified_at'], json_encode($rev1));
    t('M09 Verwerfen nachvollziehbar im Bericht (invalid = 3, gespeichert = 1)', ($r['l3']['invalid'] ?? null) === 3 && ($r['l3']['stored']['stored'] ?? null) === 1
        && count($r['l3']['invalidPairs'] ?? []) === 3, json_encode($r['l3'] ?? null));
    t('M06 Diagnosen unverändert erhoben, Monitoring-Lock frei', in_array($r['status'] ?? null, ['ok'], true) && (int)$pdo->query("SELECT IS_FREE_LOCK('dnsmanager_monitoring')")->fetchColumn() === 1, (string)($r['status'] ?? ''));
}

// ---------------------------------------------------------------- E: Fehlerinjektion beim Schreiben der Beobachtung
if ($group('E')) {
    $pdo->exec('DELETE FROM zone_server_verification');
    $pdo->exec("CREATE TRIGGER as4b_fail BEFORE INSERT ON zone_server_verification FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'as4b_injected'");
    $a = new As4bFakeAgent();
    $a->modes[IP1] = 'as2';
    $logs = [];
    $r = $publish($pdo, $a, 2);
    $t = $target($pdo, $lastAttempt($pdo, 2), 1);
    $pdo->exec('DROP TRIGGER as4b_fail');
    t('E01 Schreibfehler der Beobachtung: Publish published, Journal mit Rohwerten, keine Beobachtung, Bericht und Logzeile', $r['status'] === 'published'
        && $t['conf_verify_state'] === 'verified' && $zsv($pdo, 2, 1) === null && ($r['journal']['verification']['failed'] ?? 0) === 1
        && array_filter($logs, static fn (string $l): bool => str_contains($l, '[publish] Verifikation:') && str_contains($l, 'as4b_injected')) !== [], json_encode([$r['status'], $r['exception'], $r['journal']['verification'] ?? null, $t['conf_verify_state'] ?? null]));
    t('E02 keine offene Transaktion, Sperren frei', !H2b::hasOpenTrx($pdo) && (int)$pdo->query("SELECT IS_FREE_LOCK('dnsmanager_publish_all')")->fetchColumn() === 1);
}

// ---------------------------------------------------------------- X: Code × Schema
if ($group('X')) {
    $problemsOf = static function (string $appDir): ?array {
        $code = 'require ' . var_export("$appDir/web/inc/app/SchemaCheck.php", true) . ';'
            . '$p = new PDO("mysql:unix_socket=' . H2b::SOCK . ';dbname=' . H2b::$db . ';charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            . 'echo json_encode(SchemaCheck::problems($p));';
        $r = json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1'), true);
        return is_array($r) ? $r : null;
    };
    $p = $problemsOf($app);
    t('X01 AS4b auf dem Schema nach 007: ohne Befund', $p === [], json_encode($p));
    $p = $problemsOf($baseApp);
    t('X02 AS4a-Code (Rückfallstand) auf dem Schema nach 007: ohne Befund', $p === [], json_encode($p));
    $pdo->exec('ALTER TABLE publish_cleanup_results DROP CONSTRAINT chk_pcr_agent, DROP CONSTRAINT chk_pcr_fence, DROP CONSTRAINT chk_pcr_served, DROP COLUMN cleanup_protocol, DROP COLUMN cleanup_effect, DROP COLUMN fence_decision, DROP COLUMN fence_code, DROP COLUMN served_absent_count, DROP COLUMN served_still_count, DROP COLUMN served_unknown_count, DROP COLUMN unassigned_count');
    $pdo->exec('ALTER TABLE system_status DROP CONSTRAINT chk_ss_fence_domain, DROP COLUMN fence_domain');
    $p = $problemsOf($app);
    t('X03 AS4b auf einem Schema ohne 007b/007c: fail-closed (genau diese Befunde)', is_array($p) && count($p) === 2
        && $p[0] === 'system_status.fence_domain fehlt' && str_starts_with($p[1], 'publish_cleanup_results: Struktur weicht ab'), json_encode($p));
    $p = $problemsOf($baseApp);
    t('X04 AS4a-Code auf demselben Teilzustand: ohne Befund (Brücke)', $p === [], json_encode($p));
    $rows = SchemaCheck::historySignatureRows($pdo);
    t('X05 Migrationswerkzeug (acceptPre007) wertet den Teilzustand als ok, strikt als mismatch',
        SchemaCheck::evaluateHistory($rows, true)['publish_cleanup_results']['state'] === 'ok' && SchemaCheck::evaluateHistory($rows)['publish_cleanup_results']['state'] === 'mismatch');
}

// ---------------------------------------------------------------- S: statische Nachweise
if ($group('S')) {
    $hits = trim((string)shell_exec('grep -rlE ' . escapeshellarg('(INSERT INTO|UPDATE) zone_server_verification') . ' ' . escapeshellarg("$app/web") . ' 2>/dev/null'));
    t('S01 zone_server_verification wird nur von ServerVerificationStore geschrieben', $hits === "$app/web/inc/app/publish/ServerVerificationStore.php", $hits);
    $hits = trim((string)shell_exec('grep -rlE ' . escapeshellarg('(INSERT INTO|UPDATE) (zone_publish_targets|publish_cleanup_results|zone_publish_attempts|publish_runs)') . ' ' . escapeshellarg("$app/web") . ' 2>/dev/null'));
    $allowed = ["$app/web/inc/app/publish/PublishLedger.php", "$app/web/inc/app/publish/FenceAdminService.php"];
    t('S02 Journaltabellen nur im Ledger geschrieben (FenceAdminService: nur ALTER AUTO_INCREMENT)', array_diff(array_filter(explode("\n", $hits)), $allowed) === []
        && !str_contains((string)file_get_contents("$app/web/inc/app/publish/FenceAdminService.php"), 'INSERT INTO publish_runs'), $hits);
    $hits = trim((string)shell_exec('grep -rn ' . escapeshellarg('fence_token') . ' ' . escapeshellarg("$app/web") . ' 2>/dev/null | grep -v "^\s*\*"'));
    t('S03 fence_token nur aus PublishJournal::beforeAgentCall', count(array_filter(explode("\n", $hits), static fn (string $l): bool => str_contains($l, "'fence_token' =>"))) === 1, $hits);
}

echo "ERGEBNIS as4b ($iso): $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
