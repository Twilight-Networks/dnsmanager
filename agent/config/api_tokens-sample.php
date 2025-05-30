<?php
/**
 * Datei: /etc/dnsmanager/api_tokens.php
 * Zweck: Zentrale Verwaltung gültiger API-Tokens für REST-Endpunkte
 *
 * Rückgabe:
 * Ein Array mit statischen Tokens (z. B. für verteilte Server),
 * welche von allen API-Endpunkten zur Authentifizierung verwendet werden.
 *
 * Format:
 * Jeder Eintrag ist ein 256-Bit-Token als 64-stelliger Hex-String
 *
 * Sicherheit:
 * - Diese Datei darf nicht über das Web erreichbar sein
 * - Nur root + www-data sollten Lesezugriff haben
 * - Kann zentral via `api_config.php` eingebunden werden
 */

return [
    '1234567890abcdefghijklmnopqrstuvwxyz', // Beispieltoken
    // Weitere gültige Tokens hier ergänzen, jeweils als 64-stellige Hex-Zeichenkette
];
