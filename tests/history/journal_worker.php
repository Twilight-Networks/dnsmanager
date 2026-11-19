<?php
/**
 * Datei: tests/history/journal_worker.php
 * Zweck: Eigener Prozess (eigene Datenbankverbindung) der Publish-Journal-Tests (AP1/H2d) für Nebenläufigkeit,
 *        Verbindungsabbruch und Prozessende. Nutzt die Sandbox der aufrufenden Suite (startet keine Agenten).
 *
 * Aufruf: php journal_worker.php <App> <DB> <RR|RC> <Sandbox> <Befehl> [Argumente]
 *   publish_all <Markerdatei>                  CONNECTION_ID() in die Markerdatei, dann Publish All
 *   publish_zone <Zone> <Markerdatei>           wie oben, Publish Zone
 *   hold_zone <Zone> <Sekunden> <Markerdatei>   Zone sperren, www-Record ohne Historie ändern, warten, Commit
 *   render <Zone> <Ausgabedatei>                generate_zone_file() im Modus publish (Byte-Vergleich mit Basis-Commit)
 * Ausgabe: JSON (letzte Zeile).
 */

[$app, $db, $iso, $sbx, $cmd] = array_slice($argv, 1, 5);
$args = array_slice($argv, 6);
if (!preg_match('/^dnsmgr_t21_[a-z0-9_]+$/D', (string)$db) || !str_starts_with((string)$sbx, sys_get_temp_dir() . '/dnsmgr-h2d-sbx.')) {
    fwrite(STDERR, "unzulässige Datenbank oder Sandbox\n");
    exit(2);
}
define('BIND_BASE_DIR', "$sbx/bindbase");
define('REMOTE_API_BASE', '/api/v1');
define('CURL_SSL_VERIFYPEER', false);
define('CURL_SSL_VERIFYHOST', 0);

$pdo = new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$db;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec($iso === 'RR' ? 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' : 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
if ($iso === 'RR') {
    $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');
}
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 30');

if ($cmd === 'render') {
    // Nur der Generator des angegebenen Commits (auch Basis-Commit ohne Journal)
    require_once "$app/web/inc/deploy/bind_file_generator.php";
    $dir = sys_get_temp_dir() . '/dnsmgr-h2d-render.' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $r = generate_zone_file((int)$args[0], $dir, 'publish', $pdo);
    $ok = ($r['status'] ?? '') === 'ok';
    if ($ok) {
        copy($r['path'], $args[1]);
    }
    exec('rm -rf ' . escapeshellarg($dir));
    echo json_encode(['ok' => $ok, 'output' => $r['output'] ?? '']), "\n";
    exit(0);
}

foreach (['Actor', 'ServiceMessage', 'ServiceResult'] as $c) {
    require_once "$app/web/inc/app/$c.php";
}
require_once "$app/web/inc/app/publish/PublishService.php";
require_once "$app/web/inc/deploy/LegacyBindPublishBackend.php";

$marker = static function (string $file) use ($pdo): void {
    file_put_contents("$file.tmp", (string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    rename("$file.tmp", $file);
};
$service = static function () use ($pdo): PublishService {
    return new PublishService($pdo, new LegacyBindPublishBackend($pdo, new BindAgentClient('/api/v1', false, 0)));
};
$last = static fn (ServiceResult $r): array => $r->messages[count($r->messages) - 1]->context;

try {
    switch ($cmd) {
        case 'publish_all':
            $marker($args[0]);
            $r = $service()->publishAll(new Actor(21, 'h2b_admin', 'admin', '192.0.2.99'));
            echo json_encode(['report' => $last($r)]), "\n";
            break;
        case 'publish_zone':
            $marker($args[1]);
            $r = $service()->publishZone((int)$args[0], 5);
            echo json_encode(['report' => $last($r)]), "\n";
            break;
        case 'hold_zone':
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM zones WHERE id = ? FOR UPDATE')->execute([(int)$args[0]]);
            $pdo->prepare("UPDATE records SET content = '192.0.2.99' WHERE zone_id = ? AND name = 'www'")->execute([(int)$args[0]]);
            $marker($args[2]);
            usleep((int)((float)$args[1] * 1e6));
            $pdo->commit();
            echo json_encode(['held' => true, 'at' => microtime(true)]), "\n";
            break;
        default:
            fwrite(STDERR, "unbekannter Befehl $cmd\n");
            exit(2);
    }
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
