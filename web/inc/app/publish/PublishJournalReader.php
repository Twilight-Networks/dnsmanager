<?php
/**
 * Datei: inc/app/publish/PublishJournalReader.php
 * Zweck: Lese-Regeln des Publish-Journals (AP1/H2d, J5, E68, E70, E73) als Core-Helfer für Tests und die spätere
 *        Historienoberfläche (H3). Ohne Schreibzugriff; die Darstellung und L4 (Synchronisationszustand) folgen mit H3.
 *
 * J5 (E68/E73): Ein offener Vorgang (Zielkanal "sending", Cleanup "sending", Versuch ohne outcome) gilt als "unknown",
 * sobald sein Lauf nicht nachweisbar lebendig ist. Lebendig ist ein Lauf ausschließlich, wenn
 *   status = running  UND  die laufeigene Sperre von connection_id gehalten wird  UND  der Publish-Lock ebenfalls.
 * Eine Verbindungs-ID allein genügt nicht: Nach einem Neustart des Datenbankservers oder Verbindungsende sind alle
 * Advisory-Sperren frei; eine wiederverwendete Verbindungs-ID hält die laufeigene Sperre nie (sie trägt die Lauf-ID).
 * Im Zweifel gilt der Lauf als nicht lebendig (konservativ unknown); J6 markiert später "abandoned".
 *
 * Operative Ergebnisse sind keine Bestätigung (E65, Abschnitt 9): outcome = published heißt nur, dass alle Agenten
 * operativ mit HTTP 200 geantwortet haben. Vor AS sind alle Zielbestätigungen "legacy" (ohne Serial, Datei-Hash oder
 * Reload-Nachweis). Ein Ziel mit unknown, ohne Bestätigung oder mit einer veralteten Bestätigung ist nie synchron.
 *
 * Reaktivierung (E70): Ein Lauf speichert history_watermark = höchste Änderungsmengen-ID bei seiner Registrierung unter
 * dem Publish-Lock. Eine Reaktivierung (Audit-Ereignis server/update mit active false → true) hält denselben Lock;
 * sie liegt deshalb genau dann nach dem Lauf, wenn ihre Änderungsmengen-ID größer als dessen Wasserstand ist. Eine
 * Bestätigung eines Laufs mit kleinerem Wasserstand ist für diesen Server veraltet (unknown).
 */

require_once __DIR__ . '/PublishLedger.php';

final class PublishJournalReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Reine Lebendigkeitsregel (J5/E73) */
    public static function isAlive(string $status, int $connectionId, mixed $runLockHolder, mixed $mainLockHolder): bool
    {
        return $status === 'running'
            && $runLockHolder !== null && $runLockHolder !== false && (int)$runLockHolder === $connectionId
            && $mainLockHolder !== null && $mainLockHolder !== false && (int)$mainLockHolder === $connectionId;
    }

    /** Wirksamer Zustand eines gespeicherten Kanals/Cleanup-Status bzw. eines Versuchs-Ergebnisses (null = offen) */
    public static function effectiveState(?string $stored, bool $runAlive): string
    {
        if ($stored === null || $stored === 'sending') {
            return $runAlive ? 'sending' : 'unknown';
        }

        return $stored;
    }

    /** Reine Regel E70: Bestätigung eines Laufs mit Wasserstand $watermark ist durch eine dieser Reaktivierungen veraltet */
    public static function invalidatedByReactivation(int $watermark, array $reactivationChangeSetIds): bool
    {
        foreach ($reactivationChangeSetIds as $id) {
            if ((int)$id > $watermark) {
                return true;
            }
        }

        return false;
    }

    /** Lebt der Lauf nachweisbar (J5)? Unbekannter Lauf ⇒ false */
    public function runAlive(int $runId): bool
    {
        $stmt = $this->pdo->prepare('SELECT status, connection_id, DATABASE() AS db FROM publish_runs WHERE id = ?');
        $stmt->execute([$runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) AS r, IS_USED_LOCK(?) AS m');
        $stmt->execute([PublishLedger::runLockName((string)$row['db'], $runId), PublishLedger::MAIN_LOCK]);
        $locks = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return self::isAlive((string)$row['status'], (int)$row['connection_id'], $locks['r'], $locks['m']);
    }

    /**
     * Wirksame Zustände aller Ziele eines Versuchs.
     *
     * @return array<int, array{zone: string, conf: string}> server_id => Zustände
     */
    public function effectiveTargets(int $attemptId): array
    {
        $stmt = $this->pdo->prepare('SELECT a.run_id, t.server_id, t.zone_sync, t.conf_sync FROM zone_publish_targets t'
            . ' JOIN zone_publish_attempts a ON a.id = t.attempt_id WHERE t.attempt_id = ? ORDER BY t.server_id');
        $stmt->execute([$attemptId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $out = [];
        $alive = null;
        foreach ($rows as $r) {
            $alive ??= $this->runAlive((int)$r['run_id']);
            $out[(int)$r['server_id']] = [
                'zone' => self::effectiveState((string)$r['zone_sync'], $alive),
                'conf' => self::effectiveState((string)$r['conf_sync'], $alive),
            ];
        }

        return $out;
    }

    /** Wirksames Ergebnis eines Versuchs (offen ⇒ sending bzw. unknown) */
    public function effectiveOutcome(int $attemptId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT run_id, outcome FROM zone_publish_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            return null;
        }

        return self::effectiveState($row['outcome'] === null ? null : (string)$row['outcome'], $this->runAlive((int)$row['run_id']));
    }

    /**
     * Änderungsmengen-IDs der Reaktivierungen eines Servers (Audit-Ereignis server/update, active false → true).
     *
     * @return int[]
     */
    public function reactivations(int $serverId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT change_set_id, changes FROM audit_events WHERE entity_type = 'server' AND entity_id = ? AND action = 'update'"
            . " AND JSON_EXISTS(changes, '$.active') ORDER BY change_set_id"
        );
        $stmt->execute([$serverId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $active = json_decode((string)$row['changes'], true)['active'] ?? null;
            if (is_array($active) && ($active['old'] ?? null) === false && ($active['new'] ?? null) === true) {
                $ids[] = (int)$row['change_set_id'];
            }
        }
        $stmt->closeCursor();

        return $ids;
    }
}
