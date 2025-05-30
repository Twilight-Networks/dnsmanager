<?php
/**
 * Datei: auth.php
 * Zweck: Verwaltung der Benutzeranmeldung (Login) für den DNS-Manager.
 *
 * Details:
 * - Verifiziert Benutzername und Passwort gegen die Datenbankeinträge.
 * - Setzt bei erfolgreicher Authentifizierung die Session-Variablen.
 * - Loggt fehlgeschlagene Loginversuche für spätere Auswertung (z.B. Fail2Ban).
 *
 * Sicherheit:
 * - Passwort-Hashes werden sicher mit password_verify geprüft.
 * - Fehlgeschlagene Logins werden mit IP-Adresse ins Server-Error-Log geschrieben.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php'; // Sicherstellen, dass logFailedLogin verfügbar ist

/**
 * Versucht, einen Benutzer anhand der Anmeldedaten einzuloggen.
 *
 * @param string $username Der eingegebene Benutzername.
 * @param string $password Das eingegebene Passwort.
 *
 * @return bool True bei erfolgreichem Login, andernfalls False.
 */
function login_user(string $username, string $password): bool
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT id, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Session-Fixation verhindern
        session_regenerate_id(true);

        // Login erfolgreich: Session-Daten setzen
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $username;
        return true;
    }

    // Login fehlgeschlagen: Protokollieren
    logFailedLogin($username);
    return false;
}
?>

