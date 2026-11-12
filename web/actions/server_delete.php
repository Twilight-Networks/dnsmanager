<?php
/**
 * Datei: server_delete.php
 * Zweck: Löscht einen DNS-Servereintrag aus dem System.
 *
 * Funktionen:
 * - Verhindert das Löschen, wenn der Server noch einer Zone zugewiesen ist (Master oder Slave).
 * - Löscht den Servereintrag samt Diagnosedaten.
 * - Gibt Rückmeldung über Toast-Meldungen bei Fehlern oder Erfolg.
 *
 * Web-Adapter: ServerService::deleteServer() aufrufen, ServiceMessages in die bisherigen Toasts/Logs
 * übersetzen. Prüfung der Zuweisung und Löschung erfolgen im Service auf dem gesperrten Stand.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/TopologyLock.php';
require_once __DIR__ . '/../inc/app/servers/ServerService.php';
require_once __DIR__ . '/../inc/deploy/LegacyZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Nur POST erlaubt');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

$serverService = new ServerService($pdo, new LegacyZoneBuildBackend($pdo), new TopologyLock($pdo));
$result = $serverService->deleteServer(actorFromSession(), $id);

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'server.delete.access_denied':
            logAccessDenied('server_delete', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'server.delete.invalid_id':
            toastError(
                $LANG['server_error_invalid_id'],
                "Ungültige Server-ID beim Löschversuch übergeben."
            );
            break;

        case 'server.delete.not_found':
            toastError(
                $LANG['error_server_not_found'],
                "Server mit ID {$id} konnte in der Datenbank nicht gefunden werden."
            );
            break;

        case 'server.delete.assigned':
            toastError(
                $LANG['server_error_delete_assigned'],
                "'{$ctx['name']}' (ID {$id}) ist noch mindestens einer Zone zugewiesen – Löschvorgang abgebrochen."
            );
            break;

        case 'server.delete.success':
            toastSuccess(
                $LANG['server_deleted'],
                "Server '{$ctx['name']}' (ID {$id}) erfolgreich gelöscht."
            );
            break;

        case 'server.delete.failed':
            toastError(
                $LANG['server_error_delete_failed'],
                "Datenbankfehler beim Löschen von Server '{$ctx['name']}' (ID {$id}): " . $ctx['detail']
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (server_delete)");
            } else {
                toastError(
                    $LANG['server_error_delete_failed'],
                    "Unbehandelte ServiceMessage '{$message->code}' (server_delete)"
                );
            }
            break;
    }
}

// Redirect (immer am Ende, nach Erfolg oder Fehler)
header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
exit;
