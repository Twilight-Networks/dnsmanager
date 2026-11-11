<?php
/**
 * Datei: user_set_active.php
 * Zweck: Deaktiviert oder reaktiviert ein Benutzerkonto (S27, PR B).
 *
 * Details:
 * - Nur Admins (Session-Rolle; die Datenbankrolle und der Kontostatus des Akteurs prüft der Service).
 * - Eingaben: id (Benutzer-ID), active ('0' = deaktivieren, '1' = reaktivieren; andere Werte werden abgelehnt).
 * - Das eigene Konto und der letzte aktive Admin können nicht deaktiviert werden (Prüfung im Service unter Sperre).
 * - Name, Rolle und Zonenzuweisungen bleiben erhalten; bestehende Sitzungen des Kontos enden über die
 *   Sitzungsgeneration (session_epoch) beim nächsten Aufruf.
 *
 * Web-Adapter: UserService::setActive() aufrufen, ServiceMessages in Toasts/Logs übersetzen.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/users/UserService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $activeRaw = $_POST['active'] ?? null;

    // Nur die beiden zulässigen Werte annehmen; alles andere ausdrücklich zurückweisen (kein Service-Aufruf)
    if ($activeRaw !== '0' && $activeRaw !== '1') {
        toastError(
            $LANG['user_error_status'],
            "Statusänderung abgelehnt: ungültiger Zielstatus für Benutzer-ID {$id}."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
        exit;
    }
    $active = $activeRaw === '1';

    $userService = new UserService($pdo);
    $result = $userService->setActive(actorFromSession(), $id, $active);

    // ServiceMessages in Toasts/Logs übersetzen
    foreach ($result->messages as $message) {
        $ctx = $message->context;
        $name = isset($ctx['username']) ? " ({$ctx['username']})" : '';

        switch ($message->code) {
            case 'user.disable.access_denied':
            case 'user.enable.access_denied':
                logAccessDenied('user_set_active', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
                http_response_code(403);
                exit('Zugriff verweigert: Unzureichende Berechtigungen.');

            case 'user.disable.invalid_id':
            case 'user.enable.invalid_id':
                toastError(
                    $LANG['user_error_invalid_id'],
                    "Statusänderung abgebrochen: Ungültige Benutzer-ID übergeben ({$id})."
                );
                break;

            case 'user.disable.not_found':
            case 'user.enable.not_found':
                toastError(
                    $LANG['user_error_not_found'],
                    "Statusänderung fehlgeschlagen: Benutzer-ID {$id} nicht gefunden."
                );
                break;

            case 'user.disable.self':
                toastError(
                    $LANG['user_error_disable_self'],
                    "Deaktivierung abgelehnt: Benutzer-ID {$id} ist das eigene Konto."
                );
                break;

            case 'user.disable.last_admin':
                toastError(
                    $LANG['user_error_last_admin_disable'],
                    "Benutzer-ID {$id}{$name} ist letzter aktiver Administrator – Deaktivierung abgebrochen."
                );
                break;

            case 'user.disable.unchanged':
            case 'user.enable.unchanged':
                toastSuccess(
                    $LANG['user_status_unchanged'],
                    "Statusänderung ohne Wirkung: Benutzer-ID {$id}{$name} war bereits " . ($active ? 'aktiv' : 'deaktiviert') . '.'
                );
                break;

            case 'user.disable.success':
                toastSuccess(
                    $LANG['user_disabled'],
                    "Benutzer-ID {$id}{$name} wurde deaktiviert; bestehende Sitzungen enden."
                );
                break;

            case 'user.enable.success':
                toastSuccess(
                    $LANG['user_enabled'],
                    "Benutzer-ID {$id}{$name} wurde reaktiviert."
                );
                break;

            case 'user.disable.failed':
            case 'user.enable.failed':
                toastError(
                    $LANG['user_error_status'],
                    "Systemfehler bei der Statusänderung von Benutzer-ID {$id}: " . $ctx['detail']
                );
                break;

            default:
                // Unbekannte ServiceMessages nie still verwerfen
                if ($message->kind === 'success') {
                    appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (user_set_active)");
                } else {
                    toastError(
                        $LANG['user_error_status'],
                        "Unbehandelte ServiceMessage '{$message->code}' (user_set_active)"
                    );
                }
                break;
        }
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}
?>
