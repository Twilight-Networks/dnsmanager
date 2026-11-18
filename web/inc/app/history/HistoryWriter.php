<?php
/**
 * Datei: inc/app/history/HistoryWriter.php
 * Zweck: Gemeinsame Schreibbausteine der Historie (AP1/H2b): Änderungsmenge, Revision, Head und die Baseline einer
 *        einzelnen Zone. Genutzt von HistoryBaselineService (CLI), der Lazy-Baseline und ZoneHistoryRecorder.
 *
 * Nur in offener Transaktion des Aufrufers; kein Commit, kein Rollback, keine Sperren außer den durch die
 * Anweisungen selbst entstehenden (Einfügeabsicht, Fremdschlüsselprüfung auf die eigene bzw. unveränderliche
 * Elternzeile). Alle Werte über Platzhalter; keine Geheimnisse (nur Whitelist-Felder).
 */

require_once __DIR__ . '/HistoryContext.php';
require_once __DIR__ . '/ZoneStateSnapshot.php';
require_once __DIR__ . '/ZoneSnapshotStore.php';

final class HistoryWriter
{
    public const TRACKED = 'tracked';
    public const BASELINE_UNVERIFIED = 'baseline_unverified';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Legt eine Änderungsmenge an. $actorLabel nur für Benutzerakteure (Benutzername zum Zeitpunkt).
     *
     * @return int ID der Änderungsmenge
     */
    public static function insertChangeSet(PDO $pdo, HistoryContext $ctx, ?string $actorLabel): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO history_change_sets (operation, actor_type, actor_user_id, actor_label, actor_dyndns_account_id,'
            . ' channel, correlation_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $ctx->operation);
        $stmt->bindValue(2, $ctx->actorType);
        $stmt->bindValue(3, $ctx->userId, $ctx->userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $label = $ctx->actorType === HistoryContext::ACTOR_USER ? $actorLabel : null;
        $stmt->bindValue(4, $label, $label === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(5, $ctx->dyndnsAccountId, $ctx->dyndnsAccountId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(6, $ctx->channel);
        $stmt->bindValue(7, $ctx->correlationId);
        $stmt->execute();
        $id = (int)$pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Keine ID für die Änderungsmenge erhalten');
        }

        return $id;
    }

    /**
     * @param array<string, mixed>|null $summary Diff-Zusammenfassung (nur Feldnamen und Zähler) oder null
     */
    public static function insertRevision(
        PDO $pdo,
        int $zoneId,
        string $zoneName,
        int $revisionNo,
        int $changeSetId,
        string $kind,
        string $contentHash,
        ?array $summary
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO zone_revisions (zone_id, zone_name, revision_no, change_set_id, kind, content_hash,'
            . ' source_revision_no, diff_summary) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(2, $zoneName);
        $stmt->bindValue(3, $revisionNo, PDO::PARAM_INT);
        $stmt->bindValue(4, $changeSetId, PDO::PARAM_INT);
        $stmt->bindValue(5, $kind);
        $stmt->bindValue(6, $contentHash);
        $stmt->bindValue(7, $summary === null ? null : json_encode($summary, self::JSON_FLAGS), $summary === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }

    public static function insertState(PDO $pdo, int $zoneId, int $revisionNo, string $hash, string $tracking): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO zone_history_state (zone_id, head_revision_no, head_hash, tracking, deleted_at) VALUES (?, ?, ?, ?, NULL)'
        );
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(2, $revisionNo, PDO::PARAM_INT);
        $stmt->bindValue(3, $hash);
        $stmt->bindValue(4, $tracking);
        $stmt->execute();
    }

    /** Head fortschreiben (Zeile ist in der Transaktion bereits exklusiv gesperrt); $deleted setzt deleted_at */
    public static function updateState(PDO $pdo, int $zoneId, int $revisionNo, string $hash, bool $deleted): void
    {
        $stmt = $pdo->prepare(
            'UPDATE zone_history_state SET head_revision_no = ?, head_hash = ?'
            . ($deleted ? ', deleted_at = NOW(6)' : '') . ' WHERE zone_id = ?'
        );
        $stmt->bindValue(1, $revisionNo, PDO::PARAM_INT);
        $stmt->bindValue(2, $hash);
        $stmt->bindValue(3, $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("History-Head der Zone $zoneId nicht fortgeschrieben");
        }
    }

    /**
     * Baseline einer Zone ohne Head (Revision 1 "baseline", Head "baseline_unverified") mit eigener Änderungsmenge.
     * Voraussetzung (Aufrufer): offene Transaktion, Zone gesperrt, kein Head und keine Revision der Zone vorhanden.
     *
     * @return int ID der Änderungsmenge
     */
    public static function baselineZone(PDO $pdo, int $zoneId, ZoneStateSnapshot $snapshot, HistoryContext $ctx): int
    {
        (new ZoneSnapshotStore($pdo))->store($snapshot);
        $changeSetId = self::insertChangeSet($pdo, $ctx, null);
        self::insertRevision($pdo, $zoneId, $snapshot->zoneName(), 1, $changeSetId, 'baseline', $snapshot->contentHash(), null);
        self::insertState($pdo, $zoneId, 1, $snapshot->contentHash(), self::BASELINE_UNVERIFIED);

        return $changeSetId;
    }
}
