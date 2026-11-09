<?php
/**
 * Datei: user_add.php
 * Zweck: Neuen Benutzer anlegen.
 *
 * Details:
 * - Nur Admins dürfen neue Benutzer erstellen.
 * - Der Benutzername wird geprüft, Passwort wird sicher gehasht.
 * - Benutzerrolle kann 'admin' oder 'zoneadmin' sein.
 * - Bei Zone-Admins können individuelle Zonen zugewiesen werden.
 * - Erfolgreiche Anlage wird als Session-Toast gemeldet.
 *
 * Web-Adapter: Eingaben lesen, UserService::createUser() aufrufen, ServiceMessages in
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

// Eingaben aus dem POST-Request lesen und trimmen
$username = trim($_POST['username']);
$password_raw = $_POST['password'];
$role = $_POST['role'] === 'admin' ? 'admin' : 'zoneadmin';
$zones = postStringList(isset($_POST['zones']) ? $_POST['zones'] : []);

// Ungültige Eingabestruktur (z. B. skalares "zones") ausdrücklich zurückweisen
if ($zones === null) {
    toastError(
        $LANG['user_error_create_failed'],
        "Benutzeranlage abgelehnt: ungültige Eingabestruktur für Zonen (keine Liste)."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// "all" = alle vorhandenen Zonen; übrige Werte als Zonen-IDs (Werte <= 0 ignoriert der Service)
$input = new UserInput(
    $username,
    $role,
    array_map('intval', array_values(array_filter($zones, static fn (string $z): bool => $z !== 'all'))),
    in_array('all', $zones, true)
);

$userService = new UserService($pdo);
$result = $userService->createUser(actorFromSession(), $input, $password_raw);

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'user.create.access_denied':
            logAccessDenied('user_add', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'user.create.invalid_username':
            toastError(
                $LANG['user_error_invalid_username'],
                "Benutzeranlage fehlgeschlagen: ungültiger Benutzername '{$username}'."
            );
            break;

        case 'user.create.password_too_short':
            toastError(
                sprintf($LANG['error_password_too_short'], PASSWORD_MIN_LENGTH),
                "Benutzeranlage fehlgeschlagen: Passwort für '{$username}' zu kurz."
            );
            break;

        case 'user.create.username_exists':
            toastError(
                $LANG['user_error_username_exists'],
                "Benutzeranlage fehlgeschlagen: Benutzername '{$username}' existiert bereits."
            );
            break;

        case 'user.create.success':
            toastSuccess(
                sprintf($LANG['user_created'], htmlspecialchars($username)),
                "Benutzer '{$username}' mit Rolle '{$role}' erfolgreich erstellt."
            );
            break;

        case 'user.create.failed':
            toastError(
                $LANG['user_error_create_failed'],
                "Transaktion fehlgeschlagen bei Benutzeranlage '{$username}': " . $ctx['detail']
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (user_add)");
            } else {
                toastError(
                    $LANG['user_error_create_failed'],
                    "Unbehandelte ServiceMessage '{$message->code}' (user_add)"
                );
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
exit;
?>
