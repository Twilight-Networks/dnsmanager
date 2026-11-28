<?php
/**
 * Datei: inc/app/publish/SerialReferenceStore.php
 * Zweck: Einziger Schreiber und Leser von zone_serial_references (AS5, Migration 008, D20–D23;
 *        docs/ARCHITECTURE_HISTORY.md Abschnitt 5k). Statisch geprüft: keine andere Stelle greift auf die Tabelle zu.
 *
 * Die Tabelle ist nur anhängend (kein UPDATE, kein DELETE). Jede Zeile gehört zu einem normalisierten Zonennamen und
 * einem Server (außer epoch/run) und trägt einen Ordnungsschlüssel run_key (Lauf-ID bzw. Wasserstand W) sowie
 * order_certain (D16/D23: 0 ⇒ Messzeitpunkt gegenüber anderen Ereignissen nicht sicher bestimmbar).
 *
 * Schreibwege:
 * - recordRun(): Laufmarke (und Epoche) eines AS5-Publish-Laufs nach J1, vor jedem Agent-Aufruf (Write-ahead).
 * - recordIntent(): Write-ahead vor jedem zone_sync; recordAgentCheck()/recordNoReport()/recordLoss() schließen ab.
 *   Ein intent ohne Abschluss ist für die Referenzermittlung ein Referenzverlust (Prozessabbruch, Verbindungsverlust,
 *   Datenbankneustart, gleichzeitig scheiternde Schreibvorgänge).
 * - mirrorObservation(): in der Transaktion des ServerVerificationStore – dieselbe Beobachtung wie der Verification Store
 *   (mit Historie). Eine nicht gespiegelte Vorgängerbeobachtung wird vor dem Überschreiben gesichert; liegt sie nach der
 *   AS5-Epoche, entsteht zusätzlich eine Verlustmarke (zwischenzeitliche Beobachtungen ohne Spiegelung, z. B. Rückfall).
 *   Deduplizierung nur bei unmittelbar vorangehender, identischer, geordneter Monitoring-Zeile mit gleichem Wasserstand
 *   (D20, Ergänzung 3.2): Jede andere Zwischenzeile (Journal-Lauf ⇒ neuer Wasserstand, Admin, Verlust, Ordnungswechsel)
 *   verhindert sie.
 * - recordUncertain(): Beobachtung ohne gesicherte Ordnung (verspätete Antwort, verworfener Wasserstand), order_certain 0.
 * - insertAdmin(): administrative Referenz in der Transaktion des SerialReferenceAdminService (mit Änderungsmenge).
 * Fehler werden immer weitergereicht; die Aufrufer entscheiden über Verlustmarke und Abbruch (D22).
 */

final class SerialReferenceStore
{
    public const SOURCES = ['epoch', 'run', 'intent', 'agent_check', 'no_report', 'loss', 'conf_sync', 'cleanup', 'monitoring', 'admin'];
    public const OBS_SOURCES = ['conf_sync', 'cleanup', 'monitoring'];
    public const FILE_STATES = ['missing', 'valid', 'invalid', 'unreadable'];
    public const CONFIG_STATES = ['yes', 'no', 'undeterminable'];
    public const SERVED_STATES = ['served', 'not_auth', 'unknown'];
    public const DECISIONS = ['accept', 'would_reject', 'reject'];

    public function __construct(private readonly PDO $pdo)
    {
        if ((int)$pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('SerialReferenceStore benötigt PDO::ERRMODE_EXCEPTION');
        }
    }

    /** Normalisierter Zonenname (Kleinbuchstaben, ohne abschließenden Punkt); null = ungültig */
    public static function normName(string $name): ?string
    {
        $n = strtolower(rtrim($name, '.'));

        return preg_match('/^[a-z0-9_][a-z0-9._-]{0,252}$/D', $n) === 1 ? $n : null;
    }

    /** SQL-Ausdruck für den normalisierten Namen einer Spalte (Journal, Verification Store) */
    public static function normSql(string $column): string
    {
        return "LOWER(TRIM(TRAILING '.' FROM $column))";
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* Epoche und Laufmarke                                                                              */
    /* ------------------------------------------------------------------------------------------------ */

    /**
     * Legt die AS5-Epoche an, falls noch keine existiert (Aufrufer hält den Publish-Lock; Autocommit).
     * run_key = Wasserstand bzw. Lauf-ID zum Zeitpunkt des ersten AS5-Schreibvorgangs.
     */
    public function ensureEpoch(int $runKey): void
    {
        $this->assertAutocommit('ensureEpoch');
        $this->pdo->prepare("INSERT INTO zone_serial_references (source, run_key) SELECT 'epoch', ? FROM DUAL"
            . " WHERE NOT EXISTS (SELECT 1 FROM zone_serial_references WHERE source = 'epoch')")->execute([$runKey]);
    }

    /** Laufmarke eines AS5-Publish-Laufs (nach J1, vor dem ersten Agent-Aufruf; Aufrufer hält beide Laufsperren) */
    public function recordRun(int $runId): void
    {
        $this->ensureEpoch($runId);
        $this->pdo->prepare("INSERT INTO zone_serial_references (source, run_key) VALUES ('run', ?)")->execute([$runId]);
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* zone_sync: Write-ahead und Abschluss                                                               */
    /* ------------------------------------------------------------------------------------------------ */

    /** @return int ID des intent */
    public function recordIntent(int $runId, int $attemptId, int $zoneId, string $zoneName, int $serverId, ?string $serverName): int
    {
        $this->assertAutocommit('recordIntent');
        $this->insert(['source' => 'intent', 'zone_id' => $zoneId, 'zone_name' => $this->name($zoneName), 'server_id' => $serverId,
            'server_name' => $serverName, 'run_key' => $runId, 'attempt_id' => $attemptId]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Gültiger serial_check-Bericht (AgentResponseParser::serialCheck). Rohwerte – kein positiver Nachweis.
     *
     * @param array{decision: string, code: ?string, fileState: string, fileSerial: ?int, fileHash: ?string,
     *              configState: string, servedState: string, servedSerial: ?int} $r
     */
    public function recordAgentCheck(int $runId, int $attemptId, int $zoneId, string $zoneName, int $serverId, ?string $serverName,
        array $r, bool $certain): void
    {
        $this->assertAutocommit('recordAgentCheck');
        $this->insert(['source' => 'agent_check', 'zone_id' => $zoneId, 'zone_name' => $this->name($zoneName), 'server_id' => $serverId,
            'server_name' => $serverName, 'run_key' => $runId, 'attempt_id' => $attemptId, 'order_certain' => $certain ? 1 : 0,
            'decision' => $r['decision'], 'code' => $r['code'], 'file_state' => $r['fileState'], 'file_serial' => $r['fileSerial'],
            'file_hash' => $r['fileHash'], 'config_state' => $r['configState'], 'served_state' => $r['servedState'],
            'served_serial' => $r['servedSerial']]);
    }

    /** Abschluss ohne Bericht (Legacy, keine Antwort, Ablehnung vor der Vorher-Prüfung) */
    public function recordNoReport(int $runId, int $attemptId, int $zoneId, string $zoneName, int $serverId, ?string $serverName,
        string $reason, bool $certain): void
    {
        $this->assertAutocommit('recordNoReport');
        $this->insert(['source' => 'no_report', 'zone_id' => $zoneId, 'zone_name' => $this->name($zoneName), 'server_id' => $serverId,
            'server_name' => $serverName, 'run_key' => $runId, 'attempt_id' => $attemptId, 'order_certain' => $certain ? 1 : 0,
            'reason' => $reason]);
    }

    /** Verlustmarke (Autocommit). Sperrt das Paar bis zur administrativen Erfassung beider Bestandteile danach. */
    public function recordLoss(int $runKey, ?int $attemptId, int $zoneId, string $zoneName, int $serverId, ?string $serverName,
        string $reason, bool $certain = true): void
    {
        $this->assertAutocommit('recordLoss');
        $this->insert(['source' => 'loss', 'zone_id' => $zoneId, 'zone_name' => $this->name($zoneName), 'server_id' => $serverId,
            'server_name' => $serverName, 'run_key' => $runKey, 'attempt_id' => $attemptId, 'order_certain' => $certain ? 1 : 0,
            'reason' => $reason]);
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* Beobachtungen                                                                                      */
    /* ------------------------------------------------------------------------------------------------ */

    /**
     * Spiegelt eine angenommene Beobachtung in der Transaktion des ServerVerificationStore (nach dessen FOR UPDATE).
     *
     * @param array<string, mixed>|null $prev bisherige Zeile des Verification Store (vollständig, gesperrt)
     * @param array{state: string, reason: ?string, served: ?int, fileSerial: ?int, fileHash: ?string} $obs
     * @return string inserted | deduplicated
     */
    public function mirrorObservation(?array $prev, string $method, int $runKey, ?int $attemptId, int $zoneId, string $zoneName,
        int $serverId, string $serverName, array $obs): string
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('SerialReferenceStore::mirrorObservation() erfordert die Transaktion des ServerVerificationStore');
        }
        if (!in_array($method, self::OBS_SOURCES, true)) {
            throw new InvalidArgumentException("Ungültige Beobachtungsquelle '$method'");
        }
        $name = $this->name($zoneName);
        if ($prev !== null && !$this->isMirrored($prev)) {
            // Nicht gespiegelte Vorgängerbeobachtung (AS4-Historie oder Lücke) vor dem Überschreiben sichern
            $pm = (string)$prev['last_method'] === 'zone_verify' ? 'monitoring' : (string)$prev['last_method'];
            $this->insert(['source' => $pm, 'zone_id' => (int)$prev['zone_id'], 'zone_name' => $name, 'server_id' => $serverId,
                'server_name' => (string)$prev['server_name'], 'run_key' => (int)($prev['last_run_id'] ?? 0),
                'attempt_id' => $pm === 'conf_sync' ? self::intOrNull($prev['last_attempt_id']) : null,
                'obs_state' => (string)$prev['last_state'], 'reason' => $prev['last_reason'],
                'file_serial' => self::intOrNull($prev['last_file_serial']), 'file_hash' => $prev['last_file_hash'],
                'served_serial' => self::intOrNull($prev['last_served_serial'])], true);
            $epoch = $this->epoch();
            if ($epoch !== null && (string)$prev['last_observed_at'] > (string)$epoch['recorded_at']) {
                $this->insert(['source' => 'loss', 'zone_id' => (int)$prev['zone_id'], 'zone_name' => $name, 'server_id' => $serverId,
                    'server_name' => (string)$prev['server_name'], 'run_key' => (int)($prev['last_run_id'] ?? 0),
                    'reason' => 'unmirrored_observation'], true);
            }
        }
        $row = ['source' => $method, 'zone_id' => $zoneId, 'zone_name' => $name, 'server_id' => $serverId, 'server_name' => $serverName,
            'run_key' => $runKey, 'attempt_id' => $method === 'conf_sync' ? $attemptId : null, 'obs_state' => $obs['state'],
            'reason' => $obs['reason'] ?? null, 'file_serial' => $obs['fileSerial'] ?? null, 'file_hash' => $obs['fileHash'] ?? null,
            'served_serial' => $obs['served'] ?? null];
        if ($method === 'monitoring') {
            $stmt = $this->pdo->prepare('SELECT source, run_key, order_certain, zone_id, obs_state, file_serial, file_hash, served_serial'
                . ' FROM zone_serial_references WHERE zone_name = ? AND server_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$name, $serverId]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt->closeCursor();
            if ($last !== null && $last['source'] === 'monitoring' && (int)$last['order_certain'] === 1 && (int)$last['run_key'] === $runKey
                && (int)$last['zone_id'] === $zoneId && self::sameValues($last, $row)) {
                return 'deduplicated';
            }
        }
        $this->insert($row, true);

        return 'inserted';
    }

    /**
     * Beobachtung ohne gesicherte Ordnung (order_certain 0): verspätete Antwort nach Lock-Verlust, verworfener Wasserstand.
     *
     * @param array{state: string, reason: ?string, served: ?int, fileSerial: ?int, fileHash: ?string} $obs
     */
    public function recordUncertain(string $method, int $runKey, ?int $attemptId, int $zoneId, string $zoneName, int $serverId,
        string $serverName, array $obs): void
    {
        $this->assertAutocommit('recordUncertain');
        if (!in_array($method, self::OBS_SOURCES, true)) {
            throw new InvalidArgumentException("Ungültige Beobachtungsquelle '$method'");
        }
        $this->insert(['source' => $method, 'zone_id' => $zoneId, 'zone_name' => $this->name($zoneName), 'server_id' => $serverId,
            'server_name' => $serverName, 'run_key' => $runKey, 'attempt_id' => $method === 'conf_sync' ? $attemptId : null,
            'order_certain' => 0, 'obs_state' => $obs['state'], 'reason' => $obs['reason'] ?? null,
            'file_serial' => $obs['fileSerial'] ?? null, 'file_hash' => $obs['fileHash'] ?? null, 'served_serial' => $obs['served'] ?? null]);
    }

    /** Administrative Referenz (in der Transaktion des SerialReferenceAdminService) */
    public function insertAdmin(int $runKey, int $zoneId, string $zoneName, int $serverId, string $serverName, ?int $fileSerial,
        ?int $servedSerial, string $evidence, int $changeSetId): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('SerialReferenceStore::insertAdmin() erfordert eine Transaktion');
        }
        $this->insert(['source' => 'admin', 'zone_id' => $zoneId, 'zone_name' => $this->name($zoneName), 'server_id' => $serverId,
            'server_name' => $serverName, 'run_key' => $runKey, 'file_serial' => $fileSerial, 'served_serial' => $servedSerial,
            'evidence' => $evidence, 'change_set_id' => $changeSetId], true);

        return (int)$this->pdo->lastInsertId();
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* Lesen (SerialReferenceReader, SerialReferenceAdminService)                                         */
    /* ------------------------------------------------------------------------------------------------ */

    /** @return array{id: int, run_key: int, recorded_at: string}|null */
    public function epoch(): ?array
    {
        $row = $this->pdo->query("SELECT id, run_key, recorded_at FROM zone_serial_references WHERE source = 'epoch' ORDER BY id LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int)$row['id'], 'run_key' => (int)$row['run_key'], 'recorded_at' => (string)$row['recorded_at']];
    }

    /** @return array<int, true> Lauf-IDs mit AS5-Laufmarke oberhalb von $after */
    public function runMarkers(int $after): array
    {
        $stmt = $this->pdo->prepare("SELECT run_key FROM zone_serial_references WHERE source = 'run' AND run_key > ?");
        $stmt->execute([$after]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $r) {
            $out[(int)$r] = true;
        }

        return $out;
    }

    /**
     * Alle Zeilen der Paare (normalisierter Name × Server), nach id geordnet.
     *
     * @param list<int> $serverIds
     * @return list<array<string, mixed>>
     */
    public function pairRows(string $zoneName, array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($serverIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM zone_serial_references WHERE zone_name = ? AND server_id IN ($in) ORDER BY id");
        $stmt->execute(array_merge([$this->name($zoneName)], array_map('intval', $serverIds)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $rows;
    }

    /** @return list<int> Server mit Referenzzeilen für einen normalisierten Namen (D5) */
    public function serverIdsForName(string $zoneName): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT server_id FROM zone_serial_references WHERE zone_name = ? AND server_id IS NOT NULL');
        $stmt->execute([$this->name($zoneName)]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt->closeCursor();

        return $ids;
    }

    /** @return list<array<string, mixed>> Paare mit Zeilen (für den Status; ohne epoch/run) */
    public function knownPairs(): array
    {
        return $this->pdo->query('SELECT zone_name, server_id, MAX(server_name) AS server_name, COUNT(*) AS n FROM zone_serial_references'
            . ' WHERE zone_name IS NOT NULL GROUP BY zone_name, server_id ORDER BY zone_name, server_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * true, wenn die (aktuelle) Beobachtung einer Zeile des Verification Store als geordnete Zeile vorliegt.
     *
     * @param array<string, mixed> $v Zeile des Verification Store
     */
    public function isMirrored(array $v): bool
    {
        $method = (string)$v['last_method'] === 'zone_verify' ? 'monitoring' : (string)$v['last_method'];
        $stmt = $this->pdo->prepare('SELECT obs_state, file_serial, file_hash, served_serial FROM zone_serial_references'
            . ' WHERE zone_id = ? AND server_id = ? AND source = ? AND run_key = ? AND order_certain = 1 ORDER BY id DESC');
        $stmt->execute([(int)$v['zone_id'], (int)$v['server_id'], $method, (int)($v['last_run_id'] ?? 0)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $want = ['obs_state' => $v['last_state'], 'file_serial' => $v['last_file_serial'], 'file_hash' => $v['last_file_hash'],
            'served_serial' => $v['last_served_serial']];
        foreach ($rows as $r) {
            if (self::sameValues($r, $want)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------------------------------------ */

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function sameValues(array $a, array $b): bool
    {
        foreach (['obs_state', 'file_serial', 'file_hash', 'served_serial'] as $k) {
            $x = $a[$k] ?? null;
            $y = $b[$k] ?? null;
            if (($x === null) !== ($y === null) || ($x !== null && (string)$x !== (string)$y)) {
                return false;
            }
        }

        return true;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int)$v;
    }

    private function name(string $zoneName): string
    {
        $n = self::normName($zoneName);
        if ($n === null) {
            throw new InvalidArgumentException('Ungültiger Zonenname für die Serial-Referenz');
        }

        return $n;
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row, bool $inTrx = false): void
    {
        foreach (array_keys($row) as $c) {
            if (preg_match('/^[a-z_]+$/D', (string)$c) !== 1) {
                throw new LogicException('Ungültige Spalte');
            }
        }
        if (!$inTrx && $this->pdo->inTransaction()) {
            throw new LogicException('SerialReferenceStore: Autocommit-Schreibvorgang in offener Transaktion');
        }
        $cols = array_keys($row);
        $this->pdo->prepare('INSERT INTO zone_serial_references (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    }

    private function assertAutocommit(string $op): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException("SerialReferenceStore::$op() darf nicht innerhalb einer offenen Transaktion aufgerufen werden");
        }
    }
}
