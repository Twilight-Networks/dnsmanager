<?php
/**
 * Datei: inc/app/monitoring/ZoneVerifyBackend.php
 * Zweck: Optionale Fähigkeit eines MonitoringBackend (AS4b, E76/E109): unabhängige L3-Beobachtung über den lesenden
 *        Agent-Endpunkt zones/zone_verify. Backends ohne diese Fähigkeit (Tests, ältere Implementierungen) liefern keine
 *        L3-Beobachtungen; die bestehende Diagnostik bleibt unverändert.
 */

interface ZoneVerifyBackend
{
    /** Meldet der Server die Fähigkeit (system/status desselben Monitoring-Laufs: capabilities, Modus observe/enforce)? */
    public function supportsZoneVerify(int $serverId): bool;

    /**
     * Eine Runde L3-Beobachtung einer Zone auf dem Server.
     *
     * @return array{body: string|false, httpCode: int, curlError: string, curlErrno: int, tlsConfigError: bool}
     */
    public function verifyZone(string $zoneName, array $server): array;
}
