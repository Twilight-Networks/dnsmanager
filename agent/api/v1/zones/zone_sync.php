<?php
/**
 * API-Endpunkt: /api/v1/zones/zone_sync.php
 *
 * Zweck:
 * - Empfängt eine base64-kodierte Zonendatei (`db.zone`)
 * - Validiert sie mit named-checkzone
 * - Speichert sie unter /etc/bind/zones/
 * - Optional: Entfernt nicht mehr gültige db.*-Dateien
 * - Führt einen BIND-Reload durch
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token
 * - Kein Datenbankzugriff erforderlich
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

// === Eingabe prüfen ===
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['zone_id'], $input['zone_name'], $input['zone_data'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültige Eingabeparameter']);
}

$zoneName = $input['zone_name'];
$safeName = safeZoneName($zoneName);

$zoneData = base64_decode($input['zone_data']);
if ($zoneData === false) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Fehler beim Dekodieren der Zonendaten']);
}

// === Dateipfade vorbereiten ===
$zoneDir = $config['zone_data_dir'];
$tmpPath = "/tmp/db.$safeName.tmp";
$finalPath = "$zoneDir/db.$safeName";

// === Temporäre Datei schreiben ===
if (!writeTextFile($tmpPath, $zoneData)) {
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben der temporären Datei']);
}

// === named-checkzone auf temporäre Datei anwenden ===
$check_output = validateZoneFile($zoneName, $tmpPath);
if (!str_contains($check_output, 'loaded serial')) {
    @unlink($tmpPath);
    sendJsonResponse(422, [
        'status' => 'error',
        'message' => 'Zonenprüfung fehlgeschlagen',
        'check_output' => trim($check_output)
    ]);
}

// === Zonendatei übernehmen ===
if (!rename($tmpPath, $finalPath)) {
    sendJsonResponse(500, ['status' => 'error', 'message' => "Fehler beim Verschieben nach $finalPath"]);
}

// === Optional: veraltete Zonendateien entfernen ===
if (isset($input['valid_zones']) && is_array($input['valid_zones'])) {
    $validSafeZones = array_map('safeZoneName', $input['valid_zones']);
    foreach (glob("$zoneDir/db.*") as $file) {
        $zonePart = substr(basename($file), 3); // entfernt "db."
        $safe = safeZoneName($zonePart);
        if (!in_array($safe, $validSafeZones, true)) {
            @unlink($file);
        }
    }
}

// === BIND reload durchführen ===
$rndcOutput = reloadBind();

// === Erfolgsantwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'message' => "Zonendatei für $zoneName übernommen.",
    'rndc' => trim($rndcOutput)
]);
