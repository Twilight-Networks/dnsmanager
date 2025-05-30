<?php
/**
 * Datei: inc/init.php
 * Zweck: Zentrale Initialisierung für alle API-Endpunkte
 *
 * - Setzt den HTTP-Antwort-Header auf JSON + UTF-8
 * - (Optional) Aktiviert Logging, Error-Handling oder andere globale Mechanismen
 */
$config = require __DIR__ . '/../config/api_config.php';

// UTF-8 erzwingen
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Optional: Fehlerberichterstattung
// error_reporting(E_ALL);
// ini_set('display_errors', 0);

// IP-Zugriffsfilter (Whitelist)
if (!empty($config['api_allowed_ips']) && is_array($config['api_allowed_ips'])) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, $config['api_allowed_ips'], true)) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Zugriff verweigert: IP nicht erlaubt'
        ]);
        exit;
    }
}
