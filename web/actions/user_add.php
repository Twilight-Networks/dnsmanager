<?php
/**
 * Datei: user_add.php
 * Zweck: Neuen Benutzer anlegen.
 *
 * Details:
 * - Nur Admins dürfen neue Benutzer erstellen.
 * - Der Benutzername wird geprüft, Passwort wird sicher gehasht.
 * - Benutzerrolle kann 'admin' oder 'zoneadmin' sein.
 * - Bei Zone-Admins können individuelle Zonen zugewiesen werden.
 * - Erfolgreiche Anlage wird als Session-Toast gemeldet.
 *
 * Zugriff:
 * - Nur Admins (requireRole(['admin'])).
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);

// Eingaben aus dem POST-Request lesen und trimmen
$username = trim($_POST['username']);
$password_raw = $_POST['password'];
$role = $_POST['role'] === 'admin' ? 'admin' : 'zoneadmin';
$zones = isset($_POST['zones']) ? $_POST['zones'] : [];

// Benutzername validieren
if (!preg_match('/^[a-zA-Z0-9_\-\.@]+$/', $username)) {
    toastError(
        $LANG['user_error_invalid_username'],
        "Benutzeranlage fehlgeschlagen: ungültiger Benutzername '{$username}'."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// Passwort validieren über zentrale Helferfunktion
if (!validatePassword($password_raw)) {
    toastError(
        sprintf($LANG['error_password_too_short'], PASSWORD_MIN_LENGTH),
        "Benutzeranlage fehlgeschlagen: Passwort für '{$username}' zu kurz."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// Prüfen, ob Benutzername bereits existiert
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetchColumn() > 0) {
    toastError(
        $LANG['user_error_username_exists'],
        "Benutzeranlage fehlgeschlagen: Benutzername '{$username}' existiert bereits."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// Passwort sicher hashen
$password_hash = password_hash($password_raw, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // Benutzer anlegen
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute([$username, $password_hash, $role]);

    $user_id = (int)$pdo->lastInsertId();
    if ($user_id < 1) {
        throw new Exception("Benutzer-ID konnte nicht ermittelt werden.");
    }

    // Zone-Zuweisung bei Zoneadmins
    if ($role === 'zoneadmin') {
        $insert = $pdo->prepare("INSERT INTO user_zones (user_id, zone_id) VALUES (?, ?)");

        if (in_array('all', $zones, true)) {
            $zone_stmt = $pdo->prepare("SELECT id FROM zones");
            $zone_stmt->execute();
            $zone_ids = $zone_stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($zone_ids as $zone_id) {
                $insert->execute([$user_id, (int)$zone_id]);
            }
        } else {
            foreach ($zones as $zone_id) {
                $zone_id = (int)$zone_id;
                if ($zone_id > 0) {
                    $insert->execute([$user_id, $zone_id]);
                }
            }
        }
    }

    $pdo->commit();
    toastSuccess(
        sprintf($LANG['user_created'], htmlspecialchars($username)),
        "Benutzer '{$username}' mit Rolle '{$role}' erfolgreich erstellt."
    );
} catch (Throwable $e) {
    $pdo->rollBack();
    toastError(
        $LANG['user_error_create_failed'],
        "Transaktion fehlgeschlagen bei Benutzeranlage '{$username}': " . $e->getMessage()
    );
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
exit;
?>
