<?php
/**
 * Datei: actions/bind_reload.php
 * Zweck: Führt einen BIND-Reload via API für einen einzelnen Server aus.
 *
 * Details:
 * - Anfrage an API-Endpunkt `/system/control.php` mit der Aktion `reload-bind` (über ServerService und
 *   LegacyAgentServerControlBackend).
 * - Erfolgs- oder Fehlermeldung wird über Toast-Messages im Session-Kontext zurückgegeben.
 *
 * Sicherheit:
 * - Zugriff nur für Benutzer mit Rolle "admin" (Session-Gate; der Service prüft die Rolle laut Datenbank).
 * - Nur per POST mit gültigem CSRF-Token.
 * - Der Server wird per ID über POST bestimmt und gegen die Datenbank verifiziert.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/TopologyLock.php';
require_once __DIR__ . '/../inc/app/servers/ServerService.php';
require_once __DIR__ . '/../inc/deploy/BindZoneBuildBackend.php';
require_once __DIR__ . '/../inc/deploy/LegacyAgentServerControlBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Zustandsändernde Aktion: nur POST mit gültigem CSRF-Token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
verify_csrf_token();

requireRole(['admin']);

$serverId = (int)($_POST['id'] ?? 0);

$serverService = new ServerService($pdo, new BindZoneBuildBackend($pdo), new TopologyLock($pdo), new LegacyAgentServerControlBackend());
$result = $serverService->reloadBind(actorFromSession(), $serverId);

foreach ($result->messages as $message) {
    $ctx = $message->context;
    $name = htmlspecialchars($ctx['name'] ?? '');

    switch ($message->code) {
        case 'server.reload.access_denied':
            logAccessDenied('bind_reload', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        // === Fehler bei unbekanntem Server ===
        case 'server.reload.not_found':
            toastError(
                $LANG['error_server_not_found'],
                "Bind-Reload abgebrochen: Server-ID {$serverId} existiert nicht in der Datenbank."
            );
            break;

        case 'server.reload.success':
            toastSuccess(
                sprintf($LANG['bind_reload_success'], $name, htmlspecialchars($ctx['message'])),
                "BIND-Reload auf Server '{$name}' erfolgreich durchgeführt."
            );
            break;

        case 'server.reload.failed':
            toastError(
                sprintf($LANG['bind_reload_failed'], $name, htmlspecialchars($ctx['message'])),
                "BIND-Reload auf Server '{$name}' fehlgeschlagen. Antwort: {$ctx['message']}"
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (bind_reload)");
            } else {
                toastError(
                    $LANG['error_server_not_found'],
                    "Unbehandelte ServiceMessage '{$message->code}' (bind_reload)"
                );
            }
            break;
    }
}

// === Zurück zur Serverübersicht ===
header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
exit;
