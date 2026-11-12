<?php
/**
 * Datei: inc/deploy/LegacyDyndnsPublishBackend.php
 * Zweck: DyndnsPublishBackend über die bestehende Funktion publish_single_zone() (inc/deploy/publish_single.php).
 *
 * - Ruft publish_single_zone() auf (Generator im Modus "dyndns" innerhalb der offenen Transaktion des Aufrufers,
 *   Verteilung, changed = 0 nur bei vollständigem Erfolg; Ablauf unverändert bis Paket 4b/S32).
 * - Paket 4a: Verbindung und Agent-Client werden explizit übergeben; keine globale Verbindung, kein Web-Bootstrap.
 *   Konfigurationskonstanten (REMOTE_API_BASE, CURL_SSL_VERIFYPEER, CURL_SSL_VERIFYHOST, BIND_BASE_DIR) müssen
 *   vom Aufrufer geladen sein; fehlende Konstanten führen zu einer LogicException.
 */

require_once __DIR__ . '/../app/dyndns/DyndnsPublishBackend.php';
require_once __DIR__ . '/BindAgentClient.php';
require_once __DIR__ . '/publish_single.php';

final class LegacyDyndnsPublishBackend implements DyndnsPublishBackend
{
    private PDO $pdo;
    private BindAgentClient $agent;

    /**
     * @throws LogicException wenn eine Konfigurationskonstante fehlt
     */
    public function __construct(PDO $pdo, ?BindAgentClient $agent = null)
    {
        if (!defined('BIND_BASE_DIR')) {
            throw new LogicException('LegacyDyndnsPublishBackend: Konfigurationskonstante BIND_BASE_DIR ist nicht definiert.');
        }
        $this->pdo = $pdo;
        $this->agent = $agent ?? BindAgentClient::fromConfig();
    }

    public function publishZone(int $zoneId, string $zoneName): array
    {
        $r = publish_single_zone($this->pdo, $this->agent, $zoneId, $zoneName);

        return ['status' => (string)($r['status'] ?? 'error'), 'output' => (string)($r['output'] ?? '')];
    }
}
