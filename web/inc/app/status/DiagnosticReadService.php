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
 *   serverWarning           – server_status eines Servers warning (S42-E6, z. B. Zertifikatsablauf)
 *   zoneError/zoneWarning   – zone_status einer Zone error|not_found bzw. warning
 * - problems: Problemeinträge der Systemstatus-Seite je Bereich server, conf, zone (Paket 6, K2; Regeln siehe
 *   getOverview). Gruppierung der Serverdiagnosen über ServerReadService::getServerDiagnosticChecks().
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
     *               zoneChecks: array<int, array>, summary: array{confError: bool, confWarning: bool, serverError: bool, serverWarning: bool,
     *               zoneError: bool, zoneWarning: bool},
     *               problems: array{server: array<int, array{serverName: ?string, status: string, message: ?string}>,
     *                               conf: array<int, array{serverName: ?string, status: ?string, message: ?string}>,
     *                               zone: array<int, array{zoneName: ?string, serverName: ?string, status: ?string, message: ?string}>}}|null
     *         null = kein Admin
     */
    public function getOverview(Actor $actor): ?array
    {
        if (!$actor->isAdmin()) {
            return null;
        }

        $serverChecks = $this->servers->getServerDiagnosticChecks($actor);

        $zoneChecks = $this->zones->listZoneDiagnostics($actor);

        $summary = ['confError' => false, 'confWarning' => false, 'serverError' => false, 'serverWarning' => false, 'zoneError' => false, 'zoneWarning' => false];
        foreach ($serverChecks as $checks) {
            $conf = $checks['zone_conf_status']['status'] ?? null;
            $summary['confError'] = $summary['confError'] || $conf === 'error';
            $summary['confWarning'] = $summary['confWarning'] || $conf === 'warning';
            $summary['serverError'] = $summary['serverError'] || ($checks['server_status']['status'] ?? null) === 'error';
            $summary['serverWarning'] = $summary['serverWarning'] || ($checks['server_status']['status'] ?? null) === 'warning';
        }
        foreach ($zoneChecks as $z) {
            if ($z['checkType'] !== 'zone_status') {
                continue;
            }
            $summary['zoneError'] = $summary['zoneError'] || in_array($z['status'], ['error', 'not_found'], true);
            $summary['zoneWarning'] = $summary['zoneWarning'] || $z['status'] === 'warning';
        }

        // Problemeinträge der Systemstatus-Seite (Paket 6, K2; Regeln unverändert aus pages/system_health.php):
        // - server: Server mit Prüfart server_status, deren Status nicht 'ok' ist;
        // - conf:   alle Server, deren zone_conf_status nicht 'ok' ist – auch Server ohne diese Prüfart (Status null);
        // - zone:   alle Zonendiagnosen, deren zone_status nicht 'ok' ist – Einträge anderer Prüfart mit Status null.
        // Jeweils in der Reihenfolge von serverChecks bzw. zoneChecks; Namen/Meldung null = nicht vorhanden.
        $problems = ['server' => [], 'conf' => [], 'zone' => []];
        foreach ($serverChecks as $checks) {
            if (isset($checks['server_status']) && $checks['server_status']['status'] !== 'ok') {
                $problems['server'][] = [
                    'serverName' => $checks['server_status']['serverName'],
                    'status'     => $checks['server_status']['status'],
                    'message'    => $checks['server_status']['message'],
                ];
            }
            $conf = $checks['zone_conf_status'] ?? null;
            if (($conf['status'] ?? null) !== 'ok') {
                $problems['conf'][] = [
                    'serverName' => $conf['serverName'] ?? null,
                    'status'     => $conf['status'] ?? null,
                    'message'    => $conf['message'] ?? null,
                ];
            }
        }
        foreach ($zoneChecks as $z) {
            $isZoneStatus = $z['checkType'] === 'zone_status';
            $status = $isZoneStatus ? $z['status'] : null;
            if ($status !== 'ok') {
                $problems['zone'][] = [
                    'zoneName'   => $z['zoneName'],
                    'serverName' => $z['serverName'],
                    'status'     => $status,
                    'message'    => $isZoneStatus ? $z['message'] : null,
                ];
            }
        }

        return ['serverChecks' => $serverChecks, 'zoneChecks' => $zoneChecks, 'summary' => $summary, 'problems' => $problems];
    }
}
