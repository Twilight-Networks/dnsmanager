<?php
/**
 * Datei: bind_file_distributor.php
 *
 * Zweck:
 * - Verteilt BIND-Zonendateien (`db.<zone>`) und Konfigurationsdateien (`<zone>.conf`) an alle zugewiesenen Nameserver über eine API.
 * - Alle Server – einschließlich des lokalen Webinterface-Hosts – werden über ihre API-Adresse angesprochen (z. B. 127.0.0.1).
 *
 * Architektur:
 * - Zonendateien und Konfigurationsdateien werden temporär erzeugt und base64-kodiert per HTTPS-POST an die jeweiligen Server übertragen.
 * - Die API-Endpunkte der Zielserver (zone_sync.php, conf_sync.php) nehmen die Dateien entgegen und verarbeiten sie lokal.
 * - Zusätzlich wird eine Liste gültiger Zonen übermittelt, damit der Zielserver veraltete Dateien selbst entfernen kann.
 *
 * Merkmale:
 * - Es wird keine zentrale zones.conf mehr erzeugt.
 * - Die Funktionalität ist vollständig API-zentriert (API-only).
 * - Temporäre Dateien werden nach erfolgreicher Verarbeitung automatisch entfernt.
 *
 * Sicherheit:
 * - Die Übertragung erfolgt per HTTPS mit Token-basierter Authentifizierung.
 * - Nur Server mit gültigem API-Token und API-IP dürfen Zonendaten empfangen.
 * - Es gibt keine direkte Bearbeitung von Benutzereingaben – alle Daten stammen aus der vertrauenswürdigen Datenbank.
 *
 * Abhängigkeiten:
 * - generate_zone_file() und generate_zone_conf_file() aus bind_file_generator.php
 */

/**
 * Verteilt eine Zonendatei (db.<zone>) an alle aktiven, zugewiesenen Server (lokal und remote) über die API (zone_sync.php).
 *
 * Zweck:
 * - Für die angegebene Zone wird eine aktuelle Zonendatei erzeugt (sofern nicht vorab übergeben) und temporär gespeichert.
 * - Die Datei wird base64-kodiert über HTTPS an jeden aktiven Server übertragen, der dieser Zone in der Datenbank zugewiesen ist.
 * - Auch der lokale Server wird über 127.0.0.1 via API angesprochen.
 * - Zusätzlich wird eine Liste aller gültigen Zonen übermittelt, um auf Empfängerseite veraltete Zonendateien bereinigen zu können.
 *
 * Verhalten:
 * - Wenn $zone_path nicht angegeben ist, wird die Datei automatisch generiert.
 * - Alle Übertragungsfehler (Curl, HTTP) werden protokolliert und gesammelt zurückgegeben.
 * - Temporäre Dateien im /tmp-Verzeichnis werden nach erfolgreichem Versand gelöscht.
 *
 * @param int $zone_id ID der zu verteilenden Zone (muss in `zones` existieren)
 * @param string|null $zone_path Optional: bereits erzeugter Dateipfad (z. B. aus Vorvalidierung). Wird sonst intern erzeugt.
 * @return array Assoziatives Array mit 'status', 'errors', 'output'
 */
function distribute_zone_file(int $zone_id, ?string $zone_path = null): array {
    global $pdo;
    $errors = [];
    $outputs = [];

    // Zonennamen anhand der ID abfragen (zur Anzeige und für named-checkzone)
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

    // Falls kein Pfad übergeben wurde, Zonendatei jetzt generieren
    if (!$zone_path) {
        $zone_path_result = generate_zone_file(
            $zone_id,
            '/tmp',
            'publish'
        );

        // Falls Generierung oder Übergabe ungültig ist: abbrechen
        if (!is_array($zone_path_result) || $zone_path_result['status'] === 'error') {
            $msg = "Zonendatei konnte nicht generiert werden für $zone_name: " . ($zone_path_result['output'] ?? 'Unbekannter Fehler');
            error_log("[distribute_zone_file] $msg");
            return [
                'status' => 'error',
                'errors' => [$msg],
                'output' => $msg
            ];
        }

        $zone_path = $zone_path_result['path'];
    }

    if (!is_string($zone_path) || !file_exists($zone_path)) {
        return [
            'status' => 'error',
            'errors' => ["Zonendatei fehlt oder ungültiger Pfad."],
            'output' => "Zonendatei nicht vorhanden oder ungültiger Pfad: " . var_export($zone_path, true)
        ];
    }

    // Dateiinhalt base64-kodieren für Versand über HTTP
    $zone_data = base64_encode(file_get_contents($zone_path));

    // Alle zugewiesenen Server durchlaufen (nur Remote-Server erhalten die Datei)
    foreach ($servers as $srv) {
        // API-Endpunkt für Zonensynchronisation auf dem Remote-Server
        $url = "https://{$srv['api_ip']}" . rtrim(REMOTE_API_BASE, '/') . '/zones/zone_sync.php';

        // Liste aller gültigen Zonennamen für diesen Server (zur Gültigkeitsprüfung auf Empfängerseite)
        $stmt_valid = $pdo->prepare("
            SELECT z.name
            FROM zones z
            JOIN zone_servers zs ON z.id = zs.zone_id
            WHERE zs.server_id = ?
        ");
        $stmt_valid->execute([$srv['server_id']]);
        $valid_zones = $stmt_valid->fetchAll(PDO::FETCH_COLUMN);

        // JSON-Payload für POST-Request vorbereiten
        $payload = json_encode([
            'zone_id'     => $zone_id,
            'zone_name'   => $zone_name,
            'zone_data'   => $zone_data,
            'valid_zones' => $valid_zones
        ]);

        // CURL-Request an den Remote-Agenten senden
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $srv['api_token'],
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFYPEER,
            CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFYHOST,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Fehlerauswertung für diesen Server
        if (!empty($curl_error)) {
            $errors[] = "CURL-Fehler bei {$srv['name']} ({$srv['api_ip']}): $curl_error";
        } elseif ($http_code !== 200) {
            $json = json_decode($response, true);
            $msg = $json['message'] ?? 'Unbekannter Fehler';
            $details = trim($json['check_output'] ?? '');
            $combined = "HTTP-$http_code bei {$srv['name']} ({$srv['api_ip']}): $msg" . ($details ? " – $details" : '');
            $errors[] = $combined;
        }
    }

    // Zusammenfassung und Logging
    $output = implode("\n", $outputs);

    // Wenn temporäre Datei verwendet wurde: löschen
    if (str_starts_with($zone_path, '/tmp/') && file_exists($zone_path)) {
        @unlink($zone_path);
    }

    return [
        'status' => $status,
        'errors' => $errors,
        'output' => $output
    ];
}

/**
 * Verteilt eine zonenspezifische BIND-Konfigurationsdatei (.conf) an alle aktiven, zugewiesenen Server (lokal und remote) via API.
 *
 * Zweck:
 * - Die Konfiguration wird für jeden Server individuell erzeugt und über die API (conf_sync.php) übermittelt.
 * - Auch der lokale Server wird wie ein Remote-Server behandelt (API-Aufruf mit 127.0.0.1).
 * - Die Konfiguration wird temporär mit `generate_zone_conf_file()` erzeugt und anschließend als base64-encoded JSON-Payload übertragen.
 * - Zusätzlich wird eine Liste aller aktuell gültigen Zonennamen für diesen Server mitgesendet,
 *   damit der Empfänger veraltete `.conf`-Dateien ggf. selbst bereinigen kann.
 *
 * Verhalten:
 * - Wenn ein Server nicht erreichbar ist oder einen Fehler zurückliefert, wird dies gesammelt und als Fehlerstatus gemeldet.
 * - Erfolgreiche Übertragungen werden in der Rückgabe geloggt.
 *
 * @param string $zone_name Name der Zone, für die die Konfiguration erzeugt und verteilt werden soll.
 * @return array Assoziatives Array mit 'status', 'errors', 'output'
 */function distribute_zone_conf_file(string $zone_name): array {
    global $pdo;
    $errors = [];
    $outputs = [];

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
        // Konfigurationsdatei erzeugen im temporären Verzeichnis
        $tmp_dir = "/tmp/dnsmanager_conf_{$srv['api_ip']}";
        $gen_result = generate_zone_conf_file($tmp_dir, $zone_name, $srv['id']);
        $outputs[] = "[{$srv['name']}]: {$gen_result['output']}";

        $conf_path = $tmp_dir . "/conf/{$zone_name}.conf";
        if (!file_exists($conf_path)) {
            $errors[] = "Remote-Conf fehlt für {$srv['name']} ($zone_name)";
            continue;
        }

        $conf_data = base64_encode(file_get_contents($conf_path));
        $url = "https://{$srv['api_ip']}" . rtrim(REMOTE_API_BASE, '/') . '/zones/conf_sync.php';

        // Gültige Zonennamen
        $stmt_valid = $pdo->prepare("
            SELECT z.name
            FROM zones z
            JOIN zone_servers zs ON z.id = zs.zone_id
            WHERE zs.server_id = ?
        ");
        $stmt_valid->execute([$srv['id']]);
        $valid_zones = $stmt_valid->fetchAll(PDO::FETCH_COLUMN);

        // API-Payload senden
        $payload = json_encode([
            'zone_name' => $zone_name,
            'conf_data' => $conf_data,
            'valid_zones' => $valid_zones
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $srv['api_token'],
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFYPEER,
            CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFYHOST,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (!empty($curl_error)) {
            $errors[] = "CURL-Fehler bei {$srv['name']}: $curl_error";
        } elseif ($http_code !== 200) {
            $json = json_decode($response, true);
            $msg = $json['message'] ?? 'Unbekannter Fehler';
            $details = $json['check_output'] ?? '';
            $errors[] = "HTTP-$http_code von {$srv['name']}: $msg" . ($details ? " – $details" : '');
        }

        // Temporärdateien löschen
        foreach (glob("$tmp_dir/conf/*.conf") as $f) {
            @unlink($f);
        }
        @rmdir($tmp_dir . '/conf');
        @rmdir($tmp_dir);
    }

    // Zusammenfassendes Logging
    $status = empty($errors) ? 'ok' : 'error';

    $output = implode("\n", $outputs);

    return [
        'status' => $status,
        'errors' => $errors,
        'output' => $output
    ];
}
