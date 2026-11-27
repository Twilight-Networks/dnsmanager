<?php
/**
 * Datei: tests/history/as5_test.php
 * Zweck: AS5 (RFC-1982-Serial-Strategie, docs/ARCHITECTURE_HISTORY.md Abschnitt 5k) gegen eine isolierte Datenbank
 *        dnsmgr_t21_r<RUN>_as5<rr|rc> unter REPEATABLE READ (innodb_snapshot_isolation = ON) bzw. READ COMMITTED.
 *        Agent-Antworten aus As5FakeAgent (kein Netzwerk, kein named); Generator und named-checkzone sind echt.
 *   G  Generator und Policy: Kalender-Serial wie bisher, zonenweite Kontinuität bei Neuanlage gleichen Namens mit anderen
 *      Zielservern (D19), serial_unresolvable ⇒ generation_failed ohne Versuch/Datei/Serial, G1/G2, D9
 *   W  Write-ahead und Persistenz (D22, Ergänzung 3.1): intent/agent_check/no_report, gescheitertes intent (kein Aufruf),
 *      gescheiterter Bericht (Verlustmarke), gleichzeitig gescheiterte Marke (offenes intent), Abbruch nach dem
 *      Write-ahead, ungültiger Bericht; Auflösung nur durch administrative Erfassung von F und D
 *   P  Referenzen (D20/D21): M1/M2/M3 (300 bleibt), Deduplizierung nur ohne Zwischenereignis (Ergänzung 3.2), Rohwerte
 *      aus unknown, R1 bei gescheitertem Reload, getrennte F-/D-Konflikte in observe und enforce
 *   L  Legacy (D18): angenommener Auslieferungsstand löst gemessene D-Werte nicht ab; spätere Messung löst ihn ab
 *   X  Übergang und Lücken (Ergänzung 3.3/3.4): AS4-Historie sperrt nicht; AS4b-Rückfall mit as2 ⇒ Lücke, mit Legacy
 *      ⇒ keine; nicht gespiegelte Beobachtung nach der Epoche ⇒ Lücke, beim Überschreiben gesichert und dauerhaft markiert;
 *      legitime Antwort ohne serial_check ⇒ keine Lücke
 *   N  Nebenläufigkeit: verspätete Antwort nach RELEASE_LOCK (ungeordnet), Verbindungsverlust (KILL) nach dem
 *      Write-ahead, verworfener Monitoring-Wasserstand, Admin bei belegtem Lock, Referenzen unter der Zonensperre
 *      gelesen (tatsächlicher Lock-Wait)
 *   A  Administrative Erfassung (D17): Lock, Identität, F/D, Eingabe, Audit atomar, additiv, keine Seiteneffekte,
 *      keine Aufhebung von serial_unresolvable, Status
 *   S  Publish-Sperre (D24): fehlende FENCE_DOMAIN unterbindet Publish All, Publish Zone und DynDNS-Publish; Admin bleibt
 *   C  Code × Schema: AS5 nach 008 ohne Befund, ohne 008 fail-closed; AS4b-Code auf Schema 008 ohne Befund
 *
 * Aufruf (root, Testsystem): php as5_test.php <App-Verzeichnis> <RR|RC> <AS4b-App-Verzeichnis (Rückfallstand)>
 * Umgebung: AS5_ONLY=G,W,… beschränkt auf Gruppen (Mutationsproben).
 */

require_once __DIR__ . '/h2b_lib.php';

[$app, $iso, $baseApp] = [$argv[1] ?? '', $argv[2] ?? '', rtrim($argv[3] ?? '', '/')];
if (!is_dir("$baseApp/web")) {
    fwrite(STDERR, "Aufruf: php as5_test.php <App-Verzeichnis> <RR|RC> <AS4b-App-Verzeichnis>\n");
    exit(2);
}
H2b::init($app, $iso, 'as5');
define('BIND_BASE_DIR', sys_get_temp_dir() . '/dnsmgr-as5-bind');
foreach (['FenceContext', 'SerialNumber', 'SerialPolicy', 'SerialReferenceStore', 'SerialReferenceReader', 'SerialReferenceAdminService',
    'ServerVerificationStore', 'PublishLedger'] as $c) {
    require_once "$app/web/inc/app/publish/$c.php";
}
require_once "$app/web/inc/deploy/LegacyBindPublishBackend.php";
require_once "$app/web/inc/deploy/bind_file_generator.php";
require_once "$app/web/inc/app/monitoring/MonitoringService.php";
require_once "$app/web/inc/app/SchemaCheck.php";
require_once __DIR__ . '/as5_fake_agent.php';

$only = array_filter(explode(',', (string)getenv('AS5_ONLY')));
$group = static fn (string $g): bool => $only === [] || in_array($g, $only, true);
$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $iso;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . "[$iso] $id" . ($detail !== '' ? ' – ' . str_replace("\n", ' | ', $detail) : '') . "\n";
}

const D = H2b::FENCE_DOMAIN;
const IP1 = '192.0.2.1';
const IP2 = '192.0.2.2';
const MAXS = 4294967295;
$K = SerialPolicy::calendarCandidate(date('Ymd'));
$logs = [];
$svc = static function (PDO $p, As5FakeAgent $a, ?string $domain = D) use (&$logs): PublishService {
    return new PublishService($p, new LegacyBindPublishBackend($p, $a), static function (string $l, string $m) use (&$logs): void {
        $logs[] = "$l $m";
    }, new FenceContext($domain));
};
$ctxOf = static fn (ServiceResult $r): array => $r->messages[array_key_last($r->messages)]->context;
$publish = static function (PDO $p, As5FakeAgent $a, int $zone, ?string $domain = D) use ($svc, $ctxOf): array {
    $p->exec("UPDATE zones SET changed = 1 WHERE id = $zone");
    return $ctxOf($svc($p, $a, $domain)->publishZone($zone, 0));
};
$one = static fn (PDO $p, string $sql, array $a = []) => H2b::one($p, $sql, $a);
$all = static fn (PDO $p, string $sql, array $a = []) => H2b::all($p, $sql, $a);
$serial = static fn (PDO $p, int $z): int => (int)H2b::one($p, 'SELECT soa_serial FROM zones WHERE id = ?', [$z]);
$lastAttempt = static fn (PDO $p, int $z): array => H2b::all($p, 'SELECT * FROM zone_publish_attempts WHERE zone_id = ? ORDER BY id DESC LIMIT 1', [$z])[0] ?? [];
$refs = static fn (PDO $p, string $where = '1', array $a = []): array => H2b::all($p, "SELECT * FROM zone_serial_references WHERE $where ORDER BY id", $a);
$W = static fn (PDO $p): int => (int)H2b::one($p, 'SELECT COALESCE(MAX(id), 0) FROM publish_runs');
$targetsOf = static function (PDO $p, int $zoneId): array {
    $out = [];
    foreach (H2b::all($p, 'SELECT s.id, s.name FROM zone_servers zs JOIN servers s ON s.id = zs.server_id WHERE zs.zone_id = ? AND s.active = 1', [$zoneId]) as $t) {
        $out[(int)$t['id']] = (string)$t['name'];
    }
    return $out;
};
$set = static function (PDO $p, int $zoneId) use ($targetsOf): array {
    $z = H2b::all($p, 'SELECT * FROM zones WHERE id = ?', [$zoneId])[0];
    return (new SerialReferenceReader($p, new SerialReferenceStore($p)))->forZone($z, $targetsOf($p, $zoneId));
};
$decide = static function (PDO $p, int $zoneId): array {
    $z = H2b::all($p, 'SELECT * FROM zones WHERE id = ?', [$zoneId])[0];
    return SerialReferenceReader::selectSerial($p, $z, date('Ymd'));
};
$pairVals = static function (array $s, int $sid, string $c): array {
    $v = $s['pairs'][$sid][$c]['values'] ?? [];
    sort($v);
    return $v;
};
$admin = static fn (PDO $p): SerialReferenceAdminService => new SerialReferenceAdminService($p, new FenceContext(D));
$monitor = static function (PDO $p, int $w, int $zoneId, string $zone, int $sid, string $state, ?int $file, ?int $served, ?string $hash = null): array {
    $p->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    try {
        $refs = new SerialReferenceStore($p);
        $refs->ensureEpoch($w);
        $obs = ['state' => $state, 'reason' => $state === 'unknown' ? 'test' : null, 'served' => $served, 'fileSerial' => $file,
            'fileHash' => $hash ?? ($file !== null ? str_repeat('a', 64) : null)];
        $st = new ServerVerificationStore($p, null, $refs);
        return $st->recordMonitoring($w, [['zoneId' => $zoneId, 'zoneName' => $zone, 'serverId' => $sid,
            'serverName' => "ns$sid.h2b.test", 'obs' => $obs]]) + ['errors' => $st->report()['errors']];
    } finally {
        $p->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
    }
};
$clean = static fn (PDO $p): bool => !H2b::hasOpenTrx($p) && (int)$p->query("SELECT IS_FREE_LOCK('dnsmanager_publish_all')")->fetchColumn() === 1;
$trigger = static function (PDO $p, string $cond): void {
    $p->exec('DROP TRIGGER IF EXISTS as5_fail');
    $p->exec("CREATE TRIGGER as5_fail BEFORE INSERT ON zone_serial_references FOR EACH ROW BEGIN IF $cond THEN "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AS5-Fehlerinjektion'; END IF; END");
};
$noTrigger = static fn (PDO $p) => $p->exec('DROP TRIGGER IF EXISTS as5_fail');
$worker = static function (string $appDir, string ...$args): array {
    $cmd = array_merge(['php', __DIR__ . '/as5_worker.php', $appDir, H2b::$db, H2b::$iso], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return ['proc' => $proc, 'pipes' => $pipes];
};
$join = static function (array $w): array {
    $out = stream_get_contents($w['pipes'][1]);
    $err = stream_get_contents($w['pipes'][2]);
    proc_close($w['proc']);
    $lines = array_values(array_filter(explode("\n", (string)$out)));
    return json_decode((string)end($lines), true) ?: ['error' => trim($out . ' ' . $err)];
};
$recordAdmin = static function (PDO $p, int $zoneId, string $zone, int $sid, ?string $f, ?string $d) use ($admin): void {
    if ($f !== null) {
        $admin($p)->record($zoneId, $zone, $sid, "ns$sid.h2b.test", $f, null, 'dig +norec @127.0.0.1 SOA (Test)');
    }
    if ($d !== null) {
        $admin($p)->record($zoneId, $zone, $sid, "ns$sid.h2b.test", null, $d, 'named-checkzone (Test)');
    }
};

echo "AS5-Tests ($iso) – Datenbank " . H2b::$db . ", Kalenderkandidat $K\n";

// ---------------------------------------------------------------- G: Generator und Policy
if ($group('G')) {
    $pdo = H2b::reset(true);
    $a = new As5FakeAgent();
    $r = $publish($pdo, $a, 2);
    $at = $lastAttempt($pdo, 2);
    $rows = array_column($refs($pdo), 'source');
    t('G01 erster Publish: Kalender-Serial wie bisher; Epoche, Laufmarke, intent und no_report (legacy)', $r['status'] === 'published'
        && $serial($pdo, 2) === $K && (int)$at['soa_serial'] === $K && $rows === ['epoch', 'run', 'intent', 'no_report']
        && $refs($pdo, "source = 'no_report'")[0]['reason'] === 'legacy', json_encode([$r['status'], $serial($pdo, 2), $rows]));
    $r = $publish($pdo, $a, 2);
    t('G02 zweiter Publish am selben Tag: + 1 (increment) wie bisher', $r['status'] === 'published' && $serial($pdo, 2) === $K + 1);
    // D19: zonenweite Kontinuität bei Neuanlage gleichen Namens mit vollständig anderen Zielservern
    $pdo->exec('UPDATE zones SET soa_serial = 3000000000 WHERE id = 6');
    $r = $publish($pdo, $a, 6);
    $s6 = $serial($pdo, 6);
    $pdo->exec('DELETE FROM zones WHERE id = 6');
    $pdo->exec("INSERT INTO zones (id, name, type, ttl, prefix_length, description, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum,"
        . " soa_serial, changed, allow_dyndns) VALUES (7, 'Delta.h2b.test', 'forward', 3600, NULL, NULL, 'ns1.h2b.test.', 'hostmaster.h2b.test.',"
        . " 3600, 1800, 1209600, 86400, $K, 1, 0)");
    $pdo->exec('INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (7, 1, 1)');
    $pdo->exec("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (7, '@', 'NS', 'ns1.h2b.test.', 3600, 1)");
    $d = $decide($pdo, 7);
    $r = $publish($pdo, $a, 7);
    t('G03 D19: Neuanlage gleichen (normalisierten) Namens auf anderem Server setzt über der letzten Serial des Vorgängers fort',
        $s6 === 3000000001 && $r['status'] === 'published' && $serial($pdo, 7) === 3000000002 && $d['ok'] && $d['basis'] === 'increment',
        json_encode([$s6, $r['status'], $serial($pdo, 7), $d]));
    // serial_unresolvable
    $s2 = $serial($pdo, 2);
    $x = ($s2 + 2147483647) & MAXS;
    $pdo->prepare("INSERT INTO zone_serial_references (source, zone_id, zone_name, server_id, server_name, run_key, obs_state, served_serial)"
        . " VALUES ('monitoring', 2, 'beta.h2b.test', 1, 'ns1.h2b.test', ?, 'still_served', ?)")->execute([$W($pdo), $x]);
    $n0 = (int)$one($pdo, 'SELECT COUNT(*) FROM zone_publish_attempts');
    $a = new As5FakeAgent();
    $r = $publish($pdo, $a, 2);
    t('G04 serial_unresolvable ⇒ generation_failed: kein Versuch, keine Datei, kein Aufruf, Serial unverändert, Zone ausstehend',
        $r['status'] === 'generation_failed' && str_contains(implode(' ', $r['errors']), 'serial_unresolvable')
        && (int)$one($pdo, 'SELECT COUNT(*) FROM zone_publish_attempts') === $n0 && $a->syncs() === [] && $serial($pdo, 2) === $s2
        && (int)$one($pdo, 'SELECT changed FROM zones WHERE id = 2') === 1 && $clean($pdo), json_encode($r['errors']));
    // G1: Modus publish ohne Journal; G2: Backend ohne Journal
    try {
        generate_zone_file(2, sys_get_temp_dir(), 'publish', $pdo);
        t('G05 G1: generate_zone_file(publish) ohne Journal abgewiesen', false);
    } catch (InvalidArgumentException $e) {
        t('G05 G1: generate_zone_file(publish) ohne Journal abgewiesen (vor jeder Transaktion)', !$pdo->inTransaction() && $serial($pdo, 2) === $s2);
    }
    try {
        (new LegacyBindPublishBackend($pdo, new As5FakeAgent()))->generateZone(2);
        t('G06 G2: Backend ohne gebundenes Journal abgewiesen', false);
    } catch (LogicException $e) {
        t('G06 G2: Backend ohne gebundenes Journal abgewiesen', $serial($pdo, 2) === $s2);
    }
    // D9: registerAttempt weist eine nicht neuere Serial ab
    $pdo->exec("DELETE FROM zone_serial_references WHERE source = 'monitoring'");
    $ledger = new PublishLedger($pdo);
    $pdo->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    $run = $ledger->registerRun('zone', 2, false, HistoryContext::system('publish.zone', 'system'));
    $g = generate_zone_file(2, sys_get_temp_dir(), 'publish', $pdo, static fn (array $g): array => $ledger->registerAttempt($run, 2, $g['zone'],
        $g['records'], (int)$g['zone']['soa_serial'], $g['file'], ($g['render'])(PublishHashContract::SERIAL_PLACEHOLDER)));
    $ledger->finishRun($run, 'exception');
    $ledger->releaseRunLock($run);
    $pdo->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
    t('G07 D9: Versuch mit nicht neuerer Serial (gleich der DB-Serial) abgewiesen, Rollback', $g['status'] === 'error'
        && str_contains($g['output'], 'D9') && $serial($pdo, 2) === $s2 && $clean($pdo), $g['output']);
}

// ---------------------------------------------------------------- W: Write-ahead und Persistenz
if ($group('W')) {
    $pdo = H2b::reset(true);
    $obs = static function (): As5FakeAgent {
        $a = new As5FakeAgent();
        $a->modes = [IP1 => 'observe', IP2 => 'observe'];
        return $a;
    };
    $a = $obs();
    $r = $publish($pdo, $a, 2);
    $chk = $refs($pdo, "source = 'agent_check'");
    $s = $set($pdo, 2);
    $N = $serial($pdo, 2);
    t('W01 observe: intent und agent_check (Neuinstallation: Datei fehlt, nicht ausgeliefert, K = nein); F/D nach Verifikation {N}',
        $r['status'] === 'published' && count($chk) === 1 && $chk[0]['decision'] === 'accept' && $chk[0]['file_state'] === 'missing'
        && $chk[0]['served_state'] === 'not_auth' && $chk[0]['config_state'] === 'no' && (int)$chk[0]['order_certain'] === 1
        && $pairVals($s, 1, 'F') === [$N] && $pairVals($s, 1, 'D') === [$N] && $s['blocks'] === [], json_encode([$r['status'], $s['pairs'][1] ?? null]));
    // gescheitertes intent ⇒ kein Aufruf
    $trigger($pdo, "NEW.source = 'intent'");
    $a = $obs();
    $runs0 = (int)$one($pdo, 'SELECT COUNT(*) FROM publish_runs');
    $r = $publish($pdo, $a, 2);
    $noTrigger($pdo);
    $at = $lastAttempt($pdo, 2);
    $tg = $all($pdo, 'SELECT * FROM zone_publish_targets WHERE attempt_id = ?', [(int)$at['id']])[0] ?? [];
    t('W02 intent nicht speicherbar ⇒ kein zone_sync, Ziel serial_intent_failed, Lauf exception, Zone ausstehend', $r['status'] === 'exception'
        && $a->syncs() === [] && ($tg['zone_sync'] ?? '') === 'failed' && ($tg['zone_error_code'] ?? '') === 'serial_intent_failed'
        && (int)$one($pdo, 'SELECT changed FROM zones WHERE id = 2') === 1 && (int)$one($pdo, 'SELECT COUNT(*) FROM publish_runs') === $runs0 + 1
        && $one($pdo, 'SELECT result FROM publish_runs ORDER BY id DESC LIMIT 1') === 'exception' && $clean($pdo), json_encode([$r['status'], $tg['zone_error_code'] ?? null]));
    // Bericht UND Verlustmarke nicht speicherbar ⇒ intent bleibt offen ⇒ Sperre
    $trigger($pdo, "NEW.source IN ('agent_check', 'loss')");
    $a = $obs();
    $r = $publish($pdo, $a, 2);
    $noTrigger($pdo);
    $d = $decide($pdo, 2);
    t('W03 Bericht und Verlustmarke nicht speicherbar ⇒ Lauf exception; intent ohne Abschluss sperrt (serial_reference_lost)',
        $r['status'] === 'exception' && count($a->syncs()) === 1 && !$d['ok'] && $d['reason'] === 'serial_reference_lost' && $clean($pdo),
        json_encode([$r['status'], $d['reason'] ?? null]));
    $a3 = $a;   // Agentenzustand nach W03 (Datei übernommen)
    $a = $obs();
    $r = $publish($pdo, $a, 2);
    t('W04 gesperrte Zone: generation_failed ohne Aufruf (keine automatische Auflösung)', $r['status'] === 'generation_failed' && $a->syncs() === []
        && str_contains(implode(' ', $r['errors']), 'serial_reference_lost'));
    $a = $a3;
    $recordAdmin($pdo, 2, 'beta.h2b.test', 1, (string)$a->file[IP1 . '|beta.h2b.test']['serial'], null);
    $d1 = $decide($pdo, 2);
    $recordAdmin($pdo, 2, 'beta.h2b.test', 1, null, (string)$a->served[IP1 . '|beta.h2b.test']);
    $d2 = $decide($pdo, 2);
    $r = $publish($pdo, $obs2 = (static function () use ($a): As5FakeAgent {
        $b = new As5FakeAgent();
        $b->modes = [IP1 => 'observe'];
        $b->file = $a->file;
        $b->served = $a->served;
        return $b;
    })(), 2);
    t('W05 Auflösung nur mit administrativer Erfassung von F UND D nach dem Ereignis; danach Publish möglich', !$d1['ok'] && $d2['ok']
        && $r['status'] === 'published', json_encode([$d1['reason'] ?? null, $d2['ok'], $r['status']]));
    // nur der Bericht nicht speicherbar ⇒ Verlustmarke, Lauf exception, Sperre
    $trigger($pdo, "NEW.source = 'agent_check'");
    $a = $obs();
    $a->file = $obs2->file;
    $a->served = $obs2->served;
    $r = $publish($pdo, $a, 2);
    $noTrigger($pdo);
    $loss = $refs($pdo, "source = 'loss' AND reason = 'report_not_stored'");
    $d = $decide($pdo, 2);
    t('W06 Bericht nicht speicherbar ⇒ Verlustmarke, Lauf exception, Zone gesperrt', $r['status'] === 'exception' && count($loss) === 1
        && !$d['ok'] && $d['reason'] === 'serial_reference_lost' && $clean($pdo));
    // Abbruch nach dem Write-ahead (Prozessende während des Aufrufs)
    $pdo = H2b::reset(true);
    $a = $obs();
    $a->hook = static function (string $ep): void {
        if ($ep === '/zones/zone_sync.php') {
            throw new RuntimeException('Prozessabbruch (Test)');
        }
    };
    $r = $publish($pdo, $a, 2);
    $d = $decide($pdo, 2);
    t('W07 Abbruch nach dem Write-ahead: intent ohne Abschluss ⇒ Sperre (nicht stillschweigend aufgelöst)', $r['status'] === 'exception'
        && count($refs($pdo, "source = 'intent'")) === 1 && !$d['ok'] && $d['reason'] === 'serial_reference_lost');
    // ungültiger Bericht ⇒ Verlustmarke
    $pdo = H2b::reset(true);
    $a = $obs();
    $a->mangle[IP1 . '|beta.h2b.test'] = static fn (array $r): array => ['new_serial' => 1] + $r;
    $r = $publish($pdo, $a, 2);
    $d = $decide($pdo, 2);
    t('W08 ungültiger serial_check ⇒ Verlustmarke serial_check_invalid, Zone danach gesperrt', $r['status'] === 'published'
        && count($refs($pdo, "source = 'loss' AND reason = 'serial_check_invalid'")) === 1 && !$d['ok'] && $d['reason'] === 'serial_reference_lost');
}

// ---------------------------------------------------------------- P: Referenzen
if ($group('P')) {
    $pdo = H2b::reset(true);
    $w = $W($pdo);
    $monitor($pdo, $w, 2, 'beta.h2b.test', 1, 'verified', 100, 100);
    $monitor($pdo, $w, 2, 'beta.h2b.test', 1, 'serial_differs', 100, 300);
    $monitor($pdo, $w, 2, 'beta.h2b.test', 1, 'unknown', null, null);
    $zsv = $all($pdo, 'SELECT * FROM zone_server_verification WHERE zone_id = 2 AND server_id = 1')[0];
    $s = $set($pdo, 2);
    t('P01 M1/M2/M3: Verification Store zeigt nur unknown, die Referenzen behalten D {300} und F {100}', $zsv['last_state'] === 'unknown'
        && $zsv['last_served_serial'] === null && $pairVals($s, 1, 'D') === [300] && $pairVals($s, 1, 'F') === [100] && in_array(300, $s['refs'], true),
        json_encode($s['pairs'][1] ?? null));
    $n0 = count($refs($pdo, "source = 'monitoring'"));
    $monitor($pdo, $w, 2, 'beta.h2b.test', 1, 'unknown', null, null);
    t('P02 Deduplizierung: identische Monitoring-Beobachtung beim gleichen Wasserstand ohne Zwischenereignis ⇒ keine Zeile',
        count($refs($pdo, "source = 'monitoring'")) === $n0);
    // Ergänzung 3.2: Monitoring 100 → Publish 200 → Monitoring 100
    $pdo = H2b::reset(true);
    $monitor($pdo, $W($pdo), 2, 'beta.h2b.test', 1, 'verified', 100, 100);
    $a = new As5FakeAgent();
    $publish($pdo, $a, 2);
    $N = $serial($pdo, 2);
    $mid = $set($pdo, 2);
    $n1 = count($refs($pdo, "source = 'monitoring'"));
    $monitor($pdo, $W($pdo), 2, 'beta.h2b.test', 1, 'verified', 100, 100);
    $s = $set($pdo, 2);
    t('P03 Monitoring 100 → Publish → Monitoring 100: zweite Messung wird gespeichert und bleibt Anker', count($refs($pdo, "source = 'monitoring'")) === $n1 + 1
        && $pairVals($mid, 1, 'D') === [100, $N] && $pairVals($s, 1, 'D') === [100] && $pairVals($s, 1, 'F') === [100],
        json_encode([$mid['pairs'][1]['D'] ?? null, $s['pairs'][1] ?? null]));
    // Zwischenereignis ohne Referenzzeile des Paars (Lauf des AS4b-Codes): neuer Wasserstand ⇒ keine Deduplizierung
    $n2 = count($refs($pdo, "source = 'monitoring'"));
    $wk = $join($worker($baseApp, 'publish', '2', IP1 . '=legacy'));
    $monitor($pdo, $W($pdo), 2, 'beta.h2b.test', 1, 'verified', 100, 100);
    t('P03b gleiche Messung nach einem Lauf ohne Referenzzeile des Paars (AS4b-Code) wird erneut gespeichert (Wasserstand)',
        ($wk['status'] ?? '') === 'published' && count($refs($pdo, "source = 'monitoring'")) === $n2 + 1, json_encode($wk));
    $monitor($pdo, $W($pdo), 2, 'beta.h2b.test', 1, 'unknown', 9, 8);
    $s = $set($pdo, 2);
    t('P04 D21: gültige Rohwerte aus unknown bleiben additiv bindend', in_array(8, $pairVals($s, 1, 'D'), true) && in_array(9, $pairVals($s, 1, 'F'), true));
    // R1: installierter Stand bei gescheitertem Reload
    $pdo = H2b::reset(true);
    $monitor($pdo, $W($pdo), 2, 'beta.h2b.test', 1, 'verified', 2026111801, 2026111801);
    $a = new As5FakeAgent();
    $a->modes = [IP1 => 'observe'];
    $a->file[IP1 . '|beta.h2b.test'] = ['serial' => 2026111801, 'sha' => str_repeat('a', 64)];
    $a->served[IP1 . '|beta.h2b.test'] = 2026111801;
    $a->queue['/zones/zone_sync.php|' . IP1][] = static function (array $payload, As5FakeAgent $ag): array {
        $bytes = base64_decode((string)$payload['zone_data']);
        preg_match('/\n\s+(\d+)\s+; Serial\n/', $bytes, $m);
        $rep = $ag->serialCheck(IP1, 'beta.h2b.test', (int)$m[1], hash('sha256', $bytes), 'observe');
        return As5FakeAgent::resp(500, ['status' => 'error', 'code' => 'reload_failed', 'effect' => 'applied', 'rollback' => 'not_attempted',
            'bind_state' => 'unknown', 'reload' => ['status' => 'failed', 'exit_code' => 1], 'installed' => ['serial' => (int)$m[1],
            'sha256' => hash('sha256', $bytes)], 'serial_check' => $rep]);
    };
    $a->queue['/zones/conf_sync.php|' . IP1][] = As5FakeAgent::resp(500, ['status' => 'error', 'code' => 'reload_failed', 'effect' => 'none']);
    $r = $publish($pdo, $a, 2);
    $N = $serial($pdo, 2);
    $tg = $all($pdo, 'SELECT * FROM zone_publish_targets WHERE attempt_id = ?', [(int)$lastAttempt($pdo, 2)['id']])[0];
    $s = $set($pdo, 2);
    t('P05 R1 bei gescheitertem Reload: Rohwert gespeichert, F-Anker {N}, D behält den gemessenen Anker und N als möglich',
        $tg['zone_sync'] === 'unknown' && (int)$tg['zone_installed_serial'] === $N && $pairVals($s, 1, 'F') === [$N]
        && $pairVals($s, 1, 'D') === [2026111801, $N], json_encode([$tg['zone_sync'], $s['pairs'][1] ?? null]));
    // getrennte F-/D-Konflikte (observe)
    $pdo = H2b::reset(true);
    $a = new As5FakeAgent();
    $a->modes = [IP1 => 'observe'];
    $X = $K + 1000;
    $a->file[IP1 . '|beta.h2b.test'] = ['serial' => 50, 'sha' => str_repeat('b', 64)];
    $a->stickyServed[IP1 . '|beta.h2b.test'] = $X;
    $r = $publish($pdo, $a, 2);
    $N = $serial($pdo, 2);
    $chk = $refs($pdo, "source = 'agent_check'")[0] ?? [];
    $s = $set($pdo, 2);
    $d = $decide($pdo, 2);
    t('P06 observe: would_reject stale_serial gespeichert; F-Konflikt 50 durch neueren F-Anker abgelöst, D-Konflikt X bleibt',
        $r['status'] === 'published' && $N === $K && ($chk['decision'] ?? '') === 'would_reject' && ($chk['code'] ?? '') === 'stale_serial'
        && $pairVals($s, 1, 'F') === [$N] && in_array($X, $pairVals($s, 1, 'D'), true) && $d['ok'] && $d['serial'] === $X + 1,
        json_encode([$s['pairs'][1] ?? null, $d]));
    $r = $publish($pdo, $a, 2);
    t('P07 nächster Publish liegt über der gemeldeten Auslieferung X', $r['status'] === 'published' && $serial($pdo, 2) === $X + 1);
    // enforce: Ablehnung, Selbstheilung beim nächsten Publish ohne Monitoring
    $pdo = H2b::reset(true);
    $a = new As5FakeAgent();
    $a->modes = [IP1 => 'enforce'];
    $a->stickyServed[IP1 . '|beta.h2b.test'] = $X;
    $r1 = $publish($pdo, $a, 2);
    $tg = $all($pdo, 'SELECT * FROM zone_publish_targets WHERE attempt_id = ?', [(int)$lastAttempt($pdo, 2)['id']])[0];
    $r2 = $publish($pdo, $a, 2);
    t('P08 enforce: stale_serial abgelehnt (rejected), gemeldete Serial gespeichert, nächster Publish ohne Monitoring über X',
        $r1['status'] !== 'published' && $tg['zone_sync'] === 'rejected' && $r2['status'] === 'published' && $serial($pdo, 2) === $X + 1,
        json_encode([$r1['status'], $tg['zone_sync'], $r2['status'], $serial($pdo, 2)]));
}

// ---------------------------------------------------------------- L: Legacy (D18)
if ($group('L')) {
    $pdo = H2b::reset(true);
    $monitor($pdo, $W($pdo), 2, 'beta.h2b.test', 1, 'serial_differs', 2026111801, 300);
    $a = new As5FakeAgent();
    $publish($pdo, $a, 2);
    $N = $serial($pdo, 2);
    $s = $set($pdo, 2);
    t('L01 Legacy-Publish: angenommener D-Zustand {N} neben dem gemessenen D-Wert 300; F-Anker {N}',
        $pairVals($s, 1, 'D') === [300, $N] && $pairVals($s, 1, 'F') === [$N], json_encode($s['pairs'][1] ?? null));
    $publish($pdo, $a, 2);
    $N2 = $serial($pdo, 2);
    $s = $set($pdo, 2);
    t('L02 zweiter Legacy-Publish: Annahme löst nur die frühere Annahme ab; 300 bleibt', $pairVals($s, 1, 'D') === [300, $N2]);
    $monitor($pdo, $W($pdo), 2, 'beta.h2b.test', 1, 'verified', $N2, $N2);
    $s = $set($pdo, 2);
    t('L03 spätere gemessene Auslieferung löst Annahme und älteren Messwert ab', $pairVals($s, 1, 'D') === [$N2]);
}

// ---------------------------------------------------------------- X: Übergang und Lücken
if ($group('X')) {
    $pdo = H2b::reset(true);
    $pdo->exec("INSERT INTO zone_server_verification (zone_id, server_id, zone_name, server_name, last_state, last_method, last_served_serial,"
        . " last_file_serial, last_file_hash, last_run_id, last_observed_at) VALUES (2, 1, 'beta.h2b.test', 'ns1.h2b.test', 'serial_differs',"
        . " 'monitoring', 2026111900, 2026111801, '" . str_repeat('c', 64) . "', NULL, NOW(6))");
    $s = $set($pdo, 2);
    t('X01 AS4-Historie ohne Epoche: keine Sperre, Werte fließen ein', $s['blocks'] === [] && $refs($pdo) === []
        && in_array(2026111900, $pairVals($s, 1, 'D'), true), json_encode($s['blocks']));
    $a = new As5FakeAgent();
    $a->modes = [IP1 => 'observe'];
    $publish($pdo, $a, 2);
    $resc = $refs($pdo, "source = 'monitoring'");
    t('X02 erste AS5-Speicherung sichert die AS4-Beobachtung (ohne Verlustmarke, da vor der Epoche)',
        count($refs($pdo, "source = 'loss'")) === 0 && $set($pdo, 2)['blocks'] === [], json_encode(array_column($resc, 'served_serial')));
    // Rückfall: AS4b-Code veröffentlicht mit as2 (observe) ⇒ Lücke
    $wk = $join($worker($baseApp, 'publish', '2', IP1 . '=observe'));
    $s = $set($pdo, 2);
    $reasons = array_column($s['blocks'], 'reason');
    $runGap = array_filter($s['blocks'], static fn (array $b): bool => $b['reason'] === 'serial_reference_gap' && str_contains($b['detail'], 'ohne AS5-Laufmarke'));
    t('X03 AS4b-Lauf mit as2-zone_sync nach der Epoche (keine Laufmarke) ⇒ serial_reference_gap des Laufs', ($wk['status'] ?? '') === 'published'
        && count($runGap) === 1, json_encode([$wk, $s['blocks']]));
    $recordAdmin($pdo, 2, 'beta.h2b.test', 1, (string)$serial($pdo, 2), (string)$serial($pdo, 2));
    t('X04 Lücke durch administrative Erfassung von F und D aufgelöst', $set($pdo, 2)['blocks'] === []);
    $wk = $join($worker($baseApp, 'publish', '4', IP1 . '=legacy'));
    t('X05 AS4b-Lauf mit Legacy-Antwort ⇒ keine Lücke (kein Bericht möglich)', ($wk['status'] ?? '') === 'published' && $set($pdo, 4)['blocks'] === [],
        json_encode($wk));
    // AS4b-Monitoring nach der Epoche ⇒ nicht gespiegelt ⇒ Lücke; Überschreiben durch AS5 sichert und markiert dauerhaft
    $w = $W($pdo);
    $wk = $join($worker($baseApp, 'monitoring', '3', '2.0.192.in-addr.arpa', '1', 'ns1.h2b.test', (string)$w, 'serial_differs', '2026111801',
        '555', '-'));
    $s = $set($pdo, 3);
    t('X06 AS4b-Monitoring nach der Epoche ⇒ nicht gespiegelt ⇒ serial_reference_gap, Wert 555 bindend', ($wk['stored'] ?? 0) === 1
        && in_array('serial_reference_gap', array_column($s['blocks'], 'reason'), true) && in_array(555, $pairVals($s, 1, 'D'), true), json_encode([$wk, $s['blocks']]));
    $mr = $monitor($pdo, $w, 3, '2.0.192.in-addr.arpa', 1, 'verified', 2026111801, 2026111801);
    $s = $set($pdo, 3);
    t('X07 AS5-Überschreiben: Beobachtung gesichert und Verlustmarke unmirrored_observation – Sperre bleibt trotz neuer Messung',
        count($refs($pdo, "source = 'loss' AND reason = 'unmirrored_observation'")) === 1 && in_array('serial_reference_lost', array_column($s['blocks'], 'reason'), true),
        json_encode([$mr, $s['blocks']]));
    // legitime as2-Antwort ohne serial_check (Ablehnung vor der Vorher-Prüfung)
    $pdo = H2b::reset(true);
    $a = new As5FakeAgent();
    $a->modes = [IP1 => 'enforce'];
    $a->queue['/zones/zone_sync.php|' . IP1][] = As5FakeAgent::resp(409, ['status' => 'error', 'code' => 'stale_fence', 'effect' => 'none',
        'fence' => ['mode' => 'enforce', 'decision' => 'reject', 'code' => 'stale_fence']]);
    $publish($pdo, $a, 2);
    $nr = $refs($pdo, "source = 'no_report'");
    t('X08 legitime Antwort ohne serial_check ⇒ no_report (no_serial_check), keine Sperre', count($nr) === 1 && $nr[0]['reason'] === 'no_serial_check'
        && $set($pdo, 2)['blocks'] === []);
}

// ---------------------------------------------------------------- N: Nebenläufigkeit
if ($group('N')) {
    $pdo = H2b::reset(true);
    $a = new As5FakeAgent();
    $a->modes = [IP1 => 'observe'];
    $a->hook = static function (string $ep) use ($pdo): void {
        if ($ep === '/zones/zone_sync.php') {
            $run = (int)H2b::one($pdo, 'SELECT MAX(id) FROM publish_runs');
            $pdo->query("SELECT RELEASE_LOCK('" . PublishLedger::runLockName(H2b::$db, $run) . "')")->fetchColumn();
        }
    };
    $r = $publish($pdo, $a, 2);
    $chk = $refs($pdo, "source = 'agent_check'");
    $s = $set($pdo, 2);
    t('N01 verspätete Antwort nach Verlust der Laufsperre: Bericht ungeordnet (order_certain 0), Werte bindend, keine Sperre, Lauf exception',
        $r['status'] === 'exception' && count($chk) === 1 && (int)$chk[0]['order_certain'] === 0 && $s['blocks'] === []
        && count(array_filter($a->calls, static fn (array $c): bool => $c['endpoint'] === '/zones/conf_sync.php')) === 0, json_encode([$r['status'], $chk[0]['order_certain'] ?? null]));
    // Verbindungsverlust nach dem Write-ahead
    $pdo = H2b::reset(true);
    $killer = H2b::pdo();
    $a = new As5FakeAgent();
    $a->modes = [IP1 => 'observe'];
    $conn = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $a->hook = static function (string $ep) use ($killer, $conn): void {
        if ($ep === '/zones/zone_sync.php') {
            $killer->exec("KILL $conn");
        }
    };
    try {
        $r = $publish($pdo, $a, 2);
    } catch (Throwable $e) {
        $r = ['status' => 'throw:' . $e->getMessage()];
    }
    $pdo = H2b::pdo();
    $d = $decide($pdo, 2);
    t('N02 Verbindungsverlust (KILL) nach dem Write-ahead: intent ohne Abschluss ⇒ Sperre nach Wiederanlauf', count($refs($pdo, "source = 'intent'")) === 1
        && !$d['ok'] && $d['reason'] === 'serial_reference_lost', json_encode([$r['status'] ?? null, $d['reason'] ?? null]));
    // verworfener Monitoring-Wasserstand
    $pdo = H2b::reset(true);
    $m = new MonitoringService($pdo, new class implements MonitoringBackend {
        public function serverStatus(array $server): array { return []; }
        public function zoneConfStatus(array $server): array { return []; }
        public function zoneStatus(string $zoneName, array $server): array { return []; }
    }, new class implements MonitoringNotifier {
        public function send(string $subject, string $body): array { return ['status' => 'ok']; }
    }, false);
    $store = Closure::bind(fn (int $w, array $items) => $this->storeUnderPublishLock($w, $items), $m, MonitoringService::class);
    $res = $store($W($pdo) + 1, [['zoneId' => 2, 'zoneName' => 'beta.h2b.test', 'serverId' => 1, 'serverName' => 'ns1.h2b.test',
        'obs' => ['state' => 'still_served', 'reason' => null, 'served' => 777, 'fileSerial' => null, 'fileHash' => null]]]);
    $u = $refs($pdo, "source = 'monitoring'");
    t('N03 verworfener Wasserstand: keine Beobachtung im Store, Messwert ungeordnet festgehalten', $res === null
        && (int)$one($pdo, 'SELECT COUNT(*) FROM zone_server_verification') === 0 && count($u) === 1 && (int)$u[0]['order_certain'] === 0
        && in_array(777, $pairVals($set($pdo, 2), 1, 'D'), true));
    // Admin bei belegtem Publish-Lock
    $holder = H2b::pdo();
    $holder->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    try {
        $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '5', null, 'Test');
        t('N04 Admin-Erfassung bei belegtem Publish-Lock abgewiesen', false);
    } catch (RuntimeException $e) {
        t('N04 Admin-Erfassung bei belegtem Publish-Lock abgewiesen, keine Zeile', count($refs($pdo, "source = 'admin'")) === 0, $e->getMessage());
    }
    $holder->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
    // Referenzen unter der Zonensperre gelesen (tatsächlicher Lock-Wait)
    $pdo = H2b::reset(true);
    $b = H2b::pdo();
    $b->beginTransaction();
    $b->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    $mark = sys_get_temp_dir() . '/as5-lockwait-' . bin2hex(random_bytes(4));
    $wp = $worker($app, 'publish', '2', IP1 . '=legacy', $mark);
    H2b::waitFile($mark);
    $waits = 0;
    for ($i = 0; $i < 100 && $waits === 0; $i++) {
        usleep(50000);
        $waits = H2b::lockWaits($pdo);
    }
    $Y = $K + 5000;
    $b->prepare("INSERT INTO zone_serial_references (source, zone_id, zone_name, server_id, server_name, run_key, obs_state, served_serial)"
        . " VALUES ('monitoring', 2, 'beta.h2b.test', 1, 'ns1.h2b.test', ?, 'still_served', ?)")->execute([$W($b), $Y]);
    $b->commit();
    $res = $join($wp);
    @unlink($mark);
    t('N05 Publish wartet auf die Zonensperre (Lock-Wait) und berücksichtigt den danach festgeschriebenen Referenzwert',
        $waits > 0 && ($res['status'] ?? '') === 'published' && $serial($pdo, 2) === $Y + 1, json_encode([$waits, $res, $serial($pdo, 2)]));
}

// ---------------------------------------------------------------- A: Administrative Erfassung
if ($group('A')) {
    $pdo = H2b::reset(true);
    $ae0 = (int)$one($pdo, 'SELECT COUNT(*) FROM audit_events');
    $before = [H2b::businessSum($pdo), $one($pdo, 'SELECT COUNT(*) FROM zone_server_verification'), $one($pdo, 'SELECT COUNT(*) FROM publish_runs')];
    $rej = static function (callable $f): ?string {
        try {
            $f();
            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };
    $cases = [
        'Zonenname passt nicht' => fn () => $admin($pdo)->record(2, 'alpha.h2b.test', 1, 'ns1.h2b.test', '5', null, 'x'),
        'Zone fehlt' => fn () => $admin($pdo)->record(99, 'beta.h2b.test', 1, 'ns1.h2b.test', '5', null, 'x'),
        'Servername passt nicht' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns2.h2b.test', '5', null, 'x'),
        'Server fehlt' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 9, 'ns9.h2b.test', '5', null, 'x'),
        'F und D zugleich' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '5', '5', 'x'),
        'weder F noch D' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', null, null, 'x'),
        'führende Null' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '05', null, 'x'),
        'negativ' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '-1', null, 'x'),
        'über 2^32-1' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '4294967296', null, 'x'),
        'keine Zahl' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', 'x', null, 'x'),
        'Herkunft leer' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '5', null, '  '),
        'Herkunft mit Geheimnismuster' => fn () => $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '5', null, str_repeat('ab12', 12)),
    ];
    $bad = [];
    foreach ($cases as $n => $f) {
        if ($rej($f) === null) {
            $bad[] = $n;
        }
    }
    t('A01 Ablehnungen ohne Änderung (' . count($cases) . ' Fälle: Identität, F/D, Wertebereich, Herkunft)', $bad === []
        && count($refs($pdo)) === 0 && (int)$one($pdo, 'SELECT COUNT(*) FROM audit_events') === $ae0 && $clean($pdo), implode(', ', $bad));
    $id0 = $admin($pdo)->record(2, 'BETA.h2b.test.', 1, 'ns1.h2b.test', '0', null, 'named-checkzone db.beta.h2b.test');
    $id1 = $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', null, '0', 'dig +norec @127.0.0.1 beta.h2b.test SOA');
    $id2 = $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', null, '7', 'zweite Erfassung');
    $rows = $refs($pdo, "source = 'admin'");
    $ev = $all($pdo, "SELECT e.*, c.operation, c.channel, c.actor_type FROM audit_events e JOIN history_change_sets c ON c.id = e.change_set_id"
        . " WHERE c.operation = 'serial.reference_record' ORDER BY e.id");
    t('A02 Erfassung (auch Serial 0, normalisierter Name): je Zeile genau ein Audit-Ereignis mit derselben Änderungsmenge', count($rows) === 3
        && $id0 < $id1 && $id1 < $id2 && count($ev) === 3 && $ev[0]['operation'] === 'serial.reference_record' && $ev[0]['channel'] === 'cli'
        && $ev[0]['entity_type'] === 'zone' && (int)$ev[0]['entity_id'] === 2 && (int)$rows[0]['change_set_id'] === (int)$ev[0]['change_set_id']
        && (int)$rows[0]['file_serial'] === 0 && $rows[0]['served_serial'] === null && (int)$rows[1]['served_serial'] === 0
        && json_decode($ev[1]['changes'], true) === ['serial_reference_server' => 1, 'serial_reference_served' => 0], json_encode($ev[1]['changes'] ?? null));
    t('A03 mehrere Erfassungen beim gleichen Wasserstand additiv (eigene Zeilen, Ordnung über die ID)', (int)$rows[1]['run_key'] === (int)$rows[2]['run_key']
        && in_array(0, $pairVals($set($pdo, 2), 1, 'D'), true) && in_array(7, $pairVals($set($pdo, 2), 1, 'D'), true));
    t('A04 keine Seiteneffekte: Fachdaten, DB-Serials, Verification Store, Läufe unverändert',
        [H2b::businessSum($pdo), $one($pdo, 'SELECT COUNT(*) FROM zone_server_verification'), $one($pdo, 'SELECT COUNT(*) FROM publish_runs')] === $before);
    $trigger($pdo, "NEW.source = 'admin'");
    $ae = (int)$one($pdo, 'SELECT COUNT(*) FROM audit_events');
    $cs = (int)$one($pdo, 'SELECT COUNT(*) FROM history_change_sets');
    try {
        $admin($pdo)->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '9', null, 'x');
        $ok = false;
    } catch (Throwable $e) {
        $ok = true;
    }
    $noTrigger($pdo);
    t('A05 Fehler beim Speichern: kein Audit-Ereignis, keine Änderungsmenge, kein offener Zustand', $ok && (int)$one($pdo, 'SELECT COUNT(*) FROM audit_events') === $ae
        && (int)$one($pdo, 'SELECT COUNT(*) FROM history_change_sets') === $cs && $clean($pdo));
    $s2 = $serial($pdo, 2);
    $pdo->prepare("INSERT INTO zone_serial_references (source, zone_id, zone_name, server_id, server_name, run_key, obs_state, served_serial)"
        . " VALUES ('monitoring', 2, 'beta.h2b.test', 1, 'ns1.h2b.test', ?, 'still_served', ?)")->execute([$W($pdo), ($s2 + 2147483647) & MAXS]);
    $d1 = $decide($pdo, 2);
    $recordAdmin($pdo, 2, 'beta.h2b.test', 1, (string)$s2, (string)$s2);
    $d2 = $decide($pdo, 2);
    t('A06 Erfassung hebt serial_unresolvable nicht auf (kein Override)', !$d1['ok'] && !$d2['ok'] && $d2['reason'] === 'serial_unresolvable');
    $st = $admin($pdo)->status('beta.h2b.test');
    t('A07 Status: Entscheidung, Paare und Publish-Sperre', count($st['zones']) === 1 && !$st['zones'][0]['decision']['ok']
        && isset($st['zones'][0]['pairs'][1]) && $st['publishBlocked'] === false && $st['epoch'] !== null);
}

// ---------------------------------------------------------------- S: Publish-Sperre
if ($group('S')) {
    $pdo = H2b::reset(true);
    $a = new As5FakeAgent();
    $n = [(int)$one($pdo, 'SELECT COUNT(*) FROM publish_runs'), count($refs($pdo))];
    $pdo->exec('UPDATE zones SET changed = 1 WHERE id IN (1, 2)');
    $ra = $svc($pdo, $a, null)->publishAll(H2b::admin());
    $rz = $publish($pdo, $a, 2, null);
    $dyn = new DyndnsUpdateService($pdo, new BindZoneBuildBackend($pdo), new PublishService($pdo, new LegacyBindPublishBackend($pdo, $a), null, new FenceContext(null)));
    $dr = $dyn->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.77', null);
    $last = $dr->messages[array_key_last($dr->messages)];
    t('S01 fehlende FENCE_DOMAIN: Publish All, Publish Zone und DynDNS-Publish ohne Lauf, Referenz und Agent-Aufruf',
        !$ra->ok && $rz['status'] === 'exception' && $last->code === 'dyndns.api.publish_pending' && $a->writes() === []
        && [(int)$one($pdo, 'SELECT COUNT(*) FROM publish_runs'), count($refs($pdo))] === $n, json_encode([$rz['status'], $last->code]));
    t('S02 DynDNS-Änderung festgeschrieben und ausstehend (Wiederaufnahme nach Aufhebung der Sperre)', (int)$one($pdo, "SELECT COUNT(*) FROM records WHERE zone_id = 1 AND content = '192.0.2.77'") === 1
        && (int)$one($pdo, 'SELECT changed FROM zones WHERE id = 1') !== 0);
    $st = (new SerialReferenceAdminService($pdo, new FenceContext(null)))->status();
    (new SerialReferenceAdminService($pdo, new FenceContext(null)))->record(2, 'beta.h2b.test', 1, 'ns1.h2b.test', '5', null, 'Restore-Abgleich');
    t('S03 Admin-Status meldet die Sperre; Erfassung bleibt möglich', $st['publishBlocked'] === true && count($refs($pdo, "source = 'admin'")) === 1);
}

// ---------------------------------------------------------------- C: Code × Schema
if ($group('C')) {
    $pdo = H2b::reset(true);
    t('C01 AS5-Code auf Schema 008: SchemaCheck ohne Befund', SchemaCheck::problems($pdo) === []);
    $wk = $join($worker($baseApp, 'schemacheck'));
    t('C02 AS4b-Code (Rückfallstand) auf Schema 008: SchemaCheck ohne Befund', ($wk['problems'] ?? null) === [], json_encode($wk));
    $pdo->exec('DROP TABLE zone_serial_references');
    $p2 = H2b::pdo();
    $probs = (static function (PDO $p): array {
        $r = new ReflectionProperty(SchemaCheck::class, 'cache');
        $r->setValue(null, []);
        return SchemaCheck::problems($p);
    })($p2);
    $a = new As5FakeAgent();
    $r = $publish($p2, $a, 2);
    t('C03 ohne 008: SchemaCheck-Befund (Login fail-closed) und Publish ohne Agent-Aufruf (Laufmarke nicht speicherbar)',
        in_array('zone_serial_references fehlt', $probs, true) && $r['status'] === 'exception' && $a->writes() === [], json_encode([$probs, $r['status']]));
}

echo "Ergebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
