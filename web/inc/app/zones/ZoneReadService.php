<?php
/**
 * Datei: inc/app/zones/ZoneReadService.php
 * Zweck: Lesender Zugriff auf DNS-Zonen für beliebige Aufrufkanäle (Web, CLI, späterer MCP-Adapter).
 *
 * - Ausschließlich lesend (nur SELECT), keine Seiteneffekte.
 * - Sichtbarkeit ausschließlich über ZoneAccess (admin: alle Zonen; zoneadmin: user_zones; sonst keine).
 *   Die Berechtigung ist Bedingung der jeweiligen Datenabfrage selbst (ZoneAccess::readableZoneCondition),
 *   sodass eine zwischenzeitlich entzogene Zuweisung sofort wirkt. Keine Transaktionen, keine Sperren.
 * - Explizite Spaltenlisten und ein fester Ergebnisvertrag (kein SELECT *).
 * - Keine Server-Zugangsdaten: Zu Servern werden nur Name, Rolle und Aktivstatus ausgegeben
 *   (kein api_token, keine api_ip).
 * - Stabile Sortierung (Zonenname, dann ID) und begrenzte Ergebnismengen.
 *
 * Fehlerfälle:
 * - Nicht vorhandene und nicht berechtigte Zonen werden gleich behandelt (null), damit keine
 *   Rückschlüsse auf fremde Zonen möglich sind.
 * - Ungültige Limit-/Offset-Werte führen zu einer InvalidArgumentException.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ZoneAccess.php';

final class ZoneReadService
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    /** Ausgegebene Zonenspalten (fester Read-Contract) */
    private const ZONE_COLUMNS = 'z.id, z.name, z.type, z.ttl, z.prefix_length, z.description, z.changed, z.allow_dyndns';

    private PDO $pdo;
    private ZoneAccess $zoneAccess;

    public function __construct(PDO $pdo, ZoneAccess $zoneAccess)
    {
        $this->pdo = $pdo;
        $this->zoneAccess = $zoneAccess;
    }

    /**
     * Für den Akteur sichtbare Zonen (Übersicht), sortiert nach Name und ID.
     *
     * @return array{zones: array<int, array>, limit: int, offset: int, hasMore: bool}
     *
     * @throws InvalidArgumentException bei ungültigem Limit oder Offset
     */
    public function listZones(Actor $actor, int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        self::assertPaging($limit, $offset, self::MAX_LIMIT);

        // Berechtigung als Bedingung der eigentlichen Abfrage (aktuelle Zuweisung, keine Vorab-ID-Liste)
        $access = $this->zoneAccess->readableZoneCondition($actor, 'z.id');

        if ($access === null) {
            return ['zones' => [], 'limit' => $limit, 'offset' => $offset, 'hasMore' => false];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ZONE_COLUMNS . ' FROM zones z WHERE ' . $access['sql']
            . ' ORDER BY z.name ASC, z.id ASC LIMIT ? OFFSET ?'
        );
        $i = 1;
        foreach ($access['params'] as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, $limit + 1, PDO::PARAM_INT); // eine Zeile mehr zur Ermittlung von hasMore
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'zones'   => array_map([self::class, 'mapZone'], $rows),
            'limit'   => $limit,
            'offset'  => $offset,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Details einer Zone, sofern sie existiert und der Akteur sie lesen darf.
     *
     * @return array|null null = Zone nicht vorhanden oder keine Berechtigung
     */
    public function getZone(Actor $actor, int $zoneId): ?array
    {
        $access = $this->zoneAccess->readableZoneCondition($actor, 'z.id');

        if ($zoneId <= 0 || $access === null) {
            return null;
        }

        // Eine einzige Abfrage: Zone, Berechtigung (aktuelle Zuweisung) und zugeordnete Server werden
        // gemeinsam gelesen. Server: nur Name, Rolle und Aktivstatus (keine Tokens, keine API-Adressen).
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ZONE_COLUMNS . ', z.soa_ns, z.soa_mail, z.soa_serial, z.soa_refresh, z.soa_retry,'
            . ' z.soa_expire, z.soa_minimum, s.name AS server_name, zs.is_master AS server_is_master,'
            . ' s.active AS server_active'
            . ' FROM zones z'
            . ' LEFT JOIN zone_servers zs ON zs.zone_id = z.id'
            . ' LEFT JOIN servers s ON s.id = zs.server_id'
            . ' WHERE z.id = ? AND ' . $access['sql']
            . ' ORDER BY s.name ASC, s.id ASC'
        );
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $i = 2;
        foreach ($access['params'] as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            // Zone nicht vorhanden oder nicht (mehr) berechtigt
            return null;
        }

        $row = $rows[0];
        $zone = self::mapZone($row);
        $zone['soa'] = [
            'primaryNs' => (string)$row['soa_ns'],
            'mail'      => (string)$row['soa_mail'],
            'serial'    => (int)$row['soa_serial'],
            'refresh'   => (int)$row['soa_refresh'],
            'retry'     => (int)$row['soa_retry'],
            'expire'    => (int)$row['soa_expire'],
            'minimum'   => (int)$row['soa_minimum'],
        ];
        $zone['servers'] = [];
        foreach ($rows as $r) {
            if ($r['server_name'] === null) {
                continue; // Zone ohne Serverzuordnung (LEFT JOIN)
            }
            $zone['servers'][] = [
                'name'   => (string)$r['server_name'],
                'role'   => (int)$r['server_is_master'] === 1 ? 'master' : 'secondary',
                'active' => (int)$r['server_active'] === 1,
            ];
        }

        return $zone;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapZone(array $row): array
    {
        return [
            'id'             => (int)$row['id'],
            'name'           => (string)$row['name'],
            'type'           => (string)$row['type'],
            'ttl'            => $row['ttl'] === null ? null : (int)$row['ttl'],
            'prefixLength'   => $row['prefix_length'] === null ? null : (int)$row['prefix_length'],
            'description'    => $row['description'] === null ? null : (string)$row['description'],
            'allowDyndns'    => (int)$row['allow_dyndns'] === 1,
            'pendingPublish' => (int)$row['changed'] !== 0,
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertPaging(int $limit, int $offset, int $maxLimit): void
    {
        if ($limit < 1 || $limit > $maxLimit) {
            throw new InvalidArgumentException("limit muss zwischen 1 und $maxLimit liegen.");
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('offset darf nicht negativ sein.');
        }
    }
}
