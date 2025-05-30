<?php
/**
 * API-Endpunkt: /api/v1/system/status.php
 *
 * Zweck:
 * - Gesundheitsprüfung des DNS-Servers
 * - Prüft, ob BIND läuft, konfiguriert ist und korrekt antwortet
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token
 * - Kein Datenbankzugriff, reine Systemdiagnose
 */

require_once __DIR__ . '/../../../inc/init.php';
require_once __DIR__ . '/../../../config/api_config.php';
require_once __DIR__ . '/../../../inc/auth.php';
require_once __DIR__ . '/../../../inc/bind_utils.php';
require_once __DIR__ . '/../../../inc/response.php';

// === Konfiguration laden und Token prüfen ===
$config = require __DIR__ . '/../../../config/api_config.php';
$validTokens = loadApiTokens($config['token_file']);
requireValidToken($validTokens);

// === Systeminformationen ===
$hostname = gethostname();
$uptimeSeconds = @file_get_contents('/proc/uptime');
$uptime = $uptimeSeconds ? (int)explode(' ', $uptimeSeconds)[0] : null;
$load = sys_getloadavg();

// === BIND-Status prüfen ===
$bindPid = shell_exec('pgrep named');
$bindRunning = !empty(trim($bindPid));

$rndcStatus = shell_exec('sudo rndc status 2>&1');
$rndcOk = !str_contains($rndcStatus, 'not running');

// === DNS-Testabfrage via dig (localhost) ===
$digResult = shell_exec('dig +short @127.0.0.1 localhost A');
$dnsQueryOk = trim($digResult) !== '';

// === Ergebnis aufbauen ===
$bindStatus = [
    'named_running' => $bindRunning,
    'rndc_status' => trim($rndcStatus),
    'dns_query_localhost_ok' => $dnsQueryOk
];

$response = [
    'status' => 'ok',
    'hostname' => $hostname,
    'uptime_seconds' => $uptime,
    'load_average' => [
        '1min' => $load[0],
        '5min' => $load[1],
        '15min' => $load[2]
    ],
    'php_version' => PHP_VERSION,
    'bind' => $bindStatus
];

// === Fehlerstatus erkennen ===
if (!$bindRunning || !$rndcOk || !$dnsQueryOk) {
    $response['status'] = 'error';
    $response['message'] = 'BIND-Status kritisch oder nicht vollständig funktionsfähig';
    http_response_code(503);
} else {
    http_response_code(200);
}

sendJsonResponse(http_response_code(), $response);
