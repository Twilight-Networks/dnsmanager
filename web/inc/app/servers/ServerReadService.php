<?php
/**
 * Datei: inc/app/servers/ServerReadService.php
 * Zweck: Lesende Serververwaltung (Serverliste, Bearbeitungsdaten, Serverdiagnosen) für beliebige Aufrufkanäle.
 *
 * - Nur admin. Der Akteur wird in derselben Abfrage gegen die Datenbank geprüft (Rolle admin wie im Actor,
 *   Konto aktiv; ActorAccess::activeCondition). Andere oder veraltete Akteure erhalten leere Ergebnisse.
 * - Ausschließlich lesend, explizite Spalten, keine Sperren, keine Transaktion.
 * - Zugangstokens: Keine Methode liefert den gespeicherten api_token (S29, SP1; bis dahin belegte
 *   getServerForEdit() das Bearbeitungsformular damit vor).
 * - Keine Session, kein HTTP, keine Sprachschlüssel.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ActorAccess.php';

final class ServerReadService
{
    private const COLUMNS = 's.id, s.name, s.dns_ip4, s.dns_ip6, s.api_ip, s.is_local, s.active';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Alle Server, sortiert nach Name (wie bisher pages/servers.php).
     *
     * @return array<int, array{id: int, name: string, dnsIp4: ?string, dnsIp6: ?string, apiIp: ?string, isLocal: bool, active: bool}>
     */
    public function listServers(Actor $actor): array
    {
        $access = $this->adminCondition($actor);
        if ($access === null) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM servers s WHERE ' . $access['sql'] . ' ORDER BY s.name');
        $this->bind($stmt, $access['params']);
        $stmt->execute();

        return array_map([self::class, 'map'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Ein Server für das Bearbeitungsformular (nur admin). Nicht vorhanden oder keine Berechtigung → null.
     *
     * S29 (SP1): Der gespeicherte API-Token gehört nicht mehr zum Bearbeitungsmodell und wird nicht gelesen;
     * beim Ändern bedeutet ein leeres Token-Feld „unverändert“ (ServerService::updateServer).
     *
     * @return array{id: int, name: string, dnsIp4: ?string, dnsIp6: ?string, apiIp: ?string, isLocal: bool, active: bool}|null
     */
    public function getServerForEdit(Actor $actor, int $serverId): ?array
    {
        $access = $this->adminCondition($actor);
        if ($access === null || $serverId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM servers s WHERE s.id = ? AND ' . $access['sql']);
        $this->bind($stmt, array_merge([$serverId], $access['params']));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            return null;
        }

        return self::map($row);
    }

    /**
     * Serverdiagnosen (Tabelle diagnostics, target_type 'server') in der bisherigen Reihenfolge.
     *
     * @return array<int, array{serverId: int, checkType: string, status: string, message: ?string, serverName: ?string}>
     */
    public function listServerDiagnostics(Actor $actor): array
    {
        $access = $this->adminCondition($actor);
        if ($access === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT d.target_id, d.check_type, d.status, d.message, s.name AS server_name'
            . ' FROM diagnostics d LEFT JOIN servers s ON s.id = d.server_id'
            . " WHERE d.target_type = 'server' AND " . $access['sql']
            . ' ORDER BY d.id ASC'
        );
        $this->bind($stmt, $access['params']);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'serverId'   => (int)$row['target_id'],
                'checkType'  => (string)$row['check_type'],
                'status'     => (string)$row['status'],
                'message'    => $row['message'] === null ? null : (string)$row['message'],
                'serverName' => $row['server_name'] === null ? null : (string)$row['server_name'],
            ];
        }

        return $result;
    }

    /**
     * Serverdiagnosen je Server und Prüfart (Paket 6, K2/K3; bisher in pages/servers.php und DiagnosticReadService).
     *
     * Gruppierung unverändert: Reihenfolge der Server nach erstem Auftreten in listServerDiagnostics(); ein späterer
     * Eintrag derselben Prüfart überschreibt einen früheren.
     *
     * @return array<int, array<string, array{status: string, message: ?string, serverName: ?string}>>
     */
    public function getServerDiagnosticChecks(Actor $actor): array
    {
        $checks = [];
        foreach ($this->listServerDiagnostics($actor) as $d) {
            $checks[$d['serverId']][$d['checkType']] = [
                'status'     => $d['status'],
                'message'    => $d['message'],
                'serverName' => $d['serverName'],
            ];
        }

        return $checks;
    }

    /**
     * Auswertung der Serverdiagnosen für die Serverübersicht (Paket 6, K3; Regeln unverändert aus pages/servers.php).
     *
     * - serverStatus: je Server der Status der Prüfart server_status (nur Server mit dieser Prüfart).
     * - hasErrors / hasWarnings: irgendein Eintrag der Prüfarten server_status bzw. zone_conf_status mit Status
     *   error bzw. warning.
     * - problems: Einträge dieser beiden Prüfarten mit Status ungleich 'ok', je Server in der Reihenfolge
     *   server_status, zone_conf_status (fehlende Prüfarten entfallen).
     *
     * @return array{serverStatus: array<int, string>, hasErrors: bool, hasWarnings: bool,
     *               problems: array<int, array{serverName: ?string, checkType: string, status: string, message: ?string}>}
     */
    public function getServerStatusOverview(Actor $actor): array
    {
        $serverStatus = [];
        $hasErrors = false;
        $hasWarnings = false;
        $problems = [];

        foreach ($this->getServerDiagnosticChecks($actor) as $serverId => $checks) {
            if (isset($checks['server_status']['status'])) {
                $serverStatus[$serverId] = $checks['server_status']['status'];
            }

            foreach (['server_status', 'zone_conf_status'] as $check) {
                if (!isset($checks[$check])) {
                    continue;
                }

                $status = $checks[$check]['status'] ?? 'ok';
                if ($status === 'error') {
                    $hasErrors = true;
                } elseif ($status === 'warning') {
                    $hasWarnings = true;
                }

                if ($status !== 'ok') {
                    $problems[] = [
                        'serverName' => $checks[$check]['serverName'],
                        'checkType'  => $check,
                        'status'     => $status,
                        'message'    => $checks[$check]['message'],
                    ];
                }
            }
        }

        return ['serverStatus' => $serverStatus, 'hasErrors' => $hasErrors, 'hasWarnings' => $hasWarnings, 'problems' => $problems];
    }

    /** @return array{sql: string, params: array<int, int>}|null */
    private function adminCondition(Actor $actor): ?array
    {
        if (!$actor->isAdmin()) {
            return null;
        }

        return (new ActorAccess($this->pdo))->activeCondition($actor);
    }

    private function bind(PDOStatement $stmt, array $params): void
    {
        $i = 1;
        foreach ($params as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
    }

    /** @return array{id: int, name: string, dnsIp4: ?string, dnsIp6: ?string, apiIp: ?string, isLocal: bool, active: bool} */
    private static function map(array $row): array
    {
        return [
            'id'      => (int)$row['id'],
            'name'    => (string)$row['name'],
            'dnsIp4'  => $row['dns_ip4'] === null ? null : (string)$row['dns_ip4'],
            'dnsIp6'  => $row['dns_ip6'] === null ? null : (string)$row['dns_ip6'],
            'apiIp'   => $row['api_ip'] === null ? null : (string)$row['api_ip'],
            'isLocal' => (int)$row['is_local'] === 1,
            'active'  => (int)$row['active'] === 1,
        ];
    }
}
