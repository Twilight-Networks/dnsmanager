<?php
/**
 * Datei: inc/bind_utils.php
 * Zweck: Hilfsfunktionen für BIND-Operationen (Zone-Prüfung, Reload etc.)
 *
 * Diese Datei stellt Funktionen zur Verfügung, um
 * - Zonennamen sicher zu bereinigen
 * - Zonendateien mittels named-checkzone zu validieren
 * - einen BIND-Reload über rndc auszuführen
 *
 * Eingesetzt in:
 * - zone_check.php
 * - zone_sync.php
 * - conf_sync.php
 */

require_once __DIR__ . '/../config/api_config.php';

// === Konfiguration laden ===
$config = require __DIR__ . '/../config/api_config.php';

/**
 * Wandelt einen Zonenbezeichner in einen sicheren Dateinamen um.
 * Erlaubt sind: Buchstaben, Zahlen, Punkt, Unterstrich, Bindestrich.
 * Unerlaubte Zeichen werden durch Unterstriche ersetzt.
 *
 * @param string $name Ursprünglicher Zonenname (z. B. "example.com")
 *
 * @return string Bereinigter Name (z. B. "example_com")
 */
function safeZoneName(string $name): string {
    return preg_replace('/[^a-zA-Z0-9._\-]/', '_', $name);
}

/**
 * Führt eine syntaktische Prüfung einer Zonendatei durch.
 * Gibt immer die vollständige Ausgabe von named-checkzone zurück.
 *
 * @param string $zoneName
 * @param string $filePath
 * @return string Konsolenausgabe (auch bei Erfolg)
 */
function validateZoneFile(string $zoneName, string $filePath): string {
    global $config;
    $binary = $config['bind_named_checkzone'] ?? '/usr/sbin/named-checkzone';
    $cmd = $binary . ' ' . escapeshellarg($zoneName) . ' ' . escapeshellarg($filePath) . ' 2>&1';
    return shell_exec($cmd) ?? '';
}

/**
 * Führt eine syntaktische Prüfung der BIND-Konfiguration durch.
 * Gibt immer die vollständige Ausgabe von named-checkconf zurück.
 *
 * @return string Konsolenausgabe (auch bei Erfolg)
 */
function validateZoneConfFile(): string {
    global $config;
    $binary = $config['bind_named_checkconf'] ?? '/usr/bin/named-checkconf';
    $conf   = $config['bind_named_conf_path'] ?? '/etc/bind/named.conf';

    $cmd = $binary . ' ' . escapeshellarg($conf) . ' 2>&1';
    return shell_exec($cmd) ?? '';
}

/**
 * Führt einen Reload des BIND-Servers über `rndc reload` durch.
 * Ausgabe von stdout/stderr wird zurückgegeben.
 *
 * @return string Konsolenausgabe des Reload-Vorgangs
 */
function reloadBind(): string {
    global $config;
    $binary = $config['bind_rndc'] ?? '/usr/sbin/rndc';
    $cmd = 'sudo ' . escapeshellcmd($binary) . ' reload 2>&1';
    return shell_exec($cmd) ?? '';
}
