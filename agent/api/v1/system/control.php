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
    $output = reloadBind();

    if (stripos($output, 'reload successful') !== false) {
        sendJsonResponse(200, ['status' => 'ok', 'message' => $output]);
    } else {
        sendJsonResponse(500, ['status' => 'error', 'message' => $output]);
    }
}

sendJsonResponse(400, ['status' => 'error', 'message' => 'Unbekannte Aktion']);
