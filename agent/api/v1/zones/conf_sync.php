<?php
/**
 * API-Endpunkt: /api/v1/zones/conf_sync.php
 *
 * Zweck:
 * - Empfängt eine zonenspezifische .conf-Datei als base64-kodierten Inhalt
 * - Speichert die Datei unter /etc/bind/zones/conf/
 * - Rekonstruiert die zentrale /etc/bind/zones/zones.conf (atomar)
 * - Entfernt KEINE anderen .conf-Dateien (Orphan-Cleanup erfolgt ausschließlich über zone_cleanup.php)
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

// === Gemeinsamen Agent-Lock erwerben (identisch zu zone_sync.php, blockierend) ===
// Serialisiert zone_sync und conf_sync gegenseitig: alle Änderungen an verwalteten BIND-Dateien
// und der BIND-Reload erfolgen unter diesem Lock. Erst nach reloadBind() freigeben.
$lockPath = '/tmp/dnsmanager_zone_sync.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für die Konfigurationsübernahme']);
}

// Lock freigeben (vor jedem sendJsonResponse nach Lock-Erwerb; mehrfacher Aufruf unschädlich)
$releaseLock = function () use (&$lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    $lockHandle = null;
};

// === Zielverzeichnis sicherstellen ===
ensureDirectory($confDir);

// === Neue .conf-Datei schreiben ===
if (!writeTextFile($confPath, $confData)) {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben der .conf-Datei']);
}

// === Neue zones.conf generieren ===
$includes = [];
foreach (glob("$confDir/*.conf") as $file) {
    if (is_file($file)) {
        $includes[] = 'include "' . realpath($file) . '";';
    }
}

$zonesConfContent = implode("\n", $includes) . "\n";

// zones.conf atomar ersetzen: vollständig in eine eindeutige Temp-Datei im selben Verzeichnis
// schreiben (gleiches Dateisystem) und erst danach per rename() übernehmen.
try {
    $zonesConfTmpPath = $zonesConfPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
} catch (Throwable $e) {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erzeugen des temporären Dateinamens für zones.conf']);
}

if (file_put_contents($zonesConfTmpPath, $zonesConfContent) !== strlen($zonesConfContent)) {
    @unlink($zonesConfTmpPath);
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben von zones.conf']);
}

// Bisherige Zugriffsrechte von zones.conf übernehmen (best effort; Owner/Gruppe bleiben die des Agenten)
if (is_file($zonesConfPath)) {
    $existingPerms = @fileperms($zonesConfPath);
    if ($existingPerms !== false) {
        @chmod($zonesConfTmpPath, $existingPerms & 0777);
    }
}

if (!rename($zonesConfTmpPath, $zonesConfPath)) {
    @unlink($zonesConfTmpPath);
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben von zones.conf']);
}

// === BIND reload ===
$rndcOutput = reloadBind();

// === Lock freigeben (nach dem Reload) ===
$releaseLock();

// === Antwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'message' => 'Zonen-Konfiguration übernommen, zones.conf neu geschrieben.',
    'rndc' => trim($rndcOutput)
]);
