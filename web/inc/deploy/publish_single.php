<?php
/**
 * Datei: publish_single.php
 * Zweck: Einzelne Zone veröffentlichen (DynDNS-Update-API)
 *
 * Hinweis: Nur verwenden, wenn vorher die Zonenprüfung (ZoneBuildBackend::validateAndFlag) erfolgreich war!
 *
 * Paket 4a: kein Web-Bootstrap und keine globale Verbindung mehr; Verbindung und Agent-Client werden explizit
 * übergeben, Zonen- und Konfigurationsdateien entstehen in einem eigenen Arbeitsverzeichnis, das auf jedem
 * kontrollierten Weg entfernt wird. Ablauf und Ergebnis (Generator im Modus 'dyndns' innerhalb der offenen
 * Transaktion des Aufrufers, Verteilung, changed = 0 nur bei vollständigem Erfolg) sind unverändert; die
 * Neuordnung von Transaktion, Lock und Publish folgt mit Paket 4b (S32).
 */

require_once __DIR__ . '/bind_file_generator.php';
require_once __DIR__ . '/bind_file_distributor.php';
require_once __DIR__ . '/BindAgentClient.php';
require_once __DIR__ . '/BindWorkDir.php';

/**
 * Veröffentlicht eine einzelne Zone (Zonendatei + Conf + Distribution)
 *
 * @param PDO $pdo
 * @param BindAgentClient $agent
 * @param int $zone_id
 * @param string $zone_name
 * @return array ['status' => 'ok'|'warning'|'error', 'output' => string]
 */
function publish_single_zone(PDO $pdo, BindAgentClient $agent, int $zone_id, string $zone_name): array
{
    $work = null;
    try {
        $work = BindWorkDir::create();
        $validation = generate_zone_file($zone_id, $work->path(), 'dyndns', $pdo);

        if ($validation['status'] === 'error') {
            return [
                'status' => 'error',
                'output' => "Zone {$zone_name}: " . $validation['output']
            ];
        }

        $output = [];

        if ($validation['status'] === 'warning') {
            $output[] = "Zone {$zone_name}: " . $validation['output'];
        }

        $path = $validation['path'] ?? null;

        $dist_result = distribute_zone_file($pdo, $agent, $zone_id, $path);
        if ($dist_result['status'] === 'error') {
            return [
                'status' => 'error',
                'output' => "Zone {$zone_name}: " . implode('; ', $dist_result['errors'])
            ];
        }

        if ($dist_result['status'] === 'warning') {
            $output[] = "WARNUNG: " . implode('; ', $dist_result['errors']);
        }

        $conf_result = distribute_zone_conf_file($pdo, $agent, $work, $zone_name);
        if (!empty($conf_result['errors'])) {
            $output[] = "WARNUNG: Conf-Verteilung fehlgeschlagen: " . implode('; ', $conf_result['errors']);
        }

        // changed nur zurücksetzen, wenn Zone- und Conf-Verteilung vollständig erfolgreich waren;
        // bei Teilfehler bleibt changed = 1 (vom vorherigen Rebuild gesetzt) für einen späteren Publish
        $conf_ok = ($conf_result['status'] ?? '') === 'ok' && empty($conf_result['errors']);
        if ($dist_result['status'] === 'ok' && $conf_ok) {
            $pdo->prepare("UPDATE zones SET changed = 0 WHERE id = ?")->execute([$zone_id]);
        }

        return [
            'status' => empty($output) ? 'ok' : 'warning',
            'output' => implode("\n", $output)
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'output' => "Zone {$zone_name}: Systemfehler beim Publish: " . $e->getMessage()
        ];
    } finally {
        $work?->remove();
    }
}
