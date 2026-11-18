<?php
/**
 * Datei: tests/history/db_test.php
 * Zweck: Datenbanktests der Baseline-Initialisierung (HistoryBaselineService, ZoneSnapshotReader) auf einer isolierten
 *        Datenbank dnsmgr_t21_r<RUN>_b{rr|rc} (AP1/H2a, E23/E24). Eigentum wie tests/history/owned.sh: Die Datenbank
 *        wird ohne IF NOT EXISTS angelegt, registriert und am Ende nur selbst angelegt wieder entfernt.
 *
 * Abgedeckt: Ergebnisstruktur, unveränderte Fachdaten (vollständige Inhalts-Prüfsumme), Wiederholbarkeit, Prüflauf,
 * Advisory-Lock, Schema fail-closed, Fehlerinjektion je INSERT (Trigger) mit vollständigem Rollback, Teilabbruch und
 * deterministischer Wiederanlauf, inkonsistente Revisionen, tatsächlicher Lock-Wait gegen eine laufende
 * Fachtransaktion mit Mutationsprobe (Leser ohne Sperren), gelöschte Zone während des Laufs, Suche nach Geheimnissen.
 *
 * Aufruf (als root über den MariaDB-Socket): php db_test.php <App-Verzeichnis> <RR|RC>
 */

require_once __DIR__ . '/lib.php';

[$app, $iso] = [rtrim($argv[1] ?? '', '/'), $argv[2] ?? ''];
if (!is_dir("$app/web/inc/app/history") || !in_array($iso, ['RR', 'RC'], true)) {
    fwrite(STDERR, "Aufruf: php db_test.php <App-Verzeichnis> <RR|RC>\n");
    exit(2);
}
require_once "$app/web/inc/app/history/HistoryBaselineService.php";
require_once "$app/web/inc/app/history/ZoneStateSnapshot.php";

const SOCK = '/run/mysqld/mysqld.sock';
$RUN = getenv('H2A_RUN') ?: bin2hex(random_bytes(4));
if (preg_match('/^[0-9a-f]{8}$/D', $RUN) !== 1) {
    fwrite(STDERR, "ABBRUCH: ungültige Lauf-Kennung H2A_RUN" . PHP_EOL);
    exit(2);
}
$DB = "dnsmgr_t21_r{$RUN}_b" . strtolower($iso);
$OWNED = false;   // erst nach erfolgreichem CREATE DATABASE (ohne IF NOT EXISTS) Eigentum dieses Laufs
register_shutdown_function(static function (): void {
    global $OWNED, $DB;
    if ($OWNED) {
        exec("mariadb -e 'DROP DATABASE IF EXISTS `$DB`' 2>&1");
    }
});
$W = rtrim(sys_get_temp_dir(), '/') . '/dnsmgr-h2a-db-' . strtolower($iso) . '-' . getmypid();
mkdir($W, 0700);

function connect(string $db, string $iso): PDO
{
    $pdo = new PDO('mysql:unix_socket=' . SOCK . ";dbname=$db;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($iso === 'RR') {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');
    } else {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    return $pdo;
}

function sh(string $cmd): string
{
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        throw new RuntimeException("Befehl fehlgeschlagen ($rc): $cmd\n" . implode("\n", $out));
    }
    return implode("\n", $out);
}

/** Datenbank neu aus sql/dnsmanager_schema.sql (Stand des Commits) und Fixture aufbauen */
function resetDb(): PDO
{
    global $DB, $app, $iso, $OWNED;
    if ($OWNED) {
        sh("mariadb -e 'DROP DATABASE `$DB`'");   // eigene Datenbank aus dem ersten Anlegen
    }
    sh("mariadb -e 'CREATE DATABASE `$DB` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'");   // scheitert, falls fremd vorhanden
    if (!$OWNED) {
        $OWNED = true;
        $reg = getenv('H2A_REGISTRY');
        if (is_string($reg) && $reg !== '') {
            file_put_contents($reg, "db $DB" . PHP_EOL, FILE_APPEND);
        }
    }
    sh("grep -vE '^(CREATE DATABASE|USE )' " . escapeshellarg("$app/sql/dnsmanager_schema.sql") . " | mariadb $DB");
    sh("mariadb $DB < " . escapeshellarg(__DIR__ . '/fixture.sql'));
    $r = new ReflectionProperty(SchemaCheck::class, 'cache');
    $r->setValue(null, []);
    return connect($DB, $iso);
}

/** Vollständiger Inhalt aller Fachtabellen (sortiert) als Prüfsumme */
function bizsum(): string
{
    global $DB;
    return hash('sha256', sh("mysqldump --skip-dump-date --skip-comments --order-by-primary --no-create-info --skip-extended-insert $DB"
        . ' users user_zones zones zone_servers servers records dyndns_accounts system_status deployments diagnostics diagnostic_log'));
}

function counts(PDO $pdo): string
{
    return implode(',', array_map(
        static fn (string $t): string => (string)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(),
        ['history_change_sets', 'zone_snapshots', 'zone_revisions', 'zone_history_state']
    ));
}

function lockFree(PDO $pdo): bool
{
    return (int)$pdo->query("SELECT IS_FREE_LOCK('dnsmanager_publish_all')")->fetchColumn() === 1;
}

function openTrx(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()')->fetchColumn();
}

/** @return array<string, ServiceMessage[]> Meldungen nach Code */
function byCode(ServiceResult $r): array
{
    $m = [];
    foreach ($r->messages as $msg) {
        $m[$msg->code][] = $msg;
    }
    return $m;
}

function svc(PDO $pdo): HistoryBaselineService
{
    return new HistoryBaselineService($pdo);
}

/** Snapshot einer Zone unabhängig vom Leser (nicht sperrend, außerhalb einer Transaktion) */
function independentSnapshot(PDO $pdo, int $zoneId): ZoneStateSnapshot
{
    $z = $pdo->query("SELECT * FROM zones WHERE id = $zoneId")->fetch(PDO::FETCH_ASSOC);
    $s = $pdo->query("SELECT * FROM zone_servers WHERE zone_id = $zoneId")->fetchAll(PDO::FETCH_ASSOC);
    $r = $pdo->query("SELECT * FROM records WHERE zone_id = $zoneId")->fetchAll(PDO::FETCH_ASSOC);
    return ZoneStateSnapshot::fromRows($z, $s, $r);
}

/** Startet den Worker-Prozess; liefert [Prozess, Pipes] */
function startWorker(string $appDir, string $mode = 'apply'): array
{
    global $DB, $iso;
    $cmd = ['php', __DIR__ . '/baseline_worker.php', $appDir, $DB, $iso, $mode];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$proc, $pipes];
}

function finishWorker(array $w): array
{
    [$proc, $pipes] = $w;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $json = json_decode((string)$out, true);
    return is_array($json) ? $json : ['ok' => false, 'messages' => [], 'raw' => $out . $err];
}

/** Wartet bis zu 10 s darauf, dass eine Transaktion mit der Abfrage $like auf eine Sperre wartet */
function waitForLockWait(PDO $observer, string $like): bool
{
    $stmt = $observer->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT' AND trx_query LIKE ?");
    for ($i = 0; $i < 100; $i++) {
        $stmt->execute([$like]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
        usleep(100000);
    }
    return false;
}

function workerCodes(array $res, string $code): array
{
    return array_values(array_filter($res['messages'], static fn (array $m): bool => $m['code'] === $code));
}

echo "== Baseline – $iso (" . $DB . ")\n";

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('Grundablauf');
/* ---------------------------------------------------------------------------------------------------------------- */

$pdo = resetDb();
T::eq($iso === 'RR' ? ['REPEATABLE-READ', '1'] : ['READ-COMMITTED'], $iso === 'RR'
    ? array_map('strval', $pdo->query('SELECT @@transaction_isolation, @@innodb_snapshot_isolation')->fetch(PDO::FETCH_NUM))
    : [(string)$pdo->query('SELECT @@transaction_isolation')->fetchColumn()], 'B00', 'Sitzungseinstellungen');
T::eq([], SchemaCheck::problems($pdo), 'B01', 'Schema vollständig (SchemaCheck)');
$st = svc($pdo)->status()->messages[0]->context;
T::eq([4, 0, [1, 2, 3, 5]], [$st['total'], $st['withHead'], array_column($st['missing'], 'id')], 'B02', 'status vor der Baseline');

$sum0 = bizsum();
$flags0 = $pdo->query('SELECT GROUP_CONCAT(CONCAT(id, ":", changed, ":", soa_serial) ORDER BY id), (SELECT bind_dirty FROM system_status) FROM zones')->fetch(PDO::FETCH_NUM);

$dry = svc($pdo)->run(false);
$m = byCode($dry);
T::ok($dry->ok, 'B03', 'Prüflauf erfolgreich');
T::eq(4, count($m['history.baseline.zone_planned'] ?? []), 'B03b', 'Prüflauf: 4 Zonen geplant');
T::eq('0,0,0,0', counts($pdo), 'B03c', 'Prüflauf schreibt nichts');
T::eq($sum0, bizsum(), 'B03d', 'Prüflauf: Fachdaten unverändert');
T::ok(lockFree($pdo) && openTrx($pdo) === 0, 'B03e', 'Prüflauf: Lock frei, keine offene Transaktion');
$plannedHash = [];
foreach ($m['history.baseline.zone_planned'] ?? [] as $x) {
    $plannedHash[$x->context['zoneId']] = $x->context['contentHash'];
}

$run = svc($pdo)->run(true);
$m = byCode($run);
T::ok($run->ok, 'B04', 'Baseline erfolgreich');
T::eq(4, count($m['history.baseline.zone_recorded'] ?? []), 'B04b', '4 Zonen angelegt');
T::eq($sum0, bizsum(), 'B04c', 'Fachdaten vollständig unverändert (Inhalts-Prüfsumme aller Fachtabellen)');
T::eq($flags0, $pdo->query('SELECT GROUP_CONCAT(CONCAT(id, ":", changed, ":", soa_serial) ORDER BY id), (SELECT bind_dirty FROM system_status) FROM zones')->fetch(PDO::FETCH_NUM), 'B04d', 'changed, soa_serial und bind_dirty unverändert (Zone 1 bleibt ausstehend)');
T::ok(lockFree($pdo) && openTrx($pdo) === 0, 'B04e', 'Lock frei, keine offene Transaktion');
T::eq('4,4,4,4', counts($pdo), 'B04f', 'je Zone Änderungsmenge, Snapshot, Revision, Head');

$recordedHash = [];
foreach ($m['history.baseline.zone_recorded'] ?? [] as $x) {
    $recordedHash[$x->context['zoneId']] = $x->context['contentHash'];
}
T::eq($plannedHash, $recordedHash, 'B05', 'Prüflauf und Baseline liefern dieselben Hashes');

$rows = $pdo->query(
    'SELECT r.zone_id, r.zone_name, r.revision_no, r.kind, r.content_hash, r.source_revision_no, r.diff_summary, r.change_set_id,'
    . ' s.head_revision_no, s.head_hash, s.tracking, s.deleted_at, p.payload, p.contract, p.record_count'
    . ' FROM zone_revisions r JOIN zone_history_state s ON s.zone_id = r.zone_id JOIN zone_snapshots p ON p.content_hash = r.content_hash ORDER BY r.zone_id'
)->fetchAll(PDO::FETCH_ASSOC);
$names = [1 => 'alpha.h2a.test', 2 => 'beta.h2a.test', 3 => '2.0.192.in-addr.arpa', 5 => 'empty.h2a.test'];
$recCount = [1 => 7, 2 => 2, 3 => 2, 5 => 0];
foreach ($rows as $r) {
    $z = (int)$r['zone_id'];
    $indep = independentSnapshot($pdo, $z);
    T::ok((int)$r['revision_no'] === 1 && $r['kind'] === 'baseline' && $r['source_revision_no'] === null && $r['diff_summary'] === null, "B06.$z", 'Revision 1, Art baseline, ohne Quelle und Diff');
    T::ok((int)$r['head_revision_no'] === 1 && $r['head_hash'] === $r['content_hash'] && $r['tracking'] === 'baseline_unverified' && $r['deleted_at'] === null, "B07.$z", 'Head = Revision 1, baseline_unverified');
    T::eq(hash('sha256', $r['payload']), $r['content_hash'], "B08.$z", 'Hash = SHA-256 der gespeicherten Payload');
    T::eq($indep->payload(), $r['payload'], "B09.$z", 'Payload = unabhängig gelesener DB-Stand');
    T::eq($r['content_hash'], ZoneStateSnapshot::fromPayload($r['payload'])->contentHash(), "B10.$z", 'Payload kanonisch (fromPayload)');
    T::ok($r['zone_name'] === $names[$z] && $r['contract'] === 'zs1' && (int)$r['record_count'] === $recCount[$z], "B11.$z", 'Zonenname, Vertrag, Recordzahl');
}
$cs = $pdo->query('SELECT operation, actor_type, actor_user_id, actor_label, actor_dyndns_account_id, channel, correlation_id, created_at FROM history_change_sets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
T::eq(4, count(array_filter($cs, static fn (array $c): bool => $c['operation'] === 'migration.baseline' && $c['actor_type'] === 'system'
    && $c['actor_user_id'] === null && $c['actor_label'] === null && $c['actor_dyndns_account_id'] === null && $c['channel'] === 'cli' && $c['created_at'] !== null)), 'B12', 'Änderungsmengen: migration.baseline, Akteur system, Kanal cli');
T::ok(count(array_unique(array_column($cs, 'correlation_id'))) === 1 && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string)$cs[0]['correlation_id']) === 1, 'B13', 'gemeinsame Lauf-UUID (v4) als correlation_id');
T::eq(4, (int)$pdo->query('SELECT COUNT(DISTINCT change_set_id) FROM zone_revisions')->fetchColumn(), 'B14', 'je Zone eigene Änderungsmenge');
T::ok(str_contains($rows[0]['payload'], '["mail","mx","10 mx.h2a.test.",null,null]') && substr_count($rows[0]['payload'], '["www","A","192.0.2.10",3600,null]') === 2, 'B15', 'Werte wie gespeichert (Kleinschreibung, NULL-TTL, Duplikat)');

// Geheimnisse
$all = '';
foreach (['history_change_sets', 'zone_snapshots', 'zone_revisions', 'zone_history_state'] as $t) {
    foreach ($pdo->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_NUM) as $row) {
        $all .= implode("\x1f", array_map('strval', $row)) . "\n";
    }
}
foreach (['$2y$', 'H2AFIXTUREHASH', 'H2ATOKEN', 'password', 'api_token', 'h2a_dyn', 'h2a_admin', '192.0.2.1"'] as $needle) {
    T::ok(!str_contains($all, $needle), 'B16', "keine Geheimnisse/Kontodaten in History-Tabellen: '$needle'");
}

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('Wiederholbarkeit und neue Zonen');
/* ---------------------------------------------------------------------------------------------------------------- */

$c = counts($pdo);
$again = svc($pdo)->run(true);
$m = byCode($again);
T::ok($again->ok && count($m['history.baseline.zone_skipped'] ?? []) === 4 && !isset($m['history.baseline.zone_recorded']), 'B17', 'zweiter Lauf: alle übersprungen (has_head)');
T::eq($c, counts($pdo), 'B17b', 'zweiter Lauf schreibt nichts');
T::eq($sum0, bizsum(), 'B17c', 'Fachdaten unverändert');
$st = svc($pdo)->status()->messages[0]->context;
T::eq([4, 4, []], [$st['total'], $st['withHead'], $st['missing']], 'B18', 'status nach der Baseline');
$pdo->exec("INSERT INTO zones (id, name, type, soa_ns, soa_mail) VALUES (6, 'neu.h2a.test', 'forward', 'ns1.h2a.test.', 'hostmaster.h2a.test.')");
$r6 = byCode(svc($pdo)->run(true));
T::eq([6], array_map(static fn ($x) => $x->context['zoneId'], $r6['history.baseline.zone_recorded'] ?? []), 'B19', 'neue Zone: nur sie wird angelegt');
T::eq('5,5,5,5', counts($pdo), 'B19b', 'bestehende Heads unverändert');

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('Vorbedingungen (ohne Schreibvorgang)');
/* ---------------------------------------------------------------------------------------------------------------- */

$pdo = resetDb();
$sum0 = bizsum();
$other = connect($DB, $iso);
$other->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
$res = svc($pdo)->run(true);
T::ok(!$res->ok && $res->messages[0]->code === 'history.baseline.locked', 'B20', 'Advisory-Lock belegt ⇒ locked');
T::eq('0,0,0,0', counts($pdo), 'B20b', 'locked: nichts geschrieben');
$other->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();

$pdo->beginTransaction();
$res = svc($pdo)->run(true);
$pdo->rollBack();
T::ok(!$res->ok && $res->messages[0]->code === 'history.baseline.in_transaction', 'B21', 'offene Transaktion ⇒ in_transaction');

$pdo->exec('RENAME TABLE zone_history_state TO zone_history_state_weg');
(new ReflectionProperty(SchemaCheck::class, 'cache'))->setValue(null, []);
$res = svc($pdo)->run(true);
T::ok(!$res->ok && $res->messages[0]->code === 'history.baseline.schema_incompatible' && in_array('zone_history_state fehlt', $res->messages[0]->context['problems'], true), 'B22', 'Schema fehlt ⇒ schema_incompatible (fail-closed)');
$res = svc($pdo)->status();
T::ok(!$res->ok && $res->messages[0]->code === 'history.baseline.schema_incompatible', 'B22b', 'status ebenfalls fail-closed');
$pdo->exec('RENAME TABLE zone_history_state_weg TO zone_history_state');
(new ReflectionProperty(SchemaCheck::class, 'cache'))->setValue(null, []);
T::eq('0,0,0,0', counts($pdo), 'B22c', 'nichts geschrieben');
T::eq($sum0, bizsum(), 'B22d', 'Fachdaten unverändert');
T::ok(lockFree($pdo), 'B22e', 'Lock frei');

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('Fehlerinjektion (Trigger je INSERT)');
/* ---------------------------------------------------------------------------------------------------------------- */

foreach (['zone_snapshots', 'history_change_sets', 'zone_revisions', 'zone_history_state'] as $t) {
    $pdo = resetDb();
    $sum0 = bizsum();
    $pdo->exec("CREATE TRIGGER h2a_fault BEFORE INSERT ON $t FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'h2a Fehlerinjektion'");
    $res = svc($pdo)->run(true);
    $m = byCode($res);
    $f = $m['history.baseline.failed'][0]->context ?? [];
    T::ok(!$res->ok && ($f['zoneId'] ?? null) === 1 && str_contains($f['detail'] ?? '', 'h2a Fehlerinjektion'), "B23.$t", "Fehler bei INSERT $t ⇒ Abbruch bei Zone 1");
    T::eq('0,0,0,0', counts($pdo), "B23b.$t", 'vollständiges Rollback, keine Historienzeilen');
    T::eq($sum0, bizsum(), "B23c.$t", 'Fachdaten unverändert');
    T::ok(openTrx($pdo) === 0 && lockFree($pdo), "B23d.$t", 'keine offene Transaktion, Lock frei');
    $pdo->exec('DROP TRIGGER h2a_fault');
    $res = svc($pdo)->run(true);
    T::ok($res->ok && count(byCode($res)['history.baseline.zone_recorded'] ?? []) === 4, "B23e.$t", 'Wiederanlauf erfolgreich');
}

// Teilabbruch bei Zone 3: Zonen 1 und 2 bleiben, Zone 3 vollständig zurückgerollt; Wiederanlauf deterministisch
$pdo = resetDb();
$pdo->exec("CREATE TRIGGER h2a_fault BEFORE INSERT ON zone_history_state FOR EACH ROW BEGIN IF NEW.zone_id = 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'h2a Teilabbruch'; END IF; END");
$res = svc($pdo)->run(true);
$m = byCode($res);
T::ok(!$res->ok && ($m['history.baseline.failed'][0]->context['zoneId'] ?? null) === 3, 'B24', 'Teilabbruch bei Zone 3');
T::eq('2,2,2,2', counts($pdo), 'B24b', 'Zonen 1 und 2 bestätigt, Zone 3 ohne Rückstände (auch keine Änderungsmenge/Snapshot)');
T::eq('1,2', (string)$pdo->query('SELECT GROUP_CONCAT(zone_id ORDER BY zone_id) FROM zone_history_state')->fetchColumn(), 'B24c', 'Heads nur für Zonen 1 und 2');
$pdo->exec('DROP TRIGGER h2a_fault');
$res = svc($pdo)->run(true);
$m = byCode($res);
T::eq([3, 5], array_map(static fn ($x) => $x->context['zoneId'], $m['history.baseline.zone_recorded'] ?? []), 'B24d', 'Wiederanlauf: nur Zonen 3 und 5');
T::eq('4,4,4,4', counts($pdo), 'B24e', 'danach vollständig');
T::eq(4, (int)$pdo->query("SELECT COUNT(*) FROM zone_revisions WHERE revision_no = 1 AND kind = 'baseline'")->fetchColumn(), 'B24f', 'je Zone genau eine Baseline-Revision');

// Revisionen ohne Head: nie überschreiben oder ergänzen
$pdo->exec('DELETE FROM zone_history_state WHERE zone_id = 2');
$c = counts($pdo);
$res = svc($pdo)->run(true);
$f = byCode($res)['history.baseline.failed'][0]->context ?? [];
T::ok(!$res->ok && ($f['zoneId'] ?? null) === 2 && str_contains($f['detail'] ?? '', 'ohne History-Head'), 'B25', 'Revision ohne Head ⇒ Abbruch (inkonsistent)');
T::eq($c, counts($pdo), 'B25b', 'nichts geschrieben');

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('Nebenläufigkeit: Lock-Wait gegen laufende Fachtransaktion');
/* ---------------------------------------------------------------------------------------------------------------- */

/**
 * Szenario: Eine Fachtransaktion sperrt Zone 2 (zones X wie RecordService) und ändert eine TTL, ohne zu committen.
 * Die Baseline muss auf die Sperre warten und danach den bestätigten neuen Stand aufzeichnen.
 * Rückgabe: [Lock-Wait beobachtet, Worker-Ergebnis, Payload Zone 2, Zone 1 vor dem Commit bestätigt]
 */
function lockScenario(string $appDir): array
{
    global $DB, $iso;
    $pdo = resetDb();
    $writer = connect($DB, $iso);
    $observer = connect($DB, 'RC');
    $writer->beginTransaction();
    $writer->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    $writer->exec("UPDATE records SET ttl = 111 WHERE zone_id = 2 AND name = 'web'");
    $w = startWorker($appDir);
    $waited = waitForLockWait($observer, '%FROM zones WHERE id = 2 FOR UPDATE%');
    $zone1Done = (int)$observer->query('SELECT COUNT(*) FROM zone_history_state WHERE zone_id = 1')->fetchColumn() === 1;
    usleep(300000);
    $writer->commit();
    $res = finishWorker($w);
    $payload = $observer->query('SELECT p.payload FROM zone_history_state s JOIN zone_snapshots p ON p.content_hash = s.head_hash WHERE s.zone_id = 2')->fetchColumn();
    return [$waited, $res, (string)$payload, $zone1Done, $pdo];
}

[$waited, $res, $payload, $zone1Done, $pdo] = lockScenario($app);
T::ok($waited, 'B26', 'Baseline wartet nachweislich auf die Zonensperre (innodb_trx LOCK WAIT)');
T::ok($zone1Done, 'B26b', 'Zone 1 war bereits bestätigt (eigene Transaktion je Zone)');
T::ok($res['ok'] === true && count(workerCodes($res, 'history.baseline.zone_recorded')) === 4, 'B26c', 'Baseline nach dem Commit erfolgreich (kein Fehler 1020)');
T::eq($iso === 'RR' ? ['REPEATABLE-READ', 1] : ['READ-COMMITTED'], $iso === 'RR' ? [$res['session']['isolation'] ?? '', $res['session']['snapshotIsolation'] ?? -1] : [$res['session']['isolation'] ?? ''], 'B26d', 'Worker-Sitzung wie vorgegeben');
T::ok(str_contains($payload, '["web","AAAA","2001:db8::10",111,null]'), 'B26e', 'Snapshot enthält den bestätigten neuen Stand (TTL 111)');
T::eq(independentSnapshot($pdo, 2)->payload(), $payload, 'B26f', 'Head = tatsächlicher DB-Stand nach dem Commit');

// Mutationsprobe: Leser ohne Sperren (FOR UPDATE / LOCK IN SHARE MODE entfernt) – der Test muss das erkennen
$mut = "$W/app-mutation";
sh('cp -a ' . escapeshellarg($app) . ' ' . escapeshellarg($mut));
$f = "$mut/web/inc/app/history/ZoneSnapshotReader.php";
file_put_contents($f, str_replace([' FOR UPDATE', ' LOCK IN SHARE MODE'], '', file_get_contents($f)));
T::ok(!str_contains(file_get_contents($f), 'FOR UPDATE'), 'B27', 'Mutationsprobe vorbereitet (Leser ohne Sperren)');
[$waitedM, $resM, $payloadM, , $pdo] = lockScenario($mut);
T::ok(!$waitedM, 'B27b', 'Mutationsprobe: kein Lock-Wait (erkannt)');
T::ok(str_contains($payloadM, '["web","AAAA","2001:db8::10",7200,null]') && !str_contains($payloadM, ',111,'), 'B27c', 'Mutationsprobe: veralteter Stand aufgezeichnet (erkannt: Head ≠ DB-Stand)');
T::ok(independentSnapshot($pdo, 2)->payload() !== $payloadM, 'B27d', 'Mutationsprobe: Head weicht vom DB-Stand ab – B26e/B26f würden fehlschlagen');

// Zone wird während des Laufs gelöscht (Löschung wartet die Baseline ab bzw. umgekehrt)
$pdo = resetDb();
$writer = connect($DB, $iso);
$observer = connect($DB, 'RC');
$writer->beginTransaction();
$writer->query('SELECT id FROM zones WHERE id = 3 FOR UPDATE')->fetchAll();
$writer->exec('DELETE FROM zones WHERE id = 3');
$w = startWorker($app);
$waited = waitForLockWait($observer, '%FROM zones WHERE id = 3 FOR UPDATE%');
$writer->commit();
$res = finishWorker($w);
T::ok($waited, 'B28', 'Baseline wartet auf die löschende Transaktion');
$sk = workerCodes($res, 'history.baseline.zone_skipped');
T::ok($res['ok'] === true && count($sk) === 1 && $sk[0]['context']['zoneId'] === 3 && $sk[0]['context']['reason'] === 'deleted', 'B28b', 'gelöschte Zone übersprungen (deleted)');
T::eq('1,2,5', (string)$observer->query('SELECT GROUP_CONCAT(zone_id ORDER BY zone_id) FROM zone_history_state')->fetchColumn(), 'B28c', 'Heads nur für bestehende Zonen');

// Aufräumen
sh("mariadb -e 'DROP DATABASE IF EXISTS `$DB`'");
$OWNED = false;
sh('rm -rf ' . escapeshellarg($W));
T::finish("db-$iso");
