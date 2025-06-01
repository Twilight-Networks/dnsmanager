<?php
/**
 * Datei: ui_config.php
 * Zweck: Zentrale Konfiguration für den DNS-Manager.
 *
 * Enthält Einstellungen für:
 * - Datenbankverbindung
 * - Basis-URL
 * - Benutzer- und Gruppenzuweisungen
 * - Externe Programme
 * - Sicherheitseinstellungen (Passwortregeln)
 * - Standard-Zeitzone
 * - Logziel
 * - Logging-Level
 * - PHP-Error-Reporting
 */

/** ---------------------------------------
 *  Datenbank-Konfiguration
 * --------------------------------------- */
define('DB_HOST', 'localhost');           // Hostname des Datenbankservers
define('DB_NAME', 'dnsmanager');           // Name der verwendeten Datenbank
define('DB_USER', 'root');                 // Datenbank-Benutzername
define('DB_PASS', 'init01');               // Datenbank-Passwort
define('DB_CHARSET', 'utf8mb4');            // Zeichensatz der Verbindung

/** ---------------------------------------
 *  Web-Anwendung Basis-URL
 * --------------------------------------- */
define('BASE_URL', '/dnsmanager-ui');         // Basis-URL zur Anwendung

/** ---------------------------------------
 *  Webserver- und Dateisystem-Einstellungen
 * --------------------------------------- */
define('WEBSERVER_USER', 'www-data');       // Webserver-User (z.B. www-data)
define('WEBSERVER_GROUP', 'www-data');      // Webserver-Group
define('EXPECTED_OWNER', 'root');           // Erwarteter Eigentümer von wichtigen Dateien/Verzeichnissen
define('EXPECTED_GROUP', 'root');           // Erwartete Gruppe

/** ---------------------------------------
 *  Pfade
 * --------------------------------------- */
define('BIND_BASE_DIR', '/etc/bind');      // Basisverzeichnis der BIND-Installation
define('NAMED_CHECKZONE', '/usr/bin/named-checkzone'); // Pfad zu named-checkconf

/** ---------------------------------------
 *  Sicherheitseinstellungen
 * --------------------------------------- */
define('PASSWORD_MIN_LENGTH', 4);           // Minimale Anzahl Zeichen für Passwörter

/** ---------------------------------------
 *  Session-Timeout
 * --------------------------------------- */
define('SESSION_TIMEOUT_SECONDS', 900); // z. B. 15 Minuten Inaktivität

/** ---------------------------------------
 *  Remote-API Konfiguration
 * --------------------------------------- */

// Basis-URL zur Remote-API
define('REMOTE_API_BASE', '/dnsmanager-agent/api/v1');

/** ---------------------------------------
 *  cURL-/Netzwerk-Einstellungen
 * --------------------------------------- */
/*
 * SSL-Verhalten bei ausgehenden cURL-Verbindungen (REST-API).
 *
 * - SSL_VERIFYPEER:
 *   true  = Zertifikat muss von einer vertrauenswürdigen CA signiert sein
 *   false = Zertifikat kann auch selbstsigniert sein (unsicherer, aber erlaubt z. B. interne APIs)
 *
 * - SSL_VERIFYHOST:
 *   2 = Hostname im Zertifikat muss exakt zum Zielserver passen
 *   0 = Keine Hostnamenprüfung (unsicher)
 */
define('CURL_SSL_VERIFYPEER', false);
define('CURL_SSL_VERIFYHOST', 0);

/** ---------------------------------------
 *  Zeitzoneneinstellung
 * --------------------------------------- */
date_default_timezone_set('Europe/Berlin'); // Standard-Zeitzone der Anwendung

/** ---------------------------------------
 *  Mail-Benachrichtigungen (Monitoring)
 * --------------------------------------- */

// Aktiviert den Mailversand bei Monitoring-Statusänderungen
define('MAILER_ENABLED', false);

// SMTP verwenden (true) oder systemweiten Mailversand via sendmail/mail() (false)
define('MAILER_USE_SMTP', true);

// SMTP-Serverdetails (nur bei MAILER_USE_SMTP = true relevant)
define('MAILER_SMTP_HOST', 'smtp.example.com');
define('MAILER_SMTP_PORT', 587);
define('MAILER_SMTP_USER', 'monitor@example.com');
define('MAILER_SMTP_PASS', 'dein_passwort');
define('MAILER_SMTP_SECURE', 'tls'); // 'tls' oder 'ssl'

// Absender- und Empfängerdaten
define('MAILER_FROM_ADDRESS', 'monitor@example.com');
define('MAILER_FROM_NAME', 'DNSManager Monitoring');
define('MAILER_TO_ADDRESS', 'admin@example.com');

/** ---------------------------------------
 *  Behaltedauer für Monitoringdaten im Log
 * --------------------------------------- */
define('MONITORING_LOG_RETENTION', '30D'); // gültig: z. B. 1H, 3D, 2W, 6M, 1Y

/** ---------------------------------------
 *  Logziel: 'apache' oder 'syslog'
 * --------------------------------------- */
define('LOG_TARGET', 'syslog'); // oder 'apache'

/** ---------------------------------------
 *  Logging-Level
 * --------------------------------------- */
define('LOG_LEVEL', 'info'); // Zulässige Werte: debug, info, warning, error

/** ---------------------------------------
 *  PHP-Error-Reporting
 * --------------------------------------- */
define('PHP_ERR_REPORT', false); // true = Entwicklungsmodus, false = Produktivmodus
?>
