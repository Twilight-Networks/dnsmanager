#!/usr/bin/php
<?php
/**
 * Datei: monitoring_run-cli.php
 * Zweck: Führt alle Diagnosetests für Server- und Zonenstatus durch und schreibt Ergebnisse in die Datenbank.
 *
 * Details:
 * - CLI-Adapter (Paket 5b): Die Fachlogik liegt im MonitoringService (inc/app/monitoring); Aufbau, Ausführung
 *   und Logzeilen in runMonitoringWithLog() (inc/monitoring_runner.php), gemeinsam mit der manuellen
 *   Aktualisierung der Systemstatus-Seite.
 * - Für alle aktiven Server und deren zugewiesene Zonen:
 *   - Statusabfrage des Remote-BIND-Dienstes
 *   - Prüfung der Konfigurationsdateien (named-checkconf)
 *   - Prüfung der Zonendateien (named-checkzone)
 * - Ergebnisse werden in `diagnostics` gespeichert, Änderungen zusätzlich in `diagnostic_log` protokolliert.
 * - Läuft bereits ein Monitoring-Lauf (Laufsperre), wird dieser Lauf übersprungen (Exit-Code 0).
 *
 * Ausführung:
 * - Nur CLI, nicht über Web aufrufbar (cliGuard vor dem Laden von Konfiguration und Datenbank).
 */

require_once __DIR__ . '/../inc/helpers_cli.php';
cliGuard();
require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';
require_once __DIR__ . '/../inc/monitoring_runner.php';

date_default_timezone_set('UTC');

// PDO-Instanz bereitstellen
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    appLog('error', 'PDO nicht verfügbar – Monitoring-Run abgebrochen');
    exit(1);
}

$run = runMonitoringWithLog($GLOBALS['pdo'], 0);

// Ein bereits laufender Monitoring-Lauf ist für den Cron kein Fehler
exit($run['status'] === 'locked' ? 0 : $run['exitCode']);
