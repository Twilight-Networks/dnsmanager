<?php
/**
 * Datei: actions/user_password.php
 * Zweck: Passwort eines Benutzers ändern.
 *
 * - Admins dürfen jedes Passwort ändern (nur 1x-Eingabe nötig)
 * - Benutzer dürfen ihr eigenes ändern (2x-Eingabe erforderlich)
 * - Passwörter werden per bcrypt sicher gehasht
 *
 * Web-Adapter: Eingaben lesen (trim wie bisher), UserService::changePassword() aufrufen,
 * ServiceMessages in Toasts/Logs übersetzen. Berechtigung (DB-Stand) und Transaktion liegen im Service.
 *
 * S26: Jede erfolgreiche Passwortänderung erhöht die Sitzungsgeneration des Kontos; alle Sessions dieses
 * Kontos enden. Bei eigener Änderung beendet der Adapter die eigene Session sofort (neue anonyme Session
 * mit frischer ID, Hinweis auf der Login-Seite). Ein Passwort-Reset durch einen Administrator für ein
 * anderes Konto lässt dessen eigene Session bestehen.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/users/UserService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

$user_id = (int)($_POST['id'] ?? 0);
$current_user_id = (int)$_SESSION['user_id'];

// Manipulierte Passwortfelder (z. B. Arrays) werden wie leere Eingaben behandelt: kein TypeError;
// Berechtigung (zuerst) und Passwortregeln prüft unverändert der UserService.
$password_raw = $_POST['password'] ?? '';
$password_repeat_raw = $_POST['password_repeat'] ?? '';
$password = is_string($password_raw) ? trim($password_raw) : '';
$password_repeat = is_string($password_repeat_raw) ? trim($password_repeat_raw) : '';

$userService = new UserService($pdo);
$result = $userService->changePassword(actorFromSession(), $user_id, $password, $password_repeat);

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'user.password.unauthorized':
            toastError(
                $LANG['error_password_unauthorized'],
                "Verbotener Passwort-Änderungsversuch für Benutzer-ID {$user_id} durch Benutzer-ID {$current_user_id}."
            );
            break;

        case 'user.password.access_denied':
            logAccessDenied('user_password', "Benutzer-ID {$current_user_id} ist laut Datenbank nicht (mehr) berechtigt, das Passwort von Benutzer-ID {$user_id} zu ändern.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'user.password.password_too_short':
            toastError(
                sprintf($LANG['error_password_too_short'], PASSWORD_MIN_LENGTH),
                "Passwort-Änderung abgelehnt: Passwort zu kurz für Benutzer-ID {$user_id}."
            );
            break;

        case 'user.password.mismatch':
            toastError(
                $LANG['error_password_mismatch'],
                "Passwort-Änderung durch Benutzer-ID {$user_id} abgebrochen: Eingaben nicht identisch."
            );
            break;

        case 'user.password.hash_failed':
            toastError(
                $LANG['error_password_processing'],
                "Passwort-Hashing für Benutzer-ID {$user_id} fehlgeschlagen."
            );
            break;

        case 'user.password.not_found':
            toastError(
                $LANG['user_error_not_found'],
                "Passwort-Änderung fehlgeschlagen: Benutzer-ID {$user_id} existiert nicht."
            );
            break;

        case 'user.password.success':
            if ($ctx['self']) {
                // Eigene Session beenden (Generation gilt nicht mehr); neue anonyme Session nur mit Hinweis
                appLog('info', "Passwort erfolgreich geändert für Benutzer-ID {$user_id} (eigenes Konto); Sitzungen des Kontos beendet, neue Anmeldung erforderlich.");
                $_SESSION = [];
                session_unset();
                session_destroy();
                session_start();
                session_regenerate_id(true);
                $_SESSION = ['password_changed_relogin' => true];
                header("Location: " . rtrim(BASE_URL, '/') . "/login.php");
                exit;
            }
            toastSuccess(
                $LANG['password_changed_admin'],
                "Passwort erfolgreich geändert für Benutzer-ID {$user_id} (durch Admin)"
            );
            break;

        case 'user.password.failed':
            toastError(
                $LANG['error_password_save'],
                "Fehler beim Speichern des Passworts für Benutzer-ID {$user_id}: " . $ctx['detail']
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (user_password)");
            } else {
                toastError(
                    $LANG['error_password_save'],
                    "Unbehandelte ServiceMessage '{$message->code}' (user_password)"
                );
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
exit;
