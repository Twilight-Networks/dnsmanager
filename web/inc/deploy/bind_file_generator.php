<?php
/**
 * Datei: bind_file_generator.php
 *
 * Zweck:
 * - Erzeugt temporäre BIND-Zonendateien (`db.<zone>`) und zonenspezifische Konfigurationsdateien (`<zone>.conf`) zur API-basierten Verteilung.
 * - Unterstützt gezielte Generierung pro Zone und Server für den API-Einsatz (z. B. über zone_sync.php / conf_sync.php).
 *
 * Details:
 * - `generate_zone_file()` erzeugt eine gültige Zonendatei inklusive Glue-Records und prüft sie mit named-checkzone.
 * - `generate_zone_conf_file()` erstellt eine oder mehrere `.conf`-Dateien, abhängig von Zone und Serverrolle.
 * - Es werden ausschließlich temporäre Verzeichnisse beschrieben (z. B. /tmp/...).
 * - Eine zentrale `zones.conf` oder lokaler Cleanup wird nicht mehr durchgeführt.
 * - Die SOA-Serialnummer wird nur im Modus `publish` erhöht.
 *
 * Sicherheit:
 * - Keine Verarbeitung externer Benutzereingaben.
 * - Alle Dateipfade sind kontrolliert und auf übergebene Zielverzeichnisse beschränkt.
 * - Zugriff auf die Datenbank erfolgt ausschließlich über vorbereitete Statements.
 *
 * Verwendete Funktionen:
 * - generate_zone_file(): Erzeugt und validiert eine einzelne Zonendatei.
 * - generate_zone_conf_file(): Erstellt gezielt `.conf`-Dateien für eine Zone und einen Zielserver.
 */

require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../soa_serial.php';

/**
 * Erzeugt eine Zonendatei (db.<zone>) im angegebenen Zielverzeichnis und validiert sie mit named-checkzone.
 *
 * Verhalten:
 * - Im Modus 'validate' wird die Datei nur temporär erzeugt und nach der Prüfung gelöscht.
 * - Im Modus 'publish' bleibt die Datei bestehen und wird zur weiteren Verarbeitung (z. B. Verteilung per API) verwendet.
 * - Die SOA-Serialnummer wird nur im Modus 'publish' erhöht und in der Datenbank gespeichert.
 *
 * Sicherheit:
 * - Es erfolgt keine direkte Verarbeitung von Benutzereingaben.
 * - Pfade werden auf sichere Zielverzeichnisse begrenzt.
 *
 * @param int $zone_id ID der zu verarbeitenden Zone
 * @param string $output_dir Zielverzeichnis für die erzeugte Datei (z. B. /tmp)
 * @param string $mode 'validate' (nur prüfen) oder 'publish' (für Verteilung)
 * @return array Assoziatives Ergebnis mit 'status', 'path', 'output' und optional 'errors'
 */
function generate_zone_file(int $zone_id, string $output_dir = '/tmp', string $mode = 'validate'): array {
    global $pdo;

    // Zoneninformationen laden
    $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    $zone = $stmt->fetch();

    if (!$zone) {
        return [
            'status' => 'error',
            'output' => "Zonendatenbankeintrag für ID $zone_id nicht gefunden.",
            'errors' => ["Keine Zone mit ID $zone_id vorhanden."],
        ];
    }

    // Records der Zone laden
    $stmt = $pdo->prepare("SELECT * FROM records WHERE zone_id = ?");
    $stmt->execute([$zone_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $zone_name = $zone['name'];
    $safe_zone_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $zone_name);
    $zone_file_path = rtrim($output_dir, '/') . "/db." . $safe_zone_name;

    // Neue Serialnummer nur bei publish erzeugen
    $serial = $zone['soa_serial'];
    if ($mode === 'publish') {
        try {
            $pdo->beginTransaction();
            $serial = generate_next_serial($serial);
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'output' => "Fehler beim Starten der Transaktion: " . $e->getMessage(),
                'errors' => ["DB: " . $e->getMessage()]
            ];
        }
    }

    // Zonendatei-Inhalt aufbauen
    $content = <<<ZONE
\$TTL {$zone['ttl']}
@   IN  SOA {$zone['soa_ns']} {$zone['soa_mail']} (
            $serial   ; Serial
            {$zone['soa_refresh']}      ; Refresh
            {$zone['soa_retry']}        ; Retry
            {$zone['soa_expire']}       ; Expire
            {$zone['soa_minimum']} )    ; Minimum

ZONE;

    // Alle Records einfügen
    foreach ($records as $r) {
        $name = $r['name'] === '@' ? '@' : $r['name'];
        $ttl = isset($r['ttl']) ? (int)$r['ttl'] : (int)$zone['ttl'];
        $type = strtoupper($r['type']);
        $line = "$name\t$ttl\tIN\t$type\t" . $r['content'];
        $content .= $line . "\n";
    }

    // Datei schreiben
    file_put_contents($zone_file_path, $content);

    // named-checkzone aufrufen
    $check_cmd = "named-checkzone " . escapeshellarg($zone_name) . " " . escapeshellarg($zone_file_path) . " 2>&1";
    $check_output = shell_exec($check_cmd);

    // Bei validate wieder löschen
    if ($mode === 'validate') {
        @unlink($zone_file_path);
    }

    if (strpos($check_output, 'loaded serial') === false) {
        file_put_contents("/tmp/named-checkzone-error.log", $check_output);
        return [
            'status' => 'error',
            'output' => "named-checkzone fehlgeschlagen für $zone_name:\n" . trim($check_output),
            'errors' => ["named-checkzone: " . trim($check_output)],
        ];
    }

    // Serialnummer nur bei publish aktualisieren
    if ($mode === 'publish') {
        try {
            $stmt = $pdo->prepare("UPDATE zones SET soa_serial = ? WHERE id = ?");
            $stmt->execute([$serial, $zone_id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return [
                'status' => 'error',
                'output' => "Fehler beim Speichern der Serialnummer: " . $e->getMessage(),
                'errors' => ["DB-Update: " . $e->getMessage()]
            ];
        }
    }

    return [
        'status' => 'ok',
        'path' => $zone_file_path,
        'output' => trim($check_output)
    ];
}

/**
 * Erzeugt eine oder mehrere zonenspezifische BIND-Konfigurationsdateien (.conf) im angegebenen Zielverzeichnis.
 *
 * Zweck:
 * - Diese Funktion dient ausschließlich der API-basierten Konfigurationsverteilung (z. B. über conf_sync.php).
 * - Es wird keine zentrale `zones.conf` mehr erstellt.
 * - Es findet kein Aufräumen oder Schreiben in produktive BIND-Verzeichnisse statt.
 * - Die Funktion arbeitet ausschließlich auf Basis der Zonen-/Server-Zuordnung (`zone_servers`) und erzeugt
 *   pro Zone eine eigene `.conf`-Datei.
 *
 * Verhalten:
 * - Gibt bei Übergabe von `$only_zone` und `$only_server_id` genau eine `.conf`-Datei aus.
 * - Unterstützt Master-/Slave-Generierung anhand von Rollen in der Datenbank.
 * - Die Konfiguration wird immer im angegebenen `$zone_base_dir/conf/` gespeichert.
 *
 * @param string $zone_base_dir Zielverzeichnis für die Konfiguration (z. B. /tmp/xyz)
 * @param ?string $only_zone Optional: Nur eine bestimmte Zone verarbeiten
 * @param ?int $only_server_id Optional: Nur für einen bestimmten Server (nach IP-Zuordnung) erzeugen
 * @return array Assoziatives Ergebnis mit 'status', 'zones_written', 'conf_files', 'errors', 'output'
 */
function generate_zone_conf_file(string $zone_base_dir, ?string $only_zone = null, ?int $only_server_id = null): array
{
    global $pdo;

    $zones_dir = rtrim($zone_base_dir, '/');
    $conf_dir = $zones_dir . "/conf";

    if (!is_dir($conf_dir)) {
        mkdir($conf_dir, 0755, true);
    }

    $zones_written = [];
    $conf_files = [];
    $errors = [];
    $outputs = [];

    $map_result = getZoneServerMap($pdo, $only_zone, $only_server_id);

    if ($map_result['status'] === 'error') {
        return [
            'status' => 'error',
            'output' => $map_result['output'],
            'errors' => $map_result['errors'],
            'zones_written' => [],
            'conf_files' => [],
        ];
    }

    $zone_map = $map_result['zone_map'];
    $current_ips = [];

    if ($only_server_id !== null) {
        $stmt_info = $pdo->prepare("SELECT dns_ip4, dns_ip6 FROM servers WHERE id = ?");
        $stmt_info->execute([$only_server_id]);
        $server_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
        $current_ips = array_filter([
            trim($server_info['dns_ip4'] ?? ''),
            trim($server_info['dns_ip6'] ?? '')
        ]);
    }

    foreach ($zone_map as $zone_name => $roles) {
        if ($only_server_id !== null) {
            $is_master = !empty(array_intersect($current_ips, $roles['masters']));
            $is_slave  = !empty(array_intersect($current_ips, $roles['slaves']));
            if (!$is_master && !$is_slave) {
                continue;
            }
        }

        $bind_zone_path = rtrim(BIND_BASE_DIR, '/') . "/zones/db.$zone_name";
        $is_slave = ($only_server_id !== null && !empty(array_intersect($current_ips, $roles['slaves'])));

        $filtered_masters = $is_slave
            ? array_diff($roles['masters'], $current_ips)
            : $roles['masters'];

        $result = writeZoneConfFile(
            $conf_dir,
            $zone_name,
            $bind_zone_path,
            $filtered_masters,
            $is_slave,
            false // enable_slave_mode deaktiviert
        );

        $zones_written[] = $zone_name;
        $conf_files[$result['file']] = $result['status'];
        $outputs[] = $result['output'];

        if ($result['status'] === 'error') {
            $errors[] = "Zone $zone_name: " . $result['output'];
        } elseif ($result['status'] === 'warning') {
            $errors[] = "Zone $zone_name (Warnung): " . $result['output'];
        }
    }

    return [
        'status' => empty($errors) ? 'ok' : 'warning',
        'zones_written' => $zones_written,
        'conf_files' => $conf_files,
        'zones_conf_updated' => false,
        'deleted_files' => [],
        'errors' => $errors,
        'output' => implode("\n", $outputs)
    ];
}

/**
 * Gibt für alle aktiven Server pro Zone eine strukturierte Liste zurück:
 * [
 *   'example.com' => ['masters' => ['1.2.3.4'], 'slaves' => ['5.6.7.8']],
 *   ...
 * ]
 *
 * @param PDO $pdo
 * @param ?string $only_zone
 * @param ?int $only_server_id
 * @return array
 */
function getZoneServerMap(PDO $pdo, ?string $only_zone = null, ?int $only_server_id = null): array
{
    $sql = "
        SELECT z.name AS zone_name, s.name AS server_name, zs.is_master, s.dns_ip4, s.dns_ip6
        FROM zones z
        JOIN zone_servers zs ON z.id = zs.zone_id
        JOIN servers s ON s.id = zs.server_id
        WHERE s.active = 1
    ";

    if ($only_zone !== null) {
        $sql .= " AND z.name = :zone_name";
    }

    if ($only_server_id !== null) {
        $sql .= " AND s.id = :only_server_id";
    }

    $sql .= " ORDER BY z.name";

    $stmt = $pdo->prepare($sql);

    if ($only_zone !== null) {
        $stmt->bindValue(':zone_name', $only_zone);
    }
    if ($only_server_id !== null) {
        $stmt->bindValue(':only_server_id', $only_server_id, PDO::PARAM_INT);
    }

    try {
        $stmt->execute();
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'zone_map' => [],
            'errors' => ['SQL' => $e->getMessage()],
            'output' => 'Fehler bei Datenbankabfrage'
        ];
    }

    $zone_map = [];
    $errors = [];
    $skipped_servers = 0;
    $total_zones = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $zone = $row['zone_name'];
        $server = $row['server_name'];

        if (!isset($zone_map[$zone])) {
            $zone_map[$zone] = ['masters' => [], 'slaves' => []];
            $total_zones++;
        }

        $ip_v4 = trim($row['dns_ip4'] ?? '');
        $ip_v6 = trim($row['dns_ip6'] ?? '');
        $targets = array_filter([$ip_v4, $ip_v6]);

        if (empty($targets)) {
            $errors[$zone][] = "Server ohne IP: $server";
            $skipped_servers++;
            continue;
        }

        foreach ($targets as $ip) {
            if ($row['is_master']) {
                $zone_map[$zone]['masters'][] = $ip;
            } else {
                $zone_map[$zone]['slaves'][] = $ip;
            }
        }
    }

    $status = empty($errors) ? 'ok' : 'warning';
    $output = "$total_zones Zonen verarbeitet";
    if ($skipped_servers > 0) {
        $output .= ", $skipped_servers Server übersprungen";
    }

    return [
        'status' => $status,
        'zone_map' => $zone_map,
        'errors' => $errors,
        'output' => $output
    ];
}

/**
 * Schreibt die Konfigurationsdatei einer einzelnen Zone
 * nach `$conf_dir/$zone_name.conf`
 *
 * @param string $conf_dir Zielverzeichnis (z. B. /etc/bind/zones/conf)
 * @param string $zone_name Zonenname (z. B. example.com)
 * @param string $bind_zone_path Dateipfad im BIND-Kontext (z. B. /etc/bind/zones/db.example.com)
 * @param array $masters Liste der Master-IP-Adressen
 * @param bool $is_slave true = Server ist Slave (AXFR-Modus), false = Master
 * @param bool $enable_slave_mode Globale Einstellung, ob Slave-Konfiguration geschrieben werden soll
 * @return void
 */
function writeZoneConfFile(
    string $conf_dir,
    string $zone_name,
    string $bind_zone_path,
    array $masters = [],
    bool $is_slave = false,
    bool $enable_slave_mode = false
): array {
    $conf_file_path = "$conf_dir/$zone_name.conf";
    $type = 'master';
    $masters_block = '';
    $status = 'written';
    $output = "Konfigurationsdatei für Zone $zone_name geschrieben.";

    if ($enable_slave_mode && $is_slave) {
        $type = 'slave';

        if (!empty($masters)) {
            $masters_block = "masters { " . implode('; ', $masters) . "; };";
        } else {
            error_log("[conf-generate] Warnung: Kein Master für Zone $zone_name gefunden.");
            $output = "Warnung: Kein Master definiert für Zone $zone_name.";
        }
    }

    $conf_content = <<<CONF
zone "$zone_name" {
    type $type;
    file "$bind_zone_path";
    $masters_block
};
CONF;

    try {
        file_put_contents($conf_file_path, $conf_content);
    } catch (Throwable $e) {
        return [
            'zone' => $zone_name,
            'file' => basename($conf_file_path),
            'status' => 'error',
            'output' => "Fehler beim Schreiben: " . $e->getMessage()
        ];
    }

    return [
        'zone' => $zone_name,
        'file' => basename($conf_file_path),
        'status' => $status,
        'output' => $output
    ];
}
