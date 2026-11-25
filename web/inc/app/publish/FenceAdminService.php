<?php
/**
 * Datei: inc/app/publish/FenceAdminService.php
 * Zweck: Administrative Fencing-Operationen des Core (AS4b, E74/O2, E81/O3; docs/ARCHITECTURE_HISTORY.md Abschnitt 5j).
 *        Adapter: web/scripts/fence_admin-cli.php (nur CLI).
 *
 * - status(): Domäne in Datenbank und Web-Konfiguration, nächste Lauf-ID, Fencing-Status der Agenten (nur lesend).
 * - initDomain(): setzt system_status.fence_domain einmalig (nur wenn NULL) auf die konfigurierte Domäne (FENCE_DOMAIN).
 * - raiseRuns(): hebt die nächste Lauf-ID nach einem Datenbank-Restore über einen Agent-Höchststand an (nie senken).
 * Kein automatischer Abgleich: Beide schreibenden Operationen laufen nur auf ausdrücklichen Aufruf, unter dem Publish-Lock
 * (Try-Lock; belegt ⇒ keine Änderung). Agent-Höchststände werden nie verändert (E81: Agent senkt H nie, kein API-Reset).
 */

require_once __DIR__ . '/FenceContext.php';
require_once __DIR__ . '/FenceStatusProvider.php';
require_once __DIR__ . '/PublishLedger.php';

final class FenceAdminService
{
    public function __construct(private readonly PDO $pdo, private readonly FenceContext $fence,
        private readonly ?string $configuredDomain, private readonly ?FenceStatusProvider $agents = null)
    {
    }

    /** @return array{dbDomain: ?string, configDomain: ?string, consistent: bool, nextRunId: int, agents: array, restore: string} */
    public function status(): array
    {
        $db = $this->pdo->query('SELECT fence_domain FROM system_status WHERE id = 1')->fetchColumn();
        $db = $db === false ? null : $db;
        try {
            $this->fence->resolve($this->pdo);
            $consistent = true;
        } catch (RuntimeException $e) {
            $consistent = false;
        }
        $agents = $this->agents?->agentFenceStatus() ?? [];
        try {
            $restore = FenceContext::checkRestore($this->pdo, $agents);
            $restoreText = 'ok';
        } catch (RuntimeException $e) {
            $restore = null;
            $restoreText = $e->getMessage();
        }

        return ['dbDomain' => $db, 'configDomain' => $this->configuredDomain, 'consistent' => $consistent,
            'nextRunId' => FenceContext::nextRunId($this->pdo), 'agents' => $agents, 'restore' => $restoreText,
            'servers' => $restore['servers'] ?? []];
    }

    /**
     * Setzt die Domäne der Installation einmalig (nur wenn NULL) auf die konfigurierte Domäne.
     *
     * @throws RuntimeException bei Lock belegt, bereits gesetzter Domäne oder ungültiger/abweichender Angabe
     */
    public function initDomain(string $domain): void
    {
        if (!FenceContext::isDomain($domain)) {
            throw new RuntimeException('Domäne ungültig (UUID in Kleinbuchstaben erwartet)');
        }
        if ($this->configuredDomain === null || !hash_equals($this->configuredDomain, $domain)) {
            throw new RuntimeException('Domäne entspricht nicht der Web-Konfiguration (FENCE_DOMAIN) – zuerst die Konfiguration setzen');
        }
        $this->withLock(function () use ($domain): void {
            $stmt = $this->pdo->prepare('UPDATE system_status SET fence_domain = ? WHERE id = 1 AND fence_domain IS NULL');
            $stmt->execute([$domain]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Domäne bereits gesetzt – keine Änderung (eine Änderung ist nicht vorgesehen)');
            }
        });
    }

    /**
     * Hebt die nächste Lauf-ID auf above + 1 an (nur anheben, E81). Erfordert das Recht ALTER auf publish_runs.
     *
     * @return int neue nächste Lauf-ID
     * @throws RuntimeException bei Lock belegt, keinem Anheben oder Fehlschlag
     */
    public function raiseRuns(int $above): int
    {
        $target = $above + 1;
        return $this->withLock(function () use ($target): int {
            $next = FenceContext::nextRunId($this->pdo);
            if ($target <= $next) {
                throw new RuntimeException("Nächste Lauf-ID ist bereits $next ≥ $target – keine Änderung");
            }
            $this->pdo->exec('ALTER TABLE publish_runs AUTO_INCREMENT = ' . $target);
            $after = FenceContext::nextRunId($this->pdo);
            if ($after < $target) {
                throw new RuntimeException("Anheben nicht wirksam (nächste Lauf-ID $after)");
            }

            return $after;
        });
    }

    private function withLock(callable $fn): mixed
    {
        $got = $this->pdo->query("SELECT GET_LOCK('" . PublishLedger::MAIN_LOCK . "', 0)")->fetchColumn();
        if ((int)$got !== 1) {
            throw new RuntimeException('Publish-Lock belegt – keine Änderung');
        }
        try {
            return $fn();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . PublishLedger::MAIN_LOCK . "')")->fetchColumn();
        }
    }
}
