<?php
/**
 * Datei: inc/app/publish/ServerVerificationStore.php
 * Zweck: Einziger Schreiber von zone_server_verification (AS4b; Datenvertrag AS3 Abschnitt 5h, Ordnungs- und
 *        Schreibvertrag Abschnitt 5i/5j, K3). Statisch geprüft: keine andere Stelle schreibt die Tabelle.
 *
 * Beobachtung (je Zone × Server):
 * - "letzte Beobachtung" (last_*) wird bei jeder angenommenen Beobachtung vollständig ersetzt;
 * - "letzter positiver Nachweis" (verified_*) wird NUR bei last_state = verified geschrieben – in jedem anderen Fall steht
 *   er nicht in der Änderung und bleibt technisch unveränderbar erhalten (keine Löschung, kein Überschreiben).
 *
 * Ordnungsvertrag (J7, K3) – Schlüssel (last_run_id, Quelle), last_run_id NULL ≙ 0, nie die SOA-Serial:
 * - Publish (conf_sync, cleanup): last_run_id = erzeugender Lauf R; geschrieben nur, solange R lebt (assertRunAlive in
 *   derselben Transaktion).
 * - Monitoring: last_run_id = Wasserstand W (zuletzt abgeschlossener Lauf vor Beginn der Beobachtung); der Aufrufer hält
 *   bei der Speicherung den Publish-Lock und hat W unter diesem Lock erneut bestätigt (MonitoringService).
 * - größerer Schlüssel ⇒ annehmen, kleinerer ⇒ ablehnen (stale); gleicher Schlüssel ⇒ ausdrückliche Rangfolge
 *   (ACCEPT_EQUAL): conf_sync → cleanup, Publish → Monitoring, Monitoring → Monitoring; alles andere ablehnen.
 *
 * Schreibtransaktion: erste Anweisung ist die sperrende Lesung der Zeile (kein vorheriger konsistenter Read; vermeidet
 * 1020 unter REPEATABLE READ mit Snapshot-Isolation); last_observed_at = GREATEST(NOW(6), bisheriger Wert) (monoton,
 * chk_zsv_verified_order). Die CHECKs aus AS3 bleiben unverändert wirksam. Fehler sind nicht sicherheitsrelevant (wie
 * J4): Rollback, Logzeile "[publish] Verifikation: …", Bericht; 1020/1213 einmal erneut, sonst keine Wiederholung. Das
 * operative Ergebnis bleibt unverändert.
 */

require_once __DIR__ . '/PublishLedger.php';
require_once __DIR__ . '/PublishRun.php';
require_once __DIR__ . '/RunLockLost.php';

final class ServerVerificationStore
{
    public const STATES = ['verified', 'serial_differs', 'still_served', 'absent', 'unknown'];

    /** Rangfolge bei gleichem Ordnungsschlüssel: [bestehende Quelle, neue Quelle] ⇒ annehmen (K3) */
    private const ACCEPT_EQUAL = [
        'conf_sync>cleanup' => true,
        'conf_sync>monitoring' => true,
        'cleanup>monitoring' => true,
        'monitoring>monitoring' => true,
    ];

    private PDO $pdo;
    private Closure $log;
    /** @var array<string, int> */
    private array $counts = ['stored' => 0, 'stale' => 0, 'failed' => 0];
    /** @var string[] */
    private array $errors = [];

    public function __construct(PDO $pdo, ?callable $log = null)
    {
        $this->pdo = $pdo;
        $this->log = $log !== null ? Closure::fromCallable($log) : static function (string $l, string $m): void {
        };
    }

    /** Entscheidung nach dem Ordnungsvertrag (rein) */
    public static function accepts(?array $row, string $newMethod, int $newKey): bool
    {
        if ($row === null) {
            return true;
        }
        $rowKey = (int)($row['last_run_id'] ?? 0);
        if ($newKey !== $rowKey) {
            return $newKey > $rowKey;
        }

        return isset(self::ACCEPT_EQUAL[(string)$row['last_method'] . '>' . $newMethod]);
    }

    /**
     * Publish-Beobachtung (conf_sync, cleanup) im laufenden Lauf.
     *
     * @param array{state: string, reason?: ?string, served?: ?int, fileSerial?: ?int, fileHash?: ?string} $obs
     * @return string stored | stale | failed
     */
    public function recordPublish(PublishLedger $ledger, PublishRun $run, string $method, int $zoneId, string $zoneName,
        int $serverId, string $serverName, array $obs, ?int $attemptId): string
    {
        if (!in_array($method, ['conf_sync', 'cleanup'], true)) {
            throw new InvalidArgumentException("Ungültige Quelle '$method'");
        }

        return $this->write($method, $run->id, $run->id, $attemptId, $zoneId, $zoneName, $serverId, $serverName, $obs,
            static fn () => $ledger->assertRunAlive($run));
    }

    /**
     * Monitoring-Beobachtungen; der Aufrufer hält den Publish-Lock und hat den Wasserstand W bestätigt.
     *
     * @param array<int, array{zoneId: int, zoneName: string, serverId: int, serverName: string, obs: array}> $items
     * @return array<string, int>
     */
    public function recordMonitoring(int $watermark, array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [$a['zoneId'], $a['serverId']] <=> [$b['zoneId'], $b['serverId']]);
        $n = ['stored' => 0, 'stale' => 0, 'failed' => 0];
        foreach ($items as $it) {
            $r = $this->write('monitoring', $watermark, null, null, (int)$it['zoneId'], (string)$it['zoneName'],
                (int)$it['serverId'], (string)$it['serverName'], $it['obs'], null);
            $n[$r]++;
        }

        return $n;
    }

    /**
     * Eindeutige Zuordnung eines Cleanup-Ergebnisses (O5): genau eine bestehende Zeile mit diesem Server und Zonennamen,
     * deren Zone dem Server nicht (mehr) zugewiesen ist. Keine Ableitung aus dem Namen allein, keine Neuanlage.
     *
     * Ein Lesefehler gilt wie eine nicht eindeutige Zuordnung (keine Beobachtung, Zähler failed, Logzeile); das operative
     * Ergebnis des Cleanups bleibt unberührt.
     *
     * @return array{zoneId: int, zoneName: string}|null
     */
    public function resolveCleanupPair(int $serverId, string $zoneName): ?array
    {
        try {
            return $this->resolvePair($serverId, $zoneName);
        } catch (Throwable $e) {
            $this->counts['failed']++;
            $this->errors[] = "Cleanup-Zuordnung $zoneName Server $serverId: " . $e->getMessage();
            ($this->log)('warning', "[publish] Verifikation: Cleanup-Zuordnung $zoneName Server $serverId – " . $e->getMessage());
            return null;
        }
    }

    /** @return array{zoneId: int, zoneName: string}|null */
    private function resolvePair(int $serverId, string $zoneName): ?array
    {
        $stmt = $this->pdo->prepare('SELECT zone_id FROM zone_server_verification WHERE server_id = ? AND zone_name = ?');
        $stmt->execute([$serverId, $zoneName]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt->closeCursor();
        if (count($ids) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM zone_servers WHERE zone_id = ? AND server_id = ?');
        $stmt->execute([$ids[0], $serverId]);
        $assigned = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        return $assigned ? null : ['zoneId' => $ids[0], 'zoneName' => $zoneName];
    }

    /** @return array{stored: int, stale: int, failed: int, errors: string[]} */
    public function report(): array
    {
        return $this->counts + ['errors' => $this->errors];
    }

    /**
     * Felder der letzten Beobachtung nach Zustand (Datenvertrag 5h; inkonsistente Angaben sind ein Programmfehler).
     *
     * @return array{0: ?int, 1: ?int, 2: ?string} [served, fileSerial, fileHash]
     */
    private static function lastFields(array $obs): array
    {
        $state = $obs['state'] ?? null;
        $served = $obs['served'] ?? null;
        $fs = $obs['fileSerial'] ?? null;
        $fh = $obs['fileHash'] ?? null;
        $ok = match ($state) {
            'verified' => $served !== null && $fs !== null && $served === $fs && $fh !== null,
            'serial_differs' => $served !== null && $fs !== null && $served !== $fs,
            'still_served' => $served !== null,
            'absent' => $served === null,
            'unknown' => true,
            default => false,
        };
        if (!$ok) {
            throw new LogicException("Beobachtung $state mit unvollständigen oder widersprüchlichen Werten");
        }
        if ($state === 'still_served' || $state === 'absent') {
            return [$served, null, null];
        }

        return [$served, $fs, $fh];
    }

    private function write(string $method, int $orderKey, ?int $proofRunId, ?int $attemptId, int $zoneId, string $zoneName,
        int $serverId, string $serverName, array $obs, ?callable $inTrx): string
    {
        $what = "Zone $zoneName Server $serverName ($method)";
        for ($try = 1; $try <= 2; $try++) {
            try {
                [$served, $fs, $fh] = self::lastFields($obs);
                $reason = $obs['reason'] ?? null;
                if ($reason !== null && preg_match('/^[a-z0-9_:]{1,64}$/D', $reason) !== 1) {
                    $reason = null;
                }
                $this->pdo->beginTransaction();
                $stmt = $this->pdo->prepare('SELECT last_run_id, last_method, last_observed_at FROM zone_server_verification'
                    . ' WHERE zone_id = ? AND server_id = ? FOR UPDATE');
                $stmt->execute([$zoneId, $serverId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $stmt->closeCursor();
                if ($inTrx !== null) {
                    $inTrx();
                }
                if (!self::accepts($row, $method, $orderKey)) {
                    $this->pdo->rollBack();
                    $this->counts['stale']++;
                    ($this->log)('info', "[publish] Verifikation: $what veraltet (Schlüssel $orderKey/$method, vorhanden "
                        . ($row['last_run_id'] ?? 0) . '/' . $row['last_method'] . ') – nicht gespeichert');
                    return 'stale';
                }
                $stmt = $this->pdo->prepare('SELECT GREATEST(NOW(6), COALESCE(?, NOW(6)))');
                $stmt->execute([$row['last_observed_at'] ?? null]);
                $at = (string)$stmt->fetchColumn();
                $stmt->closeCursor();
                $runId = $orderKey > 0 ? $orderKey : null;
                $last = ['zone_name' => $zoneName, 'server_name' => $serverName, 'last_state' => $obs['state'], 'last_reason' => $reason,
                    'last_method' => $method, 'last_served_serial' => $served, 'last_file_serial' => $fs, 'last_file_hash' => $fh,
                    'last_run_id' => $runId, 'last_attempt_id' => $attemptId, 'last_observed_at' => $at];
                if ($obs['state'] === 'verified') {
                    // positiver Nachweis = genau diese Beobachtung (chk_zsv_verified_current)
                    $last += ['verified_serial' => $served, 'verified_file_hash' => $fh, 'verified_run_id' => $proofRunId, 'verified_at' => $at];
                }
                if ($row === null) {
                    $cols = array_merge(['zone_id', 'server_id'], array_keys($last));
                    $this->pdo->prepare('INSERT INTO zone_server_verification (' . implode(', ', $cols) . ') VALUES ('
                        . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_merge([$zoneId, $serverId], array_values($last)));
                } else {
                    $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($last)));
                    $this->pdo->prepare("UPDATE zone_server_verification SET $set WHERE zone_id = ? AND server_id = ?")
                        ->execute(array_merge(array_values($last), [$zoneId, $serverId]));
                }
                $this->pdo->commit();
                $this->counts['stored']++;
                return 'stored';
            } catch (RunLockLost $e) {
                $this->rollbackQuietly();
                $this->counts['stale']++;
                ($this->log)('warning', "[publish] Verifikation: $what ohne Laufberechtigung – nicht gespeichert");
                return 'stale';
            } catch (Throwable $e) {
                $this->rollbackQuietly();
                $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
                if ($try === 1 && in_array($code, [1020, 1213], true)) {
                    continue;
                }
                $this->counts['failed']++;
                $this->errors[] = "$what: " . $e->getMessage();
                ($this->log)('warning', "[publish] Verifikation: $what – " . $e->getMessage());
                return 'failed';
            }
        }

        return 'failed';
    }

    private function rollbackQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable $e) {
            // Transaktion bereits beendet (z. B. 1020/1213)
        }
    }
}
