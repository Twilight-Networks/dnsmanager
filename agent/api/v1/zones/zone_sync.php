<?php
/**
 * API-Endpunkt: /api/v1/zones/zone_sync.php
 *
 * Zweck:
 * - Empfängt eine base64-kodierte Zonendatei (`db.zone`)
 * - Validiert sie mit named-checkzone
 * - Speichert sie unter /etc/bind/zones/
 * - Führt einen BIND-Reload durch
 * - Entfernt KEINE anderen Zonendateien (Orphan-Cleanup erfolgt ausschließlich über zone_cleanup.php)
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
try {
    // Eindeutiger Temp-Name pro Request, damit parallele Requests sich nicht überschreiben
    $tmpPath = "/tmp/db.$safeName." . bin2hex(random_bytes(8)) . ".tmp";
} catch (Throwable $e) {
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erzeugen des temporären Dateinamens']);
}
$finalPath = "$zoneDir/db.$safeName";
$lockPath = '/tmp/dnsmanager_zone_sync.lock';

// === Temporäre Datei schreiben ===
if (!writeTextFile($tmpPath, $zoneData)) {
    @unlink($tmpPath);
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

// === Globalen exklusiven Lock erwerben (Übernahme und Reload serialisieren) ===
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    @unlink($tmpPath);
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für die Zonenübernahme']);
}

// === named-checkconf-Preflight (unter dem Lock, vor Übernahme und Reload) ===
$preflight = checkBindConfig();
if (!$preflight['ok']) {
    @unlink($tmpPath);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    sendJsonResponse(422, [
        'status' => 'error',
        'message' => 'BIND-Konfigurationsprüfung (named-checkconf) fehlgeschlagen – Zonendatei nicht übernommen, kein Reload',
        'check_output' => trim($preflight['output']),
        'exit_code' => $preflight['exit_code']
    ]);
}

// === Zonendatei übernehmen ===
if (!rename($tmpPath, $finalPath)) {
    @unlink($tmpPath);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    sendJsonResponse(500, ['status' => 'error', 'message' => "Fehler beim Verschieben nach $finalPath"]);
}

// === BIND reload durchführen ===
$rndcOutput = reloadBind();

// === Lock freigeben ===
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

// === Erfolgsantwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'message' => "Zonendatei für $zoneName übernommen.",
    'rndc' => trim($rndcOutput)
]);
