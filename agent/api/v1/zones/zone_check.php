<?php
/**
 * API-Endpunkt: /api/v1/zones/zone_check.php
 *
 * Zweck:
 * - Prüft, ob eine Zonendatei (db.<zone>) existiert
 * - Führt eine named-checkzone-Prüfung auf deren Syntax durch
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token (Header: Authorization: Bearer <token>)
 * - Kein Datenbankzugriff
 * - Nur lesender Zugriff auf Zonendateien im Filesystem
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

// === Eingabe prüfen ===
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['zone_name']) || !is_string($input['zone_name'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Fehlender oder ungültiger Parameter: zone_name']);
}

$zoneName = trim($input['zone_name']);
$safeName = safeZoneName($zoneName);
$zonePath = $config['zone_data_dir'] . "/db.$safeName";

// === Datei prüfen ===
if (!file_exists($zonePath)) {
    sendJsonResponse(404, ['status' => 'error', 'message' => "Zonendatei für $zoneName nicht vorhanden."]);
}

// === named-checkzone ausführen ===
$checkResult = validateZoneFile($zoneName, $zonePath);

// === Antwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'zone_name' => $zoneName,
    'check_output' => trim($checkResult)
]);
