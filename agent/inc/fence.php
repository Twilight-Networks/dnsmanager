<?php
/**
 * Datei: inc/fence.php
 * Zweck: Fencing des Agenten (Meilenstein AS, Arbeitspaket AS1) – Höchststand H je Agent, Fencing-Domäne,
 *        Modi off/observe/enforce, dauerhafte Persistenz mit Write-ahead-Fehlermarke.
 *
 * Invarianten (docs/ARCHITECTURE_HISTORY.md, Abschnitte 11 und 5e):
 * - F1/F2: ein Höchststand H für alle schreibenden Endpunkte (zone_sync, conf_sync, zone_cleanup); Annahme ⇔ t ≥ H,
 *   danach H := t. H wird nie gesenkt; kein API-Weg setzt H zurück (Initialisierung/Erhöhung nur lokal per CLI).
 * - F3: Prüfung und Fortschreiben ausschließlich unter dem exklusiven Agent-flock, vor jeder Änderung.
 * - F4: H liegt in <zone_data_dir>/.dnsmanager-fence ("dnsmanager-fence v1 domain=<uuid> high=<n>").
 * - E90 (Write-ahead-Fault-Latch): Bevor H geändert wird, wird die Fehlermarke <zone_data_dir>/.dnsmanager-fence.fault
 *   dauerhaft angelegt (Datei-fsync, Verzeichnis-fsync). Erst nach vollständig erfolgreicher Persistenz des neuen
 *   Werts (Temp-Datei, fsync, rename, Verzeichnis-fsync) wird sie entfernt (unlink, Verzeichnis-fsync). Jede
 *   vorhandene Marke – auch nach Absturz oder fsync-Fehler – sperrt im Modus enforce alle Schreibvorgänge, bis ein
 *   Administrator sie lokal per CLI nach Prüfung von H aufhebt. Damit ist jeder Zustand, in dem der Seitencache von
 *   der Platte abweichen könnte, durch eine dauerhafte Marke abgedeckt.
 * - E74: Der Agent prüft selbst die lokal konfigurierte Domäne (fence_domain): Anfrage-Domäne und gespeicherte
 *   Domäne müssen ihr entsprechen. Schutzgrenze: Ein vollständiger Installationsklon (Datenbank, Web-Konfiguration,
 *   Agent-Konfiguration und H-Datei) ist davon nicht unterscheidbar (siehe Abschnitt 5e).
 *
 * Modi (api_config.php 'fencing_mode'): fehlt ⇒ off (keine Fencing-Prüfung, H wird weder gelesen noch geschrieben);
 * observe (prüfen, H fortschreiben, Fencing-Gründe nicht ablehnen, Ergebnis in der Antwort); enforce (fail-closed).
 * Ein unbekannter Wert gilt als Fehlkonfiguration und wird wie enforce mit fence_misconfigured behandelt.
 * E97 (Klarstellung zu E90/E95): Die Modi steuern nur die Fencing-PRÜFUNG, nie die Persistenz- und
 * Integritätssicherung. In JEDEM Modus – auch off – sperrt eine vorhandene Fehlermarke alle drei schreibenden
 * Endpunkte (fence_fault); in observe und enforce brechen außerdem ein unlesbarer bzw. ungültiger Höchststand
 * (fence_invalid) und ein gescheitertes dauerhaftes Fortschreiben von H (fence_persist_failed) ohne Änderung ab. fsync-Fehler der Bestandsdateien führen modusunabhängig in die Fehlerpfade der Endpunkte.
 *
 * Die Datei definiert ausschließlich Funktionen und Konstanten (kein Seiteneffekt beim Einbinden; sie liegt im per
 * Apache erreichbaren Agent-Baum).
 */

require_once __DIR__ . '/file_utils.php';

const AGENT_FENCE_FILE = '.dnsmanager-fence';
const AGENT_FENCE_LATCH = '.dnsmanager-fence.fault';
const AGENT_FENCE_MODES = ['off', 'observe', 'enforce'];
const AGENT_FENCE_MAX_BYTES = 256;
/** E97: Integritätsfehler – brechen in jedem Modus ab, in dem H verwendet wird (fence_fault auch in off) */
const AGENT_FENCE_INTEGRITY_CODES = ['fence_fault', 'fence_invalid', 'fence_persist_failed'];

/**
 * Wirksamer Modus: off | observe | enforce | invalid (unbekannter Wert, fail-closed).
 */
function fenceMode(array $config): string {
    if (!array_key_exists('fencing_mode', $config)) {
        return 'off';
    }
    $m = $config['fencing_mode'];

    return is_string($m) && in_array($m, AGENT_FENCE_MODES, true) ? $m : 'invalid';
}

/** Kanonische Domäne: UUID in Kleinbuchstaben. */
function fenceIsDomain(mixed $v): bool {
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $v) === 1;
}

/** Lokal konfigurierte Domäne oder null, wenn sie fehlt bzw. ungültig ist. */
function fenceConfiguredDomain(array $config): ?string {
    $d = $config['fence_domain'] ?? null;

    return fenceIsDomain($d) ? $d : null;
}

/**
 * Fencing-Token aus der Anfrage: positive Ganzzahl ≤ PHP_INT_MAX als JSON-Zahl oder Dezimalzeichenkette ohne
 * führende Null. Alles andere ⇒ null.
 */
function fenceParseToken(mixed $v): ?int {
    if (is_int($v)) {
        return $v >= 1 ? $v : null;
    }
    if (is_string($v) && preg_match('/^[1-9][0-9]{0,18}$/D', $v) === 1) {
        $i = filter_var($v, FILTER_VALIDATE_INT);

        return is_int($i) && (string)$i === $v ? $i : null;
    }

    return null;
}

/** @return array{dir: string, high: string, latch: string} */
function fencePaths(array $config): array {
    $dir = rtrim((string)$config['zone_data_dir'], '/');

    return ['dir' => $dir, 'high' => "$dir/" . AGENT_FENCE_FILE, 'latch' => "$dir/" . AGENT_FENCE_LATCH];
}

/** Dateiinhalt von H (eine Zeile, LF). */
function fenceFormat(string $domain, int $high): string {
    return "dnsmanager-fence v1 domain=$domain high=$high\n";
}

/**
 * Existiert die Fehlermarke (auch als defekter Eintrag oder Symlink)? Ist ihre Existenz nicht prüfbar (stat/access-Fehler
 * außer ENOENT), gilt sie als vorhanden (E99, fail-closed).
 */
function fenceLatchPresent(array $config): bool {
    return pathState(fencePaths($config)['latch']) !== 'absent';
}

/**
 * Liest H ohne Seiteneffekt.
 *
 * @return array{state: string, domain: ?string, high: ?int, detail: ?string}
 *         state: ok | missing | invalid
 */
function fenceReadHigh(array $config): array {
    $path = fencePaths($config)['high'];
    $exists = pathState($path);   // E99: nur ENOENT bedeutet "fehlt"; ein Prüffehler ist ein Integritätsfehler
    if ($exists === 'absent') {
        return ['state' => 'missing', 'domain' => null, 'high' => null, 'detail' => null];
    }
    if ($exists === 'error') {
        return ['state' => 'invalid', 'domain' => null, 'high' => null, 'detail' => 'Existenz nicht prüfbar'];
    }
    if (is_link($path) || !is_file($path)) {
        return ['state' => 'invalid', 'domain' => null, 'high' => null, 'detail' => 'keine reguläre Datei'];
    }
    $raw = @file_get_contents($path, false, null, 0, AGENT_FENCE_MAX_BYTES + 1);
    if (!is_string($raw)) {
        return ['state' => 'invalid', 'domain' => null, 'high' => null, 'detail' => 'nicht lesbar'];
    }
    if (preg_match('/^dnsmanager-fence v1 domain=([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}) high=([1-9][0-9]{0,18})\n$/D', $raw, $m) !== 1) {
        return ['state' => 'invalid', 'domain' => null, 'high' => null, 'detail' => 'ungültiges Format'];
    }
    $high = fenceParseToken($m[2]);
    if ($high === null) {
        return ['state' => 'invalid', 'domain' => null, 'high' => null, 'detail' => 'Wert außerhalb des Bereichs'];
    }

    return ['state' => 'ok', 'domain' => $m[1], 'high' => $high, 'detail' => null];
}

/**
 * Schreibt H dauerhaft nach dem Write-ahead-Fault-Latch-Protokoll (E90). Aufruf nur unter dem Agent-flock und nur,
 * wenn keine Fehlermarke vorhanden ist.
 *
 * Schritte: Marke anlegen + fsync → Verzeichnis-fsync → Temp-Datei mit neuem H + fsync → rename → Verzeichnis-fsync
 * → Marke entfernen → Verzeichnis-fsync. Bei jedem Fehler wird abgebrochen; eine angelegte Marke bleibt bestehen
 * (fail-closed). Ausnahme: Scheitert schon das Anlegen der Marke (keine Datei entstanden), gibt es keine Marke.
 *
 * @return array{ok: bool, step: ?string}
 */
function fencePersistHigh(array $config, string $domain, int $high): array {
    $p = fencePaths($config);
    $latch = sprintf("dnsmanager-fence-fault v1 pending_high=%d pid=%d time=%s\n", $high, getmypid(), gmdate('Y-m-d\TH:i:s\Z'));

    // 1. Write-ahead: Fehlermarke dauerhaft anlegen, bevor H verändert wird
    if (!writeFileDurable($p['latch'], $latch)) {
        return ['ok' => false, 'step' => 'latch_write'];
    }
    if (!syncDirectory($p['dir'])) {
        return ['ok' => false, 'step' => 'latch_dir_sync'];
    }

    // 2. Neuen Wert dauerhaft schreiben
    try {
        $tmp = $p['high'] . '.' . bin2hex(random_bytes(8)) . '.tmp';
    } catch (Throwable $e) {
        return ['ok' => false, 'step' => 'high_tmp_name'];
    }
    if (!writeFileDurable($tmp, fenceFormat($domain, $high))) {
        @unlink($tmp);
        return ['ok' => false, 'step' => 'high_write'];
    }
    if (!@rename($tmp, $p['high'])) {
        @unlink($tmp);
        return ['ok' => false, 'step' => 'high_rename'];
    }
    if (!syncDirectory($p['dir'])) {
        return ['ok' => false, 'step' => 'high_dir_sync'];
    }

    // 3. Erst nach vollständig erfolgreicher Persistenz: Marke entfernen und dauerhaft machen
    if (!@unlink($p['latch'])) {
        return ['ok' => false, 'step' => 'latch_unlink'];
    }
    if (!syncDirectory($p['dir'])) {
        return ['ok' => false, 'step' => 'latch_unlink_dir_sync'];
    }

    return ['ok' => true, 'step' => null];
}

/**
 * Bewertet eine schreibende Anfrage ohne zu schreiben.
 *
 * @return array{code: ?string, http: int, message: ?string, token: ?int, high: ?int, raise: bool}
 */
function fenceEvaluate(array $config, string $mode, mixed $input): array {
    $r = ['code' => null, 'http' => 200, 'message' => null, 'token' => null, 'high' => null, 'raise' => false];
    $fail = static function (string $code, int $http, string $message) use (&$r): array {
        $r['code'] = $code;
        $r['http'] = $http;
        $r['message'] = $message;
        return $r;
    };

    $domain = fenceConfiguredDomain($config);
    if ($mode === 'invalid') {
        return $fail('fence_misconfigured', 503, 'Fencing: unbekannter fencing_mode in der Agent-Konfiguration');
    }
    if ($domain === null) {
        return $fail('fence_misconfigured', 503, 'Fencing: fence_domain fehlt oder ist ungültig');
    }
    if (fenceLatchPresent($config)) {
        return $fail('fence_fault', 503, 'Fencing: Fehlermarke vorhanden – Schreibvorgänge gesperrt bis zur lokalen Prüfung (CLI clear-fault)');
    }

    $in = is_array($input) ? $input : [];
    if (!array_key_exists('fence_token', $in) || !array_key_exists('fence_domain', $in)) {
        return $fail('fence_token_missing', 400, 'Fencing: fence_token und fence_domain erforderlich');
    }
    $token = fenceParseToken($in['fence_token']);
    if ($token === null || !fenceIsDomain($in['fence_domain'])) {
        return $fail('fence_token_invalid', 400, 'Fencing: fence_token oder fence_domain ungültig');
    }
    $r['token'] = $token;
    if (!hash_equals($domain, $in['fence_domain'])) {
        return $fail('fence_domain_mismatch', 409, 'Fencing: Anfrage gehört zu einer anderen Fencing-Domäne');
    }

    $h = fenceReadHigh($config);
    if ($h['state'] === 'missing') {
        return $fail('fence_uninitialized', 409, 'Fencing: Höchststand nicht initialisiert (CLI init)');
    }
    if ($h['state'] !== 'ok') {
        return $fail('fence_invalid', 503, 'Fencing: gespeicherter Höchststand ungültig (' . $h['detail'] . ')');
    }
    $r['high'] = $h['high'];
    if (!hash_equals($domain, (string)$h['domain'])) {
        return $fail('fence_domain_mismatch', 409, 'Fencing: gespeicherter Höchststand gehört zu einer anderen Fencing-Domäne');
    }
    if ($token < $h['high']) {
        return $fail('stale_fence', 409, "Fencing: veraltetes Token $token < Höchststand {$h['high']}");
    }
    $r['raise'] = $token > $h['high'];

    return $r;
}

/**
 * Fencing-Prüfung einer schreibenden Anfrage. Aufruf ausschließlich unter dem exklusiven Agent-flock und vor jeder
 * Änderung an verwalteten Dateien (F3). Fügt in observe/enforce den Bericht 'fence' allen folgenden Antworten hinzu.
 *
 * @param mixed $input dekodierter JSON-Body der Anfrage
 *
 * @return array{allow: bool, http: int, body: ?array}
 */
function agentFenceCheck(array $config, mixed $input): array {
    $mode = fenceMode($config);

    // E97: Fehlermarke sperrt in jedem Modus (auch off) – Integritätssicherung, keine Fencing-Prüfung
    if (fenceLatchPresent($config)) {
        $report = ['mode' => $mode, 'decision' => 'reject', 'code' => 'fence_fault', 'token' => null,
            'high_before' => null, 'high_after' => null];
        error_log(sprintf('[fence] mode=%s decision=reject code=fence_fault', $mode));
        agentResponseAttach('fence', $report);
        return ['allow' => false, 'http' => 503, 'body' => [
            'status'  => 'error',
            'message' => 'Fencing: Fehlermarke vorhanden oder nicht prüfbar – Schreibvorgänge gesperrt bis zur lokalen Prüfung (CLI clear-fault)',
            'code'    => 'fence_fault',
        ]];
    }

    if ($mode === 'off') {
        return ['allow' => true, 'http' => 200, 'body' => null];
    }

    $e = fenceEvaluate($config, $mode, $input);
    $report = [
        'mode'        => $mode,
        'decision'    => 'accept',
        'code'        => $e['code'],
        'token'       => $e['token'],
        'high_before' => $e['high'],
        'high_after'  => $e['high'],
    ];

    $persistFailed = false;
    if ($e['code'] === null && $e['raise']) {
        $persist = fencePersistHigh($config, (string)fenceConfiguredDomain($config), (int)$e['token']);
        if ($persist['ok']) {
            $report['high_after'] = $e['token'];
        } else {
            $e['code'] = 'fence_persist_failed';
            $e['http'] = 503;
            $e['message'] = 'Fencing: Höchststand konnte nicht dauerhaft gespeichert werden (Schritt ' . $persist['step'] . ') – keine Änderung';
            $report['code'] = $e['code'];
            $report['step'] = $persist['step'];
            $persistFailed = true;
        }
    }

    // observe lehnt Fencing-Gründe (Token, Domäne, Höchststand, fehlende Initialisierung, Konfiguration) nicht ab.
    // Integritätsfehler des gespeicherten Zustands brechen dagegen in jedem Modus ab (E97): Fehlermarke, unlesbarer bzw.
    // ungültiger Höchststand, gescheitertes dauerhaftes Fortschreiben.
    $integrity = $persistFailed || in_array($e['code'], AGENT_FENCE_INTEGRITY_CODES, true);
    $reject = $e['code'] !== null && ($mode !== 'observe' || $integrity);
    if ($e['code'] !== null) {
        $report['decision'] = $reject ? 'reject' : 'would_reject';
        error_log(sprintf('[fence] mode=%s decision=%s code=%s token=%s high=%s', $mode, $report['decision'], $e['code'],
            $e['token'] ?? '-', $e['high'] ?? '-'));
    }
    agentResponseAttach('fence', $report);

    if ($reject) {
        return ['allow' => false, 'http' => $e['http'], 'body' => [
            'status'  => 'error',
            'message' => $e['message'],
            'code'    => $e['code'],
        ]];
    }

    return ['allow' => true, 'http' => 200, 'body' => null];
}

/**
 * Fencing-Zustand für system/status (ohne Seiteneffekt, ohne Lock). Im Modus off null (Antwort unverändert), außer
 * eine Fehlermarke sperrt die Schreibvorgänge (E97): dann state = fault.
 *
 * @return array{mode: string, domain: ?string, state: string, high: ?int, fault: bool}|null
 */
function agentFenceStatus(array $config): ?array {
    $mode = fenceMode($config);
    $fault = fenceLatchPresent($config);
    if ($mode === 'off' && !$fault) {
        return null;
    }
    $domain = fenceConfiguredDomain($config);
    $h = fenceReadHigh($config);
    $state = match (true) {
        $fault                                  => 'fault',
        $mode === 'invalid' || $domain === null => 'misconfigured',
        $h['state'] === 'missing'               => 'uninitialized',
        $h['state'] !== 'ok'                    => 'invalid',
        $h['domain'] !== $domain                => 'domain_mismatch',
        default                                 => 'ok',
    };

    return ['mode' => $mode, 'domain' => $domain, 'state' => $state, 'high' => $h['high'], 'fault' => $fault];
}
