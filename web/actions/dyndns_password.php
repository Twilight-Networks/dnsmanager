<?php
/**
 * Datei: actions/dyndns_password.php
 * Zweck: Passwort eines bestehenden DynDNS-Accounts ändern
 *
 * Beschreibung:
 * Diese Datei verarbeitet POST-Anfragen zur Passwortänderung eines DynDNS-Accounts.
 * Sie wird typischerweise aus einem Modal heraus aufgerufen, das in `dyndns.php` definiert ist.
 *
 * Sicherheitsmaßnahmen:
 * - Nur Admins (bei fehlender Berechtigung Toast und Rückleitung wie bisher)
 * - CSRF-Schutz
 * - Passwort wird nur in gehashter Form gespeichert
 *
 * Web-Adapter: DyndnsAccountService::changePassword() aufrufen (Mindestlänge, Hashing, Speicherung),
 * ServiceMessages in die bisherigen Toasts/Logs übersetzen.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/dyndns/DyndnsAccountService.php';
require_once __DIR__ . '/../inc/deploy/LegacyZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    toastError(
        $LANG['dyndns_error_unauthorized'],
        "Nicht-Admin versucht DynDNS-Passwort zu ändern."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/dyndns.php");
    exit;
}

$account_id = (int)($_POST['id'] ?? 0);
$password = trim($_POST['password'] ?? '');

$service = new DyndnsAccountService($pdo, new LegacyZoneBuildBackend($pdo));
$result = $service->changePassword(actorFromSession(), $account_id, $password);

foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'dyndns.password.access_denied':
            // Wie bisher bei fehlender Berechtigung: Toast und Rückleitung (kein 403)
            toastError(
                $LANG['dyndns_error_unauthorized'],
                "Nicht-Admin versucht DynDNS-Passwort zu ändern (Benutzer-ID {$ctx['actorId']} laut Datenbank kein aktiver Administrator)."
            );
            break;

        case 'dyndns.password.too_short':
            toastError(
                sprintf($LANG['error_password_too_short'], $ctx['minLength']),
                "Passwort-Änderung für DynDNS-ID {$account_id} abgelehnt: Passwort zu kurz."
            );
            break;

        case 'dyndns.password.hash_failed':
            toastError(
                $LANG['error_password_processing'],
                "Passwort-Hashing fehlgeschlagen für DynDNS-ID {$account_id}."
            );
            break;

        case 'dyndns.password.failed':
            toastError(
                $LANG['error_password_save'],
                "Fehler beim Ändern des DynDNS-Passworts für ID {$account_id}: " . $ctx['detail']
            );
            break;

        case 'dyndns.password.success':
            toastSuccess(
                $LANG['password_changed'],
                "DynDNS-Passwort aktualisiert für ID {$account_id}."
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (dyndns_password)");
            } else {
                toastError($LANG['error_password_save'], "Unbehandelte ServiceMessage '{$message->code}' (dyndns_password)");
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/dyndns.php");
exit;
