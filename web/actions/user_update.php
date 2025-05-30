<?php
/**
 * Datei: user_update.php
 * Zweck: Bestehenden Benutzer aktualisieren (Benutzername, Rolle und Zonen).
 *
 * Details:
 * - Nur Admins dürfen Benutzer bearbeiten.
 * - Benutzername wird geprüft, Rolle wird validiert.
 * - Bei Zone-Admins werden die zugewiesenen Zonen aktualisiert.
 *
 * Zugriff:
 * - Nur Admins (requireRole(['admin'])).
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);

// Eingaben absichern
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$username = trim($_POST['username']);
$role = $_POST['role'];
$zones = isset($_POST['zones']) ? $_POST['zones'] : [];

// Grundvalidierung der Eingaben
if ($id <= 0 || !in_array($role, ['admin', 'zoneadmin'], true)) {
    toastError(
        "Ungültige Eingabe.",
        "Benutzer-Update fehlgeschlagen: Ungültige ID oder Rolle (ID {$id}, Rolle '{$role}')."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// Benutzername validieren
if (!preg_match('/^[a-zA-Z0-9_\-\.@]+$/', $username)) {
    toastError(
        "Ungültiger Benutzername.",
        "Benutzer-Update fehlgeschlagen: Benutzername '{$username}' ist ungültig."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// Prüfen, ob der Benutzer existiert
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
$stmt->execute([$id]);
if ((int)$stmt->fetchColumn() === 0) {
    toastError(
        "Benutzer nicht gefunden.",
        "Update fehlgeschlagen: Benutzer-ID {$id} existiert nicht."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// Benutzername und Rolle aktualisieren
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
    $stmt->execute([$username, $role, $id]);

    $stmt = $pdo->prepare("DELETE FROM user_zones WHERE user_id = ?");
    $stmt->execute([$id]);

    if ($role === 'zoneadmin' && is_array($zones)) {
        $insert = $pdo->prepare("INSERT INTO user_zones (user_id, zone_id) VALUES (?, ?)");
        foreach ($zones as $zone_id) {
            $zone_id = (int)$zone_id;
            if ($zone_id > 0) {
                $insert->execute([$id, $zone_id]);
            }
        }
    }

    $pdo->commit();
    toastSuccess(
        "Benutzer <strong>" . htmlspecialchars($username) . "</strong> erfolgreich aktualisiert.",
        "Benutzer '{$username}' (ID {$id}) mit Rolle '{$role}' erfolgreich geändert."
    );
} catch (Exception $e) {
    $pdo->rollBack();
    toastError(
        "Fehler beim Aktualisieren des Benutzers.",
        "Fehler beim Speichern von Benutzer '{$username}' (ID {$id}): " . $e->getMessage()
    );
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
exit;
?>
