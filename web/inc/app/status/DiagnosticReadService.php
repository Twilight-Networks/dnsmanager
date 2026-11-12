<?php
/**
 * Datei: inc/app/status/DiagnosticReadService.php
 * Zweck: Gesamtübersicht der Monitoring-Diagnosen (Server und Zonen) mit fachlicher Auswertung für das
 *        Admin-Dashboard, die Layout-Statusanzeige und die Systemstatus-Seite.
 *
 * Ersetzt getDiagnosticResults() (inc/diagnostics.php, ohne Berechtigungsbezug, S38). Nutzt die vorhandenen
 * Read-Services: ServerReadService::listServerDiagnostics() und ZoneReadService::listZoneDiagnostics()
 * (Berechtigung jeweils in derselben Abfrage). Die Übersicht steht nur aktiven Admins zur Verfügung.
 *
 * - serverChecks: je Server-ID und Prüfart der zuletzt gelesene Eintrag (wie bisher: spätere Einträge
 *   derselben Prüfart überschreiben frühere), Werte status, message, serverName (null = Server unbekannt).
 * - zoneChecks: alle Zonendiagnosen in Lesereihenfolge (zoneId, zoneName, serverName, checkType, status,
 *   message; Namen null = nicht mehr vorhanden).
 * - summary: fachliche Auswertung (bisher in den Seiten):
 *   confError/confWarning   – zone_conf_status eines Servers error bzw. warning
 *   serverError             – server_status eines Servers error
 *   zoneError/zoneWarning   – zone_status einer Zone error|not_found bzw. warning
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ZoneAccess.php';
require_once __DIR__ . '/../servers/ServerReadService.php';
require_once __DIR__ . '/../zones/ZoneReadService.php';

final class DiagnosticReadService
{
    private ServerReadService $servers;
    private ZoneReadService $zones;

    public function __construct(PDO $pdo, ZoneAccess $zoneAccess)
    {
        $this->servers = new ServerReadService($pdo);
        $this->zones = new ZoneReadService($pdo, $zoneAccess);
    }

    /**
     * @return array{serverChecks: array<int, array<string, array{status: string, message: ?string, serverName: ?string}>>,
     *               zoneChecks: array<int, array>, summary: array{confError: bool, confWarning: bool, serverError: bool,
     *               zoneError: bool, zoneWarning: bool}}|null  null = kein Admin
     */
    public function getOverview(Actor $actor): ?array
    {
        if (!$actor->isAdmin()) {
            return null;
        }

        $serverChecks = [];
        foreach ($this->servers->listServerDiagnostics($actor) as $d) {
            $serverChecks[$d['serverId']][$d['checkType']] = [
                'status'     => $d['status'],
                'message'    => $d['message'],
                'serverName' => $d['serverName'],
            ];
        }

        $zoneChecks = $this->zones->listZoneDiagnostics($actor);

        $summary = ['confError' => false, 'confWarning' => false, 'serverError' => false, 'zoneError' => false, 'zoneWarning' => false];
        foreach ($serverChecks as $checks) {
            $conf = $checks['zone_conf_status']['status'] ?? null;
            $summary['confError'] = $summary['confError'] || $conf === 'error';
            $summary['confWarning'] = $summary['confWarning'] || $conf === 'warning';
            $summary['serverError'] = $summary['serverError'] || ($checks['server_status']['status'] ?? null) === 'error';
        }
        foreach ($zoneChecks as $z) {
            if ($z['checkType'] !== 'zone_status') {
                continue;
            }
            $summary['zoneError'] = $summary['zoneError'] || in_array($z['status'], ['error', 'not_found'], true);
            $summary['zoneWarning'] = $summary['zoneWarning'] || $z['status'] === 'warning';
        }

        return ['serverChecks' => $serverChecks, 'zoneChecks' => $zoneChecks, 'summary' => $summary];
    }
}
