<?php
/**
 * Datei: record_update.php
 * Zweck: Aktualisiert einen bestehenden DNS-Record innerhalb einer Zone.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/record_content_builder.php';
require_once __DIR__ . '/../inc/validators.php';
require_once __DIR__ . '/../inc/ttl_defaults.php';
require_once __DIR__ . '/../inc/glue_check.php';

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

    // Besitz prüfen (nicht für Admins)
    if ($_SESSION['role'] !== 'admin') {
        $stmt = $pdo->prepare("SELECT 1 FROM user_zones WHERE zone_id = ? AND user_id = ?");
        $stmt->execute([$zone_id, $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            logAccessDenied("Unbefugter Update-Versuch durch UID {$_SESSION['user_id']} auf ZID $zone_id");
            http_response_code(403);
            exit('Zugriff verweigert: Keine Berechtigung für diese Zone.');
        }
    }

    // Validierung
    $errors = validateDnsRecord($validator_type, $name, $content, null, $ttl);
    if (!empty($errors)) {
        foreach ($errors as $error) {
            toastError(
                $error,
                "Validierungsfehler beim Update von {$type} {$name} in Zone {$zone_name}: {$error}"
            );
        }
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
        exit;
    }

    // Record holen
    $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ? AND zone_id = ?");
    $stmt->execute([$id, $zone_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        exit('Fehler: Record nicht gefunden.');
    }

    // Glue-Check
    if (isGlueRecord($existing, getAllZoneRecords($pdo, $zone_id), getZoneName($pdo, $zone_id))) {
        if ($existing['name'] !== $name) {
            toastError(
                "Namensänderung bei Glue-Records ist nicht erlaubt.",
                "Glue-Record darf nicht umbenannt werden: {$existing['name']} (Zone: {$zone_name})"
            );
        }
        if ($existing['type'] !== $type) {
            toastError(
                "Typänderung bei Glue-Records ist nicht erlaubt.",
                "Glue-Record-Typänderung blockiert: {$existing['type']} (Zone: {$zone_name})"
            );
        }
        if (!empty($_SESSION['toast_errors'])) {
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
            exit;
        }
    }

    // Geschützte NS-Records dürfen nicht bearbeitet werden
    if (
        $existing['type'] === 'NS' &&
        isProtectedNsRecord($existing, getAllZoneRecords($pdo, $zone_id), getZoneName($pdo, $zone_id), $pdo, $zone_id)
    ) {
        if ($existing['name'] !== $name) {
            toastError(
                "Namensänderung bei geschütztem NS-Record ist nicht erlaubt.",
                "NS-Record darf nicht umbenannt werden: {$existing['name']} (Zone: {$zone_name})"
            );
        }
        if ($existing['content'] !== $content) {
            toastError(
                "Zieländerung bei geschütztem NS-Record ist nicht erlaubt.",
                "NS-Record darf nicht geändert werden: {$existing['content']} (Zone: {$zone_name})"
            );
        }
        if (!empty($_SESSION['toast_errors'])) {
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
            exit;
        }
    }

    // Update durchführen
    // Transaktion starten
    $pdo->beginTransaction();

    try {
        // Record aktualisieren
        $stmt = $pdo->prepare("
            UPDATE records
            SET name = ?, type = ?, content = ?, ttl = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $content, $ttl, $id]);

        // Zonendatei testen
        $rebuild = rebuild_zone_and_flag_if_valid($zone_id);

        if ($rebuild['status'] === 'error') {
            $pdo->rollBack();
            toastError(
                "Der Record konnte nicht aktualisiert werden, da die Zonendatei anschließend ungültig wäre.",
                $rebuild['output']
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
            exit;
        }

        if ($rebuild['status'] === 'warning') {
            toastWarning(
                "Record aktualisiert – Warnung beim Zonendatei-Check.",
                $rebuild['output']
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        toastError(
            "Beim Aktualisieren des Records ist ein Fehler aufgetreten.",
            "Systemfehler beim Update von {$type} {$name} in Zone {$zone_name} (ID {$id}): " . $e->getMessage()
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
        exit;
    }

    toastSuccess(
        "Record <strong>" . htmlspecialchars($type) . " {$name}</strong> erfolgreich in <strong>" . htmlspecialchars($zone_name) . "</strong> aktualisiert.",
        "DNS-Record {$type} {$name} erfolgreich geändert in Zone {$zone_name} (ID {$id})"
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
    exit;
}
?>
