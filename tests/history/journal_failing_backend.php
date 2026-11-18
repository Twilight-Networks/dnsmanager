<?php
/**
 * Datei: tests/history/journal_failing_backend.php
 * Zweck: Test-Backend der Publish-Journal-Tests (AP1/H2d, Review S1), geladen nach der Anwendung (journal_lib.php).
 */

/**
 * Review S1: Backend, dessen bindJournal() beim Binden eines Journals absichtlich scheitert (Lösen mit null gelingt);
 * alle übrigen Methoden delegieren an das echte Backend.
 */
final class JFailingBindBackend implements PublishBackend
{
    public int $bindAttempts = 0;

    public function __construct(private readonly PublishBackend $inner)
    {
    }

    public function bindJournal(?PublishJournal $journal): void
    {
        if ($journal !== null) {
            $this->bindAttempts++;
            throw new RuntimeException('H2d-Injektion: bindJournal');
        }
        $this->inner->bindJournal(null);
    }

    public function generateZone(int $zoneId): array { return $this->inner->generateZone($zoneId); }
    public function distributeZone(int $zoneId, ?string $zonePath): array { return $this->inner->distributeZone($zoneId, $zonePath); }
    public function distributeZoneConf(string $zoneName): array { return $this->inner->distributeZoneConf($zoneName); }
    public function cleanupOrphans(): array { return $this->inner->cleanupOrphans(); }
}
