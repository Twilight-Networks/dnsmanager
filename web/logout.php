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
 * - S35 (SP2-E5): Abmeldung nur per POST mit gültigem CSRF-Token. GET → HTTP 405 (Allow: POST) ohne Session-Zugriff;
 *   POST mit fehlendem/ungültigem Token bei bestehender Anmeldung → HTTP 403 ohne Abmeldung; ohne Anmeldung →
 *   Weiterleitung zum Login.
 */

// S35: Nur POST. Prüfung vor dem Session-Start, damit eine bestehende Session nicht verändert wird (auch nicht
// last_activity).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    exit('Nur POST erlaubt');
}

// Definieren, dass diese Seite ohne Login erreichbar ist
define('ALLOW_UNAUTHENTICATED', true);

// Zentrale Initialisierung einbinden (inkl. session.php etc.)
require_once __DIR__ . '/common.php';

// Keine Anmeldung (mehr) vorhanden: nichts abzumelden → Login
if (!isset($_SESSION['user_id'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: login.php');
    exit;
}

// Bestehende Anmeldung: nur mit gültigem CSRF-Token abmelden (sonst 403, Session unverändert)
verify_csrf_token();

// Alle Session-Daten entfernen
$_SESSION = [];

// Session-Cookie im Browser löschen (explizit) – mit denselben Attributen wie beim Setzen (SP2-E4)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
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
