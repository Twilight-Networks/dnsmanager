#!/usr/bin/php
<?php
/**
 * Datei: monitoring_run-cli.php
 * Zweck: Führt alle Diagnosetests für Server- und Zonenstatus durch und schreibt Ergebnisse in die Datenbank.
 *
 * Details:
 * - Lädt Konfiguration, Datenbankanbindung und Prüf-Funktionen.
 * - Führt für alle aktiven Server und deren zugewiesene Zonen:
 *   - Statusabfrage des Remote-BIND-Dienstes
 *   - Prüfung der Konfigurationsdateien (named-checkconf)
 *   - Prüfung der Zonendateien (named-checkzone)
 * - Ergebnisse werden in `diagnostics` gespeichert, Änderungen zusätzlich in `diagnostic_log` protokolliert.
 *
 * Ausführung:
 * - Nur CLI, nicht über Web aufrufbar.
 */

require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers_cli.php';
cliGuard();
require_once __DIR__ . '/../inc/monitoring.php';
require_once __DIR__ . '/../inc/monitoring_mailer.php';
require_once __DIR__ . '/../inc/logging.php';

date_default_timezone_set('UTC');

appLog('info', 'Monitoring-Run gestartet');

// PDO-Instanz bereitstellen
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    appLog('error', 'PDO nicht verfügbar – Monitoring-Run abgebrochen');
    exit(1);
}
$db = $GLOBALS['pdo'];

// === SERVER-PRÜFUNG ===
$stmt = $db->prepare("SELECT * FROM servers WHERE active = 1");
$stmt->execute();
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($servers as $server) {
    $serverId = (int)$server['id'];

    try {
        $db->beginTransaction();

        $remoteResult = check_server_status($server);
        saveDiagnostic($db, 'server', $serverId, 'server_status', $remoteResult['status'], $remoteResult['message'], $serverId);

        $confResult = check_zone_conf_status($server);
        saveDiagnostic($db, 'server', $serverId, 'zone_conf_status', $confResult['status'], $confResult['message'], $serverId);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('error', "Fehler bei Diagnose für Server ID $serverId: " . $e->getMessage());
    }
}

// === ZONEN-PRÜFUNG ===
$stmt = $db->prepare("
    SELECT z.id AS zone_id, z.name AS zone_name, s.id AS server_id, s.*
    FROM zones z
    JOIN zone_servers zs ON zs.zone_id = z.id
    JOIN servers s ON s.id = zs.server_id
    WHERE s.active = 1
    ORDER BY z.name, s.name
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $zoneId   = (int)$row['zone_id'];
    $zoneName = $row['zone_name'];
    $serverId = (int)$row['server_id'];

    try {
        $db->beginTransaction();

        $zoneResult = check_zone_status($zoneName, $row);
        saveDiagnostic($db, 'zone', $zoneId, 'zone_status', $zoneResult['status'], $zoneResult['message'], $serverId);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('error', "Fehler bei Diagnose für Zone $zoneName auf Server ID $serverId: " . $e->getMessage());
    }
}

// Verwaiste Diagnostics bereinigen
cleanupOrphanedZoneDiagnostics($db);

// E-Mail-Benachrichtigungen verschicken
$result = sendDiagnosticAlerts($db);

if ($result['status'] !== 'ok') {
    foreach ($result['errors'] as $error) {
        appLog('error', "Fehler beim Mailversand: $error");
    }
    appLog('error', 'Monitoring-Run beendet – aber E-Mail-Versand fehlerhaft');
    exit(1);
}

appLog('info', 'Monitoring-Run erfolgreich beendet');
exit(0);
