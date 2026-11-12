<?php
/**
 * Datei: inc/deploy/AgentMonitoringBackend.php
 * Zweck: MonitoringBackend über die Agent-Endpunkte (system/status, zones/conf_check, zones/zone_check).
 *
 * Prüf- und Auswertungslogik unverändert aus inc/monitoring.php übernommen (check_server_status,
 * check_zone_conf_status, check_zone_status: Zeitgrenze 5 s, Methoden, Header und Antwortauswertung);
 * der HTTP-Zugriff läuft über den gemeinsamen BindAgentClient. Kein Web-Bootstrap, keine Datenbank.
 */

require_once __DIR__ . '/../app/monitoring/MonitoringBackend.php';
require_once __DIR__ . '/BindAgentClient.php';

final class AgentMonitoringBackend implements MonitoringBackend
{
    /** Gesamt-Timeout je Prüfung (unverändert) */
    private const TIMEOUT = 5;

    private BindAgentClient $agent;

    public function __construct(?BindAgentClient $agent = null)
    {
        $this->agent = $agent ?? BindAgentClient::fromConfig();
    }

    public function zoneConfStatus(array $server): array
    {
        $r = $this->agent->get($server['api_ip'], '/zones/conf_check.php', (string)$server['api_token'], null, self::TIMEOUT);
        $response = $r['body'];
        $http_code = $r['httpCode'];

        if (!empty($r['curlError'])) {
            return [
                'status' => 'error',
                'message' => "Verbindungsfehler: {$r['curlError']}"
            ];
        }

        if ($http_code === 200) {
            $json = json_decode((string)$response, true);
            $status = $json['status'] ?? 'error';
            $output = $json['check_output'] ?? 'Keine Rückgabe von named-checkconf.';

            return [
                'status' => $status,
                'message' => trim($output)
            ];
        }

        $json = json_decode((string)$response, true);
        $message = is_array($json) && isset($json['message']) ? $json['message'] : $response;

        return [
            'status' => 'error',
            'message' => "Fehler $http_code: $message"
        ];
    }

    public function zoneStatus(string $zoneName, array $server): array
    {
        $r = $this->agent->postJson($server['api_ip'], '/zones/zone_check.php', (string)$server['api_token'], ['zone_name' => $zoneName], null, self::TIMEOUT);
        $response = $r['body'];
        $http_code = $r['httpCode'];

        if (!empty($r['curlError'])) {
            return [
                'status' => 'error',
                'message' => "Verbindungsfehler: {$r['curlError']}"
            ];
        }

        if ($http_code === 200) {
            $json = json_decode((string)$response, true);
            $out = $json['check_output'] ?? 'Keine Rückgabe von named-checkzone.';
            $error = strpos($out, 'loaded serial') === false;
            $warning = stripos($out, 'warning:') !== false;
            $status = $error ? 'error' : ($warning ? 'warning' : 'ok');

            return [
                'status' => $status,
                'message' => trim($out)
            ];
        }

        $json = json_decode((string)$response, true);
        $message = is_array($json) && isset($json['message']) ? $json['message'] : $response;

        return [
            'status' => 'error',
            'message' => "Fehler $http_code: $message"
        ];
    }

    public function serverStatus(array $server): array
    {
        $r = $this->agent->get($server['api_ip'], '/system/status.php', (string)$server['api_token'], null, self::TIMEOUT);
        $http_code = $r['httpCode'];

        if (!empty($r['curlError'])) {
            return [
                'status' => 'error',
                'message' => "Verbindungsfehler: {$r['curlError']}"
            ];
        }

        $json = json_decode((string)$r['body'], true);

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
}
