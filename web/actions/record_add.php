<?php
/**
 * Datei: record_add.php
 * Zweck: Fügt einen neuen DNS-Record zu einer bestehenden Zone hinzu.
 *
 * Details:
 * - Verarbeitet POST-Daten aus dem Formular zum Anlegen neuer DNS-Einträge.
 * - CSRF-Token wird geprüft, um Manipulationen und Angriffe zu verhindern.
 * - Rollenbasierte Zugriffskontrolle: Admins können systemweit schreiben,
 *   Zone-Admins nur innerhalb der ihnen zugewiesenen Zonen.
 * - Unterstützt verschiedenste Record-Typen (A, AAAA, MX, DKIM, SRV etc.)
 *   und verarbeitet diese über `record_content_builder.php`.
 * - Eingabedaten werden validiert und in die Datenbank übernommen.
 *
 * Zugriff:
 * - Nur per POST erlaubt.
 * - Zugriffsschutz erfolgt durch `verify_csrf_token()`, `require_login()`,
 *   sowie durch eine Rollen- und Zonenprüfung mittels `requireRole()` und Zonenbesitz.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/record_content_builder.php';
require_once __DIR__ . '/../inc/dkim_helpers.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/records/RecordInput.php';
require_once __DIR__ . '/../inc/app/records/RecordService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Nur POST-Anfragen zulassen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireRole(['admin', 'zoneadmin']);

    $zone_id = (int)($_POST['zone_id'] ?? 0);

    // Zonenberechtigung früh prüfen (vor Upload/Formularverarbeitung, wie bisher).
    // Die autoritative fachliche Prüfung erfolgt zusätzlich in RecordService::createRecord().
    $actor      = actorFromSession();
    $zoneAccess = new ZoneAccess($pdo);

    if (!$zoneAccess->canWriteZone($actor, $zone_id)) {
        logAccessDenied('record_add', 'Benutzer-ID ' . $actor->userId . ' versucht unbefugten Zugriff auf Zone-ID ' . $zone_id);
        http_response_code(403);
        exit('Zugriff verweigert.');
    }

    $raw_type = $_POST['type'] ?? '';
    $is_dkim = !empty($_POST['is_dkim']); // z. B. aus hidden input

    // Sonderfall: Wenn DKIM als TXT gespeichert wird, aber speziell validiert werden muss
    // (Validator-Typ wird vor buildRecordContent() aus dem ursprünglichen Typ bestimmt, z. B. SPF -> TXT)
    $validator_type = ($raw_type === 'TXT' && $is_dkim) ? 'DKIM' : $raw_type;

    // Überprüfen, ob eine DKIM-Datei hochgeladen wurde
    if (isset($_FILES['dkim_file']) && $_FILES['dkim_file']['error'] === UPLOAD_ERR_OK) {
        // Maximale Dateigröße auf 2 KB festlegen
        $maxFileSize = 2048; // 2 KB

        // Überprüfen, ob die Datei nicht größer als die maximal erlaubte Größe ist
        if ($_FILES['dkim_file']['size'] > $maxFileSize) {
        toastError(
            $LANG['record_error_dkim_file_too_large'],
            "DKIM-Upload abgebrochen: Datei überschreitet 2 KB Limit."
        );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }

        // Überprüfen der Dateiendung (nur .txt-Dateien erlauben)
        $fileInfo = pathinfo($_FILES['dkim_file']['name']);
        $fileExtension = strtolower($fileInfo['extension']);
        if ($fileExtension !== 'txt') {
            toastError(
                $LANG['record_error_dkim_invalid_extension'],
                "DKIM-Upload abgelehnt: Dateiendung '{$fileExtension}' ist nicht erlaubt."
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }

        // Pfad zur temporären Datei
        $filePath = $_FILES['dkim_file']['tmp_name'];

        // Dateiinhalt überprüfen (keine schädlichen Tags oder PHP-Code)
        $fileContent = file_get_contents($filePath);
        if (preg_match('/<\?php|<script|<\/script>/', $fileContent)) {
            toastError(
                $LANG['record_error_dkim_invalid_code'],
                "DKIM-Datei abgewiesen: Enthält verbotene Zeichenfolgen."
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }

        // DKIM-Datei verarbeiten
        $dkimData = parseDKIMFile($filePath);

        if ($dkimData) {
            // DKIM-Daten extrahiert, an das Formular übergeben
            $_POST['dkim_selector'] = $dkimData['selector'];
            $_POST['dkim_key'] = $dkimData['key'];
        } else {
            toastError(
                $LANG['record_error_dkim_parse_failed'],
                "Fehler beim Parsen der hochgeladenen DKIM-Datei."
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }
    }

    // TTL auf Basis des Record-Typs setzen, wenn "auto" gewählt wurde
    if (isset($_POST['ttl']) && $_POST['ttl'] === 'auto') {
        require_once __DIR__ . '/../inc/ttl_defaults.php';
        $_POST['ttl'] = getAutoTTL($raw_type);
    }

    // Content zusammensetzen (inkl. spezieller Typ-Logik)
    $result = buildRecordContent($raw_type, $_POST);

    if (isset($result['error'])) {
        toastError(
            $LANG['record_error_build_failed'],
            "Fehler beim Verarbeiten des Record-Typs '{$raw_type}': {$result['error']}"
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
        exit;
    }

    $type    = $result['type'];
    $name    = $result['name'];
    $content = $result['content'];
    $ttl     = intval($_POST['ttl']) ?: 3600;

    // Fachliche Anlage (inkl. optionalem AutoPTR) im RecordService
    $input      = new RecordInput($type, $name, $content, $ttl, $is_dkim, $validator_type);
    $autoPtr    = ($_POST['auto_ptr'] ?? '') === 'on';

    $recordService = new RecordService(
        $pdo,
        $zoneAccess,
        static fn (int $zoneId): array => rebuild_zone_and_flag_if_valid($zoneId, $pdo)
    );
    $serviceResult = $recordService->createRecord($actor, $zone_id, $input, $autoPtr);

    // ServiceMessages in Reihenfolge in bestehende Toasts/Logs/HTTP-Antworten übersetzen.
    // Forward-Erfolg und AutoPTR-Ergebnis bleiben gemeinsam sichtbar: Da nur eine Erfolgsmeldung
    // gespeichert wird, werden Forward-Erfolg und PTR-Erfolg (angelegt/bereits vorhanden) zu einer
    // Meldung zusammengefasst. Ein nicht angelegter PTR erscheint als Warnung daneben.
    $successToast    = null; // Forward-Erfolg (Toast-Text)
    $successLog      = null; // Forward-Erfolg (Log-Text)
    $ptrSuccessToast = null; // PTR angelegt oder identisch vorhanden (Zusatz zur Erfolgsmeldung)

    foreach ($serviceResult->messages as $message) {
        $ctx = $message->context;

        switch ($message->code) {
            case 'record.create.access_denied':
                logAccessDenied('record_add', 'Benutzer-ID ' . $ctx['userId'] . ' versucht unbefugten Zugriff auf Zone-ID ' . $ctx['zoneId']);
                http_response_code(403);
                exit('Zugriff verweigert.');

            case 'record.create.validation_error':
                toastError(
                    $LANG[$ctx['errorCode']] ?? $LANG['generic_validation_error'],
                    "Fehler bei der Validierung von Record '{$ctx['type']} {$ctx['name']}': {$ctx['errorCode']}"
                );
                break;

            case 'record.create.duplicate':
                toastError(
                    sprintf($LANG['record_error_duplicate'], htmlspecialchars($ctx['name']), $ctx['type']),
                    "Record-Duplikat erkannt: {$ctx['type']} {$ctx['name']} bereits vorhanden in Zone {$ctx['zoneName']} (ID {$ctx['zoneId']})"
                );
                break;

            case 'record.create.dyndns_reserved':
                toastError(
                    sprintf(
                        $LANG['record_error_dyndns_' . strtolower((string)$ctx['reason'])] ?? $LANG['record_error_dyndns_generic'],
                        '<code>' . htmlspecialchars((string)$ctx['name']) . '</code>'
                    ),
                    "Anlegen von {$ctx['type']} {$ctx['name']} in Zone {$ctx['zoneName']} (ID {$ctx['zoneId']}) durch DynDNS-Reservierung blockiert: {$ctx['reason']} (Hostname '{$ctx['hostname']}')"
                );
                break;

            case 'record.create.zonefile_invalid':
                toastError(
                    $LANG['record_error_zonefile_invalid'],
                    $ctx['output']
                );
                break;

            case 'record.create.zonefile_warning':
                toastWarning(
                    $LANG['record_warning_zonefile_check'],
                    $ctx['output']
                );
                break;

            case 'record.create.db_save_failed':
                toastError(
                    $LANG['record_error_db_save_failed'],
                    "Datenbankfehler beim Speichern von {$ctx['type']} {$ctx['name']} in Zone-ID {$ctx['zoneId']}: " . $ctx['detail']
                );
                break;

            case 'record.create.success':
                // Ausgabe nach der Schleife (ggf. zusammen mit dem PTR-Ergebnis)
                $successToast = sprintf($LANG['record_created'], htmlspecialchars($ctx['type']), htmlspecialchars($ctx['name']), htmlspecialchars($ctx['zoneName']));
                $successLog   = "Neuer DNS-Record {$ctx['type']} {$ctx['name']} erfolgreich gespeichert in Zone '{$ctx['zoneName']}' (ID {$ctx['zoneId']})";
                break;

            case 'record.create.ptr_skipped_wildcard':
                toastWarning(
                    sprintf($LANG['record_warning_auto_ptr_wildcard'], '<code>' . htmlspecialchars($ctx['name']) . '</code>'),
                    "Kein automatischer PTR für Wildcard-Record '{$ctx['name']}' in Zone '{$ctx['zoneName']}' (IP {$ctx['ip']})"
                );
                break;

            case 'record.create.ptr_skipped_out_of_zone':
                toastWarning(
                    sprintf($LANG['record_warning_auto_ptr_out_of_zone'], '<code>' . htmlspecialchars($ctx['name']) . '</code>', '<code>' . htmlspecialchars($ctx['zoneName']) . '</code>'),
                    "Kein automatischer PTR: Name '{$ctx['name']}' liegt außerhalb der Zone '{$ctx['zoneName']}' (IP {$ctx['ip']})"
                );
                break;

            case 'record.create.ptr_invalid_ip':
                toastWarning(
                    $LANG['record_error_auto_ptr_invalid_ip'],
                    "Fehler bei PTR-Berechnung für {$ctx['type']} {$ctx['ip']} → Zone-ID {$ctx['zoneId']}, Name '{$ctx['name']}'"
                );
                break;

            case 'record.create.ptr_zone_missing':
                toastWarning(
                    sprintf($LANG['record_error_auto_ptr_no_zone'], "<code>{$ctx['ptrZone']}</code>"),
                    "PTR-Zuordnung fehlgeschlagen: keine Reverse-Zone '{$ctx['ptrZone']}' für IP {$ctx['ip']}"
                );
                break;

            case 'record.create.ptr_access_denied':
                logAccessDenied(
                    'record_add_auto_ptr',
                    'Benutzer-ID ' . $ctx['userId'] . ' hat keine Berechtigung für Reverse-Zone-ID ' . $ctx['ptrZoneId'] . ' (' . $ctx['ptrZone'] . ') – automatischer PTR wurde nicht angelegt'
                );
                toastWarning(
                    sprintf($LANG['record_error_auto_ptr_denied'], "<code>{$ctx['ptrZone']}</code>"),
                    "PTR-Zuordnung verweigert: keine Berechtigung für Reverse-Zone '{$ctx['ptrZone']}' (ID " . $ctx['ptrZoneId'] . ")"
                );
                break;

            case 'record.create.ptr_duplicate':
                toastWarning(
                    sprintf($LANG['record_error_auto_ptr_duplicate'], "<code>{$ctx['ptrZone']}</code>", "<code>{$ctx['ptrName']}</code>"),
                    "PTR-Konflikt: PTR {$ctx['ptrName']}.{$ctx['ptrZone']} existiert bereits mit abweichendem Ziel (" . implode(', ', $ctx['existingTargets']) . "), gewünscht {$ctx['target']}"
                );
                break;

            case 'record.create.ptr_ambiguous':
                toastWarning(
                    sprintf($LANG['record_warning_auto_ptr_ambiguous'], "<code>{$ctx['ptrZone']}</code>", "<code>{$ctx['ptrName']}</code>"),
                    "PTR mehrdeutig: für {$ctx['ptrName']}.{$ctx['ptrZone']} existieren bereits " . count($ctx['existingTargets']) . " PTR-Datensätze (" . implode(', ', $ctx['existingTargets']) . "), gewünscht {$ctx['target']} – keine Änderung"
                );
                break;

            case 'record.create.ptr_zonefile_invalid':
                toastWarning(
                    sprintf($LANG['record_warning_auto_ptr_zonefile_invalid'], "<code>{$ctx['ptrZone']}</code>"),
                    "PTR {$ctx['ptrName']}.{$ctx['ptrZone']} nicht angelegt, Reverse-Zone wäre ungültig: {$ctx['output']}"
                );
                break;

            case 'record.create.ptr_db_failed':
                toastWarning(
                    $LANG['record_error_auto_ptr_db'],
                    "Datenbankfehler beim Einfügen von PTR {$ctx['ptrName']}.{$ctx['ptrZone']}: " . $ctx['detail']
                );
                break;

            case 'record.create.ptr_zonefile_warning':
                toastWarning(
                    sprintf($LANG['record_warning_auto_ptr_zonefile_check'], "<code>{$ctx['ptrZone']}</code>"),
                    $ctx['output']
                );
                break;

            case 'record.create.ptr_exists':
                $ptrSuccessToast = sprintf($LANG['record_auto_ptr_exists'], "<code>{$ctx['ptrName']}.{$ctx['ptrZone']}</code>");
                appLog('info', "PTR {$ctx['ptrName']}.{$ctx['ptrZone']} -> {$ctx['target']} bereits identisch vorhanden (keine Änderung)");
                break;

            case 'record.create.ptr_created':
                $ptrSuccessToast = sprintf($LANG['record_auto_ptr_created'], "<code>{$ctx['ptrName']}.{$ctx['ptrZone']}</code>");
                appLog('info', "PTR {$ctx['ptrName']}.{$ctx['ptrZone']} -> {$ctx['target']} automatisch angelegt");
                break;

            default:
                // Unbekannte ServiceMessages nie still verwerfen
                if ($message->kind === 'error') {
                    toastError(
                        $LANG['record_error_unhandled'],
                        "Unbehandelte ServiceMessage '{$message->code}' (record_add)"
                    );
                } else {
                    appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (record_add)");
                }
                break;
        }
    }

    // Eine gemeinsame Erfolgsmeldung: Forward-Record gespeichert (+ PTR angelegt bzw. bereits vorhanden)
    if ($successToast !== null) {
        toastSuccess(
            $ptrSuccessToast !== null ? $successToast . '<br>' . $ptrSuccessToast : $successToast,
            $successLog
        );
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . ($serviceResult->lastZoneId ?? $zone_id));
    exit;
}
?>
