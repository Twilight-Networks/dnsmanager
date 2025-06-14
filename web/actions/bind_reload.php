<?php
/**
 * Datei: actions/bind_reload.php
 * Zweck: Führt einen BIND-Reload via API für einen einzelnen Server aus.
 *
 * Details:
 * - Anfrage an API-Endpunkt `/system/control.php` mit der Aktion `reload-bind`.
 * - Erfolgs- oder Fehlermeldung wird über Toast-Messages im Session-Kontext zurückgegeben.
 *
 * Sicherheit:
 * - Zugriff nur für Benutzer mit Rolle "admin".
 * - Der Server wird per ID über POST bestimmt und gegen die Datenbank verifiziert.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/api_client.php';
requireRole(['admin']);

$serverId = (int)($_POST['id'] ?? 0);

// Serverdaten laden
$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

// === Fehler bei unbekanntem Server ===
if (!$server) {
    toastError(
        $LANG['error_server_not_found'],
        "Bind-Reload abgebrochen: Server-ID {$serverId} existiert nicht in der Datenbank."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;
}

$name = htmlspecialchars($server['name']);

// === Remote BIND-Reload via API ===
$url = "https://{$server['api_ip']}" . rtrim(REMOTE_API_BASE, '/') . '/system/control.php';
$token = $server['api_token'];
$response = apiPostJson($url, ['action' => 'reload-bind'], $token);

if ($response['status'] === 'ok') {
    toastSuccess(
        sprintf($LANG['bind_reload_success'], $name, htmlspecialchars($response['message'])),
        "BIND-Reload auf Server '{$name}' erfolgreich durchgeführt."
    );
} else {
    toastError(
        sprintf($LANG['bind_reload_failed'], $name, htmlspecialchars($response['message'])),
        "BIND-Reload auf Server '{$name}' fehlgeschlagen. Antwort: {$response['message']}"
    );
}

// === Zurück zur Serverübersicht ===
header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
exit;
