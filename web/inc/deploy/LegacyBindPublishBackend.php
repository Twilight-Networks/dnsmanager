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
 *
 * Publish-Journal (AP1/H2d, E59–E66): Ist mit bindJournal() ein Journal gebunden, registriert generateZone() den
 * Versuch in der Generator-Transaktion vor dem Serial-Commit (J2); distributeZone() und distributeZoneConf() verteilen
 * an die registrierte Zielmenge dieses Versuchs und melden jede Agentenantwort (J4); cleanupOrphans() registriert
 * vorab alle Server. Ohne Journal bleibt das Verhalten unverändert.
 */

require_once __DIR__ . '/../app/publish/PublishBackend.php';
require_once __DIR__ . '/../app/publish/PublishHashContract.php';
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

    private ?PublishJournal $journal = null;

    /** @var array<string, mixed>|null registrierter Versuch der zuletzt erzeugten Zone (generateZone → Verteilung) */
    private ?array $attempt = null;

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

    public function bindJournal(?PublishJournal $journal): void
    {
        $this->journal = $journal;
        $this->attempt = null;
    }

    public function generateZone(int $zoneId): array
    {
        $this->attempt = null;
        $journal = $this->journal;
        $hook = $journal === null ? null : static function (array $g) use ($journal, $zoneId): array {
            return $journal->registerAttempt($zoneId, $g['zone'], $g['records'], $g['serial'], $g['file'],
                ($g['render'])(PublishHashContract::SERIAL_PLACEHOLDER));
        };

        $work = BindWorkDir::create();
        try {
            $result = generate_zone_file($zoneId, $work->path(), 'publish', $this->pdo, $hook);
        } catch (Throwable $e) {
            $work->remove();
            throw $e;
        }

        $path = $result['path'] ?? null;
        if (($result['status'] ?? null) === 'error' || !is_string($path) || !$work->contains($path)) {
            $work->remove();
        } else {
            $this->pending[$path] = $work;
            if (isset($result['journal']) && is_array($result['journal'])) {
                $this->attempt = $result['journal'];
            }
        }

        return $result;
    }

    public function distributeZone(int $zoneId, ?string $zonePath): array
    {
        $opts = null;
        $attempt = $this->attempt;
        $journal = $this->journal;
        if ($journal !== null && $attempt !== null && (int)$attempt['zoneId'] === $zoneId) {
            $attemptId = (int)$attempt['attemptId'];
            $opts = [
                'targets'  => $attempt['targets'],
                'fileHash' => $attempt['fileHash'],
                'onResult' => static fn (int $sid, array $c) => $journal->zoneResult($attemptId, $sid, $c),
            ];
        }
        try {
            return distribute_zone_file($this->pdo, $this->agent, $zoneId, $zonePath, $opts);
        } finally {
            if ($zonePath !== null && isset($this->pending[$zonePath])) {
                $this->pending[$zonePath]->remove();
                unset($this->pending[$zonePath]);
            }
        }
    }

    public function distributeZoneConf(string $zoneName): array
    {
        $opts = null;
        $attempt = $this->attempt;
        $journal = $this->journal;
        if ($journal !== null && $attempt !== null && (string)$attempt['zoneName'] === $zoneName) {
            $attemptId = (int)$attempt['attemptId'];
            $opts = [
                'targets'  => $attempt['targets'],
                'onResult' => static fn (int $sid, array $c, ?string $h) => $journal->confResult($attemptId, $sid, $c, $h),
            ];
        }
        $work = BindWorkDir::create();
        try {
            return distribute_zone_conf_file($this->pdo, $this->agent, $work, $zoneName, $opts);
        } finally {
            $work->remove();
        }
    }

    public function cleanupOrphans(): array
    {
        $journal = $this->journal;
        $opts = $journal === null ? null : [
            'register' => static fn (array $servers): bool => $journal->registerCleanup($servers),
            'onResult' => static fn (int $sid, array $c, ?int $valid) => $journal->cleanupResult($sid, $c, $valid),
        ];

        return distribute_zone_cleanup($this->pdo, $this->agent, $opts);
    }
}
