<?php
/**
 * Datei: tests/history/as5_worker.php
 * Zweck: Hilfsprozess von as5_test.php (AS5) mit eigener Datenbankverbindung und frei wählbarem Codestand.
 *        Ausgabe: eine JSON-Zeile.
 *
 * Aufruf: php as5_worker.php <App> <dnsmgr_t21_*> <RR|RC> <Operation> [Argumente …]
 *   publish <zoneId> <api_ip>=<Modus>[,…] [<Startmarke>]  PublishService::publishZone() des Codestands <App> gegen den
 *                                                         Ersatz-Agenten (mit <Startmarke>: Marke unmittelbar vor dem Aufruf)
 *   monitoring <zoneId> <zoneName> <serverId> <serverName> <W> <state> <file|-> <served|-> <hash|->
 *                                                         ServerVerificationStore::recordMonitoring() des Codestands <App>
 *                                                         unter dem Publish-Lock (wie MonitoringService)
 *   schemacheck                                           SchemaCheck::problems() des Codestands <App>
 * <App> kann der AS4b-Rückfallstand sein (Lücken nach einem Rückfall, Ergänzung 3.3; Code × Schema).
 */

[$app, $db, $iso, $op] = [rtrim($argv[1] ?? '', '/'), $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
if (!is_dir("$app/web") || preg_match('/^dnsmgr_t21_[a-z0-9_]+$/D', $db) !== 1 || !in_array($iso, ['RR', 'RC'], true)) {
    fwrite(STDERR, "Aufruf: php as5_worker.php <App> <dnsmgr_t21_*> <RR|RC> <Operation> …\n");
    exit(2);
}
$a = array_slice($argv, 5);
putenv("AS5_APP=$app");
if (!defined('BIND_BASE_DIR')) {
    define('BIND_BASE_DIR', sys_get_temp_dir() . '/dnsmgr-as5-bind');
}
$pdo = new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$db;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($iso === 'RR') {
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');
} else {
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
}
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');

function out(array $r): never
{
    echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

try {
    switch ($op) {
        case 'publish':
            require_once "$app/web/inc/app/publish/PublishService.php";
            require_once "$app/web/inc/app/publish/FenceContext.php";
            require_once "$app/web/inc/app/history/HistoryContext.php";
            require_once "$app/web/inc/deploy/LegacyBindPublishBackend.php";
            require_once __DIR__ . '/as5_fake_agent.php';
            $agent = new As5FakeAgent();
            foreach (explode(',', (string)$a[1]) as $m) {
                [$ip, $mode] = explode('=', $m, 2);
                $agent->modes[$ip] = $mode;
            }
            $svc = new PublishService($pdo, new LegacyBindPublishBackend($pdo, $agent), null, new FenceContext('5a4b3c2d-1e0f-4a9b-8c7d-6e5f4a3b2c1d'));
            if (isset($a[2])) {
                file_put_contents((string)$a[2], 'x');
            }
            $pdo->exec('UPDATE zones SET changed = 1 WHERE id = ' . (int)$a[0]);
            $res = $svc->publishZone((int)$a[0], 30);
            $ctx = $res->messages[array_key_last($res->messages)]->context;
            out(['status' => $ctx['status'], 'runId' => $ctx['runId'], 'syncs' => count($agent->syncs()),
                'exception' => $ctx['exception'] ?? null, 'errors' => $ctx['errors'] ?? []]);
        case 'monitoring':
            require_once "$app/web/inc/app/publish/ServerVerificationStore.php";
            $nn = static fn (string $v): ?int => $v === '-' ? null : (int)$v;
            if ((int)$pdo->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn() !== 1) {
                out(['error' => 'lock']);
            }
            $obs = ['state' => $a[5], 'reason' => $a[5] === 'unknown' ? 'test' : null, 'served' => $nn($a[7]), 'fileSerial' => $nn($a[6]),
                'fileHash' => $a[8] === '-' ? null : $a[8]];
            $r = (new ServerVerificationStore($pdo))->recordMonitoring((int)$a[4], [['zoneId' => (int)$a[0], 'zoneName' => $a[1],
                'serverId' => (int)$a[2], 'serverName' => $a[3], 'obs' => $obs]]);
            $pdo->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchColumn();
            out($r);
        case 'schemacheck':
            require_once "$app/web/inc/app/SchemaCheck.php";
            out(['problems' => SchemaCheck::problems($pdo)]);
    }
    out(['error' => "unbekannte Operation $op"]);
} catch (Throwable $e) {
    out(['error' => get_class($e) . ': ' . $e->getMessage()]);
}
