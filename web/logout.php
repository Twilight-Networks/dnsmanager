<?php
/**
 * Datei: logout.php
 * Zweck: Beendet die aktuelle Benutzersitzung und leitet zur Login-Seite weiter.
 *
 * Details:
 * - Sessiondaten und Login-Marker werden gelöscht.
 * - Das Session-Cookie im Browser wird explizit invalidiert.
 * - Die Session wird serverseitig zerstört.
 * - Browser-Cache wird deaktiviert.
 * - Benutzer wird zuverlässig abgemeldet und weitergeleitet.
 *
 * Sicherheit:
 * - Verhindert erneuten Zugriff über alte Cookies oder geöffnete Tabs.
 * - Verhindert Zugriff auf gecachte Seiten nach Logout.
 * - Gewährleistet vollständige Abmeldung mit sauberem Session-Ende.
 */

// Definieren, dass diese Seite ohne Login erreichbar ist
define('ALLOW_UNAUTHENTICATED', true);

// Zentrale Initialisierung einbinden (inkl. session.php etc.)
require_once __DIR__ . '/common.php';

// Alle Session-Daten entfernen
$_SESSION = [];

// Session-Cookie im Browser löschen (explizit)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Serverseitige Session zerstören
session_destroy();

// Neue (leere) Session starten, um Logout-Marker zu setzen
session_start();
session_regenerate_id(true);
$_SESSION['logout_success'] = true;

// Cache deaktivieren
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Weiterleitung
header('Location: login.php');
exit;
