<?php
/**
 * Datei: zone_delete.php
 * Zweck: Löscht eine DNS-Zone aus der Datenbank.
 * Details:
 * - Nur Admins dürfen löschen (Zugriffskontrolle über requireRole(['admin'])).
 * - Nach dem Löschen wird das System-Flag 'bind_dirty' gesetzt,
 *   damit eine neue BIND-Konfiguration generiert werden kann.
 * - Aufruf erfolgt ausschließlich per POST-Request.
 *
 * Web-Adapter: ZoneService::deleteZone() aufrufen und ServiceMessages in die bisherigen
 * Toasts/Logs/Redirects übersetzen. Berechtigung (DB-Rolle), Topologie-Lock, Sperre und Transaktion
 * liegen im Service.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/TopologyLock.php';
require_once __DIR__ . '/../inc/app/zones/ZoneService.php';
require_once __DIR__ . '/../inc/deploy/LegacyZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Zugriffsbeschränkung: Nur Admins dürfen diese Funktion aufrufen
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    $zoneService = new ZoneService($pdo, new LegacyZoneBuildBackend($pdo), new TopologyLock($pdo));
    $result = $zoneService->deleteZone(actorFromSession(), $id);

    $lock = TopologyLock::NAME;

    // ServiceMessages in bestehende Toasts/Logs übersetzen
    foreach ($result->messages as $message) {
        $ctx = $message->context;

        switch ($message->code) {
            case 'zone.delete.access_denied':
                logAccessDenied('zone_delete', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein Administrator.");
                http_response_code(403);
                exit('Zugriff verweigert: Unzureichende Berechtigungen.');

            case 'zone.delete.invalid_id':
                toastError(
                    $LANG['zone_error_invalid_id'],
                    "Zone konnte nicht gelöscht werden: Ungültige ID übergeben."
                );
                break;

            case 'zone.delete.not_found':
                toastError(
                    $LANG['zone_error_not_found'],
                    "Zone mit ID {$id} existiert nicht oder wurde bereits gelöscht."
                );
                break;

            case 'zone.delete.lock_acquire_error':
                appLog('error', "[zone_delete] Lock '$lock' konnte nicht bezogen werden: " . $ctx['detail']);
                break;

            case 'zone.delete.topology_locked':
                toastWarning(
                    $LANG['topology_locked'] ?? 'Eine Veröffentlichung oder strukturelle Änderung läuft gerade. Bitte in Kürze erneut versuchen.',
                    "[zone_delete] Zone '{$ctx['name']}' (ID {$id}) nicht gelöscht: Lock '$lock' nicht verfügbar"
                );
                break;

            case 'zone.delete.lock_release_unconfirmed':
                appLog('warning', "[zone_delete] Freigabe von Lock '$lock' nicht bestätigt (RELEASE_LOCK = " . var_export($ctx['result'], true) . ")");
                break;

            case 'zone.delete.lock_release_error':
                appLog('warning', "[zone_delete] Lock '$lock' konnte nicht freigegeben werden (wird mit Verbindungsende frei): " . $ctx['detail']);
                break;

            case 'zone.delete.failed':
                toastError(
                    $LANG['zone_error_delete_failed'],
                    "Fehler beim Löschen von Zone '{$ctx['name']}' (ID {$id}): " . $ctx['detail']
                );
                break;

            case 'zone.delete.success':
                toastSuccess(
                    sprintf($LANG['zone_deleted'], htmlspecialchars($ctx['name'])),
                    "Zone '{$ctx['name']}' (ID {$id}) erfolgreich gelöscht."
                );
                break;

            default:
                // Unbekannte ServiceMessages nie still verwerfen
                if ($message->kind === 'success') {
                    appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (zone_delete)");
                } else {
                    toastError(
                        $LANG['zone_error_delete_failed'],
                        "Unbehandelte ServiceMessage '{$message->code}' (zone_delete)"
                    );
                }
                break;
        }
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
    exit;
}
