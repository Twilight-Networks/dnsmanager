<?php
/**
 * Datei: inc/deploy/LegacyAgentServerControlBackend.php
 * Zweck: ServerControlBackend über den bestehenden Agenten-Endpunkt /system/control.php (apiPostJson).
 *
 * - URL und Aufruf unverändert wie bisher in actions/bind_reload.php:
 *   https://<api_ip><REMOTE_API_BASE>/system/control.php mit {"action": "reload-bind"} und Bearer-Token.
 * - Lädt keinen Web-Bootstrap; die Konfigurationskonstanten (REMOTE_API_BASE, CURL_SSL_VERIFYPEER,
 *   CURL_SSL_VERIFYHOST) müssen vom Aufrufer geladen sein, sonst Exception.
 * - Normalisiert die Agentenantwort auf status/message (fehlende Felder: leere Zeichenkette).
 */

require_once __DIR__ . '/../app/servers/ServerControlBackend.php';
require_once __DIR__ . '/../api_client.php';

final class LegacyAgentServerControlBackend implements ServerControlBackend
{
    private const REQUIRED_CONSTANTS = ['REMOTE_API_BASE', 'CURL_SSL_VERIFYPEER', 'CURL_SSL_VERIFYHOST'];

    /**
     * @throws LogicException wenn eine Konfigurationskonstante fehlt
     */
    public function __construct()
    {
        foreach (self::REQUIRED_CONSTANTS as $constant) {
            if (!defined($constant)) {
                throw new LogicException("LegacyAgentServerControlBackend: Konfigurationskonstante $constant ist nicht definiert.");
            }
        }
    }

    public function reloadBind(?string $apiIp, string $apiToken): array
    {
        $url = "https://{$apiIp}" . rtrim(REMOTE_API_BASE, '/') . '/system/control.php';
        $response = apiPostJson($url, ['action' => 'reload-bind'], $apiToken);

        return [
            'status'  => is_string($response['status'] ?? null) ? $response['status'] : '',
            'message' => is_scalar($response['message'] ?? null) ? (string)$response['message'] : '',
        ];
    }
}
