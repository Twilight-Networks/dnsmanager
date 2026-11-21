<?php
/**
 * Datei: tests/history/as4a_test.php
 * Zweck: AS4a (Migration 007, Kompatibilitätsbrücke) gegen eine isolierte Datenbank dnsmgr_t21_r<RUN>_as4a<rr|rc> unter
 *        REPEATABLE READ (innodb_snapshot_isolation = ON) bzw. READ COMMITTED:
 *   G  Brücke: SchemaCheck akzeptiert zone_publish_targets und publish_cleanup_results je Tabelle exakt vor ODER exakt
 *      nach 007 (alle Kombinationen, auch Teilzustände) und weist jede andere Struktur ab (reine Mutationen der
 *      Signaturzeilen und echte DDL-Mutationen, u. a. die NULL-unsichere Form eines CHECKs, K1)
 *   C  CHECK-Vertrag 007a–c: gültige Belegungen, ungültige mit Name der verletzten Bedingung, insbesondere
 *      widersprüchliche NULL-Kombinationen (z. B. zone_protocol NULL bei gesetztem zone_effect) – keine Bedingung
 *      darf durch UNKNOWN erfüllt werden
 *   K  Code × Schema (eigene Prozesse): AS3-Code (Basis) vor 007 ohne Befund, nach 007 fail-closed; Brückencode vor,
 *      nach und in Teilzuständen ohne Befund
 *   V  Verhalten unverändert: derselbe Journalablauf (J1, J2 mit echtem Generator, J4, Cleanup, Abschluss) liefert vor
 *      und nach 007 dieselben Journalwerte; die neuen Spalten bleiben leer
 *   S  statisch: web/ und agent/ gegenüber der Basis nur in SchemaCheck.php geändert, HISTORY_TABLES unverändert, die
 *      neuen Spalten werden außerhalb von SchemaCheck nicht verwendet
 *
 * Aufruf (root, Testsystem): php as4a_test.php <App-Verzeichnis> <RR|RC> <Basis-App-Verzeichnis (Stand vor AS4a)>
 */

require_once __DIR__ . '/h2b_lib.php';

[$app, $iso, $baseApp] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (!is_dir("$baseApp/web")) {
    fwrite(STDERR, "Aufruf: php as4a_test.php <App-Verzeichnis> <RR|RC> <Basis-App-Verzeichnis>\n");
    exit(2);
}
H2b::init($app, $iso, 'as4a');
require_once "$app/web/inc/app/SchemaCheck.php";
require_once "$app/web/inc/app/publish/PublishLedger.php";
require_once "$app/web/inc/app/publish/PublishTargetClassifier.php";
require_once "$app/web/inc/app/publish/PublishHashContract.php";
require_once "$app/web/inc/app/history/HistoryContext.php";
require_once "$app/web/inc/deploy/bind_file_generator.php";

$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void {
    global $pass, $fail, $iso;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . "[$iso] $id" . ($detail !== '' ? " – $detail" : '') . "\n";
}

const ZPT = 'zone_publish_targets';
const PCR = 'publish_cleanup_results';
$migDir = "$app/sql/migrations";
$rb = [
    'a' => 'ALTER TABLE zone_publish_targets DROP CONSTRAINT chk_zpt_zone_agent, DROP CONSTRAINT chk_zpt_zone_installed, DROP CONSTRAINT chk_zpt_zone_fence, DROP CONSTRAINT chk_zpt_conf_agent, DROP CONSTRAINT chk_zpt_conf_fence, DROP CONSTRAINT chk_zpt_conf_verify, DROP CONSTRAINT chk_zpt_conf_verify_fmt, DROP COLUMN zone_protocol, DROP COLUMN zone_effect, DROP COLUMN zone_installed_serial, DROP COLUMN zone_installed_hash, DROP COLUMN zone_reload, DROP COLUMN zone_fence_decision, DROP COLUMN zone_fence_code, DROP COLUMN conf_protocol, DROP COLUMN conf_effect, DROP COLUMN conf_reload, DROP COLUMN conf_fence_decision, DROP COLUMN conf_fence_code, DROP COLUMN conf_verify_state, DROP COLUMN conf_verify_reason, DROP COLUMN conf_verify_file_serial, DROP COLUMN conf_verify_file_hash, DROP COLUMN conf_verify_served_serial',
    'b' => 'ALTER TABLE publish_cleanup_results DROP CONSTRAINT chk_pcr_agent, DROP CONSTRAINT chk_pcr_fence, DROP CONSTRAINT chk_pcr_served, DROP COLUMN cleanup_protocol, DROP COLUMN cleanup_effect, DROP COLUMN fence_decision, DROP COLUMN fence_code, DROP COLUMN served_absent_count, DROP COLUMN served_still_count, DROP COLUMN served_unknown_count, DROP COLUMN unassigned_count',
    'c' => 'ALTER TABLE system_status DROP CONSTRAINT chk_ss_fence_domain, DROP COLUMN fence_domain',
];
$stepFile = ['a' => "$migDir/007a_zone_publish_targets_agent.sql", 'b' => "$migDir/007b_publish_cleanup_results_agent.sql",
    'c' => "$migDir/007c_system_status_fence_domain.sql"];
$applyStep = static function (string $s) use ($stepFile): void {
    H2b::sh('mariadb ' . H2b::$db . ' < ' . escapeshellarg($stepFile[$s]));
};
// SchemaCheck speichert das Ergebnis je Prozess ⇒ Prüfung des jeweiligen Codestands in einem eigenen Prozess
$problemsOf = static function (string $appDir): ?array {
    $code = 'require ' . var_export("$appDir/web/inc/app/SchemaCheck.php", true) . ';'
        . '$p = new PDO("mysql:unix_socket=' . H2b::SOCK . ';dbname=' . H2b::$db . ';charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
        . 'echo json_encode(SchemaCheck::problems($p));';
    $r = json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1'), true);
    return is_array($r) ? $r : null;
};
$evalRows = static function (array $rows): array {
    return array_map(static fn (array $x): string => $x['state'], SchemaCheck::evaluateHistory($rows));
};
$rowsFor = static function (array $byTable): array {
    $rows = [];
    foreach ($byTable as $table => $lines) {
        foreach ($lines as $l) {
            $rows[] = [$table, $l];
        }
    }
    return $rows;
};

$pdo = H2b::reset(true);
echo "AS4a-Tests ($iso) – Datenbank " . H2b::$db . "\n";

// ---------------------------------------------------------------- G: Brücke
$post = $pdo->query(SchemaCheck::historySignatureSql())->fetchAll(PDO::FETCH_NUM);
$byTable = [];
foreach ($post as [$tb, $l]) {
    $byTable[(string)$tb][] = (string)$l;
}
t('G01 Neuinstallation (nach 007): alle History-Tabellen ok', array_unique(array_values($evalRows($rowsFor($byTable)))) === ['ok']);
t('G02 Signaturen nach 007 = HISTORY_TABLES_007', $byTable[ZPT] === SchemaCheck::HISTORY_TABLES_007[ZPT] && $byTable[PCR] === SchemaCheck::HISTORY_TABLES_007[PCR]);
foreach (['pre/pre' => [false, false], 'pre/post' => [false, true], 'post/pre' => [true, false], 'post/post' => [true, true]] as $label => [$a, $b]) {
    $m = $byTable;
    $m[ZPT] = $a ? SchemaCheck::HISTORY_TABLES_007[ZPT] : SchemaCheck::HISTORY_TABLES[ZPT];
    $m[PCR] = $b ? SchemaCheck::HISTORY_TABLES_007[PCR] : SchemaCheck::HISTORY_TABLES[PCR];
    $e = $evalRows($rowsFor($m));
    t("G03 Kombination $label (zone_publish_targets/publish_cleanup_results): ok", $e[ZPT] === 'ok' && $e[PCR] === 'ok' && array_unique(array_values($e)) === ['ok']);
}
$mutations = [
    'Spalte fehlt (nach 007)' => [ZPT, static fn (array $l): array => array_values(array_filter($l, static fn (string $x): bool => !str_starts_with($x, 'col|conf_verify_served_serial|')))],
    'zusätzliche Spalte (nach 007)' => [PCR, static fn (array $l): array => array_merge(array_slice($l, 0, 22), ['col|x|int(11)|YES|NULL||~|~'], array_slice($l, 22))],
    'CHECK-Ausdruck geändert' => [ZPT, static fn (array $l): array => array_map(static fn (string $x): string => str_starts_with($x, 'chk|chk_zpt_zone_agent|') ? str_replace('`zone_protocol` is not null and ', '', $x) : $x, $l)],
    'CHECK fehlt' => [PCR, static fn (array $l): array => array_values(array_filter($l, static fn (string $x): bool => !str_starts_with($x, 'chk|chk_pcr_served|')))],
    'ENUM-Wert ergänzt' => [ZPT, static fn (array $l): array => array_map(static fn (string $x): string => str_starts_with($x, 'col|zone_protocol|') ? str_replace("'as2')", "'as2','as3')", $x) : $x, $l)],
    'NULL-Fähigkeit geändert' => [PCR, static fn (array $l): array => array_map(static fn (string $x): string => str_starts_with($x, 'col|unassigned_count|') ? str_replace('|YES|NULL|', '|NO|~|', $x) : $x, $l)],
    'Mischform: vor 007 plus eine neue Spalte' => [ZPT, static fn (array $l): array => array_merge(array_slice(SchemaCheck::HISTORY_TABLES[ZPT], 0, 16), ['col|zone_protocol|enum(\'legacy\',\'as2\')|YES|NULL||ascii|ascii_bin'], array_slice(SchemaCheck::HISTORY_TABLES[ZPT], 16))],
    'Mischform: nach 007 ohne die neuen CHECKs' => [ZPT, static fn (array $l): array => array_values(array_filter($l, static fn (string $x): bool => !preg_match('/^chk\|chk_zpt_(zone_agent|zone_installed|zone_fence|conf_agent|conf_fence|conf_verify|conf_verify_fmt)\|/', $x)))],
    'Reihenfolge der Spalten vertauscht' => [ZPT, static function (array $l): array { $i = 16; [$l[$i], $l[$i + 1]] = [$l[$i + 1], $l[$i]]; return $l; }],
];
foreach ($mutations as $label => [$table, $fn]) {
    $m = $byTable;
    $m[$table] = $fn($m[$table]);
    $e = $evalRows($rowsFor($m));
    t("G10 rein: $label ⇒ $table mismatch", $m[$table] !== $byTable[$table] && $e[$table] === 'mismatch');
}
$m = $byTable;
$m['zone_server_verification'][] = 'col|x|int(11)|YES|NULL||~|~';
t('G11 rein: zone_server_verification bleibt strikt (keine Brücke)', $evalRows($rowsFor($m))['zone_server_verification'] === 'mismatch');
$m = $byTable;
$m['publish_runs'] = array_merge($m['publish_runs'], ['col|x|int(11)|YES|NULL||~|~']);
t('G12 rein: übrige Journaltabellen bleiben strikt', $evalRows($rowsFor($m))['publish_runs'] === 'mismatch');

// ---------------------------------------------------------------- C: CHECK-Vertrag 007a–c (Schema nach 007)
$pdo->exec("INSERT INTO publish_runs (kind, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id, status, result, finished_at)"
    . " VALUES ('all', 0, 1, 0, 'system', 'cli', '22222222-2222-4222-8222-222222222222', 'finished', 'success', NOW(6))");
$runId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO zone_publish_attempts (run_id, zone_id, zone_name, revision_basis, content_hash, snapshot_contract, render_hash, file_hash, render_contract, soa_serial, target_count, outcome, finished_at)"
    . " VALUES ($runId, 2, 'beta.h2b.test', 'no_head', REPEAT('a', 64), 'zs1', REPEAT('b', 64), REPEAT('c', 64), 'rh1', 2026112101, 1, 'published', NOW(6))");
$attemptId = (int)$pdo->lastInsertId();
$h = str_repeat('e', 64);
$ins = static function (string $table, array $cols) use ($pdo, $runId, $attemptId): ?string {
    $base = $table === ZPT ? ['attempt_id' => $attemptId, 'server_id' => 1, 'server_name' => 'ns1.h2b.test']
        : ['run_id' => $runId, 'server_id' => 1, 'server_name' => 'ns1.h2b.test'];
    $r = $cols + $base;
    try {
        $pdo->exec("DELETE FROM $table WHERE " . ($table === ZPT ? "attempt_id = $attemptId" : "run_id = $runId"));
        $st = $pdo->prepare("INSERT INTO $table (" . implode(', ', array_keys($r)) . ') VALUES (' . implode(', ', array_fill(0, count($r), '?')) . ')');
        $st->execute(array_values($r));
        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
};
$zOk = ['zone_sync' => 'ok', 'zone_sync_at' => '2026-11-21 10:00:00'];
$zUnk = ['zone_sync' => 'unknown', 'zone_sync_at' => '2026-11-21 10:00:00', 'zone_error_code' => 'timeout'];
$zRej = ['zone_sync' => 'rejected', 'zone_sync_at' => '2026-11-21 10:00:00', 'zone_http_status' => 409, 'zone_error_code' => 'stale_fence'];
$cOk = ['conf_sync' => 'ok', 'conf_sync_at' => '2026-11-21 10:00:00'];
$cUnk = ['conf_sync' => 'unknown', 'conf_sync_at' => '2026-11-21 10:00:00', 'conf_error_code' => 'timeout'];
$as2c = ['conf_protocol' => 'as2', 'conf_effect' => 'applied'];
$validZ = [
    'C01 alles leer (sending, Brückencode)' => [],
    'C02 zone ok ohne Protokoll (H2d/Brückencode)' => $zOk,
    'C03 zone legacy ok' => $zOk + ['zone_protocol' => 'legacy'],
    'C04 zone as2 ok mit installiertem Stand und Reload' => $zOk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_installed_serial' => 4294967295, 'zone_installed_hash' => $h, 'zone_reload' => 'ok', 'zone_fence_decision' => 'accept'],
    'C05 zone as2 abgelehnt (stale_fence)' => $zRej + ['zone_protocol' => 'as2', 'zone_effect' => 'none', 'zone_fence_decision' => 'reject', 'zone_fence_code' => 'stale_fence'],
    'C06 zone as2 unknown (Reload gescheitert)' => $zUnk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_reload' => 'failed'],
    'C07 conf as2 verified' => $cOk + $as2c + ['conf_reload' => 'ok', 'conf_verify_state' => 'verified', 'conf_verify_file_serial' => 7, 'conf_verify_served_serial' => 7, 'conf_verify_file_hash' => $h],
    'C08 conf as2 serial_differs' => $cOk + $as2c + ['conf_verify_state' => 'serial_differs', 'conf_verify_file_serial' => 7, 'conf_verify_served_serial' => 6, 'conf_verify_reason' => 'last_round_unknown:dns_timeout'],
    'C09 conf as2 unknown ohne Werte' => $cOk + $as2c + ['conf_verify_state' => 'unknown', 'conf_verify_reason' => 'reload_failed'],
    'C10 conf as2 unknown (Zeitüberschreitung) ohne Verifikation' => $cUnk + ['conf_protocol' => 'as2', 'conf_effect' => 'unknown'],
    'C11 conf legacy ok' => $cOk + ['conf_protocol' => 'legacy'],
];
foreach ($validZ as $id => $cols) {
    $e = $ins(ZPT, $cols);
    t("$id zulässig", $e === null, (string)$e);
}
$invalidZ = [
    'C20 zone_protocol NULL bei gesetztem zone_effect (K1)' => [$zOk + ['zone_effect' => 'applied'], 'chk_zpt_zone_agent'],
    'C21 zone_protocol NULL bei gesetztem zone_reload (K1)' => [$zOk + ['zone_reload' => 'ok'], 'chk_zpt_zone_agent'],
    'C22 zone_protocol NULL bei gesetzter Fencing-Entscheidung (K1)' => [$zOk + ['zone_fence_decision' => 'accept'], 'chk_zpt_zone_agent'],
    'C23 zone_protocol NULL bei installiertem Stand (K1)' => [$zOk + ['zone_installed_serial' => 7, 'zone_installed_hash' => $h], 'chk_zpt_zone_agent'],
    'C24 legacy mit effect' => [$zOk + ['zone_protocol' => 'legacy', 'zone_effect' => 'applied'], 'chk_zpt_zone_agent'],
    'C25 as2 ohne effect' => [$zOk + ['zone_protocol' => 'as2'], 'chk_zpt_zone_agent'],
    'C26 Protokoll ohne Antwort (sending)' => [['zone_protocol' => 'as2', 'zone_effect' => 'none'], 'chk_zpt_zone_agent'],
    'C27 Protokoll bei nicht zugestellter Anfrage (failed)' => [['zone_sync' => 'failed', 'zone_sync_at' => '2026-11-21 10:00:00', 'zone_error_code' => 'not_delivered', 'zone_protocol' => 'legacy'], 'chk_zpt_zone_agent'],
    'C28 installierte Serial ohne Hash' => [$zOk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_installed_serial' => 7], 'chk_zpt_zone_installed'],
    'C29 installierter Hash ohne Serial' => [$zOk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_installed_hash' => $h], 'chk_zpt_zone_installed'],
    'C30 installierter Stand bei zone_sync unknown' => [$zUnk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_installed_serial' => 7, 'zone_installed_hash' => $h], 'chk_zpt_zone_installed'],
    'C31 installierter Hash in Großbuchstaben' => [$zOk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_installed_serial' => 7, 'zone_installed_hash' => strtoupper($h)], 'chk_zpt_zone_installed'],
    'C32 Fencing-Code ohne Entscheidung (K1)' => [$zOk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_fence_code' => 'stale_fence'], 'chk_zpt_zone_fence'],
    'C33 Fencing-Code ungültig' => [$zOk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_fence_decision' => 'reject', 'zone_fence_code' => 'Stale'], 'chk_zpt_zone_fence'],
    'C34 conf_protocol NULL bei gesetztem conf_effect (K1)' => [$cOk + ['conf_effect' => 'none'], 'chk_zpt_conf_agent'],
    'C35 conf_protocol NULL bei gesetztem Verifikationszustand (K1)' => [$cOk + ['conf_verify_state' => 'unknown'], 'chk_zpt_conf_agent'],
    'C36 conf legacy mit Verifikation' => [$cOk + ['conf_protocol' => 'legacy', 'conf_verify_state' => 'verified', 'conf_verify_file_serial' => 7, 'conf_verify_served_serial' => 7, 'conf_verify_file_hash' => $h], 'chk_zpt_conf_agent'],
    'C37 Verifikationswerte ohne Zustand (K1)' => [$cOk + $as2c + ['conf_verify_reason' => 'dns_timeout'], 'chk_zpt_conf_verify'],
    'C38 Datei-Serial ohne Zustand (K1)' => [$cOk + $as2c + ['conf_verify_file_serial' => 7], 'chk_zpt_conf_verify'],
    'C39 verified mit verschiedenen Serials' => [$cOk + $as2c + ['conf_verify_state' => 'verified', 'conf_verify_file_serial' => 7, 'conf_verify_served_serial' => 6, 'conf_verify_file_hash' => $h], 'chk_zpt_conf_verify'],
    'C40 verified ohne Datei-Hash' => [$cOk + $as2c + ['conf_verify_state' => 'verified', 'conf_verify_file_serial' => 7, 'conf_verify_served_serial' => 7], 'chk_zpt_conf_verify'],
    'C41 verified ohne ausgelieferte Serial (NULL darf nicht genügen)' => [$cOk + $as2c + ['conf_verify_state' => 'verified', 'conf_verify_file_serial' => 7, 'conf_verify_file_hash' => $h], 'chk_zpt_conf_verify'],
    'C42 verified bei conf_sync unknown' => [$cUnk + ['conf_protocol' => 'as2', 'conf_effect' => 'unknown', 'conf_verify_state' => 'verified', 'conf_verify_file_serial' => 7, 'conf_verify_served_serial' => 7, 'conf_verify_file_hash' => $h], 'chk_zpt_conf_verify'],
    'C43 serial_differs mit gleichen Serials' => [$cOk + $as2c + ['conf_verify_state' => 'serial_differs', 'conf_verify_file_serial' => 7, 'conf_verify_served_serial' => 7], 'chk_zpt_conf_verify'],
    'C44 serial_differs ohne ausgelieferte Serial' => [$cOk + $as2c + ['conf_verify_state' => 'serial_differs', 'conf_verify_file_serial' => 7], 'chk_zpt_conf_verify'],
    'C45 Grund mit Großbuchstaben' => [$cOk + $as2c + ['conf_verify_state' => 'unknown', 'conf_verify_reason' => 'Timeout'], 'chk_zpt_conf_verify_fmt'],
    'C46 Datei-Hash zu kurz' => [$cOk + $as2c + ['conf_verify_state' => 'unknown', 'conf_verify_file_hash' => 'abc'], 'chk_zpt_conf_verify_fmt'],
    'C47 conf-Fencing-Code ohne Entscheidung (K1)' => [$cOk + $as2c + ['conf_fence_code' => 'stale_fence'], 'chk_zpt_conf_fence'],
    'C48 unbekanntes Protokoll' => [$zOk + ['zone_protocol' => 'as3', 'zone_effect' => 'none'], 'zone_protocol'],
    'C49 installierte Serial außerhalb 32 Bit' => [$zOk + ['zone_protocol' => 'as2', 'zone_effect' => 'applied', 'zone_installed_serial' => 4294967296, 'zone_installed_hash' => $h], 'zone_installed_serial'],
];
foreach ($invalidZ as $id => [$cols, $why]) {
    $e = $ins(ZPT, $cols);
    t("$id abgewiesen", $e !== null && str_contains($e, $why), (string)$e);
}
$pOk = ['status' => 'ok', 'finished_at' => '2026-11-21 10:00:00', 'valid_zone_count' => 3, 'removed_conf_count' => 1, 'removed_zone_file_count' => 1];
$pRej = ['status' => 'rejected', 'finished_at' => '2026-11-21 10:00:00', 'http_status' => 409, 'error_code' => 'stale_fence'];
$cnt = ['served_absent_count' => 1, 'served_still_count' => 0, 'served_unknown_count' => 0, 'unassigned_count' => 0];
$validP = [
    'C60 Cleanup leer (sending)' => [],
    'C61 Cleanup ok ohne Protokoll (Brückencode)' => $pOk,
    'C62 Cleanup legacy ok' => $pOk + ['cleanup_protocol' => 'legacy'],
    'C63 Cleanup as2 ok mit Zählern' => $pOk + ['cleanup_protocol' => 'as2', 'cleanup_effect' => 'applied', 'fence_decision' => 'accept'] + $cnt,
    'C64 Cleanup as2 abgelehnt' => $pRej + ['cleanup_protocol' => 'as2', 'cleanup_effect' => 'none', 'fence_decision' => 'reject', 'fence_code' => 'stale_fence'],
];
foreach ($validP as $id => $cols) {
    $e = $ins(PCR, $cols);
    t("$id zulässig", $e === null, (string)$e);
}
$invalidP = [
    'C70 cleanup_protocol NULL bei gesetztem effect (K1)' => [$pOk + ['cleanup_effect' => 'applied'], 'chk_pcr_agent'],
    'C71 cleanup_protocol NULL bei gesetzten Zählern (K1)' => [$pOk + $cnt, 'chk_pcr_served'],
    'C72 legacy mit Zählern' => [$pOk + ['cleanup_protocol' => 'legacy'] + $cnt, 'chk_pcr_served'],
    'C73 as2 mit unvollständigen Zählern' => [$pOk + ['cleanup_protocol' => 'as2', 'cleanup_effect' => 'applied', 'served_absent_count' => 1, 'served_still_count' => 0, 'served_unknown_count' => 0], 'chk_pcr_served'],
    'C74 legacy mit effect' => [$pOk + ['cleanup_protocol' => 'legacy', 'cleanup_effect' => 'applied'], 'chk_pcr_agent'],
    'C75 as2 ohne effect' => [$pOk + ['cleanup_protocol' => 'as2'], 'chk_pcr_agent'],
    'C76 Protokoll ohne Antwort (sending)' => [['cleanup_protocol' => 'as2', 'cleanup_effect' => 'none'], 'chk_pcr_agent'],
    'C77 Fencing-Code ohne Entscheidung (K1)' => [$pOk + ['cleanup_protocol' => 'as2', 'cleanup_effect' => 'applied', 'fence_code' => 'stale_fence'], 'chk_pcr_fence'],
];
foreach ($invalidP as $id => [$cols, $why]) {
    $e = $ins(PCR, $cols);
    t("$id abgewiesen", $e !== null && str_contains($e, $why), (string)$e);
}
$pdo->exec("DELETE FROM zone_publish_targets WHERE attempt_id = $attemptId");
$pdo->exec("DELETE FROM publish_cleanup_results WHERE run_id = $runId");
foreach (['C80 Domäne gültig' => ['0f1e2d3c-4b5a-4968-8776-5a4b3c2d1e0f', true], 'C81 Domäne NULL' => [null, true],
    'C82 Domäne in Großbuchstaben' => ['0F1E2D3C-4B5A-4968-8776-5A4B3C2D1E0F', false], 'C83 Domäne ohne Bindestriche' => [str_repeat('a', 36), false]] as $id => [$v, $good]) {
    try {
        $pdo->prepare('UPDATE system_status SET fence_domain = ? WHERE id = 1')->execute([$v]);
        t($id . ($good ? ' zulässig' : ' abgewiesen'), $good);
    } catch (PDOException $e) {
        t($id . ($good ? ' zulässig' : ' abgewiesen'), !$good && str_contains($e->getMessage(), 'chk_ss_fence_domain'), $e->getMessage());
    }
}
$pdo->exec('UPDATE system_status SET fence_domain = NULL WHERE id = 1');

// ---------------------------------------------------------------- V: Journalablauf vor und nach 007 gleich
$dir = sys_get_temp_dir() . '/dnsmgr-as4a-gen-' . getmypid();
@mkdir($dir, 0700);
$flow = static function (PDO $p) use ($dir): array {
    $p->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    $ledger = new PublishLedger($p);
    $ledger->abandonStale();
    $run = $ledger->registerRun('zone', 2, false, HistoryContext::system('publish.zone', 'cli'));
    $g = generate_zone_file(2, $dir, 'publish', $p, static fn (array $j): array => $ledger->registerAttempt(
        $run, 2, $j['zone'], $j['records'], $j['serial'], $j['file'], ($j['render'])(PublishHashContract::SERIAL_PLACEHOLDER)));
    $aid = (int)($g['journal']['attemptId'] ?? 0);
    $ledger->recordZoneTarget($run, $aid, 1, PublishTargetClassifier::classify('zone_sync', ['body' => '{"status":"success"}', 'httpCode' => 200, 'curlError' => '', 'curlErrno' => 0]));
    $ledger->recordConfTarget($run, $aid, 1, PublishTargetClassifier::classify('conf_sync', ['body' => false, 'httpCode' => 0, 'curlError' => 'Operation timed out', 'curlErrno' => 28]), str_repeat('f', 64));
    $ledger->finishAttempt($run, $aid, 'partial');
    $ledger->registerCleanup($run, [1 => 'ns1.h2b.test', 2 => 'ns2.h2b.test']);
    $ledger->recordCleanup($run, 1, PublishTargetClassifier::classify('zone_cleanup', ['body' => '{"status":"success","removed_conf":[],"removed_zone_files":["db.x"]}', 'httpCode' => 200, 'curlError' => '', 'curlErrno' => 0]), 3);
    $ledger->recordCleanup($run, 2, PublishTargetClassifier::classify('zone_cleanup', ['body' => '{"status":"error","message":"nein"}', 'httpCode' => 403, 'curlError' => '', 'curlErrno' => 0]), 3);
    $ledger->finishRun($run, 'warning');
    $ledger->releaseRunLock($run);
    $p->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
    $q = static fn (string $sql): array => $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return [
        'status' => $g['status'] ?? '?',
        'run' => $q("SELECT kind, requested_zone_id, full_rebuild, actor_type, channel, status, result FROM publish_runs WHERE id = {$run->id}"),
        'attempt' => $q("SELECT zone_id, zone_name, revision_no, revision_basis, content_hash, snapshot_contract, render_hash, render_contract, target_count, outcome FROM zone_publish_attempts WHERE id = $aid"),
        'targets' => $q("SELECT server_id, server_name, agent_protocol, zone_sync, zone_http_status, zone_error_code, zone_error_detail, conf_sync, conf_http_status, conf_error_code, conf_error_detail, conf_hash FROM zone_publish_targets WHERE attempt_id = $aid ORDER BY server_id"),
        'cleanup' => $q("SELECT server_id, server_name, agent_protocol, status, valid_zone_count, removed_conf_count, removed_zone_file_count, http_status, error_code, error_detail FROM publish_cleanup_results WHERE run_id = {$run->id} ORDER BY server_id"),
        'runId' => $run->id, 'attemptId' => $aid,
    ];
};
try {
    $after = $flow($pdo);
    $newNull = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM zone_publish_targets WHERE COALESCE(zone_protocol, zone_effect, zone_installed_serial, zone_installed_hash, zone_reload, zone_fence_decision, zone_fence_code, conf_protocol, conf_effect, conf_reload, conf_fence_decision, conf_fence_code, conf_verify_state, conf_verify_reason, conf_verify_file_serial, conf_verify_file_hash, conf_verify_served_serial) IS NOT NULL)"
        . " + (SELECT COUNT(*) FROM publish_cleanup_results WHERE COALESCE(cleanup_protocol, cleanup_effect, fence_decision, fence_code, served_absent_count, served_still_count, served_unknown_count, unassigned_count) IS NOT NULL)")->fetchColumn();
    t('V01 Journalablauf auf dem Schema nach 007 (Brückencode)', $after['status'] === 'ok' && $after['attemptId'] > 0 && count($after['targets']) === 1 && count($after['cleanup']) === 2,
        json_encode([$after['status'], $after['targets'][0]['zone_sync'] ?? null, $after['targets'][0]['conf_sync'] ?? null]));
    t('V02 neue Spalten bleiben leer', $newNull === 0, "$newNull");
    foreach (['a', 'b', 'c'] as $s) {
        $pdo->exec($rb[$s]);
    }
    $before = $flow($pdo);
    t('V03 derselbe Ablauf auf dem Schema vor 007 liefert dieselben Journalwerte', $before['status'] === 'ok'
        && [$before['run'], $before['attempt'], $before['targets'], $before['cleanup']] === [$after['run'], $after['attempt'], $after['targets'], $after['cleanup']],
        json_encode(array_diff_assoc(array_map('json_encode', $before['targets'][0] ?? []), array_map('json_encode', $after['targets'][0] ?? []))));
    t('V04 keine offene Transaktion, Sperren frei', !H2b::hasOpenTrx($pdo) && (int)$pdo->query("SELECT IS_FREE_LOCK('dnsmanager_publish_all')")->fetchColumn() === 1);
} catch (Throwable $e) {
    t('V01–V04 Journalablauf', false, get_class($e) . ': ' . $e->getMessage());
}
array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);

// ---------------------------------------------------------------- K: Code × Schema (eigene Prozesse)
// Stand jetzt: Schema vor 007 (V hat zurückgebaut)
$pdo->exec('DELETE FROM zone_publish_targets');
$pdo->exec('DELETE FROM publish_cleanup_results');
$p = $problemsOf($baseApp);
t('K01 AS3-Code auf dem Schema vor 007: ohne Befund', $p === [], json_encode($p));
$p = $problemsOf($app);
t('K02 Brückencode auf dem Schema vor 007: ohne Befund', $p === [], json_encode($p));
foreach (['a' => 'nur 007a', 'b' => '007a und 007b', 'c' => '007a, 007b und 007c'] as $s => $label) {
    $applyStep($s);
    $p = $problemsOf($app);
    t("K03 Brückencode im Teilzustand bzw. nach 007 ($label): ohne Befund", $p === [], json_encode($p));
}
$p = $problemsOf($baseApp);
t('K04 AS3-Code auf dem Schema nach 007: fail-closed (beide Journaltabellen abweichend)', is_array($p) && count($p) === 2
    && str_starts_with($p[0], 'zone_publish_targets: Struktur weicht ab') && str_starts_with($p[1], 'publish_cleanup_results: Struktur weicht ab'), json_encode($p));
$pdo->exec($rb['a']);
$applyStep('a');
$pdo->exec("ALTER TABLE zone_publish_targets DROP CONSTRAINT chk_zpt_zone_agent, ADD CONSTRAINT chk_zpt_zone_agent CHECK ((zone_protocol IS NULL AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL) OR (zone_protocol = 'legacy' AND zone_sync IN ('ok','rejected','unknown') AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL) OR (zone_protocol = 'as2' AND zone_sync IN ('ok','rejected','unknown') AND zone_effect IS NOT NULL))");
$p = $problemsOf($app);
t('K05 Brückencode: CHECK in NULL-unsicherer Form (K1) ⇒ Befund', is_array($p) && count($p) === 1 && str_starts_with($p[0], 'zone_publish_targets: Struktur weicht ab'), json_encode($p));
try {
    $pdo->exec("INSERT INTO zone_publish_targets (attempt_id, server_id, server_name, zone_sync, zone_sync_at, zone_effect) VALUES ($attemptId, 9, 'x', 'ok', NOW(6), 'applied')");
    t('K06 Gegenprobe: die NULL-unsichere Form akzeptiert zone_protocol NULL bei gesetztem zone_effect (deshalb verboten)', true);
} catch (PDOException $e) {
    t('K06 Gegenprobe: die NULL-unsichere Form akzeptiert zone_protocol NULL bei gesetztem zone_effect (deshalb verboten)', false, $e->getMessage());
}

// ---------------------------------------------------------------- S: statische Nachweise
$diff = trim((string)shell_exec('LC_ALL=C diff -rq ' . escapeshellarg("$baseApp/web") . ' ' . escapeshellarg("$app/web") . ' 2>&1; LC_ALL=C diff -rq '
    . escapeshellarg("$baseApp/agent") . ' ' . escapeshellarg("$app/agent") . ' 2>&1'));
t('S01 web/ und agent/ gegenüber der Basis nur in SchemaCheck.php geändert',
    $diff === "Files $baseApp/web/inc/app/SchemaCheck.php and $app/web/inc/app/SchemaCheck.php differ", $diff);
$hashOf = static fn (string $appDir): string => trim((string)shell_exec('php -r ' . escapeshellarg('require ' . var_export("$appDir/web/inc/app/SchemaCheck.php", true)
    . '; echo hash("sha256", serialize([SchemaCheck::HISTORY_TABLES, SchemaCheck::REQUIRED]));')));
t('S02 HISTORY_TABLES und REQUIRED unverändert gegenüber der Basis', $hashOf($app) === $hashOf($baseApp) && strlen($hashOf($app)) === 64);
$cols = 'zone_protocol|zone_effect|zone_installed_|zone_reload|zone_fence_|conf_protocol|conf_effect|conf_reload|conf_fence_|conf_verify_|cleanup_protocol|cleanup_effect|served_absent|served_still|served_unknown|unassigned_count|fence_domain';
$hits = trim((string)shell_exec('grep -rlE ' . escapeshellarg($cols) . ' ' . escapeshellarg("$app/web") . ' 2>/dev/null'));   // agent/: unverändert (S01)
t('S03 neue Spalten in web/ außerhalb von SchemaCheck nicht verwendet (kein Leser, kein Schreiber vor AS4b)', $hits === "$app/web/inc/app/SchemaCheck.php", $hits);

echo "ERGEBNIS as4a ($iso): $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
