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
            $LANG['zone_error_invalid_id'],
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
            $LANG['zone_error_not_found'],
            "Zone mit ID {$id} existiert nicht oder wurde bereits gelöscht."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
        exit;
    }

    $name = $zone['name'];

    // Topologie-Lock: derselbe MariaDB-Advisory-Lock wie Publish All ('dnsmanager_publish_all',
    // GET_LOCK mit Timeout 0, kein Warten). Er wird vor jeder Transaktion bzw. schreibenden Operation
    // erworben und erst nach Commit/Rollback freigegeben. Bei hartem Prozessabbruch gibt MariaDB ihn
    // mit dem Verbindungsende frei.
    $publish_lock_name = 'dnsmanager_publish_all';
    $publish_lock_acquired = false;
    $releasePublishLock = function () use ($pdo, $publish_lock_name, &$publish_lock_acquired): void {
        if (!$publish_lock_acquired) {
            return;
        }
        try {
            $stmt_release = $pdo->prepare("SELECT RELEASE_LOCK(?)");
            $stmt_release->execute([$publish_lock_name]);
            $release_result = $stmt_release->fetchColumn(); // 1 = freigegeben, 0 = nicht von dieser Verbindung gehalten, NULL = Lock existiert nicht
            if ($release_result === null || $release_result === false || (int)$release_result !== 1) {
                // Fachlicher Vorgang bleibt erfolgreich; Lock wird spätestens mit dem Verbindungsende frei
                appLog('warning', "[zone_delete] Freigabe von Lock '$publish_lock_name' nicht bestätigt (RELEASE_LOCK = " . var_export($release_result, true) . ")");
            }
        } catch (Throwable $e) {
            appLog('warning', "[zone_delete] Lock '$publish_lock_name' konnte nicht freigegeben werden (wird mit Verbindungsende frei): " . $e->getMessage());
        } finally {
            // Release-Versuch abgeschlossen: keine erneuten Freigabeversuche über diesen Helper
            $publish_lock_acquired = false;
        }
    };
    try {
        $stmt_lock = $pdo->prepare("SELECT GET_LOCK(?, 0)");
        $stmt_lock->execute([$publish_lock_name]);
        $lock_result = $stmt_lock->fetchColumn(); // 1 = erhalten, 0 = belegt, NULL = Fehler
        $publish_lock_acquired = $lock_result !== null && (int)$lock_result === 1;
    } catch (Throwable $e) {
        appLog('error', "[zone_delete] Lock '$publish_lock_name' konnte nicht bezogen werden: " . $e->getMessage());
    }
    if (!$publish_lock_acquired) {
        // Keine Transaktion, keine Schreiboperation
        toastWarning(
            $LANG['topology_locked'] ?? 'Eine Veröffentlichung oder strukturelle Änderung läuft gerade. Bitte in Kürze erneut versuchen.',
            "[zone_delete] Zone '{$name}' (ID {$id}) nicht gelöscht: Lock '$publish_lock_name' nicht verfügbar"
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Zone löschen
        $stmt = $pdo->prepare("DELETE FROM zones WHERE id = ?");
        $stmt->execute([$id]);

        // BIND als "dirty" markieren
        $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");

        $pdo->commit();

        toastSuccess(
            sprintf($LANG['zone_deleted'], htmlspecialchars($name)),
            "Zone '{$name}' (ID {$id}) erfolgreich gelöscht."
        );

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        toastError(
            $LANG['zone_error_delete_failed'],
            "Fehler beim Löschen von Zone '{$name}' (ID {$id}): " . $e->getMessage()
        );
    }

    // Lock nach Commit bzw. Rollback freigeben
    $releasePublishLock();

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
    exit;
}

