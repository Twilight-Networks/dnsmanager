<?php
/**
 * Datei: user_delete.php
 * Zweck: Löscht einen Benutzer aus dem System.
 *
 * Details:
 * - Nur Admins dürfen Benutzer löschen.
 * - Es wird geprüft, ob der Benutzer existiert.
 * - Admin-Benutzer können nur gelöscht werden, wenn noch andere Admins existieren
 *   (unter Zeilensperre geprüft, auch bei parallelen Löschungen).
 * - Bei Löschung werden auch alle Zonen-Zuweisungen des Benutzers entfernt.
 *
 * Web-Adapter: UserService::deleteUser() aufrufen, ServiceMessages in Toasts/Logs übersetzen.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/users/UserService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    $userService = new UserService($pdo);
    $result = $userService->deleteUser(actorFromSession(), $id);

    // ServiceMessages in bestehende Toasts/Logs übersetzen
    foreach ($result->messages as $message) {
        $ctx = $message->context;

        switch ($message->code) {
            case 'user.delete.access_denied':
                logAccessDenied('user_delete', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein Administrator.");
                http_response_code(403);
                exit('Zugriff verweigert: Unzureichende Berechtigungen.');

            case 'user.delete.invalid_id':
                toastError(
                    $LANG['user_error_invalid_id'],
                    "Löschvorgang abgebrochen: Ungültige Benutzer-ID übergeben ({$id})."
                );
                break;

            case 'user.delete.not_found':
                toastError(
                    $LANG['user_error_not_found'],
                    "Benutzer-Delete fehlgeschlagen: Benutzer-ID {$id} nicht gefunden."
                );
                break;

            case 'user.delete.last_admin':
                toastError(
                    $LANG['user_error_last_admin'],
                    "Benutzer-ID {$id} war letzter Administrator – Löschung abgebrochen."
                );
                break;

            case 'user.delete.success':
                toastSuccess(
                    $LANG['user_deleted'],
                    "Benutzer-ID {$id} wurde erfolgreich entfernt, inklusive aller Zonen-Zuweisungen."
                );
                break;

            case 'user.delete.failed':
                toastError(
                    $LANG['user_error_delete'],
                    "Systemfehler beim Löschen von Benutzer-ID {$id}: " . $ctx['detail']
                );
                break;

            default:
                // Unbekannte ServiceMessages nie still verwerfen
                if ($message->kind === 'success') {
                    appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (user_delete)");
                } else {
                    toastError(
                        $LANG['user_error_delete'],
                        "Unbehandelte ServiceMessage '{$message->code}' (user_delete)"
                    );
                }
                break;
        }
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}
?>
