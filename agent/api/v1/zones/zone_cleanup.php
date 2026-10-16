<?php
/**
 * API-Endpunkt: /api/v1/zones/zone_cleanup.php
 *
 * Zweck:
 * - Gleicht die vom DNS-Manager verwalteten Zonen dieses Servers mit dem Sollzustand ab
 *   (valid_zones = vollständige Liste der diesem Server in der Datenbank zugewiesenen Zonen)
 * - Verwaist ist eine vorhandene zone_conf_dir/<safe-zone-name>.conf, deren Basename nicht in valid_zones steht
 * - Entfernt verwaiste .conf-Dateien und je verwaister .conf ausschließlich die exakt gepaarte
 *   Zonendatei zone_data_dir/db.<safe-zone-name> (keine sonstigen db.*, keine .jnl, keine fremden Dateien)
 * - Ersetzt vorher zones.conf atomar (ohne die verwaisten Includes) und führt danach genau einen BIND-Reload aus
 * - Ohne verwaiste .conf: keine Änderung, kein Reload (No-op)
 *
 * Request (JSON):
 * - valid_zones: Pflicht, Array nicht-leerer Strings
 * - allow_empty: Eine leere valid_zones-Liste ist nur mit allow_empty === true (JSON-Boolean) zulässig
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token
 * - Kein Datenbankzugriff erforderlich
 * - Serialisiert mit zone_sync.php und conf_sync.php über denselben Agent-Lock
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
if (!is_array($input) || !array_key_exists('valid_zones', $input) || !is_array($input['valid_zones'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültige Eingabeparameter: valid_zones (Array) erforderlich']);
}

// Sollzonen als safeZoneName-Menge (Duplikate werden dabei zusammengefasst)
$validSafeNames = [];
foreach ($input['valid_zones'] as $zone) {
    if (!is_string($zone) || trim($zone) === '') {
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültiger Eintrag in valid_zones: nicht-leerer String erwartet']);
    }
    $validSafeNames[safeZoneName($zone)] = true;
}

// Leere Liste ist potentiell destruktiv: nur mit explizitem allow_empty === true
$allowEmpty = array_key_exists('allow_empty', $input) && $input['allow_empty'] === true;
if (empty($validSafeNames) && !$allowEmpty) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Leere valid_zones-Liste nur mit allow_empty=true zulässig']);
}

// === Pfade ===
$confDir = $config['zone_conf_dir'];
$zoneDir = $config['zone_data_dir'];
$zonesConfPath = $config['zones_conf_file'];

// === Gemeinsamen Agent-Lock erwerben (identisch zu zone_sync.php/conf_sync.php, blockierend) ===
// Scan, zones.conf-Ersatz, Löschungen und Reload erfolgen vollständig unter diesem Lock.
$lockPath = '/tmp/dnsmanager_zone_sync.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für den Zonen-Cleanup']);
}

// Lock freigeben (vor jedem sendJsonResponse nach Lock-Erwerb; mehrfacher Aufruf unschädlich)
$releaseLock = function () use (&$lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    $lockHandle = null;
};

// === Verwaltete .conf-Dateien scannen ===
$confFiles = glob("$confDir/*.conf");
if ($confFiles === false) {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Lesen des Konfigurationsverzeichnisses']);
}

$keepConfFiles = [];   // bleiben in zones.conf (Reihenfolge wie glob, analog conf_sync.php)
$orphanConfFiles = []; // safe-zone-name => Pfad der verwaisten .conf
foreach ($confFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $base = basename($file, '.conf');
    // Nur eindeutig verwaltete Namen (<safe-zone-name>.conf) können verwaist sein
    if ($base !== '' && $base === safeZoneName($base) && !isset($validSafeNames[$base])) {
        $orphanConfFiles[$base] = $file;
    } else {
        $keepConfFiles[] = $file;
    }
}

// === No-op: keine verwaisten .conf ===
if (empty($orphanConfFiles)) {
    $releaseLock();
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'Keine verwaisten Zonen gefunden, keine Änderung.',
        'removed_conf' => [],
        'removed_zone_files' => [],
        'rndc' => ''
    ]);
}

// === A/B: neue zones.conf ohne verwaiste Includes im Speicher erzeugen ===
$includes = [];
foreach ($keepConfFiles as $file) {
    $includes[] = 'include "' . realpath($file) . '";';
}
$zonesConfContent = implode("\n", $includes) . "\n";

// === C: zones.conf atomar ersetzen (Temp-Datei im selben Verzeichnis + rename) ===
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

// === D: erst jetzt pro Orphan zuerst die exakt gepaarte db.<zone>, danach die verwaiste .conf entfernen ===
// Reihenfolge db vor .conf: Die verwaiste .conf ist der einzige Orphan-Marker (db.* wird bewusst nicht
// gescannt). Scheitert das Löschen der db-Datei, bleibt die .conf bestehen, damit der nächste Cleanup
// denselben Orphan erneut erkennt und erneut versucht.
$removedConf = [];
$removedZoneFiles = [];
$errors = [];
foreach ($orphanConfFiles as $base => $confFile) {
    $zoneFile = "$zoneDir/db.$base";
    if (file_exists($zoneFile)) {
        if (!@unlink($zoneFile) && file_exists($zoneFile)) {
            // .conf als Orphan-Marker behalten → Retry beim nächsten Cleanup
            $errors[] = "Verwaiste Zonendatei konnte nicht entfernt werden: " . basename($zoneFile);
            continue;
        }
        $removedZoneFiles[] = basename($zoneFile);
    }

    if (!@unlink($confFile) && file_exists($confFile)) {
        // Bereits entfernte db-Datei wird nicht wiederhergestellt
        $errors[] = "Verwaiste Konfigurationsdatei konnte nicht entfernt werden: " . basename($confFile);
        continue;
    }
    $removedConf[] = basename($confFile);
}

// === E: genau ein BIND-Reload (zones.conf includiert die Orphans bereits nicht mehr) ===
$rndcOutput = reloadBind();

// === F: Lock freigeben ===
$releaseLock();

if (!empty($errors)) {
    sendJsonResponse(500, [
        'status' => 'error',
        'message' => 'Zonen-Cleanup unvollständig: nicht alle verwaisten Dateien konnten entfernt werden.',
        'errors' => $errors,
        'removed_conf' => $removedConf,
        'removed_zone_files' => $removedZoneFiles,
        'rndc' => trim($rndcOutput)
    ]);
}

sendJsonResponse(200, [
    'status' => 'success',
    'message' => 'Verwaiste Zonen entfernt, zones.conf neu geschrieben.',
    'removed_conf' => $removedConf,
    'removed_zone_files' => $removedZoneFiles,
    'rndc' => trim($rndcOutput)
]);
