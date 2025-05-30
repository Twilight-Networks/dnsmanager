<?php
/**
 * Datei: logging.php
 * Zweck: Zentrale Logging-Funktionen und globale PHP-Fehlerbehandlung.
 *
 * Details:
 * - Fehlerbehandlung für PHP-Fehler (Error Handler, Shutdown Handler)
 * - Logging nach definiertem Log-Level (debug, info, warning, error)
 * - Ausgabe wahlweise an Apache-Error-Log oder Syslog
 */

// Logging-System Basisfunktionen (appLog, mapErrorToLogLevel, set_error_handler, shutdown_handler)

/**
 * Mappt PHP-Fehlernummern auf interne Log-Levels.
 */
function mapErrorToLogLevel(int $errno): string
{
    switch ($errno) {
        case E_NOTICE:
        case E_USER_NOTICE:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            return 'info';
        case E_WARNING:
        case E_USER_WARNING:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
            return 'warning';
        case E_ERROR:
        case E_USER_ERROR:
        case E_PARSE:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_RECOVERABLE_ERROR:
        default:
            return 'error';
    }
}

/**
 * Protokolliert eine Nachricht anhand des angegebenen Log-Levels.
 *
 * @param string $level  Log-Level: debug, info, warning, error
 * @param string $message Die zu protokollierende Nachricht (bereits maschinenlesbar aufgebaut)
 * @param bool $forceSyslog Immer zusätzlich ins Syslog schreiben (z.B. für sicherheitsrelevante Ereignisse)
 *
 * @return void
 */
function appLog(string $level, string $message, bool $forceSyslog = false): void
{
    static $logLevels = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3
    ];

    $configuredLevel = 0; // Default
    if (defined('LOG_LEVEL') && isset($logLevels[LOG_LEVEL])) {
        $configuredLevel = $logLevels[LOG_LEVEL];
    } else {
        static $warned = false;
        if (!$warned) {
            error_log('[DNSMANAGER] [LOGGING] Invalid LOG_LEVEL in ui_config.php. Falling back to DEBUG.');
            $warned = true;
        }
    }

    if (!isset($logLevels[$level])) {
        return;
    }

    if ($logLevels[$level] < $configuredLevel) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = sprintf(
        '[DNSMANAGER] [%s] time="%s" %s',
        strtoupper($level),
        $timestamp,
        $message
    );

    // Logging-Ziel bestimmen
    $useSyslog = defined('LOG_TARGET') && strtolower(LOG_TARGET) === 'syslog';
    $syslogFacility = LOG_USER;

    // Sicherheitsrelevante Logs in LOG_AUTH (z. B. Login, Access Denied)
    if ($forceSyslog || str_contains($message, 'event=login_failure') || str_contains($message, 'event=access_denied')) {
        $syslogFacility = LOG_AUTH;
    }

    if ($useSyslog) {
        openlog('dnsmanager', LOG_PID, $syslogFacility);
        syslog(LOG_WARNING, $formattedMessage);
        closelog();
    } else {
        error_log($formattedMessage);
    }
}

/**
 * Globaler PHP-Error-Handler.
 */
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    $level = mapErrorToLogLevel($errno);
    $message = sprintf(
        'event=php_error errno=%d message="%s" file="%s" line=%d',
        $errno,
        addslashes($errstr),
        addslashes($errfile),
        $errline
    );

    appLog($level, $message);
    return true;
});

/**
 * Shutdown-Handler zum Abfangen fataler Fehler.
 */
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $level = mapErrorToLogLevel($error['type']);
        $message = sprintf(
            'event=php_fatal_error errno=%d message="%s" file="%s" line=%d',
            $error['type'],
            addslashes($error['message']),
            addslashes($error['file']),
            $error['line']
        );

        appLog($level, $message);
    }
});

// Spezifische Anwendungs-Logging-Funktionen

/**
 * Protokolliert einen verweigerten Zugriff über die zentrale Logging-Architektur.
 *
 * @param string $context Kurze Angabe der Quelle (z.B. 'requireRole', 'login').
 * @param string $reason Detaillierte Fehlerbeschreibung.
 *
 * @return void
 */
function logAccessDenied(string $context, string $reason): void
{
    $username = $_SESSION['username'] ?? 'unknown';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $message = sprintf(
        'event=access_denied context=%s user=%s ip=%s reason="%s"',
        $context,
        $username,
        $ipAddress,
        addslashes($reason)
    );

    appLog('error', $message, true); // forceSyslog=true für Sicherheitslogs
}

/**
 * Protokolliert einen fehlgeschlagenen Loginversuch über die zentrale Logging-Architektur.
 *
 * @param string $username Der eingegebene Benutzername.
 *
 * @return void
 */
function logFailedLogin(string $username): void
{
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $message = sprintf(
        'event=login_failure user=%s ip=%s',
        $username,
        $ipAddress
    );

    appLog('error', $message, true); // forceSyslog=true für Sicherheitslogs
}
?>
