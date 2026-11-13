<?php
/**
 * Datei: db.php
 * Zweck: Aufbau der PDO-Datenbankverbindung für den DNS-Manager.
 *
 * Details:
 * - Lädt die zentrale Konfiguration (DB-Zugangsdaten aus ui_config.php).
 * - Stellt eine PDO-Verbindung zur MariaDB/MySQL-Datenbank her (über AppBootstrap::createPdo()).
 * - Stellt die Verbindung als globale $pdo / $GLOBALS['pdo'] für den bestehenden Web-Code bereit.
 * - Fehler im Verbindungsaufbau führen zum sofortigen Abbruch mit einer sicheren Fehlermeldung.
 *
 * Hinweise:
 * - Verwendet UTF-8 (utf8mb4) als Zeichensatz.
 * - PDO im Fehlerfall auf "Exception Mode" gesetzt (sicheres Fehlerhandling).
 *
 * Zugriff: Wird von allen Komponenten benötigt, die auf die Datenbank zugreifen.
 */

// Konfiguration laden
require_once __DIR__ . '/../config/ui_config.php';

// Gemeinsamer Verbindungsaufbau der Application-Schicht (gleiche DSN und Optionen wie bisher)
require_once __DIR__ . '/app/AppBootstrap.php';

try {
    // PDO-Instanz mit Fehlerbehandlung initialisieren
    $pdo = AppBootstrap::createPdo();
} catch (PDOException $e) {
    // Verbindung fehlgeschlagen – sicherer Abbruch (S37b): serverseitig nur eine feste Meldung mit SQLSTATE
    // protokollieren (kein Exception-Text, damit keine Zugangsdaten); Antwort generisch, Web HTTP 503, CLI Exit-Code 1.
    error_log('[DNSMANAGER] Datenbankverbindung fehlgeschlagen (SQLSTATE ' . (string)$e->getCode() . ')');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Datenbankverbindung fehlgeschlagen.\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(503);
    }
    exit('Datenbankverbindung fehlgeschlagen.');
}
$GLOBALS['pdo'] = $pdo;
?>
