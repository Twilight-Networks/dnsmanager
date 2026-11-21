<?php
/**
 * API-Endpunkt: /api/v1/zones/zone_verify.php
 * Zweck (AS4b, E109, E76): unabhängige L3-Beobachtung einer Zone für das Monitoring des Core – genau eine Runde der
 * AS2b-Nachverifikation (Datei-, Konfigurations- und Auslieferungsnachweis aus inc/dns_state.php), ohne Polling.
 * - Rein lesend: keine Datei, keine Konfiguration und kein Fencing-Höchststand wird verändert; kein Reload.
 * - Nur in observe/enforce (E101); im Modus off deaktiviert (409 verification_disabled), ein fehlender oder ungültiger
 *   fencing_mode wird wie überall fail-closed abgelehnt (E118). Ohne ext-posix 503 agent_requirement_missing (E100).
 * - Unter der GEMEINSAMEN Agent-Sperre (LOCK_SH, mit Frist): keine Beobachtung während einer laufenden Änderung.
 * - Antwort 200: status success, verification (dasselbe Objekt wie in conf_sync: state verified | serial_differs |
 *   unknown, reason, zone, file_serial, file_sha256, served_serial, …). verified belegt Serial-Gleichheit der
 *   verwalteten Datei, nicht Inhaltsgleichheit (E107).
 * Sicherheit: Bearer-Token wie alle Endpunkte; der Zonenname wird nur in DNS-Namensform akzeptiert (Argument externer
 * Programme ohne Shell).
 */

require_once __DIR__ . '/../../../inc/init.php';
require_once __DIR__ . '/../../../config/api_config.php';
require_once __DIR__ . '/../../../inc/auth.php';
require_once __DIR__ . '/../../../inc/file_utils.php';
require_once __DIR__ . '/../../../inc/fence.php';
require_once __DIR__ . '/../../../inc/dns_verify.php';
require_once __DIR__ . '/../../../inc/response.php';

// === Konfiguration laden und Token prüfen ===
$config = require __DIR__ . '/../../../config/api_config.php';
$validTokens = loadApiTokens($config['token_file']);
requireValidToken($validTokens);

// E118: fehlender oder ungültiger fencing_mode ⇒ früher Abbruch (fence_misconfigured)
agentRejectMisconfiguredMode($config);
if (fenceMode($config) === 'off') {
    sendJsonResponse(409, ['status' => 'error', 'message' => 'zone_verify ist im Modus off deaktiviert', 'code' => 'verification_disabled']);
}
if (!agentPosixAvailable()) {
    sendJsonResponse(503, ['status' => 'error', 'message' => 'Agent-Voraussetzung fehlt: PHP-Erweiterung posix', 'code' => 'agent_requirement_missing']);
}

// === Eingabe prüfen ===
$input = json_decode(file_get_contents('php://input'), true);
$zoneName = is_array($input) ? ($input['zone_name'] ?? null) : null;
if (!is_string($zoneName) || dnsNormName($zoneName) === null) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültiger Zonenname', 'code' => 'zone_name_invalid']);
}

// === Gemeinsame Sperre mit Frist (keine Beobachtung während einer Änderung) ===
$lock = agentLockWithDeadline(agentLockPath($config), 8.0, LOCK_SH);
if ($lock['handle'] === null) {
    if ($lock['reason'] === 'timeout') {
        sendJsonResponse(503, ['status' => 'error', 'message' => 'Agent-Sperre innerhalb der Frist nicht erhalten – keine Beobachtung', 'code' => 'lock_timeout']);
    }
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus', 'code' => 'lock_failed']);
}

$verification = agentVerifyOnce($config, $zoneName);
flock($lock['handle'], LOCK_UN);
fclose($lock['handle']);

sendJsonResponse(200, [
    'status' => 'success',
    'message' => 'Beobachtung abgeschlossen',
    'verification' => $verification,
]);
