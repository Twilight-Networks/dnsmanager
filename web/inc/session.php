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
 * - Session-Fixation und XSS-Schutz durch passende `ini_set()`-Konfiguration; Cookie mit SameSite=Lax (SP2).
 * - Absolute Höchstdauer der Anmeldung (SESSION_MAX_LIFETIME_SECONDS, Standard 12 h) zusätzlich zum
 *   Inaktivitäts-Timeout (SESSION_TIMEOUT_SECONDS); bei Ablauf neue anonyme Session-ID (SP2).
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
// S40 (S40-E2): Secure verbindlich, unabhängig von $_SERVER['HTTPS'] – das Session-Cookie wird nie über
// unverschlüsseltes HTTP gesendet. Gilt für alle Pfade, die Session-Cookies setzen oder löschen (session_start,
// session_regenerate_id, Logout über session_get_cookie_params()). Voraussetzung: Betrieb ausschließlich über HTTPS.
// Cookie-Name und Path=/ unverändert; kein __Host-Präfix (vorerst).
ini_set('session.cookie_secure', '1');
ini_set('session.use_only_cookies', '1');
// S28 (SP2-E4): SameSite=Lax für das Session-Cookie (vor session_start); gilt für jedes von PHP gesetzte
// Session-Cookie (Login, Erneuerung, Ablauf, Widerruf). Pfad, Domain und Secure bleiben unverändert (SP2-E8).
ini_set('session.cookie_samesite', 'Lax');
session_name('twl_dnsmgr_sid'); // eindeutiger Session-Name

// S29 (SP1): Dynamische Seiten mit Session nicht im Browser-/Proxy-Cache ablegen – ausdrücklich gesetzt statt
// abhängig von php.ini (session.cache_limiter). „nocache“ sendet beim session_start()
// „Cache-Control: no-store, no-cache, must-revalidate“, „Pragma: no-cache“ und „Expires“ in der Vergangenheit
// (bisheriger php.ini-Standard). Statische Assets laufen nicht über PHP und bleiben unverändert.
session_cache_limiter('nocache');

ob_start(); // Absicherung gegen vorzeitige Ausgabe
session_start(); // Session starten

/**
 * Beendet eine abgelaufene Anmeldung (absolute Höchstdauer oder Inaktivität): bisherige Session vollständig
 * verwerfen, neue anonyme Session mit frischer ID, ausschließlich Hinweis „session_expired“, Weiterleitung zum Login.
 * Die alte Session-ID ist danach ungültig (Daten gelöscht, use_strict_mode). session_epoch bleibt unberührt.
 *
 * @return never
 */
function session_expire_and_redirect(): never
{
    $_SESSION = [];
    session_unset();
    session_destroy();
    session_start();               // neue Session, um den Hinweis setzen zu können
    session_regenerate_id(true);   // SP2-E6: ausdrücklich frische anonyme ID (nicht nur über use_strict_mode)
    $_SESSION = ['session_expired' => true];
    header("Location: " . rtrim(BASE_URL, '/') . "/login.php");
    exit;
}

// S28 (SP2-E1/E2/E3): absolute Höchstdauer einer Anmeldung, gemessen ab login_time (gesetzt in login_user(), während
// der Sitzung nie verlängert). Unabhängig vom Inaktivitäts-Timeout und von session_epoch; geprüft vor jeder
// Fachoperation (session.php läuft vor require_login und der Revalidierung). Eine angemeldete Session ohne gültigen
// login_time (z. B. aus der Zeit vor SP2) gilt als abgelaufen; anonyme Sessions sind nicht betroffen.
// Protokoll (Info, nicht Fail2Ban-relevant): event=session_expired reason=max_lifetime | login_time_missing.
$maxLifetime = defined('SESSION_MAX_LIFETIME_SECONDS') ? (int)SESSION_MAX_LIFETIME_SECONDS : 43200;
if (isset($_SESSION['user_id'])) {
    $loginTime = $_SESSION['login_time'] ?? null;
    $expiredReason = null;
    if (!is_int($loginTime)) {
        $expiredReason = 'login_time_missing';
    } elseif (time() - $loginTime >= $maxLifetime) {
        $expiredReason = 'max_lifetime';
    }
    if ($expiredReason !== null) {
        if (function_exists('appLog')) {
            appLog('info', sprintf(
                'event=session_expired reason=%s user=%s ip=%s',
                $expiredReason,
                (string)($_SESSION['username'] ?? 'unknown'),
                (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            ));
        }
        session_expire_and_redirect();
    }
}

// Inaktivitätsgrenze für Session-Timeout (konfigurierbar über ui_config.php); ohne zusätzliches Protokoll (SP2-E3)
$timeout = SESSION_TIMEOUT_SECONDS;

if (isset($_SESSION['user_id'], $_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_expire_and_redirect();
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
