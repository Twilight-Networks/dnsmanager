<?php
/**
 * Datei: inc/bind_utils.php
 * Zweck: Hilfsfunktionen für BIND-Operationen (Zone-Prüfung, Reload etc.)
 *
 * Diese Datei stellt Funktionen zur Verfügung, um
 * - Zonennamen sicher zu bereinigen
 * - Zonendateien mittels named-checkzone zu validieren
 * - die BIND-Konfiguration mittels named-checkconf (mit Exit-Code) zu prüfen
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
 * Prüft die BIND-Konfiguration mit named-checkconf (ohne sudo, ohne -z) und wertet den Exit-Code aus.
 *
 * - Exit-Code 0 = gültig (auch wenn Warnungen ausgegeben werden)
 * - Jeder andere Exit-Code = ungültig (fail closed), ebenso fehlendes Binary oder Ausführungsfehler
 * - stderr wird nach stdout umgeleitet und als 'output' zurückgegeben
 *
 * Verbindlicher Preflight vor jedem reloadBind().
 *
 * @return array{ok: bool, exit_code: int, output: string}
 */
function checkBindConfig(): array {
    global $config;
    $binary = $config['bind_named_checkconf'] ?? '/usr/bin/named-checkconf';
    $conf   = $config['bind_named_conf_path'] ?? '/etc/bind/named.conf';

    $cmd = escapeshellarg($binary) . ' ' . escapeshellarg($conf) . ' 2>&1';
    $lines = [];
    $exitCode = -1;

    try {
        $result = exec($cmd, $lines, $exitCode);
    } catch (Throwable $e) {
        // z. B. exec() deaktiviert → fail closed
        return ['ok' => false, 'exit_code' => -1, 'output' => 'named-checkconf konnte nicht ausgeführt werden: ' . $e->getMessage()];
    }

    $exitCode = (int)$exitCode;
    $output = implode("\n", $lines);

    if ($result === false) {
        // Ausführung fehlgeschlagen → fail closed
        return [
            'ok' => false,
            'exit_code' => $exitCode !== 0 ? $exitCode : -1,
            'output' => $output !== '' ? $output : 'named-checkconf konnte nicht ausgeführt werden.'
        ];
    }

    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => $output
    ];
}

/**
 * Führt eine syntaktische Prüfung der BIND-Konfiguration durch.
 * Gibt die vollständige Ausgabe von named-checkconf zurück (Kompatibilität; intern checkBindConfig()).
 *
 * @return string Konsolenausgabe (auch bei Erfolg)
 */
function validateZoneConfFile(): string {
    return checkBindConfig()['output'];
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
