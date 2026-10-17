<?php
/**
 * API-Endpunkt: /api/v1/zones/conf_check.php
 *
 * Zweck:
 * - Führt eine named-checkconf-Prüfung auf die BIND-Konfigurationsdateien durch
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token (Header: Authorization: Bearer <token>)
 * - Kein Datenbankzugriff
 * - Keine manipulativen Operationen, nur lesend
 */

require_once __DIR__ . '/../../../inc/init.php';
require_once __DIR__ . '/../../../config/api_config.php';
require_once __DIR__ . '/../../../inc/auth.php';
require_once __DIR__ . '/../../../inc/response.php';
require_once __DIR__ . '/../../../inc/bind_utils.php';

// === Konfiguration laden und Token prüfen ===
$config = require __DIR__ . '/../../../config/api_config.php';
$validTokens = loadApiTokens($config['token_file']);
requireValidToken($validTokens);

// === named-checkconf ausführen (Exit-Code-basiert) ===
$check = checkBindConfig();
$output = trim($check['output']);

// === Status ermitteln: Exit-Code != 0 = error; bei Exit-Code 0 ggf. warning ===
if (!$check['ok']) {
    $status = 'error';
} elseif (stripos($output, 'warning:') !== false) {
    $status = 'warning';
} else {
    $status = 'ok';
}

// === Antwort senden (HTTP 200 wie bisher; Status im JSON) ===
sendJsonResponse(200, [
    'status' => $status,
    'check_output' => $output,
    'exit_code' => $check['exit_code']
]);
