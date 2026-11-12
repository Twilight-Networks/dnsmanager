<?php
/**
 * Datei: inc/deploy/BindZoneBuildBackend.php
 * Zweck: Implementierung von ZoneBuildBackend: Zonendatei erzeugen und prüfen (named-checkzone), Zone als
 *        geändert markieren sowie NS-/Glue-Records aus der Serverzuweisung neu aufbauen.
 *
 * Ersetzt LegacyZoneBuildBackend und die Funktionen rebuild_zone_and_validate(),
 * rebuild_zone_and_flag_if_valid() und rebuild_ns_and_glue_for_zone_and_flag_if_valid() aus inc/helpers.php
 * (Paket 4a). Fachliche Logik unverändert übernommen.
 *
 * Transaktionsverantwortung (eine einzige Betriebsart):
 * - Beide Operationen laufen ausschließlich innerhalb der offenen Transaktion des Aufrufers. Ohne offene
 *   Transaktion wird mit LogicException abgebrochen (keine stille Ersatz-Transaktion, kein Autocommit).
 * - Das Backend beginnt, bestätigt oder verwirft nie eine Transaktion. Commit und Rollback verantwortet der
 *   Aufrufer; bei status 'error' oder Exception muss er zurückrollen. Damit ist der NS-/Glue-Neuaufbau
 *   (DELETE + INSERT) zusammen mit der auslösenden Änderung atomar.
 * - Der Aufrufer hält die Zonenzeile gesperrt (zones FOR UPDATE), bevor er den Neuaufbau auslöst.
 *
 * Temporäre Dateien: Die Prüfdatei entsteht in einem eigenen Arbeitsverzeichnis (BindWorkDir), das im
 * finally entfernt wird (S34). Kein Web-Bootstrap, keine globale Verbindung.
 */

require_once __DIR__ . '/../app/zones/ZoneBuildBackend.php';
require_once __DIR__ . '/bind_file_generator.php';
require_once __DIR__ . '/BindWorkDir.php';

final class BindZoneBuildBackend implements ZoneBuildBackend
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Zonendatei erzeugen und prüfen; bei Erfolg Zone als geändert markieren (changed = 1).
     *
     * @return array{status: string, output: string}
     * @throws LogicException ohne offene Transaktion des Aufrufers
     */
    public function validateAndFlag(int $zoneId): array
    {
        $this->assertCallerTransaction('validateAndFlag');

        $work = BindWorkDir::create();
        try {
            // Nur temporär zur Validierung → kein produktives Schreiben
            $result = generate_zone_file($zoneId, $work->path(), 'validate', $this->pdo);
        } finally {
            $work->remove();
        }

        if (!is_array($result) || $result['status'] === 'error') {
            return [
                'status' => 'error',
                'output' => (string)($result['output'] ?? 'Fehler beim Validieren der Zonendatei.')
            ];
        }

        // Zone als geändert markieren
        try {
            $stmt = $this->pdo->prepare("UPDATE zones SET changed = 1 WHERE id = ?");
            $stmt->execute([$zoneId]);
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'output' => "Zone konnte nicht als geändert markiert werden: " . $e->getMessage()
            ];
        }

        return [
            'status' => (string)$result['status'], // ok oder warning
            'output' => (string)($result['output'] ?? 'Zone wurde als geändert markiert.')
        ];
    }

    /**
     * NS- und Glue-Records der Zone aus der Serverzuweisung neu aufbauen, danach wie validateAndFlag().
     *
     * @return array{status: string, output: string}
     * @throws LogicException ohne offene Transaktion des Aufrufers
     */
    public function rebuildNsGlueAndFlag(int $zoneId): array
    {
        $this->assertCallerTransaction('rebuildNsGlueAndFlag');

        $pdo = $this->pdo;

        // Zone ermitteln
        $stmt = $pdo->prepare("SELECT name, type FROM zones WHERE id = ?");
        $stmt->execute([$zoneId]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$zone) {
            return ['status' => 'error', 'output' => "Zone-ID {$zoneId} nicht gefunden."];
        }

        $zone_name = rtrim($zone['name'], '.');
        $type = $zone['type'];

        // Alle zugewiesenen Server abrufen
        $stmt = $pdo->prepare("
            SELECT s.id, s.name, s.dns_ip4, s.dns_ip6
            FROM zone_servers zs
            JOIN servers s ON zs.server_id = s.id
            WHERE zs.zone_id = ?
        ");
        $stmt->execute([$zoneId]);
        $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($servers)) {
            return ['status' => 'error', 'output' => "Keine Server für Zone {$zone_name} zugewiesen."];
        }

        // TTLs vorhandener Records vor dem Löschen sichern
        $stmt = $pdo->prepare("
            SELECT name, type, ttl
            FROM records
            WHERE zone_id = ? AND server_id IS NOT NULL AND type IN ('NS', 'A', 'AAAA')
        ");
        $stmt->execute([$zoneId]);
        $existing_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ttl_map = []; // Format: ['NS']['@'] oder ['A']['ns1'] etc.
        foreach ($existing_records as $rec) {
            $key = ($rec['type'] === 'NS') ? '@' : strtolower($rec['name']);
            $ttl_map[$rec['type']][$key] = (int)$rec['ttl'];
        }

        // Alle vorhandenen systemgenerierten NS/A/AAAA-Records löschen
        $pdo->prepare("
            DELETE FROM records
            WHERE zone_id = ? AND server_id IS NOT NULL AND type IN ('NS', 'A', 'AAAA')
        ")->execute([$zoneId]);

        // Neue Insert-Statements vorbereiten
        $stmt_ns   = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, '@', 'NS', ?, ?, ?)");
        $stmt_a    = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, ?, 'A', ?, ?, ?)");
        $stmt_aaaa = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, ?, 'AAAA', ?, ?, ?)");

        foreach ($servers as $srv) {
            $sid     = $srv['id'];
            $fqdn    = rtrim($srv['name'], '.') . '.';
            $is_glue = $type === 'forward' && str_ends_with($fqdn, '.' . $zone_name . '.');

            // TTL für NS-Record holen oder Fallback
            $ns_ttl = $ttl_map['NS']['@'] ?? 3600;
            $stmt_ns->execute([$zoneId, $fqdn, $ns_ttl, $sid]);

            // Glue-Records nur bei Forward-Zonen und wenn FQDN in der Zone liegt
            if ($is_glue) {
                $host_part = rtrim(str_replace('.' . $zone_name . '.', '', $fqdn), '.');

                if (!empty($srv['dns_ip4'])) {
                    $a_ttl = $ttl_map['A'][strtolower($host_part)] ?? 300;
                    $stmt_a->execute([$zoneId, $host_part, $srv['dns_ip4'], $a_ttl, $sid]);
                }

                if (!empty($srv['dns_ip6'])) {
                    $aaaa_ttl = $ttl_map['AAAA'][strtolower($host_part)] ?? 300;
                    $stmt_aaaa->execute([$zoneId, $host_part, $srv['dns_ip6'], $aaaa_ttl, $sid]);
                }
            }
        }

        // Zonendatei neu generieren und prüfen
        return $this->validateAndFlag($zoneId);
    }

    private function assertCallerTransaction(string $operation): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException("BindZoneBuildBackend::$operation erfordert eine offene Transaktion des Aufrufers.");
        }
    }
}
