<?php
/**
 * API-Endpunkt: /api/v1/system/control.php
 * Zweck: Steuerbefehle ausführen, z. B. BIND neu laden
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

// === Action ermitteln ===
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;

if ($action === 'reload-bind') {
    // Gemeinsamer Agent-Lock (wie zone_sync/conf_sync/zone_cleanup): Preflight und Reload unter demselben Lock
    $lockPath = '/tmp/dnsmanager_zone_sync.lock';
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für den BIND-Reload']);
    }

    // Verbindlicher named-checkconf-Preflight: bei Fehler kein Reload
    $preflight = checkBindConfig();
    if (!$preflight['ok']) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        sendJsonResponse(422, [
            'status' => 'error',
            'message' => 'named-checkconf fehlgeschlagen – kein Reload durchgeführt: ' . trim($preflight['output']),
            'check_output' => trim($preflight['output']),
            'exit_code' => $preflight['exit_code']
        ]);
    }

    $output = reloadBind();

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    if (stripos($output, 'reload successful') !== false) {
        sendJsonResponse(200, ['status' => 'ok', 'message' => $output]);
    } else {
        sendJsonResponse(500, ['status' => 'error', 'message' => $output]);
    }
}

sendJsonResponse(400, ['status' => 'error', 'message' => 'Unbekannte Aktion']);
