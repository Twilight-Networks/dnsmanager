<?php
/**
 * Datei: tests/history/as3_test.php
 * Zweck: AS3 (Migration 006, E79/E87) gegen eine isolierte Datenbank dnsmgr_t21_r<RUN>_as3<rr|rc> unter REPEATABLE READ
 *        (innodb_snapshot_isolation = ON) bzw. READ COMMITTED:
 *   C  Datenvertrag zone_server_verification: gültige Zeilen je Zustand, letzter positiver Nachweis mit und ohne
 *      verified_run_id, unvollständige Nachweise und widersprüchliche Zustände abgewiesen (mit Name der verletzten
 *      Bedingung), NULL-Semantik (keine Bedingung wird durch UNKNOWN erfüllt), unbekannte Beobachtung lässt den
 *      positiven Nachweis bestehen
 *   S  32-Bit-Serials mit dem echten Generator (generate_zone_file, Modus publish; seit AS5 mit Test-Hook, G1 – der Hook
 *      registriert keinen Versuch): 2147483647 → 2147483648, seit AS5 4294967294/4294967295 → RFC-Wrap auf den
 *      Kalenderkandidaten statt Abbruch (docs/ARCHITECTURE_HISTORY.md Abschnitt 5k; Vektoren in as5_pure_test.php),
 *      4294967294 → 4294967295, 4294967295 → Abbruch mit vollständigem Rollback (Grenze bis AS5); Historie unverändert
 *   O  altes Schema (zones.soa_serial mit Vorzeichen): 2147483647 → Abbruch wie bisher; neuer Code meldet SchemaCheck-Befund
 *   K  alter Code (Basis-Commit) auf neuem Schema: SchemaCheck ohne Befund, Generator schreibt Serials > 2³¹−1
 *   T  statisch: generate_next_serial(), Generator, PublishLedger, ZoneService, History-Snapshot unverändert gegenüber dem
 *      Basis-Commit; zone_server_verification außerhalb von SchemaCheck, Schema und Migration nicht verwendet
 *
 * Aufruf (root, Testsystem): php as3_test.php <App-Verzeichnis> <RR|RC> <Basis-App-Verzeichnis>
 */

require_once __DIR__ . '/h2b_lib.php';

[$app, $iso, $baseApp] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (!is_dir("$baseApp/web")) {
    fwrite(STDERR, "Aufruf: php as3_test.php <App-Verzeichnis> <RR|RC> <Basis-App-Verzeichnis>\n");
    exit(2);
}
H2b::init($app, $iso, 'as3');
require_once "$app/web/inc/deploy/bind_file_generator.php";
require_once "$app/web/inc/app/SchemaCheck.php";

$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void {
    global $pass, $fail, $iso;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . "[$iso] $id" . ($detail !== '' ? " – $detail" : '') . "\n";
}

$pdo = H2b::reset(true);
echo "AS3-Tests ($iso) – Datenbank " . H2b::$db . "\n";

// ---------------------------------------------------------------- C: Datenvertrag zone_server_verification
$h1 = str_repeat('a', 64);
$h2 = str_repeat('b', 64);
$row = static fn (array $o): array => $o + [
    'zone_id' => 1, 'server_id' => 1, 'zone_name' => 'alpha.test', 'server_name' => 'ns1.test', 'last_state' => 'unknown',
    'last_reason' => null, 'last_method' => 'conf_sync', 'last_served_serial' => null, 'last_file_serial' => null,
    'last_file_hash' => null, 'last_run_id' => null, 'last_attempt_id' => null, 'last_observed_at' => '2026-11-20 10:00:00.000000',
    'verified_serial' => null, 'verified_file_hash' => null, 'verified_run_id' => null, 'verified_at' => null,
];
$insert = static function (PDO $pdo, array $r): ?string {
    $cols = array_keys($r);
    try {
        $pdo->exec('DELETE FROM zone_server_verification');
        $st = $pdo->prepare('INSERT INTO zone_server_verification (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($r));
        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
};
$valid = [
    'C01 verified mit Lauf' => ['last_state' => 'verified', 'last_served_serial' => 7, 'last_file_serial' => 7, 'last_file_hash' => $h1, 'last_run_id' => 30,
        'verified_serial' => 7, 'verified_file_hash' => $h1, 'verified_run_id' => 30, 'verified_at' => '2026-11-20 10:00:00.000000'],
    'C02 verified ohne Lauf (verified_run_id NULL, z. B. Monitoring)' => ['last_state' => 'verified', 'last_method' => 'monitoring', 'last_served_serial' => 4294967295,
        'last_file_serial' => 4294967295, 'last_file_hash' => $h1, 'verified_serial' => 4294967295, 'verified_file_hash' => $h1, 'verified_at' => '2026-11-20 10:00:00.000000'],
    'C03 serial_differs' => ['last_state' => 'serial_differs', 'last_served_serial' => 6, 'last_file_serial' => 7, 'last_file_hash' => $h1, 'last_reason' => 'last_round_unknown:dns_timeout'],
    'C04 still_served' => ['last_state' => 'still_served', 'last_method' => 'cleanup', 'last_served_serial' => 7, 'last_reason' => 'still_configured'],
    'C05 absent' => ['last_state' => 'absent', 'last_method' => 'cleanup', 'last_reason' => 'refused_control_ok'],
    'C06 unknown ohne Werte' => [],
    'C07 unknown nach früherem positivem Nachweis (Nachweis bleibt bestehen)' => ['last_state' => 'unknown', 'last_reason' => 'dns_timeout', 'last_observed_at' => '2026-11-21 10:00:00.000000',
        'verified_serial' => 7, 'verified_file_hash' => $h1, 'verified_run_id' => 30, 'verified_at' => '2026-11-20 10:00:00.000000'],
    'C08 absent nach früherem positivem Nachweis' => ['last_state' => 'absent', 'last_method' => 'cleanup', 'last_observed_at' => '2026-11-21 10:00:00.000000',
        'verified_serial' => 7, 'verified_file_hash' => $h1, 'verified_at' => '2026-11-20 10:00:00.000000'],
    'C09 zone_verify ohne Lauf' => ['last_state' => 'unknown', 'last_method' => 'zone_verify'],
];
foreach ($valid as $id => $o) {
    $e = $insert($pdo, $row($o));
    t($id . ' zulässig', $e === null, (string)$e);
}
$invalid = [
    'C20 positiver Nachweis nur Serial' => [['verified_serial' => 7], 'chk_zsv_verified_bundle'],
    'C21 positiver Nachweis ohne Zeitpunkt' => [['verified_serial' => 7, 'verified_file_hash' => $h1], 'chk_zsv_verified_bundle'],
    'C22 positiver Nachweis ohne Dateihash' => [['verified_serial' => 7, 'verified_at' => '2026-11-20 10:00:00.000000'], 'chk_zsv_verified_bundle'],
    'C23 nur Zeitpunkt' => [['verified_at' => '2026-11-20 10:00:00.000000'], 'chk_zsv_verified_bundle'],
    'C24 Lauf ohne positiven Nachweis' => [['verified_run_id' => 30], 'chk_zsv_verified_bundle'],
    'C25 verified ohne Serials (NULL darf nicht als erfüllt gelten)' => [['last_state' => 'verified'], 'chk_zsv_last_state'],
    'C26 verified ohne Dateihash' => [['last_state' => 'verified', 'last_served_serial' => 7, 'last_file_serial' => 7], 'chk_zsv_last_state'],
    'C27 verified mit abweichenden Serials' => [['last_state' => 'verified', 'last_served_serial' => 6, 'last_file_serial' => 7, 'last_file_hash' => $h1], 'chk_zsv_last_state'],
    'C28 verified ohne positiven Nachweis' => [['last_state' => 'verified', 'last_served_serial' => 7, 'last_file_serial' => 7, 'last_file_hash' => $h1], 'chk_zsv_verified_current'],
    'C29 verified mit abweichendem positivem Nachweis (Serial)' => [['last_state' => 'verified', 'last_served_serial' => 7, 'last_file_serial' => 7, 'last_file_hash' => $h1,
        'verified_serial' => 6, 'verified_file_hash' => $h1, 'verified_at' => '2026-11-20 10:00:00.000000'], 'chk_zsv_verified_current'],
    'C30 verified mit abweichendem positivem Nachweis (Hash)' => [['last_state' => 'verified', 'last_served_serial' => 7, 'last_file_serial' => 7, 'last_file_hash' => $h1,
        'verified_serial' => 7, 'verified_file_hash' => $h2, 'verified_at' => '2026-11-20 10:00:00.000000'], 'chk_zsv_verified_current'],
    'C31 verified mit älterem Zeitpunkt des Nachweises' => [['last_state' => 'verified', 'last_served_serial' => 7, 'last_file_serial' => 7, 'last_file_hash' => $h1,
        'verified_serial' => 7, 'verified_file_hash' => $h1, 'verified_at' => '2026-11-19 10:00:00.000000'], 'chk_zsv_verified_current'],
    'C32 serial_differs mit gleichen Serials' => [['last_state' => 'serial_differs', 'last_served_serial' => 7, 'last_file_serial' => 7], 'chk_zsv_last_state'],
    'C33 serial_differs ohne Datei-Serial' => [['last_state' => 'serial_differs', 'last_served_serial' => 7], 'chk_zsv_last_state'],
    'C34 still_served ohne Serial' => [['last_state' => 'still_served'], 'chk_zsv_last_state'],
    'C35 absent mit ausgelieferter Serial' => [['last_state' => 'absent', 'last_served_serial' => 7], 'chk_zsv_last_state'],
    'C36 Nachweis nach der letzten Beobachtung' => [['verified_serial' => 7, 'verified_file_hash' => $h1, 'verified_at' => '2026-11-21 10:00:00.000000'], 'chk_zsv_verified_order'],
    'C37 Grund mit Großbuchstaben' => [['last_reason' => 'Timeout'], 'chk_zsv_reason'],
    'C38 Dateihash nicht hexadezimal klein' => [['last_file_hash' => str_repeat('A', 64)], 'chk_zsv_hashes'],
    'C39 positiver Dateihash zu kurz' => [['verified_serial' => 7, 'verified_file_hash' => 'abc', 'verified_at' => '2026-11-20 10:00:00.000000'], 'chk_zsv_hashes'],
    'C40 Zone 0' => [['zone_id' => 0], 'chk_zsv_target'],
    'C41 leerer Servername' => [['server_name' => ''], 'chk_zsv_target'],
    'C42 unbekannter Zustand' => [['last_state' => 'loaded'], 'last_state'],
    'C43 unbekannte Methode' => [['last_method' => 'agent'], 'last_method'],
    'C44 Serial außerhalb 32 Bit' => [['last_state' => 'still_served', 'last_served_serial' => 4294967296], 'last_served_serial'],
    'C45 negative Serial' => [['last_state' => 'still_served', 'last_served_serial' => -1], 'last_served_serial'],
];
foreach ($invalid as $id => [$o, $why]) {
    $e = $insert($pdo, $row($o));
    t($id . ' abgewiesen', $e !== null && str_contains($e, $why), (string)$e);
}
// Eine spätere unbekannte Beobachtung überschreibt nur den Teil "letzte Beobachtung"; ein versehentliches Teil-Löschen
// des positiven Nachweises wird abgewiesen
$insert($pdo, $row($valid['C01 verified mit Lauf']));
$pdo->exec("UPDATE zone_server_verification SET last_state = 'unknown', last_reason = 'dns_timeout', last_served_serial = NULL, last_file_serial = NULL,"
    . " last_file_hash = NULL, last_observed_at = '2026-11-21 10:00:00.000000'");
$r = $pdo->query('SELECT verified_serial, verified_file_hash, verified_run_id FROM zone_server_verification')->fetch(PDO::FETCH_ASSOC);
t('C50 unbekannte Beobachtung lässt den positiven Nachweis bestehen', (int)$r['verified_serial'] === 7 && $r['verified_file_hash'] === $h1 && (int)$r['verified_run_id'] === 30);
try {
    $pdo->exec('UPDATE zone_server_verification SET verified_serial = NULL');
    t('C51 teilweises Löschen des positiven Nachweises abgewiesen', false);
} catch (PDOException $e) {
    t('C51 teilweises Löschen des positiven Nachweises abgewiesen', str_contains($e->getMessage(), 'chk_zsv_verified_bundle'), $e->getMessage());
}
$pdo->exec('DELETE FROM zone_server_verification');

// ---------------------------------------------------------------- S: 32-Bit-Serials mit dem echten Generator
$dir = sys_get_temp_dir() . '/dnsmgr-as3-gen-' . getmypid();
@mkdir($dir, 0700);
$zoneId = 2;   // beta.test (fixture_h2b.sql)
$serialOf = static fn (PDO $p): int => (int)$p->query("SELECT soa_serial FROM zones WHERE id = $zoneId")->fetchColumn();
$head = static fn (PDO $p): string => (string)$p->query("SELECT CONCAT(head_revision_no, ':', head_hash) FROM zone_history_state WHERE zone_id = $zoneId")->fetchColumn();
$revCount = static fn (PDO $p): int => (int)$p->query('SELECT COUNT(*) FROM zone_revisions')->fetchColumn();
$gen = static function (PDO $p, int $start) use ($zoneId, $dir, $serialOf): array {
    $p->exec("UPDATE zones SET soa_serial = $start WHERE id = $zoneId");
    $r = generate_zone_file($zoneId, $dir, 'publish', $p, static fn (array $g): array => []);   // AS5 (G1): Publish nur mit Journal-Hook
    $file = $r['path'] ?? null;
    $text = is_string($file) && is_file($file) ? (string)file_get_contents($file) : '';
    return ['status' => $r['status'] ?? '?', 'serial' => $serialOf($p), 'text' => $text, 'output' => (string)($r['output'] ?? ''),
        'tx' => $p->inTransaction(), 'files' => glob("$dir/*") ?: []];
};
$h0 = $head($pdo);
$n0 = $revCount($pdo);
$r = $gen($pdo, 2147483647);
t('S01 2147483647 → 2147483648 (neues Schema)', $r['status'] === 'ok' && $r['serial'] === 2147483648 && str_contains($r['text'], "\n            2147483648   ; Serial\n"),
    "status={$r['status']} serial={$r['serial']}");
// AS5 (D2): Bisher endete 4294967295 mit einem Abbruch (Grenze bis AS5); jetzt RFC-1982-Wrap auf den Kalenderkandidaten
$kal = (int)date('Ymd') * 100 + 1;
$r = $gen($pdo, 4294967294);
t('S02 AS5: 4294967294 → Kalenderkandidat (RFC-Wrap)', $r['status'] === 'ok' && $r['serial'] === $kal
    && str_contains($r['text'], "\n            $kal   ; Serial\n"), "status={$r['status']} serial={$r['serial']}");
array_map('unlink', glob("$dir/*") ?: []);
$r = $gen($pdo, 4294967295);
t('S03 AS5: 4294967295 → Kalenderkandidat statt Abbruch, keine offene Transaktion', $r['status'] === 'ok' && $r['serial'] === $kal
    && $r['tx'] === false, "status={$r['status']} serial={$r['serial']} " . substr($r['output'], 0, 120));
$pdo->exec("UPDATE zones SET soa_serial = 4294967295 WHERE id = $zoneId");
$ps = $pdo->query("SELECT soa_serial FROM zones WHERE id = $zoneId")->fetch(PDO::FETCH_NUM)[0];
t('S04 PDO liefert Werte > 2³¹−1 unverfälscht', (string)$ps === '4294967295' && PHP_INT_SIZE === 8, var_export($ps, true));
t('S05 Historie unverändert (kein Head-Wechsel, keine Revision durch Serial-Änderungen)', $head($pdo) === $h0 && $revCount($pdo) === $n0);
t('S06 Inhalts-Hash unabhängig von soa_serial, Invarianten der Historie erfüllt', H2b::currentHash($pdo, $zoneId) === explode(':', $h0)[1]
    && H2b::invariants($pdo) === [] && !H2b::hasOpenTrx($pdo), json_encode(H2b::invariants($pdo)));

// ---------------------------------------------------------------- O: altes Schema (Vorzeichen) zum Vergleich
$pdo->exec("UPDATE zones SET soa_serial = 2026112001 WHERE id = $zoneId");
$pdo->exec('ALTER TABLE zones MODIFY soa_serial INT NOT NULL DEFAULT 2025010101');
array_map('unlink', glob("$dir/*") ?: []);
$r = $gen($pdo, 2147483647);
t('O01 altes Schema: 2147483647 → Abbruch (Wertebereich), Serial unverändert, keine Datei', $r['status'] === 'error' && $r['serial'] === 2147483647 && $r['files'] === []
    && str_contains($r['output'], 'Out of range'), substr($r['output'], 0, 120));
// SchemaCheck speichert das Ergebnis je Prozess zwischen (eine Prüfung je Anfrage) ⇒ Prüfung in einem eigenen Prozess
$freshProblems = static function (string $appDir): ?array {
    $code = 'require ' . var_export("$appDir/web/inc/app/SchemaCheck.php", true) . ';'
        . '$p = new PDO("mysql:unix_socket=' . H2b::SOCK . ';dbname=' . H2b::$db . ';charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
        . 'echo json_encode(SchemaCheck::problems($p));';
    $r = json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1'), true);
    return is_array($r) ? $r : null;
};
$pr = $freshProblems($app);
t('O02 neuer Code auf altem Schema: SchemaCheck-Befund (Login fail-closed)', $pr === ['zones.soa_serial: Typ int(11) statt int(10) unsigned'], json_encode($pr));
$pdo->exec("UPDATE zones SET soa_serial = 2026112001 WHERE id = $zoneId");
$pdo->exec('ALTER TABLE zones MODIFY soa_serial INT UNSIGNED NOT NULL DEFAULT 2025010101');

// ---------------------------------------------------------------- K: alter Code (Basis-Commit) auf neuem Schema
// Seit AS4a enthält das Neuinstallationsschema auch Migration 007. Der Code vor AS3 ist nur mit dem Schemastand nach
// AS3 (vor 007) verträglich; deshalb wird 007 für diese Gruppe zurückgebaut (Notfall-Rückbau, sql/migrations/README.md).
if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zone_publish_targets' AND COLUMN_NAME = 'zone_protocol'")->fetchColumn() === 1) {
    $pdo->exec('ALTER TABLE zone_publish_targets DROP CONSTRAINT chk_zpt_zone_agent, DROP CONSTRAINT chk_zpt_zone_installed, DROP CONSTRAINT chk_zpt_zone_fence, DROP CONSTRAINT chk_zpt_conf_agent, DROP CONSTRAINT chk_zpt_conf_fence, DROP CONSTRAINT chk_zpt_conf_verify, DROP CONSTRAINT chk_zpt_conf_verify_fmt, DROP COLUMN zone_protocol, DROP COLUMN zone_effect, DROP COLUMN zone_installed_serial, DROP COLUMN zone_installed_hash, DROP COLUMN zone_reload, DROP COLUMN zone_fence_decision, DROP COLUMN zone_fence_code, DROP COLUMN conf_protocol, DROP COLUMN conf_effect, DROP COLUMN conf_reload, DROP COLUMN conf_fence_decision, DROP COLUMN conf_fence_code, DROP COLUMN conf_verify_state, DROP COLUMN conf_verify_reason, DROP COLUMN conf_verify_file_serial, DROP COLUMN conf_verify_file_hash, DROP COLUMN conf_verify_served_serial');
    $pdo->exec('ALTER TABLE publish_cleanup_results DROP CONSTRAINT chk_pcr_agent, DROP CONSTRAINT chk_pcr_fence, DROP CONSTRAINT chk_pcr_served, DROP COLUMN cleanup_protocol, DROP COLUMN cleanup_effect, DROP COLUMN fence_decision, DROP COLUMN fence_code, DROP COLUMN served_absent_count, DROP COLUMN served_still_count, DROP COLUMN served_unknown_count, DROP COLUMN unassigned_count');
    $pdo->exec('ALTER TABLE system_status DROP CONSTRAINT chk_ss_fence_domain, DROP COLUMN fence_domain');
}
$db = H2b::$db;
$code = 'require ' . var_export("$baseApp/web/inc/app/SchemaCheck.php", true) . ';'
    . 'require ' . var_export("$baseApp/web/inc/deploy/bind_file_generator.php", true) . ';'
    . '$p = new PDO("mysql:unix_socket=' . H2b::SOCK . ';dbname=' . $db . ';charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
    . '$p->exec("UPDATE zones SET soa_serial = 2147483647 WHERE id = ' . $zoneId . '");'
    . '$r = generate_zone_file(' . $zoneId . ', ' . var_export($dir, true) . ', "publish", $p);'
    . 'echo json_encode(["problems" => SchemaCheck::problems($p), "status" => $r["status"], "serial" => (int)$p->query("SELECT soa_serial FROM zones WHERE id = ' . $zoneId . '")->fetchColumn()]);';
$out = json_decode((string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1'), true);
t('K01 alter Code auf neuem Schema: SchemaCheck ohne Befund', is_array($out) && $out['problems'] === [], json_encode($out));
t('K02 alter Code auf neuem Schema: Generator schreibt 2147483648', is_array($out) && $out['status'] === 'ok' && $out['serial'] === 2147483648, json_encode($out));
// AS4b: Der aktuelle Code verlangt 007 strikt; auf dem Schemastand nach AS3 ohne 007 ist er fail-closed (genau die drei
// 007-Befunde). Ohne Befund auf dem Schema nach 007: as4b_test.php X01.
$pr = $freshProblems($app);
t('K03 aktueller Code (AS4b) auf dem Schema nach AS3 ohne 007: fail-closed mit genau den 007-Befunden', is_array($pr) && count($pr) === 3
    && $pr[0] === 'system_status.fence_domain fehlt' && str_starts_with($pr[1], 'zone_publish_targets: Struktur weicht ab (erwartet col|zone_protocol|')
    && str_starts_with($pr[2], 'publish_cleanup_results: Struktur weicht ab (erwartet col|cleanup_protocol|'), json_encode($pr));
array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);

// ---------------------------------------------------------------- T: statische Nachweise
// AS4b: PublishLedger.php und PublishService.php sind seit AS4b geändert (Agent-Ergebnisfelder, Fencing) und entfallen hier.
// AS5: soa_serial.php entfällt (generate_next_serial() durch SerialPolicy ersetzt), bind_file_generator.php ist geändert.
foreach (['web/inc/app/zones/ZoneService.php', 'web/inc/app/history/ZoneStateSnapshot.php',
    'web/inc/app/history/ZoneHistoryRecorder.php', 'web/inc/app/zones/ZoneReadService.php'] as $f) {
    t("T01 unverändert gegenüber Basis: $f", is_file("$app/$f") && hash_file('sha256', "$app/$f") === hash_file('sha256', "$baseApp/$f"));
}
// T03 (web/ und agent/ gegenüber dem Stand vor AS3 nur in SchemaCheck.php geändert) war ein Nachweis des AS3-PR und entfällt
// seit AS4b (Core- und Agent-Integration); die Schreiber von zone_server_verification prüfen T02 und as4b_test.php S01.
$hits = trim((string)shell_exec('grep -rl zone_server_verification ' . escapeshellarg("$app/web") . ' ' . escapeshellarg("$app/agent") . ' ' . escapeshellarg("$app/scripts") . ' 2>/dev/null'));
$allowed = ["$app/web/inc/app/SchemaCheck.php", "$app/scripts/migrate_006_zone_serial_verification.sh", "$app/scripts/migrate_007_journal_agent_results.sh",   // 007: nur Strukturprüfung (AS4a)
    "$app/scripts/migrate_008_zone_serial_references.sh",   // AS5: nur Kennwerte vor/nach der DDL
    "$app/web/inc/app/publish/ServerVerificationStore.php"];   // AS4b: einziger Schreiber und Leser (AS5: auch für die Referenzermittlung)
$extra = array_diff(array_filter(explode("\n", $hits)), $allowed);
t('T02 zone_server_verification nur in SchemaCheck, Migrationsskripten und ServerVerificationStore (AS4b)', $extra === [], implode(', ', $extra));

echo "ERGEBNIS as3 ($iso): $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
