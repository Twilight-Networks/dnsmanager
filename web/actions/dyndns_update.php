<?php
/**
 * Datei: dyndns_update.php
 * Zweck: Bestehenden DynDNS-Account bearbeiten (Benutzername, Hostname, Zone, optional Passwort)
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt (Session-Gate; der Service prüft die Rolle laut Datenbank)
 * - CSRF-Schutz über `verify_csrf_token()`
 *
 * Web-Adapter: Eingaben lesen, DyndnsAccountService::updateAccount() aufrufen (Validierung, Zonensperren,
 * Kollisionsprüfung, Record-Anpassung, Rebuild in derselben Transaktion), ServiceMessages in die bisherigen
 * Toasts/Logs/Redirects übersetzen.
 */

declare(strict_types=1);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/dyndns/DyndnsAccountService.php';
require_once __DIR__ . '/../inc/deploy/BindZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
require_once __DIR__ . '/../inc/web/dyndns_messages.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$id       = (int)($_POST['id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$input = new DyndnsAccountInput(
    $username,
    trim($_POST['password'] ?? ''),
    // Hostname bewusst nicht trimmen: Leer- und Steuerzeichen werden von der Validierung abgelehnt
    is_string($_POST['hostname'] ?? null) ? $_POST['hostname'] : '',
    (int)($_POST['zone_id'] ?? 0)
);

$service = new DyndnsAccountService($pdo, new BindZoneBuildBackend($pdo));
$result = $service->updateAccount(actorFromSession(), $id, $input);

$redirectEdit = rtrim(BASE_URL, '/') . '/pages/dyndns.php?edit_id=' . $id;
$redirectList = rtrim(BASE_URL, '/') . '/pages/dyndns.php';
$redirect = $redirectList;

foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'dyndns.update.access_denied':
            logAccessDenied('dyndns_update', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert.');

        case 'dyndns.update.invalid_input':
            toastError($LANG['dyndns_error_invalid_input']);
            $redirect = $redirectEdit;
            break;

        case 'dyndns.update.too_short':
            // S30 (SP1): bestehender Text der Passwortänderung
            toastError(
                sprintf($LANG['error_password_too_short'], $ctx['minLength']),
                "DynDNS-Account-Änderung für ID {$id} abgelehnt: Passwort zu kurz."
            );
            $redirect = $redirectEdit;
            break;

        case 'dyndns.update.not_found':
            toastError($LANG['dyndns_error_not_found']);
            break;

        case 'dyndns.update.unchanged':
            toastSuccess($LANG['no_changes'], 'Der DynDNS-Account ist unverändert.');
            break;

        case 'dyndns.update.rejected':
            toastError(dyndnsToast($LANG, $ctx), $ctx['detail']);
            $redirect = $redirectEdit;
            break;

        case 'dyndns.update.duplicate_username':
            toastError(
                sprintf($LANG['dyndns_error_username_exists'], htmlspecialchars($ctx['username'])),
                "DynDNS-Update abgelehnt: Benutzername bereits vergeben: " . $ctx['detail']
            );
            $redirect = $redirectEdit;
            break;

        case 'dyndns.update.locked':
            toastError($LANG['dyndns_error_locked'], "DynDNS-Update: Lock-Timeout/Deadlock: " . $ctx['detail']);
            $redirect = $redirectEdit;
            break;

        case 'dyndns.update.failed':
            toastError(
                $LANG['dyndns_error_update'] . ' – ' . $LANG['dyndns_error_see_log'],
                "DynDNS-Update fehlgeschlagen: " . $ctx['class'] . ': ' . $ctx['detail']
            );
            break;

        case 'dyndns.update.warning':
            toastWarning(dyndnsWarningToast($LANG, $ctx), $ctx['detail']);
            break;

        case 'dyndns.update.success':
            toastSuccess(
                $LANG['dyndns_updated'],
                $ctx['rebuilt'] ? 'Änderungen gespeichert und betroffene Zonen rebuildet.' : 'Änderungen gespeichert – kein Zonen-Rebuild notwendig.'
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (dyndns_update)");
            } else {
                toastError($LANG['dyndns_error_update'] . ' – ' . $LANG['dyndns_error_see_log'], "Unbehandelte ServiceMessage '{$message->code}' (dyndns_update)");
            }
            break;
    }
}

header("Location: " . $redirect);
exit;
