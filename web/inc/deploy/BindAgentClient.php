<?php
/**
 * Datei: inc/deploy/BindAgentClient.php
 * Zweck: Einheitlicher HTTP-Zugriff auf die DNS-Manager-Agenten (HTTPS-POST mit JSON bzw. GET, jeweils mit
 *        Bearer-Token).
 *
 * Ersetzt die kopierten curl-Aufrufe in bind_file_distributor.php (zone_sync, conf_sync, zone_cleanup),
 * apiPostJson() (system/control) und – seit Paket 5b – die Monitoring-Prüfungen aus inc/monitoring.php
 * (system/status, zones/conf_check per GET, zones/zone_check per POST). Die Auswertung der Antworten (Fehlertexte, Statuslogik) bleibt beim
 * jeweiligen Aufrufer; Zeitgrenzen werden je Aufruf übergeben (bisherige Werte unverändert).
 *
 * Konfiguration wird explizit übergeben (fromConfig() liest die bestehenden Konstanten REMOTE_API_BASE,
 * CURL_SSL_VERIFYPEER, CURL_SSL_VERIFYHOST). Kein Web-Bootstrap, keine Datenbank.
 */

final class BindAgentClient
{
    /** Zeitgrenzen der Datei-Synchronisation (unverändert aus bind_file_distributor.php) */
    public const SYNC_CONNECT_TIMEOUT = 5;
    public const SYNC_TIMEOUT = 30;

    private string $apiBase;
    private mixed $verifyPeer;
    private mixed $verifyHost;

    public function __construct(string $apiBase, mixed $verifyPeer, mixed $verifyHost)
    {
        $this->apiBase = rtrim($apiBase, '/');
        $this->verifyPeer = $verifyPeer;
        $this->verifyHost = $verifyHost;
    }

    /**
     * @throws LogicException wenn eine Konfigurationskonstante fehlt
     */
    public static function fromConfig(): self
    {
        foreach (['REMOTE_API_BASE', 'CURL_SSL_VERIFYPEER', 'CURL_SSL_VERIFYHOST'] as $constant) {
            if (!defined($constant)) {
                throw new LogicException("BindAgentClient: Konfigurationskonstante $constant ist nicht definiert.");
            }
        }

        return new self((string)REMOTE_API_BASE, CURL_SSL_VERIFYPEER, CURL_SSL_VERIFYHOST);
    }

    /** URL eines Agent-Endpunkts, z. B. url('192.0.2.1', '/zones/zone_sync.php') */
    public function url(?string $apiIp, string $endpoint): string
    {
        return "https://{$apiIp}" . $this->apiBase . $endpoint;
    }

    /**
     * Sendet $payload als JSON per POST.
     *
     * @param array<string, mixed> $payload
     * @param int|null $connectTimeout Sekunden; null = nicht gesetzt (curl-Standard)
     *
     * @return array{body: string|false, httpCode: int, curlError: string}
     */
    public function postJson(?string $apiIp, string $endpoint, string $token, array $payload, ?int $connectTimeout, int $timeout): array
    {
        return $this->request($apiIp, $endpoint, $token, $payload, $connectTimeout, $timeout);
    }

    /**
     * GET-Anfrage (z. B. Monitoring: system/status, zones/conf_check) mit denselben Headern wie bisher.
     *
     * @return array{body: string|false, httpCode: int, curlError: string}
     */
    public function get(?string $apiIp, string $endpoint, string $token, ?int $connectTimeout, int $timeout): array
    {
        return $this->request($apiIp, $endpoint, $token, null, $connectTimeout, $timeout);
    }

    /**
     * @param array<string, mixed>|null $payload null = GET, sonst POST mit JSON
     * @return array{body: string|false, httpCode: int, curlError: string}
     */
    private function request(?string $apiIp, string $endpoint, string $token, ?array $payload, ?int $connectTimeout, int $timeout): array
    {
        $ch = curl_init($this->url($apiIp, $endpoint));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyHost,
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        if ($connectTimeout !== null) {
            $options[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        return ['body' => is_string($body) ? $body : false, 'httpCode' => $httpCode, 'curlError' => $curlError];
    }
}
