<?php
/**
 * API-Endpunkt: /api/v1/zones/conf_sync.php
 *
 * Zweck:
 * - Empfängt eine zonenspezifische .conf-Datei als base64-kodierten Inhalt
 * - Speichert die Datei unter /etc/bind/zones/conf/
 * - Entfernt optional veraltete .conf-Dateien
 * - Rekonstruiert die zentrale /etc/bind/zones/zones.conf
 * - Führt einen BIND-Reload via rndc durch
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token (Header: Authorization: Bearer <token>)
 * - Kein Datenbankzugriff erforderlich
 * - Nur serverseitige File- und BIND-Konfigurationsoperationen
 */

require_once __DIR__ . '/../../../inc/init.php';
require_once __DIR__ . '/../../../config/api_config.php';
require_once __DIR__ . '/../../../inc/auth.php';
require_once __DIR__ . '/../../../inc/bind_utils.php';
require_once __DIR__ . '/../../../inc/file_utils.php';
require_once __DIR__ . '/../../../inc/response.php';

// === Konfiguration laden und Token prüfen ===
$config = require __DIR__ . '/../../../config/api_config.php';
$validTokens = loadApiTokens($config['token_file']);
requireValidToken($validTokens);

// === Eingabe verarbeiten ===
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['zone_name'], $input['conf_data'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültige Eingabeparameter']);
}

$zoneName = $input['zone_name'];
$confData = base64_decode($input['conf_data']);
if ($confData === false) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Fehler beim Dekodieren der Zonendaten']);
}

$safeName = safeZoneName($zoneName);
$confDir = $config['zone_conf_dir'];
$confPath = "$confDir/$safeName.conf";
$zonesConfPath = $config['zones_conf_file'];

// === Zielverzeichnis sicherstellen ===
ensureDirectory($confDir);

// === Optional: veraltete .conf-Dateien entfernen ===
if (isset($input['valid_zones']) && is_array($input['valid_zones'])) {
    $validNames = array_map('safeZoneName', $input['valid_zones']);
    foreach (glob("$confDir/*.conf") as $file) {
        $basename = basename($file, '.conf');
        if (!in_array($basename, $validNames, true)) {
            @unlink($file);
        }
    }
}

// === Neue .conf-Datei schreiben ===
if (!writeTextFile($confPath, $confData)) {
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben der .conf-Datei']);
}

// === Neue zones.conf generieren ===
$includes = [];
foreach (glob("$confDir/*.conf") as $file) {
    if (is_file($file)) {
        $includes[] = 'include "' . realpath($file) . '";';
    }
}

if (!writeTextFile($zonesConfPath, implode("\n", $includes) . "\n")) {
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben von zones.conf']);
}

// === BIND reload ===
$rndcOutput = reloadBind();

// === Antwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'message' => 'Zonen-Konfiguration übernommen, zones.conf neu geschrieben.',
    'rndc' => trim($rndcOutput)
]);
