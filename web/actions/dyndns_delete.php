<?php
/**
 * Datei: dyndns_delete.php
 * Zweck: DynDNS-Account löschen (samt eindeutig zuordenbarer A/AAAA-Records)
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt (Session-Gate; der Service prüft die Rolle laut Datenbank)
 * - CSRF-Schutz über `verify_csrf_token()`
 *
 * Web-Adapter: DyndnsAccountService::deleteAccount() aufrufen (Zonensperre, Doppelbelegungsschutz,
 * konservative Record-Löschung, Rebuild in derselben Transaktion), ServiceMessages in die bisherigen
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

$id = (int)($_POST['id'] ?? 0);

$service = new DyndnsAccountService($pdo, new BindZoneBuildBackend($pdo));
$result = $service->deleteAccount(actorFromSession(), $id);

foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'dyndns.delete.access_denied':
            logAccessDenied('dyndns_delete', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert.');

        case 'dyndns.delete.invalid_id':
            toastError($LANG['dyndns_error_invalid_id']);
            break;

        case 'dyndns.delete.not_found':
            toastError($LANG['dyndns_error_not_found'], $ctx['detail']);
            break;

        case 'dyndns.delete.rejected':
            toastError(dyndnsToast($LANG, $ctx), $ctx['detail']);
            break;

        case 'dyndns.delete.locked':
            toastError($LANG['dyndns_error_locked'], "DynDNS-Löschung: Lock-Timeout/Deadlock: " . $ctx['detail']);
            break;

        case 'dyndns.delete.failed':
            toastError(
                $LANG['dyndns_error_delete'] . ' – ' . $LANG['dyndns_error_see_log'],
                "DynDNS-Löschung fehlgeschlagen: " . $ctx['class'] . ': ' . $ctx['detail']
            );
            break;

        case 'dyndns.delete.warning':
            toastWarning(dyndnsWarningToast($LANG, $ctx), $ctx['detail']);
            break;

        case 'dyndns.delete.success':
            toastSuccess($ctx['accountOnly'] ? $LANG['dyndns_delete_success_account_only'] : $LANG['dyndns_delete_success']);
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (dyndns_delete)");
            } else {
                toastError($LANG['dyndns_error_delete'] . ' – ' . $LANG['dyndns_error_see_log'], "Unbehandelte ServiceMessage '{$message->code}' (dyndns_delete)");
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
