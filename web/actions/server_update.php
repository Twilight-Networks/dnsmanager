<?php
/**
 * Datei: server_update.php
 * Zweck: Aktualisiert einen bestehenden DNS-Servereintrag im System.
 *
 * Funktionen:
 * - Validiert die übermittelten Eingaben (Name, IP-Adressen, API-Token).
 * - Verhindert das Deaktivieren eines Servers, der als Master-Server in einer Zone verwendet wird.
 * - Stellt sicher, dass nur ein Server als "local" markiert ist.
 * - Bei Änderungen an Name oder IP-Adresse: führt Zonendatei-Rebuilds für alle betroffenen Zonen aus.
 * - Gibt Rückmeldung über Toast-Nachrichten bei Fehlern oder Erfolg.
 *
 * Web-Adapter: Eingaben lesen, ServerService::updateServer() aufrufen, ServiceMessages in die bisherigen
 * Toasts/Logs/Redirects übersetzen. Validierung, Berechtigung (DB-Rolle), Topologie-Lock, Transaktion und
 * NS/Glue-Neuaufbau liegen im Service.
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

$id   = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$input = new ServerInput(
    $name,
    trim($_POST['dns_ip4'] ?? ''),
    trim($_POST['dns_ip6'] ?? ''),
    trim($_POST['api_ip'] ?? ''),
    trim($_POST['api_token'] ?? ''),
    isset($_POST['is_local']),
    isset($_POST['active'])
);

$serverService = new ServerService($pdo, new LegacyZoneBuildBackend($pdo), new TopologyLock($pdo));
$result = $serverService->updateServer(actorFromSession(), $id, $input);

$lock = TopologyLock::NAME;

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'server.update.access_denied':
            logAccessDenied('server_update', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'server.update.invalid_input':
            foreach ($ctx['errors'] as $code) {
                $e = $LANG['server_error_' . $code] ?? $code;
                toastError(
                    $LANG['server_error_invalid_input'] . ': ' . htmlspecialchars($e),
                    "Validierungsfehler beim Server-Update '{$name}' (ID {$id}): {$e}"
                );
            }
            break;

        case 'server.update.not_found':
            toastError(
                $LANG['error_server_not_found'],
                "Server-ID {$id} konnte nicht geladen werden."
            );
            break;

        case 'server.update.unchanged':
            toastSuccess(
                $LANG['no_changes'],
                "Die Serverdaten sind unverändert."
            );
            break;

        case 'server.update.lock_acquire_error':
            appLog('error', "[server_update] Lock '$lock' konnte nicht bezogen werden: " . $ctx['detail']);
            break;

        case 'server.update.topology_locked':
            toastWarning(
                $LANG['topology_locked'] ?? 'Eine Veröffentlichung oder strukturelle Änderung läuft gerade. Bitte in Kürze erneut versuchen.',
                "[server_update] Server '{$name}' (ID {$id}) nicht aktualisiert: Lock '$lock' nicht verfügbar"
            );
            break;

        case 'server.update.master_deactivation_blocked':
            toastError(
                $LANG['server_error_master_deactivation_blocked'],
                "Aktiver Master-Server '{$name}' (ID {$id}) sollte deaktiviert werden – abgebrochen."
            );
            break;

        case 'server.update.zone_rebuild_error':
            toastError(
                sprintf($LANG['zone_rebuild_failed'], $ctx['zoneId']),
                $ctx['output']
            );
            break;

        case 'server.update.zone_rebuild_warning':
            toastWarning(
                sprintf($LANG['zone_rebuild_warning'], $ctx['zoneId']),
                $ctx['output']
            );
            break;

        case 'server.update.success':
            toastSuccess(
                sprintf($LANG['server_updated_success'], htmlspecialchars($name)),
                "Server '{$name}' (ID {$id}) erfolgreich aktualisiert mit neuen Daten."
            );
            break;

        case 'server.update.failed':
            toastError(
                $LANG['server_error_db_update_failed'],
                "Fehler beim Speichern von Server '{$name}' (ID {$id}): " . $ctx['detail']
            );
            break;

        case 'server.update.lock_release_unconfirmed':
            appLog('warning', "[server_update] Freigabe von Lock '$lock' nicht bestätigt (RELEASE_LOCK = " . var_export($ctx['result'], true) . ")");
            break;

        case 'server.update.lock_release_error':
            appLog('warning', "[server_update] Lock '$lock' konnte nicht freigegeben werden (wird mit Verbindungsende frei): " . $ctx['detail']);
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (server_update)");
            } else {
                toastError(
                    $LANG['server_error_db_update_failed'],
                    "Unbehandelte ServiceMessage '{$message->code}' (server_update)"
                );
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
exit;
