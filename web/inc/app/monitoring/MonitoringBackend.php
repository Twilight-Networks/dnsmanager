<?php
/**
 * Datei: inc/app/monitoring/MonitoringBackend.php
 * Zweck: Schnittstelle des MonitoringService zu den Prüfungen auf den Nameservern (Agent).
 *
 * Ergebnis jeder Prüfung: ['status' => 'ok'|'warning'|'error'|…, 'message' => string]
 * Implementierung: AgentMonitoringBackend (inc/deploy).
 */
interface MonitoringBackend
{
    /** Allgemeiner Systemstatus des Servers (bisher check_server_status) */
    public function serverStatus(array $server): array;

    /** named-checkconf auf dem Server (bisher check_zone_conf_status) */
    public function zoneConfStatus(array $server): array;

    /** named-checkzone einer Zone auf dem Server (bisher check_zone_status) */
    public function zoneStatus(string $zoneName, array $server): array;
}
