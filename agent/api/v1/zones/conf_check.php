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

// === named-checkconf ausführen ===
$output = trim(validateZoneConfFile());

// === Status ermitteln (robust) ===
$lower = strtolower($output);
$hasError = !empty($output) && (
    strpos($lower, 'unknown') !== false ||
    strpos($lower, 'unexpected') !== false ||
    strpos($lower, 'permission') !== false ||
    strpos($lower, 'failed') !== false ||
    preg_match('/:\d+:/', $output) // typische Form: /pfad/datei:zeile: fehlertext
);
$hasWarning = stripos($output, 'warning:') !== false;

$status = $hasError ? 'error' : ($hasWarning ? 'warning' : 'ok');

// === Antwort senden ===
sendJsonResponse(200, [
    'status' => $status,
    'check_output' => $output
]);
