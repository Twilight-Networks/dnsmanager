<?php
/**
 * Datei: record_update.php
 * Zweck: Aktualisiert einen bestehenden DNS-Record innerhalb einer Zone.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/record_content_builder.php';
require_once __DIR__ . '/../inc/ttl_defaults.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/records/RecordInput.php';
require_once __DIR__ . '/../inc/app/records/RecordService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin', 'zoneadmin']);

    $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $zone_id = isset($_POST['zone_id']) ? (int)$_POST['zone_id'] : 0;

    // Zonennamen für Toast-Ausgaben ermitteln
    $stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    $zone_name = rtrim($stmt->fetchColumn(), '.');

    $raw_type = $_POST['type'] ?? '';
    $is_dkim = !empty($_POST['is_dkim']); // z. B. aus hidden input

    // Sonderfall: Wenn DKIM als TXT gespeichert wird, aber speziell validiert werden muss
    // (Validator-Typ wird vor buildRecordContent() aus dem ursprünglichen Typ bestimmt, z. B. SPF -> TXT)
    $validator_type = ($raw_type === 'TXT' && $is_dkim) ? 'DKIM' : $raw_type;

    // Content zusammensetzen (inkl. spezieller Typ-Logik)
    $result = buildRecordContent($raw_type, $_POST);

    if (isset($result['error'])) {
        toastError(
            $result['error'],
            "Record-Update fehlgeschlagen: {$result['error']} (Typ: {$raw_type}, Zone: {$zone_name})"
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
        exit;
    }

    $type    = $result['type'];
    $name    = $result['name'];
    $content = $result['content'];
    // TTL auf Basis des Record-Typs setzen, wenn "auto" gewählt wurde
    $ttl_raw = $_POST['ttl'] ?? '';
    $ttl = ($ttl_raw === 'auto') ? getAutoTTL($type) : (int)$ttl_raw;

    // Fachliche Update-Operation im RecordService
    $actor      = actorFromSession();
    $zoneAccess = new ZoneAccess($pdo);
    $input      = new RecordInput($type, $name, $content, $ttl, $is_dkim, $validator_type);

    $recordService = new RecordService(
        $pdo,
        $zoneAccess,
        static fn (int $zoneId): array => rebuild_zone_and_flag_if_valid($zoneId, $pdo)
    );
    $serviceResult = $recordService->updateRecord($actor, $id, $zone_id, $input);

    // ServiceMessages in Reihenfolge in bestehende Toasts/Logs/HTTP-Antworten übersetzen
    foreach ($serviceResult->messages as $message) {
        $ctx = $message->context;

        switch ($message->code) {
            case 'record.update.access_denied':
                logAccessDenied('record_update', "Unbefugter Update-Versuch durch UID {$ctx['userId']} auf ZID {$ctx['zoneId']}");
                http_response_code(403);
                exit('Zugriff verweigert: Keine Berechtigung für diese Zone.');

            case 'record.update.not_found':
                http_response_code(404);
                exit('Fehler: Record nicht gefunden.');

            case 'record.update.validation_error':
                toastError(
                    $LANG[$ctx['errorCode']] ?? $LANG['generic_validation_error'],
                    "Validierungsfehler beim Update von {$ctx['type']} {$ctx['name']} in Zone {$ctx['zoneName']}: {$ctx['errorCode']}"
                );
                break;

            case 'record.update.no_changes':
                toastSuccess($LANG['no_changes'], 'Der DNS-Record ist unverändert.');
                break;

            case 'record.update.glue_name_change':
                toastError(
                    $LANG['record_error_glue_name_change'],
                    "Glue-Record darf nicht umbenannt werden: {$ctx['existingName']} (Zone: {$ctx['zoneName']})"
                );
                break;

            case 'record.update.glue_type_change':
                toastError(
                    $LANG['record_error_glue_type_change'],
                    "Glue-Record-Typänderung blockiert: {$ctx['existingType']} (Zone: {$ctx['zoneName']})"
                );
                break;

            case 'record.update.ns_name_change':
                toastError(
                    $LANG['record_error_ns_name_change'],
                    "NS-Record darf nicht umbenannt werden: {$ctx['existingName']} (Zone: {$ctx['zoneName']})"
                );
                break;

            case 'record.update.ns_content_change':
                toastError(
                    $LANG['record_error_ns_content_change'],
                    "NS-Record darf nicht geändert werden: {$ctx['existingContent']} (Zone: {$ctx['zoneName']})"
                );
                break;

            case 'record.update.zonefile_invalid':
                toastError(
                    $LANG['record_error_zonefile_invalid'],
                    $ctx['output']
                );
                break;

            case 'record.update.zonefile_warning':
                toastWarning(
                    $LANG['record_warning_zonefile_check'],
                    $ctx['output']
                );
                break;

            case 'record.update.db_save_failed':
                toastError(
                    $LANG['record_error_db_save_failed'],
                    "Systemfehler beim Update von {$ctx['type']} {$ctx['name']} in Zone {$ctx['zoneName']} (ID {$ctx['recordId']}): " . $ctx['detail']
                );
                break;

            case 'record.update.success':
                toastSuccess(
                    sprintf($LANG['record_updated'], htmlspecialchars($ctx['type']), htmlspecialchars($ctx['name']), htmlspecialchars($ctx['zoneName'])),
                    "DNS-Record {$ctx['type']} {$ctx['name']} erfolgreich geändert in Zone {$ctx['zoneName']} (ID {$ctx['recordId']})"
                );
                break;
        }
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . ($serviceResult->lastZoneId ?? $zone_id));
    exit;
}
?>
