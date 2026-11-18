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
 */

require_once __DIR__ . '/PublishLedger.php';
require_once __DIR__ . '/PublishRun.php';

final class PublishJournal
{
    private int $writeFailures = 0;
    /** @var string[] */
    private array $errors = [];
    private Closure $log;

    public function __construct(private readonly PublishLedger $ledger, private readonly PublishRun $run, callable $log)
    {
        $this->log = Closure::fromCallable($log);
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

    public function zoneResult(int $attemptId, int $serverId, array $c): void
    {
        $this->guard("zone_sync Versuch $attemptId Server $serverId", fn () => $this->ledger->recordZoneTarget($this->run, $attemptId, $serverId, $c));
    }

    public function confResult(int $attemptId, int $serverId, array $c, ?string $confHash): void
    {
        $this->guard("conf_sync Versuch $attemptId Server $serverId", fn () => $this->ledger->recordConfTarget($this->run, $attemptId, $serverId, $c, $confHash));
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

    public function cleanupResult(int $serverId, array $c, ?int $validZoneCount): void
    {
        $this->guard("Cleanup Server $serverId", fn () => $this->ledger->recordCleanup($this->run, $serverId, $c, $validZoneCount));
    }

    public function finishRun(string $result): void
    {
        $this->guard("Abschluss Lauf {$this->run->id}", fn () => $this->ledger->finishRun($this->run, $result));
    }

    /** @return array{runId: int, writeFailures: int, errors: string[]} */
    public function report(): array
    {
        return ['runId' => $this->run->id, 'writeFailures' => $this->writeFailures, 'errors' => $this->errors];
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
