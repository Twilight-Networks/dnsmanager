<?php
/**
 * Datei: server_delete.php
 * Zweck: Löscht einen DNS-Servereintrag aus dem System.
 *
 * Funktionen:
 * - Verhindert das Löschen, wenn der Server noch einer Zone zugewiesen ist (Master oder Slave).
 * - Löscht den Servereintrag aus der Datenbank.
 * - Gibt Rückmeldung über Toast-Meldungen bei Fehlern oder Erfolg.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Nur POST erlaubt');
}

// Eingabe validieren
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id < 1) {
    toastError(
        $LANG['server_error_invalid_id'],
        "Ungültige Server-ID beim Löschversuch übergeben."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;
}

// Serverdaten laden
$stmt = $pdo->prepare("SELECT name FROM servers WHERE id = ?");
$stmt->execute([$id]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    toastError(
        $LANG['error_server_not_found'],
        "Server mit ID {$id} konnte in der Datenbank nicht gefunden werden."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;
}

// Prüfen, ob der Server noch in zone_servers verwendet wird
$stmt = $pdo->prepare("SELECT COUNT(*) FROM zone_servers WHERE server_id = ?");
$stmt->execute([$id]);
$usage_count = $stmt->fetchColumn();

if ($usage_count > 0) {
    toastError(
        $LANG['server_error_delete_assigned'],
        "'{$server['name']}' (ID {$id}) ist noch mindestens einer Zone zugewiesen – Löschvorgang abgebrochen."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;
}

try {
    $pdo->beginTransaction();

    // Diagnosedaten entfernen
    $stmt = $pdo->prepare("DELETE FROM diagnostics WHERE server_id = ?");
    $stmt->execute([$id]);

    $stmt = $pdo->prepare("DELETE FROM diagnostic_log WHERE server_id = ?");
    $stmt->execute([$id]);

    // Server selbst löschen
    $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();

    toastSuccess(
        $LANG['server_deleted'],
        "Server '{$server['name']}' (ID {$id}) erfolgreich gelöscht."
    );

} catch (PDOException $e) {
    $pdo->rollBack();
    toastError(
        $LANG['server_error_delete_failed'],
        "Datenbankfehler beim Löschen von Server '{$server['name']}' (ID {$id}): " . $e->getMessage()
    );
}

// Redirect (immer am Ende, nach Erfolg oder Fehler)
header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
exit;
