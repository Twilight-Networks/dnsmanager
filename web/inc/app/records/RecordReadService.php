<?php
/**
 * Datei: inc/app/records/RecordReadService.php
 * Zweck: Lesender Zugriff auf die DNS-Records einer Zone für beliebige Aufrufkanäle
 *        (Web, CLI, späterer MCP-Adapter).
 *
 * - Ausschließlich lesend (nur SELECT), keine Seiteneffekte.
 * - Berechtigung ausschließlich über ZoneAccess (admin: alle Zonen; zoneadmin: user_zones;
 *   sonst keine), als Bedingung derselben Abfrage, die Zone und Records liest
 *   (ZoneAccess::readableZoneCondition). Eine zwischenzeitlich entzogene Zuweisung wirkt sofort;
 *   ohne Berechtigung werden weder Records noch Zonendaten geliefert. Keine Transaktionen, keine Sperren.
 * - Expliziter Read-Contract (kein SELECT *): server_id wird nicht ausgegeben, sondern nur als
 *   Kennzeichen systemManaged (automatisch verwaltete NS-/Glue-Records).
 * - Stabile Sortierung (Typ, Name, ID) und begrenzte Ergebnismengen.
 *
 * Fehlerfälle:
 * - Nicht vorhandene und nicht berechtigte Zonen werden gleich behandelt (null).
 * - Ungültige Limit-/Offset-Werte führen zu einer InvalidArgumentException.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ZoneAccess.php';

final class RecordReadService
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 1000;

    private PDO $pdo;
    private ZoneAccess $zoneAccess;

    public function __construct(PDO $pdo, ZoneAccess $zoneAccess)
    {
        $this->pdo = $pdo;
        $this->zoneAccess = $zoneAccess;
    }

    /**
     * Records einer berechtigten Zone, sortiert nach Typ, Name und ID.
     *
     * @return array{zone: array{id: int, name: string}, records: array<int, array>, limit: int, offset: int, hasMore: bool}|null
     *         null = Zone nicht vorhanden oder keine Berechtigung
     *
     * @throws InvalidArgumentException bei ungültigem Limit oder Offset
     */
    public function listRecords(Actor $actor, int $zoneId, int $limit = self::DEFAULT_LIMIT, int $offset = 0): ?array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit muss zwischen 1 und ' . self::MAX_LIMIT . ' liegen.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('offset darf nicht negativ sein.');
        }

        $access = $this->zoneAccess->readableZoneCondition($actor, 'z.id');

        if ($zoneId <= 0 || $access === null) {
            return null;
        }

        // Eine einzige Abfrage: Die berechtigte Zone (aktuelle Zuweisung) treibt das Ergebnis, die
        // Record-Seite wird per LEFT JOIN angehängt. Keine Zeile = Zone nicht vorhanden oder nicht
        // (mehr) berechtigt; eine Zeile ohne Record = berechtigte Zone ohne Records auf dieser Seite.
        $stmt = $this->pdo->prepare(
            'SELECT z.id AS zone_id, z.name AS zone_name, r.id, r.name, r.type, r.content, r.ttl, r.server_id'
            . ' FROM zones z'
            . ' LEFT JOIN (SELECT id, zone_id, name, type, content, ttl, server_id FROM records'
            . ' WHERE zone_id = ? ORDER BY type ASC, name ASC, id ASC LIMIT ? OFFSET ?) r ON r.zone_id = z.id'
            . ' WHERE z.id = ? AND ' . $access['sql']
            . ' ORDER BY r.type ASC, r.name ASC, r.id ASC'
        );
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit + 1, PDO::PARAM_INT); // eine Zeile mehr zur Ermittlung von hasMore
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->bindValue(4, $zoneId, PDO::PARAM_INT);
        $i = 5;
        foreach ($access['params'] as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($result === []) {
            return null;
        }

        $zone = ['id' => $result[0]['zone_id'], 'name' => $result[0]['zone_name']];
        $rows = array_values(array_filter($result, static fn (array $r): bool => $r['id'] !== null));

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'zone'    => ['id' => (int)$zone['id'], 'name' => (string)$zone['name']],
            'records' => array_map(static fn (array $r): array => [
                'id'            => (int)$r['id'],
                'name'          => (string)$r['name'],
                'type'          => (string)$r['type'],
                'content'       => (string)$r['content'],
                'ttl'           => $r['ttl'] === null ? null : (int)$r['ttl'],
                'systemManaged' => $r['server_id'] !== null,
            ], $rows),
            'limit'   => $limit,
            'offset'  => $offset,
            'hasMore' => $hasMore,
        ];
    }
}
