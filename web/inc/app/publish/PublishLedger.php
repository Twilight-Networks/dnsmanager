<?php
/**
 * Datei: inc/app/publish/PublishLedger.php
 * Zweck: Schreibzugriffe des Publish-Journals (AP1/H2d, J1–J8, E57–E73; docs/ARCHITECTURE_HISTORY.md Abschnitt 5d).
 *
 * Einziger Ort, an dem die Journaltabellen publish_runs, zone_publish_attempts, zone_publish_targets und
 * publish_cleanup_results geschrieben werden (statisch geprüft). Fachservices schreiben das Journal nie (R-H9).
 *
 * Zwei Rollen (E57):
 * 1. Autocommit-Rolle – jede Methode weist eine offene Transaktion des Aufrufers ab (LogicException) und verlangt,
 *    dass diese Verbindung den Publish-Lock hält:
 *    - abandonStale()   J6: offene Vorgänge beendeter Läufe in EINER eigenen kurzen Transaktion als "abandoned"
 *    - registerRun()    J1: Lauf vor jedem Seiteneffekt; danach die laufeigene Sperre (E73)
 *    - recordZoneTarget()/recordConfTarget()/finishAttempt()/registerCleanup()/recordCleanup()/finishRun()  J4
 * 2. Transaktionale Rolle – ausschließlich registerAttempt() (J2): nur in der offenen Generator-Transaktion unter der
 *    Zonensperre, nur mit einem offenen Lauf-Token dieses Ledgers, nur solange diese Verbindung Publish-Lock UND
 *    laufeigene Sperre hält; ausschließlich lesende Anweisungen und INSERTs. Ein Fehler ist eine Exception; der
 *    Generator rollt dann zurück (keine Serial, keine Datei, kein Agentaufruf).
 *
 * Unveränderlichkeit (J4a, Q8): Registrierungsdaten werden nie geändert. Lebenszyklusfelder wechseln nur über bedingte
 * UPDATEs vom offenen Zustand (sending bzw. outcome IS NULL bzw. running) in genau einen Endzustand; ein Endzustand wird
 * nie überschrieben (rowCount 0 ⇒ false). Kein DB-Trigger.
 *
 * Identität eines Laufs (J5, E73): Nach der Registrierung hält die Verbindung zusätzlich die laufeigene Sperre
 * "dnsmanager_run_<12 Hex der Datenbank>_<Lauf-ID>". Advisory-Sperren enden mit der Verbindung und mit jedem
 * Neustart des Datenbankservers; eine wiederverwendete Verbindungs-ID hält diese Sperre nie. Lebendig ist ein Lauf
 * nur, wenn er running ist und dieselbe Verbindung (connection_id) beide Sperren hält (PublishJournalReader).
 *
 * AS4b (Migration 007, J4a): Die Agent-Rohwerte je Kanal (Protokoll, effect, installierter Stand, Reload, Fencing,
 * L3-Rohwerte bzw. Cleanup-Zähler) werden im SELBEN bedingten UPDATE wie der Endzustand geschrieben – also genau einmal,
 * nie danach. assertRunAlive() prüft unmittelbar vor jedem Agent-Aufruf Publish-Lock und laufeigene Sperre (E83).
 */

require_once __DIR__ . '/../TopologyLock.php';
require_once __DIR__ . '/../history/HistoryContext.php';
require_once __DIR__ . '/../history/ZoneStateSnapshot.php';
require_once __DIR__ . '/PublishRun.php';
require_once __DIR__ . '/PublishHashContract.php';
require_once __DIR__ . '/RunLockLost.php';
require_once __DIR__ . '/SerialNumber.php';

final class PublishLedger
{
    /** Publish-/Topologie-Lock (identisch mit PublishService::LOCK_NAME) */
    public const MAIN_LOCK = TopologyLock::NAME;

    public const TARGET_RESULTS = ['ok', 'failed', 'rejected', 'unknown'];
    public const CLEANUP_RESULTS = ['ok', 'partial', 'failed', 'rejected', 'unknown'];
    public const ATTEMPT_OUTCOMES = ['published', 'partial', 'failed'];

    /** AS4b: zulässige Agent-Ergebnisfelder je Kanal (Migration 007a/007b) */
    public const AGENT_FIELDS = [
        'zone' => ['zone_protocol', 'zone_effect', 'zone_installed_serial', 'zone_installed_hash', 'zone_reload', 'zone_fence_decision', 'zone_fence_code'],
        'conf' => ['conf_protocol', 'conf_effect', 'conf_reload', 'conf_fence_decision', 'conf_fence_code', 'conf_verify_state',
            'conf_verify_reason', 'conf_verify_file_serial', 'conf_verify_file_hash', 'conf_verify_served_serial'],
        'cleanup' => ['cleanup_protocol', 'cleanup_effect', 'fence_decision', 'fence_code', 'served_absent_count',
            'served_still_count', 'served_unknown_count', 'unassigned_count'],
    ];

    private PDO $pdo;

    /** @var WeakMap<PublishRun, bool> ausgegebene Lauf-Token (true = offen) */
    private WeakMap $runs;

    public function __construct(PDO $pdo)
    {
        if ((int)$pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PublishLedger benötigt eine PDO-Verbindung mit PDO::ERRMODE_EXCEPTION.');
        }
        $this->pdo = $pdo;
        $this->runs = new WeakMap();
    }

    /** Name der laufeigenen Sperre (höchstens 64 Zeichen; Datenbankname als Hash, damit Läufe verschiedener Datenbanken desselben Servers sich nicht verwechseln) */
    public static function runLockName(string $database, int $runId): string
    {
        return 'dnsmanager_run_' . substr(hash('sha256', $database), 0, 12) . '_' . $runId;
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* J6 – Wiederanlauf                                                                                  */
    /* ------------------------------------------------------------------------------------------------ */

    /**
     * Markiert alle offenen Vorgänge beendeter Läufe als "abandoned" (nur Lesbarkeit; J6). Unter dem Publish-Lock ist
     * jeder andere Lauf mit status = running tot; zusätzlich wird geprüft, dass keiner seine laufeigene Sperre hält.
     *
     * @return array{runs: int, attempts: int, zoneTargets: int, confTargets: int, cleanup: int}
     * @throws RuntimeException|PDOException – der Aufrufer bricht vor dem ersten Seiteneffekt ab
     */
    public function abandonStale(): array
    {
        $this->assertAutocommit('abandonStale');
        $db = $this->assertMainLock();

        $stmt = $this->pdo->query("SELECT id FROM publish_runs WHERE status = 'running' ORDER BY id");
        $running = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt->closeCursor();
        $check = $this->pdo->prepare('SELECT IS_USED_LOCK(?)');
        foreach ($running as $id) {
            $check->execute([self::runLockName($db, $id)]);
            $holder = $check->fetchColumn();
            $check->closeCursor();
            if ($holder !== null && $holder !== false) {
                throw new RuntimeException("Lauf $id hält noch seine laufeigene Sperre – keine Markierung");
            }
        }

        $this->pdo->beginTransaction();
        try {
            $n = [];
            $n['zoneTargets'] = $this->pdo->exec("UPDATE zone_publish_targets SET zone_sync = 'abandoned', zone_sync_at = NOW(6) WHERE zone_sync = 'sending'");
            $n['confTargets'] = $this->pdo->exec("UPDATE zone_publish_targets SET conf_sync = 'abandoned', conf_sync_at = NOW(6) WHERE conf_sync = 'sending'");
            $n['cleanup'] = $this->pdo->exec("UPDATE publish_cleanup_results SET status = 'abandoned', finished_at = NOW(6) WHERE status = 'sending'");
            $n['attempts'] = $this->pdo->exec("UPDATE zone_publish_attempts SET outcome = 'abandoned', finished_at = NOW(6) WHERE outcome IS NULL");
            $n['runs'] = $this->pdo->exec("UPDATE publish_runs SET status = 'abandoned', finished_at = NOW(6) WHERE status = 'running'");
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackQuietly();
            throw $e;
        }

        return array_map('intval', $n);
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* J1 – Lauf registrieren                                                                             */
    /* ------------------------------------------------------------------------------------------------ */

    /**
     * Registriert einen Lauf vor jedem Seiteneffekt (J1) und bezieht danach die laufeigene Sperre (E73).
     *
     * @param string   $kind            all | zone
     * @param int|null $requestedZoneId nur bei zone
     * @throws RuntimeException|PDOException|LogicException – der Aufrufer bricht ohne Seiteneffekt ab (Status exception)
     */
    public function registerRun(string $kind, ?int $requestedZoneId, bool $fullRebuild, HistoryContext $ctx): PublishRun
    {
        $this->assertAutocommit('registerRun');
        if (!in_array($kind, ['all', 'zone'], true) || ($kind === 'zone') !== ($requestedZoneId !== null) || ($kind === 'zone' && $fullRebuild)) {
            throw new InvalidArgumentException('Ungültige Laufangaben');
        }
        $db = $this->assertMainLock();

        $row = $this->pdo->query('SELECT CONNECTION_ID() AS c, (SELECT COALESCE(MAX(id), 0) FROM history_change_sets) AS w')->fetch(PDO::FETCH_ASSOC);
        $connectionId = (int)$row['c'];

        $stmt = $this->pdo->prepare(
            'INSERT INTO publish_runs (kind, requested_zone_id, full_rebuild, connection_id, history_watermark, actor_type,'
            . ' actor_user_id, actor_dyndns_account_id, channel, correlation_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $kind, $requestedZoneId, $fullRebuild ? 1 : 0, $connectionId, (int)$row['w'], $ctx->actorType,
            $ctx->userId, $ctx->dyndnsAccountId, $ctx->channel, $ctx->correlationId,
        ]);
        $runId = (int)$this->pdo->lastInsertId();
        $lock = self::runLockName($db, $runId);

        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$lock]);
        $got = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($got === null || $got === false || (int)$got !== 1) {
            $this->pdo->prepare("UPDATE publish_runs SET status = 'finished', result = 'exception', finished_at = NOW(6) WHERE id = ? AND status = 'running'")
                ->execute([$runId]);
            throw new RuntimeException("Laufeigene Sperre für Lauf $runId nicht verfügbar");
        }

        $run = new PublishRun($runId, $kind, $fullRebuild, $connectionId, $lock);
        $this->runs[$run] = true;

        return $run;
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* J2 – Versuch atomar mit der Serial (transaktionale Rolle)                                          */
    /* ------------------------------------------------------------------------------------------------ */

    /**
     * Registriert in der offenen Generator-Transaktion den Versuch und je Zielserver eine Zielzeile (beide Kanäle
     * "sending"). Aufruf unmittelbar vor dem Serial-Commit; die Zone ist bereits gesperrt (zones … FOR UPDATE).
     *
     * @param array<string, mixed>             $zoneRow      Zonenzeile, wie sie der Generator gelesen hat
     * @param array<int, array<string, mixed>> $records      Recordzeilen, wie sie der Generator rendert
     * @param string                           $fileBytes    exakte Bytes der Zonendatei
     * @param string                           $maskedRender Rendering mit PublishHashContract::SERIAL_PLACEHOLDER
     * @return array{attemptId: int, runId: int, zoneId: int, zoneName: string, revisionNo: ?int, revisionBasis: string,
     *               contentHash: string, renderHash: string, fileHash: string, serial: int, targets: array<int, string>}
     * @throws LogicException|RuntimeException|PDOException – der Generator rollt zurück
     */
    public function registerAttempt(PublishRun $run, int $zoneId, array $zoneRow, array $records, int $serial, string $fileBytes, string $maskedRender): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('PublishLedger::registerAttempt() erfordert die offene Generator-Transaktion');
        }
        $this->assertOpenRun($run);
        $this->assertRunLocks($run);
        if ($serial < 0 || $serial > PublishHashContract::SERIAL_MAX) {
            throw new RuntimeException('Serial außerhalb von 0 … 2^32-1');
        }
        // AS5 (D9): Verteidigung in der Tiefe – die neue Serial muss nach RFC 1982 neuer sein als die gesperrte DB-Serial
        $dbSerial = SerialNumber::parse(is_int($zoneRow['soa_serial'] ?? null) ? $zoneRow['soa_serial'] : (string)($zoneRow['soa_serial'] ?? ''));
        if ($dbSerial === null || SerialNumber::compare($serial, $dbSerial) !== SerialNumber::NEWER) {
            throw new RuntimeException('Serial nicht nach RFC 1982 neuer als die DB-Serial (D9)');
        }

        // Zone (bereits vom Generator gesperrt) erneut sperren – No-op, belegt die Sperre unabhängig vom Aufrufer
        $stmt = $this->pdo->prepare('SELECT name FROM zones WHERE id = ? FOR UPDATE');
        $stmt->execute([$zoneId]);
        $lockedName = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($lockedName === false || (string)$lockedName !== (string)($zoneRow['name'] ?? '')) {
            throw new RuntimeException("Zone $zoneId nicht (mehr) vorhanden oder abweichend");
        }

        // Serverzuweisung, Zielserver und Head – nicht sperrend im Read-View nach der Zonensperre (unter RR derselbe
        // Read-View wie die gerenderten Zeilen; Zuweisung und Head ändern sich nur unter zones X, Zielmenge und
        // servers.active nur unter dem Publish-Lock)
        $stmt = $this->pdo->prepare('SELECT server_id, is_master FROM zone_servers WHERE zone_id = ? ORDER BY server_id');
        $stmt->execute([$zoneId]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.name FROM zone_servers zs JOIN servers s ON s.id = zs.server_id WHERE zs.zone_id = ? AND s.active = 1 ORDER BY s.id'
        );
        $stmt->execute([$zoneId]);
        $targets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $targets[(int)$t['id']] = (string)$t['name'];
        }
        $stmt->closeCursor();
        $stmt = $this->pdo->prepare('SELECT head_revision_no, head_hash, deleted_at FROM zone_history_state WHERE zone_id = ?');
        $stmt->execute([$zoneId]);
        $head = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        // Hashes (E60): content_hash aus genau den gerenderten Zeilen; render_hash nach rh1 mit Gegenprobe
        $snapshot = ZoneStateSnapshot::fromRows($zoneRow, $assignments, $records);
        $contentHash = $snapshot->contentHash();
        if (PublishHashContract::maskSerial($fileBytes, $serial) !== $maskedRender) {
            throw new RuntimeException('rh1: maskierte Datei weicht vom Rendering mit Platzhalter ab');
        }
        $renderHash = PublishHashContract::sha256($maskedRender);
        $fileHash = PublishHashContract::sha256($fileBytes);

        // Revisionsbezug (E61): nur bei identischem Inhalt des nicht gelöschten Heads
        if ($head === false || $head['deleted_at'] !== null) {
            [$revisionNo, $basis] = [null, 'no_head'];
        } elseif (hash_equals((string)$head['head_hash'], $contentHash)) {
            [$revisionNo, $basis] = [(int)$head['head_revision_no'], 'head'];
        } else {
            [$revisionNo, $basis] = [null, 'head_mismatch'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO zone_publish_attempts (run_id, zone_id, zone_name, revision_no, revision_basis, content_hash, snapshot_contract,'
            . ' render_hash, file_hash, render_contract, soa_serial, target_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $run->id, $zoneId, (string)$zoneRow['name'], $revisionNo, $basis, $contentHash, $snapshot->contract(),
            $renderHash, $fileHash, PublishHashContract::RENDER_CONTRACT, $serial, count($targets),
        ]);
        $attemptId = (int)$this->pdo->lastInsertId();

        if ($targets !== []) {
            $values = [];
            $params = [];
            foreach ($targets as $serverId => $serverName) {
                $values[] = '(?, ?, ?)';
                array_push($params, $attemptId, $serverId, $serverName);
            }
            $this->pdo->prepare('INSERT INTO zone_publish_targets (attempt_id, server_id, server_name) VALUES ' . implode(', ', $values))
                ->execute($params);
        }

        return [
            'attemptId' => $attemptId, 'runId' => $run->id, 'zoneId' => $zoneId, 'zoneName' => (string)$zoneRow['name'],
            'revisionNo' => $revisionNo, 'revisionBasis' => $basis, 'contentHash' => $contentHash,
            'renderHash' => $renderHash, 'fileHash' => $fileHash, 'serial' => $serial, 'targets' => $targets,
        ];
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* J4 – Ergebnisse (Autocommit, je Agentenantwort)                                                    */
    /* ------------------------------------------------------------------------------------------------ */

    /**
     * @param array{status: string, httpStatus: ?int, errorCode: ?string, errorDetail: ?string} $c PublishTargetClassifier
     * @return bool true = eingetragen; false = kein offener Kanal (Endzustand bleibt unverändert, J4a)
     */
    public function recordZoneTarget(PublishRun $run, int $attemptId, int $serverId, array $c, array $agent = []): bool
    {
        return $this->recordChannel($run, 'zone', $attemptId, $serverId, $c, null, $agent);
    }

    /** @param array<string, mixed> $agent AS4b: Agent-Ergebnisfelder (AgentResponseParser::journalFields) */
    public function recordConfTarget(PublishRun $run, int $attemptId, int $serverId, array $c, ?string $confHash, array $agent = []): bool
    {
        if ($confHash !== null && !PublishHashContract::isHash($confHash)) {
            throw new InvalidArgumentException('Ungültiger conf_hash');
        }

        return $this->recordChannel($run, 'conf', $attemptId, $serverId, $c, $confHash, $agent);
    }

    /** Ergebnis des Versuchs (operativer Ausgang von processZone; keine L2/L3-Bestätigung, E65) */
    public function finishAttempt(PublishRun $run, int $attemptId, string $outcome): bool
    {
        $this->assertAutocommit('finishAttempt');
        $this->assertOpenRun($run);
        if (!in_array($outcome, self::ATTEMPT_OUTCOMES, true)) {
            throw new InvalidArgumentException("Ungültiges Ergebnis '$outcome'");
        }
        $stmt = $this->pdo->prepare('UPDATE zone_publish_attempts SET outcome = ?, finished_at = NOW(6) WHERE id = ? AND run_id = ? AND outcome IS NULL');
        $stmt->execute([$outcome, $attemptId, $run->id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Registriert vor dem ersten zone_cleanup alle angesprochenen Server mit "sending" (eine Anweisung, E66).
     *
     * @param array<int, string> $servers server_id => Name
     */
    public function registerCleanup(PublishRun $run, array $servers): void
    {
        $this->assertAutocommit('registerCleanup');
        $this->assertOpenRun($run);
        if ($servers === []) {
            return;
        }
        $values = [];
        $params = [];
        foreach ($servers as $serverId => $name) {
            $values[] = '(?, ?, ?)';
            array_push($params, $run->id, (int)$serverId, (string)$name);
        }
        $this->pdo->prepare('INSERT INTO publish_cleanup_results (run_id, server_id, server_name) VALUES ' . implode(', ', $values))
            ->execute($params);
    }

    /** @param array{status: string, httpStatus: ?int, errorCode: ?string, errorDetail: ?string, removedConf: ?int, removedZoneFiles: ?int} $c */
    public function recordCleanup(PublishRun $run, int $serverId, array $c, ?int $validZoneCount, array $agent = []): bool
    {
        $this->assertAutocommit('recordCleanup');
        $this->assertOpenRun($run);
        if (!in_array($c['status'] ?? null, self::CLEANUP_RESULTS, true)) {
            throw new InvalidArgumentException('Ungültiges Cleanup-Ergebnis');
        }
        [$agentSet, $agentParams] = self::agentSet('cleanup', $agent, '');
        $counts = in_array($c['status'], ['ok', 'partial'], true);
        $stmt = $this->pdo->prepare(
            'UPDATE publish_cleanup_results SET status = ?, finished_at = NOW(6), valid_zone_count = ?, removed_conf_count = ?,'
            . ' removed_zone_file_count = ?, http_status = ?, error_code = ?, error_detail = ?' . $agentSet
            . " WHERE run_id = ? AND server_id = ? AND status = 'sending'"
        );
        $stmt->execute(array_merge([
            $c['status'], $validZoneCount, $counts ? (int)$c['removedConf'] : null, $counts ? (int)$c['removedZoneFiles'] : null,
            $c['httpStatus'], $c['errorCode'], $c['errorDetail']], $agentParams, [$run->id, $serverId,
        ]));

        return $stmt->rowCount() === 1;
    }

    /**
     * Schließt den Lauf ab (status finished, Ergebnis = Service-Status). Danach ist das Token geschlossen.
     */
    public function finishRun(PublishRun $run, string $result): bool
    {
        $this->assertAutocommit('finishRun');
        $this->assertOpenRun($run);
        if (preg_match('/^[a-z_]{1,32}$/D', $result) !== 1) {
            throw new InvalidArgumentException('Ungültiges Laufergebnis');
        }
        $this->runs[$run] = false;
        $stmt = $this->pdo->prepare("UPDATE publish_runs SET status = 'finished', result = ?, finished_at = NOW(6) WHERE id = ? AND status = 'running'");
        $stmt->execute([$result, $run->id]);

        return $stmt->rowCount() === 1;
    }

    /** Gibt die laufeigene Sperre frei (im finally des Laufs, vor dem Publish-Lock); Fehler werden nicht gemeldet */
    public function releaseRunLock(PublishRun $run): void
    {
        $this->runs[$run] = false;
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$run->runLock]);
            $stmt->closeCursor();
        } catch (Throwable $e) {
            // Sperre endet spätestens mit der Verbindung
        }
    }

    /* ------------------------------------------------------------------------------------------------ */

    private function recordChannel(PublishRun $run, string $ch, int $attemptId, int $serverId, array $c, ?string $confHash, array $agent = []): bool
    {
        $this->assertAutocommit('record' . ucfirst($ch) . 'Target');
        $this->assertOpenRun($run);
        if (!in_array($c['status'] ?? null, self::TARGET_RESULTS, true)) {
            throw new InvalidArgumentException('Ungültiges Zielergebnis');
        }
        [$agentSet, $agentParams] = self::agentSet($ch, $agent, 't.');
        $hashSet = $ch === 'conf' ? ', t.conf_hash = ?' : '';
        $sql = "UPDATE zone_publish_targets t JOIN zone_publish_attempts a ON a.id = t.attempt_id"
            . " SET t.{$ch}_sync = ?, t.{$ch}_sync_at = NOW(6), t.{$ch}_http_status = ?, t.{$ch}_error_code = ?, t.{$ch}_error_detail = ?$hashSet$agentSet"
            . " WHERE t.attempt_id = ? AND t.server_id = ? AND a.run_id = ? AND t.{$ch}_sync = 'sending'";
        $params = [$c['status'], $c['httpStatus'], $c['errorCode'], $c['errorDetail']];
        if ($ch === 'conf') {
            $params[] = $confHash;
        }
        array_push($params, ...$agentParams);
        array_push($params, $attemptId, $serverId, $run->id);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() === 1;
    }

    /**
     * AS4b (E83): unmittelbar vor jedem Agent-Aufruf – Publish-Lock und laufeigene Sperre auf der Verbindung des Laufs.
     *
     * @throws RunLockLost wenn eine Sperre nicht mehr gehalten wird oder die Prüfung selbst scheitert (fail-closed)
     */
    public function assertRunAlive(PublishRun $run): void
    {
        try {
            $this->assertRunLocks($run);
        } catch (Throwable $e) {
            throw new RunLockLost("Lauf {$run->id}: Laufberechtigung verloren – kein weiterer Agent-Aufruf (" . $e->getMessage() . ')', 0, $e);
        }
    }

    /**
     * SET-Teil der Agent-Ergebnisfelder (nur Schlüssel aus AGENT_FIELDS; fehlende Schlüssel werden nicht geschrieben).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function agentSet(string $channel, array $agent, string $prefix): array
    {
        $set = '';
        $params = [];
        foreach ($agent as $col => $value) {
            if (!in_array($col, self::AGENT_FIELDS[$channel], true)) {
                throw new InvalidArgumentException("Unbekanntes Agent-Ergebnisfeld '$col'");
            }
            if ($value !== null && !is_int($value) && !is_string($value)) {
                throw new InvalidArgumentException("Ungültiger Wert für '$col'");
            }
            $set .= ", {$prefix}{$col} = ?";
            $params[] = $value;
        }

        return [$set, $params];
    }

    private function assertAutocommit(string $op): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException("PublishLedger::$op() darf nicht innerhalb einer offenen Transaktion aufgerufen werden");
        }
    }

    private function assertOpenRun(PublishRun $run): void
    {
        if (!isset($this->runs[$run]) || $this->runs[$run] !== true) {
            throw new LogicException('Kein offenes Lauf-Token dieses Ledgers');
        }
    }

    /** @return string Name der aktuellen Datenbank */
    private function assertMainLock(): string
    {
        $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS held, DATABASE() AS db');
        $stmt->execute([self::MAIN_LOCK]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false || (int)$row['held'] !== 1) {
            throw new RuntimeException('Publish-Lock wird von dieser Verbindung nicht gehalten');
        }

        return (string)$row['db'];
    }

    private function assertRunLocks(PublishRun $run): void
    {
        $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AND IS_USED_LOCK(?) = CONNECTION_ID() AND CONNECTION_ID() = ?');
        $stmt->execute([self::MAIN_LOCK, $run->runLock, $run->connectionId]);
        $held = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($held === null || $held === false || (int)$held !== 1) {
            throw new RuntimeException("Lauf {$run->id}: Publish-Lock oder laufeigene Sperre nicht (mehr) gehalten");
        }
    }

    private function rollbackQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable $e) {
            // nichts
        }
    }
}
