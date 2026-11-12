<?php
/**
 * Datei: inc/app/servers/ServerInput.php
 * Zweck: Eingabe für das Anlegen und Ändern eines DNS-Servers (ServerService).
 *
 * Werte wie aus dem Formular: Zeichenketten bereits getrimmt; leere Zeichenkette = nicht angegeben.
 * Normalisierung (leer → NULL) und Validierung erfolgen im Service unverändert wie bisher in den Actions.
 */

final class ServerInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $dnsIp4,
        public readonly string $dnsIp6,
        public readonly string $apiIp,
        public readonly string $apiToken,
        public readonly bool $isLocal,
        public readonly bool $active
    ) {
    }
}
