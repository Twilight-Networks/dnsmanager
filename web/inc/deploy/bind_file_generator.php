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
 * Abhängigkeiten (Paket 4a):
 * - Kein Web-Bootstrap, keine globale Datenbankverbindung: Alle Funktionen erhalten die PDO-Verbindung
 *   explizit. `generate_zone_conf_file()` liest zusätzlich die Konfigurationskonstante BIND_BASE_DIR.
 * - Zielverzeichnisse übergibt der Aufrufer (eigenes Arbeitsverzeichnis, BindWorkDir; S34).
 *
 * Sicherheit:
 * - Keine Verarbeitung externer Benutzereingaben.
 * - Alle Dateipfade sind kontrolliert und auf übergebene Zielverzeichnisse beschränkt.
 * - Zonennamen werden vor jeder Verwendung in Dateinamen oder BIND-Konfiguration geprüft
 *   (zone_name_is_safe, S33).
 * - Zugriff auf die Datenbank erfolgt ausschließlich über vorbereitete Statements.
 *
 * Verwendete Funktionen:
 * - generate_zone_file(): Erzeugt und validiert eine einzelne Zonendatei.
 * - generate_zone_conf_file(): Erstellt gezielt `.conf`-Dateien für eine Zone und einen Zielserver.
 */

require_once __DIR__ . '/../soa_serial.php';

/**
 * Zeile eines Records in der Zonendatei (U4-UI, E3): "<Name>\t<TTL>\tIN\t<TYP>\t<Inhalt>".
 *
 * Reine Funktion ohne Datei- oder Datenbankzugriff. Wird von generate_zone_file() und der Record-Vorschau
 * (inc/web/record_editor.php) gemeinsam verwendet, damit die Vorschau bytegenau der erzeugten Zeile entspricht.
 * Format unverändert aus generate_zone_file() übernommen: Tabulatoren, Typ in Großbuchstaben, TTL des Records
 * oder – falls nicht gesetzt – die TTL der Zone, Inhalt unverändert.
 *
 * @param array $r       Record mit name, type, content und optional ttl
 * @param mixed $zoneTtl TTL der Zone (Rückfall, wenn der Record keine TTL hat)
 * @return string Zeile ohne Zeilenumbruch
 */
function zone_file_record_line(array $r, $zoneTtl): string
{
    $name = $r['name'] === '@' ? '@' : $r['name'];
    $ttl = isset($r['ttl']) ? (int)$r['ttl'] : (int)$zoneTtl;
    $type = strtoupper($r['type']);
    return "$name\t$ttl\tIN\t$type\t" . $r['content'];
}

/**
 * Defensive Prüfung eines SOA-Namensfeldes (soa_ns, soa_mail) unmittelbar vor dem Schreiben der
 * Zonendatei (Sicherheitsbefund S17).
 *
 * Der Wert wird ungefiltert in die SOA-Zeile eingesetzt. Er muss daher genau ein Namens-Token sein:
 * Buchstaben, Ziffern, '.', '-', '_' und – nur für soa_mail – die maskierte Punkt-Sequenz '\.'
 * (Postfachname mit Punkt). Leerraum, Steuerzeichen (z. B. Zeilenumbrüche) und Zonendatei-Syntax
 * (';', '(', ')', '"', '$', '@' …) sind ausgeschlossen. Bewusst großzügiger als die Eingabeprüfung,
 * damit vorhandene gültige Werte weiterhin erzeugt werden; die fachliche Gültigkeit prüft anschließend
 * named-checkzone.
 *
 * @param string $value       Gespeicherter Feldwert
 * @param bool   $allowEscape true für soa_mail ('\.' zulässig)
 * @return bool true, wenn der Wert gefahrlos in die SOA-Zeile geschrieben werden kann
 */
function zone_file_soa_name_is_safe(string $value, bool $allowEscape = false): bool
{
    if ($value === '' || strlen($value) > 255) {
        return false;
    }
    $pattern = $allowEscape ? '/^(?:[A-Za-z0-9._\-]|\\\\\.)+\z/' : '/^[A-Za-z0-9._\-]+\z/';

    return preg_match($pattern, $value) === 1;
}

/**
 * Defensive Prüfung eines Record-Feldes (name, type, content) unmittelbar vor dem Schreiben der
 * Zonendatei.
 *
 * Jeder Record wird in genau eine Zeile `name TTL IN type content` geschrieben. Ein Steuerzeichen
 * (insbesondere Zeilenumbruch oder Wagenrücklauf) könnte zusätzliche Zonendatei-Zeilen oder
 * Direktiven erzeugen. Die fachliche Validierung verhindert dies bereits beim Anlegen/Ändern; diese
 * Prüfung ist der letzte Schutz auch für bereits gespeicherte Daten und für Pfade ohne
 * validateDnsRecord() (z. B. DynDNS). Byteweise geprüft (ohne /u).
 *
 * @param string $value Feldwert aus der Datenbank
 * @return bool true, wenn der Wert ein Steuerzeichen enthält (= unsicher)
 */
function zone_file_value_has_control_chars(string $value): bool
{
    return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
}

/**
 * Defensive Prüfung eines Zonennamens vor der Verwendung in Dateinamen und in der BIND-Konfiguration
 * (Sicherheitsbefund S33).
 *
 * Zulässig ist genau der Zeichenvorrat der Zonen-Eingabeprüfung (validateZoneInput): Buchstaben, Ziffern,
 * '.', '-', '_' – höchstens 253 Zeichen, nicht mit '.' beginnend. Ausgeschlossen sind damit insbesondere
 * '/', Anführungszeichen, Leerraum, Steuerzeichen und Verweise wie '.' oder '..'.
 */
function zone_name_is_safe(string $name): bool
{
    return strlen($name) <= 253 && preg_match('/^[A-Za-z0-9_\-][A-Za-z0-9._\-]*\z/', $name) === 1;
}

/**
 * Erzeugt eine Zonendatei (db.<zone>) im angegebenen Zielverzeichnis und validiert sie mit named-checkzone.
 *
 * Verhalten:
 * - Im Modus 'validate' wird die Datei nur temporär erzeugt und nach der Prüfung gelöscht.
 * - Im Modus 'publish' bleibt die Datei bestehen und wird zur weiteren Verarbeitung (z. B. Verteilung per API) verwendet.
 * - Die SOA-Serialnummer wird im Modus 'publish' erhöht und in der Datenbank gespeichert.
 * - Den früheren Modus 'dyndns' (Serial in der Transaktion des Aufrufers) gibt es seit Paket 4b nicht mehr;
 *   DynDNS veröffentlicht über PublishService::publishZone() im Modus 'publish'.
 *
 * Sicherheit:
 * - Es erfolgt keine direkte Verarbeitung von Benutzereingaben.
 * - soa_ns und soa_mail werden vor dem Schreiben geprüft (zone_file_soa_name_is_safe, S17); unsichere
 *   Werte führen zu status 'error' ohne Zonendatei.
 * - Record-Felder (name, type, content) werden vor dem Schreiben auf Steuerzeichen geprüft
 *   (zone_file_value_has_control_chars); unsichere Werte führen zu status 'error' ohne Zonendatei.
 * - Pfade werden auf sichere Zielverzeichnisse begrenzt; der Zonenname wird geprüft (zone_name_is_safe, S33).
 * - Bei fehlgeschlagener Prüfung wird die Datei in jedem Modus gelöscht; die Ausgabe von named-checkzone
 *   steht im Ergebnis (keine feste Fehlerprotokoll-Datei in /tmp mehr, S34).
 *
 * @param int $zone_id ID der zu verarbeitenden Zone
 * @param string $output_dir Zielverzeichnis für die erzeugte Datei (eigenes Arbeitsverzeichnis, BindWorkDir)
 * @param string $mode 'validate' (nur prüfen) oder 'publish' (für Verteilung)
 * @param PDO $pdo Datenbankverbindung des Aufrufers (explizit, keine globale Verbindung)
 * @return array Assoziatives Ergebnis mit 'status', 'path', 'output' und optional 'errors'
 */
function generate_zone_file(int $zone_id, string $output_dir, string $mode, PDO $pdo): array {
    if ($mode !== 'validate' && $mode !== 'publish') {
        throw new InvalidArgumentException("generate_zone_file: unbekannter Modus '$mode'.");
    }


    $tx_started = false; // true nur, wenn diese Funktion die Transaktion selbst gestartet hat

    // publish: Transaktion starten und Zone sperren, BEVOR Zone, Serial und Records gelesen werden.
    // Der Lock muss die erste Abfrage der Transaktion sein, damit der Read-Snapshot erst nach dem
    // Warten auf parallele Schreiber (z. B. DynDNS) entsteht.
    if ($mode === 'publish') {
        try {
            $pdo->beginTransaction();
            $tx_started = true;
            $stmt_lock = $pdo->prepare("SELECT id FROM zones WHERE id = ? FOR UPDATE");
            $stmt_lock->execute([$zone_id]);
        } catch (Throwable $e) {
            if ($tx_started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'status' => 'error',
                'output' => "Fehler beim Initialisieren des Publish-Locks: " . $e->getMessage(),
                'errors' => ["DB: " . $e->getMessage()]
            ];
        }
    }

    try {
        // Zoneninformationen laden
        $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
        $stmt->execute([$zone_id]);
        $zone = $stmt->fetch();

        if (!$zone) {
            if ($tx_started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

        // S33: Zonenname wird für Dateinamen und named-checkzone verwendet
        if (!zone_name_is_safe((string)$zone_name)) {
            if ($tx_started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'status' => 'error',
                'output' => "Ungültiger Zonenname (Zone-ID $zone_id): unzulässige Zeichen – Zonendatei wird nicht erzeugt.",
                'errors' => ['Zonenname: unzulässige Zeichen'],
            ];
        }

        // S17: SOA-Felder werden unverändert in die Zonendatei geschrieben. Unsichere Werte (auch
        // bereits gespeicherte) führen in jedem Modus zum Abbruch, bevor eine Datei entsteht.
        // Der Feldinhalt wird bewusst nicht ausgegeben (Toast/Log).
        $unsafe_soa_fields = [];
        if (!zone_file_soa_name_is_safe((string)$zone['soa_ns'])) {
            $unsafe_soa_fields[] = 'soa_ns';
        }
        if (!zone_file_soa_name_is_safe((string)$zone['soa_mail'], true)) {
            $unsafe_soa_fields[] = 'soa_mail';
        }
        if ($unsafe_soa_fields !== []) {
            if ($tx_started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $fields = implode(', ', $unsafe_soa_fields);
            return [
                'status' => 'error',
                'output' => "Ungültige SOA-Angaben in Zone $zone_name ($fields): unzulässige Zeichen – Zonendatei wird nicht erzeugt.",
                'errors' => ["SOA: unzulässige Zeichen in $fields"],
            ];
        }

        // Record-Felder (name, type, content) werden unverändert in je eine Zeile geschrieben.
        // Unsichere, bereits gespeicherte Werte führen in jedem Modus zum Abbruch, bevor eine Datei
        // entsteht. Der Feldinhalt wird bewusst nicht ausgegeben (Toast/Log).
        $unsafe_records = 0;
        foreach ($records as $r) {
            if (zone_file_value_has_control_chars((string)($r['name'] ?? ''))
                || zone_file_value_has_control_chars((string)($r['type'] ?? ''))
                || zone_file_value_has_control_chars((string)($r['content'] ?? ''))
            ) {
                $unsafe_records++;
            }
        }
        if ($unsafe_records > 0) {
            if ($tx_started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'status' => 'error',
                'output' => "Ungültige Record-Daten in Zone $zone_name ($unsafe_records betroffen): unzulässige Zeichen – Zonendatei wird nicht erzeugt.",
                'errors' => ["Records: unzulässige Zeichen in $unsafe_records Datensätzen"],
            ];
        }

        $safe_zone_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $zone_name);
        $zone_file_path = rtrim($output_dir, '/') . "/db." . $safe_zone_name . "." . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        // Eigene Transaktion zurückrollen; Exception unverändert weiterreichen (bisheriges Verhalten)
        if ($tx_started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Neue Serialnummer nur bei publish (eigene Transaktion unter Zonensperre) erzeugen
    $serial = $zone['soa_serial'];
    if ($mode === 'publish') {
        // Transaktion und Zonen-Lock bestehen bereits; $serial stammt aus dem unter Lock gelesenen Stand.
        try {
            $serial = generate_next_serial($serial);
        } catch (Throwable $e) {
            if ($tx_started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'status' => 'error',
                'output' => "Fehler beim Ermitteln der Serialnummer: " . $e->getMessage(),
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
        $content .= zone_file_record_line($r, $zone['ttl']) . "\n";
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

    if (strpos((string)$check_output, 'loaded serial') === false) {
        if ($tx_started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Ungültige Datei nicht liegen lassen
        if (is_file($zone_file_path)) {
            unlink($zone_file_path);
        }
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
            if ($tx_started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_file($zone_file_path)) {
                unlink($zone_file_path);
            }
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
 * @param PDO $pdo Datenbankverbindung (explizit)
 * @param string $zone_base_dir Zielverzeichnis für die Konfiguration (eigenes Arbeitsverzeichnis, BindWorkDir)
 * @param ?string $only_zone Optional: Nur eine bestimmte Zone verarbeiten
 * @param ?int $only_server_id Optional: Nur für einen bestimmten Server (nach IP-Zuordnung) erzeugen
 * @return array Assoziatives Ergebnis mit 'status', 'zones_written', 'conf_files', 'errors', 'output'
 */
function generate_zone_conf_file(PDO $pdo, string $zone_base_dir, ?string $only_zone = null, ?int $only_server_id = null): array
{
    $zones_dir = rtrim($zone_base_dir, '/');
    $conf_dir = $zones_dir . "/conf";

    if (!is_dir($conf_dir)) {
        mkdir($conf_dir, 0700, true);
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
    // S33: Zonenname geht in Dateinamen und in zone "…" der BIND-Konfiguration
    if (!zone_name_is_safe($zone_name)) {
        return [
            'zone' => $zone_name,
            'file' => '',
            'status' => 'error',
            'output' => 'Ungültiger Zonenname: unzulässige Zeichen – Konfigurationsdatei wird nicht erzeugt.'
        ];
    }

    $conf_file_path = "$conf_dir/$zone_name.conf";
    $type = 'master';
    $masters_block = '';
    $status = 'written';
    $output = '';

    if ($enable_slave_mode && $is_slave) {
        $type = 'slave';

        if (!empty($masters)) {
            $masters_block = "masters { " . implode('; ', $masters) . "; };";
        } else {
            $status = 'warning';
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
