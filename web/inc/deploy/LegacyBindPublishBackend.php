<?php
/**
 * Datei: inc/deploy/LegacyBindPublishBackend.php
 * Zweck: PublishBackend-Implementierung auf Basis der bestehenden BIND-/Agentenroutinen.
 *
 * - Ruft generate_zone_file(), distribute_zone_file(), distribute_zone_conf_file() und
 *   distribute_zone_cleanup() unverändert auf.
 * - Kapselt die globale PDO-Kopplung der Legacy-Distributoren ("global $pdo"): Der Konstruktor
 *   verlangt, dass die übergebene Verbindung dieselbe Instanz wie $GLOBALS['pdo'] ist. Damit nutzen
 *   Advisory-Lock, Flag-Verwaltung (PublishService), Generator und Distributoren dieselbe Verbindung.
 *   Die globale Verbindung wird niemals gesetzt oder ersetzt.
 * - Lädt keinen Web-Bootstrap (common.php). Konfigurationskonstanten (z. B. aus ui_config.php)
 *   müssen vom Aufrufer bereits geladen sein; fehlende Konstanten führen zu einer Exception.
 */

require_once __DIR__ . '/../app/publish/PublishBackend.php';
require_once __DIR__ . '/bind_file_generator.php';
require_once __DIR__ . '/bind_file_distributor.php';

final class LegacyBindPublishBackend implements PublishBackend
{
    /** Von Generator und Distributoren benötigte Konfigurationskonstanten */
    private const REQUIRED_CONSTANTS = ['REMOTE_API_BASE', 'CURL_SSL_VERIFYPEER', 'CURL_SSL_VERIFYHOST', 'BIND_BASE_DIR'];

    private PDO $pdo;

    /**
     * @param PDO $pdo Verbindung des PublishService; muss identisch mit $GLOBALS['pdo'] sein
     *
     * @throws LogicException wenn die globale Verbindung fehlt bzw. abweicht oder Konfiguration fehlt
     */
    public function __construct(PDO $pdo)
    {
        if (!isset($GLOBALS['pdo']) || $GLOBALS['pdo'] !== $pdo) {
            throw new LogicException(
                'LegacyBindPublishBackend: Die übergebene PDO-Verbindung muss mit $GLOBALS[\'pdo\'] identisch sein '
                . '(die Legacy-Distributoren verwenden die globale Verbindung).'
            );
        }

        foreach (self::REQUIRED_CONSTANTS as $constant) {
            if (!defined($constant)) {
                throw new LogicException("LegacyBindPublishBackend: Konfigurationskonstante $constant ist nicht definiert.");
            }
        }

        $this->pdo = $pdo;
    }

    public function generateZone(int $zoneId): array
    {
        // Explizite Übergabe derselben Verbindung (vom Generator bereits unterstützt)
        return generate_zone_file($zoneId, '/tmp', 'publish', $this->pdo);
    }

    public function distributeZone(int $zoneId, ?string $zonePath): array
    {
        return distribute_zone_file($zoneId, $zonePath);
    }

    public function distributeZoneConf(string $zoneName): array
    {
        return distribute_zone_conf_file($zoneName);
    }

    public function cleanupOrphans(): array
    {
        return distribute_zone_cleanup();
    }
}
