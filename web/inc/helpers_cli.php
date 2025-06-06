<?php
/**
 * Datei: helpers_cli.php
 * Zweck: Gemeinsame Hilfsfunktionen für CLI-Skripte (z. B. Monitoring, Cleanup)
 *
 * Diese Datei ist ausschließlich für die Kommandozeilen-basierten Werkzeuge gedacht
 * und enthält keinerlei Abhängigkeiten zu Sessions, CSRF-Mechanismen oder HTML-Ausgabe.
 *
 * Verwendung:
 * - Einbinden über require_once __DIR__ . '/../inc/helpers.cli.php';
 * - Vor jedem CLI-Skript ist cliGuard() aufzurufen, um Webzugriffe zu unterbinden.
 */

/**
 * Verhindert den Aufruf dieses Skripts über HTTP oder andere Nicht-CLI-Kontexte.
 *
 * Wenn das Skript nicht über die Kommandozeile (`php`) ausgeführt wurde, wird es sofort
 * mit HTTP-Code 403 beendet – ohne jegliche Ausgabe.
 *
 * Beispielnutzung in einem CLI-Skript:
 *     require_once __DIR__ . '/../inc/helpers.cli.php';
 *     cliGuard();
 *
 * @return void
 */
function cliGuard(): void {
    if (php_sapi_name() !== 'cli') {
        http_response_code(403);
        echo "Zugriff verweigert.";
        exit;
    }
}
