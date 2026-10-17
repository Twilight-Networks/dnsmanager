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

// === Zustand vor der ersten Änderung sichern (für Rollback) ===
// Schlägt ein Backup-Lesevorgang fehl, wird ohne jede Änderung abgebrochen.
$confExisted = file_exists($confPath);
$oldConf = null;
$oldConfPerms = null;
if ($confExisted) {
    $oldConf = is_file($confPath) ? @file_get_contents($confPath) : false;
    if ($oldConf === false) {
        $releaseLock();
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Sicherung der bestehenden .conf-Datei fehlgeschlagen – keine Änderung vorgenommen']);
    }
    $perms = @fileperms($confPath);
    $oldConfPerms = $perms !== false ? ($perms & 0777) : null;
}

$zonesConfExisted = file_exists($zonesConfPath);
$oldZonesConf = null;
$oldZonesConfPerms = null;
if ($zonesConfExisted) {
    $oldZonesConf = is_file($zonesConfPath) ? @file_get_contents($zonesConfPath) : false;
    if ($oldZonesConf === false) {
        $releaseLock();
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Sicherung der bestehenden zones.conf fehlgeschlagen – keine Änderung vorgenommen']);
    }
    $perms = @fileperms($zonesConfPath);
    $oldZonesConfPerms = $perms !== false ? ($perms & 0777) : null;
}

// Rollback auf den gesicherten Zustand. Reihenfolge: zuerst zones.conf, danach <zone>.conf,
// damit zones.conf nie auf eine bereits entfernte .conf zeigt.
$rollback = function () use (
    $confPath, $confExisted, $oldConf, $oldConfPerms,
    $zonesConfPath, $zonesConfExisted, $oldZonesConf, $oldZonesConfPerms
): bool {
    $ok = true;

    // 1. zones.conf wiederherstellen (atomar) bzw. entfernen, falls sie vorher nicht existierte
    if ($zonesConfExisted) {
        if (!writeTextFileAtomic($zonesConfPath, $oldZonesConf, $oldZonesConfPerms)) {
            $ok = false;
        }
    } elseif (file_exists($zonesConfPath) && !@unlink($zonesConfPath)) {
        $ok = false;
    }

    // 2. <zone>.conf wiederherstellen bzw. entfernen, falls sie neu angelegt wurde
    if ($confExisted) {
        if (!writeTextFileAtomic($confPath, $oldConf, $oldConfPerms)) {
            $ok = false;
        }
    } elseif (file_exists($confPath)) {
        // Neue .conf nur entfernen, wenn zones.conf erfolgreich wiederhergestellt wurde;
        // sonst würde die (Kandidaten-)zones.conf auf eine fehlende Datei zeigen.
        if (!$ok || !@unlink($confPath)) {
            $ok = false;
        }
    }

    return $ok;
};

// Kontrollierter Fehler nach einer Live-Änderung: Rollback versuchen, kein Reload, Lock freigeben, antworten.
$failWithRollback = function (int $httpCode, string $message, array $extra = []) use ($rollback, $releaseLock): never {
    if (!$rollback()) {
        error_log("[conf_sync] ROLLBACK FEHLGESCHLAGEN nach: $message");
        $releaseLock();
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => "$message – Rollback fehlgeschlagen: manuelle Prüfung der BIND-Konfiguration erforderlich. Kein Reload durchgeführt.",
            'rollback' => 'failed'
        ] + $extra);
    }

    // Diagnose: Ist der wiederhergestellte vorherige Zustand gültig? (löst keinen Reload aus)
    $previous = checkBindConfig();
    $extra['rollback'] = 'ok';
    $extra['previous_config_valid'] = $previous['ok'];
    if (!$previous['ok']) {
        $extra['previous_check_output'] = trim($previous['output']);
        $message .= ' (Hinweis: auch der wiederhergestellte vorherige Zustand ist laut named-checkconf ungültig)';
        error_log("[conf_sync] Vorheriger Konfigurationszustand ist ebenfalls ungültig: " . trim($previous['output']));
    }

    $releaseLock();
    sendJsonResponse($httpCode, ['status' => 'error', 'message' => $message] + $extra);
};

// === Zielverzeichnis sicherstellen ===
ensureDirectory($confDir);

// === Neue .conf-Datei schreiben (atomar) ===
// Bei Fehler bleibt die Zieldatei unverändert (writeTextFileAtomic): keine Live-Änderung → kein Rollback, kein Reload.
if (!writeTextFileAtomic($confPath, $confData)) {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben der .conf-Datei – keine Änderung vorgenommen']);
}

// === Ab hier wurde eine Live-Datei verändert: jeder kontrollierte Fehler führt zum Rollback ===

// === Neue zones.conf generieren ===
$confFiles = glob("$confDir/*.conf");
if ($confFiles === false) {
    $failWithRollback(500, 'Fehler beim Lesen des Konfigurationsverzeichnisses');
}
$includes = [];
foreach ($confFiles as $file) {
    if (is_file($file)) {
        $includes[] = 'include "' . realpath($file) . '";';
    }
}

$zonesConfContent = implode("\n", $includes) . "\n";

// zones.conf atomar ersetzen (Temp-Datei im selben Verzeichnis + rename, bisherige Rechte best effort)
if (!writeTextFileAtomic($zonesConfPath, $zonesConfContent)) {
    $failWithRollback(500, 'Fehler beim Schreiben von zones.conf');
}

// === Verbindlicher named-checkconf-Preflight vor dem Reload ===
$preflight = checkBindConfig();
if (!$preflight['ok']) {
    $failWithRollback(422, 'BIND-Konfigurationsprüfung (named-checkconf) fehlgeschlagen – Änderung zurückgerollt, kein Reload', [
        'check_output' => trim($preflight['output']),
        'exit_code' => $preflight['exit_code']
    ]);
}

// === BIND reload (nur nach erfolgreichem Preflight) ===
$rndcOutput = reloadBind();

// === Lock freigeben (nach dem Reload) ===
$releaseLock();

// === Antwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'message' => 'Zonen-Konfiguration übernommen, zones.conf neu geschrieben.',
    'rndc' => trim($rndcOutput)
]);
