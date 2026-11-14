<?php
/**
 * Datei: tests/ui/lib.php
 * Zweck: Hilfsfunktionen der UI-Golden-Basis (U0). Führt die Anwendung einer Testkopie per php-cgi als www-data aus,
 *        ohne Webserver, gegen eine isolierte Datenbank. Normalisiert flüchtige Werte (CSRF-Token, Zeitstempel,
 *        Hash-Salts, SOA-Serials), damit Läufe desselben Commits identische Ergebnisse liefern.
 *
 * Nur für dedizierte Testsysteme. Erwartet die von run.sh angelegte Umgebung unter T (Konstante unten).
 */

const T = '/tmp/dnsmgr-uig';
const APP = T . '/app';
const DB = 'dnsmgr_uig_app';
const TPL = 'dnsmgr_uig_tpl';
const SNAP_TABLES = ['users', 'user_zones', 'zones', 'zone_servers', 'servers', 'records', 'dyndns_accounts', 'system_status', 'diagnostics', 'deployments'];
const RESET_TABLES = ['users', 'user_zones', 'zones', 'zone_servers', 'servers', 'records', 'dyndns_accounts', 'system_status', 'diagnostics', 'diagnostic_log', 'deployments'];

if (DB === 'dnsmanager' || !preg_match('/^dnsmgr_uig_/', DB)) { fwrite(STDERR, "ABBRUCH: unzulässige Datenbank\n"); exit(3); }

$GLOBALS['root'] = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['C'] = require T . '/creds.php';

/** Datenbank der Testkopie auf die Fixture zurücksetzen und Sessions löschen */
function resetDb(): void
{
    $r = $GLOBALS['root'];
    $r->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (RESET_TABLES as $t) {
        $r->exec('TRUNCATE TABLE `' . DB . "`.`$t`");
        $r->exec('INSERT INTO `' . DB . "`.`$t` SELECT * FROM `" . TPL . "`.`$t`");
    }
    $r->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (glob(T . '/sess/sess_*') ?: [] as $f) { unlink($f); }
}

/** Zusätzliches SQL auf der Testdatenbank (Platzhalter DB.) */
function sqlx(string $sql): void { $GLOBALS['root']->exec(str_replace('DB.', '`' . DB . '`.', $sql)); }

/**
 * Datenbankzustand ohne flüchtige Werte: Zeitstempelspalten entfallen, Passwort-Hashes und Tokens nur als
 * „wie Fixture“/„neu“, SOA-Serial nur als „wie Fixture“/„geändert“.
 */
function snap(): array
{
    $r = $GLOBALS['root']; $out = [];
    foreach (SNAP_TABLES as $t) {
        $cols = $r->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . DB . "' AND TABLE_NAME = '$t' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_NUM);
        $sel = [];
        foreach ($cols as [$c, $type]) {
            if (in_array($type, ['timestamp', 'datetime'], true)) { continue; }
            if (in_array($c, ['password_hash', 'api_token', 'soa_serial'], true)) {
                $sel[] = "IF(a.`$c` <=> f.`$c`, 'wie-Fixture', 'neu') AS `$c`";
            } else {
                $sel[] = "a.`$c`";
            }
        }
        $hasId = in_array('id', array_column($cols, 0), true);
        $join = $hasId ? ' LEFT JOIN `' . TPL . "`.`$t` f ON f.id = a.id" : ' LEFT JOIN (SELECT NULL AS id) f ON 0';
        $rows = $r->query('SELECT ' . implode(', ', $sel) . ' FROM `' . DB . "`.`$t` a$join ORDER BY " . ($hasId ? 'a.id' : '1, 2'))->fetchAll(PDO::FETCH_NUM);
        $out[$t] = array_map(fn($row) => implode('|', array_map(fn($v) => $v === null ? 'NULL' : (string)$v, $row)), $rows);
    }
    return $out;
}

/** HTTP-Anfrage an die Testkopie über php-cgi als www-data */
function cgi(string $method, string $path, string $body, array &$cookies, array $extraEnv = []): array
{
    [$script, $query] = array_pad(explode('?', $path, 2), 2, '');
    $env = ['REDIRECT_STATUS' => '1', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REQUEST_METHOD' => $method, 'SCRIPT_FILENAME' => APP . "/web/$script",
        'SCRIPT_NAME' => "/dnsmanager-ui/$script", 'REQUEST_URI' => "/dnsmanager-ui/$path", 'QUERY_STRING' => $query, 'HTTP_HOST' => 'ui-golden.test',
        'SERVER_NAME' => 'ui-golden.test', 'SERVER_PORT' => '443', 'HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.57', 'HTTP_ACCEPT_LANGUAGE' => 'de',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => (string)strlen($body),
        'HTTP_COOKIE' => implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies)), 'PATH' => '/usr/sbin:/usr/bin:/bin'] + $extraEnv;
    $cmd = array_merge(['sudo', '-u', 'www-data', 'env', '-i'], array_map(fn($k, $v) => "$k=$v", array_keys($env), $env),
        ['php-cgi', '-d', 'session.save_path=' . T . '/sess', '-d', 'error_log=' . T . '/app.log', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'opcache.enable=0']);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $body); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    [$head, $resp] = array_pad(preg_split("/\r?\n\r?\n/", $out, 2), 2, '');
    $status = preg_match('/^Status: (\d+)/mi', $head, $m) ? (int)$m[1] : 200;
    $loc = preg_match('/^Location: (.*)$/mi', $head, $m) ? trim($m[1]) : null;
    if ($loc !== null && $status === 200) { $status = 302; }
    if (preg_match_all('/^Set-Cookie: ([^=]+)=([^;\r\n]*)/mi', $head, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $c) { $cookies[$c[1]] = $c[2]; }
    }
    preg_match_all('/^(Content-type|X-Content-Type-Options|X-Frame-Options|Referrer-Policy|Content-Security-Policy|Allow): (.*)$/mi', $head, $hh, PREG_SET_ORDER);
    $hdr = array_map(fn($x) => strtolower($x[1]) . ': ' . trim($x[2]), $hh); sort($hdr);
    return ['status' => $status, 'location' => $loc, 'body' => $resp, 'headers' => $hdr];
}

function sessRaw(array $c): string { $f = T . '/sess/sess_' . ($c['twl_dnsmgr_sid'] ?? 'x'); clearstatcache(); return is_file($f) ? (string)file_get_contents($f) : ''; }
function csrfFromSession(array $c): string { return preg_match('/csrf_token\|s:64:"([0-9a-f]{64})"/', sessRaw($c), $m) ? $m[1] : ''; }

/** Session-Inhalt ohne CSRF-Token und Zeitstempel; Toasts werden zusätzlich getrennt ausgewertet */
function sessNorm(array $c): string
{
    return preg_replace(['/s:64:"[0-9a-f]{64}"/', '/last_activity\|i:\d+;/', '/login_time\|i:\d+;/'], ['s:64:"CSRF"', 'last_activity|i:T;', 'login_time|i:T;'], sessRaw($c));
}

/** Toasts aus der Session (Erfolg/Warnungen/Fehler) */
function toasts(array $c): array
{
    $s = sessRaw($c); $t = [];
    if (preg_match('/toast_success\|s:\d+:"(.*?)";/s', $s, $m)) { $t[] = 'success: ' . $m[1]; }
    foreach (['toast_warnings' => 'warning', 'toast_errors' => 'error'] as $k => $label) {
        if (preg_match('/' . $k . '\|a:\d+:\{(.*?)\}/s', $s, $m) && preg_match_all('/s:\d+:"(.*?)";/s', $m[1], $mm)) {
            foreach ($mm[1] as $x) { $t[] = "$label: $x"; }
        }
    }
    return $t;
}

function logOffset(): int { clearstatcache(); return is_file(T . '/app.log') ? (int)filesize(T . '/app.log') : 0; }

/** Neue Logzeilen seit $off, ohne Zeitstempel, Pfade, Token und Serials */
function logSince(int $off): array
{
    clearstatcache(); $out = [];
    $txt = is_file(T . '/app.log') ? (string)file_get_contents(T . '/app.log', false, null, $off) : '';
    foreach (preg_split('/\R/', $txt) as $l) {
        if (trim($l) === '') { continue; }
        $out[] = preg_replace(['/^\[[^\]]+\] /', '/ time="[^"]+"/', '/ line=\d+/', '#' . preg_quote(APP, '#') . '/#', '/' . DB . '/', '/[0-9a-f]{64}/', '/\b20\d{8}\b/', '#/tmp/dnsmanager-work-[A-Za-z0-9_-]+#'],
            ['', '', ' line=N', '/APP/', 'DB', 'HEX64', 'SERIAL', '/tmp/dnsmanager-work-X'], $l);
    }
    return $out;
}

/** Login mit Benutzername in gegebener Schreibweise; liefert Cookies und Antwort */
function loginAs(string $typed, string $credKey): array
{
    $c = [];
    $r = cgi('GET', 'login.php', '', $c);
    $tok = preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $r['body'], $m) ? $m[1] : '';
    $r = cgi('POST', 'login.php', http_build_query(['csrf_token' => $tok, 'username' => $typed, 'password' => $GLOBALS['C'][$credKey] ?? 'x']), $c);
    return [$c, $r];
}

/** Angemeldete Session einer Rolle (admin|zoneadmin) oder leere Cookies für anonym */
function session(string $role): array
{
    if ($role === 'anon') { return []; }
    $user = ['admin' => 'u0admin', 'zoneadmin' => 'u0zone'][$role];
    [$c, $r] = loginAs($user, $user);
    if (!($r['status'] === 302 && str_contains((string)$r['location'], 'dashboard'))) { fwrite(STDERR, "Login $user fehlgeschlagen\n"); exit(1); }
    return $c;
}

/** Attribute eines HTML-Tags */
function attrs(string $tag): array
{
    preg_match_all('/([a-zA-Z_:-]+)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', preg_replace('/^<\w+|\/?>$/', '', $tag), $m, PREG_SET_ORDER);
    $a = [];
    foreach ($m as $x) {
        // Nicht getroffene Gruppen liefert PCRE als leere Zeichenkette: die Variante am ersten Zeichen des Werts erkennen
        $raw = $x[2] ?? '';
        $v = $raw === '' ? '' : ($raw[0] === '"' ? $x[3] : ($raw[0] === "'" ? $x[4] : ($x[5] ?? '')));
        $a[strtolower($x[1])] = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $a;
}

/** Alle Formulare einer Seite mit Methode, Ziel und Feldern (Browser-Semantik für Checkbox/Radio/Select) */
function forms(string $html): array
{
    preg_match_all('#(<form\b[^>]*>)(.*?)</form>#s', $html, $fm, PREG_SET_ORDER);
    $forms = [];
    foreach ($fm as [$all, $open, $inner]) {
        $fa = attrs($open);
        $fields = []; $values = [];
        preg_match_all('#<(input|select|textarea|button)\b[^>]*>#s', $inner, $im, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($im as $x) {
            $tag = $x[0][0]; $kind = strtolower($x[1][0]); $a = attrs($tag);
            if (!isset($a['name']) || $a['name'] === '') { continue; }
            $n = $a['name']; $type = $kind === 'input' ? strtolower($a['type'] ?? 'text') : $kind;
            $fields[] = "$n:$type";
            if ($kind === 'button' || in_array($type, ['submit', 'file', 'button', 'reset'], true)) { continue; }
            if (in_array($type, ['checkbox', 'radio'], true) && !array_key_exists('checked', $a)) { continue; }
            if (array_key_exists('disabled', $a)) { continue; }
            if ($kind === 'select') {
                $rest = substr($inner, $x[0][1]);
                $sel = preg_match('#^(.*?)</select>#s', $rest, $sm) ? $sm[1] : '';
                preg_match_all('#<option\b[^>]*>#s', $sel, $om);
                $v = null;
                foreach ($om[0] as $o) { $oa = attrs($o); if ($v === null) { $v = $oa['value'] ?? ''; } if (array_key_exists('selected', $oa)) { $v = $oa['value'] ?? ''; break; } }
                $v = $v ?? '';
            } elseif ($kind === 'textarea') {
                $v = preg_match('#^<textarea\b[^>]*>(.*?)</textarea>#s', substr($inner, $x[0][1]), $tm) ? html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            } else {
                $v = $a['value'] ?? (in_array($type, ['checkbox', 'radio'], true) ? 'on' : '');
            }
            if (str_ends_with($n, '[]')) { $values[substr($n, 0, -2)][] = $v; } else { $values[$n] = $v; }
        }
        $fields = array_values(array_unique($fields)); sort($fields);
        // Kodierung wie der Browser: ohne Attribut application/x-www-form-urlencoded (K2)
        $forms[] = ['method' => strtolower($fa['method'] ?? 'get'), 'action' => preg_replace('#^/dnsmanager-ui/#', '', $fa['action'] ?? ''), 'id' => $fa['id'] ?? '',
            'enctype' => strtolower(trim($fa['enctype'] ?? '')) ?: 'application/x-www-form-urlencoded', 'fields' => $fields, 'values' => $values];
    }
    return $forms;
}

/** Erstes Formular mit dem gegebenen Ziel (optional Formular-ID) */
function formFor(string $html, string $action, ?string $id = null): ?array
{
    foreach (forms($html) as $f) { if ($f['action'] === $action && ($id === null || $f['id'] === $id)) { return $f; } }
    return null;
}

/** Vertragsrelevante Struktur einer Seite: Formulare (Methode, Ziel, Kodierung, Feldnamen/-typen) und Linkziele */
function pageContract(string $html): array
{
    $forms = array_map(fn($f) => $f['method'] . ' ' . $f['action'] . ' ' . $f['enctype'] . ' [' . implode(', ', $f['fields']) . ']', forms($html));
    sort($forms);
    // Linkziele mit doppelten und einfachen Anführungszeichen (K1); attrs() dekodiert HTML-Entitäten
    preg_match_all('#<a\b[^>]*>#s', $html, $am);
    $hrefs = [];
    foreach ($am[0] as $tag) { $a = attrs($tag); if (isset($a['href'])) { $hrefs[] = $a['href']; } }
    $links = array_values(array_unique(array_filter(array_map(fn($h) => preg_replace(['/[?&]v=\d+/', '/[0-9a-f]{64}/'], ['', 'HEX64'], $h), $hrefs),
        fn($h) => !str_starts_with($h, '#') && !str_starts_with($h, 'javascript:'))));
    sort($links);
    return ['forms' => $forms, 'links' => $links];
}

/** Normalisierter Body (nur informativ; ändert sich mit der UI-Modernisierung beabsichtigt) */
function bodyNorm(string $html): string
{
    $h = preg_replace(['/[0-9a-f]{64}/', '/\?v=\d+/', '#' . preg_quote(APP, '#') . '/#', '/\b20\d{8}\b/', '/v1:[0-9a-f]{64}/'], ['HEX64', '?v=N', '/APP/', 'SERIAL', 'v1:HEX'], $html);
    return implode("\n", array_values(array_filter(array_map('trim', explode("\n", $h)), fn($l) => $l !== '')));
}

function phpProblems(string $html, array $log): array
{
    $p = [];
    if (preg_match_all('/(Fatal error|Warning|Notice|Deprecated|Parse error):/', $html, $m)) { $p[] = 'Body: ' . implode(',', array_unique($m[1])); }
    // PHP-eigene Meldungen sowie die über den Fehlerhandler der Anwendung protokollierten (event=php_error)
    foreach ($log as $l) { if (preg_match('/PHP (Fatal error|Warning|Notice|Deprecated|Parse error)|event=php_error/', $l)) { $p[] = 'Log: ' . $l; } }
    return $p;
}

/** Benutzer-ID per Name aus der Testdatenbank */
function idOf(string $table, string $col, string $value): int
{
    $s = $GLOBALS['root']->prepare('SELECT id FROM `' . DB . "`.`$table` WHERE `$col` = ?"); $s->execute([$value]);
    return (int)$s->fetchColumn();
}
