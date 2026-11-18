<?php
/**
 * Datei: inc/app/history/ZoneHistoryRecorder.php
 * Zweck: Atomare Erfassung der Zonenhistorie in DNS-relevanten Fachtransaktionen (AP1/H2b; R-H1–R-H9,
 *        docs/ARCHITECTURE_HISTORY.md Abschnitte 5b und 6).
 *
 * Eine Instanz je Fachtransaktion. Ablauf im Service:
 *   0. ensureHeads($pdo, $zoneIds, $ctx)   VOR beginTransaction(): Lazy-Baseline für Zonen ohne Head in eigener,
 *                                          kurzer Transaktion je Zone (E35, Muster der H2a-Baseline)
 *   1. beginTransaction() → users (S) → user_zones (S/X) → ALLE zones (X, aufsteigend)
 *   2. $h = open($pdo, $ctx)               Klammer an Verbindung UND Transaktion binden (Savepoint, E39)
 *   3. $h->beginZones($ids)                je Zone aufsteigend: zones X bestätigen → zone_history_state FOR UPDATE
 *                                          (Satzsperre auf vorhandener Zeile) → Vorher-Snapshot sperrend lesen.
 *                                          Ausschließlich sperrende Lesevorgänge, keine Schreibvorgänge: Es entsteht
 *                                          kein Read-View (MariaDB 11.8, innodb_snapshot_isolation, Fehler 1020).
 *      $h->zoneCreated($id)                bei Zonenanlage nach dem INSERT der Zone
 *   4. Fachänderung (Fachtabellen, ZoneBuildBackend – nur mit offener Klammer für die Zone zulässig)
 *      $h->zoneDeleting($id)               vor DELETE FROM zones (Inhalt = Vorher-Snapshot, R-H5)
 *   5. $h->finish()                        unmittelbar vor commit(): Drift-, Änderungs-, Anlage- und Löschrevisionen,
 *                                          Snapshots, Änderungsmengen, Head, Abhängigkeiten
 *   6. commit() bzw. Rollback; in jedem Fall $h->close() (finally)
 *
 * Regeln:
 * - R-H1: Alle Historienzeilen in der Fachtransaktion; jeder Fehler ist eine Exception, der Aufrufer rollt zurück.
 * - R-H2 (präzisiert, E35): Vorhandene Heads werden unmittelbar nach den Zonensperren mit FOR UPDATE gesperrt. Ein
 *   fehlender Head in der Fachtransaktion ist ein Fehler (Rollback) – es wird keine Gap-Sperre gehalten, auf die
 *   gewartet würde, und kein Head in der Fachtransaktion angelegt. Fehlende Heads legt ensureHeads() vorher an.
 * - R-H3: Vorher-Hash ≠ Head ⇒ zuerst Revision "drift" in einer Änderungsmenge des Akteurs "system".
 * - R-H4: Nachher-Hash = Head ⇒ keine Revision. Änderungsmengen entstehen erst mit der ersten Revision.
 * - R-H5: Zonenlöschung ⇒ Revision "delete" mit dem Inhalt vor dem DELETE, Head mit deleted_at.
 * - R-H6: Mehrere Zonen ⇒ eine Änderungsmenge des Akteurs, je Zone eine Revision, Zonen aufsteigend.
 * - R-H7: AutoPTR ⇒ eigene Änderungsmenge (T2) mit Korrelation auf T1 und Eintrag in history_dependencies.
 * - R-H8: ZoneBuildBackend verlangt eine offene Klammer (assertOpenFor()).
 *
 * Keine Session, kein HTTP, kein Logging.
 */

require_once __DIR__ . '/HistoryContext.php';
require_once __DIR__ . '/HistoryWriter.php';
require_once __DIR__ . '/ZoneSnapshotDiff.php';
require_once __DIR__ . '/ZoneSnapshotReader.php';
require_once __DIR__ . '/ZoneSnapshotStore.php';
require_once __DIR__ . '/ZoneStateSnapshot.php';

final class ZoneHistoryRecorder
{
    public const OP_DRIFT = 'history.drift';
    public const OP_BASELINE_LAZY = 'history.baseline_lazy';
    public const KIND_AUTO_PTR = 'auto_ptr';

    /** @var array<int, array{pdo: WeakReference, recorder: WeakReference}> Klammer-Register je Verbindung */
    private static array $scopes = [];

    private readonly string $savepoint;
    private bool $open = true;
    private bool $finished = false;

    /** @var array<int, array{pre: ZoneStateSnapshot, headNo: int, headHash: string, deleting: bool}> */
    private array $begun = [];
    /** @var array<int, true> */
    private array $created = [];
    /** @var list<array> */
    private array $dependencies = [];

    private ?int $actorChangeSetId = null;
    private ?int $driftChangeSetId = null;
    /** @var array<int, int> Zone => Revisionsnummer der Akteursrevision */
    private array $actorRevisions = [];

    private function __construct(private readonly PDO $pdo, private readonly HistoryContext $ctx)
    {
        $this->savepoint = 'dnsmgr_hist_' . bin2hex(random_bytes(8));
    }

    /* ======================================================================================== */
    /* Lazy-Baseline vor der Fachtransaktion (E35)                                              */
    /* ======================================================================================== */

    /**
     * Legt für Zonen ohne History-Head je eine Baseline an – jeweils in einer eigenen, kurzen Transaktion vor der
     * Fachtransaktion: zones X → Snapshot sperrend lesen → Head nicht sperrend prüfen (der Read-View entsteht erst
     * nach der Zonensperre; Heads werden nur unter ihr angelegt) → Baseline → Commit. Keine Gap-Sperren, keine
     * Kopplung fremder Zonen. Nicht vorhandene Zonen werden übergangen.
     *
     * @param int[] $zoneIds
     * @return int[] Zonen, für die eine Baseline angelegt wurde
     *
     * @throws LogicException in offener Transaktion
     * @throws Throwable bei Datenbank- oder Snapshotfehlern (die laufende Zone ist zurückgerollt)
     */
    public static function ensureHeads(PDO $pdo, array $zoneIds, HistoryContext $ctx): array
    {
        if ($pdo->inTransaction()) {
            throw new LogicException('ZoneHistoryRecorder::ensureHeads() ist vor der Fachtransaktion aufzurufen');
        }
        $ids = self::normalizeIds($zoneIds);
        if ($ids === []) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT z.id FROM zones z LEFT JOIN zone_history_state s ON s.zone_id = z.id'
            . ' WHERE z.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND s.zone_id IS NULL ORDER BY z.id'
        );
        foreach ($ids as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $missing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt->closeCursor();

        $done = [];
        $reader = new ZoneSnapshotReader($pdo);
        $lazyCtx = $ctx->systemDerived(self::OP_BASELINE_LAZY);
        foreach ($missing as $zoneId) {
            $pdo->beginTransaction();
            try {
                $snapshot = $reader->lockAndRead($zoneId);
                if ($snapshot === null) {
                    $pdo->rollBack();
                    continue;
                }
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM zone_history_state WHERE zone_id = ?');
                $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
                $stmt->execute();
                $hasHead = (int)$stmt->fetchColumn() > 0;
                $stmt->closeCursor();
                if ($hasHead) {
                    $pdo->rollBack();   // zwischenzeitlich von einem anderen Vorgang angelegt
                    continue;
                }
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM zone_revisions WHERE zone_id = ?');
                $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
                $stmt->execute();
                $orphans = (int)$stmt->fetchColumn();
                $stmt->closeCursor();
                if ($orphans > 0) {
                    throw new RuntimeException("History: Zone $zoneId hat $orphans Revision(en) ohne Head");
                }
                HistoryWriter::baselineZone($pdo, $zoneId, $snapshot, $lazyCtx);
                $pdo->commit();
                $done[] = $zoneId;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return $done;
    }

    /* ======================================================================================== */
    /* Klammer der Fachtransaktion                                                              */
    /* ======================================================================================== */

    /**
     * Öffnet die Klammer in der laufenden Fachtransaktion und bindet sie an Verbindung und Transaktion (Savepoint;
     * ein Savepoint endet mit Commit oder Rollback). Eine noch registrierte frühere Klammer derselben Verbindung
     * verliert ihre Gültigkeit.
     *
     * @throws LogicException ohne offene Transaktion
     */
    public static function open(PDO $pdo, HistoryContext $ctx): self
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('ZoneHistoryRecorder::open() erfordert eine offene Transaktion');
        }
        $recorder = new self($pdo, $ctx);
        $pdo->exec('SAVEPOINT ' . $recorder->savepoint);

        $key = spl_object_id($pdo);
        $previous = self::$scopes[$key]['recorder'] ?? null;
        $previous?->get()?->invalidate();
        self::$scopes[$key] = ['pdo' => WeakReference::create($pdo), 'recorder' => WeakReference::create($recorder)];

        return $recorder;
    }

    /**
     * Sperrt Head und liest den Vorher-Snapshot je Zone (aufsteigend). Voraussetzung: Die Zonen sind in dieser
     * Transaktion bereits exklusiv gesperrt und es fand noch kein nicht sperrendes Lesen statt (E34).
     * Nicht (mehr) vorhandene Zonen werden übergangen; der Service meldet sie wie bisher.
     *
     * @param int[] $zoneIds
     * @throws RuntimeException bei fehlendem oder inkonsistentem Head (Aufrufer: Rollback)
     */
    public function beginZones(array $zoneIds): void
    {
        $this->assertUsable();
        $reader = new ZoneSnapshotReader($this->pdo);
        $lockZone = $this->pdo->prepare('SELECT id FROM zones WHERE id = ? FOR UPDATE');
        $lockHead = $this->pdo->prepare(
            'SELECT head_revision_no, head_hash, deleted_at FROM zone_history_state WHERE zone_id = ? FOR UPDATE'
        );

        foreach (self::normalizeIds($zoneIds) as $zoneId) {
            if (isset($this->begun[$zoneId]) || isset($this->created[$zoneId])) {
                continue;
            }
            $lockZone->bindValue(1, $zoneId, PDO::PARAM_INT);
            $lockZone->execute();
            $exists = $lockZone->fetchColumn() !== false;
            $lockZone->closeCursor();
            if (!$exists) {
                continue;
            }

            $lockHead->bindValue(1, $zoneId, PDO::PARAM_INT);
            $lockHead->execute();
            $head = $lockHead->fetch(PDO::FETCH_ASSOC);
            $lockHead->closeCursor();
            if ($head === false) {
                throw new RuntimeException("History: Zone $zoneId ohne History-Head (Baseline fehlt)");
            }
            if ($head['deleted_at'] !== null) {
                throw new RuntimeException("History: Head der vorhandenen Zone $zoneId ist als gelöscht markiert");
            }

            $pre = $reader->lockAndRead($zoneId);
            if ($pre === null) {
                throw new RuntimeException("History: Zone $zoneId während der Erfassung nicht lesbar");
            }
            $this->begun[$zoneId] = [
                'pre'      => $pre,
                'headNo'   => (int)$head['head_revision_no'],
                'headHash' => (string)$head['head_hash'],
                'deleting' => false,
            ];
        }
    }

    /** Neu angelegte Zone (nach INSERT INTO zones in dieser Transaktion) */
    public function zoneCreated(int $zoneId): void
    {
        $this->assertUsable();
        if ($zoneId < 1 || isset($this->begun[$zoneId])) {
            throw new LogicException("History: ungültige Anlage der Zone $zoneId");
        }
        $this->created[$zoneId] = true;
    }

    /** Zone wird gelöscht (vor DELETE FROM zones; Zone muss mit beginZones() erfasst sein) */
    public function zoneDeleting(int $zoneId): void
    {
        $this->assertUsable();
        if (!isset($this->begun[$zoneId])) {
            throw new LogicException("History: Löschung der nicht erfassten Zone $zoneId");
        }
        $this->begun[$zoneId]['deleting'] = true;
    }

    /** Ist die Zone in dieser Klammer erfasst (beginZones() bzw. zoneCreated())? */
    public function covers(int $zoneId): bool
    {
        return isset($this->begun[$zoneId]) || isset($this->created[$zoneId]);
    }

    /**
     * Abhängigkeit (R-H7): Record dieser Transaktion in $zoneId hängt von einem Record einer früheren Änderungsmenge ab.
     *
     * @param array{0: string, 1: string, 2: string} $recordKey    (name, type, content) wie gespeichert
     * @param array{0: string, 1: string, 2: string} $depRecordKey
     */
    public function addDependency(int $zoneId, array $recordKey, int $depChangeSetId, int $depZoneId, int $depRevisionNo, array $depRecordKey, string $kind = self::KIND_AUTO_PTR): void
    {
        $this->assertUsable();
        if (!$this->covers($zoneId)) {
            throw new LogicException("History: Abhängigkeit für nicht erfasste Zone $zoneId");
        }
        $this->dependencies[] = [$zoneId, array_values($recordKey), $depChangeSetId, $depZoneId, $depRevisionNo, array_values($depRecordKey), $kind];
    }

    /**
     * Schreibt die Historie der Transaktion (unmittelbar vor commit()). Danach autorisiert die Klammer keine weitere
     * Zonenprüfung mehr.
     *
     * @throws Throwable bei jedem Fehler (Aufrufer: Rollback der gesamten Fachtransaktion)
     */
    public function finish(): void
    {
        $this->assertUsable();
        $this->assertSameTransaction();
        $this->finished = true;

        $store = new ZoneSnapshotStore($this->pdo);
        $reader = new ZoneSnapshotReader($this->pdo);
        $label = $this->actorLabel();

        $zoneIds = array_keys($this->begun + $this->created);
        sort($zoneIds, SORT_NUMERIC);

        foreach ($zoneIds as $zoneId) {
            if (isset($this->created[$zoneId])) {
                $post = $reader->lockAndRead($zoneId);
                if ($post === null) {
                    throw new LogicException("History: angelegte Zone $zoneId nicht vorhanden");
                }
                $store->store($post);
                HistoryWriter::insertRevision($this->pdo, $zoneId, $post->zoneName(), 1, $this->actorChangeSet($label), 'create',
                    $post->contentHash(), ZoneSnapshotDiff::summary(ZoneSnapshotDiff::between(null, $post)));
                HistoryWriter::insertState($this->pdo, $zoneId, 1, $post->contentHash(), HistoryWriter::TRACKED);
                $this->actorRevisions[$zoneId] = 1;
                continue;
            }

            $z = $this->begun[$zoneId];
            $pre = $z['pre'];
            $headNo = $z['headNo'];
            $headHash = $z['headHash'];

            // R-H3: fremde Änderung seit dem Head zuerst als Drift (Akteur system)
            if ($pre->contentHash() !== $headHash) {
                $store->store($pre);
                $headNo++;
                HistoryWriter::insertRevision($this->pdo, $zoneId, $pre->zoneName(), $headNo, $this->driftChangeSet(), 'drift',
                    $pre->contentHash(), ZoneSnapshotDiff::summary(ZoneSnapshotDiff::between($store->load($headHash), $pre)));
                $headHash = $pre->contentHash();
            }

            if ($z['deleting']) {
                if ($this->zoneExists($zoneId)) {
                    throw new LogicException("History: Zone $zoneId als gelöscht erfasst, aber vorhanden");
                }
                $headNo++;
                HistoryWriter::insertRevision($this->pdo, $zoneId, $pre->zoneName(), $headNo, $this->actorChangeSet($label), 'delete',
                    $pre->contentHash(), null);
                HistoryWriter::updateState($this->pdo, $zoneId, $headNo, $pre->contentHash(), true);
                $this->actorRevisions[$zoneId] = $headNo;
                continue;
            }

            $post = $reader->lockAndRead($zoneId);
            if ($post === null) {
                throw new LogicException("History: Zone $zoneId ohne zoneDeleting() gelöscht");
            }
            if ($post->contentHash() !== $headHash) {
                $store->store($post);
                $headNo++;
                HistoryWriter::insertRevision($this->pdo, $zoneId, $post->zoneName(), $headNo, $this->actorChangeSet($label), 'change',
                    $post->contentHash(), ZoneSnapshotDiff::summary(ZoneSnapshotDiff::between($pre, $post)));
                $this->actorRevisions[$zoneId] = $headNo;
                $headHash = $post->contentHash();
            }
            if ($headNo !== $z['headNo']) {
                HistoryWriter::updateState($this->pdo, $zoneId, $headNo, $headHash, false);
            }
        }

        $this->writeDependencies();
    }

    /** Gibt die Klammer frei (nach Commit oder Rollback, auch im Fehlerpfad; mehrfach aufrufbar). */
    public function close(): void
    {
        $this->open = false;
        $key = spl_object_id($this->pdo);
        if ((self::$scopes[$key]['recorder'] ?? null)?->get() === $this) {
            unset(self::$scopes[$key]);
        }
    }

    /** Änderungsmenge des Akteurs (null = keine Revision des Akteurs) */
    public function changeSetId(): ?int
    {
        return $this->actorChangeSetId;
    }

    /** Revisionsnummer der Akteursrevision einer Zone (null = keine) */
    public function revisionNo(int $zoneId): ?int
    {
        return $this->actorRevisions[$zoneId] ?? null;
    }

    public function context(): HistoryContext
    {
        return $this->ctx;
    }

    /* ======================================================================================== */
    /* Wächter (R-H8, E39)                                                                      */
    /* ======================================================================================== */

    /**
     * Prüft, dass auf dieser Verbindung eine offene, noch nicht abgeschlossene Klammer der LAUFENDEN Transaktion die
     * Zone erfasst. Die Bindung an die Transaktion wird über den Savepoint der Klammer in der Datenbank nachgewiesen:
     * Nach Commit oder Rollback existiert er nicht mehr, eine frühere Klammer autorisiert also keine spätere
     * Transaktion – auch dann nicht, wenn close() fehlt.
     *
     * @throws LogicException ohne gültige Klammer
     */
    public static function assertOpenFor(PDO $pdo, int $zoneId): void
    {
        $entry = self::$scopes[spl_object_id($pdo)] ?? null;
        $recorder = ($entry !== null && $entry['pdo']->get() === $pdo) ? $entry['recorder']->get() : null;
        if (!$recorder instanceof self || !$recorder->open || $recorder->finished) {
            throw new LogicException("History: keine offene Erfassung für Zone $zoneId (R-H8)");
        }
        if (!$recorder->covers($zoneId)) {
            throw new LogicException("History: Zone $zoneId ist nicht erfasst (R-H8)");
        }
        $recorder->assertSameTransaction();
    }

    /* ======================================================================================== */
    /* Intern                                                                                   */
    /* ======================================================================================== */

    private function invalidate(): void
    {
        $this->open = false;
    }

    private function assertUsable(): void
    {
        if (!$this->open || $this->finished) {
            throw new LogicException('History: Erfassung ist geschlossen oder abgeschlossen');
        }
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('History: keine offene Transaktion');
        }
    }

    /** Savepoint der Klammer muss in der laufenden Transaktion existieren */
    private function assertSameTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('History: keine offene Transaktion');
        }
        try {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1305) {
                $this->open = false;
                throw new LogicException('History: Erfassung gehört zu einer beendeten Transaktion (R-H8)');
            }
            throw $e;
        }
        $this->pdo->exec('SAVEPOINT ' . $this->savepoint);
    }

    /** Benutzername zum Zeitpunkt aus der in der Transaktion bereits gesperrten users-Zeile */
    private function actorLabel(): ?string
    {
        if ($this->ctx->actorType !== HistoryContext::ACTOR_USER) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT username FROM users WHERE id = ? LOCK IN SHARE MODE');
        $stmt->bindValue(1, $this->ctx->userId, PDO::PARAM_INT);
        $stmt->execute();
        $name = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($name === false) {
            throw new RuntimeException('History: Akteur ' . $this->ctx->userId . ' nicht vorhanden');
        }

        return (string)$name;
    }

    private function actorChangeSet(?string $label): int
    {
        return $this->actorChangeSetId ??= HistoryWriter::insertChangeSet($this->pdo, $this->ctx, $label);
    }

    private function driftChangeSet(): int
    {
        return $this->driftChangeSetId ??= HistoryWriter::insertChangeSet($this->pdo, $this->ctx->systemDerived(self::OP_DRIFT), null);
    }

    private function zoneExists(int $zoneId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM zones WHERE id = ?');
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $n = (int)$stmt->fetchColumn();
        $stmt->closeCursor();

        return $n > 0;
    }

    private function writeDependencies(): void
    {
        if ($this->dependencies === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO history_dependencies (change_set_id, zone_id, revision_no, record_name, record_type, record_content,'
            . ' dep_change_set_id, dep_zone_id, dep_revision_no, dep_record_name, dep_record_type, dep_record_content, kind)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($this->dependencies as [$zoneId, $key, $depCs, $depZone, $depRev, $depKey, $kind]) {
            $revisionNo = $this->actorRevisions[$zoneId] ?? null;
            if ($revisionNo === null || $this->actorChangeSetId === null) {
                throw new LogicException("History: Abhängigkeit ohne Revision der Zone $zoneId");
            }
            $stmt->execute([
                $this->actorChangeSetId, $zoneId, $revisionNo, (string)$key[0], (string)$key[1], (string)$key[2],
                $depCs, $depZone, $depRev, (string)$depKey[0], (string)$depKey[1], (string)$depKey[2], $kind,
            ]);
        }
    }

    /** @return int[] eindeutig, positiv, aufsteigend */
    private static function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
