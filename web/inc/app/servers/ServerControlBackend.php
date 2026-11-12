<?php
/**
 * Datei: inc/app/servers/ServerControlBackend.php
 * Zweck: Schnittstelle für Steueraufrufe an einen DNS-Server (derzeit: BIND neu laden über den Agenten).
 *
 * Der ServerService kennt weder HTTP noch Agent-URLs; die Anbindung liefert ein Adapter
 * (LegacyAgentServerControlBackend). Rückgabe: ['status' => 'ok'|..., 'message' => string].
 */

interface ServerControlBackend
{
    /**
     * Lädt BIND auf dem Server neu.
     *
     * @param ?string $apiIp    API-IP des Servers (wie gespeichert, ggf. NULL)
     * @param string  $apiToken API-Token des Servers
     *
     * @return array{status: string, message: string}
     */
    public function reloadBind(?string $apiIp, string $apiToken): array;
}
