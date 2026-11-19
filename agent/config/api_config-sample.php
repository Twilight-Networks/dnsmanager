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
    // Verwalteter Scope: Alle <zone>.conf in diesem Verzeichnis gehören dem DNS-Manager.
    // zone_cleanup.php entfernt hier .conf-Dateien, deren Zone dem Server laut Sollzustand
    // (Datenbank) nicht mehr zugewiesen ist. Keine fremden .conf-Dateien hier ablegen.
    'zone_conf_dir' => '/etc/bind/zones/conf',

    // Pfad zum Verzeichnis mit den Zonendateien (db.<zone>)
    // Cleanup entfernt hier NUR db.<zone> als exakt gepaartes Artefakt einer verwaisten
    // <zone>.conf aus zone_conf_dir. Sonstige db.*, .jnl und fremde Dateien werden nicht
    // pauschal gelöscht.
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
    // AS2a (nur observe/enforce): dig für die Abfrage des lokalen BIND an 127.0.0.1
    'bind_dig'             => '/usr/bin/dig',

    /** ---------------------------------------
     *  Zustandsprüfung vor der Übernahme (AS2a, docs/ARCHITECTURE_HISTORY.md Abschnitt 5f; nur observe/enforce)
     * --------------------------------------- */
    // Kontrollzone (Betriebsvoraussetzung): autoritative Zone dieses BIND ohne eigene allow-query-Anweisung, nicht vom
    // DNS-Manager verwaltet (Standard 'localhost', z. B. aus named.conf.default-zones). Ohne sie gilt eine REFUSED-Antwort
    // für eine nicht konfigurierte Zone ohne Elternzone als nicht nachweisbar (served_state_unknown).
    // 'l3_control_zone' => 'localhost',

    /** ---------------------------------------
     *  Fencing (Meilenstein AS, docs/ARCHITECTURE_HISTORY.md Abschnitt 5e)
     * --------------------------------------- */
    // Modus (steuert nur die Fencing-Prüfung, nie die Integritätssicherung) – PFLICHTANGABE (E118):
    // - 'off': keine Fencing-Prüfung, der Höchststand wird nicht verwendet (bisheriges Verhalten);
    // - 'observe': prüfen und Höchststand fortschreiben, Fencing-Gründe (Token, Domäne, Höchststand) nicht ablehnen,
    //   aber Integritätsfehler (Höchststand ungültig oder nicht dauerhaft speicherbar) ablehnen;
    // - 'enforce': fail-closed.
    // Fehlt der Schlüssel oder ist der Wert unbekannt, lehnen zone_sync, conf_sync, zone_cleanup und system/status jeden
    // Aufruf mit fence_misconfigured (503) ab – es gibt keinen stillen Rückfall auf 'off'. In JEDEM Modus sperren eine
    // Fehlermarke (.dnsmanager-fence.fault) und eine fehlende PHP-Erweiterung posix alle Schreibvorgänge
    // (docs/AGENT_FENCING.md).
    'fencing_mode' => 'off',

    // Fencing-Domäne der Installation (UUID in Kleinbuchstaben). Muss der Domäne der Datenbank dieser Installation
    // entsprechen; der Agent nimmt nur Anfragen und Höchststände dieser Domäne an. Für observe/enforce Pflicht.
    // 'fence_domain' => '00000000-0000-4000-8000-000000000000',
];
