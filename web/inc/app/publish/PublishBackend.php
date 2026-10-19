<?php
/**
 * Datei: inc/app/publish/PublishBackend.php
 * Zweck: Infrastruktur-Schnittstelle des PublishService (Zonengenerierung, Verteilung, Cleanup).
 *
 * Der PublishService kennt weder BIND, named-checkzone noch die HTTP-API der DNS-Agenten.
 * Er arbeitet ausschließlich gegen diese Schnittstelle. Die Rückgabewerte entsprechen bewusst
 * unverändert den bestehenden Ergebnis-Arrays der Legacy-Funktionen, damit deren Status- und
 * Fehlersemantik (ok / warning / error, errors[], output) exakt erhalten bleibt.
 *
 * Implementierungen:
 * - LegacyBindPublishBackend (inc/deploy): ruft die bestehenden Generator-/Distributor-Funktionen auf
 * - Test-Backends (Fake) ohne BIND und Agenten
 */

interface PublishBackend
{
    /**
     * Erzeugt die Zonendatei einer Zone für die Veröffentlichung und validiert sie
     * (Legacy: generate_zone_file($zoneId, '/tmp', 'publish', $pdo)).
     *
     * Erhöht im Erfolgsfall den SOA-Serial in einer eigenen, vollständig abgeschlossenen
     * Transaktion auf derselben PDO-Verbindung wie der PublishService.
     *
     * @return array{status: string, path?: string, output?: string, errors?: array}
     *               status: 'ok' | 'warning' | 'error'
     */
    public function generateZone(int $zoneId): array;

    /**
     * Verteilt eine erzeugte Zonendatei an alle aktiven, zugewiesenen Server
     * (Legacy: distribute_zone_file($zoneId, $zonePath)).
     *
     * @return array{status: string, errors: array, output: string}
     *               status: 'ok' | 'warning' (Agent-/HTTP-/Netzwerkfehler) | 'error' (interne Fehler)
     */
    public function distributeZone(int $zoneId, ?string $zonePath): array;

    /**
     * Erzeugt und verteilt die zonenspezifische BIND-Konfiguration
     * (Legacy: distribute_zone_conf_file($zoneName)).
     *
     * @return array{status: string, errors: array, output: string}
     *               status: 'ok' | 'error'
     */
    public function distributeZoneConf(string $zoneName): array;

    /**
     * Entfernt verwaiste Zonen auf allen aktiven Servern
     * (Legacy: distribute_zone_cleanup()).
     *
     * @return array{status: string, errors: array, output: string}
     *               status: 'ok' | 'warning'
     */
    public function cleanupOrphans(): array;
}
