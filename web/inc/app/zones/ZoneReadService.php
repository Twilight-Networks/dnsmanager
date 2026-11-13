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
 * - Keine Server-Zugangsdaten: Zu zugeordneten Servern werden nur ID, Name, Rolle und Aktivstatus
 *   ausgegeben, zu zuweisbaren Servern zusätzlich die öffentlichen DNS-Adressen (kein api_token, keine
 *   api_ip, kein is_local).
 * - Normalisierte Rückgabewerte ohne HTML-/Web-Bezug; die Übersetzung in Template-Felder erfolgt im
 *   Web-Layer.
 * - Stabile Sortierung (Zonenname, dann ID) und begrenzte Ergebnismengen.
 *
 * Fehlerfälle:
 * - Nicht vorhandene und nicht berechtigte Zonen werden gleich behandelt (null), damit keine
 *   Rückschlüsse auf fremde Zonen möglich sind.
 * - Ungültige Limit-/Offset-Werte führen zu einer InvalidArgumentException.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ActorAccess.php';
require_once __DIR__ . '/../ZoneAccess.php';
require_once __DIR__ . '/ZoneEditVersion.php';

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
     * Für den Akteur sichtbare Zonen (Übersicht), sortiert nach Name und ID, jeweils mit den zugeordneten
     * Servern (id, name, role, active; sortiert nach Servername und ID).
     *
     * Eine einzige Abfrage: Die Zonenseite (Berechtigung, Sortierung, LIMIT/OFFSET) wird als abgeleitete
     * Tabelle auf Zonenebene gebildet; erst danach werden die Server angefügt. Die Pagination bezieht sich
     * damit ausschließlich auf Zonen, Serverlisten werden nie über Seitengrenzen abgeschnitten.
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
            'SELECT zp.id, zp.name, zp.type, zp.ttl, zp.prefix_length, zp.description, zp.changed, zp.allow_dyndns,'
            . ' s.id AS server_id, s.name AS server_name, zs.is_master AS server_is_master,'
            . ' s.active AS server_active'
            . ' FROM (SELECT ' . self::ZONE_COLUMNS . ' FROM zones z WHERE ' . $access['sql']
            . ' ORDER BY z.name ASC, z.id ASC LIMIT ? OFFSET ?) zp'
            . ' LEFT JOIN zone_servers zs ON zs.zone_id = zp.id'
            . ' LEFT JOIN servers s ON s.id = zs.server_id'
            . ' ORDER BY zp.name ASC, zp.id ASC, s.name ASC, s.id ASC'
        );
        $i = 1;
        foreach ($access['params'] as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, $limit + 1, PDO::PARAM_INT); // eine Zone mehr zur Ermittlung von hasMore
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        // Zeilen (Zone × Server) zu Zonen zusammenfassen; Reihenfolge der Abfrage bleibt erhalten
        $zones = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['id'];
            if (!isset($zones[$id])) {
                $zones[$id] = self::mapZone($row);
                $zones[$id]['servers'] = [];
            }
            if ($row['server_id'] !== null) {
                $zones[$id]['servers'][] = self::mapServer($row);
            }
        }
        $zones = array_values($zones);

        $hasMore = count($zones) > $limit;

        return [
            'zones'   => array_slice($zones, 0, $limit),
            'limit'   => $limit,
            'offset'  => $offset,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Details einer Zone, sofern sie existiert und der Akteur sie lesen darf.
     *
     * editVersion (S21): Bearbeitungsstand für ZoneService::updateZone() (ZoneEditVersion), berechnet
     * aus demselben Abfrageergebnis. Geltungsbereich nach der Rolle des Akteurs: admin inklusive
     * Serverzuweisung, zoneadmin ohne; andere Rollen null (keine Bearbeitung möglich).
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
        // gemeinsam gelesen. Server: nur ID, Name, Rolle und Aktivstatus (keine Tokens, keine API-Adressen).
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ZONE_COLUMNS . ', z.soa_ns, z.soa_mail, z.soa_serial, z.soa_refresh, z.soa_retry,'
            . ' z.soa_expire, z.soa_minimum, s.id AS server_id, s.name AS server_name,'
            . ' zs.is_master AS server_is_master, s.active AS server_active'
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
        $assignment = [];
        foreach ($rows as $r) {
            if ($r['server_id'] === null) {
                continue; // Zone ohne Serverzuordnung (LEFT JOIN)
            }
            $zone['servers'][] = self::mapServer($r);
            $assignment[(int)$r['server_id']] = (int)$r['server_is_master'];
        }

        $scope = match ($actor->role) {
            'admin'     => ZoneEditVersion::SCOPE_ADMIN,
            'zoneadmin' => ZoneEditVersion::SCOPE_ZONEADMIN,
            default     => null,
        };
        $zone['editVersion'] = $scope === null ? null : ZoneEditVersion::of($scope, $row, $assignment);

        return $zone;
    }

    /**
     * Server, die in Zonenformularen zur Zuweisung angezeigt werden (Anlage- und Bearbeitungsformular).
     *
     * Sichtbar für admin und zoneadmin (das Bearbeitungsformular zeigt auch zoneadmins die Serverzuweisung;
     * Änderungen daran übernimmt ZoneService nur für admins), für alle anderen Rollen leer.
     * Nur öffentliche Serverdaten: ID, Name, DNS-Adressen, Aktivstatus – keine Tokens, keine API-Adressen.
     * Sortierung nach Name und ID.
     * Der Akteur wird in derselben Abfrage gegen die Datenbank geprüft (Rolle wie im Actor, Konto aktiv;
     * ActorAccess): ein deaktiviertes, gelöschtes oder umgestuftes Konto erhält eine leere Liste.
     *
     * @param bool $activeOnly true = nur aktive Server (Anlageformular)
     *
     * @return array<int, array{id: int, name: string, dnsIp4: ?string, dnsIp6: ?string, active: bool}>
     */
    public function listAssignableServers(Actor $actor, bool $activeOnly): array
    {
        $access = (new ActorAccess($this->pdo))->activeCondition($actor);
        if ($access === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.name, s.dns_ip4, s.dns_ip6, s.active FROM servers s'
            . ' WHERE ' . $access['sql'] . ($activeOnly ? ' AND s.active = 1' : '')
            . ' ORDER BY s.name ASC, s.id ASC'
        );
        $i = 1;
        foreach ($access['params'] as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
        $stmt->execute();

        $servers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $servers[] = [
                'id'     => (int)$row['id'],
                'name'   => (string)$row['name'],
                'dnsIp4' => $row['dns_ip4'] === null ? null : (string)$row['dns_ip4'],
                'dnsIp6' => $row['dns_ip6'] === null ? null : (string)$row['dns_ip6'],
                'active' => (int)$row['active'] === 1,
            ];
        }

        return $servers;
    }

    /**
     * Zonendiagnosen (Tabelle diagnostics, target_type 'zone') für alle Zonen, die der Akteur lesen darf.
     *
     * Die Berechtigung ist Bedingung derselben Abfrage (ZoneAccess::readableZoneCondition auf
     * d.target_id): admin erhält alle Zonendiagnosen – auch solche ohne vorhandene Zone –, zoneadmin nur
     * Diagnosen zugewiesener Zonen, alle anderen Rollen keine. Serverdiagnosen werden nicht geliefert.
     *
     * Reihenfolge: nach Diagnose-ID (Einfügereihenfolge), damit die Ausgabe stabil ist.
     *
     * @return array<int, array{zoneId: int, zoneName: ?string, serverName: ?string, checkType: string,
     *                          status: string, message: ?string}>
     *         zoneName/serverName null = Zone bzw. Server nicht (mehr) vorhanden
     */
    public function listZoneDiagnostics(Actor $actor): array
    {
        $access = $this->zoneAccess->readableZoneCondition($actor, 'd.target_id');

        if ($access === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT d.target_id, d.check_type, d.status, d.message, z.name AS zone_name, s.name AS server_name'
            . ' FROM diagnostics d'
            . ' LEFT JOIN zones z ON z.id = d.target_id'
            . ' LEFT JOIN servers s ON s.id = d.server_id'
            . " WHERE d.target_type = 'zone' AND " . $access['sql']
            . ' ORDER BY d.id ASC'
        );
        $i = 1;
        foreach ($access['params'] as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'zoneId'     => (int)$row['target_id'],
                'zoneName'   => $row['zone_name'] === null ? null : (string)$row['zone_name'],
                'serverName' => $row['server_name'] === null ? null : (string)$row['server_name'],
                'checkType'  => (string)$row['check_type'],
                'status'     => (string)$row['status'],
                'message'    => $row['message'] === null ? null : (string)$row['message'],
            ];
        }

        return $result;
    }

    /**
     * Auswertung der Zonendiagnosen für die Zonenübersicht (Paket 6, K1; bisher in pages/zones.php).
     *
     * Grundlage sind dieselben Einträge wie listZoneDiagnostics() (gleiche Berechtigung, gleiche Reihenfolge).
     * Regeln unverändert übernommen:
     * - zones: je Zone der maßgebliche Diagnose-Eintrag. Der erste Eintrag einer Zone gilt; ein späterer
     *   Eintrag ersetzt ihn nur, wenn der bisherige Status 'ok' und der neue nicht 'ok' ist oder der bisherige
     *   'warning' und der neue 'error' ist ('not_found' und 'error' werden nie ersetzt, 'warning' nicht durch
     *   'not_found'). Maßgeblich ist der Status der Prüfart zone_status; Einträge anderer Prüfart haben den
     *   Status null (werden wie bisher nie ersetzt und zählen als 'ok').
     * - hasErrors / hasWarnings: irgendein Eintrag mit Status error|not_found bzw. warning (Status null = 'ok').
     * - problems: alle Einträge mit einem Status ungleich 'ok' (null = 'ok'), in Lesereihenfolge.
     *
     * Den Anzeigestatus einer Zone ermittelt zoneDisplayStatus() aus dem maßgeblichen Status und dem
     * ausstehenden Publish (listZones: pendingPublish).
     *
     * @return array{zones: array<int, array{status: ?string, serverName: ?string, message: ?string}>,
     *               hasErrors: bool, hasWarnings: bool,
     *               problems: array<int, array{zoneName: ?string, serverName: ?string, status: string, message: ?string}>}
     *         zones nach Zonen-ID; Namen null = Zone bzw. Server nicht (mehr) vorhanden
     */
    public function getZoneStatusOverview(Actor $actor): array
    {
        $zones = [];
        $hasErrors = false;
        $hasWarnings = false;
        $problems = [];

        foreach ($this->listZoneDiagnostics($actor) as $diag) {
            $isZoneStatus = $diag['checkType'] === 'zone_status';
            $status = $isZoneStatus ? $diag['status'] : null;
            $entry = [
                'status'     => $status,
                'serverName' => $diag['serverName'],
                'message'    => $isZoneStatus ? $diag['message'] : null,
            ];

            if (!isset($zones[$diag['zoneId']])) {
                $zones[$diag['zoneId']] = $entry;
            } else {
                $current = $zones[$diag['zoneId']]['status'];
                if (($current === 'ok' && $status !== 'ok') || ($current === 'warning' && $status === 'error')) {
                    $zones[$diag['zoneId']] = $entry;
                }
            }

            $effective = $status ?? 'ok';
            if (in_array($effective, ['error', 'not_found'], true)) {
                $hasErrors = true;
            } elseif ($effective === 'warning') {
                $hasWarnings = true;
            }
            if ($effective !== 'ok') {
                $problems[] = [
                    'zoneName'   => $diag['zoneName'],
                    'serverName' => $diag['serverName'],
                    'status'     => $effective,
                    'message'    => $entry['message'],
                ];
            }
        }

        return ['zones' => $zones, 'hasErrors' => $hasErrors, 'hasWarnings' => $hasWarnings, 'problems' => $problems];
    }

    /**
     * Anzeigestatus einer Zone (Paket 6, K1; Regeln unverändert aus pages/zones.php).
     *
     * error|not_found → 'error', warning → 'warning', sonst 'ok'; eine Zone mit Status 'ok' und ausstehendem
     * Publish (changed ≠ 0) wird 'changed'. Fehler und Warnungen haben Vorrang vor 'changed'.
     *
     * @param ?string $diagnosticStatus maßgeblicher Status aus getZoneStatusOverview() (null = keiner)
     * @param bool    $pendingPublish   ausstehender Publish (listZones: pendingPublish)
     *
     * @return string ok | warning | error | changed
     */
    public static function zoneDisplayStatus(?string $diagnosticStatus, bool $pendingPublish): string
    {
        $status = 'ok';
        if (in_array($diagnosticStatus, ['error', 'not_found'], true)) {
            $status = 'error';
        } elseif ($diagnosticStatus === 'warning') {
            $status = 'warning';
        }

        if ($status === 'ok' && $pendingPublish) {
            $status = 'changed';
        }

        return $status;
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
     * Zugeordneter Server aus einer Zeile mit server_id, server_name, server_is_master, server_active.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string, role: string, active: bool}
     */
    private static function mapServer(array $row): array
    {
        return [
            'id'     => (int)$row['server_id'],
            'name'   => (string)$row['server_name'],
            'role'   => (int)$row['server_is_master'] === 1 ? 'master' : 'secondary',
            'active' => (int)$row['server_active'] === 1,
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
