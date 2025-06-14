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
require_once __DIR__ . '/../inc/glue_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin', 'zoneadmin']);

    $bulk_ids   = isset($_POST['bulk_ids']) ? explode(',', $_POST['bulk_ids']) : [];
    $single_id  = isset($_POST['id']) ? [(int)$_POST['id']] : [];
    $ids        = array_filter(array_map('intval', array_merge($bulk_ids, $single_id)));

    if (empty($ids)) {
        http_response_code(400);
        exit('Keine gültigen Record-IDs übergeben.');
    }

    $pdo->beginTransaction();

    try {
        $affected_zones = [];

        foreach ($ids as $id) {
            $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ?");
            $stmt->execute([$id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new RuntimeException("Record mit ID $id nicht gefunden.");
            }

            $zone_id = (int)$record['zone_id'];
            $zone_name = getZoneName($pdo, $zone_id);

            // Rechte prüfen
            if ($_SESSION['role'] !== 'admin') {
                $stmt = $pdo->prepare("SELECT 1 FROM user_zones WHERE zone_id = ? AND user_id = ?");
                $stmt->execute([$zone_id, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    logAccessDenied("Benutzer-ID {$_SESSION['user_id']} wollte Record-ID $id in Zone-ID $zone_id löschen.");
                    throw new RuntimeException("Keine Berechtigung für Record-ID $id.");
                }
            }

            // Glue-Check
            $all_records = getAllZoneRecords($pdo, $zone_id);
            if (isGlueRecord($record, $all_records, $zone_name)) {
                toastError(
                    sprintf($LANG['record_error_glue_protected'], "<strong>" . htmlspecialchars($record['name']) . "</strong>"),
                    "Löschversuch für Glue-Record '{$record['name']}' in Zone '{$zone_name}' blockiert."
                );
                throw new RuntimeException("Löschen von Glue-Record nicht erlaubt.");
            }

            // Geschützte NS-Records dürfen nicht gelöscht werden
            if ($record['type'] === 'NS' && isProtectedNsRecord($record, $all_records, $zone_name, $pdo, $zone_id)) {
                toastError(
                    sprintf($LANG['record_error_ns_protected'], "<strong>" . htmlspecialchars($record['content']) . "</strong>"),
                    "Löschversuch für NS-Record in Zone '{$zone_name}' blockiert."
                );
                throw new RuntimeException("Löschen von geschütztem NS-Record nicht erlaubt.");
            }

            // Löschen
            $stmt = $pdo->prepare("DELETE FROM records WHERE id = ?");
            $stmt->execute([$id]);

            // Merken, welche Zonen betroffen sind
            $affected_zones[$zone_id] = $zone_name;
        }

        // Zonendateien prüfen
        foreach ($affected_zones as $zone_id => $zone_name) {
            $rebuild = rebuild_zone_and_flag_if_valid($zone_id);

            if ($rebuild['status'] === 'error') {
                throw new RuntimeException("Zonendatei-Fehler nach Löschung in Zone '{$zone_name}': {$rebuild['output']}");
            }

            if ($rebuild['status'] === 'warning') {
                toastWarning(
                    sprintf($LANG['record_warning_zonefile_check_after_delete'], "<strong>{$zone_name}</strong>"),
                    $rebuild['output']
                );
            }
        }

        $pdo->commit();
        toastSuccess(
            $LANG['record_deleted_success'],
            "Alle ausgewählten Records wurden erfolgreich entfernt."
        );
    } catch (Throwable $e) {
        $pdo->rollBack();
        toastError(
            $LANG['record_error_delete_failed'],
            "Transaktion abgebrochen: " . $e->getMessage()
        );
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . ($zone_id ?? 0));
    exit;
}
?>
