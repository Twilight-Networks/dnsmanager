<?php
/**
 * Datei: tests/agent/as4b_zone_verify_test.php
 * Zweck: Endpunkt-Test für agent/api/v1/zones/zone_verify.php (AS4b, E109). Befund der realen E2E-Abnahme vom
 *        25.11.2026: Der Endpunkt band inc/bind_utils.php nicht ein; jeder Aufruf endete mit „Call to undefined function
 *        safeZoneName()“ (HTTP 500). Die bisherigen Tests verdeckten das, weil as2b_pure_test.php safeZoneName() selbst
 *        definiert und as4b_test.php einen Ersatz-Agenten verwendet.
 *
 * Prinzip:
 * - Der ECHTE Endpunkt des geprüften Checkouts läuft in einem eigenen php-cgi-Prozess als www-data. Es gibt keine
 *   Ersatzfunktion, keinen Ersatz-Agenten, kein auto_prepend und keine vom Harness eingebundenen Dateien: Der Endpunkt
 *   muss alle Abhängigkeiten selbst laden.
 * - Eine Kopie von agent/ (nur reguläre Dateien und Verzeichnisse, ohne agent/config/) erhält eine eigene
 *   Test-Konfiguration: ausgewählte, geheimnisfreie Pfad- und Werkzeugangaben der installierten api_config.php (nur
 *   gelesen), fencing_mode observe bzw. off, eigene Token-Datei, kein IP-Filter. Installierte Konfiguration und
 *   Token-Datei werden weder kopiert noch geschrieben; Symlinks im Checkout außerhalb von agent/config/ führen zum
 *   Abbruch im Preflight (Review PR #109). Sandbox 0750/0640, root:www-data.
 * - Geprüft wird gegen den installierten named und das echte Zonenverzeichnis (CLAUDE.md §5: Fixture ohne eigenen
 *   DNS-Server). Der Endpunkt ist rein lesend; Zonenverzeichnis (inkl. H-Datei, Fehlermarke, Sperrdatei) wird vorher
 *   und nachher byte-genau verglichen.
 *
 * Aufruf (root, Testsystem mit installiertem BIND und DNS-Manager-Agent, z. B. ns1):
 *   php tests/agent/as4b_zone_verify_test.php <Checkout> [<Zone>]
 * Exit 0 nur ohne Fehlschlag.
 */

$root = rtrim($argv[1] ?? '', '/');
$zoneArg = $argv[2] ?? null;
if (!is_file("$root/agent/api/v1/zones/zone_verify.php")) {
    fwrite(STDERR, "Aufruf: php as4b_zone_verify_test.php <Checkout> [<Zone>]\n");
    exit(2);
}
$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $id . ($detail !== '' ? " – $detail" : '') . "\n";
}
function sh(string $cmd): string {
    return (string)shell_exec($cmd);
}

if (function_exists('posix_getuid') && posix_getuid() !== 0) {
    fwrite(STDERR, "als root ausführen\n");
    exit(2);
}
foreach (['/usr/bin/php-cgi', '/usr/bin/sudo', '/etc/dnsmanager/api_config.php'] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "ABBRUCH: $f fehlt\n");
        exit(2);
    }
}
// Nur lesend und nur geheimnisfreie Pfad-/Werkzeugangaben aus der installierten Konfiguration; die Datei selbst wird
// weder kopiert noch geschrieben, die Token-Datei der Installation nie gelesen
$installed = require '/etc/dnsmanager/api_config.php';
$zd = rtrim((string)$installed['zone_data_dir'], '/');
$cfgKeys = ['zone_conf_dir', 'zone_data_dir', 'zones_conf_file', 'bind_named_conf_path', 'bind_named_checkzone', 'bind_named_checkconf', 'bind_rndc', 'l3_control_zone'];
$wwwGid = posix_getgrnam('www-data')['gid'] ?? null;
if ($wwwGid === null) {
    fwrite(STDERR, "ABBRUCH: Gruppe www-data fehlt\n");
    exit(2);
}

/**
 * Harness-Sicherheit (Review PR #109): Die Sandbox enthält ausschließlich reguläre Dateien und Verzeichnisse, die der
 * Harness selbst anlegt. agent/config/ des Checkouts wird nie übernommen (dort verweisen installierte Checkouts per
 * Symlink auf /etc/dnsmanager/); jeder andere Symlink im Checkout führt zum Abbruch vor jeder Schreiboperation. Rechte:
 * Verzeichnisse 0750, Dateien 0640, Eigentümer root, Gruppe www-data (nur root und der php-cgi-Prozess als www-data).
 */
$preflightLinks = static function (string $dir) use (&$preflightLinks, $root): array {
    $bad = [];
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = "$dir/$e";
        if ($p === "$root/agent/config") {
            continue;   // nie übernommen
        }
        if (is_link($p)) {
            $bad[] = $p;
        } elseif (is_dir($p)) {
            $bad = array_merge($bad, $preflightLinks($p));
        } elseif (!is_file($p)) {
            $bad[] = $p;
        }
    }
    return $bad;
};
$bad = $preflightLinks("$root/agent");
if ($bad !== []) {
    fwrite(STDERR, "ABBRUCH (Preflight): Symlinks bzw. Sonderdateien im Checkout außerhalb von agent/config – kein Test: " . implode(', ', $bad) . "\n");
    exit(2);
}
$secure = static function (string $path, int $mode) use ($wwwGid): void {
    if (is_link($path) || !chown($path, 0) || !chgrp($path, $wwwGid) || !chmod($path, $mode)) {
        throw new RuntimeException("Rechte nicht setzbar: $path");
    }
};
$copyTree = static function (string $src, string $dst) use (&$copyTree, $secure, $root): void {
    if (!mkdir($dst, 0700)) {
        throw new RuntimeException("Verzeichnis nicht anlegbar: $dst");
    }
    $secure($dst, 0750);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..' || "$src/$e" === "$root/agent/config") {
            continue;
        }
        $s = "$src/$e";
        if (is_link($s)) {
            throw new RuntimeException("Symlink während der Kopie: $s");
        }
        if (is_dir($s)) {
            $copyTree($s, "$dst/$e");
        } else {
            $in = fopen($s, 'rb');
            $out = fopen("$dst/$e", 'xb');   // exklusiv: nie über eine vorhandene Datei oder einen Symlink schreiben
            if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException("Kopie fehlgeschlagen: $s");
            }
            fclose($in);
            fclose($out);
            $secure("$dst/$e", 0640);
        }
    }
};
/** Reguläre Datei in der Sandbox exklusiv anlegen bzw. ersetzen (Temporärdatei mit 'x', dann rename) */
$writeRegular = static function (string $path, string $content) use ($secure): void {
    if (is_link($path)) {
        throw new RuntimeException("Symlink am Zielpfad: $path");
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $h = fopen($tmp, 'xb');
    if ($h === false || fwrite($h, $content) !== strlen($content)) {
        throw new RuntimeException("Schreiben fehlgeschlagen: $tmp");
    }
    fclose($h);
    $secure($tmp, 0640);
    if (!rename($tmp, $path)) {
        throw new RuntimeException("Ersetzen fehlgeschlagen: $path");
    }
};

$sbx = sys_get_temp_dir() . '/dnsmgr-as4b-zv.' . bin2hex(random_bytes(4));
if (!mkdir($sbx, 0700)) {
    fwrite(STDERR, "ABBRUCH: Sandbox nicht anlegbar\n");
    exit(2);
}
register_shutdown_function(static function () use ($sbx): void {
    if (str_starts_with($sbx, sys_get_temp_dir() . '/dnsmgr-as4b-zv.') && !is_link($sbx)) {
        exec('rm -rf ' . escapeshellarg($sbx));
    }
});
$secure($sbx, 0750);
$copyTree("$root/agent", "$sbx/agent");
mkdir("$sbx/agent/config", 0700);
$secure("$sbx/agent/config", 0750);
$token = 'zv-test-' . bin2hex(random_bytes(24));
$writeCfg = static function (string $mode) use ($sbx, $installed, $cfgKeys, $writeRegular): void {
    $c = array_intersect_key($installed, array_flip($cfgKeys));
    $c['fencing_mode'] = $mode;
    $c['api_allowed_ips'] = [];
    $c['token_file'] = "$sbx/agent/config/api_tokens.php";
    $writeRegular("$sbx/agent/config/api_config.php", "<?php\nreturn " . var_export($c, true) . ";\n");
};
$writeRegular("$sbx/agent/config/api_tokens.php", "<?php\nreturn " . var_export([$token], true) . ";\n");
$links = trim(sh('find ' . escapeshellarg($sbx) . ' -type l'));
$modes = trim(sh('find ' . escapeshellarg($sbx) . ' \( -type d ! -perm 0750 \) -o \( -type f ! -perm 0640 \) -o ! -user root -o ! -group www-data'));
t('Z00a Sandbox: keine Symlinks, nur root:www-data mit 0750/0640, Konfiguration und Token als reguläre Dateien', $links === '' && $modes === ''
    && is_file("$sbx/agent/config/api_tokens.php") && !is_link("$sbx/agent/config/api_tokens.php"), "$links $modes");

// Kein Harness-Eingriff in die Abhängigkeiten des Endpunkts
$own = array_filter(file(__FILE__) ?: [], static fn (string $l): bool => !str_contains($l, 'Z00') && !str_contains($l, 'preg_match'));
$bad = array_filter($own, static fn (string $l): bool => preg_match('/^\s*function\s+safeZoneName\b/', $l) === 1
    || preg_match('/\b(require|include)(_once)?\b[^;]*agent\/(inc|api)\//', $l) === 1);
t('Z00 Harness definiert kein safeZoneName() und bindet keine Agent-Datei ein', $bad === [], implode(' | ', array_map('trim', $bad)));

// Zone: angegeben oder erste verwaltete Zone aus zones.conf mit vorhandener db.<zone>
$zones = [];
foreach (glob("$zd/conf/*.conf") ?: [] as $f) {
    $n = basename($f, '.conf');
    if (is_file("$zd/db.$n")) {
        $zones[] = $n;
    }
}
sort($zones);
$zone = $zoneArg ?? ($zones[0] ?? null);
if ($zone === null) {
    fwrite(STDERR, "ABBRUCH: keine verwaltete Zone gefunden\n");
    exit(2);
}
echo "Endpunkt-Test zone_verify – Checkout $root, Zone $zone\n";

$treeSum = static fn (): string => trim(sh('cd ' . escapeshellarg($zd) . ' && find . -type f -print0 | sort -z | xargs -0 -r sha256sum | sha256sum') . sh('stat -c "%Y %s" ' . escapeshellarg("$zd/.dnsmanager-agent.lock") . ' 2>/dev/null'));
$before = $treeSum();
$faultBefore = file_exists("$zd/.dnsmanager-fence.fault");

/** Endpunkt als www-data in eigenem php-cgi-Prozess aufrufen ⇒ [HTTP-Status, Body, Stderr] */
$call = static function (array $payload) use ($sbx, $token): array {
    $body = json_encode($payload);
    $script = "$sbx/agent/api/v1/zones/zone_verify.php";
    $env = ['REDIRECT_STATUS=200', 'REQUEST_METHOD=POST', 'CONTENT_TYPE=application/json', 'CONTENT_LENGTH=' . strlen($body),
        "SCRIPT_FILENAME=$script", 'SCRIPT_NAME=/api/v1/zones/zone_verify.php', 'REQUEST_URI=/api/v1/zones/zone_verify.php',
        'GATEWAY_INTERFACE=CGI/1.1', 'SERVER_PROTOCOL=HTTP/1.1', 'REMOTE_ADDR=127.0.0.1', 'HTTPS=on',
        "HTTP_AUTHORIZATION=Bearer $token", 'PATH=/usr/sbin:/usr/bin:/sbin:/bin'];
    $cmd = 'cd / && /usr/bin/sudo -u www-data /usr/bin/env -i ' . implode(' ', array_map('escapeshellarg', $env))
        . ' /usr/bin/php-cgi -d auto_prepend_file= -d auto_append_file= -d display_errors=0 -d log_errors=1 -d error_log=/dev/stderr';
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    [$head, $content] = array_pad(explode("\r\n\r\n", (string)$out, 2), 2, '');
    $code = preg_match('/^Status:\s*(\d{3})/mi', $head, $m) === 1 ? (int)$m[1] : 200;
    return [$code, $content, (string)$err];
};

// observe: Beobachtung der Zone
$writeCfg('observe');
[$code, $body, $err] = $call(['zone_name' => $zone]);
$j = json_decode($body, true);
$v = is_array($j) ? ($j['verification'] ?? null) : null;
t('Z01 HTTP 200, gültiges JSON mit status success', $code === 200 && is_array($j) && ($j['status'] ?? null) === 'success', "HTTP $code " . substr($body, 0, 160));
t('Z02 kein PHP-Fehler im Endpunkt (insbesondere kein undefined function safeZoneName)', !preg_match('/PHP (Fatal|Warning|Notice|Deprecated)|undefined function/i', $err . $body),
    substr(trim($err), 0, 200));
$fields = ['state', 'reason', 'zone', 'file_serial', 'file_sha256', 'served_serial'];
t('Z03 Verifikationsobjekt mit vollständigem Grundschema (6 Felder vorhanden)', is_array($v) && array_diff($fields, array_keys($v)) === [], json_encode(is_array($v) ? array_keys($v) : $v));
t('Z04 Verifikationslogik erreicht: eine Runde, Konfigurationsnachweis ermittelt, angefragte Zone', is_array($v) && ($v['rounds'] ?? null) === 1
    && ($v['config_state'] ?? null) !== null && ($v['zone'] ?? null) === $zone, json_encode(array_intersect_key((array)$v, array_flip(['rounds', 'config_state', 'zone', 'state', 'reason']))));
$fileHash = hash_file('sha256', "$zd/db.$zone");
t('Z05 verified: Datei-Serial = ausgelieferte Serial, Hash = verwaltete Datei', is_array($v) && $v['state'] === 'verified' && $v['file_serial'] === $v['served_serial']
    && is_int($v['file_serial']) && $v['file_sha256'] === $fileHash, json_encode(array_intersect_key((array)$v, array_flip(['state', 'reason', 'file_serial', 'served_serial']))));
require_once "$root/web/inc/app/publish/AgentResponseParser.php";
$parsed = AgentResponseParser::parseZoneVerify(['body' => $body, 'httpCode' => $code, 'curlError' => '', 'curlErrno' => 0], $zone, $why);
t('Z06 Core übernimmt die Antwort als gültige Beobachtung (parseZoneVerify)', is_array($parsed) && $why === null && $parsed['state'] === ($v['state'] ?? null), json_encode([$parsed, $why]));
// ungültiger Zonenname ⇒ 400, Abbruch vor jeder Beobachtung
[$code, $body] = $call(['zone_name' => 'ungültig..zone']);
t('Z07 ungültiger Zonenname ⇒ 400 zone_name_invalid', $code === 400 && (json_decode($body, true)['code'] ?? null) === 'zone_name_invalid', "HTTP $code");
// off ⇒ 409 verification_disabled
$writeCfg('off');
[$code, $body] = $call(['zone_name' => $zone]);
t('Z08 Modus off ⇒ 409 verification_disabled', $code === 409 && (json_decode($body, true)['code'] ?? null) === 'verification_disabled', "HTTP $code");
// rein lesend
t('Z09 Zonenverzeichnis (Dateien inkl. H-Datei, Sperrdatei) byte-gleich, keine Fehlermarke entstanden', $treeSum() === $before
    && file_exists("$zd/.dnsmanager-fence.fault") === $faultBefore);

echo "ERGEBNIS as4b_zone_verify: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
