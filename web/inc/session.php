<?php
/**
 * Datei: session.php
 * Zweck: Initialisierung und Verwaltung der Session sowie Zugriffsschutz im DNS-Manager.
 *
 * Details:
 * - Startet die PHP-Session mit sicherheitsoptimierten Einstellungen.
 * - Prüft auf gültige Konfiguration (`BASE_URL`) und verhindert direkten Zugriff.
 * - Initialisiert einen CSRF-Token für POST-Formulare.
 * - Stellt Hilfsfunktionen bereit:
 *     - `is_logged_in()` zur Statusprüfung.
 *     - `require_login()` zur Durchsetzung des Logins.
 *     - `verify_csrf_token()` zum Schutz gegen CSRF-Angriffe bei POST-Requests.
 *
 * Sicherheit:
 * - Session-Fixation und XSS-Schutz durch passende `ini_set()`-Konfiguration.
 * - CSRF-Schutz durch Token-Überprüfung bei POST-Anfragen.
 * - Direkter Zugriff auf diese Datei ist unterbunden.
 *
 * Einbindung:
 * - Wird von `common.php` geladen und steht somit in allen geschützten Bereichen zur Verfügung.
 */

// Zentrale Konfigurationsdatei laden
require_once __DIR__ . '/../config/ui_config.php';

// Abbruch, falls die grundlegende Konstante nicht definiert ist
if (!defined('BASE_URL')) {
    die('Fehler: BASE_URL ist nicht definiert. Bitte ui_config.php prüfen.');
}

// Session-bezogene Sicherheitseinstellungen setzen (vor session_start!)
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.use_only_cookies', '1');
session_name('twl_dnsmgr_sid'); // eindeutiger Session-Name

ob_start(); // Absicherung gegen vorzeitige Ausgabe
session_start(); // Session starten

// Inaktivitätsgrenze für Session-Timeout (konfigurierbar über ui_config.php)
$timeout = SESSION_TIMEOUT_SECONDS;

if (isset($_SESSION['user_id'], $_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    session_start(); // neue leere Session starten, um Marker setzen zu können
    $_SESSION['session_expired'] = true;
    header("Location: " . rtrim(BASE_URL, '/') . "/login.php");
    exit;
}

// Zeitstempel der letzten Aktivität aktualisieren
$_SESSION['last_activity'] = time();

// Direktzugriff auf die Datei blockieren
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

// CSRF-Token initialisieren, falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Führt die Validierung des CSRF-Tokens bei POST-Anfragen durch.
 *
 * - Vergleicht das übermittelte Token (`$_POST['csrf_token']`) mit dem in der Session gespeicherten Wert.
 * - Bricht bei fehlendem oder ungültigem Token mit HTTP 403 ab.
 *
 * @return void
 */
function verify_csrf_token(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            http_response_code(403);
            exit('Ungültiger CSRF-Token.');
        }
    }
}

/**
 * Prüft, ob ein Benutzer aktuell eingeloggt ist.
 *
 * @return bool True, wenn die Session eine gültige Benutzer-ID enthält; andernfalls false.
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Erzwingt die Anmeldung eines Benutzers.
 *
 * - Bei fehlender Authentifizierung wird der Zugriff blockiert
 *   und eine Weiterleitung zur Login-Seite (`login.php`) ausgelöst.
 *
 * @return void
 */
function require_login(): void {
    if (!is_logged_in()) {
        header("Location: " . rtrim(BASE_URL, '/') . "/login.php");
        exit;
    }
}
?>
