<?php
/**
 * Datei: inc/deploy/LegacyBindPublishBackend.php
 * Zweck: PublishBackend-Implementierung auf Basis der bestehenden BIND-/Agentenroutinen.
 * - Ruft generate_zone_file(), distribute_zone_file(), distribute_zone_conf_file() und
 *   distribute_zone_cleanup() mit expliziter Verbindung und explizitem Agent-Client auf (Paket 4a;
 *   keine globale Verbindung, kein Web-Bootstrap).
 * - Konfigurationskonstanten (z. B. aus ui_config.php) müssen vom Aufrufer bereits geladen sein; fehlende
 *   Konstanten führen zu einer Exception.
 *
 * Temporäre Dateien (S34):
 * - generateZone() erzeugt die Zonendatei in einem eigenen Arbeitsverzeichnis (BindWorkDir) je Zone.
 *   distributeZone() entfernt dieses Verzeichnis nach der Verteilung (auch bei Fehlern); bei einem
 *   Generierungsfehler wird es sofort entfernt.
 * - distributeZoneConf() verwendet je Aufruf ein eigenes Arbeitsverzeichnis und entfernt es im finally.
 * - Nicht verteilte Arbeitsverzeichnisse (z. B. Abbruch des Laufs nach generateZone()) entfernt der
 *   Destruktor; Restverzeichnisse nach hartem Prozessabbruch räumt BindWorkDir::create() später auf.
 */

require_once __DIR__ . '/../app/publish/PublishBackend.php';
require_once __DIR__ . '/bind_file_generator.php';
require_once __DIR__ . '/bind_file_distributor.php';
require_once __DIR__ . '/BindAgentClient.php';
require_once __DIR__ . '/BindWorkDir.php';

final class LegacyBindPublishBackend implements PublishBackend
{
    private PDO $pdo;
    private BindAgentClient $agent;

    /** @var array<string, BindWorkDir> Arbeitsverzeichnisse erzeugter, noch nicht verteilter Zonendateien (Pfad → Verzeichnis) */
    private array $pending = [];

    /**
     * @param PDO $pdo Verbindung des PublishService (Advisory-Lock, Flags, Generator und Distributoren)
     * @throws LogicException wenn Konfiguration fehlt
     */
    public function __construct(PDO $pdo, ?BindAgentClient $agent = null)
    {
        if (!defined('BIND_BASE_DIR')) {
            throw new LogicException('LegacyBindPublishBackend: Konfigurationskonstante BIND_BASE_DIR ist nicht definiert.');
        }

        $this->pdo = $pdo;
        $this->agent = $agent ?? BindAgentClient::fromConfig();
    }

    public function __destruct()
    {
        foreach ($this->pending as $work) {
            $work->remove();
        }
        $this->pending = [];
    }

    public function generateZone(int $zoneId): array
    {
        $work = BindWorkDir::create();
        try {
            $result = generate_zone_file($zoneId, $work->path(), 'publish', $this->pdo);
        } catch (Throwable $e) {
            $work->remove();
            throw $e;
        }

        $path = $result['path'] ?? null;
        if (($result['status'] ?? null) === 'error' || !is_string($path) || !$work->contains($path)) {
            $work->remove();
        } else {
            $this->pending[$path] = $work;
        }

        return $result;
    }

    public function distributeZone(int $zoneId, ?string $zonePath): array
    {
        try {
            return distribute_zone_file($this->pdo, $this->agent, $zoneId, $zonePath);
        } finally {
            if ($zonePath !== null && isset($this->pending[$zonePath])) {
                $this->pending[$zonePath]->remove();
                unset($this->pending[$zonePath]);
            }
        }
    }

    public function distributeZoneConf(string $zoneName): array
    {
        $work = BindWorkDir::create();
        try {
            return distribute_zone_conf_file($this->pdo, $this->agent, $work, $zoneName);
        } finally {
            $work->remove();
        }
    }

    public function cleanupOrphans(): array
    {
        return distribute_zone_cleanup($this->pdo, $this->agent);
    }
}
