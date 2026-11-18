<?php
/**
 * Datei: inc/app/history/HistoryBaselineService.php
 * Zweck: Konservative Baseline-Initialisierung der Änderungshistorie für bestehende Zonen
 *        (AP1/H2a, E24; docs/ARCHITECTURE_HISTORY.md Abschnitt 15).
 *
 * Je Zone ohne History-Head entsteht genau eine Revision "baseline" (Revision 1) mit dem aktuellen DNS-Inhalt,
 * Head-Status "baseline_unverified". Es wird
 * - keine frühere Historie konstruiert,
 * - kein Veröffentlichungsstand angenommen (ein ausstehender Stand mit zones.changed ≠ 0 bleibt ausstehend; die
 *   Baseline sagt nichts darüber aus, was auf den Servern liegt),
 * - nichts an Fachtabellen, Serials, Publish-Flags (zones.changed, system_status.bind_dirty) oder Agenten geändert.
 *
 * Ablauf:
 * 1. Schema fail-closed prüfen (SchemaCheck), keine offene Transaktion des Aufrufers.
 * 2. Advisory-Try-Lock 'dnsmanager_publish_all' (TopologyLock): serialisiert gegen Publish, Zonenanlage/-löschung,
 *    Serverzuweisung und parallele Baseline-Läufe. Belegt ⇒ Abbruch ohne Schreibvorgang.
 * 3. Je Zone (aufsteigende ID) eine eigene, kurze Transaktion:
 *    zones X → Serverzuweisung und Records sperrend lesen (ZoneSnapshotReader) → History-Zustand der Zone lesen
 *    (nicht sperrend; zone_history_state wird nur unter der Zonensperre geändert, eine Gap-Sperre auf eine fehlende
 *    Zeile wird so vermieden) → vorhandener Head: überspringen (wiederholbar) → Snapshot (inhaltsadressiert,
 *    vorhandener Payload muss byte-identisch sein) → Änderungsmenge (migration.baseline, Akteur system, Kanal cli,
 *    Lauf-UUID als correlation_id) → Revision 1 "baseline" → Head "baseline_unverified" → Commit.
 *    Im Prüflauf (dry run) wird nach dem Lesen zurückgerollt.
 * 4. Der erste Fehler bricht den Lauf ab (Rollback der laufenden Zone). Bereits abgeschlossene Zonen bleiben
 *    bestehen; ein erneuter Lauf setzt deterministisch fort.
 *
 * Keine Session, kein HTTP, kein Logging (Codes und Rohdaten im ServiceResult).
 *
 * Codes:
 *   history.baseline.schema_incompatible (problems)        Schema fehlt oder weicht ab
 *   history.baseline.in_transaction                        Aufruf in offener Transaktion
 *   history.baseline.locked                                Advisory-Lock belegt
 *   history.baseline.lock_failed (detail)                  Lock-Bezug technisch fehlgeschlagen
 *   history.baseline.zone_planned (zoneId, zoneName, contentHash, recordCount)                  Prüflauf
 *   history.baseline.zone_recorded (zoneId, zoneName, revisionNo, contentHash, recordCount, changeSetId)
 *   history.baseline.zone_skipped (zoneId, zoneName, reason: has_head | deleted)
 *   history.baseline.failed (zoneId, errorClass, errorCode, detail)                              Abbruch
 *   history.baseline.success (mode: apply | dry_run, total, recorded, planned, skipped, correlationId)
 *   history.baseline.status (total, withHead, missing: list<array{id: int, name: string}>)     status()
 *   history.baseline.status_failed (errorClass, errorCode)
 */

require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../SchemaCheck.php';
require_once __DIR__ . '/../TopologyLock.php';
require_once __DIR__ . '/ZoneStateSnapshot.php';
require_once __DIR__ . '/ZoneSnapshotReader.php';

final class HistoryBaselineService
{
    public const OPERATION = 'migration.baseline';
    public const CHANNEL = 'cli';
    public const KIND = 'baseline';
    public const TRACKING = 'baseline_unverified';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Zustand: Anzahl der Zonen mit und ohne History-Head (nur lesend, ohne Sperren).
     */
    public function status(): ServiceResult
    {
        $schema = $this->schemaProblems();
        if ($schema !== []) {
            return new ServiceResult(false, [new ServiceMessage('error', 'history.baseline.schema_incompatible', ['problems' => $schema])]);
        }
        try {
            $rows = $this->pdo->query(
                'SELECT z.id, z.name, (s.zone_id IS NOT NULL) AS has_head'
                . ' FROM zones z LEFT JOIN zone_history_state s ON s.zone_id = z.id ORDER BY z.id'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return new ServiceResult(false, [new ServiceMessage('error', 'history.baseline.status_failed', self::errorContext($e))]);
        }

        $missing = [];
        foreach ($rows as $row) {
            if ((int)$row['has_head'] !== 1) {
                $missing[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
            }
        }

        return new ServiceResult(true, [new ServiceMessage('success', 'history.baseline.status', [
            'total'    => count($rows),
            'withHead' => count($rows) - count($missing),
            'missing'  => $missing,
        ])]);
    }

    /**
     * Führt die Baseline-Initialisierung aus (apply = true) oder prüft sie ohne Schreibvorgang (apply = false).
     */
    public function run(bool $apply): ServiceResult
    {
        $schema = $this->schemaProblems();
        if ($schema !== []) {
            return new ServiceResult(false, [new ServiceMessage('error', 'history.baseline.schema_incompatible', ['problems' => $schema])]);
        }
        if ($this->pdo->inTransaction()) {
            return new ServiceResult(false, [new ServiceMessage('error', 'history.baseline.in_transaction')]);
        }

        $lock = new TopologyLock($this->pdo);
        $acquired = $lock->acquire();
        if ($acquired['error'] !== null) {
            return new ServiceResult(false, [new ServiceMessage('error', 'history.baseline.lock_failed', ['detail' => $acquired['error']])]);
        }
        if (!$acquired['acquired']) {
            return new ServiceResult(false, [new ServiceMessage('error', 'history.baseline.locked')]);
        }

        try {
            return $this->runLocked($apply);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(bool $apply): ServiceResult
    {
        $correlationId = self::uuid4();
        $messages = [];
        $recorded = $planned = $skipped = 0;

        try {
            $zoneIds = array_map('intval', $this->pdo->query('SELECT id FROM zones ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return new ServiceResult(false, [new ServiceMessage('error', 'history.baseline.failed', ['zoneId' => null] + self::errorContext($e))]);
        }

        $reader = new ZoneSnapshotReader($this->pdo);
        foreach ($zoneIds as $zoneId) {
            try {
                $this->pdo->beginTransaction();

                $snapshot = $reader->lockAndRead($zoneId);
                if ($snapshot === null) {
                    $this->rollbackIfActive();
                    $skipped++;
                    $messages[] = new ServiceMessage('warning', 'history.baseline.zone_skipped', ['zoneId' => $zoneId, 'zoneName' => null, 'reason' => 'deleted']);
                    continue;
                }

                // Zustand erst nach der Zonensperre lesen (unter ihr wird er ausschließlich geändert)
                $stmt = $this->pdo->prepare('SELECT head_revision_no FROM zone_history_state WHERE zone_id = ?');
                $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
                $stmt->execute();
                $head = $stmt->fetchColumn();
                $stmt->closeCursor();
                if ($head !== false) {
                    $this->rollbackIfActive();
                    $skipped++;
                    $messages[] = new ServiceMessage('success', 'history.baseline.zone_skipped', ['zoneId' => $zoneId, 'zoneName' => $snapshot->zoneName(), 'reason' => 'has_head']);
                    continue;
                }

                // Revisionen ohne Head sind ein inkonsistenter Zustand: nie überschreiben oder ergänzen
                $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM zone_revisions WHERE zone_id = ?');
                $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
                $stmt->execute();
                $orphans = (int)$stmt->fetchColumn();
                $stmt->closeCursor();
                if ($orphans > 0) {
                    throw new RuntimeException("Zone $zoneId hat $orphans Revision(en) ohne History-Head");
                }

                if (!$apply) {
                    $this->rollbackIfActive();
                    $planned++;
                    $messages[] = new ServiceMessage('success', 'history.baseline.zone_planned', [
                        'zoneId' => $zoneId, 'zoneName' => $snapshot->zoneName(),
                        'contentHash' => $snapshot->contentHash(), 'recordCount' => $snapshot->recordCount(),
                    ]);
                    continue;
                }

                $this->storeSnapshot($snapshot);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO history_change_sets (operation, actor_type, actor_user_id, actor_label,'
                    . ' actor_dyndns_account_id, channel, correlation_id) VALUES (?, \'system\', NULL, NULL, NULL, ?, ?)'
                );
                $stmt->execute([self::OPERATION, self::CHANNEL, $correlationId]);
                $changeSetId = (int)$this->pdo->lastInsertId();
                if ($changeSetId < 1) {
                    throw new RuntimeException('Keine ID für die Änderungsmenge erhalten');
                }

                $stmt = $this->pdo->prepare(
                    'INSERT INTO zone_revisions (zone_id, zone_name, revision_no, change_set_id, kind, content_hash,'
                    . ' source_revision_no, diff_summary) VALUES (?, ?, 1, ?, ?, ?, NULL, NULL)'
                );
                $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
                $stmt->bindValue(2, $snapshot->zoneName());
                $stmt->bindValue(3, $changeSetId, PDO::PARAM_INT);
                $stmt->bindValue(4, self::KIND);
                $stmt->bindValue(5, $snapshot->contentHash());
                $stmt->execute();

                $stmt = $this->pdo->prepare(
                    'INSERT INTO zone_history_state (zone_id, head_revision_no, head_hash, tracking, deleted_at)'
                    . ' VALUES (?, 1, ?, ?, NULL)'
                );
                $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
                $stmt->bindValue(2, $snapshot->contentHash());
                $stmt->bindValue(3, self::TRACKING);
                $stmt->execute();

                $this->pdo->commit();
                $recorded++;
                $messages[] = new ServiceMessage('success', 'history.baseline.zone_recorded', [
                    'zoneId' => $zoneId, 'zoneName' => $snapshot->zoneName(), 'revisionNo' => 1,
                    'contentHash' => $snapshot->contentHash(), 'recordCount' => $snapshot->recordCount(),
                    'changeSetId' => $changeSetId,
                ]);
            } catch (Throwable $e) {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('error', 'history.baseline.failed', ['zoneId' => $zoneId] + self::errorContext($e));

                return new ServiceResult(false, $messages);
            }
        }

        $messages[] = new ServiceMessage('success', 'history.baseline.success', [
            'mode' => $apply ? 'apply' : 'dry_run', 'total' => count($zoneIds), 'recorded' => $recorded,
            'planned' => $planned, 'skipped' => $skipped, 'correlationId' => $correlationId,
        ]);

        return new ServiceResult(true, $messages);
    }

    /**
     * Speichert den Snapshot inhaltsadressiert; ein vorhandener Eintrag muss byte-identisch sein.
     */
    private function storeSnapshot(ZoneStateSnapshot $snapshot): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO zone_snapshots (content_hash, contract, payload, record_count) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE content_hash = content_hash'
        );
        $stmt->bindValue(1, $snapshot->contentHash());
        $stmt->bindValue(2, $snapshot->contract());
        $stmt->bindValue(3, $snapshot->payload());
        $stmt->bindValue(4, $snapshot->recordCount(), PDO::PARAM_INT);
        $stmt->execute();

        $stmt = $this->pdo->prepare('SELECT contract, payload FROM zone_snapshots WHERE content_hash = ? LOCK IN SHARE MODE');
        $stmt->execute([$snapshot->contentHash()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false || $row['contract'] !== $snapshot->contract() || !hash_equals((string)$row['payload'], $snapshot->payload())) {
            throw new RuntimeException('Gespeicherter Snapshot weicht vom berechneten Inhalt ab: ' . $snapshot->contentHash());
        }
    }

    /** @return string[] */
    private function schemaProblems(): array
    {
        try {
            return SchemaCheck::problems($this->pdo);
        } catch (Throwable $e) {
            return ['Prüfung fehlgeschlagen (' . get_class($e) . ')'];
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (Throwable) {
                // Verbindung ggf. verloren: die Transaktion endet mit ihr
            }
        }
    }

    /** @return array{errorClass: string, errorCode: string, detail: string} */
    private static function errorContext(Throwable $e): array
    {
        $code = $e instanceof PDOException ? (string)$e->getCode() : '-';

        return [
            'errorClass' => get_class($e),
            'errorCode'  => (string)preg_replace('/[^A-Za-z0-9]/', '', $code),
            'detail'     => substr($e->getMessage(), 0, 300),
        ];
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
