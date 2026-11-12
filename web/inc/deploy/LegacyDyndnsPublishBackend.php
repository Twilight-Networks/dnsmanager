<?php
/**
 * Datei: inc/deploy/LegacyDyndnsPublishBackend.php
 * Zweck: DyndnsPublishBackend über die bestehende Funktion publish_single_zone() (inc/deploy/publish_single.php).
 *
 * - Ruft publish_single_zone() unverändert auf (Generator im Modus "dyndns", Verteilung, changed = 0 nur bei
 *   vollständigem Erfolg).
 * - Der Generator arbeitet im Modus "dyndns" über die globale Verbindung und setzt die offene Transaktion des
 *   Aufrufers voraus. Der Konstruktor verlangt deshalb, dass die übergebene Verbindung dieselbe Instanz wie
 *   $GLOBALS['pdo'] ist (wie LegacyBindPublishBackend). Die globale Verbindung wird nie gesetzt oder ersetzt.
 * - publish_single.php lädt den Web-Bootstrap (common.php); das bleibt bis Paket 4 so.
 */

require_once __DIR__ . '/../app/dyndns/DyndnsPublishBackend.php';
require_once __DIR__ . '/publish_single.php';

final class LegacyDyndnsPublishBackend implements DyndnsPublishBackend
{
    private PDO $pdo;

    /**
     * @throws LogicException wenn die globale Verbindung fehlt bzw. abweicht
     */
    public function __construct(PDO $pdo)
    {
        if (!isset($GLOBALS['pdo']) || $GLOBALS['pdo'] !== $pdo) {
            throw new LogicException(
                'LegacyDyndnsPublishBackend: Die übergebene PDO-Verbindung muss mit $GLOBALS[\'pdo\'] identisch sein '
                . '(der Generator im Modus "dyndns" verwendet die globale Verbindung und die offene Transaktion).'
            );
        }
        $this->pdo = $pdo;
    }

    public function publishZone(int $zoneId, string $zoneName): array
    {
        $r = publish_single_zone($this->pdo, $zoneId, $zoneName);

        return ['status' => (string)($r['status'] ?? 'error'), 'output' => (string)($r['output'] ?? '')];
    }
}
