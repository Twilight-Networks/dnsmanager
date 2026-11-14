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
 *   Kennzeichen systemManaged (automatisch verwaltete NS-/Glue-Records); DynDNS-Accounts nur als Kennzeichen
 *   isDyndns (eindeutige Zuordnung nach den DynDNS-Regeln), ohne Hostnamen, IPs oder Account-Daten.
 * - Stabile Sortierung (Typ, Name, ID) und begrenzte Ergebnismengen.
 *
 * Fehlerfälle:
 * - Nicht vorhandene und nicht berechtigte Zonen werden gleich behandelt (null).
 * - Ungültige Limit-/Offset-Werte führen zu einer InvalidArgumentException.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ZoneAccess.php';
require_once __DIR__ . '/../../glue_check.php';
require_once __DIR__ . '/../../dyndns_guard.php';

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

    /**
     * Alle Records einer berechtigten Zone mit fachlichen Kennzeichen für die Verwaltungsansicht
     * (bisher in pages/records.php und templates/record_edit_form.php berechnet):
     * - isDkim: TXT-Record mit "v=DKIM1" im Inhalt und "._domainkey" im Namen;
     * - isGlue: A/AAAA-Record, dessen FQDN Ziel eines NS-Records der Zone ist (isGlueRecord);
     * - isProtectedNs: NS-Record mit Glue oder mit Ziel auf einen der Zone zugewiesenen Server
     *   (isProtectedNsRecordForServers);
     * - isDyndns: A/AAAA-Record, der nach den bestehenden DynDNS-Regeln eindeutig einem DynDNS-Account der Zone
     *   zugeordnet ist (U4-Core; mehrdeutige oder nur reservierte Namen: false).
     *
     * Vollständige Liste ohne Seitenbegrenzung (die Ansicht und die Schutzprüfungen benötigen alle Records
     * der Zone). Sortierung nach Typ, Name und ID. Records und zugewiesene Servernamen werden mit je einer
     * Abfrage gelesen, beide mit der Berechtigungsbedingung (ZoneAccess::readableZoneCondition) in derselben
     * Anweisung.
     *
     * @return array{zone: array{id: int, name: string}, records: array<int, array{id: int, name: string,
     *               type: string, content: string, ttl: ?int, systemManaged: bool, isDkim: bool, isGlue: bool,
     *               isProtectedNs: bool, isDyndns: bool}>}|null
     *         null = Zone nicht vorhanden oder keine Berechtigung
     */
    public function getZoneRecordView(Actor $actor, int $zoneId): ?array
    {
        $access = $this->zoneAccess->readableZoneCondition($actor, 'z.id');

        if ($zoneId <= 0 || $access === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT z.id AS zone_id, z.name AS zone_name, r.id, r.name, r.type, r.content, r.ttl, r.server_id'
            . ' FROM zones z'
            . ' LEFT JOIN records r ON r.zone_id = z.id'
            . ' WHERE z.id = ? AND ' . $access['sql']
            . ' ORDER BY r.type ASC, r.name ASC, r.id ASC'
        );
        $this->bindZoneAndAccess($stmt, $zoneId, $access['params']);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($result === []) {
            return null;
        }

        $zoneName = (string)$result[0]['zone_name'];

        $stmt = $this->pdo->prepare(
            'SELECT s.name FROM zones z'
            . ' JOIN zone_servers zs ON zs.zone_id = z.id'
            . ' JOIN servers s ON s.id = zs.server_id'
            . ' WHERE z.id = ? AND ' . $access['sql']
        );
        $this->bindZoneAndAccess($stmt, $zoneId, $access['params']);
        $stmt->execute();
        $serverNames = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $records = [];
        foreach ($result as $r) {
            if ($r['id'] === null) {
                continue; // berechtigte Zone ohne Records (LEFT JOIN)
            }
            $records[] = [
                'id'            => (int)$r['id'],
                'name'          => (string)$r['name'],
                'type'          => (string)$r['type'],
                'content'       => (string)$r['content'],
                'ttl'           => $r['ttl'] === null ? null : (int)$r['ttl'],
                'systemManaged' => $r['server_id'] !== null,
            ];
        }

        $dyndnsIds = $this->dyndnsManagedRecordIds($zoneId, $zoneName, $records, $access);

        foreach ($records as $i => $r) {
            $records[$i]['isDkim'] = $r['type'] === 'TXT'
                && str_contains($r['content'], 'v=DKIM1')
                && str_contains($r['name'], '._domainkey');
            $records[$i]['isGlue'] = isGlueRecord($r, $records, $zoneName);
            $records[$i]['isProtectedNs'] = $r['type'] === 'NS'
                && isProtectedNsRecordForServers($r, $records, $zoneName, $serverNames);
            $records[$i]['isDyndns'] = isset($dyndnsIds[$r['id']]);
        }

        return [
            'zone'    => ['id' => (int)$result[0]['zone_id'], 'name' => $zoneName],
            'records' => $records,
        ];
    }

    /**
     * IDs der A/AAAA-Records, die nach den bestehenden DynDNS-Regeln eindeutig einem Account zugeordnet sind
     * (U4-Core, isDyndns). Gleiche Kriterien wie der DynDNS-Schutz in dyndnsCheckRecordChange():
     * - genau ein Account mit kanonisch gleichem Hostnamen (strict, dyndnsCanonicalName), kein Account, der den Namen
     *   nur unter der Datenbank-Collation trifft (loose), kein Systemrecord;
     * - dyndnsAttributeAddressRecord() liefert für diesen Account und Typ MANAGED mit genau diesem Record.
     * Mehrdeutige, abweichende oder nur reservierte Namen werden nicht gekennzeichnet. Accounts werden nur mit der
     * Zonenberechtigung in derselben Abfrage gelesen und nicht ausgegeben (kein Hostname, keine IP).
     *
     * @param array<int, array> $records Records der Zone (id, name, type, content, systemManaged)
     * @param array{sql: string, params: array<int, int>} $access Berechtigungsbedingung (ZoneAccess, Alias z.id)
     *
     * @return array<int, true>
     */
    private function dyndnsManagedRecordIds(int $zoneId, string $zoneName, array $records, array $access): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.hostname, a.current_ipv4, a.current_ipv6 FROM dyndns_accounts a'
            . ' JOIN zones z ON z.id = a.zone_id'
            . ' WHERE z.id = ? AND ' . $access['sql']
            . ' ORDER BY a.id'
        );
        $this->bindZoneAndAccess($stmt, $zoneId, $access['params']);
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($accounts === []) {
            return [];
        }

        // A/AAAA-Records im Format der DynDNS-Funktionen (server_id nur als Kennzeichen eines Systemrecords)
        $address = [];
        foreach ($records as $r) {
            if (in_array(strtoupper($r['type']), ['A', 'AAAA'], true)) {
                $address[] = ['id' => $r['id'], 'name' => $r['name'], 'type' => strtoupper($r['type']), 'content' => $r['content'],
                    'server_id' => $r['systemManaged'] ? 1 : null];
            }
        }

        // Kandidaten: genau ein strikter Account, Zuordnung MANAGED mit genau diesem Record
        $candidates = [];
        foreach ($address as $rec) {
            if ($rec['server_id'] !== null) {
                continue;
            }
            $owner = dyndnsCanonicalName($rec['name'], $zoneName);
            if ($owner === null) {
                continue;
            }
            $strict = array_values(array_filter($accounts, static fn (array $a): bool => dyndnsCanonicalName((string)$a['hostname'], $zoneName) === $owner));
            if (count($strict) !== 1) {
                continue;
            }
            $acc = $strict[0];
            $attr = dyndnsAttributeAddressRecord(
                $address,
                $zoneName,
                (string)$acc['hostname'],
                $rec['type'],
                $rec['type'] === 'A' ? ($acc['current_ipv4'] ?? null) : ($acc['current_ipv6'] ?? null)
            );
            if ($attr['state'] === 'MANAGED' && (int)$attr['record']['id'] === $rec['id']) {
                $candidates[$rec['id']] = ['owner' => $owner, 'name' => $rec['name'], 'accountId' => (int)$acc['id']];
            }
        }
        if ($candidates === []) {
            return [];
        }

        // Lose Treffer über die Collation (wie dyndnsFindOwnerReservations), für alle Kandidaten in einer Anweisung,
        // jeweils mit der Zonenberechtigung. Ein loser Treffer schließt die Kennzeichnung aus (Apex ausgenommen).
        $parts = [];
        $params = [];
        foreach ($candidates as $rid => $c) {
            if ($c['owner'] === '@') {
                continue;
            }
            $forms = dyndnsLooseNameForms($c['name'], $zoneName);
            $in = implode(',', array_fill(0, count($forms), '?'));
            $parts[] = 'SELECT ? AS rid, a.id FROM dyndns_accounts a JOIN zones z ON z.id = a.zone_id'
                . ' WHERE z.id = ? AND ' . $access['sql']
                . " AND (a.hostname IN ($in) OR TRIM(TRAILING '.' FROM TRIM(a.hostname)) IN ($in))";
            $params = array_merge($params, [$rid, $zoneId], $access['params'], $forms, $forms);
        }
        if ($parts !== []) {
            $stmt = $this->pdo->prepare(implode(' UNION ALL ', $parts));
            foreach ($params as $i => $v) {
                $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rid = (int)$row['rid'];
                if (isset($candidates[$rid]) && (int)$row['id'] !== $candidates[$rid]['accountId']) {
                    unset($candidates[$rid]); // weiterer Account trifft den Namen nur lose: nicht eindeutig
                }
            }
        }

        return array_fill_keys(array_keys($candidates), true);
    }

    /**
     * @param array<int, int> $accessParams
     */
    private function bindZoneAndAccess(PDOStatement $stmt, int $zoneId, array $accessParams): void
    {
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $i = 2;
        foreach ($accessParams as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
    }
}
