<?php
/**
 * Datei: tests/agent/as2b_stage.php
 * Zweck: Stufentest AS2b über den INSTALLIERTEN Core (Testsystem ns1). Ruft PublishService wie die Web-Adapter auf
 *        (LegacyBindPublishBackend, Advisory-Lock, Journal) bzw. misst einzelne Agent-Aufrufe mit denselben
 *        cURL-Einstellungen wie BindAgentClient (Verbindungs-Timeout 5 s, Gesamt-Timeout 30 s, Agent-CA) und gibt die
 *        Teilzeiten aus (Namensauflösung, Verbindung, TLS, erstes Byte, gesamt). Tokens werden nie ausgegeben.
 *        Ändert nichts am Core; Ausgabe als JSON-Zeilen.
 *
 * Aufruf (als www-data auf ns1, Arbeitsverzeichnis beliebig):
 *   php as2b_stage.php <Core-Wurzel> publish-all
 *   php as2b_stage.php <Core-Wurzel> publish-zone <Zonenname> [Sperr-Wartezeit s]
 *   php as2b_stage.php <Core-Wurzel> conf-sync <Servername> <Zonenname>
 *   php as2b_stage.php <Core-Wurzel> cleanup <Servername>
 */

[$_, $root, $cmd] = $argv + [null, null, null];
chdir($root . '/web/scripts');
require_once $root . '/web/inc/helpers_cli.php';
require_once $root . '/web/config/ui_config.php';
require_once $root . '/web/inc/db.php';
require_once $root . '/web/inc/logging.php';
require_once $root . '/web/inc/app/Actor.php';
require_once $root . '/web/inc/app/publish/PublishService.php';
require_once $root . '/web/inc/deploy/LegacyBindPublishBackend.php';
require_once $root . '/web/inc/deploy/BindAgentClient.php';
require_once $root . '/web/inc/deploy/bind_file_generator.php';

$pdo = $GLOBALS['pdo'];
$out = static function (array $row): void {
    echo json_encode(['t' => date('H:i:s')] + $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
};
$service = static fn (): PublishService => new PublishService($pdo, new LegacyBindPublishBackend($pdo), static function (string $l, string $m): void {
});
$resultOf = static function (ServiceResult $r, string $code): array {
    foreach ($r->messages as $m) {
        if ($m->code === $code) {
            return $m->context;
        }
    }
    return [];
};
$server = static function (string $name) use ($pdo): array {
    $st = $pdo->prepare('SELECT id, name, api_ip, api_token FROM servers WHERE name = ? AND active = 1');
    $st->execute([$name]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) {
        fwrite(STDERR, "Server nicht gefunden\n");
        exit(2);
    }
    return $s;
};
/** Agent-Aufruf wie BindAgentClient::request(), zusätzlich mit cURL-Teilzeiten */
$call = static function (array $srv, string $endpoint, array $payload): array {
    $client = BindAgentClient::fromConfig();
    $ch = curl_init($client->url($srv['api_ip'], $endpoint));
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $srv['api_token'], 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFYPEER,
        CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFYHOST,
        CURLOPT_TIMEOUT => BindAgentClient::SYNC_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => BindAgentClient::SYNC_CONNECT_TIMEOUT,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
    ];
    if (defined('AGENT_TLS_CA_FILE') && (bool)CURL_SSL_VERIFYPEER) {
        $opt[CURLOPT_CAINFO] = (string)AGENT_TLS_CA_FILE;
        $opt[CURLOPT_CAPATH] = null;
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $i = curl_getinfo($ch);
    // Zeiten in ms ab Beginn (PHP 8.5/curl 8: TLS nur als appconnect_time_us); TLS-Dauer = appconnect − connect
    $ms = static fn (string $k): float => round(((int)($i[$k . '_us'] ?? 0)) / 1000, 1);
    $r = ['http' => (int)$i['http_code'], 'curl_errno' => curl_errno($ch), 'curl_error' => curl_error($ch),
        'connect_ms' => $ms('connect_time'), 'tls_done_ms' => $ms('appconnect_time'),
        'tls_ms' => round($ms('appconnect_time') - $ms('connect_time'), 1), 'pretransfer_ms' => $ms('pretransfer_time'),
        'first_byte_ms' => $ms('starttransfer_time'), 'total_ms' => $ms('total_time'),
        'upload_bytes' => (int)$i['size_upload'], 'download_bytes' => (int)$i['size_download']];
    $json = is_string($body) ? json_decode($body, true) : null;
    if (is_array($json)) {
        $r['status'] = $json['status'] ?? null;
        $r['code'] = $json['code'] ?? null;
        $r['effect'] = $json['effect'] ?? null;
        $r['verification'] = $json['verification'] ?? null;
        if (isset($json['zones'])) {
            $r['zones'] = $json['zones'];
        }
        $r['classified'] = PublishTargetClassifier::classify(str_contains($endpoint, 'cleanup') ? PublishTargetClassifier::KIND_CLEANUP
            : PublishTargetClassifier::KIND_CONF, ['body' => $body, 'httpCode' => (int)$i['http_code'], 'curlError' => curl_error($ch), 'curlErrno' => curl_errno($ch)])['status'];
    }
    return $r;
};

switch ($cmd) {
    case 'publish-all':
        $st = $pdo->query("SELECT id, username, role FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
        $t0 = microtime(true);
        $r = $service()->publishAll(new Actor((int)$st['id'], (string)$st['username'], (string)$st['role'], null, 'cli'));
        $res = $resultOf($r, 'publish.result');
        $out(['cmd' => 'publish-all', 'seconds' => round(microtime(true) - $t0, 2), 'status' => $res['status'] ?? null,
            'runId' => $res['runId'] ?? null, 'zones' => array_map(static fn ($z) => [$z['name'], $z['outcome']], $res['zones'] ?? []),
            'cleanup' => $res['cleanup'] ?? null, 'warnings' => $res['warnings'] ?? [], 'errors' => $res['errors'] ?? []]);
        break;
    case 'publish-zone':
        $st = $pdo->prepare('SELECT id FROM zones WHERE name = ?');
        $st->execute([$argv[3]]);
        $t0 = microtime(true);
        $r = $service()->publishZone((int)$st->fetchColumn(), (int)($argv[4] ?? 5), HistoryContext::system('publish.zone', 'cli'));
        $res = $resultOf($r, 'publish.zone_result');
        $out(['cmd' => 'publish-zone', 'zone' => $argv[3], 'seconds' => round(microtime(true) - $t0, 2), 'status' => $res['status'] ?? null,
            'runId' => $res['runId'] ?? null, 'errors' => $res['errors'] ?? [], 'warnings' => $res['warnings'] ?? []]);
        break;
    case 'conf-sync':
        $srv = $server($argv[3]);
        $tmp = sys_get_temp_dir() . '/as2b-stage-conf-' . getmypid();
        generate_zone_conf_file($pdo, $tmp, $argv[4], (int)$srv['id']);
        $conf = (string)file_get_contents("$tmp/conf/{$argv[4]}.conf");
        exec('rm -rf ' . escapeshellarg($tmp));
        $out(['cmd' => 'conf-sync', 'server' => $srv['name'], 'zone' => $argv[4]]
            + $call($srv, '/zones/conf_sync.php', ['zone_name' => $argv[4], 'conf_data' => base64_encode($conf)]));
        break;
    case 'cleanup':
        $srv = $server($argv[3]);
        $st = $pdo->prepare('SELECT z.name FROM zones z JOIN zone_servers zs ON z.id = zs.zone_id WHERE zs.server_id = ?');
        $st->execute([$srv['id']]);
        $valid = $st->fetchAll(PDO::FETCH_COLUMN);
        $out(['cmd' => 'cleanup', 'server' => $srv['name'], 'valid' => count($valid)]
            + $call($srv, '/zones/zone_cleanup.php', ['valid_zones' => array_values($valid), 'allow_empty' => $valid === []]));
        break;
    default:
        fwrite(STDERR, "Aufruf: as2b_stage.php <Core-Wurzel> publish-all|publish-zone|conf-sync|cleanup …\n");
        exit(2);
}
