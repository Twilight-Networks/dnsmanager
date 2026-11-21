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
require_once __DIR__ . '/../app/publish/FenceStatusProvider.php';
require_once __DIR__ . '/../app/publish/PublishHashContract.php';
require_once __DIR__ . '/bind_file_generator.php';
require_once __DIR__ . '/bind_file_distributor.php';
require_once __DIR__ . '/BindAgentClient.php';
require_once __DIR__ . '/BindWorkDir.php';

final class LegacyBindPublishBackend implements PublishBackend, FenceStatusProvider
{
    /** AS4b (E81): Frist der system/status-Abfrage für den Restore-Abgleich (Sekunden) */
    public const STATUS_TIMEOUT = 5;

    private PDO $pdo;
    private AgentClient $agent;

    /** @var array<string, BindWorkDir> Arbeitsverzeichnisse erzeugter, noch nicht verteilter Zonendateien (Pfad → Verzeichnis) */
    private array $pending = [];

    private ?PublishJournal $journal = null;

    /** @var array<string, mixed>|null registrierter Versuch der zuletzt erzeugten Zone (generateZone → Verteilung) */
    private ?array $attempt = null;

    /**
     * @param PDO $pdo Verbindung des PublishService (Advisory-Lock, Flags, Generator und Distributoren)
     * @throws LogicException wenn Konfiguration fehlt
     */
    public function __construct(PDO $pdo, ?AgentClient $agent = null)
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
                'onResult' => static fn (int $sid, array $c, ?array $raw = null) => $journal->zoneResult($attemptId, $sid, $c, $raw),
                'beforeCall' => static fn (int $sid): array => $journal->beforeAgentCall(),
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
            $opts = [
                'targets'  => $attempt['targets'],
                'onResult' => static fn (int $sid, array $c, ?string $h, ?array $raw = null) => $journal->confResult($attempt, $sid, $c, $h, $raw),
                'beforeCall' => static fn (int $sid): array => $journal->beforeAgentCall(),
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
            'onResult' => static fn (int $sid, array $c, ?int $valid, ?array $raw = null, string $name = '') => $journal->cleanupResult($sid, $c, $valid, $raw, $name),
            'beforeCall' => static fn (int $sid): array => $journal->beforeAgentCall(),
        ];

        return distribute_zone_cleanup($this->pdo, $this->agent, $opts);
    }

    /**
     * AS4b (E81): Fencing-Status der aktiven Agenten aus system/status (nur lesend). mode/high nur, wenn der Agent sie
     * meldet (observe/enforce bzw. off mit gesperrten Schreibvorgängen); nicht erreichbar ⇒ reachable = false.
     */
    public function agentFenceStatus(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, name, api_ip, api_token FROM servers WHERE active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $srv) {
            $r = $this->agent->get($srv['api_ip'], '/system/status.php', (string)$srv['api_token'], null, self::STATUS_TIMEOUT);
            $json = is_string($r['body'] ?? null) ? json_decode((string)$r['body'], true) : null;
            $reachable = ($r['curlError'] ?? '') === '' && (int)($r['httpCode'] ?? 0) === 200 && is_array($json);
            $f = $reachable && is_array($json['fencing'] ?? null) ? $json['fencing'] : [];
            $mode = is_string($f['mode'] ?? null) ? $f['mode'] : null;
            $high = $f['high'] ?? null;
            $high = is_int($high) && $high > 0 ? $high : (is_string($high) && preg_match('/^[1-9][0-9]{0,18}$/D', $high) === 1 ? (int)$high : null);
            $out[(int)$srv['id']] = ['name' => (string)$srv['name'], 'mode' => $mode, 'high' => $high, 'reachable' => $reachable];
        }

        return $out;
    }
}
