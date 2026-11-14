<?php
/**
 * Datei: tests/ui/cases.php
 * Zweck: Fälle der UI-Golden-Basis (U0). Erzeugt ein normalisiertes Ergebnis (JSON) für
 *        - alle Seiten je Rolle (anonym, admin, zoneadmin): Status, Weiterleitung, Header, Logs, PHP-Probleme,
 *          Seitenvertrag (Formulare mit Methode/Ziel/Feldern, Linkziele) und informativ den normalisierten Body;
 *        - die relevanten Actions, Login/Logout und die DynDNS-API: Status, Weiterleitung, Toasts, Logs,
 *          Datenbankzustand und PHP-Probleme.
 * Formulare werden wie von einem Browser abgesendet: Felder aus der ausgelieferten Seite übernehmen und nur die
 * genannten Werte setzen. Für Fälle ohne zugängliches Formular (fehlende Berechtigung, Löschknöpfe) werden die
 * Felder direkt mit dem CSRF-Token der Session gesendet.
 * Aufruf (über run.sh): php cases.php <Ausgabedatei> <getesteter Commit>
 */
require __DIR__ . '/lib.php';
[$_, $outFile, $sha] = $argv;
$res = [];

function rec(string $id, array $data): void
{
    global $res;
    ksort($data);
    $res[$id] = $data;
    $flag = ($data['problems'] ?? []) ? ' PHP-PROBLEM' : '';
    echo sprintf("%-28s %s %s%s\n", $id, $data['status'] ?? '-', $data['location'] ?? '', $flag);
}

// ===================== Seiten =====================
$pages = [
    'login' => 'login.php', 'logout_get' => 'logout.php', 'dashboard' => 'pages/dashboard.php',
    'zones' => 'pages/zones.php', 'zones_edit1' => 'pages/zones.php?edit_id=1', 'zones_edit2' => 'pages/zones.php?edit_id=2',
    'zones_add' => 'pages/zones.php?add_new=1', 'records_z1' => 'pages/records.php?zone_id=1', 'records_z2' => 'pages/records.php?zone_id=2',
    'records_z3' => 'pages/records.php?zone_id=3', 'records_edit3' => 'pages/records.php?zone_id=1&edit_id=3',
    'records_add' => 'pages/records.php?zone_id=1&add_new=1', 'servers' => 'pages/servers.php', 'servers_add' => 'pages/servers.php?add_new=1', 'servers_edit2' => 'pages/servers.php?edit_id=2',
    'dyndns' => 'pages/dyndns.php', 'dyndns_add' => 'pages/dyndns.php?add_new=1', 'dyndns_edit1' => 'pages/dyndns.php?edit_id=1', 'users' => 'pages/users.php', 'users_add' => 'pages/users.php?add_new=1',
    'users_edit2' => 'pages/users.php?edit_id=2', 'system_health' => 'pages/system_health.php', 'update' => 'pages/update.php',
];
foreach (['anon', 'admin', 'zoneadmin'] as $role) {
    resetDb();
    $c = session($role);
    foreach ($pages as $key => $path) {
        $cc = $c;
        $off = logOffset();
        $r = cgi('GET', $path, '', $cc);
        $log = logSince($off);
        $body = bodyNorm($r['body']);
        rec("page/$role/$key", ['kind' => 'page', 'role' => $role, 'request' => "GET $path", 'status' => $r['status'], 'location' => $r['location'],
            'headers' => $r['headers'], 'log' => $log, 'problems' => phpProblems($r['body'], $log), 'contract' => pageContract($r['body']),
            'info' => ['body_sha256' => hash('sha256', $body), 'body_lines' => substr_count($body, "\n") + 1,
                'title' => preg_match('#<title>(.*?)</title>#s', $r['body'], $m) ? trim($m[1]) : '']]);
    }
}

// ===================== Actions =====================
/**
 * Formular von $page als $role absenden. $set überschreibt Felder (null = entfernen); $raw sendet stattdessen genau
 * diese Felder (plus CSRF-Token aus der Session, außer $csrf = false).
 */
function submit(string $id, string $role, ?string $page, string $action, array $set = [], ?array $raw = null, ?string $formId = null, bool $csrf = true, ?callable $before = null): void
{
    resetDb();
    if ($before) { $before(); }
    $c = session($role);
    $formFound = null;
    if ($raw === null) {
        $p = cgi('GET', $page, '', $c);
        $f = formFor($p['body'], $action, $formId);
        $formFound = $f !== null;
        $fields = $f['values'] ?? [];
        foreach ($set as $k => $v) { if ($v === null) { unset($fields[$k]); } else { $fields[$k] = $v; } }
    } else {
        if ($page !== null) { cgi('GET', $page, '', $c); }
        $fields = $raw;
        if ($csrf) { $fields = ['csrf_token' => csrfFromSession($c)] + $fields; }
    }
    $off = logOffset();
    $r = cgi('POST', $action, http_build_query($fields), $c);
    $log = logSince($off);
    rec($id, ['kind' => 'action', 'role' => $role, 'request' => "POST $action" . ($raw === null ? " (Formular von $page)" : ' (direkt)'),
        'form_found' => $formFound, 'status' => $r['status'], 'location' => $r['location'], 'headers' => $r['headers'], 'toasts' => toasts($c),
        'log' => $log, 'db' => snap(), 'problems' => phpProblems($r['body'], $log), 'body' => $r['status'] === 302 ? '' : trim(strip_tags(substr($r['body'], 0, 300)))]);
}

/** Login-/Logout-Fälle */
function loginCase(string $id, string $typed, string $credKey, bool $withCsrf = true): void
{
    resetDb();
    $c = [];
    $p = cgi('GET', 'login.php', '', $c);
    $tok = preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $p['body'], $m) ? $m[1] : '';
    $fields = ['username' => $typed, 'password' => $GLOBALS['C'][$credKey] ?? 'falsches-Passwort-U0'];
    if ($withCsrf) { $fields = ['csrf_token' => $tok] + $fields; }
    $off = logOffset();
    $r = cgi('POST', 'login.php', http_build_query($fields), $c);
    $after = $r['status'] === 302 ? cgi('GET', 'pages/dashboard.php', '', $c) : null;
    $log = logSince($off);
    preg_match_all('#<div class="alert[^"]*"[^>]*>(.*?)</div>#s', $r['body'], $am);
    rec($id, ['kind' => 'action', 'role' => 'anon', 'request' => "POST login.php als „{$typed}“", 'status' => $r['status'], 'location' => $r['location'],
        'headers' => $r['headers'], 'alerts' => array_map(fn($t) => trim(preg_replace('/\s+/', ' ', strip_tags($t))), $am[1]),
        'next_dashboard' => $after ? [$after['status'], $after['location']] : null, 'session_user' => preg_match('/username\|s:\d+:"([^"]*)"/', sessRaw($c), $um) ? $um[1] : null,
        'log' => $log, 'db' => snap(), 'problems' => phpProblems($r['body'], $log)]);
}

loginCase('auth/login_ok', 'u0admin', 'u0admin');
loginCase('auth/login_case_variant', 'U0ADMIN', 'u0admin');
loginCase('auth/login_wrong_password', 'u0admin', 'gibt-es-nicht');
loginCase('auth/login_inactive', 'u0off', 'u0off');
loginCase('auth/login_unknown', 'u0unbekannt', 'gibt-es-nicht');
loginCase('auth/login_without_csrf', 'u0admin', 'u0admin', false);
submit('auth/logout_post', 'admin', 'pages/dashboard.php', 'logout.php', [], []);

// Zonen
submit('zone/add_admin', 'admin', 'pages/zones.php?add_new=1', 'actions/zone_add.php', ['type' => 'forward', 'zone_prefix' => 'neu.u0.test', 'soa_mail' => 'hostmaster.neu.u0.test.', 'server_ids' => ['1', '2'], 'master_server_id' => '1', 'description' => 'U0']);
submit('zone/add_zoneadmin_denied', 'zoneadmin', 'pages/zones.php', 'actions/zone_add.php', [], ['type' => 'forward', 'zone_prefix' => 'neu.u0.test']);
submit('zone/update_admin', 'admin', 'pages/zones.php?edit_id=1', 'actions/zone_update.php', ['ttl' => '7200'], null, 'editForm_1');
submit('zone/update_zoneadmin_own', 'zoneadmin', 'pages/zones.php?edit_id=1', 'actions/zone_update.php', ['ttl' => '7200'], null, 'editForm_1');
submit('zone/update_zoneadmin_foreign', 'zoneadmin', 'pages/zones.php', 'actions/zone_update.php', [], ['id' => '2', 'ttl' => '7200', 'soa_mail' => 'hostmaster.other.u0.test.',
    'soa_refresh' => '3600', 'soa_retry' => '900', 'soa_expire' => '1209600', 'soa_minimum' => '300', 'server_ids' => ['1'], 'master_server_id' => '1']);
submit('zone/delete_admin', 'admin', 'pages/zones.php', 'actions/zone_delete.php', [], ['id' => '2']);
submit('zone/delete_zoneadmin_denied', 'zoneadmin', 'pages/zones.php', 'actions/zone_delete.php', [], ['id' => '1']);

// Records
submit('record/add_admin_a', 'admin', 'pages/records.php?zone_id=1&add_new=1', 'actions/record_add.php', ['type' => 'A', 'name' => 'neu', 'content' => '192.0.2.90', 'ttl' => '3600']);
// TXT wie mit aktivem JavaScript (record_add_form.js entfernt das versteckte Feld is_dkim bei Nicht-DKIM-Einträgen)
submit('record/add_admin_txt', 'admin', 'pages/records.php?zone_id=1&add_new=1', 'actions/record_add.php', ['type' => 'TXT', 'name' => 'info', 'content' => '"u0-golden"', 'ttl' => '3600', 'is_dkim' => null]);
// Ist-Zustand ohne JavaScript: das Formular sendet is_dkim=1 mit, der TXT wird als DKIM geprüft (Befund für U4, serverseitiger Rückfall)
submit('record/add_admin_txt_ohne_js', 'admin', 'pages/records.php?zone_id=1&add_new=1', 'actions/record_add.php', ['type' => 'TXT', 'name' => 'info', 'content' => '"u0-golden"', 'ttl' => '3600']);
submit('record/add_admin_invalid', 'admin', 'pages/records.php?zone_id=1&add_new=1', 'actions/record_add.php', ['type' => 'A', 'name' => 'kaputt', 'content' => '999.1.1.1', 'ttl' => '3600']);
submit('record/add_zoneadmin_own', 'zoneadmin', 'pages/records.php?zone_id=1&add_new=1', 'actions/record_add.php', ['type' => 'A', 'name' => 'zone', 'content' => '192.0.2.91', 'ttl' => '3600']);
submit('record/add_zoneadmin_foreign', 'zoneadmin', 'pages/zones.php', 'actions/record_add.php', [], ['zone_id' => '2', 'type' => 'A', 'name' => 'fremd', 'content' => '192.0.2.92', 'ttl' => '3600']);
submit('record/add_without_csrf', 'admin', 'pages/zones.php', 'actions/record_add.php', [], ['zone_id' => '1', 'type' => 'A', 'name' => 'ohne', 'content' => '192.0.2.93', 'ttl' => '3600'], null, false);
submit('record/update_admin', 'admin', 'pages/records.php?zone_id=1&edit_id=3', 'actions/record_update.php', ['content' => '192.0.2.88']);
submit('record/delete_admin', 'admin', 'pages/records.php?zone_id=1', 'actions/record_delete.php', [], ['id' => '3']);
submit('record/delete_bulk_admin', 'admin', 'pages/records.php?zone_id=1', 'actions/record_delete.php', [], ['bulk_ids' => '3,4']);
submit('record/delete_zoneadmin_foreign', 'zoneadmin', 'pages/zones.php', 'actions/record_delete.php', [], ['id' => '10']);

// Server
submit('server/add_admin', 'admin', 'pages/servers.php?add_new=1', 'actions/server_add.php', ['name' => 'ns3.u0.test', 'dns_ip4' => '192.0.2.13', 'dns_ip6' => '', 'api_ip' => '127.0.0.4',
    'api_token' => str_repeat('c3', 20), 'is_local' => null, 'active' => '1']);
submit('server/update_admin_unchanged', 'admin', 'pages/servers.php?edit_id=2', 'actions/server_update.php', [], null, 'editForm_2');
submit('server/update_admin_change', 'admin', 'pages/servers.php?edit_id=2', 'actions/server_update.php', ['dns_ip4' => '192.0.2.22'], null, 'editForm_2');
submit('server/delete_admin', 'admin', 'pages/servers.php', 'actions/server_delete.php', [], ['id' => '2']);
submit('server/add_zoneadmin_denied', 'zoneadmin', 'pages/zones.php', 'actions/server_add.php', [], ['name' => 'ns9.u0.test', 'dns_ip4' => '192.0.2.19', 'api_ip' => '127.0.0.9', 'api_token' => str_repeat('d4', 20), 'active' => '1']);
submit('server/bind_reload_admin', 'admin', 'pages/servers.php', 'actions/bind_reload.php', [], ['id' => '1']);

// Publish All
submit('publish/all_admin', 'admin', 'pages/zones.php', 'actions/publish_all.php', ['return' => '/dnsmanager-ui/pages/zones.php']);
submit('publish/all_zoneadmin', 'zoneadmin', 'pages/zones.php', 'actions/publish_all.php', ['return' => '/dnsmanager-ui/pages/zones.php']);

// Benutzer
submit('user/add_admin', 'admin', 'pages/users.php?add_new=1', 'actions/user_add.php', ['username' => 'u0neu', 'password' => 'U0-Neues-Passwort-1', 'role' => 'zoneadmin', 'zones' => ['1']]);
submit('user/update_admin', 'admin', 'pages/users.php?edit_id=2', 'actions/user_update.php', ['zones' => ['1', '2']]);
submit('user/set_inactive_admin', 'admin', 'pages/users.php', 'actions/user_set_active.php', [], ['id' => '2', 'active' => '0']);
submit('user/delete_admin', 'admin', 'pages/users.php', 'actions/user_delete.php', [], ['id' => '4']);
submit('user/password_admin_other', 'admin', 'pages/users.php', 'actions/user_password.php', [], ['id' => '2', 'password' => 'U0-Neues-Passwort-2', 'password_repeat' => 'U0-Neues-Passwort-2']);
submit('user/password_zoneadmin_own', 'zoneadmin', 'pages/users.php', 'actions/user_password.php', [], ['id' => '2', 'password' => 'U0-Neues-Passwort-3', 'password_repeat' => 'U0-Neues-Passwort-3']);
submit('user/password_zoneadmin_other', 'zoneadmin', 'pages/users.php', 'actions/user_password.php', [], ['id' => '1', 'password' => 'U0-Neues-Passwort-4', 'password_repeat' => 'U0-Neues-Passwort-4']);
submit('user/add_zoneadmin_denied', 'zoneadmin', 'pages/users.php', 'actions/user_add.php', [], ['username' => 'u0fremd', 'password' => 'U0-Neues-Passwort-5', 'role' => 'admin']);

// DynDNS-Verwaltung
submit('dyndns/add_admin', 'admin', 'pages/dyndns.php?add_new=1', 'actions/dyndns_add.php', ['username' => 'u0dyn2', 'password' => 'U0-DynDNS-Passwort-1', 'hostname' => 'dyn2', 'zone_id' => '1']);
submit('dyndns/update_admin', 'admin', 'pages/dyndns.php?edit_id=1', 'actions/dyndns_update.php', ['hostname' => 'dyn3']);
submit('dyndns/password_admin', 'admin', 'pages/dyndns.php', 'actions/dyndns_password.php', [], ['id' => '1', 'password' => 'U0-DynDNS-Passwort-2']);
submit('dyndns/password_unknown_id', 'admin', 'pages/dyndns.php', 'actions/dyndns_password.php', [], ['id' => '999', 'password' => 'U0-DynDNS-Passwort-3']);
submit('dyndns/delete_admin', 'admin', 'pages/dyndns.php', 'actions/dyndns_delete.php', [], ['id' => '1']);
submit('dyndns/add_zoneadmin_denied', 'zoneadmin', 'pages/zones.php', 'actions/dyndns_add.php', [], ['username' => 'u0dyn9', 'password' => 'U0-DynDNS-Passwort-9', 'hostname' => 'dyn9', 'zone_id' => '1']);

// DynDNS-API (Basic Auth)
foreach (['api/dyndns_update_ok' => $GLOBALS['C']['u0dyn'], 'api/dyndns_update_badauth' => 'falsches-Passwort-U0'] as $id => $pw) {
    resetDb();
    $c = [];
    $off = logOffset();
    $r = cgi('GET', 'api/v1/dyndns/update.php?myip=192.0.2.98', '', $c, ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode("u0dyn:$pw")]);
    $log = logSince($off);
    rec($id, ['kind' => 'action', 'role' => 'dyndns', 'request' => 'GET api/v1/dyndns/update.php?myip=192.0.2.98 (Basic Auth u0dyn)', 'status' => $r['status'],
        'location' => $r['location'], 'headers' => $r['headers'], 'body' => trim($r['body']), 'log' => $log, 'db' => snap(), 'problems' => phpProblems($r['body'], $log)]);
}

ksort($res);
$out = ['meta' => ['harness' => 'tests/ui (U0)', 'commit' => $sha, 'php' => PHP_VERSION,
    'mariadb' => $GLOBALS['root']->query('SELECT VERSION()')->fetchColumn(), 'cases' => count($res)], 'cases' => $res];
file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "\nFälle: " . count($res) . " → $outFile\n";
