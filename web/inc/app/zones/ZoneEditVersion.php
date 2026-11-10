<?php
/**
 * Datei: inc/app/zones/ZoneEditVersion.php
 * Zweck: Bearbeitungsstand einer Zone (S21, Schutz gegen veraltete Bearbeitungsformulare / Lost Updates).
 *
 * Eine einzige Berechnungsfunktion für Lesen (ZoneReadService::getZone(), Feld "editVersion") und
 * Schreiben (ZoneService::updateZone(), Vergleich auf dem gesperrten Stand). Das Token gewährt keine
 * Rechte und enthält kein Geheimnis; es belegt nur, auf welchem Stand ein Formular beruht.
 *
 * Hash-Vertrag "v1" (Format: "v1:" + SHA-256 hex, 64 Zeichen klein):
 * - Kanonische JSON-Liste mit fester Reihenfolge und festen Typen:
 *   [Vertragskennung, Geltungsbereich, id (int), name (string), type (string), ttl (int|null),
 *    prefix_length (int|null), description (string|null), allow_dyndns (int 0/1), soa_ns (string),
 *    soa_mail (string), soa_refresh (int), soa_retry (int), soa_expire (int), soa_minimum (int),
 *    Zuweisung (nur admin: Liste [server_id (int), is_master (int 0/1)], aufsteigend nach server_id;
 *    zoneadmin: null)]
 * - NULL und Leerstring bleiben unterscheidbar (JSON null bzw. ""); Strings wie gespeichert (ungetrimmt).
 * - Nicht enthalten: soa_serial, changed, created_at (Publish/Generator) sowie Serverstammdaten
 *   (Name, IP-Adressen, Aktivstatus).
 * - Geltungsbereich (rollenabhängig): "admin" inklusive vollständiger Serverzuweisung mit
 *   Master/Secondary; "zoneadmin" ohne Serverzuweisung (der zoneadmin kann sie nicht schreiben).
 *   Der Geltungsbereich ist Teil des Hashes: ein Token eines Bereichs passt nie zu einem anderen.
 *   Welcher Bereich gilt, bestimmt allein der Server (Rolle des Akteurs), nie der Client.
 *
 * Eine Änderung an Feldern, Typen oder Reihenfolge erfordert eine neue Vertragskennung.
 */

final class ZoneEditVersion
{
    /** Interne Kennung des Hash-Vertrags */
    public const CONTRACT = 'v1';

    public const SCOPE_ADMIN = 'admin';
    public const SCOPE_ZONEADMIN = 'zoneadmin';

    private const PATTERN = '/^v1:[0-9a-f]{64}$/D';

    /**
     * Bearbeitungsstand berechnen.
     *
     * @param string               $scope      SCOPE_ADMIN oder SCOPE_ZONEADMIN
     * @param array<string, mixed> $zone       Zonenzeile mit den Spalten id, name, type, ttl, prefix_length,
     *                                         description, allow_dyndns, soa_ns, soa_mail, soa_refresh,
     *                                         soa_retry, soa_expire, soa_minimum (Werte roh aus PDO)
     * @param array<int|string, mixed>|null $assignment server_id => is_master (nur SCOPE_ADMIN, sonst ignoriert)
     */
    public static function of(string $scope, array $zone, ?array $assignment): string
    {
        if ($scope !== self::SCOPE_ADMIN && $scope !== self::SCOPE_ZONEADMIN) {
            throw new InvalidArgumentException("Unbekannter Geltungsbereich für den Bearbeitungsstand: $scope");
        }

        $servers = null;
        if ($scope === self::SCOPE_ADMIN) {
            $servers = [];
            foreach ($assignment ?? [] as $serverId => $isMaster) {
                $servers[] = [(int)$serverId, (int)$isMaster === 1 ? 1 : 0];
            }
            usort($servers, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }

        $canonical = [
            self::CONTRACT,
            $scope,
            (int)$zone['id'],
            (string)$zone['name'],
            (string)$zone['type'],
            self::intOrNull($zone['ttl']),
            self::intOrNull($zone['prefix_length']),
            $zone['description'] === null ? null : (string)$zone['description'],
            (int)$zone['allow_dyndns'] === 1 ? 1 : 0,
            (string)$zone['soa_ns'],
            (string)$zone['soa_mail'],
            (int)$zone['soa_refresh'],
            (int)$zone['soa_retry'],
            (int)$zone['soa_expire'],
            (int)$zone['soa_minimum'],
            $servers,
        ];

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::CONTRACT . ':' . hash('sha256', $json);
    }

    /** Formal gültiges Token des aktuellen Vertrags? (fehlend/manipuliert → false) */
    public static function isWellFormed(mixed $token): bool
    {
        return is_string($token) && preg_match(self::PATTERN, $token) === 1;
    }

    /** Erwartetes Token entspricht dem berechneten Stand (zeitkonstanter Vergleich)? */
    public static function matches(mixed $expected, string $current): bool
    {
        return self::isWellFormed($expected) && hash_equals($current, $expected);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}
