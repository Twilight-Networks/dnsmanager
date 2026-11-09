<?php
/**
 * Datei: user_update.php
 * Zweck: Bestehenden Benutzer aktualisieren (Benutzername, Rolle und Zonen).
 *
 * Details:
 * - Nur Admins dürfen Benutzer bearbeiten.
 * - Benutzername wird geprüft, Rolle wird validiert.
 * - Bei Zone-Admins werden die zugewiesenen Zonen aktualisiert.
 * - Der letzte Administrator kann nicht herabgestuft werden.
 *
 * Web-Adapter: Eingaben lesen, UserService::updateUser() aufrufen, ServiceMessages in
 * Toasts/Logs übersetzen. Validierung, Berechtigung (DB-Rolle) und Transaktion liegen im Service.
 *
 * Zugriff:
 * - Nur Admins (requireRole(['admin'])); zusätzlich prüft der Service die Rolle in der Datenbank.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/users/UserService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Eingaben absichern
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$username = trim($_POST['username']);
$role = $_POST['role'];
$zones = postStringList(isset($_POST['zones']) ? $_POST['zones'] : []);

// Ungültige Eingabestruktur (z. B. skalares "zones") ausdrücklich zurückweisen
if ($zones === null) {
    toastError(
        $LANG['user_error_update_failed'],
        "Benutzer-Update abgelehnt: ungültige Eingabestruktur für Zonen (keine Liste, ID {$id})."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

$input = new UserInput(
    $username,
    is_string($role) ? $role : '',
    array_map('intval', $zones)
);

$userService = new UserService($pdo);
$result = $userService->updateUser(actorFromSession(), $id, $input);

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'user.update.access_denied':
            logAccessDenied('user_update', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'user.update.invalid_input':
            toastError(
                $LANG['user_error_invalid_id'],
                "Benutzer-Update fehlgeschlagen: Ungültige ID oder Rolle (ID {$id}, Rolle '{$ctx['role']}')."
            );
            break;

        case 'user.update.invalid_username':
            toastError(
                $LANG['user_error_invalid_username'],
                "Benutzer-Update fehlgeschlagen: Benutzername '{$username}' ist ungültig."
            );
            break;

        case 'user.update.not_found':
            toastError(
                $LANG['user_error_not_found'],
                "Update fehlgeschlagen: Benutzer-ID {$id} existiert nicht."
            );
            break;

        case 'user.update.unchanged':
            toastSuccess($LANG['no_changes'], "Benutzerdaten sind unverändert.");
            break;

        case 'user.update.last_admin':
            toastError(
                $LANG['user_error_last_admin_demote'],
                "Benutzer-ID {$id} ist letzter Administrator – Herabstufung abgebrochen."
            );
            break;

        case 'user.update.success':
            toastSuccess(
                sprintf($LANG['user_updated'], htmlspecialchars($username)),
                "Benutzer '{$username}' (ID {$id}) mit Rolle '{$ctx['role']}' erfolgreich geändert."
            );
            break;

        case 'user.update.failed':
            toastError(
                $LANG['user_error_update_failed'],
                "Fehler beim Speichern von Benutzer '{$username}' (ID {$id}): " . $ctx['detail']
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (user_update)");
            } else {
                toastError(
                    $LANG['user_error_update_failed'],
                    "Unbehandelte ServiceMessage '{$message->code}' (user_update)"
                );
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
exit;
?>
