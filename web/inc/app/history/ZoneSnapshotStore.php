<?php
/**
 * Datei: inc/app/history/ZoneSnapshotStore.php
 * Zweck: Inhaltsadressierte Ablage der Zonen-Snapshots in zone_snapshots (AP1/H2b, E33), gemeinsam genutzt von
 *        ZoneHistoryRecorder und HistoryBaselineService.
 *
 * Snapshots sind unveränderlich: Eine Zeile wird nie geändert oder gelöscht (keine Retention, E8; Fremdschlüssel
 * RESTRICT). Daraus folgen die Sperrregeln:
 * - Existenzprüfung als nicht sperrendes Lesen. Ein sichtbarer Eintrag bleibt gültig; es entsteht keine Gap-Sperre
 *   im gemeinsamen Hash-Raum, die fremde Zonen koppeln würde (anders als INSERT … ON DUPLICATE KEY UPDATE oder
 *   sperrendes Lesen eines fehlenden Schlüssels unter REPEATABLE READ).
 * - Nicht sichtbar ⇒ einfaches INSERT (nur Einfügeabsicht).
 * - Duplicate-Key-Pfad: Hat eine andere Transaktion denselben Hash inzwischen gespeichert, meldet das INSERT 1062
 *   (Anweisungsfehler, die Transaktion bleibt bestehen). Dann wird der vorhandene Eintrag sperrend gelesen (jüngster
 *   bestätigter Stand) und byte-genau mit Vertrag und Payload verglichen: identisch ⇒ Erfolg, abweichend ⇒
 *   RuntimeException (fail-closed).
 * - REPEATABLE READ mit innodb_snapshot_isolation (MariaDB 11.8): Ist der vorhandene Eintrag jünger als der Read-View
 *   der Transaktion, meldet das INSERT 1020 und der Server setzt die GESAMTE Transaktion zurück (PDO::inTransaction()
 *   bleibt dennoch true). Der Store prüft deshalb nach jedem Fehler @@in_transaction und bricht ohne weitere Anweisung
 *   mit RuntimeException ab – nie Weiterarbeit im Autocommit. Fachlich ist dieser Fall nicht erreichbar: Ein gleicher
 *   Inhalt hat denselben Zonennamen, wird also nur unter derselben Zonensperre geschrieben, und der Read-View der
 *   Fachtransaktion entsteht erst nach ihren Zonensperren (E34); ein solcher Eintrag ist stets sichtbar.
 *
 * Nur in offener Transaktion; schreibt ausschließlich zone_snapshots.
 */

require_once __DIR__ . '/ZoneStateSnapshot.php';

final class ZoneSnapshotStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Speichert den Snapshot, sofern noch nicht vorhanden; ein vorhandener Eintrag muss byte-identisch sein.
     *
     * @return bool true = neu gespeichert, false = bereits vorhanden
     *
     * @throws LogicException ohne offene Transaktion
     * @throws RuntimeException bei abweichendem gespeichertem Inhalt
     * @throws PDOException bei Datenbankfehlern
     */
    public function store(ZoneStateSnapshot $snapshot): bool
    {
        $this->assertTransaction();
        $hash = $snapshot->contentHash();

        $existing = $this->read($hash, false);
        if ($existing !== null) {
            $this->assertIdentical($snapshot, $existing);
            return false;
        }

        $stmt = $this->pdo->prepare('INSERT INTO zone_snapshots (content_hash, contract, payload, record_count) VALUES (?, ?, ?, ?)');
        $stmt->bindValue(1, $hash);
        $stmt->bindValue(2, $snapshot->contract());
        $stmt->bindValue(3, $snapshot->payload());
        $stmt->bindValue(4, $snapshot->recordCount(), PDO::PARAM_INT);
        try {
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (!$this->serverInTransaction()) {
                throw new RuntimeException("History: Snapshot $hash nicht gespeichert, Transaktion vom Server zurückgesetzt (Fehler $code)", 0, $e);
            }
            if ($code !== 1062) {
                throw $e;
            }
        }

        // Konkurrierend gespeichert: vorhandenen Eintrag im jüngsten bestätigten Stand prüfen
        $existing = $this->read($hash, true);
        if ($existing === null) {
            throw new RuntimeException('Snapshot ' . $hash . ' weder einfügbar noch lesbar');
        }
        $this->assertIdentical($snapshot, $existing);

        return false;
    }

    /**
     * Lädt einen gespeicherten Snapshot (nicht sperrend; ist er im Read-View nicht sichtbar, sperrend).
     *
     * @throws RuntimeException wenn der Snapshot fehlt oder nicht dem Vertrag entspricht
     */
    public function load(string $hash): ZoneStateSnapshot
    {
        $row = $this->read($hash, false) ?? ($this->pdo->inTransaction() ? $this->read($hash, true) : null);
        if ($row === null) {
            throw new RuntimeException('Snapshot ' . $hash . ' nicht vorhanden');
        }
        $snapshot = ZoneStateSnapshot::fromPayload($row['payload']);
        if ($snapshot->contentHash() !== $hash || $snapshot->contract() !== $row['contract']) {
            throw new RuntimeException('Gespeicherter Snapshot ' . $hash . ' ist inkonsistent');
        }

        return $snapshot;
    }

    /** @return array{contract: string, payload: string}|null */
    private function read(string $hash, bool $locking): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT contract, payload FROM zone_snapshots WHERE content_hash = ?' . ($locking ? ' LOCK IN SHARE MODE' : '')
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row === false ? null : ['contract' => (string)$row['contract'], 'payload' => (string)$row['payload']];
    }

    /** @param array{contract: string, payload: string} $row */
    private function assertIdentical(ZoneStateSnapshot $snapshot, array $row): void
    {
        if ($row['contract'] !== $snapshot->contract() || !hash_equals($row['payload'], $snapshot->payload())) {
            throw new RuntimeException('Gespeicherter Snapshot weicht vom berechneten Inhalt ab: ' . $snapshot->contentHash());
        }
    }

    /** Tatsächlicher Transaktionszustand der Verbindung (nach 1020/1213 setzt der Server die Transaktion zurück) */
    private function serverInTransaction(): bool
    {
        return (int)$this->pdo->query('SELECT @@in_transaction')->fetchColumn() === 1;
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('ZoneSnapshotStore erfordert eine offene Transaktion');
        }
    }
}
