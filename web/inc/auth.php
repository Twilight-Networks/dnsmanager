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
 * - Nur aktive Konten (users.active = 1) werden angemeldet (S27). password_verify läuft auch für
 *   deaktivierte Konten; ein deaktiviertes Konto erhält dieselbe Rückmeldung und dieselbe
 *   Protokollzeile (event=login_failure, Fail2Ban) wie ein falsches Passwort – keine Kontoaufzählung.
 *   Nur bei korrektem Passwort eines deaktivierten Kontos folgt zusätzlich event=login_rejected_inactive
 *   (nicht von der Fail2Ban-Regel erfasst), damit Administratoren den Grund erkennen.
 * - Die Session erhält die aktuelle Sitzungsgeneration (session_epoch, S26); die Revalidierung
 *   (inc/web/session_guard.php) widerruft Sessions mit fehlender oder abweichender Generation.
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

    $stmt = $pdo->prepare("SELECT id, password_hash, role, active, session_epoch FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $passwordOk = $user && password_verify($password, $user['password_hash']);

    if ($passwordOk && (int)$user['active'] === 1) {
        // Session-Fixation verhindern
        session_regenerate_id(true);

        // Login erfolgreich: Session-Daten setzen (inkl. Sitzungsgeneration)
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $username;
        $_SESSION['session_epoch'] = (string)$user['session_epoch'];
        return true;
    }

    if ($passwordOk) {
        // Korrektes Passwort, Konto deaktiviert: Zusatzhinweis für Administratoren (nicht Fail2Ban)
        appLog('error', sprintf(
            'event=login_rejected_inactive user=%s ip=%s',
            $username,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ), true);
    }

    // Login fehlgeschlagen: Protokollieren
    logFailedLogin($username);
    return false;
}
?>

