<?php
/**
 * Datei: tests/history/baseline_worker.php
 * Zweck: Führt HistoryBaselineService in einem eigenen Prozess (eigene Verbindung) aus – für Nebenläufigkeitstests
 *        (AP1/H2a). Ausgabe: JSON mit Ergebnis, Meldungen und den wirksamen Sitzungseinstellungen.
 *
 * Aufruf (als root über den MariaDB-Socket): php baseline_worker.php <App-Verzeichnis> <dnsmgr_t21_*> <RR|RC> <apply|dry>
 */

[$app, $db, $iso, $mode] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
if (!preg_match('/^dnsmgr_t21_[a-z0-9_]+$/D', $db) || !in_array($iso, ['RR', 'RC'], true) || !in_array($mode, ['apply', 'dry'], true)) {
    fwrite(STDERR, "Aufruf: php baseline_worker.php <App> <dnsmgr_t21_*> <RR|RC> <apply|dry>\n");
    exit(2);
}
require_once "$app/web/inc/app/history/HistoryBaselineService.php";

$pdo = new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$db;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($iso === 'RR') {
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');
} else {
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
}
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 30');
$session = $pdo->query('SELECT @@transaction_isolation, @@innodb_snapshot_isolation, CONNECTION_ID()')->fetch(PDO::FETCH_NUM);

$result = (new HistoryBaselineService($pdo))->run($mode === 'apply');
echo json_encode([
    'ok'       => $result->ok,
    'messages' => array_map(static fn (ServiceMessage $m): array => ['kind' => $m->kind, 'code' => $m->code, 'context' => $m->context], $result->messages),
    'session'  => ['isolation' => $session[0], 'snapshotIsolation' => (int)$session[1], 'connectionId' => (int)$session[2]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
