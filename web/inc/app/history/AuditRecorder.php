<?php
/**
 * Datei: inc/app/history/AuditRecorder.php
 * Zweck: Atomare Erfassung fachlicher Audit-Ereignisse (AP1/H2c, E47/E49/E51; R-A1–R-A8,
 *        docs/ARCHITECTURE_HISTORY.md Abschnitte 5c und 6).
 *
 * Eine Instanz je Fachtransaktion. Ablauf im Service:
 *   1. beginTransaction() → Berechtigung (users S/X) → ggf. Zonensperren und ZoneHistoryRecorder::open()/beginZones()
 *   2. $a = open($pdo, $ctx, $zones)   nach der Akteurssperre: liest den Akteursnamen sperrend (Stand zu BEGINN des
 *                                      Vorgangs – auch bei Selbstlöschung oder Selbstumbenennung). Ohne
 *                                      ZoneHistoryRecorder bindet ein eigener Savepoint die Erfassung an die
 *                                      Transaktion; bei einer Mischoperation gilt die Bindung des
 *                                      ZoneHistoryRecorder (gleicher Kontext Pflicht).
 *   3. $a->record(…)                   nur Pufferung und Prüfung (AuditPolicy), keine Datenbankanweisung
 *   4. $zones?->finish(); $a->finish() unmittelbar vor commit(): verwendet die Änderungsmenge der Revisionen
 *                                      (gleiche Operation, gleiche Korrelation) oder legt genau eine an; schreibt
 *                                      die Ereignisse mit fortlaufender seq
 *   5. commit() bzw. Rollback; in jedem Fall $a->close() (finally)
 *
 * Regeln:
 * - R-A1: Ereignisse in der Fachtransaktion; jeder Fehler ist eine Exception, der Aufrufer rollt zurück.
 * - R-A2: Ein Ereignis entsteht nur mit Commit. Ablehnungen und Fehler hinterlassen nichts (Syslog wie bisher).
 * - R-A3/R-A4: Inhalte ausschließlich über AuditPolicy (Whitelist, Typprüfung, Schwärzung, Längengrenzen).
 * - R-A6: Je Fachtransaktion genau eine Änderungsmenge des Akteurs für Revisionen und Ereignisse.
 * - R-A7: Nicht transaktionale Vorgänge (BIND-Reload) danach über recordStandalone() in eigener kurzer Transaktion.
 * - R-A8: Bis finish() keine Anweisung außer dem sperrenden Lesen des Akteursnamens (kein Read-View, E34).
 *
 * Keine Session, kein HTTP, kein Logging.
 */

require_once __DIR__ . '/AuditPolicy.php';
require_once __DIR__ . '/HistoryContext.php';
require_once __DIR__ . '/HistoryWriter.php';
require_once __DIR__ . '/ZoneHistoryRecorder.php';

final class AuditRecorder
{
    private ?string $savepoint = null;
    private bool $open = true;
    private bool $finished = false;
    private ?int $changeSetId = null;

    /** @var list<array{type: string, id: int, label: string, zoneId: ?int, action: string, changes: string}> */
    private array $events = [];

    private function __construct(
        private readonly PDO $pdo,
        private readonly HistoryContext $ctx,
        private readonly ?ZoneHistoryRecorder $zones,
        private readonly ?string $actorLabel
    ) {
    }

    /**
     * Öffnet die Erfassung in der laufenden Fachtransaktion (nach der Akteurssperre).
     *
     * @throws LogicException ohne offene Transaktion oder bei abweichendem Kontext des ZoneHistoryRecorder
     * @throws RuntimeException wenn der Akteur nicht (mehr) vorhanden ist
     */
    public static function open(PDO $pdo, HistoryContext $ctx, ?ZoneHistoryRecorder $zones = null): self
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('AuditRecorder::open() erfordert eine offene Transaktion');
        }
        if ($zones !== null && $zones->context() !== $ctx) {
            throw new LogicException('Audit: ZoneHistoryRecorder mit abweichendem Kontext (R-A6)');
        }
        $recorder = new self($pdo, $ctx, $zones, HistoryWriter::actorLabel($pdo, $ctx));
        if ($zones === null) {
            $recorder->savepoint = 'dnsmgr_audit_' . bin2hex(random_bytes(8));
            $pdo->exec('SAVEPOINT ' . $recorder->savepoint);
        }

        return $recorder;
    }

    /**
     * Puffert ein Ereignis. Keine Datenbankanweisung.
     *
     * @param array<string, mixed>  $before  Vorher-Werte aus GESPERRTEM Stand (R-A5)
     * @param array<string, mixed>  $after   Nachher-Werte (wie geschrieben)
     * @param array<string, string> $secrets nur Kennzeichen (set, changed, rotated, cleared)
     * @param array<string, mixed>  $facts   Fakten (Zähler, Ergebnis)
     * @return bool true = Ereignis vorgemerkt, false = keine Änderung (kein Ereignis)
     *
     * @throws LogicException bei unzulässigen Feldern oder Werten (AuditPolicy) bzw. geschlossener Erfassung
     */
    public function record(
        string $entityType,
        int $entityId,
        string $label,
        ?int $zoneId,
        string $action,
        array $before,
        array $after,
        array $secrets = [],
        array $facts = []
    ): bool {
        $this->assertUsable();
        $event = self::event($entityType, $entityId, $label, $zoneId, $action, $before, $after, $secrets, $facts);
        if ($event === null) {
            return false;
        }
        if (count($this->events) >= 1000) {
            throw new LogicException('Audit: zu viele Ereignisse in einer Änderungsmenge');
        }
        $this->events[] = $event;

        return true;
    }

    /**
     * Schreibt die Ereignisse unmittelbar vor commit(). Bei einer Mischoperation erst nach
     * ZoneHistoryRecorder::finish(); dessen Änderungsmenge wird weiterverwendet.
     *
     * @throws LogicException bei falscher Reihenfolge oder beendeter Transaktion
     */
    public function finish(): void
    {
        $this->assertUsable();
        $this->assertSameTransaction();
        if ($this->zones !== null && !$this->zones->isFinished()) {
            throw new LogicException('Audit: finish() vor ZoneHistoryRecorder::finish() (R-A6)');
        }
        $this->finished = true;
        if ($this->events === []) {
            return;
        }

        $changeSetId = $this->zones?->changeSetId()
            ?? HistoryWriter::insertChangeSet($this->pdo, $this->ctx, $this->actorLabel);
        foreach ($this->events as $i => $e) {
            HistoryWriter::insertAuditEvent($this->pdo, $changeSetId, $i + 1, $e['type'], $e['id'], $e['label'], $e['zoneId'], $e['action'], $e['changes']);
        }
        $this->changeSetId = $changeSetId;
    }

    /** Gibt die Erfassung frei (nach Commit oder Rollback, auch im Fehlerpfad; mehrfach aufrufbar). */
    public function close(): void
    {
        $this->open = false;
    }

    /** Änderungsmenge der geschriebenen Ereignisse (null = keine Ereignisse oder noch nicht abgeschlossen) */
    public function changeSetId(): ?int
    {
        return $this->changeSetId;
    }

    /** Anzahl der vorgemerkten Ereignisse */
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Nachtrag eines nicht transaktionalen Vorgangs (BIND-Reload, E51) in eigener, kurzer Transaktion: users S
     * (Name zum Zeitpunkt; fehlt der Akteur inzwischen, bleibt er leer) → Änderungsmenge → Ereignis → Commit.
     *
     * @param array<string, mixed> $facts
     * @return int ID der Änderungsmenge
     *
     * @throws LogicException in offener Transaktion oder bei unzulässigem Inhalt
     * @throws Throwable bei Datenbankfehlern (zurückgerollt)
     */
    public static function recordStandalone(
        PDO $pdo,
        HistoryContext $ctx,
        string $entityType,
        int $entityId,
        string $label,
        ?int $zoneId,
        string $action,
        array $facts
    ): int {
        if ($pdo->inTransaction()) {
            throw new LogicException('AuditRecorder::recordStandalone() ist außerhalb einer Transaktion aufzurufen');
        }
        $event = self::event($entityType, $entityId, $label, $zoneId, $action, [], [], [], $facts);
        if ($event === null) {
            throw new LogicException('Audit: Nachtrag ohne Inhalt');
        }
        $pdo->beginTransaction();
        try {
            $label = HistoryWriter::actorLabel($pdo, $ctx, true);
            $changeSetId = HistoryWriter::insertChangeSet($pdo, $ctx, $label);
            HistoryWriter::insertAuditEvent($pdo, $changeSetId, 1, $event['type'], $event['id'], $event['label'], $event['zoneId'], $event['action'], $event['changes']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $changeSetId;
    }

    /* ======================================================================================== */
    /* Intern                                                                                   */
    /* ======================================================================================== */

    /**
     * @return array{type: string, id: int, label: string, zoneId: ?int, action: string, changes: string}|null
     */
    private static function event(
        string $entityType,
        int $entityId,
        string $label,
        ?int $zoneId,
        string $action,
        array $before,
        array $after,
        array $secrets,
        array $facts
    ): ?array {
        if ($entityId < 1) {
            throw new LogicException('Audit: ungültige Entitäts-ID');
        }
        $zoneValid = match ($entityType) {
            'zone'           => $zoneId === $entityId,
            'dyndns_account' => $zoneId !== null && $zoneId >= 1,
            'server', 'user' => $zoneId === null,
            default          => false,
        };
        if (!$zoneValid) {
            throw new LogicException("Audit: ungültiger Zonenbezug für '$entityType'");
        }
        $changes = AuditPolicy::changes($entityType, $action, $before, $after, $secrets, $facts);
        if ($changes === null) {
            return null;
        }

        return [
            'type'    => $entityType,
            'id'      => $entityId,
            'label'   => AuditPolicy::label($label),
            'zoneId'  => $zoneId,
            'action'  => $action,
            'changes' => $changes,
        ];
    }

    private function assertUsable(): void
    {
        if (!$this->open || $this->finished) {
            throw new LogicException('Audit: Erfassung ist geschlossen oder abgeschlossen');
        }
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Audit: keine offene Transaktion');
        }
    }

    /** Bindung an die laufende Transaktion: eigener Savepoint bzw. der des ZoneHistoryRecorder (E39) */
    private function assertSameTransaction(): void
    {
        if ($this->zones !== null) {
            $this->zones->assertCurrentTransaction();
            return;
        }
        try {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1305) {
                $this->open = false;
                throw new LogicException('Audit: Erfassung gehört zu einer beendeten Transaktion');
            }
            throw $e;
        }
        $this->pdo->exec('SAVEPOINT ' . $this->savepoint);
    }
}
