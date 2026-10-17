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
        static fn (int $zoneId): array => rebuild_zone_and_flag_if_valid($zoneId)
    );
    $serviceResult = $recordService->createRecord($actor, $zone_id, $input, $autoPtr);

    // ServiceMessages in Reihenfolge in bestehende Toasts/Logs/HTTP-Antworten übersetzen
    $successContext = null;

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
                // Erfolgs-Toast erst nach den AutoPTR-Meldungen (nur ohne Fehler-Toasts)
                $successContext = $ctx;
                break;

            case 'record.create.ptr_invalid_ip':
                toastError(
                    $LANG['record_error_auto_ptr_invalid_ip'],
                    "Fehler bei PTR-Berechnung für {$ctx['type']} {$ctx['ip']} → Zone-ID {$ctx['zoneId']}, Name '{$ctx['name']}'"
                );
                break;

            case 'record.create.ptr_zone_missing':
                toastError(
                    sprintf($LANG['record_error_auto_ptr_no_zone'], "<code>{$ctx['ptrZone']}</code>"),
                    "PTR-Zuordnung fehlgeschlagen: keine Reverse-Zone '{$ctx['ptrZone']}' für IP {$ctx['ip']}"
                );
                break;

            case 'record.create.ptr_access_denied':
                logAccessDenied(
                    'record_add_auto_ptr',
                    'Benutzer-ID ' . $ctx['userId'] . ' hat keine Berechtigung für Reverse-Zone-ID ' . $ctx['ptrZoneId'] . ' (' . $ctx['ptrZone'] . ') – automatischer PTR wurde nicht angelegt'
                );
                toastError(
                    sprintf($LANG['record_error_auto_ptr_denied'], "<code>{$ctx['ptrZone']}</code>"),
                    "PTR-Zuordnung verweigert: keine Berechtigung für Reverse-Zone '{$ctx['ptrZone']}' (ID " . $ctx['ptrZoneId'] . ")"
                );
                break;

            case 'record.create.ptr_duplicate':
                toastError(
                    sprintf($LANG['record_error_auto_ptr_duplicate'], "<code>{$ctx['ptrZone']}</code>", "<code>{$ctx['ptrName']}</code>"),
                    "PTR-Duplikat erkannt: PTR {$ctx['ptrName']}.{$ctx['ptrZone']} existiert bereits"
                );
                break;

            case 'record.create.ptr_db_failed':
                toastError(
                    $LANG['record_error_auto_ptr_db'],
                    "Datenbankfehler beim Einfügen von PTR {$ctx['ptrName']}.{$ctx['ptrZone']}: " . $ctx['detail']
                );
                break;

            case 'record.create.ptr_created':
                // Bisher keine eigene Ausgabe
                break;
        }
    }

    if ($successContext !== null && empty($_SESSION['toast_errors'])) {
        toastSuccess(
            sprintf($LANG['record_created'], htmlspecialchars($successContext['type']), htmlspecialchars($successContext['name']), htmlspecialchars($successContext['zoneName'])),
            "Neuer DNS-Record {$successContext['type']} {$successContext['name']} erfolgreich gespeichert in Zone '{$successContext['zoneName']}' (ID {$successContext['zoneId']})"
        );
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . ($serviceResult->lastZoneId ?? $zone_id));
    exit;
}
?>
