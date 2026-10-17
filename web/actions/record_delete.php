<?php
/**
 * Datei: record_delete.php
 * Zweck: Löscht einen einzelnen DNS-Record aus einer Zone.
 * Details:
 * - Admins dürfen alle Records löschen.
 * - Zone-Admins dürfen nur Records in ihnen zugewiesenen Zonen löschen.
 * - Glue-Records (A/AAAA, die zu autoritativen NS-Einträgen gehören) dürfen nicht gelöscht werden.
 * - Nach dem Löschen wird das "changed"-Flag der Zone gesetzt.
 * Zugriff: Nur per POST, geschützt durch Rollen- und Besitzprüfung.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/records/RecordService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin', 'zoneadmin']);

    $actor      = actorFromSession();
    $zoneAccess = new ZoneAccess($pdo);

    $bulk_ids   = isset($_POST['bulk_ids']) ? explode(',', $_POST['bulk_ids']) : [];
    $single_id  = isset($_POST['id']) ? [(int)$_POST['id']] : [];
    $ids        = array_filter(array_map('intval', array_merge($bulk_ids, $single_id)));

    if (empty($ids)) {
        http_response_code(400);
        exit('Keine gültigen Record-IDs übergeben.');
    }

    $recordService = new RecordService($pdo, $zoneAccess);
    $result        = $recordService->deleteRecords($actor, $ids);

    // ServiceMessages in Reihenfolge in bestehende Toasts/Logs übersetzen
    foreach ($result->messages as $message) {
        $ctx = $message->context;

        switch ($message->code) {
            case 'record.delete.access_denied':
                logAccessDenied(
                    'record_delete',
                    "Benutzer-ID {$ctx['userId']} wollte Record-ID {$ctx['recordId']} in Zone-ID {$ctx['zoneId']} löschen."
                );
                break;

            case 'record.delete.glue_protected':
                toastError(
                    sprintf($LANG['record_error_glue_protected'], "<strong>" . htmlspecialchars($ctx['recordName']) . "</strong>"),
                    "Löschversuch für Glue-Record '{$ctx['recordName']}' in Zone '{$ctx['zoneName']}' blockiert."
                );
                break;

            case 'record.delete.ns_protected':
                toastError(
                    sprintf($LANG['record_error_ns_protected'], "<strong>" . htmlspecialchars($ctx['recordContent']) . "</strong>"),
                    "Löschversuch für NS-Record in Zone '{$ctx['zoneName']}' blockiert."
                );
                break;

            case 'record.delete.zonefile_warning':
                toastWarning(
                    sprintf($LANG['record_warning_zonefile_check_after_delete'], "<strong>{$ctx['zoneName']}</strong>"),
                    $ctx['output']
                );
                break;

            case 'record.delete.failed':
                toastError(
                    $LANG['record_error_delete_failed'],
                    "Transaktion abgebrochen: " . $ctx['detail']
                );
                break;

            case 'record.delete.success':
                toastSuccess(
                    $LANG['record_deleted_success'],
                    "Alle ausgewählten Records wurden erfolgreich entfernt."
                );
                break;
        }
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . ($result->lastZoneId ?? 0));
    exit;
}
?>
