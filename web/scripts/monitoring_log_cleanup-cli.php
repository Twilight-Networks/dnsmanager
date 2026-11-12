#!/usr/bin/php
<?php
/**
 * Datei: monitoring_log_cleanup-cli.php
 * Zweck: Entfernt veraltete Einträge aus der Tabelle `diagnostic_log` entsprechend der konfigurierten Aufbewahrungsfrist.
 *
 * Details:
 * - CLI-Adapter (Paket 5b): Die Fachlogik liegt in MonitoringService::purgeLog() (inc/app/monitoring).
 * - Die Aufbewahrungsfrist wird über die Konstante MONITORING_LOG_RETENTION gesteuert (z. B. '30D', '12M', '1Y');
 *   ohne Konstante gilt '30D'.
 * - Nur CLI-Aufruf erlaubt (cliGuard vor dem Laden von Konfiguration und Datenbank).
 * - Bei ungültiger Frist wird ein Fehlerprotokoll geschrieben.
 * - Erfolgreiche Löschoperationen werden per AppLog ausgegeben.
 *
 * Konfiguration:
 * - Konstante MONITORING_LOG_RETENTION aus ui_config.php (z. B. '90D', '1Y', '72H')
 */

require_once __DIR__ . '/../inc/helpers_cli.php';
cliGuard();
require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';
require_once __DIR__ . '/../inc/app/monitoring/MonitoringService.php';
require_once __DIR__ . '/../inc/deploy/AgentMonitoringBackend.php';
require_once __DIR__ . '/../inc/deploy/PhpMailerNotifier.php';

date_default_timezone_set('UTC');

appLog('info', 'Monitoring-Log-Cleanup gestartet');

// Datenbank prüfen
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    appLog('error', 'PDO nicht verfügbar – Abbruch');
    exit(1);
}

$retention = defined('MONITORING_LOG_RETENTION') ? (string)MONITORING_LOG_RETENTION : '30D';
$service = new MonitoringService($GLOBALS['pdo'], new AgentMonitoringBackend(), new PhpMailerNotifier(), false);
$result = $service->purgeLog($retention);
$message = $result->messages[0];
$ctx = $message->context;

switch ($message->code) {
    case 'monitoring.log_cleanup.success':
        appLog('info', "{$ctx['count']} veraltete Einträge aus diagnostic_log entfernt (älter als {$ctx['threshold']})");
        exit(0);

    case 'monitoring.log_cleanup.invalid_retention':
        $msg = "Ungültige Aufbewahrungsfrist: '{$ctx['retention']}'";
        appLog('error', $msg);
        echo "[Fehler] $msg\n";
        exit(1);

    case 'monitoring.log_cleanup.failed':
        appLog('error', "Fehler bei der Bereinigung: " . $ctx['detail']);
        exit(1);

    default:
        appLog('error', "Unbehandelte ServiceMessage '{$message->code}' (monitoring log cleanup)");
        exit(1);
}
