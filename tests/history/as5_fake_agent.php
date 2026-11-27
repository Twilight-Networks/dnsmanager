<?php
/**
 * Datei: tests/history/as5_fake_agent.php
 * Zweck: AgentClient mit modelliertem Datei- und Auslieferungszustand je (api_ip, Zone) für as5_test.php (AS5).
 *        Kein Netzwerk, kein Server, kein named. Die Vorher-Prüfung bildet E103 (Zeilen 6, 7, 9, 10) mit RFC 1982 nach.
 *
 * Modi je api_ip:
 * - legacy   Agent in off: HTTP 200 {"status":"success"} ohne AS2-Felder und ohne serial_check
 * - observe  serial_check; Serial-Gründe nur gemeldet (would_reject), Übernahme erfolgt
 * - enforce  serial_check; Serial-Gründe ⇒ 409 mit effect none, Bestand unverändert
 * Zustand: $file["ip|zone"] = Datei-Serial (Sha), $served["ip|zone"] = ausgelieferte Serial (Standard: wie Datei nach
 * erfolgreicher Übernahme; $stickyServed hält eine abweichende Auslieferung fest, z. B. „Datei unlesbar für named“).
 * Einzelne Antworten lassen sich je (Endpunkt, api_ip) vorgeben (queue); ein Haken läuft vor jeder Antwort.
 */

// AgentClient aus der geprüften Anwendung (AS5_APP, z. B. der AS4b-Rückfallstand im Hilfsprozess); SerialNumber immer aus
// diesem Testbaum (AS5), damit der Ersatz-Agent RFC 1982 unabhängig vom geprüften Codestand anwendet.
$__app = getenv('AS5_APP') ?: dirname(__DIR__, 2);
require_once $__app . '/web/inc/deploy/AgentClient.php';
require_once dirname(__DIR__, 2) . '/web/inc/app/publish/SerialNumber.php';

final class As5FakeAgent implements AgentClient
{
    /** @var array<string, string> api_ip => legacy | observe | enforce */
    public array $modes = [];
    /** @var array<int, array{endpoint: string, ip: ?string, payload: array}> */
    public array $calls = [];
    /** @var array<string, array<int, mixed>> */
    public array $queue = [];
    /** @var callable|null fn(string $endpoint, ?string $ip, array $payload): void */
    public $hook = null;
    /** @var array<string, array{serial: int, sha: string}> */
    public array $file = [];
    /** @var array<string, int> */
    public array $served = [];
    /** @var array<string, int> ausgelieferte Serial, die eine Übernahme nicht ändert */
    public array $stickyServed = [];
    /** @var array<string, array> */
    public array $status = [];
    /** @var array<string, callable> Nachbearbeitung des serial_check je "ip|zone" (ungültige Berichte) */
    public array $mangle = [];

    public static function resp(int $http, array $body): array
    {
        return ['body' => json_encode($body), 'httpCode' => $http, 'curlError' => '', 'curlErrno' => 0, 'tlsConfigError' => false];
    }

    public function postJson(?string $apiIp, string $endpoint, string $token, array $payload, ?int $connectTimeout, int $timeout): array
    {
        $this->calls[] = ['endpoint' => $endpoint, 'ip' => $apiIp, 'payload' => $payload];
        if ($this->hook !== null) {
            ($this->hook)($endpoint, $apiIp, $payload);
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

    /** zone_sync-Aufrufe */
    public function syncs(?string $ip = null): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c['endpoint'] === '/zones/zone_sync.php'
            && ($ip === null || $c['ip'] === $ip)));
    }

    public function writes(): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c['endpoint'] !== '/system/status.php'));
    }

    /** Vorher-Bericht und Entscheidung (E103, vereinfacht: K = ja genau bei vorhandener Datei bzw. Auslieferung) */
    public function serialCheck(string $ip, string $zone, int $new, string $sha, string $mode): array
    {
        $k = "$ip|$zone";
        $f = $this->file[$k] ?? null;
        $s = $this->stickyServed[$k] ?? ($this->served[$k] ?? null);
        $cmps = [];
        if ($f !== null) {
            $cmps[] = SerialNumber::compare($new, $f['serial']);
        }
        if ($s !== null) {
            $cmps[] = SerialNumber::compare($new, $s);
        }
        $identical = $f !== null && hash_equals($f['sha'], $sha);
        $code = null;
        if ($cmps !== [] && array_unique($cmps) !== ['newer'] && !($identical && array_unique($cmps) === ['equal'])) {
            $code = in_array('undefined', $cmps, true) ? 'serial_undefined' : (in_array('older', $cmps, true) ? 'stale_serial' : 'serial_conflict');
        }
        $report = ['mode' => $mode, 'decision' => $code === null ? 'accept' : ($mode === 'enforce' ? 'reject' : 'would_reject'),
            'code' => $code, 'row' => 10, 'new_serial' => $new, 'file_state' => $f === null ? 'missing' : 'valid',
            'file_serial' => $f['serial'] ?? null, 'file_sha256' => $f['sha'] ?? null, 'file_reason' => null,
            'config_state' => ($f !== null || $s !== null) ? 'yes' : 'no', 'config_reason' => null,
            'served_state' => $s !== null ? 'served' : 'not_auth', 'served_serial' => $s, 'served_reason' => null,
            'served_rcode' => 'NOERROR', 'cmp_file' => null, 'cmp_served' => null];
        if (isset($this->mangle[$k])) {
            $report = ($this->mangle[$k])($report);
        }

        return $report;
    }

    private function verification(string $ip, string $zone): array
    {
        $k = "$ip|$zone";
        $f = $this->file[$k] ?? null;
        $s = $this->stickyServed[$k] ?? ($this->served[$k] ?? null);
        if ($f === null || $s === null) {
            return ['state' => 'unknown', 'reason' => 'file_missing', 'zone' => $zone, 'file_serial' => $f['serial'] ?? null,
                'file_sha256' => $f['sha'] ?? null, 'served_serial' => $s];
        }

        return ['state' => $f['serial'] === $s ? 'verified' : 'serial_differs', 'reason' => null, 'zone' => $zone,
            'file_serial' => $f['serial'], 'file_sha256' => $f['sha'], 'served_serial' => $s];
    }

    private function standard(string $endpoint, string $ip, array $payload): array
    {
        $mode = $this->modes[$ip] ?? 'legacy';
        $zone = (string)($payload['zone_name'] ?? '');
        $k = "$ip|$zone";
        if ($mode === 'legacy') {
            if ($endpoint === '/zones/zone_sync.php') {
                $bytes = base64_decode((string)$payload['zone_data']);
                preg_match('/\n\s+(\d+)\s+; Serial\n/', $bytes, $m);
                $this->file[$k] = ['serial' => (int)$m[1], 'sha' => hash('sha256', $bytes)];
                $this->served[$k] = (int)$m[1];
            }
            return self::resp(200, $endpoint === '/zones/zone_cleanup.php'
                ? ['status' => 'success', 'removed_conf' => [], 'removed_zone_files' => []]
                : ['status' => 'success', 'message' => 'ok']);
        }
        $fence = isset($payload['fence_token']) ? ['fence' => ['mode' => $mode, 'decision' => 'accept', 'code' => null, 'token' => $payload['fence_token']]] : [];
        switch ($endpoint) {
            case '/zones/zone_sync.php':
                $bytes = base64_decode((string)$payload['zone_data']);
                preg_match('/\n\s+(\d+)\s+; Serial\n/', $bytes, $m);
                $new = (int)$m[1];
                $sha = hash('sha256', $bytes);
                $report = $this->serialCheck($ip, $zone, $new, $sha, $mode);
                if ($report['decision'] === 'reject') {
                    return self::resp(409, ['status' => 'error', 'message' => 'abgelehnt', 'code' => $report['code'], 'effect' => 'none',
                        'serial_check' => $report] + $fence);
                }
                $this->file[$k] = ['serial' => $new, 'sha' => $sha];
                $this->served[$k] = $new;
                return self::resp(200, ['status' => 'success', 'message' => 'ok', 'effect' => 'applied', 'reload' => ['status' => 'ok', 'exit_code' => 0],
                    'installed' => ['serial' => $new, 'sha256' => $sha], 'serial_check' => $report] + $fence);
            case '/zones/conf_sync.php':
                return self::resp(200, ['status' => 'success', 'message' => 'ok', 'effect' => 'unchanged', 'reload' => ['status' => 'ok', 'exit_code' => 0],
                    'verification' => $this->verification($ip, $zone)] + $fence);
            case '/zones/zone_cleanup.php':
                return self::resp(200, ['status' => 'success', 'message' => 'ok', 'effect' => 'unchanged', 'removed_conf' => [],
                    'removed_zone_files' => [], 'zones' => []] + $fence);
            case '/zones/zone_verify.php':
                return self::resp(200, ['status' => 'success', 'verification' => $this->verification($ip, $zone)]);
        }

        return self::resp(404, ['status' => 'error', 'message' => 'unbekannt']);
    }
}
