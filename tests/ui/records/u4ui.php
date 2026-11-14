<?php
/**
 * Datei: tests/ui/records/u4ui.php
 * Zweck: Funktions-, Rechte- und Konsistenzprüfungen der Record-Verwaltung U4-UI gegen die Testkopie (run.sh matrix):
 *        V  Vorschau-Endpunkt actions/record_preview.php: Methode, CSRF, Rollen, fremde Zonen und Records (einheitlich
 *           verweigert, keine Datenoffenlegung), Antwortvertrag;
 *        W  Vorschau ohne Schreiboperationen (Datenbankzustand, Sitzung/Toasts, Zonen-Änderungskennzeichen);
 *        Z  tatsächliche Zonendatei ↔ Vorschau bytegenau: für alle Matrixfälle (ohne und mit JS) Vorschau, dann
 *           Speichern über die Action, dann generate_zone_file(): Bei gültiger Vorschau steht die vorhergesagte Zeile
 *           exakt in der erzeugten Zonendatei (Bearbeitung: auch die bisherige Zeile vorher), bei ungültiger Vorschau
 *           wird nichts gespeichert;
 *        R  Roundtrip aller gespeicherten Records: Vorschau ohne Änderung = Zeile der Zonendatei, „keine Änderung“;
 *        N  No-JS-Vorschau (POST auf pages/records.php): CSRF, gleiche Zeile wie der Endpunkt, Eingaben erhalten;
 *        E3 zone_file_record_line(): erzeugte Zonendateien von Basis- und neuem Stand bytegenau gleich (inkl. langer
 *           DKIM-Records), named-checkzone ok (nur mit Basis-Anwendung, UIG_BASE_SHA);
 *        G  Zonenauswahl mit mehr als 500 berechtigten Zonen (admin und zoneadmin), alle erreichbar;
 *        D  DKIM-Upload mit echter OpenDKIM-Datei (opendkim-genkey): Vorschau und Speichern ohne PHP-Warnung, Vorschau =
 *           gespeicherter Record, ungültige Uploads abgelehnt, Rückgabevertrag von parseDKIMFile() unverändert (U4U-R2).
 * Aufruf (über run.sh matrix): php u4ui.php <js_posts.json> [<Basis-Anwendung>]
 */
require __DIR__ . '/../lib.php';

[$_, $jsFile, $baseApp] = $argv + [null, null, ''];
$cases = require __DIR__ . '/matrix_cases.php';
$jsPosts = json_decode((string)file_get_contents($jsFile), true)['posts'] ?? [];
$fixtureMaxId = 1000;
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require APP . '/web/inc/deploy/bind_file_generator.php';

$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };
$enc = fn(array $pairs) => implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs));

function reset4(): void
{
    resetDb();
    sqlx('ALTER TABLE DB.records AUTO_INCREMENT = 1001');
}

/** Zonendatei der Zone erzeugen (publish in ein eigenes Verzeichnis) und Zeilen liefern */
function zoneLines(PDO $pdo, int $zoneId): array
{
    $dir = T . '/u4ui-zf';
    if (!is_dir($dir)) { mkdir($dir, 0700); }
    $r = generate_zone_file($zoneId, $dir, 'publish', $pdo);
    if (($r['status'] ?? '') !== 'ok') { return ['__error' => (string)($r['output'] ?? 'Fehler')]; }
    $lines = explode("\n", (string)file_get_contents($r['path']));
    unlink($r['path']);
    return $lines;
}

/** POST mit CSRF der Sitzung */
function post(string $path, array $pairs, array &$c): array
{
    $csrf = csrfFromSession($c);
    $pairs = array_map(fn($p) => $p[0] === 'csrf_token' ? ['csrf_token', $csrf] : $p, $pairs);
    if (!in_array('csrf_token', array_column($pairs, 0), true)) { $pairs[] = ['csrf_token', $csrf]; }
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}

// ---------- V: Vorschau-Endpunkt ----------
reset4();
$admin = session('admin');
cgi('GET', 'pages/records.php?zone_id=1', '', $admin);
$aPairs = [['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'vtest'], ['content', '192.0.2.77']];

$r = cgi('GET', 'actions/record_preview.php', '', $admin);
$ok($r['status'] === 405 && in_array('allow: POST', $r['headers'], true), 'V01', "GET → {$r['status']}, Allow: POST");
$r = cgi('PUT', 'actions/record_preview.php', '', $admin);
$ok($r['status'] === 405, 'V02', "PUT → {$r['status']}");
$r = cgi('POST', 'actions/record_preview.php', $enc($aPairs), $admin);
$ok($r['status'] === 403 && str_contains($r['body'], 'CSRF'), 'V03', "POST ohne CSRF → {$r['status']}");
$r = cgi('POST', 'actions/record_preview.php', $enc(array_merge($aPairs, [['csrf_token', str_repeat('0', 64)]])), $admin);
$ok($r['status'] === 403, 'V04', "POST mit falschem CSRF → {$r['status']}");
$anon = [];
$r = cgi('POST', 'actions/record_preview.php', $enc($aPairs), $anon);
$ok($r['status'] !== 200 && !str_contains($r['body'], 'newLine'), 'V05', "anonym → {$r['status']} ohne Vorschau");
$r = post('actions/record_preview.php', array_merge($aPairs, [['preview_seq', '7']]), $admin);
$j = json_decode($r['body'], true);
$ok($r['status'] === 200 && ($j['status'] ?? '') === 'ok' && $j['newLine'] === "vtest\t300\tIN\tA\t192.0.2.77" && $j['oldLine'] === null
    && $j['seq'] === 7 && in_array('content-type: application/json; charset=utf-8', $r['headers'], true), 'V06', 'admin Anlage A: ' . json_encode($j['newLine'] ?? null));
$r = post('actions/record_preview.php', [['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'vtest'], ['content', '999.1.1.1']], $admin);
$j = json_decode($r['body'], true);
$ok(($j['status'] ?? '') === 'invalid' && $j['newLine'] === null && $j['errors'] !== [], 'V07', 'ungültige IPv4: invalid, kein DIFF, Fehler: ' . json_encode($j['errors'] ?? null, JSON_UNESCAPED_UNICODE));

$za = session('zoneadmin');
cgi('GET', 'pages/records.php?zone_id=1', '', $za);
$denied = [];
foreach ([
    'V08 fremde Zone (Anlage)'                => [['zone_id', '2'], ['type', 'A'], ['ttl', 'auto'], ['name', 'x'], ['content', '192.0.2.1']],
    'V09 unbekannte Zone'                     => [['zone_id', '999'], ['type', 'A'], ['ttl', 'auto'], ['name', 'x'], ['content', '192.0.2.1']],
    'V10 fremder Record in eigener Zone'      => [['id', '10'], ['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'app'], ['content', '192.0.2.1']],
    'V11 fremder Record mit fremder Zone'     => [['id', '10'], ['zone_id', '2'], ['type', 'A'], ['ttl', 'auto'], ['name', 'app'], ['content', '192.0.2.1']],
    'V12 unbekannter Record'                  => [['id', '99999'], ['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'x'], ['content', '192.0.2.1']],
] as $label => $pairs) {
    $r = post('actions/record_preview.php', $pairs, $za);
    $denied[] = $r['body'];
    $ok($r['status'] === 403 && json_decode($r['body'], true) === ['status' => 'denied', 'seq' => null], substr($label, 0, 3), substr($label, 4) . " → {$r['status']} {$r['body']}");
}
$ok(count(array_unique($denied)) === 1, 'V13', 'alle Verweigerungen mit identischer Antwort (keine Unterscheidung fremd/unbekannt)');
$r = post('actions/record_preview.php', [['id', '3'], ['zone_id', '1'], ['type', 'A'], ['ttl', '600'], ['name', 'www'], ['content', '192.0.2.10']], $za);
$j = json_decode($r['body'], true);
$ok(($j['status'] ?? '') === 'ok' && $j['oldLine'] !== null && str_starts_with((string)$j['newLine'], "www\t600\tIN\tA\t"), 'V14', 'zoneadmin eigener Record: ' . json_encode([$j['oldLine'] ?? null, $j['newLine'] ?? null]));
// Bearbeitung: Ausgangsrecord immer aus der Datenbank, nicht aus übermittelten Altwerten
$r = post('actions/record_preview.php', [['id', '3'], ['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'www'], ['content', '192.0.2.10'], ['oldLine', "evil\t1\tIN\tA\t1.1.1.1"], ['existing', 'x']], $za);
$j = json_decode($r['body'], true);
$st = $GLOBALS['root']->query('SELECT name, ttl, type, content FROM `' . DB . '`.records WHERE id = 3')->fetch(PDO::FETCH_ASSOC);
$ok(($j['oldLine'] ?? '') === "{$st['name']}\t{$st['ttl']}\tIN\t{$st['type']}\t{$st['content']}", 'V15', 'Altzeile aus dem gespeicherten Record (übermittelte Altwerte ignoriert)');
// Schutzregeln in der Vorschau (Glue: Namensänderung; DynDNS: IP-Änderung)
$r = post('actions/record_preview.php', [['id', '13'], ['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'ns1neu'], ['content', '192.0.2.53']], $admin);
$j = json_decode($r['body'], true);
$ok(($j['status'] ?? '') === 'invalid' && $j['newLine'] === null, 'V16', 'Glue-Namensänderung: invalid ' . json_encode($j['errors'] ?? null, JSON_UNESCAPED_UNICODE));
$r = post('actions/record_preview.php', [['id', '8'], ['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'dyn'], ['content', '192.0.2.1']], $admin);
$j = json_decode($r['body'], true);
$ok(($j['status'] ?? '') === 'invalid', 'V17', 'DynDNS-Record IP-Änderung: invalid');
$r = post('actions/record_preview.php', [['id', '8'], ['zone_id', '1'], ['type', 'A'], ['ttl', '600'], ['name', 'dyn'], ['content', '192.0.2.99']], $admin);
$j = json_decode($r['body'], true);
$ok(($j['status'] ?? '') === 'ok', 'V18', 'DynDNS-Record nur TTL: ok');

// ---------- W: keine Schreiboperationen ----------
reset4();
$admin = session('admin');
cgi('GET', 'pages/records.php?zone_id=1', '', $admin);
$snap0 = snap(); $sess0 = sessNorm($admin); $off = logOffset();
$n = 0;
foreach ($cases as $id => $case) {
    if (!isset($jsPosts[$id])) { continue; }
    post('actions/record_preview.php', $jsPosts[$id]['pairs'], $admin); $n++;
}
post('pages/records.php?zone_id=1&add_new=1', array_merge($aPairs, [['preview', '1']]), $admin); $n++;
$log = logSince($off);
$ok(snap() === $snap0, 'W01', "$n Vorschauen: Datenbank unverändert (inkl. Zonen-Änderungskennzeichen und Serial)");
$ok(sessNorm($admin) === $sess0 && toasts($admin) === [], 'W02', 'Sitzung unverändert, keine Toasts');
$ok($log === [], 'W03', 'keine Logzeilen durch die Vorschau (' . count($log) . ')');
$ok(array_filter($log, fn($l) => str_contains($l, 'php_error')) === [], 'W04', 'keine PHP-Meldungen');

// ---------- Z: Vorschau ↔ Zonendatei bytegenau (alle Matrixfälle, ohne und mit JS) ----------
$zStats = ['ok' => 0, 'invalid' => 0];
$zFail = [];
foreach ($cases as $id => $case) {
    foreach (['nojs', 'js'] as $variant) {
        reset4();
        $c = session('admin');
        $page = cgi('GET', $case['page'], '', $c);
        $form = formFor($page['body'], $case['action'], $case['form']);
        if ($variant === 'nojs') {
            $fields = $form['values'] ?? [];
            foreach ($case['ops'] as [$sel, $value, $name]) {
                if (array_key_exists($name, $fields)) { $fields[$name] = $value; }
            }
            $pairs = array_map(null, array_keys($fields), array_values($fields));
        } else {
            if (!isset($jsPosts[$id])) { $zFail[] = "$id/js: keine JS-Daten"; continue; }
            $pairs = $jsPosts[$id]['pairs'];
        }
        $snapBefore = snap();
        $pv = json_decode(post('actions/record_preview.php', $pairs, $c)['body'], true);
        if (snap() !== $snapBefore) { $zFail[] = "$id/$variant: Vorschau hat geschrieben"; }
        $zoneId = (int)$case['zone'];
        $linesBefore = zoneLines($pdo, $zoneId);
        if ($case['mode'] === 'edit' && $pv['oldLine'] !== null && !in_array($pv['oldLine'], $linesBefore, true)) {
            $zFail[] = "$id/$variant: bisherige Zeile nicht in der Zonendatei: " . json_encode($pv['oldLine']);
        }
        $before = $GLOBALS['root']->query('SELECT COUNT(*) FROM `' . DB . '`.records WHERE id > ' . $fixtureMaxId)->fetchColumn();
        $rowBefore = $case['mode'] === 'edit' ? $GLOBALS['root']->query('SELECT name, type, content, ttl FROM `' . DB . '`.records WHERE id = ' . (int)$case['record'])->fetch(PDO::FETCH_ASSOC) : null;
        post($case['action'], $pairs, $c);
        $after = $GLOBALS['root']->query('SELECT COUNT(*) FROM `' . DB . '`.records WHERE id > ' . $fixtureMaxId)->fetchColumn();
        $rowAfter = $case['mode'] === 'edit' ? $GLOBALS['root']->query('SELECT name, type, content, ttl FROM `' . DB . '`.records WHERE id = ' . (int)$case['record'])->fetch(PDO::FETCH_ASSOC) : null;
        $lines = zoneLines($pdo, $zoneId);
        if (($pv['status'] ?? '') === 'ok') {
            $zStats['ok']++;
            $saved = $case['mode'] === 'add' ? ((int)$after === (int)$before + 1) : true;
            if (!$saved) { $zFail[] = "$id/$variant: Vorschau gültig, aber nicht gespeichert"; }
            if (isset($lines['__error'])) { $zFail[] = "$id/$variant: Zonendatei: " . $lines['__error']; }
            elseif (!in_array($pv['newLine'], $lines, true)) { $zFail[] = "$id/$variant: vorhergesagte Zeile fehlt: " . json_encode($pv['newLine']); }
            if ($case['mode'] === 'edit' && $pv['unchanged'] && $rowAfter !== $rowBefore) { $zFail[] = "$id/$variant: „keine Änderung“, aber Record geändert"; }
        } else {
            $zStats['invalid']++;
            $unchanged = $case['mode'] === 'add' ? ((int)$after === (int)$before) : ($rowAfter === $rowBefore);
            if (!$unchanged) { $zFail[] = "$id/$variant: Vorschau ungültig, aber gespeichert"; }
        }
    }
}
$ok($zFail === [], 'Z01', sprintf('%d Fall-Varianten: %d gültig (Zeile exakt in der erzeugten Zonendatei), %d ungültig (nichts gespeichert)%s',
    $zStats['ok'] + $zStats['invalid'], $zStats['ok'], $zStats['invalid'], $zFail ? ': ' . implode(' | ', $zFail) : ''));

// ---------- ZN: ungültige Eingaben – Vorschau ungültig ⇔ nichts gespeichert; Grenze der Vorschau (nicht autoritativ) ----------
$neg = [
    'A-IP'        => ['add', [['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'n1'], ['content', '192.0.2.300']]],
    'MX-Prio'     => ['add', [['zone_id', '1'], ['type', 'MX'], ['ttl', 'auto'], ['name', 'n2'], ['mx_priority', 'x'], ['content', 'mx.other.test'], ['mx_target_mode', 'dot']]],
    'SRV-Ziel'    => ['add', [['zone_id', '1'], ['type', 'SRV'], ['ttl', 'auto'], ['name', 'n3'], ['srv_protocol', '_tcp'], ['srv_priority', '0'], ['srv_weight', '0'], ['srv_port', '1'], ['srv_target', '.'], ['srv_target_mode', 'dot']]],
    'DKIM-Key'    => ['add', [['zone_id', '1'], ['type', 'DKIM'], ['ttl', 'auto'], ['dkim_selector', 'n4'], ['dkim_key_type', 'rsa'], ['dkim_key', 'AAAA!BBBB']]],
    'DKIM-h'      => ['add', [['zone_id', '1'], ['type', 'DKIM'], ['ttl', 'auto'], ['dkim_selector', 'n5'], ['dkim_key_type', 'rsa'], ['dkim_hash_algos', 'sha256; s=*'], ['dkim_key', str_repeat('A', 64)]]],
    'NAPTR-Felder' => ['add', [['zone_id', '1'], ['type', 'NAPTR'], ['ttl', 'auto'], ['name', 'n6'], ['naptr_order', '1'], ['naptr_pref', '1'], ['naptr_flags', ''], ['naptr_service', 'x'], ['naptr_regexp', 'x'], ['naptr_replacement', '.']]],
    'Glue-Name'   => ['edit', [['id', '13'], ['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'ns1neu'], ['content', '192.0.2.11']], 13],
    'NS-Ziel'     => ['edit', [['id', '1'], ['zone_id', '1'], ['type', 'NS'], ['ttl', 'auto'], ['name', '@'], ['content', 'ns9.other.test.']], 1],
];
$nFail = [];
foreach ($neg as $label => [$mode, $pairs]) {
    reset4();
    $c = session('admin');
    cgi('GET', 'pages/records.php?zone_id=1', '', $c);
    $pv = json_decode(post('actions/record_preview.php', $pairs, $c)['body'], true);
    $s0 = snap();
    post($mode === 'add' ? 'actions/record_add.php' : 'actions/record_update.php', $pairs, $c);
    $stored = snap()['records'] !== $s0['records'];
    if (($pv['status'] ?? '') !== 'invalid' || $pv['newLine'] !== null || $stored) {
        $nFail[] = "$label: " . json_encode([$pv['status'] ?? null, $stored]);
    }
}
$ok($nFail === [], 'Z02', count($neg) . ' ungültige Eingaben (Anlage und geschützte Bearbeitung): Vorschau ungültig ohne DIFF, Speichern ändert nichts' . ($nFail ? ': ' . implode(' | ', $nFail) : ''));
// Grenze: Duplikat erkennt erst der RecordService beim Speichern (Hinweis in der Vorschau, keine Garantie)
reset4();
$c = session('admin');
cgi('GET', 'pages/records.php?zone_id=1', '', $c);
$dup = [['zone_id', '1'], ['type', 'A'], ['ttl', '3600'], ['name', 'www'], ['content', '192.0.2.80']];
$pv = json_decode(post('actions/record_preview.php', $dup, $c)['body'], true);
$s0 = snap();
post('actions/record_add.php', $dup, $c);
$t = toasts($c);
$ok(($pv['status'] ?? '') === 'ok' && snap()['records'] === $s0['records'] && $t !== [] && str_starts_with($t[0], 'error'), 'Z03',
    'Duplikat: Vorschau gültig (nicht autoritativ), Speichern abgelehnt: ' . ($t[0] ?? ''));

// ---------- R: Roundtrip aller Records (Vorschau ohne Änderung) ----------
reset4();
$c = session('admin');
$rFail = []; $rn = 0;
foreach ([1, 2, 3] as $zoneId) {
    $lines = zoneLines($pdo, $zoneId);
    $ids = $GLOBALS['root']->query('SELECT id FROM `' . DB . "`.records WHERE zone_id = $zoneId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $rid) {
        $page = cgi('GET', "pages/records.php?zone_id=$zoneId&edit_id=$rid", '', $c);
        $form = formFor($page['body'], 'actions/record_update.php', "editForm_$rid");
        if ($form === null) { $rFail[] = "$rid: kein Formular"; continue; }
        $pv = json_decode(post('actions/record_preview.php', array_map(null, array_keys($form['values']), array_values($form['values'])), $c)['body'], true);
        $rn++;
        if (($pv['status'] ?? '') !== 'ok' || !$pv['unchanged'] || !in_array($pv['newLine'], $lines, true)) {
            $rFail[] = "$rid: " . json_encode([$pv['status'] ?? null, $pv['unchanged'] ?? null, $pv['errors'] ?? null], JSON_UNESCAPED_UNICODE);
        }
    }
}
$ok($rFail === [], 'R01', "$rn Records: Vorschau des unveränderten Formulars = Zeile der Zonendatei, „keine Änderung“" . ($rFail ? ': ' . implode(' | ', $rFail) : ''));

// ---------- N: No-JS-Vorschau auf pages/records.php ----------
reset4();
$c = session('admin');
cgi('GET', 'pages/records.php?zone_id=1&add_new=1', '', $c);
$srv = [['zone_id', '1'], ['type', 'SRV'], ['ttl', 'auto'], ['name', 'ldap'], ['srv_protocol', '_tcp'], ['srv_priority', '0'], ['srv_weight', '0'],
    ['srv_port', '389'], ['srv_target', 'ldap.other.test.'], ['srv_target_mode', 'u0.test.']];
$r = cgi('POST', 'pages/records.php?zone_id=1&add_new=1', $enc(array_merge($srv, [['preview', '1']])), $c);
$ok($r['status'] === 403, 'N01', "No-JS-Vorschau ohne CSRF → {$r['status']}");
$r = post('pages/records.php?zone_id=1&add_new=1', array_merge($srv, [['preview', '1']]), $c);
$pv = json_decode(post('actions/record_preview.php', $srv, $c)['body'], true);
$expected = htmlspecialchars("_ldap._tcp\t300\tIN\tSRV\t0 0 389 ldap.other.test.", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$f = formFor($r['body'], 'actions/record_add.php', 'recordForm');
$ok($r['status'] === 200 && str_contains($r['body'], $expected) && $pv['newLine'] === "_ldap._tcp\t300\tIN\tSRV\t0 0 389 ldap.other.test.", 'N02', 'gleiche Zeile wie der Endpunkt (U4C-A10: absolutes Ziel unverändert)');
$ok(($f['values']['srv_port'] ?? '') === '389' && ($f['values']['srv_target'] ?? '') === 'ldap.other.test.' && ($f['values']['type'] ?? '') === 'SRV'
    && ($f['values']['srv_target_mode'] ?? '') === 'u0.test.', 'N03', 'Eingaben nach der No-JS-Vorschau vollständig erhalten, Feldbereich SRV aktiv');
$r = post('pages/records.php?zone_id=1&add_new=1', [['zone_id', '1'], ['type', 'A'], ['ttl', 'auto'], ['name', 'x'], ['content', 'kein-ip'], ['preview', '1']], $c);
$ok(str_contains($r['body'], 'dm-record-preview-errors') && !str_contains($r['body'], 'dm-diff-add'), 'N04', 'ungültige Eingabe: Fehlerliste, kein DIFF');
$za = session('zoneadmin');
cgi('GET', 'pages/records.php?zone_id=1', '', $za);
$r = post('pages/records.php?zone_id=2&add_new=1', array_merge([['zone_id', '2']], array_slice($aPairs, 1), [['preview', '1']]), $za);
$ok($r['status'] === 403, 'N05', "zoneadmin fremde Zone (No-JS-Vorschau) → {$r['status']}");

// ---------- G: Zonenauswahl mit mehr als 500 Zonen ----------
reset4();
$values = [];
for ($i = 1; $i <= 600; $i++) {
    $values[] = sprintf("(%d, 'z%03d.big.test', 'forward', 3600, NULL, NULL, 'ns1.u0.test.', 'hostmaster.u0.test.', 3600, 600, 1209600, 300, 2026111401, 0, 0)", 1000 + $i, $i);
}
sqlx('INSERT INTO DB.zones (id, name, type, ttl, prefix_length, description, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns) VALUES ' . implode(',', $values));
sqlx('INSERT INTO DB.user_zones (user_id, zone_id) SELECT 2, id FROM DB.zones WHERE id BETWEEN 1001 AND 1550');
foreach (['admin' => [603, range(1001, 1600)], 'zoneadmin' => [551, range(1001, 1550)]] as $role => [$expect, $mustIds]) {
    $c = session($role);
    $t0 = microtime(true);
    $r = cgi('GET', 'pages/records.php?zone_id=1', '', $c);
    $ms = (int)((microtime(true) - $t0) * 1000);
    preg_match('#<select name="zone_id" id="dmZoneSelect"[^>]*>(.*?)</select>#s', $r['body'], $m);
    preg_match_all('#<option value="(\d+)"#', $m[1] ?? '', $om);
    $ids = array_map('intval', $om[1]);
    $ok($r['status'] === 200 && count($ids) === $expect && array_diff($mustIds, $ids) === [] && count(array_unique($ids)) === count($ids)
        && ($role === 'admin' || !array_intersect($ids, range(1551, 1600))), 'G-' . $role,
        sprintf('%d Zonen in der Auswahl (erwartet %d), alle berechtigten erreichbar, keine fremden; %d ms', count($ids), $expect, $ms));
}

// ---------- D: DKIM-Upload mit echter OpenDKIM-Datei (U4U-R2, U2-B3) ----------
/** Multipart-Body wie ein Browser (Felder und optional eine Datei) */
function multipart(array $pairs, ?array $file): array
{
    $b = '----u4uiBoundary' . bin2hex(random_bytes(6));
    $body = '';
    foreach ($pairs as [$n, $v]) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$n\"\r\n\r\n$v\r\n";
    }
    if ($file !== null) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"dkim_file\"; filename=\"{$file[0]}\"\r\nContent-Type: text/plain\r\n\r\n{$file[1]}\r\n";
    }
    return [$body . "--$b--\r\n", "multipart/form-data; boundary=$b"];
}
function postMultipart(string $path, array $pairs, ?array $file, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    [$body, $ct] = multipart($pairs, $file);
    return cgi('POST', $path, $body, $c, ['CONTENT_TYPE' => $ct]);
}
$genDir = T . '/u4ui-dkim';
if (!is_dir($genDir)) { mkdir($genDir, 0700); }
exec('opendkim-genkey -D ' . escapeshellarg($genDir) . ' -s u4dkim -d u0.test -b 2048 2>&1', $genOut, $genRc);
$dkimFile = $genRc === 0 ? (string)file_get_contents("$genDir/u4dkim.txt") : '';
@unlink("$genDir/u4dkim.private");
$ok($genRc === 0 && str_starts_with($dkimFile, 'u4dkim._domainkey') && str_contains($dkimFile, 'p=MII'), 'D01',
    'echte DKIM-Datei mit opendkim-genkey (RSA 2048, ' . strlen($dkimFile) . ' Bytes; privater Schlüssel sofort gelöscht)');
// Formularfelder der DKIM-Anlage wie ein Browser ohne Eingabe in Selector/Schlüssel (Werte kommen aus der Datei)
$dkimForm = [['zone_id', '1'], ['type', 'DKIM'], ['ttl', 'auto'], ['dkim_selector', ''], ['dkim_subdomain', ''], ['dkim_key_type', 'rsa'],
    ['dkim_hash_algos', ''], ['dkim_flags', ''], ['dkim_key', '']];

reset4();
$c = session('admin');
cgi('GET', 'pages/records.php?zone_id=1&add_new=1&type=DKIM', '', $c);
$off = logOffset();
$s0 = snap();
$rPv = postMultipart('actions/record_preview.php', $dkimForm, ['u4dkim.txt', $dkimFile], $c);
$pv = json_decode($rPv['body'], true);
$logPv = logSince($off);
$ok(($pv['status'] ?? '') === 'ok' && str_starts_with((string)$pv['newLine'], "u4dkim._domainkey\t3600\tIN\tTXT\t\"v=DKIM1; k=rsa;\" \"p=MII")
    && snap() === $s0, 'D02', 'Vorschau mit Datei: gültig, keine Schreibzugriffe – ' . substr((string)($pv['newLine'] ?? json_encode($pv)), 0, 70) . '…');
$ok(array_filter($logPv, fn($l) => preg_match('/php_error|PHP (Warning|Notice|Deprecated)|Undefined variable/', $l)) === [], 'D03',
    'Vorschau ohne PHP-Warnung (' . count($logPv) . ' Logzeilen)');
$off = logOffset();
$rSave = postMultipart('actions/record_add.php', $dkimForm, ['u4dkim.txt', $dkimFile], $c);
$logSave = logSince($off);
$row = $GLOBALS['root']->query('SELECT name, type, content, ttl FROM `' . DB . '`.records WHERE id > 1000')->fetchAll(PDO::FETCH_ASSOC);
$lines = zoneLines($pdo, 1);
$storedLine = count($row) === 1 ? zone_file_record_line($row[0], 3600) : null;
$ok($rSave['status'] === 302 && count($row) === 1 && str_starts_with((string)(toasts($c)[0] ?? ''), 'success'), 'D04',
    'Speichern mit Datei: Record angelegt (' . ($row[0]['name'] ?? '-') . ', TTL ' . ($row[0]['ttl'] ?? '-') . ')');
$ok($storedLine !== null && $storedLine === $pv['newLine'] && in_array($pv['newLine'], $lines, true), 'D05',
    'Vorschau = gespeicherter Record = Zeile der erzeugten Zonendatei (bytegenau)');
$ok(array_filter($logSave, fn($l) => preg_match('/php_error|PHP (Warning|Notice|Deprecated)|Undefined variable/', $l)) === [], 'D06',
    'Speichern ohne PHP-Warnung (' . count($logSave) . ' Logzeilen, nur Anwendungsmeldungen)');
// Ungültige Uploads: Vorschau ungültig mit bisheriger Meldung, Speichern lehnt ab (Fehler-Toast, nichts gespeichert)
$bad = [
    'zu groß'        => ['u4dkim.txt', $dkimFile . str_repeat("; Kommentar zum Auffüllen\n", 80), 'record_error_dkim_file_too_large'],
    'Endung .pem'    => ['u4dkim.pem', $dkimFile, 'record_error_dkim_invalid_extension'],
    'ohne Endung'    => ['u4dkim', $dkimFile, 'record_error_dkim_invalid_extension'],
    'PHP-Code'       => ['u4dkim.txt', "<?php echo 1; ?>\n" . $dkimFile, 'record_error_dkim_invalid_code'],
    'nicht lesbar'   => ['u4dkim.txt', "kein DKIM-Inhalt\n", 'record_error_dkim_parse_failed'],
];
$lang = require APP . '/web/lang/de.php';
$bFail = [];
foreach ($bad as $label => [$fname, $content, $key]) {
    reset4();
    $c = session('admin');
    cgi('GET', 'pages/records.php?zone_id=1&add_new=1&type=DKIM', '', $c);
    $off = logOffset();
    $pv = json_decode(postMultipart('actions/record_preview.php', $dkimForm, [$fname, $content], $c)['body'], true);
    $s0 = snap();
    $r = postMultipart('actions/record_add.php', $dkimForm, [$fname, $content], $c);
    $t = toasts($c)[0] ?? '';
    $log = logSince($off);
    $expected = trim(html_entity_decode(strip_tags($lang[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (($pv['status'] ?? '') !== 'invalid' || ($pv['errors'] ?? []) !== [$expected] || snap()['records'] !== $s0['records']
        || !str_contains($t, $lang[$key]) || array_filter($log, fn($l) => preg_match('/php_error|PHP (Warning|Notice|Deprecated)/', $l)) !== []) {
        $bFail[] = "$label: " . json_encode([$pv['status'] ?? null, $pv['errors'] ?? null, $t], JSON_UNESCAPED_UNICODE);
    }
}
$ok($bFail === [], 'D07', count($bad) . ' ungültige Uploads (zu groß, falsche/fehlende Endung, PHP-Code, nicht lesbar): Vorschau ungültig mit der bisherigen Meldung, Speichern abgelehnt, nichts gespeichert, keine PHP-Warnung' . ($bFail ? ': ' . implode(' | ', $bFail) : ''));
// Rückgabevertrag von parseDKIMFile(): Basis ↔ neu gleich; nur die Basis erzeugt die Warnung (Gegenprobe)
if ($baseApp !== '' && is_dir($baseApp)) {
    file_put_contents("$genDir/u4dkim.txt", $dkimFile);
    $probe = function (string $app) use ($genDir): array {
        $code = 'set_error_handler(function($n,$m){echo "WARN:",$m,"\n";return true;});'
            . 'require ' . var_export($app . '/web/inc/dkim_helpers.php', true) . ';'
            . 'echo json_encode(parseDKIMFile(' . var_export("$genDir/u4dkim.txt", true) . '));';
        exec('php -r ' . escapeshellarg($code) . ' 2>&1', $out);
        $warn = array_values(array_filter($out, fn($l) => str_starts_with($l, 'WARN:')));
        $json = array_values(array_filter($out, fn($l) => !str_starts_with($l, 'WARN:')));
        return [$warn, json_decode(implode('', $json), true)];
    };
    [$wBase, $resBase] = $probe($baseApp);
    [$wNew, $resNew] = $probe(APP);
    $ok($resBase === $resNew && is_array($resNew) && array_key_exists('domain', $resNew) && $resNew['domain'] === null
        && $wNew === [] && $wBase !== [] && str_contains($wBase[0], 'domain'), 'D08',
        'parseDKIMFile(): Rückgabe Basis = neu (Schlüssel ' . implode(', ', array_keys($resNew ?? [])) . '; domain null); Warnung nur in der Basis: ' . ($wBase[0] ?? '-'));
}
@unlink("$genDir/u4dkim.txt");
@rmdir($genDir);

// ---------- E3: Zonendateien Basis ↔ neu bytegenau ----------
if ($baseApp !== '' && is_dir($baseApp)) {
    reset4();
    // langer DKIM-Record (RSA 4096) wie vom Builder erzeugt
    require_once APP . '/web/inc/app/records/RecordContentBuilder.php';
    $keys = require __DIR__ . '/unit_keys.php';
    $dk = RecordContentBuilder::build('DKIM', ['dkim_selector' => 'long', 'dkim_key' => $keys['rsa4096'], 'dkim_key_type' => 'rsa', 'dkim_hash_algos' => 'sha256'],
        ['mode' => 'create', 'zoneName' => 'u0.test']);
    $st = $GLOBALS['root']->prepare('INSERT INTO `' . DB . "`.records (id, zone_id, name, type, content, ttl) VALUES (950, 1, ?, 'TXT', ?, NULL)");
    $st->execute([$dk['name'], $dk['content']]);
    $GLOBALS['root']->exec('DROP TABLE IF EXISTS `' . TPL . '`.records_e3');
    $GLOBALS['root']->exec('CREATE TABLE `' . TPL . '`.records_e3 AS SELECT * FROM `' . DB . '`.records');
    $out = [];
    foreach (['basis' => $baseApp, 'neu' => APP] as $label => $app) {
        // gleicher Ausgangsstand: Fixture + DKIM-Record; Serial je Lauf gleich (publish erhöht ab Fixture-Stand)
        resetDb();
        $GLOBALS['root']->exec('SET FOREIGN_KEY_CHECKS=0');
        $GLOBALS['root']->exec('TRUNCATE TABLE `' . DB . '`.records');
        $GLOBALS['root']->exec('INSERT INTO `' . DB . '`.records SELECT * FROM `' . TPL . '`.records_e3');
        $GLOBALS['root']->exec('SET FOREIGN_KEY_CHECKS=1');
        $out[$label] = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/u4ui_zonefiles.php') . ' ' . escapeshellarg($app) . ' ' . escapeshellarg(DB) . ' 2>&1');
    }
    $GLOBALS['root']->exec('DROP TABLE IF EXISTS `' . TPL . '`.records_e3');
    $b = json_decode($out['basis'], true); $nw = json_decode($out['neu'], true);
    $okAll = is_array($b) && is_array($nw) && $b !== [] && array_keys($b) === array_keys($nw);
    $detail = [];
    foreach ($b ?? [] as $z => $res) {
        $same = $res['content'] === ($nw[$z]['content'] ?? null);
        $okAll = $okAll && $same && $res['status'] === 'ok' && ($nw[$z]['status'] ?? '') === 'ok';
        $detail[] = "$z: " . strlen($res['content']) . ' Bytes ' . ($same ? 'gleich' : 'VERSCHIEDEN') . ', ' . $res['status'] . '/' . ($nw[$z]['status'] ?? '?');
    }
    $hasLong = is_array($nw) && str_contains($nw['u0.test']['content'] ?? '', $dk['content']);
    $ok($okAll && $hasLong, 'E3', 'generate_zone_file Basis ↔ neu bytegenau, named-checkzone ok, inkl. DKIM 4096 (' . strlen($dk['content']) . ' Zeichen): ' . implode('; ', $detail));
} else {
    echo "SKIP E3 (keine Basis-Anwendung, UIG_BASE_SHA)\n";
}

printf("== U4-UI: %d/%d bestanden\n", $pass, $pass + $fail);
exit($fail ? 1 : 0);
