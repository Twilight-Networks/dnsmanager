<?php
/**
 * Datei: inc/app/servers/ServerReadService.php
 * Zweck: Lesende Serververwaltung (Serverliste, Bearbeitungsdaten, Serverdiagnosen) für beliebige Aufrufkanäle.
 *
 * - Nur admin. Der Akteur wird in derselben Abfrage gegen die Datenbank geprüft (Rolle admin wie im Actor,
 *   Konto aktiv; ActorAccess::activeCondition). Andere oder veraltete Akteure erhalten leere Ergebnisse.
 * - Ausschließlich lesend, explizite Spalten, keine Sperren, keine Transaktion.
 * - Zugangstokens: listServers() liefert kein api_token. getServerForEdit() liefert es als einzige Methode,
 *   weil das bestehende Bearbeitungsformular den gespeicherten Token vorbelegt (unverändertes Verhalten,
 *   siehe Architektur-Inventar, Befund zu Tokens im Formular).
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
     * Ein Server einschließlich API-Token für das Bearbeitungsformular (nur admin).
     * Nicht vorhanden oder keine Berechtigung → null.
     *
     * @return array{id: int, name: string, dnsIp4: ?string, dnsIp6: ?string, apiIp: ?string, isLocal: bool, active: bool, apiToken: string}|null
     */
    public function getServerForEdit(Actor $actor, int $serverId): ?array
    {
        $access = $this->adminCondition($actor);
        if ($access === null || $serverId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ', s.api_token FROM servers s WHERE s.id = ? AND ' . $access['sql']);
        $this->bind($stmt, array_merge([$serverId], $access['params']));
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            return null;
        }

        return self::map($row) + ['apiToken' => (string)$row['api_token']];
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
