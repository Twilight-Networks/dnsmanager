<?php
/**
 * Datei: scripts/agent_fence-cli.php
 * Zweck: Lokale Verwaltung des Fencing-Höchststands H eines Agenten (Meilenstein AS, AS1; E81).
 *
 * Liegt bewusst außerhalb des per Apache erreichbaren Agent-Baums. Es gibt keinen API-Weg, H zu setzen oder
 * zurückzusetzen. Dieses Werkzeug kann H nur anlegen (wenn er fehlt) oder erhöhen – nie senken – und eine
 * Fehlermarke nach Prüfung aufheben. Alle Vorgänge laufen unter demselben exklusiven flock wie die Agent-Endpunkte.
 *
 * Aufruf (als Eigentümer des Zonenverzeichnisses, auf ns1/ns2 www-data):
 *   sudo -u www-data php scripts/agent_fence-cli.php [--config=PFAD] status
 *   sudo -u www-data php scripts/agent_fence-cli.php [--config=PFAD] init --domain=UUID --high=N
 *   sudo -u www-data php scripts/agent_fence-cli.php [--config=PFAD] raise --high=N
 *   sudo -u www-data php scripts/agent_fence-cli.php [--config=PFAD] clear-fault --expect-high=N|missing
 *
 * Standardkonfiguration: agent/config/api_config.php dieses Checkouts.
 * Exit-Codes: 0 Erfolg · 1 Aufruffehler · 2 abgelehnt (Vorbedingung) · 3 Persistenzfehler · 4 Lock nicht erhalten
 *
 * init:        nur ohne vorhandenen Höchststand und ohne Fehlermarke; --domain muss der konfigurierten fence_domain
 *              entsprechen (ausdrückliche Bestätigung). Der Wert muss ≥ der höchsten vergebenen Lauf-ID der
 *              Installation sein (Abgleich mit dem Core ab AS4).
 * raise:       nur bei gültigem Höchststand derselben Domäne, ohne Fehlermarke, mit N > H.
 * clear-fault: nur bei vorhandener Fehlermarke. --expect-high muss dem gelesenen H entsprechen (bzw. "missing", wenn
 *              keine H-Datei existiert). H-Datei und Verzeichnis werden erneut synchronisiert, liegengebliebene
 *              Temp-Dateien entfernt, danach die Marke entfernt und das Verzeichnis synchronisiert.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../agent/inc/response.php';
require_once __DIR__ . '/../agent/inc/fence.php';

const EXIT_OK = 0;
const EXIT_USAGE = 1;
const EXIT_REFUSED = 2;
const EXIT_PERSIST = 3;
const EXIT_LOCK = 4;

function cliOut(array $data, int $code): never {
    fwrite($code === EXIT_OK ? STDOUT : STDERR, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit($code);
}

$args = array_slice($argv, 1);
$opts = [];
$cmd = null;
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) === 1) {
        $opts[$m[1]] = $m[2];
    } elseif ($cmd === null && in_array($a, ['status', 'init', 'raise', 'clear-fault'], true)) {
        $cmd = $a;
    } else {
        cliOut(['error' => "Unbekanntes Argument: $a"], EXIT_USAGE);
    }
}
if ($cmd === null) {
    cliOut(['error' => 'Befehl fehlt (status | init | raise | clear-fault)'], EXIT_USAGE);
}

$configPath = $opts['config'] ?? (__DIR__ . '/../agent/config/api_config.php');
$config = @include $configPath;
if (!is_array($config) || !isset($config['zone_data_dir'])) {
    cliOut(['error' => "Agent-Konfiguration nicht lesbar: $configPath"], EXIT_USAGE);
}
$paths = fencePaths($config);

// E100: ext-posix ist verbindlich (belastbare Existenzprüfung, Eigentümerprüfung) – kein Rückfall
if (!agentPosixAvailable() || !function_exists('posix_geteuid')) {
    cliOut(['error' => 'Agent-Voraussetzung fehlt: PHP-Erweiterung posix'], EXIT_REFUSED);
}

// Nur als Eigentümer des Zonenverzeichnisses (Dateieigentum wie bei den Endpunkten)
$euid = posix_geteuid();
$owner = @fileowner($paths['dir']);
if ($owner === false || $euid !== $owner) {
    cliOut(['error' => "Aufruf nur als Eigentümer von {$paths['dir']} (uid $owner), nicht als uid $euid"], EXIT_REFUSED);
}

$state = static function () use ($config): array {
    $h = fenceReadHigh($config);
    return [
        'mode'              => fenceMode($config),
        'configured_domain' => fenceConfiguredDomain($config),
        'fault'             => fenceLatchPresent($config),
        'high_state'        => $h['state'],
        'high'              => $h['high'],
        'high_domain'       => $h['domain'],
        'detail'            => $h['detail'],
    ];
};

if ($cmd === 'status') {
    cliOut($state(), EXIT_OK);
}

// Exklusiver Agent-Lock (wie die Endpunkte), höchstens 30 s warten
$lock = @fopen(agentLockPath($config), 'c');
$locked = false;
if ($lock !== false) {
    $until = microtime(true) + 30;
    while (!($locked = flock($lock, LOCK_EX | LOCK_NB)) && microtime(true) < $until) {
        usleep(100000);
    }
}
if (!$locked) {
    cliOut(['error' => 'Agent-Lock nicht erhalten'], EXIT_LOCK);
}

$before = $state();
$domain = fenceConfiguredDomain($config);
$parseHigh = static function (string $name) use ($opts): int {
    $v = fenceParseToken($opts[$name] ?? null);
    if ($v === null) {
        cliOut(['error' => "--$name=N (positive Ganzzahl) erforderlich"], EXIT_USAGE);
    }
    return $v;
};

switch ($cmd) {
    case 'init':
        $high = $parseHigh('high');
        if ($domain === null || ($opts['domain'] ?? null) !== $domain) {
            cliOut(['error' => '--domain muss der konfigurierten fence_domain entsprechen', 'state' => $before], EXIT_REFUSED);
        }
        if ($before['fault'] || $before['high_state'] !== 'missing') {
            cliOut(['error' => 'init nur ohne vorhandenen Höchststand und ohne Fehlermarke', 'state' => $before], EXIT_REFUSED);
        }
        $p = fencePersistHigh($config, $domain, $high);
        cliOut(['result' => $p['ok'] ? 'initialized' : 'persist_failed', 'step' => $p['step'], 'state' => $state()], $p['ok'] ? EXIT_OK : EXIT_PERSIST);

    case 'raise':
        $high = $parseHigh('high');
        if ($domain === null || $before['fault'] || $before['high_state'] !== 'ok' || $before['high_domain'] !== $domain) {
            cliOut(['error' => 'raise nur bei gültigem Höchststand der konfigurierten Domäne ohne Fehlermarke', 'state' => $before], EXIT_REFUSED);
        }
        if ($high <= $before['high']) {
            cliOut(['error' => "Höchststand wird nie gesenkt oder unverändert neu geschrieben ($high ≤ {$before['high']})", 'state' => $before], EXIT_REFUSED);
        }
        $p = fencePersistHigh($config, $domain, $high);
        cliOut(['result' => $p['ok'] ? 'raised' : 'persist_failed', 'step' => $p['step'], 'state' => $state()], $p['ok'] ? EXIT_OK : EXIT_PERSIST);

    case 'clear-fault':
        $expect = $opts['expect-high'] ?? null;
        if (!$before['fault']) {
            cliOut(['error' => 'keine Fehlermarke vorhanden', 'state' => $before], EXIT_REFUSED);
        }
        $matches = ($expect === 'missing' && $before['high_state'] === 'missing')
            || ($before['high_state'] === 'ok' && $expect !== null && fenceParseToken($expect) === $before['high']
                && $domain !== null && $before['high_domain'] === $domain);
        if (!$matches) {
            cliOut(['error' => '--expect-high entspricht nicht dem gelesenen Höchststand (bzw. H ungültig oder fremde Domäne) – manuelle Prüfung', 'state' => $before], EXIT_REFUSED);
        }
        if ($before['high_state'] === 'ok') {
            $fh = @fopen($paths['high'], 'r');
            if ($fh === false || !@fsync($fh)) {
                cliOut(['result' => 'persist_failed', 'step' => 'high_resync', 'state' => $state()], EXIT_PERSIST);
            }
            fclose($fh);
        }
        foreach (glob($paths['high'] . '.*.tmp') ?: [] as $tmp) {
            @unlink($tmp);
        }
        if (!syncDirectory($paths['dir'])) {
            cliOut(['result' => 'persist_failed', 'step' => 'dir_resync', 'state' => $state()], EXIT_PERSIST);
        }
        if (!@unlink($paths['latch']) || !syncDirectory($paths['dir'])) {
            cliOut(['result' => 'persist_failed', 'step' => 'latch_unlink', 'state' => $state()], EXIT_PERSIST);
        }
        cliOut(['result' => 'fault_cleared', 'state' => $state()], EXIT_OK);
}
