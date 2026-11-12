<?php
/**
 * Datei: dyndns_add.php
 * Zweck: DynDNS-Account hinzufügen
 *
 * Beschreibung:
 * Verarbeitet das Formular aus `dyndns_add_form.php` und legt einen neuen DynDNS-Account
 * mit zugehöriger Zone, Subdomain (Hostname) und initialem Passwort in der Datenbank an.
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt (Session-Gate; der Service prüft die Rolle laut Datenbank)
 * - CSRF-Schutz über `verify_csrf_token()`
 *
 * Web-Adapter: Eingaben lesen, DyndnsAccountService::createAccount() aufrufen (Validierung, Zonensperre,
 * Kollisionsprüfung, Anlage), ServiceMessages in die bisherigen Toasts/Logs/Redirects übersetzen.
 */

declare(strict_types=1);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/dyndns/DyndnsAccountService.php';
require_once __DIR__ . '/../inc/deploy/LegacyZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
require_once __DIR__ . '/../inc/web/dyndns_messages.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$username = trim($_POST['username'] ?? '');
$input = new DyndnsAccountInput(
    $username,
    trim($_POST['password'] ?? ''),
    // Hostname bewusst nicht trimmen: Leer- und Steuerzeichen werden von der Validierung abgelehnt
    is_string($_POST['hostname'] ?? null) ? $_POST['hostname'] : '',
    (int)($_POST['zone_id'] ?? 0)
);

$service = new DyndnsAccountService($pdo, new LegacyZoneBuildBackend($pdo));
$result = $service->createAccount(actorFromSession(), $input);

$redirect = rtrim(BASE_URL, '/') . '/pages/dyndns.php?add_new=1';

foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'dyndns.create.access_denied':
            logAccessDenied('dyndns_add', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert.');

        case 'dyndns.create.missing_fields':
            toastError($LANG['dyndns_error_missing_fields']);
            break;

        case 'dyndns.create.rejected':
            toastError(dyndnsToast($LANG, $ctx), $ctx['detail']);
            break;

        case 'dyndns.create.duplicate_username':
            toastError(
                sprintf($LANG['dyndns_error_username_exists'], htmlspecialchars($ctx['username'])),
                "DynDNS-Anlage abgelehnt: Benutzername bereits vergeben: " . $ctx['detail']
            );
            break;

        case 'dyndns.create.locked':
            toastError($LANG['dyndns_error_locked'], "DynDNS-Anlage: Lock-Timeout/Deadlock: " . $ctx['detail']);
            break;

        case 'dyndns.create.failed':
            toastError(
                $LANG['dyndns_error_db'] . ' – ' . $LANG['dyndns_error_see_log'],
                "DynDNS-Anlage fehlgeschlagen: " . $ctx['class'] . ': ' . $ctx['detail']
            );
            break;

        case 'dyndns.create.success':
            toastSuccess($LANG['dyndns_success_account_created']);
            $redirect = rtrim(BASE_URL, '/') . '/pages/dyndns.php';
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (dyndns_add)");
            } else {
                toastError($LANG['dyndns_error_db'] . ' – ' . $LANG['dyndns_error_see_log'], "Unbehandelte ServiceMessage '{$message->code}' (dyndns_add)");
            }
            break;
    }
}

header("Location: " . $redirect);
exit;
