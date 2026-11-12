<?php
/**
 * Datei: inc/deploy/LegacyAgentServerControlBackend.php
 * Zweck: ServerControlBackend über den bestehenden Agenten-Endpunkt /system/control.php.
 *
 * - URL und Aufruf unverändert wie bisher in actions/bind_reload.php:
 *   https://<api_ip><REMOTE_API_BASE>/system/control.php mit {"action": "reload-bind"} und Bearer-Token,
 *   Gesamt-Timeout 5 s (bisher apiPostJson()). Paket 4a: Zugriff über den gemeinsamen BindAgentClient.
 * - Lädt keinen Web-Bootstrap; die Konfigurationskonstanten (REMOTE_API_BASE, CURL_SSL_VERIFYPEER,
 *   CURL_SSL_VERIFYHOST) müssen vom Aufrufer geladen sein, sonst Exception.
 * - Antwortauswertung wie bisher apiPostJson(): curl-Fehler → status error mit Fehlertext; sonst die
 *   JSON-Antwort (HTTP-Status wird wie bisher nicht ausgewertet), ungültiges JSON → "Ungültige Antwort".
 *   Normalisiert auf status/message (fehlende Felder: leere Zeichenkette).
 */

require_once __DIR__ . '/../app/servers/ServerControlBackend.php';
require_once __DIR__ . '/BindAgentClient.php';

final class LegacyAgentServerControlBackend implements ServerControlBackend
{
    /** Gesamt-Timeout des Steuerungsaufrufs (unverändert aus apiPostJson) */
    private const TIMEOUT = 5;

    private BindAgentClient $agent;

    /**
     * @throws LogicException wenn eine Konfigurationskonstante fehlt
     */
    public function __construct(?BindAgentClient $agent = null)
    {
        $this->agent = $agent ?? BindAgentClient::fromConfig();
    }

    public function reloadBind(?string $apiIp, string $apiToken): array
    {
        $r = $this->agent->postJson($apiIp, '/system/control.php', $apiToken, ['action' => 'reload-bind'], null, self::TIMEOUT);

        if (!empty($r['curlError'])) {
            $response = ['status' => 'error', 'message' => $r['curlError']];
        } else {
            $decoded = json_decode((string)$r['body'], true);
            $response = is_array($decoded) ? $decoded : ['status' => 'error', 'message' => 'Ungültige Antwort'];
        }

        return [
            'status'  => is_string($response['status'] ?? null) ? $response['status'] : '',
            'message' => is_scalar($response['message'] ?? null) ? (string)$response['message'] : '',
        ];
    }
}
