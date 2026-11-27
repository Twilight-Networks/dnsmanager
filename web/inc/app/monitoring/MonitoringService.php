<?php
/**
 * Datei: inc/app/monitoring/MonitoringService.php
 * Zweck: Monitoring-Lauf als webunabhängiger Application Service (bisher scripts/monitoring_run-cli.php mit
 *        inc/monitoring.php und inc/monitoring_mailer.php) sowie Bereinigung des Diagnoseprotokolls (bisher
 *        scripts/monitoring_log_cleanup-cli.php).
 *
 * Ablauf run() – fachlich unverändert:
 * 1. Laufsperre (Advisory-Lock 'dnsmanager_monitoring', höchstens $lockWaitSeconds Wartezeit). Cron und die
 *    manuelle Aktualisierung der Systemstatus-Seite laufen damit nie gleichzeitig.
 * 2. Für alle aktiven Server: server_status und zone_conf_status prüfen (MonitoringBackend) und speichern.
 * 3. Für alle aktiven Server × zugewiesene Zonen (sortiert nach Zone, Server): zone_status prüfen und speichern.
 *    Die Netzwerkprüfungen laufen vor der Transaktion; gespeichert wird je Server bzw. Zone in einer kurzen
 *    Transaktion. Ein Fehler betrifft nur diesen Server bzw. diese Zone (Meldung, Lauf geht weiter).
 * 4. Verwaiste Zonendiagnosen (Zone dem Server nicht mehr zugewiesen) mit einer Anweisung entfernen.
 * 5. Benachrichtigungen (nur wenn aktiviert): unverschickte Statuswechsel ok → warning|error|not_found bzw.
 *    zurück auf ok, ältester zuerst; notified = 1 erst nach erfolgreichem Versand, fehlgeschlagene Meldungen
 *    bleiben für den nächsten Lauf offen (unverändert).
 *
 * Speichern (saveDiagnostic, unverändert in der Wirkung): Eintrag je (target_type, target_id, check_type,
 * server_id) anlegen bzw. aktualisieren; jeder Statuswechsel (bzw. die Neuanlage mit alt = ok) wird in
 * diagnostic_log protokolliert, notified = 0 bei aktivierter Benachrichtigung, sonst 1. Paket 5b: Zeile unter
 * FOR UPDATE gelesen; ein gleichzeitig angelegter Eintrag (Unique-Schlüssel) führt zur Aktualisierung statt zu
 * einem Fehler. Zeitstempel in UTC (wie bisher im CLI-Lauf).
 *
 * AS4b (E76, E109, Ordnungsvertrag Abschnitt 5i/5j): Nach den Zonenprüfungen – nur wenn das Backend ZoneVerifyBackend
 * implementiert und ein Server die Fähigkeit meldet – eine L3-Runde je Server × Zone über zones/zone_verify. Beginn:
 * Publish-Lock (0 s) ⇒ Wasserstand W = höchste Lauf-ID (alle Läufe ≤ W beendet) ⇒ Lock frei; Abfragen ohne Lock;
 * Speicherung: Publish-Lock (0 s) ⇒ MAX(id) = W erneut (aktuelle Lesung) ⇒ ServerVerificationStore ⇒ Lock frei.
 * Belegter Lock oder geänderter Wasserstand ⇒ alle Beobachtungen des Zyklus verworfen; Transportfehler und nicht
 * unterstützte Antworten ⇒ keine Beobachtung. Lock-Reihenfolge: dnsmanager_monitoring → dnsmanager_publish_all.
 * Diagnosen, Meldungen und Benachrichtigungen bleiben unverändert; der Bericht enthält zusätzlich 'l3'.
 * Keine Session, kein HTTP, keine Sprachtexte, kein Logging: Ergebnis als ServiceResult mit ServiceMessages
 * ("monitoring.*"), Rohdaten im Kontext. Die Aufrufer (CLI, Systemstatus-Seite) schreiben Logs bzw. Toasts.
 */

require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/MonitoringBackend.php';
require_once __DIR__ . '/MonitoringNotifier.php';
require_once __DIR__ . '/ZoneVerifyBackend.php';
require_once __DIR__ . '/../publish/AgentResponseParser.php';
require_once __DIR__ . '/../publish/ServerVerificationStore.php';
require_once __DIR__ . '/../publish/SerialReferenceStore.php';
require_once __DIR__ . '/../publish/PublishLedger.php';

final class MonitoringService
{
    public const LOCK_NAME = 'dnsmanager_monitoring';

    private PDO $pdo;
    private MonitoringBackend $backend;
    private MonitoringNotifier $notifier;
    private bool $notificationsEnabled;

    /**
     * @param bool $notificationsEnabled bisher MAILER_ENABLED === true
     */
    public function __construct(PDO $pdo, MonitoringBackend $backend, MonitoringNotifier $notifier, bool $notificationsEnabled)
    {
        $this->pdo = $pdo;
        $this->backend = $backend;
        $this->notifier = $notifier;
        $this->notificationsEnabled = $notificationsEnabled;
    }

    /**
     * Monitoring-Lauf.
     *
     * Meldungen (in Ablaufreihenfolge): server_check_failed (serverId, detail), zone_check_failed (zoneName,
     * serverId, detail), mail_failed (errors), zuletzt genau eine monitoring.result mit status
     * ok | mail_error | locked | lock_failed sowie servers, zoneChecks, removedOrphans, mailOutput.
     * ok = true nur bei status ok (wie bisher Exit-Code 0).
     */
    public function run(int $lockWaitSeconds = 0): ServiceResult
    {
        $m = [];
        $report = ['status' => null, 'servers' => 0, 'zoneChecks' => 0, 'removedOrphans' => 0, 'mailOutput' => ''];

        try {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([self::LOCK_NAME, max(0, $lockWaitSeconds)]);
            $got = $stmt->fetchColumn();
        } catch (Throwable $e) {
            $m[] = new ServiceMessage('error', 'monitoring.lock_failed', ['detail' => $e->getMessage()]);
            return self::finish($m, $report, 'lock_failed');
        }
        if ($got === null || $got === false || (int)$got !== 1) {
            return self::finish($m, $report, 'locked');
        }

        try {
            // === SERVER-PRÜFUNG ===
            $servers = $this->pdo->query('SELECT id, name, api_ip, api_token FROM servers WHERE active = 1')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($servers as $server) {
                $serverId = (int)$server['id'];
                $report['servers']++;
                try {
                    $remoteResult = $this->backend->serverStatus($server);
                    $confResult = $this->backend->zoneConfStatus($server);

                    $this->pdo->beginTransaction();
                    $this->saveDiagnostic('server', $serverId, 'server_status', (string)$remoteResult['status'], (string)$remoteResult['message'], $serverId);
                    $this->saveDiagnostic('server', $serverId, 'zone_conf_status', (string)$confResult['status'], (string)$confResult['message'], $serverId);
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    $this->rollbackIfActive();
                    $m[] = new ServiceMessage('error', 'monitoring.server_check_failed', ['serverId' => $serverId, 'detail' => $e->getMessage()]);
                }
            }

            // === ZONEN-PRÜFUNG ===
            $rows = $this->pdo->query("
                SELECT z.id AS zone_id, z.name AS zone_name, s.id AS server_id, s.name, s.api_ip, s.api_token
                FROM zones z
                JOIN zone_servers zs ON zs.zone_id = z.id
                JOIN servers s ON s.id = zs.server_id
                WHERE s.active = 1
                ORDER BY z.name, s.name
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $zoneId = (int)$row['zone_id'];
                $zoneName = (string)$row['zone_name'];
                $serverId = (int)$row['server_id'];
                $report['zoneChecks']++;
                try {
                    $zoneResult = $this->backend->zoneStatus($zoneName, $row);

                    $this->pdo->beginTransaction();
                    $this->saveDiagnostic('zone', $zoneId, 'zone_status', (string)$zoneResult['status'], (string)$zoneResult['message'], $serverId);
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    $this->rollbackIfActive();
                    $m[] = new ServiceMessage('error', 'monitoring.zone_check_failed', ['zoneName' => $zoneName, 'serverId' => $serverId, 'detail' => $e->getMessage()]);
                }
            }

            // AS4b: L3-Beobachtung (zone_verify) nach dem Ordnungsvertrag; Fehler ändern das Monitoring-Ergebnis nicht
            $report['l3'] = $this->observeL3($rows);

            // Verwaiste Zonendiagnosen bereinigen (eine Anweisung statt N+1)
            $report['removedOrphans'] = $this->cleanupOrphanedZoneDiagnostics();

            // Benachrichtigungen
            $mail = $this->sendPendingAlerts();
            $report['mailOutput'] = $mail['output'];
            if ($mail['status'] !== 'ok') {
                $m[] = new ServiceMessage('error', 'monitoring.mail_failed', ['errors' => $mail['errors']]);
                return self::finish($m, $report, 'mail_error');
            }

            return self::finish($m, $report, 'ok');
        } finally {
            try {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
            } catch (Throwable $e) {
                // Freigabe spätestens mit dem Verbindungsende
            }
        }
    }

    /**
     * Entfernt Einträge aus diagnostic_log, die älter als die Aufbewahrungsfrist sind.
     *
     * @param string $retention z. B. '30D' (H, D, W, M, Y)
     *
     * Meldungen: monitoring.log_cleanup.invalid_retention (retention), .failed (detail),
     *            .success (count, threshold)
     */
    public function purgeLog(string $retention): ServiceResult
    {
        $interval = self::parseRetention($retention);
        if ($interval === null) {
            return new ServiceResult(false, [new ServiceMessage('error', 'monitoring.log_cleanup.invalid_retention', ['retention' => $retention])]);
        }

        $threshold = (new DateTime('now', new DateTimeZone('UTC')))->sub($interval)->format('Y-m-d H:i:s');

        try {
            $stmt = $this->pdo->prepare('DELETE FROM diagnostic_log WHERE changed_at < ?');
            $stmt->execute([$threshold]);
            $count = $stmt->rowCount();
        } catch (Throwable $e) {
            return new ServiceResult(false, [new ServiceMessage('error', 'monitoring.log_cleanup.failed', ['detail' => $e->getMessage()])]);
        }

        return new ServiceResult(true, [new ServiceMessage('success', 'monitoring.log_cleanup.success', ['count' => $count, 'threshold' => $threshold])]);
    }

    /**
     * Zeitbezeichner wie „30D“ oder „6M“ als DateInterval (H, D, W, M, Y); bisher parseIntervalToDateTime().
     */
    public static function parseRetention(string $spec): ?DateInterval
    {
        if (!preg_match('/^(\d+)([HDWMY])$/i', $spec, $match)) {
            return null;
        }
        [$_, $amount, $unit] = $match;

        return match (strtoupper($unit)) {
            'H' => new DateInterval("PT{$amount}H"),
            'D' => new DateInterval("P{$amount}D"),
            'W' => new DateInterval("P" . ($amount * 7) . "D"),
            'M' => new DateInterval("P{$amount}M"),
            'Y' => new DateInterval("P{$amount}Y"),
            default => null
        };
    }

    /**
     * AS4b: eine L3-Runde je unterstütztem Server × zugewiesener Zone mit Wasserstand (Abschnitt 5j).
     *
     * @param array<int, array<string, mixed>> $rows Zonenprüfungen (zone_id, zone_name, server_id, name, api_ip, api_token)
     * @return array<string, mixed>
     */
    private function observeL3(array $rows): array
    {
        if (!$this->backend instanceof ZoneVerifyBackend) {
            return ['status' => 'unsupported'];
        }
        $candidates = array_values(array_filter($rows, fn (array $r): bool => $this->backend->supportsZoneVerify((int)$r['server_id'])));
        if ($candidates === []) {
            return ['status' => 'no_capable_server'];
        }
        try {
            $watermark = $this->watermarkUnderPublishLock();
            if ($watermark === null) {
                return ['status' => 'publish_active', 'queried' => 0];
            }
            $items = [];
            $skipped = 0;
            $invalid = [];
            foreach ($candidates as $row) {
                $r = $this->backend->verifyZone((string)$row['zone_name'], $row);
                $v = AgentResponseParser::parseZoneVerify($r, (string)$row['zone_name'], $why);
                if ($v === null) {
                    // R3: ungültige Verifikation (auch falsche Zone, R2) wird verworfen – nachvollziehbar im Bericht
                    if ($why === 'invalid') {
                        $invalid[] = $row['zone_name'] . '@' . $row['name'];
                    } else {
                        $skipped++;
                    }
                    continue;
                }
                $items[] = ['zoneId' => (int)$row['zone_id'], 'zoneName' => (string)$row['zone_name'], 'serverId' => (int)$row['server_id'],
                    'serverName' => (string)$row['name'], 'obs' => ['state' => $v['state'], 'reason' => $v['reason'],
                        'served' => $v['servedSerial'], 'fileSerial' => $v['fileSerial'], 'fileHash' => $v['fileHash']]];
            }
            $stored = $this->storeUnderPublishLock($watermark, $items);

            return ['status' => $stored === null ? 'discarded' : 'ok', 'watermark' => $watermark, 'queried' => count($candidates),
                'noObservation' => $skipped, 'invalid' => count($invalid), 'invalidPairs' => $invalid, 'stored' => $stored];
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return ['status' => 'error', 'detail' => $e->getMessage()];
        }
    }

    /** Wasserstand W unter dem Publish-Lock (0 s); null = Lock belegt (Lauf aktiv) */
    private function watermarkUnderPublishLock(): ?int
    {
        if ((int)$this->pdo->query("SELECT GET_LOCK('" . PublishLedger::MAIN_LOCK . "', 0)")->fetchColumn() !== 1) {
            return null;
        }
        try {
            return (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM publish_runs')->fetchColumn();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . PublishLedger::MAIN_LOCK . "')")->fetchColumn();
        }
    }

    /**
     * Speicherung unter dem Publish-Lock nach erneuter Prüfung des Wasserstands; null = verworfen.
     *
     * @return array<string, int>|null
     */
    private function storeUnderPublishLock(int $watermark, array $items): ?array
    {
        if ((int)$this->pdo->query("SELECT GET_LOCK('" . PublishLedger::MAIN_LOCK . "', 0)")->fetchColumn() !== 1) {
            return null;
        }
        try {
            $refs = new SerialReferenceStore($this->pdo);
            if ((int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM publish_runs')->fetchColumn() !== $watermark) {
                // AS5 (D23): Messwerte ohne gesicherte Ordnung festhalten (lösen nichts ab); Fehler nur im Bericht
                foreach ($items as $it) {
                    try {
                        $refs->recordUncertain('monitoring', $watermark, null, (int)$it['zoneId'], (string)$it['zoneName'],
                            (int)$it['serverId'], (string)$it['serverName'], self::refObs($it['obs']));
                    } catch (Throwable $e) {
                        // Beobachtung war nie gespeichert; kein Referenzverlust gegenüber dem Stand ohne Monitoring
                    }
                }
                return null;
            }
            $refs->ensureEpoch($watermark);   // AS5: Epoche beim ersten AS5-Schreibvorgang

            return (new ServerVerificationStore($this->pdo, null, $refs))->recordMonitoring($watermark, $items);
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . PublishLedger::MAIN_LOCK . "')")->fetchColumn();
        }
    }

    /** AS5: Beobachtung im Format des SerialReferenceStore (Felder wie im Verification Store) */
    private static function refObs(array $obs): array
    {
        $state = (string)$obs['state'];

        return ['state' => $state, 'reason' => $obs['reason'] ?? null, 'served' => $obs['served'] ?? null,
            'fileSerial' => in_array($state, ['still_served', 'absent'], true) ? null : ($obs['fileSerial'] ?? null),
            'fileHash' => in_array($state, ['still_served', 'absent'], true) ? null : ($obs['fileHash'] ?? null)];
    }

    /**
     * Schreibt oder aktualisiert einen Diagnosestatus und protokolliert Statuswechsel (in der Transaktion des
     * Aufrufers).
     */
    private function saveDiagnostic(string $type, int $id, string $check, string $status, string $message, int $serverId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $notified = $this->notificationsEnabled ? 0 : 1;

        $row = $this->lockDiagnostic($type, $id, $check, $serverId);

        if ($row === null) {
            try {
                $this->pdo->prepare("
                    INSERT INTO diagnostics (target_type, target_id, check_type, status, message, checked_at, server_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$type, $id, $check, $status, $message, $now, $serverId]);
                $this->insertLog((int)$this->pdo->lastInsertId(), 'ok', $status, $now, $message, $serverId, $notified);
                return;
            } catch (PDOException $e) {
                // Gleichzeitig angelegt (Unique-Schlüssel): wie ein vorhandener Eintrag aktualisieren
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                $row = $this->lockDiagnostic($type, $id, $check, $serverId);
                if ($row === null) {
                    throw $e;
                }
            }
        }

        $this->pdo->prepare('UPDATE diagnostics SET status = ?, message = ?, checked_at = ? WHERE id = ?')
            ->execute([$status, $message, $now, $row['id']]);

        if ($row['status'] !== $status) {
            $this->insertLog((int)$row['id'], (string)$row['status'], $status, $now, $message, $serverId, $notified);
        }
    }

    /** @return array{id: int, status: string}|null */
    private function lockDiagnostic(string $type, int $id, string $check, int $serverId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, status FROM diagnostics
            WHERE target_type = ? AND target_id = ? AND check_type = ? AND server_id = ?
            FOR UPDATE
        ');
        $stmt->execute([$type, $id, $check, $serverId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row === false ? null : ['id' => (int)$row['id'], 'status' => (string)$row['status']];
    }

    private function insertLog(int $diagnosticId, string $old, string $new, string $now, string $message, int $serverId, int $notified): void
    {
        $this->pdo->prepare('
            INSERT INTO diagnostic_log (diagnostic_id, old_status, new_status, changed_at, message, server_id, notified)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$diagnosticId, $old, $new, $now, $message, $serverId, $notified]);
    }

    /**
     * Entfernt Zonendiagnosen, deren Zone dem Server nicht mehr zugewiesen ist (target_type 'zone' mit
     * server_id). Das zugehörige diagnostic_log entfällt per ON DELETE CASCADE (wie bisher).
     */
    private function cleanupOrphanedZoneDiagnostics(): int
    {
        return (int)$this->pdo->exec("
            DELETE d FROM diagnostics d
            WHERE d.target_type = 'zone'
              AND d.server_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM zone_servers zs WHERE zs.zone_id = d.target_id AND zs.server_id = d.server_id)
        ");
    }

    /**
     * Versendet Benachrichtigungen zu offenen Statuswechseln (bisher sendDiagnosticAlerts(); Auswahl, Texte,
     * Reihenfolge und Markierung unverändert).
     *
     * @return array{status: string, errors: string[], output: string}
     */
    private function sendPendingAlerts(): array
    {
        if (!$this->notificationsEnabled) {
            return [
                'status' => 'ok',
                'errors' => [],
                'output' => 'E-Mail-Benachrichtigungen deaktiviert (MAILER_ENABLED = false)'
            ];
        }

        $rows = $this->pdo->query("
            SELECT dl.id, dl.old_status, dl.new_status, dl.changed_at, dl.message, d.target_type, d.check_type,
                   s.name AS server_name, z.name AS zone_name
            FROM diagnostic_log dl
            JOIN diagnostics d ON d.id = dl.diagnostic_id
            LEFT JOIN servers s ON s.id = d.server_id
            LEFT JOIN zones z ON z.id = d.target_id AND d.target_type = 'zone'
            WHERE dl.notified = 0
              AND (
                (dl.old_status = 'ok' AND dl.new_status IN ('warning', 'error', 'not_found')) OR
                (dl.old_status IN ('warning', 'error', 'not_found') AND dl.new_status = 'ok')
              )
            ORDER BY dl.changed_at ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $errors = [];
        $output_lines = [];

        foreach ($rows as $row) {
            $namePart = $row['target_type'] === 'zone'
                ? "Zone: {$row['zone_name']}"
                : "Server: {$row['server_name']}";

            $subject = "[DNSManager] {$namePart} ({$row['check_type']}) Status: {$row['new_status']}";
            $target  = $row['target_type'] === 'zone' ? "Zone: {$row['zone_name']}" : "Server: {$row['server_name']}";
            $body = <<<TEXT
Statuswechsel erkannt:

Typ: {$row['target_type']}
Check: {$row['check_type']}
$target

Zeitpunkt: {$row['changed_at']}
Alter Status: {$row['old_status']}
Neuer Status: {$row['new_status']}

Meldung:
{$row['message']}
TEXT;

            $mailResult = $this->notifier->send($subject, $body);

            if ($mailResult['status'] === 'ok') {
                $this->pdo->prepare('UPDATE diagnostic_log SET notified = 1 WHERE id = ?')->execute([$row['id']]);
                $output_lines[] = "Versand: {$subject}";
            } else {
                foreach ($mailResult['errors'] as $msg) {
                    $errors[] = "Fehler beim Versand von ({$subject}): {$msg}";
                }
            }
        }

        return [
            'status' => empty($errors) ? 'ok' : 'error',
            'errors' => $errors,
            'output' => implode("\n", $output_lines)
        ];
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @param ServiceMessage[] $m
     */
    private static function finish(array $m, array $report, string $status): ServiceResult
    {
        $report['status'] = $status;
        $kind = match ($status) {
            'ok'     => 'success',
            'locked' => 'warning',
            default  => 'error',
        };
        $m[] = new ServiceMessage($kind, 'monitoring.result', $report);

        return new ServiceResult($status === 'ok', $m);
    }
}
