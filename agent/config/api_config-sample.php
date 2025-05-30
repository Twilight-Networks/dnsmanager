<?php
/**
 * Datei: api_config.php
 * Zweck: Zentrale Konfiguration der REST-API-Endpunkte für den DNS-Manager
 *
 * Enthält:
 * - Pfade zu Zonendateien und Konfigurationsdateien
 * - Pfade zu externen Programmen (rndc, named-checkzone, etc.)
 * - API-spezifische Einstellungen
 * - Sicherheit (z. B. IP-Whitelisting)
 * - Debug-Optionen
 *
 * Diese Datei wird von API-Endpunkten eingebunden, nicht vom Webinterface.
 */

return [

    // Zugriff nur von diesen IP-Adressen erlauben (Whitelist)
    'api_allowed_ips' => [
        // '127.0.0.1', '192.168.0.2', '::1', '2001:db8::1',
    ],

    // Pfad zum Verzeichnis mit .conf-Dateien für einzelne Zonen (BIND-include)
    'zone_conf_dir' => '/etc/bind/zones/conf',

    // Pfad zum Verzeichnis mit den Zonendateien (db.<zone>)
    'zone_data_dir' => '/etc/bind/zones',

    // Pfad zur globalen zones.conf (wird automatisch generiert)
    'zones_conf_file' => '/etc/bind/zones/zones.conf',

    // Pfad zur globalen Named-Conf-Datei
    'bind_named_conf_path' => '/etc/bind/named.conf',

    /** ---------------------------------------
     *  API-Token-Datei
     * --------------------------------------- */
    // Datei mit gültigen API-Tokens (muss ein return-Array enthalten)
    'token_file' => __DIR__ . '/api_tokens.php',

    /** ---------------------------------------
     *  Externe Tools
     * --------------------------------------- */
    'bind_named_checkzone' => '/usr/bin/named-checkzone',
    'bind_named_checkconf' => '/usr/bin/named-checkconf',
    'bind_rndc'            => '/usr/sbin/rndc',
];
