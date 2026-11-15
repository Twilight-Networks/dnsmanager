<?php
/**
 * Datei: tests/ui/soa_worker.php
 * Zweck: Führt eine Server-/Zonenoperation der Testkopie in einem eigenen Prozess mit eigener Datenbankverbindung und
 *        gewählter Isolationsstufe aus (für die Nebenläufigkeitsfälle in soa_consistency.php) und gibt das Ergebnis
 *        als JSON aus: {"ok": bool, "codes": [...]}.
 * Aufruf: php soa_worker.php RR|RC rename <serverId> <neuerName>
 *         php soa_worker.php RR|RC adopt <zoneId>
 *         php soa_worker.php RR|RC master <zoneId> <masterId> <serverId,serverId,...>
 */
require __DIR__ . '/lib.php';
require APP . '/web/inc/logging.php';
require APP . '/web/inc/app/Actor.php';
require APP . '/web/inc/app/ZoneAccess.php';
require APP . '/web/inc/app/TopologyLock.php';
require APP . '/web/inc/app/zones/ZoneService.php';
require APP . '/web/inc/app/zones/ZoneReadService.php';
require APP . '/web/inc/app/servers/ServerService.php';
require APP . '/web/inc/deploy/BindZoneBuildBackend.php';
if (!defined('LOG_LEVEL')) { define('LOG_LEVEL', 'error'); }

[$_, $iso, $op] = $argv + [null, 'RR', ''];
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . ($iso === 'RC' ? 'READ COMMITTED' : 'REPEATABLE READ'));
$admin = new Actor(1, 'u0admin', 'admin', '127.0.0.1', 'cli');
$build = new BindZoneBuildBackend($pdo);

/** Admin-Eingabe einer Zone auf dem aktuellen Stand (wie das Bearbeitungsformular) */
function zoneInput(PDO $pdo, Actor $admin, int $zoneId, array $over = []): ZoneUpdateInput
{
    $z = (new ZoneReadService($pdo, new ZoneAccess($pdo)))->getZone($admin, $zoneId);
    $servers = array_map(fn($s) => (int)$s['id'], $z['servers']);
    $master = array_values(array_filter($z['servers'], fn($s) => $s['role'] === 'master'))[0]['id'] ?? null;
    return new ZoneUpdateInput(
        (string)$z['ttl'], $z['prefixLength'] === null ? null : (string)$z['prefixLength'], (string)($z['description'] ?? ''), (bool)$z['allowDyndns'],
        $z['soa']['mail'], (string)$z['soa']['refresh'], (string)$z['soa']['retry'], (string)$z['soa']['expire'], (string)$z['soa']['minimum'],
        $over['serverIds'] ?? $servers, $over['masterId'] ?? (int)$master, null, $z['editVersion'], $over['adopt'] ?? false
    );
}

$result = match ($op) {
    'rename' => (function () use ($pdo, $admin, $build, $argv) {
        $id = (int)$argv[3];
        $s = $pdo->query("SELECT name, dns_ip4, dns_ip6, api_ip, is_local, active FROM servers WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $in = new ServerInput((string)$argv[4], (string)$s['dns_ip4'], (string)($s['dns_ip6'] ?? ''), (string)$s['api_ip'], '', (bool)$s['is_local'], (bool)$s['active']);
        return (new ServerService($pdo, $build, new TopologyLock($pdo)))->updateServer($admin, $id, $in);
    })(),
    'adopt' => (new ZoneService($pdo, $build, new TopologyLock($pdo)))->updateZone($admin, (int)$argv[3], zoneInput($pdo, $admin, (int)$argv[3], ['adopt' => true])),
    'master' => (new ZoneService($pdo, $build, new TopologyLock($pdo)))->updateZone($admin, (int)$argv[3],
        zoneInput($pdo, $admin, (int)$argv[3], ['masterId' => (int)$argv[4], 'serverIds' => array_map('intval', explode(',', (string)$argv[5]))])),
    default => null,
};
if ($result === null) { fwrite(STDERR, "unbekannte Operation\n"); exit(2); }
echo json_encode(['ok' => $result->ok, 'codes' => array_map(fn($m) => $m->code, $result->messages)]), "\n";
