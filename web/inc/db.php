<?php
/**
 * Datei: db.php
 * Zweck: Aufbau der PDO-Datenbankverbindung für den DNS-Manager.
 *
 * Details:
 * - Lädt die zentrale Konfiguration (DB-Zugangsdaten aus ui_config.php).
 * - Stellt eine PDO-Verbindung zur MariaDB/MySQL-Datenbank her.
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

// DSN (Data Source Name) für PDO erstellen
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

try {
    // PDO-Instanz mit Fehlerbehandlung initialisieren
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Fehler als Exception werfen
    ]);
} catch (PDOException $e) {
    // Verbindung fehlgeschlagen – sicherer Abbruch
    die("Datenbankverbindung fehlgeschlagen: " . htmlspecialchars($e->getMessage()));
}
$GLOBALS['pdo'] = $pdo;
?>
