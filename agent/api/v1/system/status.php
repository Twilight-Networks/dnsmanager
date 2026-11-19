<?php
/**
 * API-Endpunkt: /api/v1/system/status.php
 *
 * Zweck:
 * - Gesundheitsprüfung des DNS-Servers
 * - Prüft, ob BIND läuft, konfiguriert ist und korrekt antwortet
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token
 * - Kein Datenbankzugriff, reine Systemdiagnose
 */

require_once __DIR__ . '/../../../inc/init.php';
require_once __DIR__ . '/../../../config/api_config.php';
require_once __DIR__ . '/../../../inc/auth.php';
require_once __DIR__ . '/../../../inc/bind_utils.php';
require_once __DIR__ . '/../../../inc/response.php';
require_once __DIR__ . '/../../../inc/fence.php';
require_once __DIR__ . '/../../../inc/dns_state.php';

// === Konfiguration laden und Token prüfen ===
$config = require __DIR__ . '/../../../config/api_config.php';
$validTokens = loadApiTokens($config['token_file']);
requireValidToken($validTokens);

// === Systeminformationen ===
$hostname = gethostname();
$uptimeSeconds = @file_get_contents('/proc/uptime');
$uptime = $uptimeSeconds ? (int)explode(' ', $uptimeSeconds)[0] : null;
$load = sys_getloadavg();

// AS2a (E117): Nur der ausdrücklich konfigurierte Modus off (bzw. fehlendes fencing_mode) verwendet den bisherigen Ablauf
// (E95, E101). In observe/enforce laufen alle externen Prozesse dieses Endpunkts über den Prozess-Runner mit absoluter
// Frist. Fail-closed ohne externen Prozess (Review AS2a): fehlende PHP-Erweiterung posix (Prozessgruppen wären nicht
// beendbar; E100) ⇒ 503 agent_requirement_missing; unbekannter fencing_mode ⇒ 503 fence_misconfigured.
$mode = fenceMode($config);
if ($mode !== 'off') {
    if (!agentPosixAvailable()) {
        sendJsonResponse(503, [
            'status'  => 'error',
            'message' => 'Agent-Voraussetzung fehlt: PHP-Erweiterung posix – Statusprüfung nicht ausgeführt',
            'code'    => 'agent_requirement_missing',
            'fencing' => agentFenceStatus($config),
        ]);
    }
    if ($mode === 'invalid') {
        sendJsonResponse(503, [
            'status'  => 'error',
            'message' => 'Fencing: unbekannter fencing_mode in der Agent-Konfiguration – Statusprüfung nicht ausgeführt',
            'code'    => 'fence_misconfigured',
            'fencing' => agentFenceStatus($config),
        ]);
    }
}
$as2 = $mode !== 'off';
$timeouts = [];
$rndcBinary = $config['bind_rndc'] ?? '/usr/sbin/rndc';

if ($as2) {
    // Bewertung wie bisher; ein nicht abgeschlossener Prozess zählt als Fehlschlag und wird unter bind.timeouts benannt
    $run = static function (string $name, array $argv, float $cap) use (&$timeouts): array {
        $p = agentRunProcess($argv, $cap);
        if ($p['status'] !== 'ok') {
            $timeouts[] = $name;
        }
        return $p;
    };
    $p = $run('pgrep', ['/usr/bin/pgrep', 'named'], AGENT_CAP_PGREP);
    $bindRunning = $p['status'] === 'ok' && trim($p['output']) !== '';

    $p = $run('rndc_status', [AGENT_SUDO, '-n', $rndcBinary, 'status'], AGENT_CAP_RNDC_STATUS);
    $rndcStatus = $p['output'];
    $rndcOk = $p['status'] === 'ok' && !str_contains($rndcStatus, 'not running');

    $p = $run('dig', [(string)($config['bind_dig'] ?? '/usr/bin/dig'), '+short', '+time=1', '+tries=1', '@127.0.0.1', 'localhost', 'A'], AGENT_CAP_DIG);
    // dig +short schreibt auch Fehlermeldungen (";; communications error … timed out") auf stdout – eine Antwort liegt
    // nur bei Exit-Code 0 und mindestens einer Zeile ohne ";" vor (der bisherige Ablauf in off wertete jede Ausgabe)
    $dnsQueryOk = $p['status'] === 'ok' && $p['exit_code'] === 0 && preg_match('/^[^;\s]/m', $p['output']) === 1;
} else {
    // === BIND-Status prüfen ===
    $bindPid = shell_exec('pgrep named');
    $bindRunning = !empty(trim($bindPid));

    $rndcStatus = shell_exec('sudo -n ' . escapeshellcmd($rndcBinary) . ' status 2>&1');
    $rndcOk = !str_contains($rndcStatus, 'not running');

    // === DNS-Testabfrage via dig (localhost) ===
    $digResult = shell_exec('dig +short @127.0.0.1 localhost A');
    $dnsQueryOk = trim($digResult) !== '';
}

// === Ergebnis aufbauen ===
$bindStatus = [
    'named_running' => $bindRunning,
    'rndc_status' => trim($rndcStatus),
    'dns_query_localhost_ok' => $dnsQueryOk
];

$response = [
    'status' => 'ok',
    'hostname' => $hostname,
    'uptime_seconds' => $uptime,
    'load_average' => [
        '1min' => $load[0],
        '5min' => $load[1],
        '15min' => $load[2]
    ],
    'php_version' => PHP_VERSION,
    'bind' => $bindStatus
];

// === AS1: Fähigkeiten und Fencing-Zustand – in observe/enforce immer; in off nur bei gesperrten Schreibvorgängen
// (Fehlermarke vorhanden oder nicht prüfbar, fehlende PHP-Erweiterung posix), sonst unveränderte Antwort ===
$fenceStatus = agentFenceStatus($config);
if ($fenceStatus !== null) {
    $response['capabilities'] = ['fencing' => 1, 'durable_writes' => 1];
    $response['fencing'] = $fenceStatus;
}

// === AS2a: Zustandsprüfung (E103) und Betriebsvoraussetzung Kontrollzone – nur in observe/enforce; HTTP-Status und
// BIND-Auswertung unverändert. In off bleibt die Antwort unverändert. ===
if (in_array(fenceMode($config), ['observe', 'enforce'], true) && agentPosixAvailable()) {
    $proof = dnsConfigProof($config);
    $response['capabilities']['serial_rule'] = 1;
    $response['capabilities']['state_check'] = 1;
    $response['verification'] = [
        'config'       => $proof['state'],
        'config_reason' => $proof['reason'],
        'control_zone' => dnsControlZoneState($config, $proof)['state'],
    ];
}

if ($as2 && isset($proof) && $proof['state'] !== 'ok' && preg_match('/_(timeout|not_started)$/D', (string)$proof['reason']) === 1) {
    $timeouts[] = 'config_proof';
}

// === Fehlerstatus erkennen ===
if ($timeouts !== []) {
    // AS2a: eindeutiger Fehlerzustand bei Fristablauf (nie unbegrenztes Warten)
    $response['bind']['timeouts'] = $timeouts;
    $response['status'] = 'error';
    $response['code'] = 'status_timeout';
    $response['message'] = 'BIND-Statusprüfung nicht innerhalb der Frist abgeschlossen (' . implode(', ', $timeouts) . ')';
    http_response_code(503);
} elseif (!$bindRunning || !$rndcOk || !$dnsQueryOk) {
    $response['status'] = 'error';
    $response['message'] = 'BIND-Status kritisch oder nicht vollständig funktionsfähig';
    http_response_code(503);
} else {
    http_response_code(200);
}

sendJsonResponse(http_response_code(), $response);
