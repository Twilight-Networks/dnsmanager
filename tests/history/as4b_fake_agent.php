<?php
/**
 * Datei: tests/history/as4b_fake_agent.php
 * Zweck: AgentClient mit festgelegten Antworten für as4b_test.php (AS4b). Kein Netzwerk, kein Server, kein named.
 *
 * Je Server (api_ip) ein Modus:
 * - legacy        Agent im Modus off bzw. alter Agent: HTTP 200 {"status":"success"} ohne AS2-Felder
 * - as2           AS2a/AS2b in observe: effect, reload, installed (aus der übertragenen Datei), fence, conf_sync mit
 *                 verification verified (Serial und sha256 der zuletzt übertragenen Datei dieser Zone)
 * - as2_noverify  wie as2, aber ohne verification (AS2a-Stand)
 * Einzelne Antworten lassen sich je (Endpunkt, api_ip) vorgeben (queue), ein Haken läuft vor jeder Antwort.
 */

require_once dirname(__DIR__, 1) . '/../web/inc/deploy/AgentClient.php';

final class As4bFakeAgent implements AgentClient
{
    /** @var array<string, string> api_ip => Modus */
    public array $modes = [];
    /** @var array<int, array{endpoint: string, ip: ?string, payload: array}> */
    public array $calls = [];
    /** @var array<string, array<int, mixed>> "endpoint|ip" => Antworten (array oder callable(array $payload): array) */
    public array $queue = [];
    /** @var callable|null fn(string $endpoint, ?string $ip, array $payload): void */
    public $hook = null;
    /** @var array<string, array{serial: int, sha: string}> "ip|zone" => zuletzt übertragene Datei */
    public array $files = [];
    /** @var array<string, array> system/status je api_ip */
    public array $status = [];

    public static function resp(int $http, array $body): array
    {
        return ['body' => json_encode($body), 'httpCode' => $http, 'curlError' => '', 'curlErrno' => 0, 'tlsConfigError' => false];
    }

    public static function transport(int $errno, string $msg): array
    {
        return ['body' => false, 'httpCode' => 0, 'curlError' => $msg, 'curlErrno' => $errno, 'tlsConfigError' => false];
    }

    public function postJson(?string $apiIp, string $endpoint, string $token, array $payload, ?int $connectTimeout, int $timeout): array
    {
        $this->calls[] = ['endpoint' => $endpoint, 'ip' => $apiIp, 'payload' => $payload];
        if ($this->hook !== null) {
            ($this->hook)($endpoint, $apiIp, $payload);
        }
        if ($endpoint === '/zones/zone_sync.php' && isset($payload['zone_data'])) {
            $bytes = base64_decode((string)$payload['zone_data']);
            preg_match('/\n\s+(\d+)\s+; Serial\n/', $bytes, $m);
            $this->files[$apiIp . '|' . $payload['zone_name']] = ['serial' => (int)($m[1] ?? 0), 'sha' => hash('sha256', $bytes)];
        }
        $key = $endpoint . '|' . $apiIp;
        if (!empty($this->queue[$key])) {
            $r = array_shift($this->queue[$key]);
            return is_callable($r) ? $r($payload, $this) : $r;
        }

        return $this->standard($endpoint, (string)$apiIp, $payload);
    }

    public function get(?string $apiIp, string $endpoint, string $token, ?int $connectTimeout, int $timeout): array
    {
        $this->calls[] = ['endpoint' => $endpoint, 'ip' => $apiIp, 'payload' => []];
        if ($endpoint === '/system/status.php') {
            return self::resp(200, $this->status[(string)$apiIp] ?? ['status' => 'ok']);
        }

        return self::resp(200, ['status' => 'ok', 'message' => '']);
    }

    /** Endpunkte eines Laufs (ohne system/status) */
    public function writes(): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c['endpoint'] !== '/system/status.php'));
    }

    private function standard(string $endpoint, string $ip, array $payload): array
    {
        $mode = $this->modes[$ip] ?? 'legacy';
        if ($mode === 'legacy') {
            return self::resp(200, $endpoint === '/zones/zone_cleanup.php'
                ? ['status' => 'success', 'removed_conf' => [], 'removed_zone_files' => []]
                : ['status' => 'success', 'message' => 'ok']);
        }
        $fence = isset($payload['fence_token']) ? ['mode' => 'observe', 'decision' => 'accept', 'code' => null, 'token' => $payload['fence_token']] : null;
        $base = ['status' => 'success', 'message' => 'ok', 'effect' => 'applied', 'reload' => ['status' => 'ok', 'exit_code' => 0]] + ($fence ? ['fence' => $fence] : []);
        switch ($endpoint) {
            case '/zones/zone_sync.php':
                $f = $this->files[$ip . '|' . $payload['zone_name']];
                return self::resp(200, $base + ['installed' => ['serial' => $f['serial'], 'sha256' => $f['sha']]]);
            case '/zones/conf_sync.php':
                if ($mode === 'as2_noverify') {
                    return self::resp(200, $base);
                }
                $f = $this->files[$ip . '|' . $payload['zone_name']] ?? null;
                return self::resp(200, $base + ['verification' => $f === null
                    ? ['state' => 'unknown', 'reason' => 'file_missing', 'zone' => $payload['zone_name']]
                    : ['state' => 'verified', 'reason' => null, 'zone' => $payload['zone_name'], 'file_serial' => $f['serial'],
                        'file_sha256' => $f['sha'], 'served_serial' => $f['serial']]]);
            case '/zones/zone_cleanup.php':
                return self::resp(200, ['status' => 'success', 'message' => 'ok', 'effect' => 'unchanged', 'removed_conf' => [],
                    'removed_zone_files' => [], 'zones' => []] + ($fence ? ['fence' => $fence] : []));
            case '/zones/zone_verify.php':
                $f = $this->files[$ip . '|' . $payload['zone_name']] ?? null;
                return self::resp(200, ['status' => 'success', 'verification' => $f === null
                    ? ['state' => 'unknown', 'reason' => 'file_missing', 'zone' => $payload['zone_name']]
                    : ['state' => 'verified', 'reason' => null, 'zone' => $payload['zone_name'], 'file_serial' => $f['serial'], 'file_sha256' => $f['sha'], 'served_serial' => $f['serial']]]);
        }

        return self::resp(404, ['status' => 'error', 'message' => 'unbekannt']);
    }
}
