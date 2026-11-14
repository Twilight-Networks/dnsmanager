<?php
/**
 * Datei: tests/ui/u5b.php
 * Zweck: Funktions-, Rollen- und Schutzprüfungen für U5b (Server, DynDNS, Benutzer, Systemstatus, Update) gegen die
 *        Testkopie:
 *        R  Rollenmatrix (anonym, admin, zoneadmin): Status, Formulare und Aktionen je Rolle;
 *        O  Öffnen von Editoren und Bestätigungen ändert keinen Zustand (Datenbank, Sitzung);
 *        P  Passwortänderung ohne JavaScript über den Editor-Modus pw_id: admin für andere (ohne Wiederholung),
 *           eigenes Konto mit Wiederholung inkl. Abmeldung/Neuanmeldung (Session-Verhalten), zoneadmin nur eigenes
 *           Konto; DynDNS-Passwort;
 *        D  Deaktivieren (Inline-Bestätigung) und Aktivieren ohne JavaScript; Schutz des letzten aktiven Administrators
 *           und des eigenen Kontos in der Anzeige; Service lehnt manipulierte Anfragen weiterhin ab;
 *        B  BIND-Reload ohne JavaScript: ein Formular je Server mit unverändertem POST-/CSRF-Vertrag;
 *        E  Editoren: Server/DynDNS/Benutzer anlegen und bearbeiten über die ausgelieferten Formulare.
 * Aufruf (über run.sh matrix): php u5b.php
 */
require __DIR__ . '/lib.php';

$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };
$q = fn(string $sql) => $GLOBALS['root']->query(str_replace('DB.', '`' . DB . '`.', $sql));

function postPairs(string $path, array $pairs, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}
function formPairs(array $form, array $override = []): array
{
    $pairs = [];
    foreach ($form['values'] as $n => $v) {
        if (array_key_exists($n, $override) || $n === 'csrf_token') { continue; }
        if (is_array($v)) { foreach ($v as $x) { $pairs[] = [$n . '[]', $x]; } } else { $pairs[] = [$n, $v]; }
    }
    foreach ($override as $n => $v) {
        if ($v === null) { continue; }
        if (is_array($v)) { foreach ($v as $x) { $pairs[] = [$n . '[]', $x]; } } else { $pairs[] = [$n, $v]; }
    }
    return $pairs;
}
/** Formulare eines Ziels mit bestimmtem Feldwert */
function formsWhere(string $html, string $action, array $values): array
{
    return array_values(array_filter(forms($html), function ($f) use ($action, $values) {
        if ($f['action'] !== $action) { return false; }
        foreach ($values as $k => $v) { if (($f['values'][$k] ?? null) !== $v) { return false; } }
        return true;
    }));
}
/** Anmeldung mit ausdrücklichem Passwort (Testwert) */
function loginWith(string $user, string $password): array
{
    $c = [];
    $r = cgi('GET', 'login.php', '', $c);
    $tok = preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $r['body'], $m) ? $m[1] : '';
    $r = cgi('POST', 'login.php', http_build_query(['csrf_token' => $tok, 'username' => $user, 'password' => $password]), $c);
    return [$c, $r];
}
$user = fn(int $id) => $q("SELECT id, username, role, active, session_epoch FROM DB.users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
$hash = fn(int $id) => (string)$q("SELECT password_hash FROM DB.users WHERE id = $id")->fetchColumn();

// ---------- R: Rollenmatrix ----------
resetDb();
$sess = ['anon' => [], 'admin' => session('admin'), 'zoneadmin' => session('zoneadmin')];
$pages = ['pages/servers.php', 'pages/servers.php?add_new=1', 'pages/servers.php?edit_id=2', 'pages/dyndns.php', 'pages/dyndns.php?add_new=1',
    'pages/dyndns.php?edit_id=1', 'pages/dyndns.php?pw_id=1', 'pages/system_health.php', 'pages/update.php', 'pages/users.php', 'pages/users.php?add_new=1',
    'pages/users.php?edit_id=2', 'pages/users.php?pw_id=1', 'pages/users.php?pw_id=2'];
$rFail = [];
foreach ($pages as $p) {
    foreach (['anon', 'admin', 'zoneadmin'] as $role) {
        $c = $sess[$role];
        $r = cgi('GET', $p, '', $c);
        $adminOnly = !str_starts_with($p, 'pages/users.php');
        $want = $role === 'anon' ? 302 : ($role === 'zoneadmin' && $adminOnly ? 403 : 200);
        if ($r['status'] !== $want) { $rFail[] = "$role $p {$r['status']} statt $want"; continue; }
        if ($r['status'] !== 200) { continue; }
        $actions = array_column(forms($r['body']), 'action');
        if ($p === 'pages/users.php?edit_id=2' && in_array('actions/user_update.php', $actions, true) !== ($role === 'admin')) { $rFail[] = "$role $p user_update"; }
        if ($p === 'pages/users.php?add_new=1' && in_array('actions/user_add.php', $actions, true) !== ($role === 'admin')) { $rFail[] = "$role $p user_add"; }
        if ($p === 'pages/users.php?pw_id=1' && in_array('actions/user_password.php', $actions, true) !== ($role === 'admin')) { $rFail[] = "$role $p Passwort fremd"; }
        if ($p === 'pages/users.php?pw_id=2' && !in_array('actions/user_password.php', $actions, true)) { $rFail[] = "$role $p Passwort eigen/zoneadmin fehlt"; }
        if ($role === 'zoneadmin' && $p === 'pages/users.php') {
            if (array_intersect($actions, ['actions/user_set_active.php', 'actions/user_delete.php', 'actions/user_update.php', 'actions/user_add.php'])) { $rFail[] = "zoneadmin users: Verwaltungsformulare"; }
            if (str_contains($r['body'], 'u0admin')) { $rFail[] = 'zoneadmin sieht fremde Konten'; }
        }
    }
}
$ok($rFail === [], 'R01', count($pages) . ' Seiten × 3 Rollen: Status (admin-Seiten 403 für zoneadmin), Benutzerverwaltung nur admin, Passwort nur sichtbare Konten' . ($rFail ? ': ' . implode(' | ', $rFail) : ''));

// ---------- O: Öffnen ändert keinen Zustand ----------
resetDb();
$a = session('admin');
cgi('GET', 'pages/users.php', '', $a);
$s0 = snap(); $sess0 = sessNorm($a); $off = logOffset();
foreach (['pages/users.php?pw_id=2', 'pages/users.php?pw_id=1', 'pages/users.php?edit_id=2', 'pages/users.php?add_new=1', 'pages/dyndns.php?pw_id=1',
          'pages/dyndns.php?edit_id=1', 'pages/dyndns.php?add_new=1', 'pages/servers.php?edit_id=1', 'pages/servers.php?add_new=1', 'pages/servers.php'] as $p) {
    cgi('GET', $p, '', $a);
}
$log = array_filter(logSince($off), fn($l) => !str_contains($l, 'event=page_view'));
$ok(snap() === $s0 && sessNorm($a) === $sess0 && array_filter($log, fn($l) => str_contains($l, 'php_error')) === [], 'O01',
    '10 Editoren/Bestätigungen geöffnet: Datenbank und Sitzung unverändert, keine PHP-Meldungen');

// ---------- P: Passwortänderung ohne JavaScript ----------
// admin ändert Passwort eines anderen Kontos (ohne Wiederholung, wie bisher)
resetDb();
$a = session('admin');
$f = formFor(cgi('GET', 'pages/users.php?pw_id=2', '', $a)['body'], 'actions/user_password.php');
$fields = array_map(fn($x) => explode(':', $x)[0], $f['fields'] ?? []);
sort($fields);
$h0 = $hash(2);
$r = postPairs('actions/user_password.php', formPairs($f, ['password' => 'U5b-Testpasswort-2']), $a);
$ok($fields === ['csrf_token', 'id', 'password'] && $r['status'] === 302 && $hash(2) !== $h0 && str_starts_with((string)(toasts($a)[0] ?? ''), 'success'), 'P01',
    'admin → anderes Konto: Felder ' . implode(',', $fields) . ', gespeichert, Erfolgs-Toast');
[$cz, $rz] = loginWith('u0zone', 'U5b-Testpasswort-2');
$ok($rz['status'] === 302 && str_contains((string)$rz['location'], 'dashboard'), 'P02', 'Anmeldung mit dem neuen Passwort möglich');
// zoneadmin ändert eigenes Passwort (mit Wiederholung): Abmeldung und Neuanmeldung wie bisher
resetDb();
$z = session('zoneadmin');
$f = formFor(cgi('GET', 'pages/users.php?pw_id=2', '', $z)['body'], 'actions/user_password.php');
$fields = array_map(fn($x) => explode(':', $x)[0], $f['fields'] ?? []);
sort($fields);
$r = postPairs('actions/user_password.php', formPairs($f, ['password' => 'U5b-Testpasswort-3', 'password_repeat' => 'U5b-Testpasswort-3']), $z);
$after = cgi('GET', 'pages/dashboard.php', '', $z);
[$c2, $r2] = loginWith('u0zone', 'U5b-Testpasswort-3');
$ok($fields === ['csrf_token', 'id', 'password', 'password_repeat'] && $r['status'] === 302 && str_ends_with((string)$r['location'], '/login.php')
    && $after['status'] === 302 && str_contains((string)$after['location'], 'login') && $r2['status'] === 302 && str_contains((string)$r2['location'], 'dashboard'), 'P03',
    'zoneadmin eigenes Konto: Felder mit Wiederholung, Weiterleitung zur Anmeldung, alte Sitzung beendet, Neuanmeldung mit neuem Passwort');
// eigenes Konto mit abweichender Wiederholung: abgelehnt, Passwort unverändert
resetDb();
$a = session('admin');
$f = formFor(cgi('GET', 'pages/users.php?pw_id=1', '', $a)['body'], 'actions/user_password.php');
$h0 = $hash(1);
postPairs('actions/user_password.php', formPairs($f, ['password' => 'U5b-Testpasswort-4', 'password_repeat' => 'U5b-Testpasswort-5']), $a);
$ok($hash(1) === $h0 && str_starts_with((string)(toasts($a)[0] ?? ''), 'error'), 'P04', 'admin eigenes Konto mit abweichender Wiederholung: abgelehnt, Passwort unverändert');
// zoneadmin: kein Formular für fremde Konten; manipulierte Anfrage weiterhin abgelehnt
resetDb();
$z = session('zoneadmin');
cgi('GET', 'pages/users.php', '', $z);
$h0 = $hash(1);
$r = postPairs('actions/user_password.php', [['id', '1'], ['password', 'U5b-Testpasswort-6'], ['password_repeat', 'U5b-Testpasswort-6']], $z);
$ok(formFor(cgi('GET', 'pages/users.php?pw_id=1', '', $z)['body'], 'actions/user_password.php') === null && $hash(1) === $h0, 'P05',
    "zoneadmin: kein Passwortformular für fremdes Konto; manipulierte Anfrage abgelehnt ({$r['status']}), Passwort unverändert");
// DynDNS-Passwort über den Editor
resetDb();
$a = session('admin');
$f = formFor(cgi('GET', 'pages/dyndns.php?pw_id=1', '', $a)['body'], 'actions/dyndns_password.php');
$dh0 = (string)$q('SELECT password_hash FROM DB.dyndns_accounts WHERE id = 1')->fetchColumn();
$r = postPairs('actions/dyndns_password.php', formPairs($f, ['password' => 'U5b-DynDNS-Test-1']), $a);
$ok($f !== null && $r['status'] === 302 && (string)$q('SELECT password_hash FROM DB.dyndns_accounts WHERE id = 1')->fetchColumn() !== $dh0, 'P06',
    'DynDNS-Passwort über den Editor-Modus (ohne JavaScript) gespeichert');

// ---------- D: Deaktivieren/Aktivieren und Schutzregeln ----------
resetDb();
$a = session('admin');
$h = cgi('GET', 'pages/users.php', '', $a)['body'];
$disable2 = formsWhere($h, 'actions/user_set_active.php', ['id' => '2', 'active' => '0']);
$ok(count($disable2) === 1 && str_contains($h, 'data-dm-confirm-inline') && formsWhere($h, 'actions/user_set_active.php', ['id' => '1', 'active' => '0']) === []
    && formsWhere($h, 'actions/user_set_active.php', ['id' => '3', 'active' => '1']) !== [], 'D01',
    'Anzeige: Deaktivieren für zoneadmin (Inline-Bestätigung), nicht für das eigene Konto; Aktivieren für inaktives Konto');
$r = postPairs('actions/user_set_active.php', formPairs($disable2[0]), $a);
$ok((int)$user(2)['active'] === 0 && $r['status'] === 302, 'D02', 'Deaktivieren über das ausgelieferte Formular (ohne JavaScript): Konto inaktiv');
$h = cgi('GET', 'pages/users.php', '', $a)['body'];
$enable2 = formsWhere($h, 'actions/user_set_active.php', ['id' => '2', 'active' => '1']);
postPairs('actions/user_set_active.php', formPairs($enable2[0]), $a);
$ok((int)$user(2)['active'] === 1, 'D03', 'Aktivieren über das ausgelieferte Formular: Konto wieder aktiv');
// letzter aktiver Administrator: u0admin2 deaktivieren → u0admin ist letzter aktiver Admin
$q('UPDATE DB.users SET active = 0 WHERE id = 4');
$h = cgi('GET', 'pages/users.php', '', $a)['body'];
$ok(formsWhere($h, 'actions/user_set_active.php', ['id' => '1', 'active' => '0']) === [] && formsWhere($h, 'actions/user_delete.php', ['id' => '1']) === []
    && formsWhere($h, 'actions/user_delete.php', ['id' => '4']) !== [], 'D04', 'letzter aktiver Admin: weder Deaktivieren noch Löschen angeboten; inaktiver Admin löschbar');
$r = postPairs('actions/user_set_active.php', [['id', '1'], ['active', '0']], $a);
$ok((int)$user(1)['active'] === 1, 'D05', 'manipuliertes Deaktivieren des eigenen/letzten Admin-Kontos vom UserService abgelehnt');
$q('UPDATE DB.users SET active = 1 WHERE id = 4');
$h = cgi('GET', 'pages/users.php', '', $a)['body'];
$ok(formsWhere($h, 'actions/user_set_active.php', ['id' => '4', 'active' => '0']) !== [] && formsWhere($h, 'actions/user_delete.php', ['id' => '1']) !== [], 'D06',
    'zwei aktive Admins: anderer Admin deaktivierbar, eigenes Konto löschbar angeboten (wie bisher, Service prüft)');

// ---------- B: BIND-Reload ohne JavaScript ----------
resetDb();
$a = session('admin');
$h = cgi('GET', 'pages/servers.php', '', $a)['body'];
$reload = array_values(array_filter(forms($h), fn($f) => $f['action'] === 'actions/bind_reload.php'));
$ids = array_map(fn($f) => $f['values']['id'] ?? null, $reload);
sort($ids);
$fields = array_map(fn($x) => explode(':', $x)[0], $reload[0]['fields'] ?? []);
sort($fields);
$ok($ids === ['1', '2'] && $fields === ['csrf_token', 'id'] && $reload[0]['method'] === 'post' && !str_contains($h, 'reloadConfirmModal'), 'B01',
    'je Server ein POST-Formular bind_reload.php mit csrf_token und id; kein Modal');
$off = logOffset();
$r = postPairs('actions/bind_reload.php', formPairs($reload[0]), $a);
$t = toasts($a);
$ok($r['status'] === 302 && $t !== [] && array_filter(logSince($off), fn($l) => str_contains($l, 'php_error')) === [], 'B02',
    'BIND-Reload über das ausgelieferte Formular: ' . $r['status'] . ', ' . substr((string)$t[0], 0, 80));

// ---------- E: Editoren anlegen/bearbeiten ----------
resetDb();
$a = session('admin');
$f = formFor(cgi('GET', 'pages/servers.php?edit_id=2', '', $a)['body'], 'actions/server_update.php');
$tok0 = (string)$q('SELECT api_token FROM DB.servers WHERE id = 2')->fetchColumn();
$r = postPairs('actions/server_update.php', formPairs($f, ['dns_ip6' => '2001:db8::12']), $a);
$srv = $q('SELECT dns_ip6, api_token, active, is_local FROM DB.servers WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
$ok($srv['dns_ip6'] === '2001:db8::12' && $srv['api_token'] === $tok0 && (int)$srv['active'] === 1 && (int)$srv['is_local'] === 0 && !str_contains(cgi('GET', 'pages/servers.php?edit_id=2', '', $a)['body'], $tok0), 'E01',
    'Server bearbeiten: gespeichert, leerer Token = unverändert, Token nie ausgegeben');
$f = formFor(cgi('GET', 'pages/servers.php?add_new=1', '', $a)['body'], 'actions/server_add.php');
$r = postPairs('actions/server_add.php', formPairs($f, ['name' => 'ns9.u0.test', 'dns_ip4' => '192.0.2.19', 'api_ip' => '127.0.0.9', 'api_token' => str_repeat('e5', 32)]), $a);
$ok((int)$q("SELECT COUNT(*) FROM DB.servers WHERE name = 'ns9.u0.test'")->fetchColumn() === 1, 'E02', 'Server anlegen über den Editor (ohne JavaScript)');
$f = formFor(cgi('GET', 'pages/dyndns.php?edit_id=1', '', $a)['body'], 'actions/dyndns_update.php');
$r = postPairs('actions/dyndns_update.php', formPairs($f, ['hostname' => 'dyn2']), $a);
$ok((string)$q('SELECT hostname FROM DB.dyndns_accounts WHERE id = 1')->fetchColumn() === 'dyn2', 'E03', 'DynDNS-Account bearbeiten über den Editor');
$f = formFor(cgi('GET', 'pages/users.php?edit_id=2', '', $a)['body'], 'actions/user_update.php');
$r = postPairs('actions/user_update.php', formPairs($f, ['zones' => ['1', '2']]), $a);
$zones = $q('SELECT zone_id FROM DB.user_zones WHERE user_id = 2 ORDER BY zone_id')->fetchAll(PDO::FETCH_COLUMN);
$ok(array_map('intval', $zones) === [1, 2], 'E04', 'Benutzer bearbeiten (Zonen 1, 2) über den Editor: ' . json_encode($zones));
$f = formFor(cgi('GET', 'pages/users.php?add_new=1', '', $a)['body'], 'actions/user_add.php');
$r = postPairs('actions/user_add.php', formPairs($f, ['username' => 'u5bneu', 'password' => 'U5b-Testpasswort-7', 'role' => 'zoneadmin', 'zones' => ['1']]), $a);
$nu = $q("SELECT id, role FROM DB.users WHERE username = 'u5bneu'")->fetch(PDO::FETCH_ASSOC);
$ok($nu && $nu['role'] === 'zoneadmin' && (int)$q('SELECT COUNT(*) FROM DB.user_zones WHERE user_id = ' . (int)$nu['id'])->fetchColumn() === 1, 'E05', 'Benutzer anlegen über den Editor (zoneadmin mit Zone 1)');

printf("== U5b: %d/%d bestanden\n", $pass, $pass + $fail);
exit($fail ? 1 : 0);
