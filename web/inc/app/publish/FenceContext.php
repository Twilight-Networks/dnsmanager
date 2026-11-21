<?php
/**
 * Datei: inc/app/publish/FenceContext.php
 * Zweck: Fencing-Domäne des Core (AS4b, E74, O2, K2; docs/ARCHITECTURE_HISTORY.md Abschnitte 5e, 5i, 5j).
 *
 * Die Domäne der Installation steht in der Datenbank (system_status.fence_domain, Migration 007c) UND in der
 * Web-Konfiguration (Konstante FENCE_DOMAIN in ui_config.php). Vor jedem Publish-Lauf – unter dem Publish-Lock, vor J6/J1
 * und damit vor dem ersten Journal-Schreibvorgang (K2) – müssen beide gesetzt, gültig (UUID in Kleinbuchstaben) und
 * identisch sein; sonst startet kein Lauf (fail-closed, E74: geklonte Datenbank ohne geklonte Web-Konfiguration bzw.
 * umgekehrt). Nach der Laufregistrierung (J1) bildet die Lauf-ID das Fencing-Token (F5); beides wird an zone_sync,
 * conf_sync und zone_cleanup gesendet (fence_token, fence_domain). Agenten im Modus off ignorieren die Felder (E95).
 *
 * Restore-Abgleich (E81, O3): checkRestore() vergleicht die nächste Lauf-ID mit den von Agenten in observe/enforce
 * gemeldeten Höchstständen H. Ist die nächste Lauf-ID ≤ H, startet kein Lauf; behoben wird das nur über die Admin-CLI
 * (web/scripts/fence_admin-cli.php raise-runs). Agenten in off, Legacy-Agenten und nicht erreichbare Agenten melden
 * keinen Höchststand und blockieren nicht; sie erscheinen im Bericht.
 *
 * Rein bis auf die übergebene PDO-Verbindung; kein Netzwerk (die Höchststände liefert das Backend).
 */

final class FenceContext
{
    public const DOMAIN_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** @param string|null $configuredDomain Domäne aus der Web-Konfiguration (null = nicht konfiguriert) */
    public function __construct(private readonly ?string $configuredDomain)
    {
    }

    /** Domäne aus der Web-Konfiguration (FENCE_DOMAIN; fehlt die Konstante, gilt sie als nicht konfiguriert) */
    public static function fromConfig(): self
    {
        return new self(defined('FENCE_DOMAIN') && is_string(FENCE_DOMAIN) ? FENCE_DOMAIN : null);
    }

    public static function isDomain(mixed $value): bool
    {
        return is_string($value) && preg_match(self::DOMAIN_PATTERN, $value) === 1;
    }

    /**
     * Prüft Datenbank- gegen Web-Domäne (vor J6/J1, unter dem Publish-Lock).
     *
     * @return string die gemeinsame Domäne
     * @throws RuntimeException "Fencing: …" (der Aufrufer bricht ohne Seiteneffekt ab)
     */
    public function resolve(PDO $pdo): string
    {
        $stmt = $pdo->prepare('SELECT fence_domain FROM system_status WHERE id = 1');
        $stmt->execute();
        $db = $stmt->fetchColumn();
        $stmt->closeCursor();
        $db = $db === false ? null : $db;
        if ($db === null) {
            throw new RuntimeException('Fencing: Domäne in der Datenbank nicht initialisiert (system_status.fence_domain; fence_admin-cli.php init-domain)');
        }
        if (!self::isDomain($db)) {
            throw new RuntimeException('Fencing: Domäne in der Datenbank ungültig');
        }
        if ($this->configuredDomain === null || $this->configuredDomain === '') {
            throw new RuntimeException('Fencing: Domäne in der Web-Konfiguration nicht gesetzt (FENCE_DOMAIN)');
        }
        if (!self::isDomain($this->configuredDomain)) {
            throw new RuntimeException('Fencing: Domäne in der Web-Konfiguration ungültig (FENCE_DOMAIN)');
        }
        if (!hash_equals((string)$db, $this->configuredDomain)) {
            throw new RuntimeException('Fencing: Domäne der Datenbank weicht von der Web-Konfiguration ab');
        }

        return (string)$db;
    }

    /**
     * Nächste Lauf-ID (Untergrenze): GREATEST(MAX(id) + 1, AUTO_INCREMENT).
     */
    public static function nextRunId(PDO $pdo): int
    {
        $row = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 AS m, (SELECT COALESCE(AUTO_INCREMENT, 0) FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publish_runs') AS a FROM publish_runs")->fetch(PDO::FETCH_ASSOC);

        return max((int)$row['m'], (int)$row['a']);
    }

    /**
     * Restore-Abgleich (E81): jeder gemeldete Höchststand H muss kleiner als die nächste Lauf-ID sein.
     *
     * @param array<int, array{name: string, mode: ?string, high: ?int, reachable: bool}> $agents je aktivem Server
     * @return array{nextRunId: int, servers: array<int, string>} Bericht je Server (ok | kein Höchststand (…) | Konflikt …)
     * @throws RuntimeException "Fencing: …" bei einem Konflikt (kein Lauf)
     */
    public static function checkRestore(PDO $pdo, array $agents): array
    {
        $next = self::nextRunId($pdo);
        $report = [];
        $conflicts = [];
        foreach ($agents as $sid => $a) {
            if (!$a['reachable']) {
                $report[(int)$sid] = 'kein Höchststand (nicht erreichbar)';
            } elseif (!in_array($a['mode'], ['observe', 'enforce'], true) || $a['high'] === null) {
                $report[(int)$sid] = 'kein Höchststand (' . ($a['mode'] ?? 'legacy') . ')';
            } elseif ($a['high'] >= $next) {
                $report[(int)$sid] = "Konflikt: Höchststand {$a['high']} ≥ nächste Lauf-ID $next";
                $conflicts[] = "{$a['name']} (H = {$a['high']})";
            } else {
                $report[(int)$sid] = "ok (H = {$a['high']} < $next)";
            }
        }
        if ($conflicts !== []) {
            throw new RuntimeException('Fencing: nächste Lauf-ID ' . $next . ' nicht größer als der Höchststand von '
                . implode(', ', $conflicts) . ' – Abgleich nach Datenbank-Restore erforderlich (fence_admin-cli.php raise-runs)');
        }

        return ['nextRunId' => $next, 'servers' => $report];
    }
}
