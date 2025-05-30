#!/usr/bin/php
<?php
/**
 * Datei: monitoring_log_cleanup-cli.php
 * Zweck: Entfernt veraltete Einträge aus der Tabelle `diagnostic_log` entsprechend der konfigurierten Aufbewahrungsfrist.
 *
 * Details:
 * - Die Aufbewahrungsfrist wird über die Konstante MONITORING_LOG_RETENTION gesteuert (z. B. '30D', '12M', '1Y').
 * - Nur CLI-Aufruf erlaubt.
 * - Bei ungültiger Frist wird ein Fehlerprotokoll geschrieben.
 * - Erfolgreiche Löschoperationen werden per AppLog und CLI ausgegeben.
 *
 * Konfiguration:
 * - Konstante MONITORING_LOG_RETENTION aus ui_config.php (z. B. '90D', '1Y', '72H')
 */

require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';

date_default_timezone_set('UTC');

// Nur CLI erlauben
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Nur CLI-Zugriff erlaubt.\n");
}

appLog('info', 'Monitoring-Log-Cleanup gestartet');

// Datenbank prüfen
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    appLog('error', 'PDO nicht verfügbar – Abbruch');
    exit(1);
}

$db = $GLOBALS['pdo'];
$retention = MONITORING_LOG_RETENTION ?? '30D';
$interval = parseIntervalToDateTime($retention);

if ($interval === null) {
    $msg = "Ungültige Aufbewahrungsfrist: '$retention'";
    appLog('error', $msg);
    echo "[Fehler] $msg\n";
    exit(1);
}

$threshold = (new DateTime())->sub($interval)->format('Y-m-d H:i:s');

try {
    $stmt = $db->prepare("DELETE FROM diagnostic_log WHERE changed_at < ?");
    $stmt->execute([$threshold]);
    $count = $stmt->rowCount();

    $msg = "$count veraltete Einträge aus diagnostic_log entfernt (älter als $threshold)";
    appLog('info', $msg);
    exit(0);
} catch (Exception $e) {
    $msg = "Fehler bei der Bereinigung: " . $e->getMessage();
    appLog('error', $msg);
    exit(1);
}

/**
 * Hilfsfunktion: Wandelt einen Zeitbezeichner wie „30D“ oder „6M“ in ein DateInterval-Objekt.
 * Unterstützte Einheiten: H = Stunden, D = Tage, W = Wochen, M = Monate, Y = Jahre.
 *
 * @param string $spec Zeitbezeichner, z. B. '30D'
 * @return DateInterval|null
 */
function parseIntervalToDateTime(string $spec): ?DateInterval {
    if (!preg_match('/^(\d+)([HDWMY])$/i', $spec, $match)) return null;
    [$_, $amount, $unit] = $match;

    return match (strtoupper($unit)) {
        'H' => new DateInterval("PT{$amount}H"),
        'D' => new DateInterval("P{$amount}D"),
        'W' => new DateInterval("P" . ($amount * 7) . "D"),
        'M' => new DateInterval("P{$amount}M"),
        'Y' => new DateInterval("P{$amount}Y"),
        default => null
    };
}
