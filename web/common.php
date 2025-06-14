<?php
/**
 * Datei: common.php
 * Zweck: Zentrale Initialisierung für interne Skripte des DNS Managers.
 */

// Konfiguration laden (z.B. DB-Verbindungsdaten, BASE_URL)
require_once __DIR__ . '/config/ui_config.php';

// Sprache laden
$availableLanguages = ['de', 'en', 'es'];
$defaultLanguage = defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'auto';

if ($defaultLanguage === 'auto') {
    $lang = $_GET['lang'] ?? $_SESSION['lang'] ?? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 2);
} else {
    $lang = $defaultLanguage;
}

$lang = substr($lang, 0, 2); // z. B. "de-DE" → "de"
$lang = in_array($lang, $availableLanguages, true) ? $lang : 'de';

$_SESSION['lang'] = $lang;

$langFile = __DIR__ . '/lang/' . $lang . '.php';

if (!file_exists($langFile)) {
    die("Language file not found: " . htmlspecialchars($langFile));
}

$LANG = require $langFile;

if (!isset($LANG) || !is_array($LANG)) {
    die("Language file invalid or not readable: " . htmlspecialchars($langFile));
}

// Asset-Verwaltungsklasse einbinden
require_once __DIR__ . '/inc/asset_registry.php';

// Expliziter HTTP-Header zur Zeichencodierung aller HTML-Seiten
// Wichtig: Verhindert Encoding-Probleme bei Umlauten, Toasts, dynamischen Ausgaben usw.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// PHP-Error-Reporting steuern (abhängig von Entwicklungsmodus)
if (defined('PHP_ERR_REPORT') && PHP_ERR_REPORT === true) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// Logging- und Fehlerbehandlung laden
require_once __DIR__ . '/inc/logging.php';

// Session-Management und Login-Absicherung
require_once __DIR__ . '/inc/session.php';

// NUR require_login(), wenn NICHT auf Login- oder öffentlichen Seiten
if (!defined('ALLOW_UNAUTHENTICATED') || ALLOW_UNAUTHENTICATED !== true) {
    require_login();
}

// Datenbankverbindung initialisieren
require_once __DIR__ . '/inc/db.php';

// Hilfsfunktionen (z.B. Rollenprüfungen, sichere Shell-Kommandos) laden
require_once __DIR__ . '/inc/helpers.php';

/**
 * Fügt eine Toast-Nachricht zur Session hinzu, um sie beim nächsten Seitenaufruf im Webinterface anzuzeigen.
 *
 * Diese Funktion unterstützt verschiedene Typen von Toast-Nachrichten (z. B. "success", "error", "info").
 * Die Nachrichten werden typabhängig in die jeweilige Session-Variable geschrieben:
 * - "success" → $_SESSION['toast_success'] (nur eine Nachricht)
 * - "error"   → $_SESSION['toast_errors'][] (mehrere Fehler möglich)
 * - sonst     → $_SESSION['toast_messages'][] (z. B. für Hinweise oder Warnungen)
 *
 * Hinweis: Diese Funktion wird im Rahmen des PRG-Musters (Post-Redirect-Get) verwendet.
 * Die Ausgabe erfolgt typischerweise im HTML-Template beim nächsten Seitenaufruf.
 *
 * @param string $type    Typ der Nachricht, z. B. "success", "error", "info"
 * @param string $message Die eigentliche Nachricht (HTML erlaubt, z. B. <strong>…</strong>)
 *
 * @return void
 */
function addToast(string $type, string $message): void
{
    if ($type === 'success') {
        $_SESSION['toast_success'] = $message;
    } elseif ($type === 'error') {
        $_SESSION['toast_errors'][] = $message;
    } else {
        $_SESSION['toast_messages'][] = $message;
    }
}

/**
 * Zeigt eine Toast-Nachricht im Webinterface und loggt gleichzeitig die zugehörige Nachricht ins Systemlog.
 *
 * @param string $toastType     Typ der Toast-Nachricht: 'success', 'error', 'info', etc.
 * @param string $toastMessage  Nachricht für die Benutzeroberfläche
 * @param string|null $logMessage Nachricht fürs Logging (wenn leer, wird $toastMessage verwendet)
 * @param string $logLevel      Log-Level für appLog(): 'info', 'error', 'warning', 'debug'
 *
 * @return void
 */
function toastAndLog(string $toastType, string $toastMessage, ?string $logMessage = null, string $logLevel = 'info'): void
{
    addToast($toastType, $toastMessage);

    if ($logMessage === null) {
        $logMessage = $toastMessage;
    }

    appLog($logLevel, $logMessage);
}

/**
 * Zeigt eine Fehlermeldung im Webinterface (Toast) und loggt sie mit Log-Level "error".
 *
 * @param string $toastMessage  Nachricht für die Benutzeroberfläche
 * @param string|null $logMessage Nachricht für das Log (optional, default = Toast-Text)
 *
 * @return void
 */
function toastError(string $toastMessage, ?string $logMessage = null): void
{
    toastAndLog('error', $toastMessage, $logMessage, 'error');
}

/**
 * Zeigt eine Erfolgsmeldung im Webinterface (Toast) und loggt sie mit Log-Level "info".
 *
 * @param string $toastMessage  Nachricht für die Benutzeroberfläche
 * @param string|null $logMessage Nachricht für das Log (optional, default = Toast-Text)
 *
 * @return void
 */
function toastSuccess(string $toastMessage, ?string $logMessage = null): void
{
    toastAndLog('success', $toastMessage, $logMessage, 'info');
}

/**
 * Zeigt eine Warnmeldung im Webinterface (Toast) und loggt sie mit Log-Level "warning".
 *
 * @param string $toastMessage  Nachricht für die Benutzeroberfläche
 * @param string|null $logMessage Nachricht für das Log (optional, default = Toast-Text)
 *
 * @return void
 */
function toastWarning(string $toastMessage, ?string $logMessage = null): void
{
    toastAndLog('warning', $toastMessage, $logMessage, 'warning');
}

// Hinweis:
// - In dieser Datei KEINE eigenen HTML-Ausgaben!
// - KEINE zusätzlichen Weiterleitungen außer require_login()!
// - Diese Datei wird auf *jeder* internen Seite ganz oben eingebunden.
?>
