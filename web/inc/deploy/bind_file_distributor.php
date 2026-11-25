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
 * Publish-Journal (AP1/H2d, E62–E66): Alle drei Funktionen nehmen optional eine Journal-Anbindung entgegen und liefern
 * zusätzlich 'targets' => [serverId => Einordnung nach PublishTargetClassifier]. Mit Journal
 * - wird ausschließlich an die registrierte Zielmenge verteilt (unter dem Publish-Lock identisch mit der aktuellen;
 *   ein registriertes, nicht mehr aktiv zugewiesenes Ziel wird nicht beliefert und als Fehler gemeldet),
 * - sendet distribute_zone_file() nur eine Datei mit dem registrierten file_hash,
 * - registriert distribute_zone_cleanup() vor dem ersten Agentaufruf alle Server (scheitert das: kein Cleanup),
 * - wird jede Agentenantwort unmittelbar nach ihrem Eingang gemeldet (J4).
 * Status, errors und output – und damit die operative Auswertung im PublishService – bleiben unverändert (J8).
 *
 * AS4b (E83, F5, E74): Mit 'beforeCall' im Journal wird unmittelbar vor JEDEM Agent-Aufruf die Laufberechtigung
 * geprüft; die Rückgabe (fence_token, fence_domain) wird der Nutzlast hinzugefügt. Scheitert die Prüfung, wird das
 * aktuelle Ziel als sicher nicht gesendet (run_lock_lost) gemeldet und die Ausnahme weitergereicht – kein weiterer
 * Request dieses Laufs. 'onResult' erhält zusätzlich das Rohergebnis von postJson() (AgentResponseParser).
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
require_once __DIR__ . '/../app/publish/PublishTargetClassifier.php';
require_once __DIR__ . '/../app/publish/PublishHashContract.php';

/**
 * Filtert die aktuell geladenen Server auf die registrierte Zielmenge (AP1/H2d, E62).
 *
 * @param array<int, array<string, mixed>> $servers   aktuelle Server (Schlüssel $idKey)
 * @param array<int, string>               $registered registrierte Ziele server_id => Name
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} [zu beliefernde Server, fehlende Ziele]
 */
function distribute_registered_targets(array $servers, array $registered, string $idKey): array
{
    $current = [];
    foreach ($servers as $srv) {
        $current[(int)$srv[$idKey]] = true;
    }
    $missing = [];
    foreach ($registered as $sid => $name) {
        if (!isset($current[(int)$sid])) {
            $missing[(int)$sid] = (string)$name;
        }
    }
    $filtered = array_values(array_filter($servers, static fn (array $srv): bool => array_key_exists((int)$srv[$idKey], $registered)));

    return [$filtered, $missing];
}

/**
 * AS4b (E83, F5, E74): Laufberechtigung unmittelbar vor einem Agent-Aufruf; liefert die Fencing-Felder der Nutzlast.
 * Scheitert die Prüfung, wird das Ziel als sicher nicht gesendet gemeldet ($fail, run_lock_lost) und die Ausnahme
 * weitergereicht (kein weiterer Request des Laufs).
 *
 * @return array<string, int|string>
 */
function distribute_before_call(?array $journal, int $sid, callable $fail): array
{
    if ($journal === null || !isset($journal['beforeCall'])) {
        return [];
    }
    try {
        return ($journal['beforeCall'])($sid);
    } catch (Throwable $e) {
        $fail($sid, PublishTargetClassifier::localFailure('run_lock_lost', $e->getMessage()));
        throw $e;
    }
}

/**
 * Verteilt eine Zonendatei (db.<zone>) an alle aktiven, zugewiesenen Server (lokal und remote) über die API (zone_sync.php).
 *
 * Verhalten:
 * - Die Zonendatei muss bereits erzeugt sein (generate_zone_file); fehlt sie, wird ein Fehler gemeldet.
 * - Die Datei wird base64-kodiert über HTTPS an jeden aktiven Server übertragen, der dieser Zone zugewiesen ist.
 * - Alle Übertragungsfehler (Curl, HTTP) werden gesammelt zurückgegeben.
 *
 * @param PDO $pdo Datenbankverbindung
 * @param AgentClient $agent Agent-Client (BindAgentClient)
 * @param int $zone_id ID der zu verteilenden Zone (muss in `zones` existieren)
 * @param string|null $zone_path Pfad der erzeugten Zonendatei
 * @param array|null $journal AP1/H2d: ['targets' => [serverId => Name], 'fileHash' => string,
 *                            'onResult' => callable(int $serverId, array $einordnung): void]
 * @return array Assoziatives Array mit 'status', 'errors', 'output', 'targets'
 */
function distribute_zone_file(PDO $pdo, AgentClient $agent, int $zone_id, ?string $zone_path, ?array $journal = null): array {
    $errors = [];
    $outputs = [];
    $targets = [];
    $report = static function (int $sid, array $c, ?array $raw = null) use (&$targets, $journal): void {
        $targets[$sid] = $c;
        if ($journal !== null) {
            ($journal['onResult'])($sid, $c, $raw);
        }
    };
    $failAll = static function (array $registered, string $code, string $detail) use ($report): void {
        foreach (array_keys($registered) as $sid) {
            $report((int)$sid, PublishTargetClassifier::localFailure($code, $detail));
        }
    };
    $registered = $journal['targets'] ?? [];

    // Zonennamen anhand der ID abfragen (zur Anzeige)
    $zone_stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
    $zone_stmt->execute([$zone_id]);
    $zone_name = $zone_stmt->fetchColumn();
    if (!$zone_name) {
        $msg = "Zone-ID $zone_id nicht gefunden.";
        error_log("[distribute_zone_file] $msg");
        $failAll($registered, 'target_changed', $msg);
        return [
            'status' => 'error',
            'errors' => [$msg],
            'output' => $msg,
            'targets' => $targets,
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

    // AP1/H2d (E62): nur die registrierte Zielmenge beliefern
    if ($journal !== null) {
        [$servers, $missing] = distribute_registered_targets($servers, $registered, 'server_id');
        foreach ($missing as $sid => $name) {
            $msg = "Zielserver $name (ID $sid) ist der Zone $zone_name nicht mehr aktiv zugewiesen – keine Übertragung.";
            $errors[] = $msg;
            $report($sid, PublishTargetClassifier::localFailure('target_changed', $msg));
        }
    }

    if (empty($servers)) {
        $msg = "Keine aktiven Server für Zone $zone_name.";
        error_log("[distribute_zone_file] $msg");
        return [
            'status' => 'error',
            'errors' => array_merge($errors, [$msg]),
            'output' => $msg,
            'targets' => $targets,
        ];
    }

    // Keine Generierung an dieser Stelle (früherer versteckter Publish-Pfad entfernt)
    if (!is_string($zone_path) || $zone_path === '' || !file_exists($zone_path)) {
        $failAll(array_column($servers, 'name', 'server_id'), 'no_file', 'Zonendatei fehlt oder ungültiger Pfad');
        return [
            'status' => 'error',
            'errors' => ["Zonendatei fehlt oder ungültiger Pfad."],
            'output' => "Zonendatei nicht vorhanden oder ungültiger Pfad: " . var_export($zone_path, true),
            'targets' => $targets,
        ];
    }

    $zone_bytes = file_get_contents($zone_path);

    // AP1/H2d (E60/E62): nur die Datei mit dem registrierten file_hash senden
    if ($journal !== null && (!is_string($zone_bytes) || !hash_equals((string)$journal['fileHash'], PublishHashContract::sha256($zone_bytes)))) {
        $msg = "Zonendatei für $zone_name weicht vom registrierten Stand ab (file_hash) – keine Übertragung.";
        error_log("[distribute_zone_file] $msg");
        $failAll(array_column($servers, 'name', 'server_id'), 'file_hash_mismatch', $msg);
        return [
            'status' => 'error',
            'errors' => array_merge($errors, [$msg]),
            'output' => $msg,
            'targets' => $targets,
        ];
    }

    // Dateiinhalt base64-kodieren für Versand über HTTP
    $zone_data = base64_encode((string)$zone_bytes);

    foreach ($servers as $srv) {
        // AS4b (E83): Laufberechtigung unmittelbar vor dem Aufruf; Fencing-Felder (F5, E74)
        $fence = distribute_before_call($journal, (int)$srv['server_id'], $report);

        // Nur die konkrete Zone
        $r = $agent->postJson($srv['api_ip'], '/zones/zone_sync.php', (string)$srv['api_token'], [
            'zone_id'     => $zone_id,
            'zone_name'   => $zone_name,
            'zone_data'   => $zone_data
        ] + $fence, BindAgentClient::SYNC_CONNECT_TIMEOUT, BindAgentClient::SYNC_TIMEOUT);

        // Journal (J4): Ergebnis unmittelbar nach der Antwort
        $report((int)$srv['server_id'], PublishTargetClassifier::classify(PublishTargetClassifier::KIND_ZONE, $r), $r);

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
        'output' => $output,
        'targets' => $targets,
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
 * @param AgentClient $agent Agent-Client (BindAgentClient)
 * @param BindWorkDir $work Arbeitsverzeichnis des Aufrufers
 * @param string $zone_name Name der Zone, für die die Konfiguration erzeugt und verteilt werden soll.
 * @param array|null $journal AP1/H2d: ['targets' => [serverId => Name],
 *                            'onResult' => callable(int $serverId, array $einordnung, ?string $confHash): void]
 * @return array Assoziatives Array mit 'status', 'errors', 'output', 'targets'
 */
function distribute_zone_conf_file(PDO $pdo, AgentClient $agent, BindWorkDir $work, string $zone_name, ?array $journal = null): array {
    $errors = [];
    $outputs = [];
    $targets = [];
    $report = static function (int $sid, array $c, ?string $confHash, ?array $raw = null) use (&$targets, $journal): void {
        $targets[$sid] = $c + ['confHash' => $confHash];
        if ($journal !== null) {
            ($journal['onResult'])($sid, $c, $confHash, $raw);
        }
    };
    $registered = $journal['targets'] ?? [];

    // S33: Zonenname geht in Pfade und in die BIND-Konfiguration
    if (!zone_name_is_safe($zone_name)) {
        foreach ($registered as $sid => $name) {
            $report((int)$sid, PublishTargetClassifier::localFailure('conf_missing', 'Ungültiger Zonenname'), null);
        }
        return [
            'status' => 'error',
            'errors' => ['Ungültiger Zonenname: unzulässige Zeichen'],
            'output' => '[conf-distribute] Ungültiger Zonenname – keine Konfiguration erzeugt.',
            'targets' => $targets,
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

    // AP1/H2d (E62): nur die registrierte Zielmenge beliefern
    if ($journal !== null) {
        [$servers, $missing] = distribute_registered_targets($servers, $registered, 'id');
        foreach ($missing as $sid => $name) {
            $msg = "Zielserver $name (ID $sid) ist der Zone $zone_name nicht mehr aktiv zugewiesen – keine Konfiguration übertragen";
            $errors[] = $msg;
            $report($sid, PublishTargetClassifier::localFailure('target_changed', $msg), null);
        }
    }

    if (empty($servers)) {
        $msg = "[conf-distribute] Keine aktiven Server für Zone $zone_name.";
        error_log($msg);
        $errors[] = "Keine aktiven Server für $zone_name";
        return [
            'status' => 'error',
            'errors' => $errors,
            'output' => $msg,
            'targets' => $targets,
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
            $report((int)$srv['id'], PublishTargetClassifier::localFailure('conf_missing', "Konfiguration für {$srv['name']} nicht erzeugt"), null);
            continue;
        }

        $conf_bytes = (string)file_get_contents($conf_path);
        $conf_data = base64_encode($conf_bytes);

        // AS4b (E83): Laufberechtigung unmittelbar vor dem Aufruf; Fencing-Felder (F5, E74)
        $fence = distribute_before_call($journal, (int)$srv['id'], static fn (int $sid, array $c) => $report($sid, $c, null));

        // API-Payload senden (nur die konkrete Zone)
        $r = $agent->postJson($srv['api_ip'], '/zones/conf_sync.php', (string)$srv['api_token'], [
            'zone_name' => $zone_name,
            'conf_data' => $conf_data
        ] + $fence, BindAgentClient::SYNC_CONNECT_TIMEOUT, BindAgentClient::SYNC_TIMEOUT);

        // Journal (J4): Ergebnis unmittelbar nach der Antwort, mit conf_hash der gesendeten Bytes
        $report((int)$srv['id'], PublishTargetClassifier::classify(PublishTargetClassifier::KIND_CONF, $r), PublishHashContract::sha256($conf_bytes), $r);

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
        'output' => $output,
        'targets' => $targets,
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
 * @param AgentClient $agent Agent-Client (BindAgentClient)
 * @param array|null $journal AP1/H2d: ['register' => callable(array $servers): bool,
 *                            'onResult' => callable(int $serverId, array $einordnung, ?int $validZoneCount): void]
 * @return array Assoziatives Array mit 'status', 'errors', 'output', 'targets'
 */
function distribute_zone_cleanup(PDO $pdo, AgentClient $agent, ?array $journal = null): array {
    $errors = [];
    $outputs = [];
    $targets = [];
    $report = static function (int $sid, array $c, ?int $valid, ?array $raw = null, string $name = '') use (&$targets, $journal): void {
        $targets[$sid] = $c + ['validZoneCount' => $valid];
        if ($journal !== null) {
            ($journal['onResult'])($sid, $c, $valid, $raw, $name);
        }
    };

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
            'output' => '',
            'targets' => $targets,
        ];
    }

    // AP1/H2d (E66): alle angesprochenen Server vor dem ersten Agentaufruf registrieren; sonst kein Cleanup
    if ($journal !== null && !($journal['register'])(array_column($servers, 'name', 'id'))) {
        return [
            'status' => 'warning',
            'errors' => ['Publish-Journal: Cleanup konnte nicht registriert werden – kein Cleanup ausgeführt'],
            'output' => '',
            'targets' => $targets,
        ];
    }

    foreach ($servers as $srv) {
        // Vollständige Soll-Zonenliste dieses Servers
        try {
            $stmt_valid->execute([$srv['id']]);
            $valid_zones = $stmt_valid->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $errors[] = "Soll-Zonenliste für {$srv['name']} konnte nicht ermittelt werden: " . $e->getMessage();
            $report((int)$srv['id'], PublishTargetClassifier::localFailure('zone_list_failed', 'Soll-Zonenliste nicht ermittelbar'), null);
            continue;
        }

        // AS4b (E83): Laufberechtigung unmittelbar vor dem Aufruf; Fencing-Felder (F5, E74)
        $fence = distribute_before_call($journal, (int)$srv['id'], static fn (int $sid, array $c) => $report($sid, $c, null, null, (string)$srv['name']));

        // Leere Liste nur mit ausdrücklichem allow_empty = true (Server ohne zugewiesene Zone)
        $r = $agent->postJson($srv['api_ip'], '/zones/zone_cleanup.php', (string)$srv['api_token'], [
            'valid_zones' => array_values($valid_zones),
            'allow_empty' => empty($valid_zones)
        ] + $fence, BindAgentClient::SYNC_CONNECT_TIMEOUT, BindAgentClient::SYNC_TIMEOUT);

        // Journal (J4): Ergebnis unmittelbar nach der Antwort (nur Zähler, Q5)
        $report((int)$srv['id'], PublishTargetClassifier::classify(PublishTargetClassifier::KIND_CLEANUP, $r), count($valid_zones), $r, (string)$srv['name']);

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
        'output' => implode("\n", $outputs),
        'targets' => $targets,
    ];
}
