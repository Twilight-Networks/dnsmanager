<?php
/**
 * Datei: auth.php
 * Zweck: Web-Adapter der Benutzeranmeldung (Login) für den DNS-Manager.
 *
 * Details:
 * - Die fachliche Prüfung von Benutzername, Passwort und Kontostatus erfolgt im webunabhängigen
 *   AuthService (inc/app/auth/AuthService.php, Paket 5c); Abfrage und Prüfregeln unverändert.
 * - Setzt bei erfolgreicher Authentifizierung die Session-Variablen (Session-Regeneration wie bisher).
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
require_once __DIR__ . '/app/auth/AuthService.php';

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

    // Fachliche Prüfung (Zugangsdaten, Kontostatus) im Core; hier nur Session und Protokoll (Paket 5c)
    $result = (new AuthService($pdo))->authenticate($username, $password);

    foreach ($result->messages as $message) {
        $ctx = $message->context;
        switch ($message->code) {
            case 'auth.login.success':
                // Session-Fixation verhindern
                session_regenerate_id(true);

                // Login erfolgreich: Session-Daten setzen (inkl. Sitzungsgeneration)
                $_SESSION['user_id'] = $ctx['userId'];
                $_SESSION['role'] = $ctx['role'];
                $_SESSION['username'] = $ctx['username'];
                $_SESSION['session_epoch'] = $ctx['sessionEpoch'];
                return true;

            case 'auth.login.inactive':
                // Korrektes Passwort, Konto deaktiviert: Zusatzhinweis für Administratoren (nicht Fail2Ban)
                appLog('error', sprintf(
                    'event=login_rejected_inactive user=%s ip=%s',
                    $username,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ), true);
                break;

            case 'auth.login.failed':
                break;

            default:
                // Unbekannte ServiceMessages nie still verwerfen; Anmeldung gilt als fehlgeschlagen
                appLog('error', "Unbehandelte ServiceMessage '{$message->code}' (auth)");
                break;
        }
    }

    // Login fehlgeschlagen: Protokollieren
    logFailedLogin($username);
    return false;
}
?>

