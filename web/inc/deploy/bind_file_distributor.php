<?php
/**
 * Datei: bind_file_distributor.php
 *
 * Zweck:
 * - Verteilt BIND-Zonendateien (`db.<zone>`) und Konfigurationsdateien (`<zone>.conf`) an alle zugewiesenen Nameserver über eine API.
 * - Alle Server – einschließlich des lokalen Webinterface-Hosts – werden über ihre API-Adresse angesprochen (z. B. 127.0.0.1).
 *
 * Architektur:
 * - Zonendateien und Konfigurationsdateien werden temporär erzeugt und base64-kodiert per HTTPS-POST an die jeweiligen Server übertragen.
 * - Die API-Endpunkte der Zielserver (zone_sync.php, conf_sync.php) nehmen die Dateien entgegen und verarbeiten sie lokal.
 * - Normale Sync-Requests übertragen ausschließlich die konkrete Zone. Verwaiste Zonen werden einmal pro
 *   Publish-Lauf über distribute_zone_cleanup() / zone_cleanup.php auf allen aktiven Servern entfernt.
 *
 * Abhängigkeiten (Paket 4a):
 * - Datenbankverbindung, Agent-Client (BindAgentClient) und Arbeitsverzeichnis (BindWorkDir) werden explizit
 *   übergeben; keine globale Verbindung, kein Web-Bootstrap.
 * - distribute_zone_file() verteilt ausschließlich eine bereits erzeugte Zonendatei (kein versteckter
 *   Generierungs-/Publish-Pfad mehr). Die Datei gehört dem Aufrufer (Arbeitsverzeichnis), der sie entfernt.
 * - Konfigurationsdateien entstehen im übergebenen Arbeitsverzeichnis (keine festen /tmp-Pfade, S34).
 *
 * Sicherheit:
 * - Die Übertragung erfolgt per HTTPS mit Token-basierter Authentifizierung.
 * - Nur Server mit gültigem API-Token und API-IP dürfen Zonendaten empfangen.
 * - Es gibt keine direkte Bearbeitung von Benutzereingaben – alle Daten stammen aus der vertrauenswürdigen Datenbank.
 *
 * Abhängigkeiten:
 * - generate_zone_conf_file() und zone_name_is_safe() aus bind_file_generator.php
 */

require_once __DIR__ . '/bind_file_generator.php';
require_once __DIR__ . '/BindAgentClient.php';
require_once __DIR__ . '/BindWorkDir.php';

/**
 * Verteilt eine Zonendatei (db.<zone>) an alle aktiven, zugewiesenen Server (lokal und remote) über die API (zone_sync.php).
 *
 * Verhalten:
 * - Die Zonendatei muss bereits erzeugt sein (generate_zone_file); fehlt sie, wird ein Fehler gemeldet.
 * - Die Datei wird base64-kodiert über HTTPS an jeden aktiven Server übertragen, der dieser Zone zugewiesen ist.
 * - Alle Übertragungsfehler (Curl, HTTP) werden gesammelt zurückgegeben.
 *
 * @param PDO $pdo Datenbankverbindung
 * @param BindAgentClient $agent Agent-Client
 * @param int $zone_id ID der zu verteilenden Zone (muss in `zones` existieren)
 * @param string|null $zone_path Pfad der erzeugten Zonendatei
 * @return array Assoziatives Array mit 'status', 'errors', 'output'
 */
function distribute_zone_file(PDO $pdo, BindAgentClient $agent, int $zone_id, ?string $zone_path): array {
    $errors = [];
    $outputs = [];

    // Zonennamen anhand der ID abfragen (zur Anzeige)
    $zone_stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
    $zone_stmt->execute([$zone_id]);
    $zone_name = $zone_stmt->fetchColumn();
    if (!$zone_name) {
        $msg = "Zone-ID $zone_id nicht gefunden.";
        error_log("[distribute_zone_file] $msg");
        return [
            'status' => 'error',
            'errors' => [$msg],
            'output' => $msg
        ];
    }

    // Aktive Server für diese Zone laden (mind. einer muss aktiv sein)
    $stmt = $pdo->prepare("
        SELECT zs.server_id, s.name, s.api_ip, s.api_token
        FROM zone_servers zs
        JOIN servers s ON s.id = zs.server_id
        WHERE zs.zone_id = ? AND s.active = 1
    ");
    $stmt->execute([$zone_id]);
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($servers)) {
        $msg = "Keine aktiven Server für Zone $zone_name.";
        error_log("[distribute_zone_file] $msg");
        return [
            'status' => 'error',
            'errors' => [$msg],
            'output' => $msg
        ];
    }

    // Keine Generierung an dieser Stelle (früherer versteckter Publish-Pfad entfernt)
    if (!is_string($zone_path) || $zone_path === '' || !file_exists($zone_path)) {
        return [
            'status' => 'error',
            'errors' => ["Zonendatei fehlt oder ungültiger Pfad."],
            'output' => "Zonendatei nicht vorhanden oder ungültiger Pfad: " . var_export($zone_path, true)
        ];
    }

    // Dateiinhalt base64-kodieren für Versand über HTTP
    $zone_data = base64_encode(file_get_contents($zone_path));

    foreach ($servers as $srv) {
        // Nur die konkrete Zone
        $r = $agent->postJson($srv['api_ip'], '/zones/zone_sync.php', (string)$srv['api_token'], [
            'zone_id'     => $zone_id,
            'zone_name'   => $zone_name,
            'zone_data'   => $zone_data
        ], BindAgentClient::SYNC_CONNECT_TIMEOUT, BindAgentClient::SYNC_TIMEOUT);

        // Fehlerauswertung für diesen Server
        if (!empty($r['curlError'])) {
            $errors[] = "CURL-Fehler bei {$srv['name']} ({$srv['api_ip']}): {$r['curlError']}";
        } elseif ($r['httpCode'] !== 200) {
            $json = json_decode((string)$r['body'], true);
            $msg = $json['message'] ?? 'Unbekannter Fehler';
            $details = trim($json['check_output'] ?? '');
            $combined = "HTTP-{$r['httpCode']} bei {$srv['name']} ({$srv['api_ip']}): $msg" . ($details ? " – $details" : '');
            $errors[] = $combined;
        }
    }

    $output = implode("\n", $outputs);

    // Status: Agent-/HTTP-/Netzwerkfehler werden als 'warning' gemeldet (nicht 'error'),
    // damit Publish-Ablauf, DynDNS-Rollback und changed/bind_dirty unverändert bleiben.
    $status = empty($errors) ? 'ok' : 'warning';

    return [
        'status' => $status,
        'errors' => $errors,
        'output' => $output
    ];
}

/**
 * Verteilt eine zonenspezifische BIND-Konfigurationsdatei (.conf) an alle aktiven, zugewiesenen Server (lokal und remote) via API.
 *
 * - Die Konfiguration wird je Server im übergebenen Arbeitsverzeichnis erzeugt (generate_zone_conf_file)
 *   und als base64-encoded JSON-Payload übertragen; die erzeugten Dateien werden je Server wieder entfernt.
 * - Wenn ein Server nicht erreichbar ist oder einen Fehler zurückliefert, wird dies gesammelt und als Fehlerstatus gemeldet.
 *
 * @param PDO $pdo Datenbankverbindung
 * @param BindAgentClient $agent Agent-Client
 * @param BindWorkDir $work Arbeitsverzeichnis des Aufrufers
 * @param string $zone_name Name der Zone, für die die Konfiguration erzeugt und verteilt werden soll.
 * @return array Assoziatives Array mit 'status', 'errors', 'output'
 */
function distribute_zone_conf_file(PDO $pdo, BindAgentClient $agent, BindWorkDir $work, string $zone_name): array {
    $errors = [];
    $outputs = [];

    // S33: Zonenname geht in Pfade und in die BIND-Konfiguration
    if (!zone_name_is_safe($zone_name)) {
        return [
            'status' => 'error',
            'errors' => ['Ungültiger Zonenname: unzulässige Zeichen'],
            'output' => '[conf-distribute] Ungültiger Zonenname – keine Konfiguration erzeugt.'
        ];
    }

    // Server ermitteln
    $stmt = $pdo->prepare("
        SELECT s.id AS id, s.name, s.api_ip, s.api_token
        FROM zone_servers zs
        JOIN servers s ON s.id = zs.server_id
        WHERE zs.zone_id = (SELECT id FROM zones WHERE name = ?) AND s.active = 1
    ");
    $stmt->execute([$zone_name]);
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($servers)) {
        $msg = "[conf-distribute] Keine aktiven Server für Zone $zone_name.";
        error_log($msg);
        $errors[] = "Keine aktiven Server für $zone_name";
        return [
            'status' => 'error',
            'errors' => $errors,
            'output' => $msg
        ];
    }

    foreach ($servers as $srv) {
        // Konfigurationsdatei im eigenen Arbeitsverzeichnis erzeugen (je Server getrennt)
        $tmp_dir = $work->path() . '/conf_' . (int)$srv['id'];
        $gen_result = generate_zone_conf_file($pdo, $tmp_dir, $zone_name, (int)$srv['id']);
        $outputs[] = "[{$srv['name']}]: {$gen_result['output']}";

        $conf_path = $tmp_dir . "/conf/{$zone_name}.conf";
        if (!file_exists($conf_path)) {
            $errors[] = "Remote-Conf fehlt für {$srv['name']} ($zone_name)";
            continue;
        }

        $conf_data = base64_encode(file_get_contents($conf_path));

        // API-Payload senden (nur die konkrete Zone)
        $r = $agent->postJson($srv['api_ip'], '/zones/conf_sync.php', (string)$srv['api_token'], [
            'zone_name' => $zone_name,
            'conf_data' => $conf_data
        ], BindAgentClient::SYNC_CONNECT_TIMEOUT, BindAgentClient::SYNC_TIMEOUT);

        if (!empty($r['curlError'])) {
            $errors[] = "CURL-Fehler bei {$srv['name']}: {$r['curlError']}";
        } elseif ($r['httpCode'] !== 200) {
            $json = json_decode((string)$r['body'], true);
            $msg = $json['message'] ?? 'Unbekannter Fehler';
            $details = $json['check_output'] ?? '';
            $errors[] = "HTTP-{$r['httpCode']} von {$srv['name']}: $msg" . ($details ? " – $details" : '');
        }

        // Temporärdateien dieses Servers löschen
        foreach (glob("$tmp_dir/conf/*.conf") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmp_dir . '/conf');
        @rmdir($tmp_dir);
    }

    $status = empty($errors) ? 'ok' : 'error';

    $output = implode("\n", $outputs);

    return [
        'status' => $status,
        'errors' => $errors,
        'output' => $output
    ];
}

/**
 * Entfernt verwaiste Zonen auf ALLEN aktiven Servern (einmal pro Publish-Lauf) über die API (zone_cleanup.php).
 *
 * Zweck:
 * - Pro aktivem Server (servers.active = 1) wird die vollständige Soll-Zonenliste aus der Datenbank ermittelt
 *   (zones JOIN zone_servers) und an zone_cleanup.php gesendet.
 * - Auch aktive Server ohne zugewiesene Zone werden angesprochen; dann mit leerer Liste und allow_empty = true.
 *
 * Verhalten:
 * - Curl-, HTTP- und Agent-Fehler werden pro Server gesammelt.
 * - status 'ok', wenn alle Cleanup-Requests erfolgreich waren, sonst 'warning'.
 *
 * @param PDO $pdo Datenbankverbindung
 * @param BindAgentClient $agent Agent-Client
 * @return array Assoziatives Array mit 'status', 'errors', 'output'
 */
function distribute_zone_cleanup(PDO $pdo, BindAgentClient $agent): array {
    $errors = [];
    $outputs = [];

    // Alle aktiven Server laden (nicht nur Server mit veröffentlichten Zonen)
    try {
        $servers = $pdo->query("SELECT id, name, api_ip, api_token FROM servers WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $stmt_valid = $pdo->prepare("
            SELECT z.name
            FROM zones z
            JOIN zone_servers zs ON z.id = zs.zone_id
            WHERE zs.server_id = ?
        ");
    } catch (Throwable $e) {
        return [
            'status' => 'warning',
            'errors' => ["Aktive Server für Cleanup konnten nicht geladen werden: " . $e->getMessage()],
            'output' => ''
        ];
    }

    foreach ($servers as $srv) {
        // Vollständige Soll-Zonenliste dieses Servers
        try {
            $stmt_valid->execute([$srv['id']]);
            $valid_zones = $stmt_valid->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $errors[] = "Soll-Zonenliste für {$srv['name']} konnte nicht ermittelt werden: " . $e->getMessage();
            continue;
        }

        // Leere Liste nur mit ausdrücklichem allow_empty = true (Server ohne zugewiesene Zone)
        $r = $agent->postJson($srv['api_ip'], '/zones/zone_cleanup.php', (string)$srv['api_token'], [
            'valid_zones' => array_values($valid_zones),
            'allow_empty' => empty($valid_zones)
        ], BindAgentClient::SYNC_CONNECT_TIMEOUT, BindAgentClient::SYNC_TIMEOUT);

        if (!empty($r['curlError'])) {
            $errors[] = "CURL-Fehler bei Cleanup auf {$srv['name']} ({$srv['api_ip']}): {$r['curlError']}";
            continue;
        }

        $http_code = $r['httpCode'];
        $json = json_decode((string)$r['body'], true);
        if ($http_code !== 200 || !is_array($json) || ($json['status'] ?? '') !== 'success') {
            $msg = is_array($json) ? ($json['message'] ?? 'Unbekannter Fehler') : 'Ungültige Antwort';
            $details = (is_array($json) && !empty($json['errors']) && is_array($json['errors']))
                ? ' – ' . implode('; ', $json['errors'])
                : '';
            $errors[] = "Cleanup HTTP-$http_code bei {$srv['name']} ({$srv['api_ip']}): $msg$details";
            continue;
        }

        $removed_conf = $json['removed_conf'] ?? [];
        $removed_zone_files = $json['removed_zone_files'] ?? [];
        if (!empty($removed_conf) || !empty($removed_zone_files)) {
            $outputs[] = "[{$srv['name']}] entfernt: " . implode(', ', array_merge((array)$removed_conf, (array)$removed_zone_files));
        }
    }

    return [
        'status' => empty($errors) ? 'ok' : 'warning',
        'errors' => $errors,
        'output' => implode("\n", $outputs)
    ];
}
