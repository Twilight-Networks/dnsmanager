<?php
/**
 * Datei: tests/ui/u6.php
 * Zweck: CSP-Modi (U6-P1/P2) gegen die Testkopie:
 *        V  Zielrichtlinie unverändert (Literal aus U2/Leitfaden 4);
 *        M  je Konfigurationsvariante exakte Headerwerte für normale Seite, Login, Redirects (Seite und Action),
 *           403 (Seite und Action) und DynDNS-API: genau ein CSP-Header des erwarteten Modus bzw. keiner bei 'off';
 *           die bestehenden Sicherheitsheader sind immer vorhanden;
 *        K  Report-Endpunkt csp_report.php (ohne common.php): nur im Modus 'report-only' erreichbar (405/204 mit
 *           Logzeile), sonst 404 ohne Logzeile; sendet selbst nie einen CSP-Header;
 *        S  Status und Weiterleitung aller Antworten sind in allen Modi identisch (CSP ändert keinen Seitenvertrag).
 *        Die Konfiguration der Testkopie wird je Variante umgeschrieben und am Ende wiederhergestellt.
 * Aufruf (über run.sh matrix): php u6.php
 */
require __DIR__ . '/lib.php';

$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };

const U6_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
    . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
const U6_REPORT_URI = '/dnsmanager-ui/csp_report.php';
const U6_STATE = T . '/csp-state-u6';

$cfgFile = APP . '/web/config/ui_config.php';
$cfgOrig = (string)file_get_contents($cfgFile);
register_shutdown_function(function () use ($cfgFile, $cfgOrig) {
    file_put_contents($cfgFile, $cfgOrig);
    if (is_dir(U6_STATE)) { array_map('unlink', glob(U6_STATE . '/*') ?: []); rmdir(U6_STATE); }
});
foreach (["// define('CSP_MODE', 'enforce');", "// define('CSP_REPORT_ONLY', true);", "// define('CSP_REPORT_STATE_DIR', '/var/lib/dnsmanager/csp');"] as $line) {
    if (substr_count($cfgOrig, "\n$line") !== 1) { fwrite(STDERR, "ABBRUCH: Zeile fehlt in der Testkonfiguration: $line\n"); exit(1); }
}
if (!is_dir(U6_STATE)) { mkdir(U6_STATE, 0700); chown(U6_STATE, 'www-data'); chgrp(U6_STATE, 'www-data'); }

/** Konfiguration der Testkopie: $mode = null (nicht definiert) oder Wert; $legacy = CSP_REPORT_ONLY true */
function setCsp(?string $mode, bool $legacy): void
{
    global $cfgFile, $cfgOrig;
    $c = $cfgOrig;
    if ($mode !== null) { $c = str_replace("\n// define('CSP_MODE', 'enforce');", "\ndefine('CSP_MODE', " . var_export($mode, true) . ');', $c); }
    if ($legacy) { $c = str_replace("\n// define('CSP_REPORT_ONLY', true);", "\ndefine('CSP_REPORT_ONLY', true);", $c); }
    $c = str_replace("\n// define('CSP_REPORT_STATE_DIR', '/var/lib/dnsmanager/csp');", "\ndefine('CSP_REPORT_STATE_DIR', '" . U6_STATE . "');", $c);
    file_put_contents($cfgFile, $c);
    if (is_dir(U6_STATE)) { array_map('unlink', glob(U6_STATE . '/*') ?: []); }   // frischer Ratenstatus je Variante
}

// ---------- V: Zielrichtlinie ----------
$src = (string)file_get_contents(APP . '/web/inc/web/csp.php');
$ok(preg_match('/const CSP_TARGET_POLICY = "(.*?)"\s*\.\s*"(.*?)";/s', $src, $m) === 1 && $m[1] . $m[2] === U6_POLICY, 'V01',
    'Zielrichtlinie unverändert (Literal U2/Leitfaden 4)');

// ---------- M/K/S: Varianten ----------
resetDb();
$a = session('admin');
$z = session('zoneadmin');
$variants = [
    'M01' => [null, false, 'enforce', 'ohne Einstellung → Standard enforce'],
    'M02' => ['enforce', false, 'enforce', "CSP_MODE 'enforce'"],
    'M03' => ['report-only', false, 'report-only', "CSP_MODE 'report-only'"],
    'M04' => ['off', false, 'off', "CSP_MODE 'off'"],
    'M05' => [null, true, 'report-only', 'nur CSP_REPORT_ONLY true → Kompatibilitätsfall report-only'],
    'M06' => ['off', true, 'off', "CSP_MODE 'off' hat Vorrang vor CSP_REPORT_ONLY"],
    'M07' => ['enforce', true, 'enforce', "CSP_MODE 'enforce' hat Vorrang vor CSP_REPORT_ONLY"],
    'M08' => ['Enforce', true, 'enforce', "ungültiger Wert 'Enforce' → enforce (Fail-Closed, Vorrang vor CSP_REPORT_ONLY)"],
];
$report = json_encode(['csp-report' => ['document-uri' => 'https://ui-golden.test/dnsmanager-ui/pages/dashboard.php',
    'violated-directive' => 'style-src-attr', 'effective-directive' => 'style-src-attr', 'blocked-uri' => 'inline', 'disposition' => 'report']]);
$contracts = [];
foreach ($variants as $id => [$mode, $legacy, $expect, $label]) {
    setCsp($mode, $legacy);
    $anon = [];
    $resp = [
        'page'       => cgi('GET', 'pages/dashboard.php', '', $a),
        'login'      => cgi('GET', 'login.php', '', $anon),
        'redir_page' => cgi('GET', 'pages/zones.php', '', $anon),
        'redir_act'  => cgi('POST', 'actions/user_set_active.php', http_build_query(['id' => 999, 'active' => 1, 'csrf_token' => csrfFromSession($a)]), $a),
        '403_page'   => cgi('GET', 'pages/servers.php', '', $z),
        '403_act'    => cgi('POST', 'actions/server_delete.php', http_build_query(['id' => 2, 'csrf_token' => csrfFromSession($z)]), $z),
        'api'        => cgi('GET', 'api/v1/dyndns/update.php?hostname=dyn1.u0.test&myip=192.0.2.80', '', $anon),
    ];
    $want = match ($expect) {
        'enforce' => ['content-security-policy: ' . U6_POLICY],
        'report-only' => ['content-security-policy-report-only: ' . U6_POLICY . '; report-uri ' . U6_REPORT_URI],
        default => [],
    };
    $errors = [];
    foreach ($resp as $k => $r) {
        $csp = array_values(array_filter($r['headers'], fn($h) => str_starts_with($h, 'content-security-policy')));
        if ($csp !== $want) { $errors[] = "$k: " . json_encode($csp, JSON_UNESCAPED_SLASHES); }
        foreach (['x-content-type-options: nosniff', 'x-frame-options: DENY', 'referrer-policy: same-origin'] as $h) {
            if (!in_array($h, $r['headers'], true)) { $errors[] = "$k: fehlt $h"; }
        }
        $contracts[$k][$id] = $r['status'] . ' ' . ($r['location'] ?? '-');
    }
    // Erwartete Antworttypen (Plausibilität des Falls, unabhängig vom Modus)
    $types = ['page' => 200, 'login' => 200, 'redir_page' => 302, 'redir_act' => 302, '403_page' => 403, '403_act' => 403, 'api' => 401];
    foreach ($types as $k => $st) { if ($resp[$k]['status'] !== $st) { $errors[] = "$k: Status {$resp[$k]['status']} statt $st"; } }
    $ok($errors === [], $id, "$label: exakte Header für Seite, Login, Redirects, 403, DynDNS-API" . ($errors ? ' – ' . implode('; ', $errors) : ''));

    // K: Report-Endpunkt
    $off = logOffset();
    $g = cgi('GET', 'csp_report.php', '', $anon);
    $p = cgi('POST', 'csp_report.php', $report, $anon, ['CONTENT_TYPE' => 'application/csp-report', 'CONTENT_LENGTH' => (string)strlen($report)]);
    $lines = array_values(array_filter(logSince($off), fn($l) => str_contains($l, 'event=csp_')));
    $cspHdr = array_filter(array_merge($g['headers'], $p['headers']), fn($h) => str_starts_with($h, 'content-security-policy'));
    $kOk = $expect === 'report-only'
        ? ($g['status'] === 405 && $p['status'] === 204 && count($lines) === 1 && str_contains($lines[0], 'directive="style-src-attr" blocked="inline"'))
        : ($g['status'] === 404 && $p['status'] === 404 && $lines === []);
    $ok($kOk && $cspHdr === [], 'K' . substr($id, 1), "Report-Endpunkt bei $label: GET {$g['status']}, POST {$p['status']}, Logzeilen " . count($lines)
        . ', CSP-Header am Endpunkt ' . count($cspHdr));
}

// ---------- S: Verträge modusunabhängig ----------
$diff = [];
foreach ($contracts as $k => $byMode) { if (count(array_unique($byMode)) !== 1) { $diff[] = "$k: " . json_encode($byMode, JSON_UNESCAPED_SLASHES); } }
$ok($diff === [], 'S01', 'Status und Weiterleitung aller Antworttypen in allen 8 Varianten identisch' . ($diff ? ' – ' . implode('; ', $diff) : ''));
$cnt = fn(string $sql) => (int)$GLOBALS['root']->query(str_replace('DB.', '`' . DB . '`.', $sql))->fetchColumn();
$ok($cnt('SELECT COUNT(*) FROM DB.users WHERE id = 999') === 0 && $cnt('SELECT COUNT(*) FROM DB.servers WHERE id = 2') === 1, 'S02',
    'Kein Zustandswechsel durch die Prüfanfragen (Benutzer 999 nicht angelegt, Server 2 nicht gelöscht)');

file_put_contents($cfgFile, $cfgOrig);
$ok((string)file_get_contents($cfgFile) === $cfgOrig, 'S03', 'Konfiguration der Testkopie wiederhergestellt');

printf("== U6: %d/%d bestanden\n", $pass, $pass + $fail);
exit($fail ? 1 : 0);
