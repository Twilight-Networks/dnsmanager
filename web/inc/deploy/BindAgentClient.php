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
 * CURL_SSL_VERIFYPEER, CURL_SSL_VERIFYHOST sowie optional AGENT_TLS_CA_FILE). Kein Web-Bootstrap, keine Datenbank.
 *
 * Agent-TLS-Vertrauensstellung (S42):
 * - Ohne AGENT_TLS_CA_FILE bleibt das bisherige Verhalten unverändert (Prüfung gemäß CURL_SSL_VERIFYPEER/-HOST,
 *   bei eingeschalteter Prüfung gegen den systemweiten CA-Bestand).
 * - Mit AGENT_TLS_CA_FILE und eingeschalteter Prüfung wird ausschließlich dieser CA-Datei vertraut:
 *   CURLOPT_CAINFO = CA-Datei, CURLOPT_CAPATH = null. Das Abschalten des CA-Verzeichnisses ist nötig: libcurl
 *   verwendet sonst zusätzlich seinen Standard (auf ns1 /etc/ssl/certs, das u. a. das lokale snakeoil-Zertifikat
 *   enthält) – auf ns1 nachgewiesen (S42-Vorversuch, PHP 8.5 / libcurl 8.18 / OpenSSL 3.5).
 * - Fail-Closed: Ist die CA-Datei bei eingeschalteter Prüfung nicht vorhanden, nicht lesbar oder enthält kein
 *   Zertifikat, oder ist CURL_SSL_VERIFYHOST nicht 2, wird keine Verbindung aufgebaut; der Aufruf liefert einen
 *   Fehler (curlError), nie eine ungeprüfte Verbindung.
 * - Der Bearer-Token erscheint in keiner Fehlermeldung.
 */

final class BindAgentClient
{
    /** Zeitgrenzen der Datei-Synchronisation (unverändert aus bind_file_distributor.php) */
    public const SYNC_CONNECT_TIMEOUT = 5;
    public const SYNC_TIMEOUT = 30;

    private string $apiBase;
    private mixed $verifyPeer;
    private mixed $verifyHost;
    private ?string $caFile;
    /** Konfigurationsfehler der Agent-TLS-Vertrauensstellung (null = in Ordnung) */
    private ?string $tlsConfigError = null;

    /**
     * @param string|null $caFile ausschließlich vertraute CA-Datei (PEM) für die Agenten; null/'' = nicht gesetzt
     */
    public function __construct(string $apiBase, mixed $verifyPeer, mixed $verifyHost, ?string $caFile = null)
    {
        $this->apiBase = rtrim($apiBase, '/');
        $this->verifyPeer = $verifyPeer;
        $this->verifyHost = $verifyHost;
        $this->caFile = ($caFile === null || $caFile === '') ? null : $caFile;

        if ($this->caFile !== null && (bool)$this->verifyPeer) {
            $this->tlsConfigError = self::validateTlsConfig($this->caFile, $this->verifyHost);
        }
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

        $caFile = defined('AGENT_TLS_CA_FILE') ? (string)AGENT_TLS_CA_FILE : null;

        return new self((string)REMOTE_API_BASE, CURL_SSL_VERIFYPEER, CURL_SSL_VERIFYHOST, $caFile);
    }

    /**
     * Prüft die Agent-TLS-Konfiguration bei eingeschalteter Zertifikatsprüfung mit eigener CA-Datei.
     *
     * @return string|null Fehlerbeschreibung (ohne Geheimnisse) oder null
     */
    private static function validateTlsConfig(string $caFile, mixed $verifyHost): ?string
    {
        if ((int)$verifyHost !== 2) {
            return 'Agent-TLS-Konfiguration ungültig: CURL_SSL_VERIFYHOST muss bei AGENT_TLS_CA_FILE 2 sein';
        }
        if (!is_file($caFile) || !is_readable($caFile)) {
            return "Agent-TLS-Konfiguration ungültig: CA-Datei $caFile nicht vorhanden oder nicht lesbar";
        }
        $pem = @file_get_contents($caFile);
        if (!is_string($pem) || !preg_match('/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem)) {
            return "Agent-TLS-Konfiguration ungültig: CA-Datei $caFile enthält kein PEM-Zertifikat";
        }

        return null;
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
        // S42: ungültige Vertrauensstellung → kein Verbindungsaufbau (Fail-Closed), keine Geheimnisse in der Meldung
        if ($this->tlsConfigError !== null) {
            return ['body' => false, 'httpCode' => 0, 'curlError' => $this->tlsConfigError];
        }

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
        // S42: ausschließlich die konfigurierte Agent-CA vertrauen (systemweites CA-Verzeichnis abschalten)
        if ($this->caFile !== null && (bool)$this->verifyPeer) {
            $options[CURLOPT_CAINFO] = $this->caFile;
            $options[CURLOPT_CAPATH] = null;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        return ['body' => is_string($body) ? $body : false, 'httpCode' => $httpCode, 'curlError' => $curlError];
    }
}
