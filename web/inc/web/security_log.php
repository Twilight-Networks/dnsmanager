<?php
/**
 * Datei: inc/web/security_log.php
 * Zweck: Web-Adapter für Sicherheitsprotokolle (Paket 5d).
 *
 * Ermittelt Benutzer (Session) und Quell-IP (Request) und übergibt sie ausdrücklich an die
 * transportunabhängigen Logging-Funktionen in inc/logging.php. Funktionsnamen, Parameter und Logformat der
 * bisherigen Helfer logAccessDenied()/logFailedLogin() sind unverändert; die Aufrufer (Actions, Seiten,
 * requireRole, Login, DynDNS-Endpunkt) bleiben gleich.
 *
 * Abhängigkeiten: inc/logging.php (geladen über common.php).
 */

/**
 * Protokolliert einen verweigerten Zugriff des aktuellen Web-Benutzers.
 *
 * @param string $context Kurze Angabe der Quelle (z.B. 'requireRole', 'login').
 * @param string $reason  Detaillierte Fehlerbeschreibung.
 *
 * @return void
 */
function logAccessDenied(string $context, string $reason): void
{
    logAccessDeniedEvent(
        $context,
        (string)($_SESSION['username'] ?? 'unknown'),
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        $reason
    );
}

/**
 * Protokolliert einen fehlgeschlagenen Loginversuch der aktuellen Web-Anfrage.
 *
 * @param string $username Der eingegebene Benutzername.
 *
 * @return void
 */
function logFailedLogin(string $username): void
{
    logLoginFailureEvent($username, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
