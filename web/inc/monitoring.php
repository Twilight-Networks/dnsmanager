<?php
/**
 * Datei: monitoring.php
 * Zweck: Durchführung von Diagnosen lokaler und entfernter DNS-Server
 *
 * Beschreibung:
 * Diese Datei enthält Funktionen zur Prüfung der Konfiguration und Zonendateien von BIND-Servern,
 * sowohl lokal als auch über eine REST-API bei entfernten Servern. Zusätzlich wird der allgemeine
 * Systemstatus von Remote-Servern ermittelt.
 * Diese Datei ruft u. a. sendDiagnosticAlerts() aus monitoring_mailer.php auf,
 * um bei Statuswechseln E-Mail-Benachrichtigungen auszulösen.
 *
 * Abhängigkeiten:
 * - Konstanten: NAMED_CHECKCONF, NAMED_CHECKZONE, REMOTE_API_BASE
 * - CURL-Konstanten: CURL_SSL_VERIFYPEER, CURL_SSL_VERIFYHOST
 * - Erwartet: assoziative Arrays für Serverinformationen mit Feldern wie api_ip, api_token etc.
 */

/**
 * Führt named-checkconf auf dem angegebenen Server über die API aus.
 *
 * Erwartet, dass der Agent unter /zones/conf_check.php erreichbar ist.
 * Auch für den lokalen Server wird die API via 127.0.0.1 verwendet.
 *
 * @param array $server Serverdaten (inkl. api_ip, api_token etc.)
 * @return array{
 *     status: string,   // 'ok' | 'warning' | 'error'
 *     message: string   // Ausgabe von named-checkconf oder Fehlermeldung
 * }
 */
function check_zone_conf_status(array $server): array {
    $url = "https://{$server['api_ip']}" . rtrim(REMOTE_API_BASE, '/') . '/zones/conf_check.php';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$server['api_token']}",
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFYPEER,
        CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFYHOST,
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_error)) {
        return [
            'status' => 'error',
            'message' => "Verbindungsfehler: $curl_error"
        ];
    }

    if ($http_code === 200) {
        $json = json_decode($response, true);
        $status = $json['status'] ?? 'error';
        $output = $json['check_output'] ?? 'Keine Rückgabe von named-checkconf.';

        return [
            'status' => $status,
            'message' => trim($output)
        ];
    }

    $json = json_decode($response, true);
    $message = is_array($json) && isset($json['message']) ? $json['message'] : $response;

    return [
        'status' => 'error',
        'message' => "Fehler $http_code: $message"
    ];
}

/**
 * Führt named-checkzone für eine einzelne Zone auf dem angegebenen Server über die API aus.
 *
 * Der Server muss die Zone bereits als Datei gespeichert haben.
 * Die API-Antwort enthält den Status ('ok', 'warning', 'error') und die Ausgabe von named-checkzone.
 *
 * @param string $zone  Name der DNS-Zone (z. B. "example.com")
 * @param array  $server Serverdaten (inkl. api_ip, api_token etc.)
 * @return array{
 *     status: string,        // 'ok' | 'warning' | 'error'
 *     message: string        // Ausgabe von named-checkzone oder Fehlermeldung
 * }
 */
function check_zone_status(string $zone, array $server): array {
    $url = "https://{$server['api_ip']}" . rtrim(REMOTE_API_BASE, '/') . '/zones/zone_check.php';
    $payload = json_encode(['zone_name' => $zone]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$server['api_token']}",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFYPEER,
        CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFYHOST,
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_error)) {
        return [
            'status' => 'error',
            'message' => "Verbindungsfehler: $curl_error"
        ];
    }

    if ($http_code === 200) {
        $json = json_decode($response, true);
        $out = $json['check_output'] ?? 'Keine Rückgabe von named-checkzone.';
        $error = strpos($out, 'loaded serial') === false;
        $warning = stripos($out, 'warning:') !== false;
        $status = $error ? 'error' : ($warning ? 'warning' : 'ok');

        return [
            'status' => $status,
            'message' => trim($out)
        ];
    }

    $json = json_decode($response, true);
    $message = is_array($json) && isset($json['message']) ? $json['message'] : $response;

    return [
        'status' => 'error',
        'message' => "Fehler $http_code: $message"
    ];
}


/**
 * Prüft den allgemeinen Systemstatus eines Servers über die REST-API.
 *
 * Der Status wird über den Agenten-Endpunkt /system/status.php abgefragt.
 * Auch der lokale Server wird über seine API-IP (z. B. 127.0.0.1) angesprochen.
 *
 * @param array $server Serverdaten (inkl. api_ip, api_token etc.)
 * @return array{
 *     status: string,        // 'ok' | 'error'
 *     message: string        // Statusmeldung oder Fehlermeldung
 * }
 */
function check_server_status(array $server): array {
    $url = "https://{$server['api_ip']}" . rtrim(REMOTE_API_BASE, '/') . '/system/status.php';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$server['api_token']}",
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFYPEER,
        CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFYHOST,
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_error)) {
        return [
            'status' => 'error',
            'message' => "Verbindungsfehler: $curl_error"
        ];
    }

    $json = json_decode($response, true);

    if ($http_code !== 200 || !is_array($json)) {
        $msg = $json['message'] ?? "Unbekannter Fehler ($http_code)";
        return [
            'status' => 'error',
            'message' => $msg
        ];
    }

    return [
        'status' => $json['status'] ?? 'error',
        'message' => $json['message'] ?? ''
    ];
}

/**
 * Schreibt oder aktualisiert einen Diagnosestatus in der Datenbank.
 * Protokolliert Statusänderungen zusätzlich in `diagnostic_log`.
 */
function saveDiagnostic(PDO $db, string $type, int $id, string $check, string $status, string $message, ?int $serverId = null): void
{
    $now = date('Y-m-d H:i:s');
    $notified = defined('MAILER_ENABLED') && MAILER_ENABLED === true ? 0 : 1;

    // Korrekte Auswahl basierend auf NULL oder Wert
    if ($serverId === null) {
        $select = $db->prepare("
            SELECT id, status FROM diagnostics
            WHERE target_type = ? AND target_id = ? AND check_type = ? AND server_id IS NULL
        ");
        $select->execute([$type, $id, $check]);
    } else {
        $select = $db->prepare("
            SELECT id, status FROM diagnostics
            WHERE target_type = ? AND target_id = ? AND check_type = ? AND server_id = ?
        ");
        $select->execute([$type, $id, $check, $serverId]);
    }

    $row = $select->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $diagnosticId = $row['id'];
        $oldStatus = $row['status'];

        $update = $db->prepare("
            UPDATE diagnostics SET status = ?, message = ?, checked_at = ?
            WHERE id = ?
        ");
        $update->execute([$status, $message, $now, $diagnosticId]);

        if ($oldStatus !== $status) {
            $log = $db->prepare("
                INSERT INTO diagnostic_log (diagnostic_id, old_status, new_status, changed_at, message, server_id, notified)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $log->execute([$diagnosticId, $oldStatus, $status, $now, $message, $serverId, $notified]);
        }
    } else {
        $insert = $db->prepare("
            INSERT INTO diagnostics (target_type, target_id, check_type, status, message, checked_at, server_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$type, $id, $check, $status, $message, $now, $serverId]);

        $diagnosticId = $db->lastInsertId();
        $log = $db->prepare("
            INSERT INTO diagnostic_log (diagnostic_id, old_status, new_status, changed_at, message, server_id, notified)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $log->execute([$diagnosticId, 'ok', $status, $now, $message, $serverId, $notified]);
    }
}

/**
 * Entfernt Diagnosedatensätze für Zonen, die dem Server nicht mehr zugewiesen sind.
 * Nur target_type = 'zone' wird betrachtet. Betrifft z. B. check_type = 'zone_status'.
 *
 * Beispiel: Zone wurde von einem Server entfernt – Eintrag in diagnostics wird gelöscht.
 *
 * @param PDO $db
 */
function cleanupOrphanedZoneDiagnostics(PDO $db): void {
    $stmt = $db->query("
        SELECT d.id, d.target_id AS zone_id, d.server_id
        FROM diagnostics d
        WHERE d.target_type = 'zone'
          AND d.server_id IS NOT NULL
    ");

    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($entries as $entry) {
        $check = $db->prepare("
            SELECT COUNT(*) FROM zone_servers
            WHERE zone_id = ? AND server_id = ?
        ");
        $check->execute([$entry['zone_id'], $entry['server_id']]);
        $stillAssigned = $check->fetchColumn();

        if ((int)$stillAssigned === 0) {
            $del = $db->prepare("DELETE FROM diagnostics WHERE id = ?");
            $del->execute([$entry['id']]);

            // Optional: Logging
            /*
            $log = $db->prepare("
                INSERT INTO diagnostic_log (diagnostic_id, old_status, new_status, changed_at, message, server_id)
                VALUES (?, ?, ?, NOW(), ?, ?)
            ");
            $log->execute([
                $entry['id'], 'ok', 'deleted',
                'Zone wurde diesem Server nicht mehr zugewiesen',
                $entry['server_id']
            ]);
            */
        }
    }
}
?>
