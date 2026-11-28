<?php
/**
 * Datei: inc/app/publish/PublishJournal.php
 * Zweck: Journal-Anbindung eines laufenden Publish-Laufs für das PublishBackend (AP1/H2d, E64).
 *
 * Bündelt Ledger und Lauf-Token. Der PublishService erzeugt sie nach der Laufregistrierung (J1) und übergibt sie mit
 * PublishBackend::bindJournal() an das Backend; das Backend meldet je Agentenantwort das Ergebnis (J4).
 *
 * Fehlerverhalten:
 * - registerAttempt() (J2) reicht jeden Fehler weiter: Der Generator rollt zurück (keine Serial, keine Datei, kein
 *   Agentaufruf).
 * - registerCleanup() liefert false bei einem Fehler: Das Backend führt dann keinen Cleanup aus (E66).
 * - Alle übrigen Ergebnis-Schreibvorgänge (J4) sind nicht sicherheitsrelevant: Ein Fehler wird gezählt, mit einer
 *   Logzeile "[publish] Journal: …" gemeldet (Q4) und im Bericht unter "journal" ausgewiesen; der Vorgang bleibt
 *   offen (J5: unknown nach Laufende, J6: abandoned). Das operative Ergebnis des Publish bleibt unverändert.
 *
 * AS4b (docs/ARCHITECTURE_HISTORY.md Abschnitt 5j):
 * - beforeAgentCall(): unmittelbar vor jedem Agent-Aufruf Publish-Lock und laufeigene Sperre (E83); Verlust ⇒
 *   RunLockLost (fail-closed, kein weiterer Request). Liefert die Fencing-Felder fence_token (Lauf-ID, F5) und
 *   fence_domain (E74) für zone_sync, conf_sync und zone_cleanup.
 * - Agent-Rohwerte (AgentResponseParser) im selben Endzustandsübergang wie das Ergebnis (J4a).
 * - L3-Beobachtungen nach der Matrix E77/J8 (Abschnitt 5i): conf_sync mit verification bzw. Zeitüberschreitung/
 *   unbestimmter Übertragung (unknown), Cleanup-Ergebnisse nur für eindeutig zuordenbare Paare
 *   (ServerVerificationStore). Legacy-Antworten, Antworten ohne verification und sicher nicht zugestellte Anfragen
 *   erzeugen keine Beobachtung. Schreibfehler ändern das operative Ergebnis nicht.
 *
 * AS5 (D20–D23, docs/ARCHITECTURE_HISTORY.md Abschnitt 5k) – Serial-Referenzen (SerialReferenceStore):
 * - beforeZoneSync(): Laufprüfung (E83), Write-ahead intent und erneute Laufprüfung VOR jedem zone_sync. Scheitert das
 *   intent, unterbleibt der Aufruf (SerialReferenceLost, Ziel serial_intent_failed, sicher nicht gesendet); geht die
 *   Laufberechtigung nach dem intent verloren, ebenso (RunLockLost, run_lock_lost). Der Lauf endet (exception).
 * - zoneResult(): schließt das intent ab – gültiger serial_check als Rohwert (agent_check), sonst no_report; ein
 *   vorhandener, aber ungültiger Bericht ist eine Verlustmarke. order_certain nur, wenn der Lauf nach der Antwort noch
 *   beide Sperren hält (verspätete Antwort ⇒ 0, D23). Danach unverändert J4.
 * - Scheitert eine sicherheitsrelevante Speicherung (Bericht bzw. Beobachtung), wird eine Verlustmarke geschrieben und der
 *   Lauf nach dem J4-Eintrag mit SerialReferenceLost beendet (bewusste Abweichung von E64 nur im Fehlerfall, D22).
 *   Scheitert auch die Marke, bleibt das intent ohne Abschluss – der Referenzverlust ist dauerhaft erkennbar.
 */

require_once __DIR__ . '/PublishLedger.php';
require_once __DIR__ . '/PublishRun.php';
require_once __DIR__ . '/PublishTargetClassifier.php';
require_once __DIR__ . '/AgentResponseParser.php';
require_once __DIR__ . '/ServerVerificationStore.php';
require_once __DIR__ . '/SerialReferenceStore.php';
require_once __DIR__ . '/SerialReferenceLost.php';

final class PublishJournal
{
    private int $writeFailures = 0;
    /** @var string[] */
    private array $errors = [];
    private Closure $log;

    /** @var array<string, int> */
    private array $unassigned = ['cleanup' => 0];

    /** @var array<string, true> AS5: geschriebene intents dieses Laufs ("Versuch:Server") */
    private array $intents = [];

    /** @var array<string, int> AS5: Serial-Referenzen dieses Laufs */
    private array $serialRefs = ['intent' => 0, 'agent_check' => 0, 'no_report' => 0, 'loss' => 0, 'uncertain' => 0];

    /**
     * @param string|null                  $domain  AS4b: geprüfte Fencing-Domäne (FenceContext::resolve); null = keine Felder
     * @param ServerVerificationStore|null $store   AS4b: Schreiber der L3-Beobachtungen; null = keine Beobachtungen
     * @param array<string, mixed>|null    $restore AS4b: Bericht des Restore-Abgleichs (E81)
     * @param SerialReferenceStore|null    $refs    AS5: Serial-Referenzen (null = keine; nur Tests der Vorstände)
     */
    public function __construct(private readonly PublishLedger $ledger, private readonly PublishRun $run, callable $log,
        private readonly ?string $domain = null, private readonly ?ServerVerificationStore $store = null,
        private readonly ?array $restore = null, private readonly ?SerialReferenceStore $refs = null)
    {
        $this->log = Closure::fromCallable($log);
    }

    /**
     * AS4b (E83, F5, E74): unmittelbar vor jedem Agent-Aufruf.
     *
     * @return array<string, int|string> zusätzliche Nutzlastfelder (fence_token, fence_domain)
     * @throws RunLockLost
     */
    public function beforeAgentCall(): array
    {
        $this->ledger->assertRunAlive($this->run);

        return $this->domain === null ? [] : ['fence_token' => $this->run->id, 'fence_domain' => $this->domain];
    }

    /**
     * AS5 (D22): Laufprüfung und Write-ahead vor einem zone_sync.
     *
     * @param array{attemptId: int, zoneId: int, zoneName: string, targets: array<int, string>} $attempt
     * @return array<string, int|string> Fencing-Felder
     * @throws RunLockLost|SerialReferenceLost
     */
    public function beforeZoneSync(array $attempt, int $serverId): array
    {
        $fields = $this->beforeAgentCall();
        if ($this->refs !== null) {
            try {
                $this->refs->recordIntent($this->run->id, (int)$attempt['attemptId'], (int)$attempt['zoneId'], (string)$attempt['zoneName'],
                    $serverId, isset($attempt['targets'][$serverId]) ? (string)$attempt['targets'][$serverId] : null);
            } catch (Throwable $e) {
                $this->failure("Serial-Referenz intent Versuch {$attempt['attemptId']} Server $serverId", $e->getMessage());
                throw new SerialReferenceLost("Write-ahead vor zone_sync nicht gespeichert – kein Aufruf: " . $e->getMessage(), 0, $e);
            }
            $this->intents[$attempt['attemptId'] . ':' . $serverId] = true;
            $this->serialRefs['intent']++;
            // Review AS5 (Punkt 4, E83): Laufberechtigung nach dem Write-ahead erneut prüfen. Ging sie dazwischen verloren,
            // folgt kein Agent-Aufruf (RunLockLost ⇒ Ziel run_lock_lost, sicher nicht gesendet); das intent wird mit
            // no_report/not_sent abgeschlossen, soweit die Verbindung das noch zulässt – sonst bleibt es offen (Sperre).
            $this->ledger->assertRunAlive($this->run);
        }

        return $fields;
    }

    public function runId(): int
    {
        return $this->run->id;
    }

    public function run(): PublishRun
    {
        return $this->run;
    }

    /** J2 – Fehler werden weitergereicht (Generator-Rollback) */
    public function registerAttempt(int $zoneId, array $zoneRow, array $records, int $serial, string $fileBytes, string $maskedRender): array
    {
        return $this->ledger->registerAttempt($this->run, $zoneId, $zoneRow, $records, $serial, $fileBytes, $maskedRender);
    }

    /**
     * @param array<string, mixed>|null $raw     Ergebnis von postJson() (null = lokaler Fehler vor dem Senden)
     * @param array<string, mixed>|null $attempt AS5: registrierter Versuch (für den Abschluss des intent)
     * @throws SerialReferenceLost nach dem J4-Eintrag, wenn der Abschluss nicht gespeichert werden konnte (D22)
     */
    public function zoneResult(int $attemptId, int $serverId, array $c, ?array $raw = null, ?array $attempt = null): void
    {
        $parsed = AgentResponseParser::parse(PublishTargetClassifier::KIND_ZONE, $raw, $c);
        $lost = null;
        if ($this->refs !== null && $attempt !== null && isset($this->intents[$attemptId . ':' . $serverId])) {
            $lost = $this->completeIntent($attempt, $serverId, $raw, $parsed);
        }
        $agent = AgentResponseParser::journalFields(PublishTargetClassifier::KIND_ZONE, $parsed);
        $this->guard("zone_sync Versuch $attemptId Server $serverId", fn () => $this->ledger->recordZoneTarget($this->run, $attemptId, $serverId, $c, $agent));
        if ($lost !== null) {
            throw new SerialReferenceLost($lost);
        }
    }

    /**
     * AS5: Abschluss des intent (agent_check, no_report oder Verlustmarke).
     *
     * @return string|null Fehlermeldung bei Referenzverlust (Lauf endet), sonst null
     */
    private function completeIntent(array $attempt, int $serverId, ?array $raw, array $parsed): ?string
    {
        $aid = (int)$attempt['attemptId'];
        unset($this->intents[$aid . ':' . $serverId]);
        $zoneId = (int)$attempt['zoneId'];
        $zoneName = (string)$attempt['zoneName'];
        $serverName = isset($attempt['targets'][$serverId]) ? (string)$attempt['targets'][$serverId] : null;
        try {
            $this->ledger->assertRunAlive($this->run);
            $certain = true;
        } catch (Throwable $e) {
            $certain = false;   // verspätete Antwort nach Lock-Verlust (D23)
        }
        if (!$certain) {
            $this->serialRefs['uncertain']++;
        }
        $sc = AgentResponseParser::serialCheck($raw, (int)$attempt['serial']);
        try {
            if ($sc['state'] === 'valid') {
                $this->refs->recordAgentCheck($this->run->id, $aid, $zoneId, $zoneName, $serverId, $serverName, $sc['report'], $certain);
                $this->serialRefs['agent_check']++;
            } elseif ($sc['state'] === 'invalid') {
                $this->refs->recordLoss($this->run->id, $aid, $zoneId, $zoneName, $serverId, $serverName, 'serial_check_invalid', $certain);
                $this->serialRefs['loss']++;
                ($this->log)('warning', "[publish] Serial-Referenz: ungültiger serial_check Versuch $aid Server $serverId ({$sc['why']}) – Verlustmarke");
            } else {
                $reason = $raw === null ? 'not_sent' : match ($parsed['protocol']) {
                    null => 'no_agent_response',
                    'legacy' => 'legacy',
                    default => 'no_serial_check',
                };
                $this->refs->recordNoReport($this->run->id, $aid, $zoneId, $zoneName, $serverId, $serverName, $reason, $certain);
                $this->serialRefs['no_report']++;
            }

            return null;
        } catch (Throwable $e) {
            $this->failure("Serial-Referenz Versuch $aid Server $serverId", $e->getMessage());
            try {
                $this->refs->recordLoss($this->run->id, $aid, $zoneId, $zoneName, $serverId, $serverName, 'report_not_stored', $certain);
                $this->serialRefs['loss']++;
            } catch (Throwable $e2) {
                $this->failure("Verlustmarke Versuch $aid Server $serverId", $e2->getMessage());
                return "Serial-Referenz Versuch $aid Server $serverId nicht gespeichert, Verlustmarke ebenfalls nicht – intent bleibt offen";
            }

            return "Serial-Referenz Versuch $aid Server $serverId nicht gespeichert (Verlustmarke gesetzt)";
        }
    }

    /**
     * conf_sync-Ergebnis (J4) und L3-Beobachtung (Matrix E77/J8, Abschnitt 5i).
     *
     * @param array{attemptId: int, zoneId: int, zoneName: string, targets: array<int, string>} $attempt registrierter Versuch
     */
    public function confResult(array $attempt, int $serverId, array $c, ?string $confHash, ?array $raw = null): void
    {
        $attemptId = (int)$attempt['attemptId'];
        // R2: Zonenidentität der Verifikation gegen die Zone des registrierten Versuchs
        $p = AgentResponseParser::parse(PublishTargetClassifier::KIND_CONF, $raw, $c, (string)$attempt['zoneName']);
        $agent = AgentResponseParser::journalFields(PublishTargetClassifier::KIND_CONF, $p);
        $this->guard("conf_sync Versuch $attemptId Server $serverId", fn () => $this->ledger->recordConfTarget($this->run, $attemptId, $serverId, $c, $confHash, $agent));
        if ($this->store === null) {
            return;
        }
        $obs = null;
        if ($p['verify'] !== null) {
            $v = $p['verify'];
            $obs = ['state' => $v['state'], 'reason' => $v['reason'], 'served' => $v['servedSerial'], 'fileSerial' => $v['fileSerial'], 'fileHash' => $v['fileHash']];
        } elseif ($p['protocol'] === null && ($c['status'] ?? null) === 'unknown' && in_array($c['errorCode'] ?? null, ['timeout', 'transport_unknown'], true)) {
            // Zeitüberschreitung bzw. unbestimmte Übertragung: Wirkung möglich ⇒ unknown (E77); positiver Nachweis bleibt
            $obs = ['state' => 'unknown', 'reason' => $c['errorCode'] === 'timeout' ? 'agent_timeout' : 'transport_unknown'];
        }
        if ($obs !== null) {
            $res = $this->store->recordPublish($this->ledger, $this->run, 'conf_sync', (int)$attempt['zoneId'], (string)$attempt['zoneName'],
                $serverId, (string)($attempt['targets'][$serverId] ?? "server $serverId"), $obs, $attemptId);
            $this->stopOnLoss($res, "conf_sync-Beobachtung Versuch $attemptId Server $serverId");
        }
    }

    /** AS5 (D22): nicht gespeicherte Beobachtung ⇒ Lauf endet (failed: Verlustmarke gesetzt; lost: auch diese fehlt) */
    private function stopOnLoss(string $result, string $what): void
    {
        if ($this->refs !== null && in_array($result, ['failed', 'lost'], true)) {
            throw new SerialReferenceLost("$what nicht gespeichert" . ($result === 'lost' ? ', Verlustmarke ebenfalls nicht' : ' (Verlustmarke gesetzt)'));
        }
    }

    public function finishAttempt(int $attemptId, string $outcome): void
    {
        $this->guard("Ergebnis Versuch $attemptId", fn () => $this->ledger->finishAttempt($this->run, $attemptId, $outcome));
    }

    /** @param array<int, string> $servers */
    public function registerCleanup(array $servers): bool
    {
        try {
            $this->ledger->registerCleanup($this->run, $servers);
            return true;
        } catch (Throwable $e) {
            $this->failure('Cleanup-Registrierung', $e->getMessage());
            return false;
        }
    }

    /**
     * Cleanup-Ergebnis (J4) und Beobachtungen der Entfernung (nur eindeutig zuordenbare Paare, O5).
     */
    public function cleanupResult(int $serverId, array $c, ?int $validZoneCount, ?array $raw = null, string $serverName = ''): void
    {
        $p = AgentResponseParser::parse(PublishTargetClassifier::KIND_CLEANUP, $raw, $c);
        $items = [];
        $unassigned = 0;
        if ($this->store !== null && $p['protocol'] === 'as2' && is_array($p['zones'])) {
            foreach ($p['zones'] as $z) {
                $pair = $z['name'] === null ? null : $this->store->resolveCleanupPair($serverId, $z['name']);
                if ($pair === null) {
                    $unassigned++;
                    ($this->log)('info', "[publish] Verifikation: Cleanup-Ergebnis auf Server $serverId für "
                        . ($z['name'] ?? '(ohne Namen)') . ' nicht eindeutig zuordenbar – nicht gespeichert');
                    continue;
                }
                $obs = match ($z['served']) {
                    'absent' => ['state' => 'absent', 'reason' => $z['reason']],
                    'still_served' => $z['servedSerial'] !== null
                        ? ['state' => 'still_served', 'reason' => $z['reason'], 'served' => $z['servedSerial']]
                        : ['state' => 'unknown', 'reason' => 'still_served_no_serial'],
                    default => ['state' => 'unknown', 'reason' => $z['reason']],
                };
                $items[] = [$pair, $obs];
            }
        }
        $this->unassigned['cleanup'] += $unassigned;
        $agent = AgentResponseParser::journalFields(PublishTargetClassifier::KIND_CLEANUP, $p, $unassigned);
        $this->guard("Cleanup Server $serverId", fn () => $this->ledger->recordCleanup($this->run, $serverId, $c, $validZoneCount, $agent));
        foreach ($items as [$pair, $obs]) {
            $res = $this->store->recordPublish($this->ledger, $this->run, 'cleanup', $pair['zoneId'], $pair['zoneName'], $serverId,
                $serverName !== '' ? $serverName : "server $serverId", $obs, null);
            $this->stopOnLoss($res, "Cleanup-Beobachtung {$pair['zoneName']} Server $serverId");
        }
    }

    public function finishRun(string $result): void
    {
        $this->guard("Abschluss Lauf {$this->run->id}", fn () => $this->ledger->finishRun($this->run, $result));
    }

    /** @return array{runId: int, writeFailures: int, errors: string[], fencing?: array, verification?: array} */
    public function report(): array
    {
        $r = ['runId' => $this->run->id, 'writeFailures' => $this->writeFailures, 'errors' => $this->errors];
        if ($this->domain !== null || $this->restore !== null) {
            $r['fencing'] = ['token' => $this->run->id, 'domain' => $this->domain, 'restore' => $this->restore];
        }
        if ($this->store !== null) {
            $r['verification'] = $this->store->report() + ['cleanupUnassigned' => $this->unassigned['cleanup']];
        }
        if ($this->refs !== null) {
            $r['serialReferences'] = $this->serialRefs + ['openIntents' => count($this->intents)];
        }

        return $r;
    }

    private function guard(string $what, callable $fn): void
    {
        try {
            if ($fn() !== true) {
                $this->failure($what, 'kein offener Eintrag (Endzustand bleibt unverändert)');
            }
        } catch (Throwable $e) {
            $this->failure($what, $e->getMessage());
        }
    }

    private function failure(string $what, string $detail): void
    {
        $this->writeFailures++;
        $msg = "$what: $detail";
        $this->errors[] = $msg;
        ($this->log)('warning', "[publish] Journal: $msg");
    }
}
