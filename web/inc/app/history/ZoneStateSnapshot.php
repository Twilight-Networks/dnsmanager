<?php
/**
 * Datei: inc/app/history/ZoneStateSnapshot.php
 * Zweck: Kanonischer, versionierter Snapshot des DNS-Inhalts einer Zone mit SHA-256-Inhaltshash
 *        (AP1/H2a, docs/ARCHITECTURE_HISTORY.md Abschnitte 3 und 17).
 *
 * Rein: keine Datenbank, keine Session, kein Logging. Eingaben sind Zeilen wie von PDO geliefert (Zahlen auch als
 * Zeichenketten); das Lesen aus der Datenbank übernimmt ZoneSnapshotReader.
 *
 * Vertrag "zs1" (Payload = kanonisches JSON, Hash = SHA-256 hex klein über die Payload-Bytes):
 *   {"contract":"zs1",
 *    "zone":{"name":s,"type":s,"ttl":i|null,"prefix_length":i|null,"soa_ns":s,"soa_mail":s,
 *            "soa_refresh":i,"soa_retry":i,"soa_expire":i,"soa_minimum":i},
 *    "servers":[[server_id:i,is_master:0|1], …]                 aufsteigend nach server_id
 *    "records":[[name:s,type:s,content:s,ttl:i|null,server_id:i|null], …]}
 * - Schlüsselreihenfolge fest wie oben; json_encode mit JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, ohne
 *   Leerraum. Ungültiges UTF-8 ist ein Fehler (kein Ersatzzeichen).
 * - Records sind eine Multimenge: Duplikate bleiben erhalten. Sortierung nach Bytes (strcmp) über name, type, content,
 *   danach ttl und server_id (NULL vor Zahlen, Zahlen numerisch) – unabhängig von Collation und Eingabereihenfolge.
 * - Werte wie gespeichert: kein Trimmen, keine Änderung der Groß-/Kleinschreibung. NULL, "" und 0 bleiben
 *   unterscheidbar. Ganzzahlen werden streng geprüft (int oder Dezimalziffern), sonst InvalidArgumentException.
 * - Whitelist: Nur die genannten Felder werden übernommen; zusätzliche Eingabefelder (z. B. id, soa_serial, changed,
 *   description, allow_dyndns, Passwort-Hashes, Tokens) werden ignoriert. Systemrecords sind über server_id ≠ NULL
 *   gekennzeichnet; Servernamen und -adressen sind nicht enthalten.
 * - Nicht enthalten ist die Zonen-ID: Gleicher Inhalt ergibt denselben Hash (Deduplizierung in zone_snapshots).
 *
 * Eine Änderung an Feldern, Typen, Reihenfolge oder Kodierung erfordert eine neue Vertragskennung.
 */

final class ZoneStateSnapshot
{
    /** Kennung des Kanonisierungsvertrags */
    public const CONTRACT = 'zs1';

    private const ZONE_STRING_FIELDS = ['name', 'type', 'soa_ns', 'soa_mail'];
    private const ZONE_FIELDS = [
        'name', 'type', 'ttl', 'prefix_length', 'soa_ns', 'soa_mail',
        'soa_refresh', 'soa_retry', 'soa_expire', 'soa_minimum',
    ];
    private const ZONE_NULLABLE_INT_FIELDS = ['ttl', 'prefix_length'];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param array{contract: string, zone: array<string, mixed>, servers: list<array{0: int, 1: int}>,
     *              records: list<array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}>} $data
     */
    private function __construct(
        private readonly array $data,
        private readonly string $payload,
        private readonly string $hash
    ) {
    }

    /**
     * Erzeugt den Snapshot aus Datenbankzeilen.
     *
     * @param array<string, mixed>             $zone        Zonenzeile (mindestens die Felder des Vertrags)
     * @param array<int, array<string, mixed>> $assignments Zeilen mit server_id, is_master
     * @param array<int, array<string, mixed>> $records     Zeilen mit name, type, content, ttl, server_id
     *
     * @throws InvalidArgumentException bei fehlenden Feldern, ungültigen Typen oder doppelter Serverzuweisung
     * @throws JsonException bei nicht kodierbaren Werten (ungültiges UTF-8)
     */
    public static function fromRows(array $zone, array $assignments, array $records): self
    {
        $z = [];
        foreach (self::ZONE_FIELDS as $field) {
            if (!array_key_exists($field, $zone)) {
                throw new InvalidArgumentException("Zonenfeld fehlt: $field");
            }
            if (in_array($field, self::ZONE_STRING_FIELDS, true)) {
                $z[$field] = self::str($zone[$field], "zone.$field");
            } elseif (in_array($field, self::ZONE_NULLABLE_INT_FIELDS, true)) {
                $z[$field] = self::intOrNull($zone[$field], "zone.$field");
            } else {
                $z[$field] = self::int($zone[$field], "zone.$field");
            }
        }

        $servers = [];
        $seen = [];
        foreach ($assignments as $i => $row) {
            if (!is_array($row) || !array_key_exists('server_id', $row) || !array_key_exists('is_master', $row)) {
                throw new InvalidArgumentException("Serverzuweisung $i unvollständig");
            }
            $serverId = self::int($row['server_id'], "servers[$i].server_id");
            $isMaster = self::int($row['is_master'], "servers[$i].is_master");
            if ($isMaster !== 0 && $isMaster !== 1) {
                throw new InvalidArgumentException("servers[$i].is_master ist nicht 0 oder 1");
            }
            if (isset($seen[$serverId])) {
                throw new InvalidArgumentException("Server $serverId mehrfach zugewiesen");
            }
            $seen[$serverId] = true;
            $servers[] = [$serverId, $isMaster];
        }

        $recs = [];
        foreach ($records as $i => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException("Record $i ist keine Zeile");
            }
            foreach (['name', 'type', 'content', 'ttl', 'server_id'] as $field) {
                if (!array_key_exists($field, $row)) {
                    throw new InvalidArgumentException("Recordfeld fehlt: records[$i].$field");
                }
            }
            $recs[] = [
                self::str($row['name'], "records[$i].name"),
                self::str($row['type'], "records[$i].type"),
                self::str($row['content'], "records[$i].content"),
                self::intOrNull($row['ttl'], "records[$i].ttl"),
                self::intOrNull($row['server_id'], "records[$i].server_id"),
            ];
        }

        return self::build($z, $servers, $recs);
    }

    /**
     * Stellt einen Snapshot aus einer gespeicherten Payload wieder her. Die Payload muss exakt kanonisch sein
     * (Neuberechnung byte-identisch), sonst InvalidArgumentException.
     *
     * @throws InvalidArgumentException bei unbekanntem Vertrag, Strukturfehler oder nicht kanonischer Payload
     */
    public static function fromPayload(string $payload): self
    {
        try {
            $d = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Payload ist kein gültiges JSON', 0, $e);
        }
        if (!is_array($d) || array_keys($d) !== ['contract', 'zone', 'servers', 'records']) {
            throw new InvalidArgumentException('Payload-Struktur ungültig');
        }
        if ($d['contract'] !== self::CONTRACT) {
            throw new InvalidArgumentException('Unbekannter Snapshot-Vertrag');
        }
        if (!is_array($d['zone']) || array_keys($d['zone']) !== self::ZONE_FIELDS
            || !is_array($d['servers']) || !array_is_list($d['servers'])
            || !is_array($d['records']) || !array_is_list($d['records'])) {
            throw new InvalidArgumentException('Payload-Struktur ungültig');
        }

        $assignments = [];
        foreach ($d['servers'] as $s) {
            if (!is_array($s) || !array_is_list($s) || count($s) !== 2 || !is_int($s[0]) || !is_int($s[1])) {
                throw new InvalidArgumentException('Payload-Serverzuweisung ungültig');
            }
            $assignments[] = ['server_id' => $s[0], 'is_master' => $s[1]];
        }
        $records = [];
        foreach ($d['records'] as $r) {
            if (!is_array($r) || !array_is_list($r) || count($r) !== 5
                || !is_string($r[0]) || !is_string($r[1]) || !is_string($r[2])
                || !($r[3] === null || is_int($r[3])) || !($r[4] === null || is_int($r[4]))) {
                throw new InvalidArgumentException('Payload-Record ungültig');
            }
            $records[] = ['name' => $r[0], 'type' => $r[1], 'content' => $r[2], 'ttl' => $r[3], 'server_id' => $r[4]];
        }
        foreach (self::ZONE_FIELDS as $field) {
            $v = $d['zone'][$field];
            $ok = in_array($field, self::ZONE_STRING_FIELDS, true) ? is_string($v)
                : (in_array($field, self::ZONE_NULLABLE_INT_FIELDS, true) ? ($v === null || is_int($v)) : is_int($v));
            if (!$ok) {
                throw new InvalidArgumentException("Payload-Zonenfeld ungültig: $field");
            }
        }

        $snapshot = self::fromRows($d['zone'], $assignments, $records);
        if ($snapshot->payload !== $payload) {
            throw new InvalidArgumentException('Payload ist nicht kanonisch');
        }

        return $snapshot;
    }

    /** Kanonische Payload (JSON, UTF-8) */
    public function payload(): string
    {
        return $this->payload;
    }

    /** Inhaltshash: SHA-256 hex (64 Zeichen, klein) über die Payload */
    public function contentHash(): string
    {
        return $this->hash;
    }

    public function contract(): string
    {
        return self::CONTRACT;
    }

    public function recordCount(): int
    {
        return count($this->data['records']);
    }

    /** Zonenname laut Snapshot */
    public function zoneName(): string
    {
        return $this->data['zone']['name'];
    }

    /**
     * Kanonische Daten (Zone, Serverzuweisungen, Records) in Vertragsform.
     *
     * @return array{contract: string, zone: array<string, mixed>, servers: list<array{0: int, 1: int}>,
     *               records: list<array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}>}
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Vergleich zweier Records in Vertragsreihenfolge (Bytes, dann ttl und server_id; NULL zuerst).
     *
     * @param array{0: string, 1: string, 2: string, 3: ?int, 4: ?int} $a
     * @param array{0: string, 1: string, 2: string, 3: ?int, 4: ?int} $b
     */
    public static function compareRecords(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            $c = strcmp($a[$i], $b[$i]);
            if ($c !== 0) {
                return $c < 0 ? -1 : 1;
            }
        }
        for ($i = 3; $i < 5; $i++) {
            $c = self::compareNullableInt($a[$i], $b[$i]);
            if ($c !== 0) {
                return $c;
            }
        }

        return 0;
    }

    public static function compareNullableInt(?int $a, ?int $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === null) {
            return -1;
        }
        if ($b === null) {
            return 1;
        }

        return $a <=> $b;
    }

    /**
     * @param array<string, mixed>                                           $zone
     * @param list<array{0: int, 1: int}>                                     $servers
     * @param list<array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}> $records
     */
    private static function build(array $zone, array $servers, array $records): self
    {
        usort($servers, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        usort($records, [self::class, 'compareRecords']);

        $data = [
            'contract' => self::CONTRACT,
            'zone'     => $zone,
            'servers'  => $servers,
            'records'  => $records,
        ];
        $payload = json_encode($data, self::JSON_FLAGS);

        return new self($data, $payload, hash('sha256', $payload));
    }

    private static function str(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("$field ist keine Zeichenkette");
        }

        return $value;
    }

    private static function int(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]{0,18})$/D', $value) === 1) {
            $int = (int)$value;
            if ((string)$int === $value) {
                return $int;
            }
        }

        throw new InvalidArgumentException("$field ist keine Ganzzahl");
    }

    private static function intOrNull(mixed $value, string $field): ?int
    {
        return $value === null ? null : self::int($value, $field);
    }
}
