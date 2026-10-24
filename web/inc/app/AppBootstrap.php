<?php
/**
 * Datei: inc/app/AppBootstrap.php
 * Zweck: Gemeinsamer, Web-unabhängiger Bootstrap der Application-Schicht.
 *
 * - Lädt ausschließlich die bestehende Konfiguration (config/ui_config.php); keine neuen
 *   Konfigurationsdateien oder Zugangsdaten.
 * - Erzeugt eine PDO-Verbindung mit denselben DSN-Bestandteilen und Optionen wie bisher
 *   inc/db.php (PDO::ERRMODE_EXCEPTION).
 * - Keine Session, kein HTTP, keine Redirects, keine Toasts, kein HTML, kein Logging.
 * - Keine globalen Seiteneffekte: Die Verbindung wird zurückgegeben, nicht global abgelegt.
 *
 * Verwendung durch Nicht-Web-Aufrufer (z. B. CLI, späterer MCP-Adapter):
 *   require_once .../inc/app/AppBootstrap.php;
 *   $pdo = AppBootstrap::createPdo();          // genau einmal pro Prozess bzw. Lauf
 *   $zoneAccess = new ZoneAccess($pdo);
 *   $zones = new ZoneReadService($pdo, $zoneAccess);
 *
 * Hinweis LegacyBindPublishBackend: Die Legacy-Distributoren verwenden die globale Verbindung.
 * Wer den PublishService mit diesem Backend nutzt, setzt im Einstiegspunkt ausdrücklich
 * $GLOBALS['pdo'] = $pdo (dieselbe Instanz); der Bootstrap tut das bewusst nicht.
 * Die Weboberfläche erhält ihre Verbindung unverändert über inc/db.php.
 */

final class AppBootstrap
{
    private const CONFIG_FILE = __DIR__ . '/../../config/ui_config.php';

    /**
     * Lädt die bestehende Konfiguration (einmalig).
     */
    public static function loadConfig(): void
    {
        require_once self::CONFIG_FILE;
    }

    /**
     * Erzeugt eine neue PDO-Verbindung aus der bestehenden Konfiguration.
     *
     * Jeder Aufruf erzeugt eine eigene Verbindung; der Aufrufer ist dafür verantwortlich,
     * sie einmalig zu erzeugen und an alle Services weiterzureichen.
     *
     * @throws PDOException bei fehlgeschlagenem Verbindungsaufbau (Behandlung durch den Aufrufer)
     */
    public static function createPdo(): PDO
    {
        self::loadConfig();

        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Fehler als Exception werfen
        ]);
    }
}
