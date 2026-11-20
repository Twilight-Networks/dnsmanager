<?php
/**
 * Datei: inc/dns_state.php
 * Zweck: Gemeinsamer technischer Zustandsbaustein des Agenten (AS2a; docs/ARCHITECTURE_HISTORY.md Abschnitt 5f).
 *        AS2b (inc/dns_verify.php) verwendet ausschließlich diese Bausteine (Polling, Nachverifikation,
 *        Cleanup-Nachweise); ergänzt ist nur der optionale Rundenspeicher der Kontrollzone in dnsServedState().
 *
 * Bausteine:
 * - DNS-Abfrage und strenger Parser (dnsQuery/dnsParseDig): dig +norec +time=1 +tries=1 +nocookie an 127.0.0.1;
 *   ausgewertet werden Status, Flags, Antwort- und Authority-Sektion, nie der Exit-Code allein (E91).
 * - Konfigurationsnachweis K (dnsConfigProof/dnsZoneConfigState): named-checkconf -l (Zonenliste) und -px (Struktur,
 *   Schlüssel maskiert; -p ohne -x wird nie verwendet); globale Voraussetzungen (keine Views, kein allow-new-zones,
 *   keine catalog-zones/dlz/in-view, keine NZD/NZF-Dateien im directory bzw. new-zones-directory, Zonenzahl aus
 *   rndc status als schwacher Widerspruchsindikator); je Zone genau eine Zone IN/_default vom Typ master/primary,
 *   nur mit type und file, Dateipfad eindeutig = verwaltete Datei.
 * - Ausgelieferter Zustand D (dnsServedState): served(s) | not_auth | unknown.
 * - Kontrollzone (dnsControlZoneState): Betriebsvoraussetzung für REFUSED ohne konfigurierte Elternzone.
 * - Dateizustand (dnsZoneFileState) und Vorher-Prüfung nach E103 (dnsPreTransferCheck).
 *
 * Der Agent liefert technische Fakten und setzt nur die definierten Sicherheitsinvarianten durch (I-A1, E80); die
 * fachliche Bewertung (Serial-Wahl E78, Reparaturfreigabe, Veröffentlichungsstand) liegt im Core.
 * Ausgaben von named-checkconf werden weder gespeichert noch protokolliert (nur extrahierte Felder).
 *
 * Nur in den Modi observe/enforce verwendet (E101). Die Datei definiert ausschließlich Funktionen und Konstanten.
 */

require_once __DIR__ . '/file_utils.php';
require_once __DIR__ . '/proc.php';
require_once __DIR__ . '/serial.php';

/** sudo (absoluter Pfad; sudoers erlaubt nur rndc reload und rndc status) */
const AGENT_SUDO = '/usr/bin/sudo';
/** Abfrageadresse des lokalen BIND (E17) */
const DNS_QUERY_ADDRESS = '127.0.0.1';
/** Verwalteter Konfigurationskontext */
const DNS_SUPPORTED_VIEW = '_default';
const DNS_SUPPORTED_TYPES = ['master', 'primary'];
/** Zulässige Optionen einer verwalteten Zonenanweisung (Format des Generators, writeZoneConfFile) */
const DNS_MANAGED_ZONE_OPTIONS = ['type', 'file'];
/** Ergebnisse von dnsControlZoneState(), denen eine DNS-Abfrage der Kontrollzone zugrunde liegt (AS2b, Zählung) */
const DNS_CONTROL_QUERIED_STATES = ['ok', 'not_served'];

/** Normalisierter Zonenname (Kleinbuchstaben, ohne abschließenden Punkt); null bei unzulässiger Form. */
function dnsNormName(mixed $name): ?string {
    if (!is_string($name)) {
        return null;
    }
    $n = strtolower(rtrim($name, '.'));
    if ($n === '' || strlen($n) > 253 || preg_match('/^[a-z0-9_](?:[a-z0-9_.-]*[a-z0-9_])?$/D', $n) !== 1 || str_contains($n, '..')) {
        return null;
    }

    return $n;
}

/** Ist $child echt unterhalb von $parent? (normalisierte Namen; Wurzel "" ist Vorfahr aller Namen) */
function dnsIsBelow(string $child, string $parent): bool {
    if ($parent === '') {
        return $child !== '';
    }

    return $child !== $parent && str_ends_with($child, '.' . $parent);
}

// ------------------------------------------------------------------------------------------------------------------
// DNS-Abfrage und strenger Parser
// ------------------------------------------------------------------------------------------------------------------

/**
 * Wertet die Ausgabe von dig (+noall +comments +answer +authority) streng aus.
 *
 * @return array{kind: string, rcode: ?string, aa: bool, answer: array, authority: array, reason: ?string}
 *         kind: response | timeout | conn_refused | error
 *         answer/authority: Liste von [owner (normalisiert), type, rdata-Felder[]]
 */
function dnsParseDig(string $out, string $procStatus = 'ok'): array {
    $res = ['kind' => 'error', 'rcode' => null, 'aa' => false, 'answer' => [], 'authority' => [], 'reason' => null];
    if ($procStatus === 'timeout') {
        $res['kind'] = 'timeout';
        $res['reason'] = 'process_timeout';
        return $res;
    }
    $headers = preg_match_all('/^;; ->>HEADER<<- opcode: ([A-Z]+), status: ([A-Z]+), id: [0-9]+$/m', $out, $h);
    if ($headers === 0) {
        if (preg_match('/^;; communications error to \S+: timed out$/m', $out) === 1 || preg_match('/connection timed out; no servers could be reached/', $out) === 1) {
            $res['kind'] = 'timeout';
            $res['reason'] = 'dns_timeout';
        } elseif (preg_match('/connection refused/', $out) === 1) {
            $res['kind'] = 'conn_refused';
            $res['reason'] = 'connection_refused';
        } else {
            $res['reason'] = 'no_header';
        }
        return $res;
    }
    if ($headers !== 1 || $h[1][0] !== 'QUERY') {
        $res['reason'] = 'header_ambiguous';
        return $res;
    }
    if (preg_match_all('/^;; flags:([a-z ]*); QUERY: ([0-9]+), ANSWER: ([0-9]+), AUTHORITY: ([0-9]+), ADDITIONAL: ([0-9]+)$/m', $out, $f) !== 1) {
        $res['reason'] = 'flags_missing';
        return $res;
    }
    $flags = preg_split('/\s+/', trim($f[1][0]), -1, PREG_SPLIT_NO_EMPTY);
    if (!in_array('qr', $flags, true) || (int)$f[2][0] !== 1) {
        $res['reason'] = 'flags_invalid';
        return $res;
    }

    $sections = ['answer' => [], 'authority' => []];
    $current = null;
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if ($line === ';; ANSWER SECTION:') {
            $current = 'answer';
            continue;
        }
        if ($line === ';; AUTHORITY SECTION:') {
            $current = 'authority';
            continue;
        }
        if (str_starts_with($line, ';;')) {
            $current = null;
            continue;
        }
        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }
        if ($current === null) {
            $res['reason'] = 'unexpected_line';
            return $res;
        }
        $p = preg_split('/\s+/', trim($line));
        if (count($p) < 5 || $p[2] !== 'IN' || preg_match('/^[0-9]+$/D', $p[1]) !== 1) {
            $res['reason'] = 'rr_unparsable';
            return $res;
        }
        $owner = $p[0] === '.' ? '' : dnsNormName($p[0]);
        if ($owner === null) {
            $res['reason'] = 'rr_owner_invalid';
            return $res;
        }
        $sections[$current][] = [$owner, strtoupper($p[3]), array_slice($p, 4)];
    }
    if (count($sections['answer']) !== (int)$f[3][0] || count($sections['authority']) !== (int)$f[4][0]) {
        $res['reason'] = 'section_count_mismatch';
        return $res;
    }

    return ['kind' => 'response', 'rcode' => $h[2][0], 'aa' => in_array('aa', $flags, true),
        'answer' => $sections['answer'], 'authority' => $sections['authority'], 'reason' => null];
}

/** Eine SOA-Abfrage an den lokalen BIND. */
function dnsQuery(array $config, string $name): array {
    $dig = (string)($config['bind_dig'] ?? '/usr/bin/dig');
    $p = agentRunProcess([$dig, '+norec', '+time=1', '+tries=1', '+nocookie', '+noall', '+comments', '+answer',
        '+authority', '@' . DNS_QUERY_ADDRESS, $name === '' ? '.' : $name, 'SOA'], AGENT_CAP_DIG);
    if ($p['status'] === 'not_started' || $p['status'] === 'error') {
        return ['kind' => 'error', 'rcode' => null, 'aa' => false, 'answer' => [], 'authority' => [], 'reason' => 'dig_' . $p['status']];
    }

    return dnsParseDig($p['output'], $p['status']);
}

/** SOA-Serial aus den rdata-Feldern (mname rname serial refresh retry expire minimum) oder null. */
function dnsSoaSerial(array $rdata): ?int {
    return count($rdata) === 7 ? serialParse($rdata[2]) : null;
}

/** Autoritative SOA genau für $zone? Liefert die Serial oder null. */
function dnsAuthoritativeSoa(array $r, string $zone): ?int {
    if ($r['kind'] !== 'response' || $r['rcode'] !== 'NOERROR' || !$r['aa'] || count($r['answer']) !== 1) {
        return null;
    }
    [$owner, $type, $rdata] = $r['answer'][0];

    return $owner === $zone && $type === 'SOA' ? dnsSoaSerial($rdata) : null;
}

// ------------------------------------------------------------------------------------------------------------------
// Konfigurationsnachweis K
// ------------------------------------------------------------------------------------------------------------------

/**
 * Zerlegt die Ausgabe von named-checkconf -px in Anweisungen der obersten Ebene.
 *
 * @return array{ok: bool, options: array<string, string[]>, zones: array<int, array>, top: string[]}
 *         options: Schlüsselwort ⇒ Werte (erste Ebene des options-Blocks)
 *         zones: [name (normalisiert), Klasse, Optionen (Schlüsselwort ⇒ Werte)]
 *         top: Schlüsselwörter aller Anweisungen der obersten Ebene
 */
function dnsParseCheckconf(string $px): array {
    $res = ['ok' => false, 'options' => [], 'zones' => [], 'top' => []];
    $depth = 0;
    $stmt = null;   // ['kind' => …, 'name' => …, 'class' => …, 'opts' => []]
    foreach (preg_split('/\r?\n/', $px) as $line) {
        if (trim($line) === '') {
            continue;
        }
        // Klammern außerhalb von Zeichenketten zählen
        $plain = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $line);
        $open = substr_count($plain, '{');
        $close = substr_count($plain, '}');
        if ($depth === 0) {
            if (preg_match('/^([a-z-]+)\b/', $line, $m) !== 1) {
                return $res;
            }
            $res['top'][] = $m[1];
            $stmt = ['kind' => $m[1], 'name' => null, 'class' => 'IN', 'opts' => []];
            if ($m[1] === 'zone') {
                if (preg_match('/^zone "((?:[^"\\\\]|\\\\.)*)"(?: ([A-Za-z]+))? \{$/', $line, $z) !== 1) {
                    return $res;
                }
                $stmt['name'] = stripcslashes($z[1]);
                $stmt['class'] = strtoupper($z[2] ?? 'IN');
            }
        } elseif ($depth === 1 && $stmt !== null) {
            if (preg_match('/^\s+([a-z0-9-]+)(?:\s+(.*?))?;?$/', $line, $o) === 1) {
                $val = $o[2] ?? '';
                if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)";?$/', rtrim($val, ' '), $q) === 1) {
                    $val = stripcslashes($q[1]);
                } else {
                    $val = rtrim($val, ' ;');
                }
                $stmt['opts'][$o[1]][] = $val;
            }
        }
        $depth += $open - $close;
        if ($depth < 0) {
            return $res;
        }
        if ($depth === 0 && $stmt !== null) {
            if ($stmt['kind'] === 'options') {
                $res['options'] = $stmt['opts'];
            } elseif ($stmt['kind'] === 'zone') {
                $res['zones'][] = [$stmt['name'] === '.' ? '' : dnsNormName($stmt['name']), $stmt['class'], $stmt['opts']];
            }
            $stmt = null;
        }
    }
    $res['ok'] = $depth === 0 && $stmt === null;

    return $res;
}

/**
 * Löst einen Pfad aus der BIND-Konfiguration eindeutig auf (absolut bzw. relativ zu directory); Bestandteile "", "."
 * und ".." sind unzulässig (keine mehrdeutige Normalisierung).
 */
function dnsResolveConfPath(string $path, ?string $directory): ?string {
    if ($path === '') {
        return null;
    }
    if ($path[0] !== '/') {
        if ($directory === null || $directory === '' || $directory[0] !== '/') {
            return null;
        }
        $path = rtrim($directory, '/') . '/' . $path;
    }
    $parts = explode('/', substr($path, 1));
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return null;
        }
    }

    return '/' . implode('/', $parts);
}

/**
 * Globaler Konfigurationsnachweis (einmal je Anfrage ermittelt).
 *
 * @return array{state: string, reason: ?string, list: array, zones: array, directory: ?string}
 *         state: ok | undeterminable; list: Zeilen von -l als [name, Klasse, View, Typ]
 */
function dnsConfigProof(array $config, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $fail = static function (string $reason) use (&$cache): array {
        return $cache = ['state' => 'undeterminable', 'reason' => $reason, 'list' => [], 'zones' => [], 'directory' => null];
    };

    $checkconf = (string)($config['bind_named_checkconf'] ?? '/usr/bin/named-checkconf');
    $conf = (string)($config['bind_named_conf_path'] ?? '/etc/bind/named.conf');

    $l = agentRunProcess([$checkconf, '-l', $conf], AGENT_CAP_CHECKCONF);
    if ($l['status'] !== 'ok' || $l['exit_code'] !== 0) {
        return $fail('checkconf_list_' . ($l['status'] === 'ok' ? 'rc' : $l['status']));
    }
    $list = [];
    foreach (preg_split('/\r?\n/', trim($l['output'])) as $line) {
        if ($line === '') {
            continue;
        }
        $p = preg_split('/\s+/', $line);
        if (count($p) !== 4) {
            return $fail('checkconf_list_format');
        }
        $name = $p[0] === '.' ? '' : dnsNormName($p[0]);
        if ($name === null) {
            return $fail('checkconf_list_name');
        }
        $list[] = [$name, strtoupper($p[1]), $p[2], strtolower($p[3])];
    }

    $x = agentRunProcess([$checkconf, '-p', '-x', $conf], AGENT_CAP_CHECKCONF);
    if ($x['status'] !== 'ok' || $x['exit_code'] !== 0) {
        return $fail('checkconf_print_' . ($x['status'] === 'ok' ? 'rc' : $x['status']));
    }
    $px = dnsParseCheckconf($x['output']);
    unset($x);
    if (!$px['ok']) {
        return $fail('checkconf_print_format');
    }

    // Nicht unterstützte Konfigurationskontexte (fail-closed)
    foreach ($list as $e) {
        if ($e[2] !== DNS_SUPPORTED_VIEW) {
            return $fail('views_present');
        }
    }
    if (in_array('view', $px['top'], true)) {
        return $fail('views_present');
    }
    if (in_array('dlz', $px['top'], true)) {
        return $fail('dlz_present');
    }
    if (isset($px['options']['catalog-zones'])) {
        return $fail('catalog_zones_present');
    }
    if (in_array('yes', array_map('strtolower', $px['options']['allow-new-zones'] ?? []), true)
        || in_array('true', array_map('strtolower', $px['options']['allow-new-zones'] ?? []), true)) {
        return $fail('allow_new_zones');
    }
    foreach ($px['zones'] as $z) {
        if (isset($z[2]['in-view'])) {
            return $fail('in_view_present');
        }
    }

    // Persistente dynamische Zonen (NZD/NZF): directory und ggf. new-zones-directory (M5, AS2a-Präzisierung A)
    $dirs = $px['options']['directory'] ?? [];
    if (count($dirs) !== 1 || ($directory = dnsResolveConfPath($dirs[0], null)) === null) {
        return $fail('directory_unresolved');
    }
    $nzdDirs = [$directory];
    foreach ($px['options']['new-zones-directory'] ?? [] as $nd) {
        $r = dnsResolveConfPath($nd, $directory);
        if ($r === null) {
            return $fail('new_zones_directory_unresolved');
        }
        $nzdDirs[] = $r;
    }
    foreach (array_unique($nzdDirs) as $d) {
        $names = @scandir($d);
        if ($names === false) {
            return $fail('nzd_directory_unreadable');
        }
        foreach ($names as $n) {
            if (preg_match('/\.nz[df](?:-lock)?$/D', $n) === 1) {
                return $fail('nzd_present');
            }
        }
    }

    // Zonenzahl: schwacher Widerspruchsindikator (Übereinstimmung ist nie ein Nachweis)
    $rndc = (string)($config['bind_rndc'] ?? '/usr/sbin/rndc');
    $s = agentRunProcess([AGENT_SUDO, '-n', $rndc, 'status'], AGENT_CAP_RNDC_STATUS);
    if ($s['status'] !== 'ok' || $s['exit_code'] !== 0
        || preg_match_all('/^number of zones: ([0-9]+) \(([0-9]+) automatic\)$/m', $s['output'], $zc) !== 1) {
        return $fail('rndc_status_' . ($s['status'] === 'ok' ? 'unparsable' : $s['status']));
    }
    if ((int)$zc[1][0] - (int)$zc[2][0] !== count($list)) {
        return $fail('zone_count_mismatch');
    }

    return $cache = ['state' => 'ok', 'reason' => null, 'list' => $list, 'zones' => $px['zones'], 'directory' => $directory];
}

/** Verwalteter Pfad der Zonendatei (wie zone_sync). */
function dnsManagedZonePath(array $config, string $zoneName): string {
    return rtrim((string)$config['zone_data_dir'], '/') . '/db.' . safeZoneName($zoneName);
}

/**
 * K für eine Zone.
 *
 * @return array{state: string, reason: ?string, ancestor: ?string}  state: yes | no | undeterminable
 */
function dnsZoneConfigState(array $config, string $zone, array $proof): array {
    if ($proof['state'] !== 'ok') {
        return ['state' => 'undeterminable', 'reason' => $proof['reason'], 'ancestor' => null];
    }
    $ancestor = null;
    $entries = [];
    foreach ($proof['list'] as $e) {
        if ($e[0] === $zone) {
            $entries[] = $e;
        } elseif ($e[1] === 'IN' && $e[3] !== 'hint' && dnsIsBelow($zone, $e[0])
            && ($ancestor === null || strlen($e[0]) > strlen($ancestor))) {
            $ancestor = $e[0];
        }
    }
    if ($entries === []) {
        return ['state' => 'no', 'reason' => null, 'ancestor' => $ancestor];
    }
    $u = static fn (string $r): array => ['state' => 'undeterminable', 'reason' => $r, 'ancestor' => $ancestor];
    if (count($entries) !== 1) {
        return $u('zone_ambiguous');
    }
    [, $class, $view, $type] = $entries[0];
    if ($class !== 'IN' || $view !== DNS_SUPPORTED_VIEW || !in_array($type, DNS_SUPPORTED_TYPES, true)) {
        return $u('zone_type_unsupported');
    }
    $stmts = array_values(array_filter($proof['zones'], static fn (array $z): bool => $z[0] === $zone && $z[1] === 'IN'));
    if (count($stmts) !== 1) {
        return $u('zone_statement_ambiguous');
    }
    $opts = $stmts[0][2];
    if (array_diff(array_keys($opts), DNS_MANAGED_ZONE_OPTIONS) !== [] || count($opts['file'] ?? []) !== 1) {
        return $u('zone_options_unsupported');
    }
    $file = dnsResolveConfPath((string)$opts['file'][0], $proof['directory']);
    $managed = dnsManagedZonePath($config, $zone);
    $realDir = realpath(dirname($managed));
    if ($file === null || $file !== $managed || $realDir === false || realpath(dirname($file)) !== $realDir) {
        return $u('zone_file_mismatch');
    }

    return ['state' => 'yes', 'reason' => null, 'ancestor' => $ancestor];
}

// ------------------------------------------------------------------------------------------------------------------
// Kontrollzone und ausgelieferter Zustand D
// ------------------------------------------------------------------------------------------------------------------

/**
 * Prüft die Kontrollzone (Betriebsvoraussetzung, E116).
 *
 * @return array{state: string, zone: ?string}  state: ok | missing | own_acl | managed | not_served | unverifiable
 */
function dnsControlZoneState(array $config, array $proof): array {
    $zone = dnsNormName($config['l3_control_zone'] ?? 'localhost');
    if ($zone === null) {
        return ['state' => 'missing', 'zone' => null];
    }
    if ($proof['state'] !== 'ok') {
        return ['state' => 'unverifiable', 'zone' => $zone];
    }
    $entries = array_values(array_filter($proof['list'], static fn (array $e): bool => $e[0] === $zone));
    $stmts = array_values(array_filter($proof['zones'], static fn (array $z): bool => $z[0] === $zone && $z[1] === 'IN'));
    if (count($entries) !== 1 || $entries[0][1] !== 'IN' || !in_array($entries[0][3], DNS_SUPPORTED_TYPES, true) || count($stmts) !== 1) {
        return ['state' => 'missing', 'zone' => $zone];
    }
    if (isset($stmts[0][2]['allow-query']) || isset($stmts[0][2]['allow-query-on'])) {
        return ['state' => 'own_acl', 'zone' => $zone];
    }
    $confState = pathState(rtrim((string)$config['zone_conf_dir'], '/') . '/' . safeZoneName($zone) . '.conf');
    $file = dnsResolveConfPath((string)($stmts[0][2]['file'][0] ?? ''), $proof['directory']);
    if ($confState !== 'absent' || $file === null || $file === dnsManagedZonePath($config, $zone)) {
        return ['state' => 'managed', 'zone' => $zone];
    }
    $r = dnsQuery($config, $zone);

    return ['state' => dnsAuthoritativeSoa($r, $zone) !== null ? 'ok' : 'not_served', 'zone' => $zone];
}

/**
 * Ausgelieferter Zustand D einer Zone (eine Abfrage, ggf. eine Kontrollabfrage).
 *
 * @param array      $k     Ergebnis von dnsZoneConfigState()
 * @param array|null $round AS2b (E124): Speicher einer Polling-Runde für das Ergebnis der Kontrollzone. Wiederverwendet
 *                          wird es nur innerhalb dieser Runde und nur für denselben Konfigurationsnachweis ($proof);
 *                          ohne Speicher (zone_sync, AS2a) wird die Kontrollzone wie bisher je Aufruf geprüft.
 *
 * @return array{state: string, serial: ?int, reason: ?string, rcode: ?string, control: ?string}
 *         state: served | not_auth | unknown
 */
function dnsServedState(array $config, string $zone, array $k, array $proof, ?array &$round = null): array {
    $out = ['state' => 'unknown', 'serial' => null, 'reason' => null, 'rcode' => null, 'control' => null];
    $r = dnsQuery($config, $zone);
    $out['rcode'] = $r['kind'] === 'response' ? $r['rcode'] : $r['kind'];
    if ($r['kind'] !== 'response') {
        $out['reason'] = $r['reason'];
        return $out;
    }
    $soa = dnsAuthoritativeSoa($r, $zone);
    if ($soa !== null) {
        return ['state' => 'served', 'serial' => $soa] + $out;
    }
    if ($k['state'] !== 'no') {
        $out['reason'] = $k['state'] === 'yes' ? 'configured_not_served' : 'config_undeterminable';
        return $out;
    }

    $parent = $k['ancestor'];
    if ($parent !== null) {
        // Antwort muss aus der nächstgelegenen konfigurierten Elternzone stammen; REFUSED ⇒ unknown (ACL der Elternzone)
        if ($r['answer'] === [] && $r['aa'] && in_array($r['rcode'], ['NXDOMAIN', 'NOERROR'], true)) {
            foreach ($r['authority'] as [$owner, $type]) {
                if ($type === 'SOA' && $owner === $parent) {
                    return ['state' => 'not_auth', 'reason' => 'parent_negative'] + $out;
                }
            }
        }
        if ($r['answer'] === [] && !$r['aa'] && $r['rcode'] === 'NOERROR' && $r['authority'] !== []) {
            $ok = true;
            foreach ($r['authority'] as [$owner, $type]) {
                if ($type !== 'NS' || !dnsIsBelow($owner, $parent) || !($owner === $zone || dnsIsBelow($zone, $owner))) {
                    $ok = false;
                }
            }
            if ($ok) {
                return ['state' => 'not_auth', 'reason' => 'parent_referral'] + $out;
            }
        }
        $out['reason'] = $r['rcode'] === 'REFUSED' ? 'refused_with_parent' : 'parent_answer_unexpected';
        return $out;
    }

    // Keine konfigurierte Elternzone: nur REFUSED mit erfolgreicher Kontrollabfrage ist ein Nachweis
    if ($r['rcode'] === 'REFUSED' && !$r['aa'] && $r['answer'] === [] && $r['authority'] === []) {
        if ($round !== null && isset($round['control']) && ($round['proof'] ?? null) === $proof) {
            $c = $round['control'];
        } else {
            $c = dnsControlZoneState($config, $proof);
            if ($round !== null) {
                $round['control'] = $c;
                $round['proof'] = $proof;
                // gezählt wird nur eine tatsächlich ausgeführte DNS-Abfrage der Kontrollzone: dnsControlZoneState() fragt
                // nur, wenn die Konfiguration sie zulässt (Ergebnis ok bzw. not_served); missing, own_acl, managed und
                // unverifiable entstehen ohne Abfrage
                if (in_array($c['state'], DNS_CONTROL_QUERIED_STATES, true)) {
                    $round['control_queries'] = ($round['control_queries'] ?? 0) + 1;
                }
            }
        }
        $out['control'] = $c['state'];
        if ($c['state'] === 'ok') {
            return ['state' => 'not_auth', 'reason' => 'refused_control_ok'] + $out;
        }
        $out['reason'] = 'control_zone_' . $c['state'];
        return $out;
    }
    $out['reason'] = 'answer_unexpected';

    return $out;
}

// ------------------------------------------------------------------------------------------------------------------
// Dateizustand und Vorher-Prüfung (E103)
// ------------------------------------------------------------------------------------------------------------------

/**
 * Prüft eine Zonendatei mit named-checkzone (Exit-Code, E94) und liest die Serial.
 *
 * @return array{state: string, serial: ?int, output: string}  state: valid | invalid | timeout
 */
function dnsCheckzone(array $config, string $zoneName, string $path): array {
    $bin = (string)($config['bind_named_checkzone'] ?? '/usr/sbin/named-checkzone');
    $p = agentRunProcess([$bin, $zoneName, $path], AGENT_CAP_CHECKZONE);
    if ($p['status'] !== 'ok') {
        return ['state' => 'timeout', 'serial' => null, 'output' => $p['output']];
    }
    $serial = checkzoneSerial($p['output']);

    return ['state' => $p['exit_code'] === 0 && $serial !== null ? 'valid' : 'invalid', 'serial' => $serial, 'output' => $p['output']];
}

/**
 * Zustand der verwalteten Zonendatei (E80).
 *
 * @return array{state: string, serial: ?int, sha256: ?string, bytes: ?string, reason: ?string}
 *         state: missing | valid | invalid | unreadable
 */
function dnsZoneFileState(array $config, string $zoneName, string $path): array {
    $r = ['state' => 'unreadable', 'serial' => null, 'sha256' => null, 'bytes' => null, 'reason' => null];
    $e = pathState($path);
    if ($e === 'absent') {
        return ['state' => 'missing'] + $r;
    }
    if ($e === 'error') {
        return ['reason' => 'existence_unverifiable'] + $r;
    }
    clearstatcache(true, $path);
    if (is_link($path) || !is_file($path)) {
        return ['reason' => 'not_regular_file'] + $r;
    }
    $data = readFileComplete($path);
    if (!is_string($data)) {
        return ['reason' => 'read_incomplete'] + $r;
    }
    $c = dnsCheckzone($config, $zoneName, $path);
    if ($c['state'] === 'timeout') {
        return ['reason' => 'check_timeout'] + $r;
    }

    return ['state' => $c['state'], 'serial' => $c['state'] === 'valid' ? $c['serial'] : null,
        'sha256' => hash('sha256', $data), 'bytes' => $data, 'reason' => null];
}

/**
 * Vorher-Prüfung nach E103 für zone_sync (unter dem Agent-flock, vor jeder Änderung).
 *
 * @param int    $newSerial Serial der neuen Datei (named-checkzone)
 * @param string $newBytes  Inhalt der neuen Datei
 * @param mixed  $repair    Reparaturkennzeichen der Anfrage (Core-Admin-Operation, AS4) oder null
 *
 * @return array{allow: bool, idempotent: bool, http: int, code: ?string, message: ?string, report: array}
 */
function dnsPreTransferCheck(array $config, string $mode, string $zoneName, int $newSerial, string $newBytes, mixed $repair): array {
    $zone = dnsNormName($zoneName);
    $path = dnsManagedZonePath($config, $zoneName);
    $f = dnsZoneFileState($config, $zoneName, $path);
    $proof = dnsConfigProof($config);
    $k = $zone === null ? ['state' => 'undeterminable', 'reason' => 'zone_name_invalid', 'ancestor' => null]
        : dnsZoneConfigState($config, $zone, $proof);
    $d = ($zone === null || $f['state'] === 'unreadable' || $k['state'] === 'undeterminable')
        ? ['state' => 'unknown', 'serial' => null, 'reason' => 'not_queried', 'rcode' => null, 'control' => null]
        : dnsServedState($config, $zone, $k, $proof);

    $report = [
        'mode' => $mode, 'decision' => 'accept', 'code' => null, 'row' => null,
        'new_serial' => $newSerial,
        'file_state' => $f['state'], 'file_serial' => $f['serial'], 'file_sha256' => $f['sha256'], 'file_reason' => $f['reason'],
        'config_state' => $k['state'], 'config_reason' => $k['reason'],
        'served_state' => $d['state'], 'served_serial' => $d['serial'], 'served_reason' => $d['reason'], 'served_rcode' => $d['rcode'],
        'cmp_file' => null, 'cmp_served' => null,
    ];
    $result = static function (bool $allow, int $row, ?string $code, int $http, ?string $message, bool $idem = false) use (&$report): array {
        $report['row'] = $row;
        $report['code'] = $code;
        if ($code !== null) {
            $report['decision'] = $allow ? 'would_reject' : 'reject';
        }
        return ['allow' => $allow, 'idempotent' => $idem, 'http' => $http, 'code' => $code, 'message' => $message, 'report' => $report];
    };
    $repairGiven = $repair !== null;

    // Zeilen 1–5: nicht prüfbare oder widersprüchliche Zustände – fail-closed in jedem Modus, keine Reparatur
    if ($f['state'] === 'unreadable') {
        return $result(false, 1, 'zone_state_unreadable', 503, 'Vorhandene Zonendatei nicht lesbar bzw. nicht prüfbar – keine Änderung');
    }
    if ($k['state'] === 'undeterminable') {
        return $result(false, 2, 'served_state_unknown', 503, 'Konfigurationszustand nicht eindeutig bestimmbar (' . $k['reason'] . ') – keine Änderung');
    }
    if ($d['state'] === 'unknown') {
        return $result(false, 3, 'served_state_unknown', 503, 'Ausgelieferter Zustand nicht eindeutig bestimmbar (' . $d['reason'] . ') – keine Änderung');
    }
    if ($k['state'] === 'no' && $d['state'] === 'served') {
        return $result(false, 4, 'served_state_unknown', 503, 'Widerspruch: Zone nicht konfiguriert, aber autoritativ ausgeliefert – keine Änderung');
    }
    if ($k['state'] === 'yes' && $d['state'] !== 'served') {
        return $result(false, 5, 'served_state_unknown', 503, 'Widerspruch: Zone konfiguriert, aber nicht nachweisbar ausgeliefert – keine Änderung');
    }

    // Reparaturkennzeichen: nur für ungültige, lesbare Dateien (Zeilen 8, 11), exakt passend
    $expectServed = $d['state'] === 'served' ? $d['serial'] : 'not_auth';
    if ($f['state'] === 'invalid') {
        $row = $d['state'] === 'served' ? 11 : 8;
        $ok = is_array($repair) && isset($repair['expect_file'], $repair['expect_served'])
            && is_string($repair['expect_file']) && hash_equals((string)$f['sha256'], $repair['expect_file'])
            && ($expectServed === 'not_auth' ? $repair['expect_served'] === 'not_auth'
                : serialParse($repair['expect_served']) === $expectServed);
        if (!$ok) {
            return $result(false, $row, $repairGiven ? 'repair_mismatch' : 'zone_state_invalid', 409,
                $repairGiven ? 'Reparaturkennzeichen passt nicht zum vorgefundenen Zustand – keine Änderung'
                    : 'Vorhandene Zonendatei ungültig – Übernahme nur mit passendem Reparaturkennzeichen');
        }
        if ($d['state'] === 'served') {
            $report['cmp_served'] = serialCompare($newSerial, (int)$d['serial']);
            if ($report['cmp_served'] !== 'newer') {
                return serialReject($result, $mode, 11, [$report['cmp_served']]);
            }
        }
        return $result(true, $row, null, 200, null);
    }
    if ($repairGiven) {
        return $result(false, 0, 'repair_mismatch', 409, 'Reparaturkennzeichen nur bei ungültiger vorhandener Zonendatei zulässig – keine Änderung');
    }

    // Zeilen 6, 7, 9, 10: Monotonie I-A1 gegenüber Datei- und ausgelieferter Serial
    $identical = $f['state'] === 'valid' && hash_equals((string)$f['sha256'], hash('sha256', $newBytes));
    $cmps = [];
    if ($f['state'] === 'valid') {
        $report['cmp_file'] = $cmps[] = serialCompare($newSerial, (int)$f['serial']);
    }
    if ($d['state'] === 'served') {
        $report['cmp_served'] = $cmps[] = serialCompare($newSerial, (int)$d['serial']);
    }
    $row = match (true) {
        $f['state'] === 'missing' && $d['state'] === 'not_auth' => 6,
        $d['state'] === 'not_auth' => 7,
        $f['state'] === 'missing' => 9,
        default => 10,
    };
    if ($cmps === [] || array_unique($cmps) === ['newer']) {
        return $result(true, $row, null, 200, null);
    }
    // Idempotent: gleiche Serial und identische Bytes gegenüber jeder vorhandenen Referenz (Zeile 7: Datei; Zeile 10:
    // Datei und ausgelieferte Serial gleich)
    if ($identical && array_unique($cmps) === ['equal']) {
        return $result(true, $row, null, 200, null, true);
    }

    return serialReject($result, $mode, $row, $cmps);
}

/**
 * Einziger Aufrufpunkt der Vorher-Prüfung in zone_sync (E103). Die AS1-Regression ersetzt ausschließlich diese
 * Funktion in ihrer Sandbox-Kopie des Agenten (dort gibt es keinen zur Sandbox passenden BIND); der Produktionscode hat
 * keinen Schalter, der die Prüfung abschaltet.
 */
function agentPreTransferGate(array $config, string $mode, string $zoneName, int $newSerial, string $newBytes, mixed $repair): array {
    return dnsPreTransferCheck($config, $mode, $zoneName, $newSerial, $newBytes, $repair);
}

/**
 * Aggregierte Wirkung eines Cleanups aus den Ergebnissen je Zone (Vorrangregel, E113/5f):
 * 1. nachgewiesene Änderung (zones.conf ersetzt oder eine Datei entfernt) und ein Rest (kept/unknown) ⇒ partial
 * 2. nachgewiesene Änderung ohne Rest ⇒ applied
 * 3. keine nachgewiesene Änderung, aber unknown ⇒ unknown
 * 4. sonst ⇒ none
 *
 * @param array<int, array{zone: string, conf: string, file: string}> $zones
 */
function cleanupAggregateEffect(bool $zonesConfReplaced, array $zones): string {
    $changed = $zonesConfReplaced;
    $rest = false;
    $unknown = false;
    foreach ($zones as $z) {
        foreach (['conf', 'file'] as $k) {
            $v = $z[$k] ?? 'unknown';
            $changed = $changed || $v === 'removed';
            $rest = $rest || $v === 'kept' || $v === 'unknown';
            $unknown = $unknown || $v === 'unknown';
        }
    }
    if ($changed) {
        return $rest ? 'partial' : 'applied';
    }

    return $unknown ? 'unknown' : 'none';
}

/** Ablehnung aus Serial-Gründen; in observe nur gemeldet (would_reject). */
function serialReject(callable $result, string $mode, int $row, array $cmps): array {
    $code = in_array('undefined', $cmps, true) ? 'serial_undefined'
        : (in_array('older', $cmps, true) ? 'stale_serial' : 'serial_conflict');
    $msg = [
        'serial_undefined' => 'Serial nach RFC 1982 nicht vergleichbar (Abstand 2^31) – keine Übernahme',
        'stale_serial'     => 'Neue Serial ist nach RFC 1982 nicht größer als der lokale Stand – keine Übernahme',
        'serial_conflict'  => 'Gleiche Serial mit abweichendem Inhalt – keine Übernahme',
    ][$code];

    return $result($mode === 'observe', $row, $code, 409, $msg);
}

// ------------------------------------------------------------------------------------------------------------------
// Preflight und Reload mit Frist (observe/enforce; im Modus off unverändert checkBindConfig()/reloadBind())
// ------------------------------------------------------------------------------------------------------------------

/**
 * named-checkconf-Preflight mit Frist.
 *
 * @return array{ok: bool, exit_code: int, output: string, status: string}  status: ok | timeout | not_started | error
 */
function agentCheckconfTimed(array $config): array {
    $bin = (string)($config['bind_named_checkconf'] ?? '/usr/bin/named-checkconf');
    $conf = (string)($config['bind_named_conf_path'] ?? '/etc/bind/named.conf');
    $p = agentRunProcess([$bin, $conf], AGENT_CAP_CHECKCONF);
    if ($p['status'] !== 'ok') {
        return ['ok' => false, 'exit_code' => -1, 'output' => 'named-checkconf nicht abgeschlossen (' . $p['status'] . ')', 'status' => $p['status']];
    }

    return ['ok' => $p['exit_code'] === 0, 'exit_code' => (int)$p['exit_code'], 'output' => $p['output'], 'status' => 'ok'];
}

/**
 * rndc reload mit Frist (E93: Exit-Code nur für Befehlskanal und Konfiguration; kein Nachweis der Übernahme).
 *
 * @return array{status: string, exit_code: ?int, output: string}
 *         status: ok (rc 0) | failed (rc ≠ 0) | timeout (abgebrochen; Wirkung unbestimmt) | not_started
 */
function agentReloadTimed(array $config): array {
    $rndc = (string)($config['bind_rndc'] ?? '/usr/sbin/rndc');
    $p = agentRunProcess([AGENT_SUDO, '-n', $rndc, 'reload'], AGENT_CAP_RNDC_RELOAD);
    $status = match ($p['status']) {
        'ok'          => $p['exit_code'] === 0 ? 'ok' : 'failed',
        'timeout'     => 'timeout',
        'not_started' => 'not_started',
        default       => 'failed',
    };

    return ['status' => $status, 'exit_code' => $p['exit_code'], 'output' => trim($p['output'])];
}
