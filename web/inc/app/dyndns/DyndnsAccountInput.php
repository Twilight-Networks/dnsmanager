<?php
/**
 * Datei: inc/app/dyndns/DyndnsAccountInput.php
 * Zweck: Eingabe für das Anlegen und Ändern eines DynDNS-Accounts (DyndnsAccountService).
 *
 * Werte wie bisher in den Actions aufbereitet: Benutzername und Passwort getrimmt, Hostname bewusst
 * NICHT getrimmt (Leer- und Steuerzeichen lehnt die Validierung ab), Zone als Integer.
 * Passwort leer = beim Ändern: Passwort unverändert lassen.
 */

final class DyndnsAccountInput
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
        public readonly string $hostname,
        public readonly int $zoneId
    ) {
    }
}
