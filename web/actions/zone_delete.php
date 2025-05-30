<?php
/**
 * Datei: zone_delete.php
 * Zweck: Löscht eine DNS-Zone aus der Datenbank.
 * Details:
 * - Nur Admins dürfen löschen (Zugriffskontrolle über requireRole(['admin'])).
 * - Nach dem Löschen wird das System-Flag 'bind_dirty' gesetzt,
 *   damit eine neue BIND-Konfiguration generiert werden kann.
 * - Aufruf erfolgt ausschließlich per POST-Request.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();

// Zugriffsbeschränkung: Nur Admins dürfen diese Funktion aufrufen
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    // Früher Ausstieg bei ungültiger ID
    if ($id <= 0) {
        toastError(
            "Ungültige Zonen-ID.",
            "Zone konnte nicht gelöscht werden: Ungültige ID übergeben."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
        exit;
    }

    // Namen der Zone für Logging und UI-Meldung laden
    $stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
    $stmt->execute([$id]);
    $zone = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$zone) {
        toastError(
            "Zone nicht gefunden.",
            "Zone mit ID {$id} existiert nicht oder wurde bereits gelöscht."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
        exit;
    }

    $name = $zone['name'];

    try {
        $pdo->beginTransaction();

        // Zone löschen
        $stmt = $pdo->prepare("DELETE FROM zones WHERE id = ?");
        $stmt->execute([$id]);

        // BIND als "dirty" markieren
        $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");

        $pdo->commit();

        toastSuccess(
            "Zone <strong>" . htmlspecialchars($name) . "</strong> wurde gelöscht.",
            "Zone '{$name}' (ID {$id}) erfolgreich gelöscht."
        );

    } catch (Exception $e) {
        $pdo->rollBack();
        toastError(
            "Fehler beim Löschen der Zone.",
            "Fehler beim Löschen von Zone '{$name}' (ID {$id}): " . $e->getMessage()
        );
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
    exit;
}

