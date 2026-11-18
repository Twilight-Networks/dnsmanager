<?php
/**
 * Datei: tests/history/h2b_fake_publish.php
 * Zweck: Test-Backend für PublishService (AP1/H2b-Tests), geladen nach der Anwendung (h2b_lib.php).
 */

/** Publish-Backend ohne BIND und Agenten: jede Erzeugung schlägt fehl, die Zone bleibt ausstehend */
final class H2bFakePublishBackend implements PublishBackend
{
    public function bindJournal(?PublishJournal $journal): void {}
    public function generateZone(int $zoneId): array { return ['status' => 'error', 'output' => 'Test-Backend', 'errors' => ['Test-Backend']]; }
    public function distributeZone(int $zoneId, ?string $zonePath): array { return ['status' => 'error', 'errors' => ['Test-Backend'], 'output' => '']; }
    public function distributeZoneConf(string $zoneName): array { return ['status' => 'error', 'errors' => ['Test-Backend'], 'output' => '']; }
    public function cleanupOrphans(): array { return ['status' => 'ok', 'errors' => [], 'output' => '']; }
}
