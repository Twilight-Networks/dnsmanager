<?php
/**
 * Datei: tests/ui/u5a.php
 * Zweck: Funktions-, Rollen- und Vertragsprüfungen für U5a (Dashboard, Zonenliste, Zoneneditor) gegen die Testkopie:
 *        R  Rollenmatrix (anonym, admin, zoneadmin) für Dashboard, Zonenliste, Anlage und Bearbeitung eigener/fremder
 *           Zonen: Status, vorhandene Formulare, Löschen/Anlegen nur admin, Serverzuweisung nur admin als Eingabe;
 *        T  No-JS-Typübernahme bei der Anlage: CSRF-Pflicht, keine Schreiboperation, alle Werte erhalten;
 *        A  No-JS-Anlage (Forward und Reverse nach Typübernahme) und No-JS-Bearbeitung (admin, zoneadmin) über die
 *           ausgelieferten Formulare; zoneadmin ändert keine Serverzuweisung;
 *        S  S21: Absenden mit veraltetem edit_version wird abgelehnt, nichts geändert;
 *        D  Dashboard: admin globale Kennzahlen (= Datenbank), zoneadmin nur eigene Zonen; Diagnosefehler und
 *           ausstehende Veröffentlichung getrennt (Status über zoneDisplayStatus);
 *        G  Zonenliste und Dashboard mit mehr als 500 Zonen (admin 603, zoneadmin 551), alle erreichbar.
 * Aufruf (über run.sh): php u5a.php
 */
require __DIR__ . '/lib.php';
require APP . '/web/inc/app/Actor.php';
require APP . '/web/inc/app/ZoneAccess.php';
require APP . '/web/inc/app/zones/ZoneReadService.php';

$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };
$enc = fn(array $pairs) => implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs));
$q = fn(string $sql) => $GLOBALS['root']->query(str_replace('DB.', '`' . DB . '`.', $sql));

/** POST mit dem CSRF-Token der Sitzung */
function postPairs(string $path, array $pairs, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}
/** Formularwerte als Paare (Mehrfachnamen wie server_ids[] einzeln) */
function formPairs(array $form, array $override = []): array
{
    $pairs = [];
    foreach ($form['values'] as $n => $v) {
        if (array_key_exists($n, $override)) { continue; }
        if (is_array($v)) { foreach ($v as $x) { $pairs[] = [$n . '[]', $x]; } } else { $pairs[] = [$n, $v]; }
    }
    foreach ($override as $n => $v) {
        if ($v === null) { continue; }
        if (is_array($v)) { foreach ($v as $x) { $pairs[] = [$n . '[]', $x]; } } else { $pairs[] = [$n, $v]; }
    }
    return array_values(array_filter($pairs, fn($p) => $p[0] !== 'csrf_token'));
}
function zoneRow(int $id): array|false
{
    $s = $GLOBALS['root']->query('SELECT id, name, type, ttl, prefix_length, description, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, allow_dyndns FROM `' . DB . "`.zones WHERE id = $id");
    return $s->fetch(PDO::FETCH_ASSOC);
}
function assignment(int $id): array
{
    return $GLOBALS['root']->query('SELECT server_id, is_master FROM `' . DB . "`.zone_servers WHERE zone_id = $id ORDER BY server_id")->fetchAll(PDO::FETCH_NUM);
}

// ---------- R: Rollenmatrix ----------
resetDb();
$sess = ['anon' => [], 'admin' => session('admin'), 'zoneadmin' => session('zoneadmin')];
$expect = [
    // Seite => [anon, admin, zoneadmin] (Status; Formular zone_update/zone_add vorhanden?)
    'pages/dashboard.php'          => [[302], [200], [200]],
    'pages/zones.php'              => [[302], [200], [200]],
    'pages/zones.php?add_new=1'    => [[302], [200, 'actions/zone_add.php'], [200, null]],
    'pages/zones.php?edit_id=1'    => [[302], [200, 'actions/zone_update.php'], [200, 'actions/zone_update.php']],
    'pages/zones.php?edit_id=2'    => [[302], [200, 'actions/zone_update.php'], [200, null]],
    'pages/zones.php?edit_id=3'    => [[302], [200, 'actions/zone_update.php'], [200, null]],
];
$rFail = [];
foreach ($expect as $page => $byRole) {
    foreach (['anon', 'admin', 'zoneadmin'] as $i => $role) {
        $c = $sess[$role];
        $r = cgi('GET', $page, '', $c);
        $want = $byRole[$i];
        if ($r['status'] !== $want[0]) { $rFail[] = "$role $page Status {$r['status']}"; continue; }
        if ($r['status'] !== 200) { continue; }
        $forms = forms($r['body']);
        $actions = array_column($forms, 'action');
        if (array_key_exists(1, $want)) {
            foreach (['actions/zone_add.php', 'actions/zone_update.php'] as $a) {
                $has = in_array($a, $actions, true);
                if ($has !== ($want[1] === $a)) { $rFail[] = "$role $page Formular $a " . ($has ? 'vorhanden' : 'fehlt'); }
            }
        }
        $hasDelete = in_array('actions/zone_delete.php', $actions, true);
        if (str_starts_with($page, 'pages/zones.php') && $hasDelete !== ($role === 'admin')) { $rFail[] = "$role $page Löschen " . ($hasDelete ? 'angeboten' : 'fehlt'); }
        $hasAddLink = str_contains($r['body'], 'href="pages/zones.php?add_new=1"');
        if (str_starts_with($page, 'pages/zones.php') && $hasAddLink !== ($role === 'admin')) { $rFail[] = "$role $page Anlage-Link"; }
        $upd = formFor($r['body'], 'actions/zone_update.php');
        if ($upd !== null) {
            $srvInputs = (bool)array_filter($upd['fields'], fn($f) => str_starts_with($f, 'server_ids[]') || str_starts_with($f, 'master_server_id'));
            if ($srvInputs !== ($role === 'admin')) { $rFail[] = "$role $page Serverzuweisung als Eingabe: " . var_export($srvInputs, true); }
            if (!in_array('edit_version:hidden', $upd['fields'], true) || !in_array('csrf_token:hidden', $upd['fields'], true)) { $rFail[] = "$role $page edit_version/CSRF fehlt"; }
        }
    }
}
$ok($rFail === [], 'R01', 'Rollenmatrix 6 Seiten × 3 Rollen: Status, Anlage/Löschen nur admin, fremde Zonen ohne Formular, Serverzuweisung nur admin als Eingabe, edit_version/CSRF vorhanden' . ($rFail ? ': ' . implode(' | ', $rFail) : ''));
$add = formFor(cgi('GET', 'pages/zones.php?add_new=1', '', $sess['admin'])['body'], 'actions/zone_add.php');
$fieldsAdd = array_map(fn($f) => explode(':', $f)[0], $add['fields'] ?? []);
$ok($add !== null && array_diff(['csrf_token', 'zone_prefix', 'type', 'prefix_length', 'ttl', 'soa_domain', 'soa_mail', 'soa_refresh', 'soa_retry', 'soa_expire',
    'soa_minimum', 'server_ids[]', 'master_server_id', 'allow_dyndns', 'description'], $fieldsAdd) === [], 'R02', 'Anlageformular enthält alle bisherigen Felder (' . count($fieldsAdd) . ')');

// ---------- T: No-JS-Typübernahme ----------
resetDb();
$c = session('admin');
cgi('GET', 'pages/zones.php?add_new=1', '', $c);
$typed = [['zone_prefix', '10.10'], ['type', 'reverse_ipv4'], ['prefix_length', ''], ['ttl', '7200'], ['soa_domain', 'u0.test'], ['soa_mail', 'hostmaster.u0.test.'],
    ['soa_refresh', '3601'], ['soa_retry', '1201'], ['soa_expire', '1209601'], ['soa_minimum', '3601'], ['server_ids[]', '1'], ['server_ids[]', '2'],
    ['master_server_id', '2'], ['allow_dyndns', '1'], ['description', 'Typwechsel <ohne> JS'], ['apply_type', '1']];
$r = cgi('POST', 'pages/zones.php?add_new=1', $enc($typed), $c);
$ok($r['status'] === 403, 'T01', "Typübernahme ohne CSRF → {$r['status']}");
$s0 = snap(); $off = logOffset();
$r = postPairs('pages/zones.php?add_new=1', $typed, $c);
$f = formFor($r['body'], 'actions/zone_add.php');
$v = $f['values'] ?? [];
$ok($r['status'] === 200 && snap() === $s0 && toasts($c) === [] && array_filter(logSince($off), fn($l) => str_contains($l, 'php_error')) === [], 'T02',
    'Typübernahme: 200, Datenbank unverändert, keine Toasts, keine PHP-Meldungen');
$ok(($v['zone_prefix'] ?? '') === '10.10' && ($v['type'] ?? '') === 'reverse_ipv4' && ($v['prefix_length'] ?? '') === '24' && ($v['ttl'] ?? '') === '7200'
    && ($v['soa_domain'] ?? '') === 'u0.test' && ($v['soa_mail'] ?? '') === 'hostmaster.u0.test.' && ($v['soa_refresh'] ?? '') === '3601'
    && ($v['soa_minimum'] ?? '') === '3601' && ($v['server_ids'] ?? []) === ['1', '2'] && ($v['master_server_id'] ?? '') === '2' && ($v['allow_dyndns'] ?? '') === '1'
    && ($v['description'] ?? '') === 'Typwechsel <ohne> JS', 'T03', 'alle Werte erhalten (Präfixlänge leer → Vorgabe 24), Reverse-Felder sichtbar: ' . json_encode($v, JSON_UNESCAPED_UNICODE));
$ok(!preg_match('#data-dm-zone-reverse-only hidden#', $r['body']) && preg_match('#data-dm-zone-forward-only hidden#', $r['body']) && str_contains($r['body'], '.in-addr.arpa'),
    'T04', 'Reverse-Felder serverseitig eingeblendet, Forward-Anzeige ausgeblendet, Suffix .in-addr.arpa');

// ---------- A: No-JS-Anlage und -Bearbeitung ----------
// Forward direkt
resetDb();
$c = session('admin');
$f = formFor(cgi('GET', 'pages/zones.php?add_new=1', '', $c)['body'], 'actions/zone_add.php');
$pairs = formPairs($f, ['zone_prefix' => 'nojs.test', 'soa_mail' => 'hostmaster.nojs.test.', 'server_ids' => ['1', '2'], 'master_server_id' => '1', 'description' => 'No-JS']);
$r = postPairs('actions/zone_add.php', $pairs, $c);
$z = $q("SELECT id FROM DB.zones WHERE name = 'nojs.test'")->fetchColumn();
$row = $z ? zoneRow((int)$z) : false;
$ok($r['status'] === 302 && $row && $row['type'] === 'forward' && (int)$row['ttl'] === 86400 && $row['soa_ns'] === 'ns1.u0.test.nojs.test.' && assignment((int)$z) === [[1, 1], [2, 0]],
    'A01', 'No-JS-Anlage Forward: Zone mit Vorgaben, SOA-NS nach ZoneService-Regel (Master außerhalb der Zone + Zonenname), Zuweisung ns1 (Master) + ns2: ' . json_encode($row ? [$row['ttl'], $row['soa_ns'], $row['prefix_length']] : null));
// Reverse nach Typübernahme
resetDb();
$c = session('admin');
cgi('GET', 'pages/zones.php?add_new=1', '', $c);
$r = postPairs('pages/zones.php?add_new=1', [['zone_prefix', '10.10'], ['type', 'reverse_ipv4'], ['ttl', '7200'], ['soa_mail', 'hostmaster.u0.test.'], ['apply_type', '1']], $c);
$f = formFor($r['body'], 'actions/zone_add.php');
$pairs = formPairs($f, ['soa_domain' => 'u0.test', 'server_ids' => ['1'], 'master_server_id' => '1']);
$r = postPairs('actions/zone_add.php', $pairs, $c);
$z = $q("SELECT id FROM DB.zones WHERE name = '10.10.in-addr.arpa'")->fetchColumn();
$row = $z ? zoneRow((int)$z) : false;
$ok($r['status'] === 302 && $row && $row['type'] === 'reverse' && (int)$row['prefix_length'] === 24 && (int)$row['ttl'] === 7200 && $row['soa_ns'] === 'ns1.u0.test.u0.test.',
    'A02', 'No-JS-Anlage Reverse nach Typübernahme: ' . json_encode($row ? [$row['name'], $row['prefix_length'], $row['ttl'], $row['soa_ns']] : null));
// Bearbeitung admin (No-JS-Formular): TTL und Serverzuweisung
resetDb();
$c = session('admin');
$f = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $c)['body'], 'actions/zone_update.php');
$r = postPairs('actions/zone_update.php', formPairs($f, ['ttl' => '7200']), $c);
$row = zoneRow(1);
$ok($r['status'] === 302 && (int)$row['ttl'] === 7200 && assignment(1) === [[1, 1], [2, 0]] && str_starts_with((string)(toasts($c)[0] ?? ''), 'success'), 'A03',
    'No-JS-Bearbeitung admin: TTL 7200 gespeichert, Zuweisung unverändert');
$f = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $c)['body'], 'actions/zone_update.php');
$r = postPairs('actions/zone_update.php', formPairs($f, ['server_ids' => ['2'], 'master_server_id' => '2']), $c);
$ok(assignment(1) === [[2, 1]], 'A04', 'admin ändert Serverzuweisung (nur ns2 als Master): ' . json_encode(assignment(1)));
// Bearbeitung zoneadmin: ohne Serverfelder; Zuweisung bleibt, auch bei manipuliert gesendeten Serverfeldern
resetDb();
$c = session('zoneadmin');
$f = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $c)['body'], 'actions/zone_update.php');
$r = postPairs('actions/zone_update.php', formPairs($f, ['ttl' => '7200', 'description' => 'zoneadmin']), $c);
$row = zoneRow(1);
$ok($r['status'] === 302 && (int)$row['ttl'] === 7200 && $row['description'] === 'zoneadmin' && assignment(1) === [[1, 1], [2, 0]], 'A05',
    'No-JS-Bearbeitung zoneadmin: TTL/Beschreibung gespeichert, Serverzuweisung unverändert');
$f = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $c)['body'], 'actions/zone_update.php');
postPairs('actions/zone_update.php', formPairs($f, ['server_ids' => ['2'], 'master_server_id' => '2']), $c);
$ok(assignment(1) === [[1, 1], [2, 0]], 'A06', 'zoneadmin mit manipuliert gesendeter Serverzuweisung: vom ZoneService ignoriert (unverändert)');

// ---------- S: S21 veraltetes edit_version ----------
resetDb();
$a = session('admin');
$stale = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $a)['body'], 'actions/zone_update.php');
$fresh = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $a)['body'], 'actions/zone_update.php');
postPairs('actions/zone_update.php', formPairs($fresh, ['ttl' => '7200']), $a);
$before = zoneRow(1);
cgi('GET', 'pages/zones.php', '', $a); // Erfolgs-Toast der ersten Änderung anzeigen (aus der Sitzung entfernt)
$r = postPairs('actions/zone_update.php', formPairs($stale, ['soa_retry' => '901']), $a);
$after = zoneRow(1);
$t = toasts($a);
$ok($before === $after && (int)$after['ttl'] === 7200 && (int)$after['soa_retry'] === 900 && $t !== [] && str_starts_with($t[0], 'error'), 'S01',
    'veraltetes edit_version: abgelehnt, nichts geändert (' . ($t[0] ?? '-') . ')');
$z = session('zoneadmin');
$stale = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $z)['body'], 'actions/zone_update.php');
postPairs('actions/zone_update.php', formPairs(formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $a)['body'], 'actions/zone_update.php'), ['soa_refresh' => '3700']), $a);
$before = zoneRow(1);
postPairs('actions/zone_update.php', formPairs($stale, ['description' => 'veraltet']), $z);
$ok(zoneRow(1) === $before, 'S02', 'zoneadmin mit veraltetem edit_version (Änderung durch admin dazwischen): abgelehnt, nichts geändert');

// ---------- D: Dashboard ----------
resetDb();
// Zone 2: Diagnosefehler; Zone 1: ausstehend (Fixture changed=1); Zone 3: ausstehend und Warnung
$q("UPDATE DB.diagnostics SET status = 'error', message = 'Testfehler' WHERE id = 7");
$q("UPDATE DB.diagnostics SET status = 'warning', message = 'Testwarnung' WHERE id = 8");
$q("UPDATE DB.zones SET changed = 1 WHERE id = 3");
$counts = $q('SELECT (SELECT COUNT(*) FROM DB.users), (SELECT COUNT(*) FROM DB.zones), (SELECT COUNT(*) FROM DB.records)')->fetch(PDO::FETCH_NUM);
$kpi = function (string $html): array {
    preg_match_all('#<div class="dm-kpi-label">.*?<span>(.*?)</span></div><div class="dm-kpi-value">(\d+)#s', $html, $m, PREG_SET_ORDER);
    return array_combine(array_map(fn($x) => html_entity_decode($x[1]), $m), array_map(fn($x) => (int)$x[2], $m));
};
$c = session('admin');
$h = cgi('GET', 'pages/dashboard.php', '', $c)['body'];
$k = $kpi($h);
$ok(($k['Zonen'] ?? -1) === (int)$counts[1] && ($k['Einträge'] ?? -1) === (int)$counts[2] && ($k['Benutzer'] ?? -1) === (int)$counts[0]
    && ($k['Ausstehende Veröffentlichungen'] ?? -1) === 2 && ($k['Zonen mit Prüfproblemen'] ?? -1) === 2 && str_contains($h, '1 Fehler, 1 Warnungen'), 'D01',
    'admin: Kennzahlen = Datenbank (global), 2 ausstehend, 2 Prüfprobleme (1 Fehler, 1 Warnung): ' . json_encode($k, JSON_UNESCAPED_UNICODE));
// Statuskennzeichen gegen zoneDisplayStatus (Core) und getrennte Ausweisung von ausstehend
$svc = new ZoneReadService(new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
    new ZoneAccess(new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '')));
$actor = new Actor(1, 'u0admin', 'admin', '127.0.0.1', 'cli');
$ov = $svc->getZoneStatusOverview($actor);
$zp = cgi('GET', 'pages/zones.php', '', $c)['body'];
$dFail = [];
$labels = ['error' => 'Fehler', 'warning' => 'Warnung', 'changed' => 'Ausstehend', 'ok' => 'Gültig'];
foreach ($svc->listZones($actor, 500, 0)['zones'] as $zn) {
    $core = ZoneReadService::zoneDisplayStatus($ov['zones'][$zn['id']]['status'] ?? null, $zn['pendingPublish']);
    if (!preg_match('#>' . preg_quote($zn['name'], '#') . '</a>.*?<td class="dm-col-status">(.*?)</td>#s', $zp, $m)) { $dFail[] = "$zn[name] fehlt"; continue; }
    $shown = array_map('html_entity_decode', preg_match_all('#</svg>([^<]+)</span>#', $m[1], $cm) ? $cm[1] : []);
    $expected = [$labels[$core]];
    if ($zn['pendingPublish'] && $core !== 'changed') { $expected[] = 'Ausstehend'; }
    if ($shown !== $expected) { $dFail[] = "$zn[name]: " . json_encode($shown, JSON_UNESCAPED_UNICODE) . ' statt ' . json_encode($expected, JSON_UNESCAPED_UNICODE); }
}
$ok($dFail === [], 'D02', 'Statuskennzeichen je Zone = zoneDisplayStatus (Core); ausstehende Veröffentlichung bei Diagnoseproblem zusätzlich ausgewiesen' . ($dFail ? ': ' . implode(' | ', $dFail) : ''));
$ok(substr_count($h, 'class="dm-col-status"') === 3, 'D03', 'admin: „Zonen mit Handlungsbedarf“ enthält genau die 3 betroffenen Zonen');
$c = session('zoneadmin');
$h = cgi('GET', 'pages/dashboard.php', '', $c)['body'];
$k = $kpi($h);
$own = (int)$q('SELECT COUNT(*) FROM DB.records WHERE zone_id = 1')->fetchColumn();
$ok(($k['Meine Zonen'] ?? -1) === 1 && ($k['Einträge'] ?? -1) === $own && ($k['Ausstehende Veröffentlichungen'] ?? -1) === 1 && ($k['Zonen mit Prüfproblemen'] ?? -1) === 0
    && !isset($k['Benutzer']) && !str_contains($h, 'other.u0.test') && !str_contains($h, 'in-addr.arpa') && !str_contains($h, 'Systemstatus fehlerfrei') && !str_contains($h, 'Es wurden Probleme erkannt'), 'D04',
    "zoneadmin: nur eigene Zone (Einträge $own statt global {$counts[2]}), keine fremden Zonen, kein Systemstatus/Benutzer: " . json_encode($k, JSON_UNESCAPED_UNICODE));

// ---------- G: mehr als 500 Zonen ----------
resetDb();
$values = [];
for ($i = 1; $i <= 600; $i++) {
    $values[] = sprintf("(%d, 'z%03d.big.test', 'forward', 3600, NULL, NULL, 'ns1.u0.test.', 'hostmaster.u0.test.', 3600, 600, 1209600, 300, 2026111401, %d, 0)", 1000 + $i, $i, $i % 7 === 0 ? 1 : 0);
}
sqlx('INSERT INTO DB.zones (id, name, type, ttl, prefix_length, description, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns) VALUES ' . implode(',', $values));
sqlx('INSERT INTO DB.user_zones (user_id, zone_id) SELECT 2, id FROM DB.zones WHERE id BETWEEN 1001 AND 1550');
foreach (['admin' => [603, range(1001, 1600)], 'zoneadmin' => [551, range(1001, 1550)]] as $role => [$n, $must]) {
    $c = session($role);
    $t0 = microtime(true);
    $zp = cgi('GET', 'pages/zones.php', '', $c);
    $ms = (int)((microtime(true) - $t0) * 1000);
    preg_match_all('#href="pages/zones\.php\?edit_id=(\d+)"#', $zp['body'], $m);
    $ids = array_values(array_unique(array_map('intval', $m[1])));
    $dash = cgi('GET', 'pages/dashboard.php', '', $c)['body'];
    $k = $kpi($dash);
    $pendingExpected = (int)$q('SELECT COUNT(*) FROM DB.zones z' . ($role === 'zoneadmin' ? ' JOIN DB.user_zones uz ON uz.zone_id = z.id AND uz.user_id = 2' : '') . ' WHERE z.changed <> 0')->fetchColumn();
    $ok($zp['status'] === 200 && count($ids) === $n && array_diff($must, $ids) === [] && ($role === 'admin' || !array_intersect($ids, range(1551, 1600)))
        && ($k[$role === 'admin' ? 'Zonen' : 'Meine Zonen'] ?? -1) === $n && ($k['Ausstehende Veröffentlichungen'] ?? -1) === $pendingExpected, "G-$role",
        sprintf('Zonenliste %d Zonen (erwartet %d), alle erreichbar, keine fremden; Dashboard %d Zonen, %d ausstehend; %d ms', count($ids), $n,
            $k[$role === 'admin' ? 'Zonen' : 'Meine Zonen'] ?? -1, $k['Ausstehende Veröffentlichungen'] ?? -1, $ms));
}

printf("== U5a: %d/%d bestanden\n", $pass, $pass + $fail);
exit($fail ? 1 : 0);
