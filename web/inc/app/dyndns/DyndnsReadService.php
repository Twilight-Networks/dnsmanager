<?php
/**
 * Datei: inc/app/dyndns/DyndnsReadService.php
 * Zweck: Lesende DynDNS-Accountverwaltung (Kontenliste, Zonenauswahl der Formulare) für beliebige Aufrufkanäle.
 *
 * - Nur admin. Der Akteur wird in derselben Abfrage gegen die Datenbank geprüft (Rolle admin wie im Actor,
 *   Konto aktiv; ActorAccess::activeCondition). Andere oder veraltete Akteure erhalten leere Ergebnisse.
 * - Ausschließlich lesend, explizite Spalten, kein password_hash, keine Sperren.
 * - Filter und Sortierung unverändert wie bisher in pages/dyndns.php und den Formular-Templates
 *   (Anlage: allow_dyndns = 1; Bearbeitung: freigegebene Forward-Zonen sowie immer die aktuelle Zone).
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ActorAccess.php';

final class DyndnsReadService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Alle DynDNS-Accounts, sortiert nach Hostname (wie bisher).
     *
     * @return array<int, array{id: int, username: string, hostname: string, zoneId: int, zoneName: string,
     *                          currentIpv4: ?string, currentIpv6: ?string, lastUpdate: ?string}>
     */
    public function listAccounts(Actor $actor): array
    {
        $access = $this->adminCondition($actor);
        if ($access === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.username, d.hostname, d.zone_id, z.name AS zone_name, d.current_ipv4, d.current_ipv6, d.last_update'
            . ' FROM dyndns_accounts d JOIN zones z ON d.zone_id = z.id'
            . ' WHERE ' . $access['sql']
            . ' ORDER BY d.hostname'
        );
        $this->bind($stmt, $access['params']);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $result[] = [
                'id'          => (int)$r['id'],
                'username'    => (string)$r['username'],
                'hostname'    => (string)$r['hostname'],
                'zoneId'      => (int)$r['zone_id'],
                'zoneName'    => (string)$r['zone_name'],
                'currentIpv4' => $r['current_ipv4'] === null ? null : (string)$r['current_ipv4'],
                'currentIpv6' => $r['current_ipv6'] === null ? null : (string)$r['current_ipv6'],
                'lastUpdate'  => $r['last_update'] === null ? null : (string)$r['last_update'],
            ];
        }

        return $result;
    }

    /**
     * Zonenauswahl des Anlageformulars: Zonen mit allow_dyndns = 1, sortiert nach Name (wie bisher).
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function listZonesForCreate(Actor $actor): array
    {
        $access = $this->adminCondition($actor);
        if ($access === null) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT z.id, z.name FROM zones z WHERE z.allow_dyndns = 1 AND ' . $access['sql'] . ' ORDER BY z.name');
        $this->bind($stmt, $access['params']);
        $stmt->execute();

        return array_map(fn(array $r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zonenauswahl des Bearbeitungsformulars: freigegebene Forward-Zonen sowie IMMER die aktuelle Zone des
     * Accounts (wie bisher); eligible = Zone ist für DynDNS zulässig (allow_dyndns = 1 und Forward).
     *
     * @return array<int, array{id: int, name: string, eligible: bool}>
     */
    public function listZonesForEdit(Actor $actor, int $currentZoneId): array
    {
        $access = $this->adminCondition($actor);
        if ($access === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT z.id, z.name, z.type, z.allow_dyndns FROM zones z"
            . " WHERE ((z.allow_dyndns = 1 AND z.type = 'forward') OR z.id = ?) AND " . $access['sql']
            . ' ORDER BY z.name'
        );
        $this->bind($stmt, array_merge([$currentZoneId], $access['params']));
        $stmt->execute();

        return array_map(fn(array $r) => [
            'id'       => (int)$r['id'],
            'name'     => (string)$r['name'],
            'eligible' => (int)$r['allow_dyndns'] === 1 && $r['type'] === 'forward',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
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
}
