<?php
/**
 * Datei: inc/monitoring_runner.php
 * Zweck: Gemeinsamer Adapter für den Monitoring-Lauf aus dem CLI-Skript (Cron) und der manuellen
 *        Aktualisierung auf der Systemstatus-Seite (bisher exec des CLI-Skripts, S37).
 *
 * Baut den MonitoringService mit den Infrastruktur-Adaptern (AgentMonitoringBackend, PhpMailerNotifier) auf,
 * führt ihn aus und schreibt die bisherigen Logzeilen. Rückgabe wie bisher der Exit-Code des CLI-Skripts:
 * 0 = erfolgreich, 1 = Fehler (E-Mail-Versand fehlerhaft, Laufsperre nicht verfügbar).
 * Ein bereits laufender Lauf (Laufsperre belegt) ergibt 'locked' (Cron: übersprungen).
 *
 * Voraussetzung: Konfiguration (ui_config.php) und logging.php sind geladen.
 */

require_once __DIR__ . '/app/monitoring/MonitoringService.php';
require_once __DIR__ . '/deploy/AgentMonitoringBackend.php';
require_once __DIR__ . '/deploy/PhpMailerNotifier.php';

/**
 * @param int $lockWaitSeconds Wartezeit auf einen laufenden Monitoring-Lauf (Cron 0, manuelle Aktualisierung > 0)
 * @return array{exitCode: int, status: string}
 */
function runMonitoringWithLog(PDO $pdo, int $lockWaitSeconds): array
{
    $logger = static function (string $level, string $message): void {
        appLog($level, $message);
    };

    appLog('info', 'Monitoring-Run gestartet');

    $service = new MonitoringService(
        $pdo,
        new AgentMonitoringBackend(),
        new PhpMailerNotifier($logger),
        defined('MAILER_ENABLED') && MAILER_ENABLED === true
    );
    $result = $service->run($lockWaitSeconds);

    $status = 'error';
    foreach ($result->messages as $message) {
        $ctx = $message->context;
        switch ($message->code) {
            case 'monitoring.server_check_failed':
                appLog('error', "Fehler bei Diagnose für Server ID {$ctx['serverId']}: {$ctx['detail']}");
                break;
            case 'monitoring.zone_check_failed':
                appLog('error', "Fehler bei Diagnose für Zone {$ctx['zoneName']} auf Server ID {$ctx['serverId']}: {$ctx['detail']}");
                break;
            case 'monitoring.mail_failed':
                foreach ($ctx['errors'] as $error) {
                    appLog('error', "Fehler beim Mailversand: $error");
                }
                break;
            case 'monitoring.lock_failed':
                appLog('error', 'Monitoring-Run: Laufsperre nicht verfügbar: ' . $ctx['detail']);
                break;
            case 'monitoring.result':
                $status = (string)$ctx['status'];
                break;
            default:
                // Unbekannte ServiceMessages nie still verwerfen
                appLog('error', "Unbehandelte ServiceMessage '{$message->code}' (monitoring)");
                break;
        }
    }

    switch ($status) {
        case 'ok':
            appLog('info', 'Monitoring-Run erfolgreich beendet');
            return ['exitCode' => 0, 'status' => $status];
        case 'mail_error':
            appLog('error', 'Monitoring-Run beendet – aber E-Mail-Versand fehlerhaft');
            return ['exitCode' => 1, 'status' => $status];
        case 'locked':
            appLog('info', 'Monitoring-Run übersprungen: ein anderer Monitoring-Lauf ist aktiv');
            return ['exitCode' => 1, 'status' => $status];
        default:
            return ['exitCode' => 1, 'status' => $status];
    }
}
