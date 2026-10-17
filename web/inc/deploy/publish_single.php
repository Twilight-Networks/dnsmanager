<?php
/**
 * Datei: publish_single.php
 * Zweck: Einzelne Zone veröffentlichen (DynDNS, CLI etc.)
 *
 * Hinweis: Nur verwenden, wenn vorher rebuild_zone_and_flag_if_valid() erfolgreich war!
 */

// Web-Bootstrap explizit laden (Konfiguration, globale DB-Verbindung): Der Generator tut das nicht mehr selbst.
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/bind_file_generator.php';
require_once __DIR__ . '/bind_file_distributor.php';

/**
 * Veröffentlicht eine einzelne Zone (Zonendatei + Conf + Distribution)
 *
 * @param PDO $pdo
 * @param int $zone_id
 * @param string $zone_name
 * @return array ['status' => 'ok'|'warning'|'error', 'output' => string]
 */
function publish_single_zone(PDO $pdo, int $zone_id, string $zone_name): array
{
    try {
        $validation = generate_zone_file($zone_id, '/tmp', 'dyndns');

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

        $dist_result = distribute_zone_file($zone_id, $path);
        if ($dist_result['status'] === 'error') {
            return [
                'status' => 'error',
                'output' => "Zone {$zone_name}: " . implode('; ', $dist_result['errors'])
            ];
        }

        if ($dist_result['status'] === 'warning') {
            $output[] = "WARNUNG: " . implode('; ', $dist_result['errors']);
        }

        $conf_result = distribute_zone_conf_file($zone_name);
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
    }
}
