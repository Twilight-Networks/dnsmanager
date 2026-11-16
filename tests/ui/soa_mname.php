<?php
/**
 * Datei: tests/ui/soa_mname.php
 * Zweck: Regressionstests SOA-MNAME (primärer Nameserver) gegen die Testkopie, über die Web-Actions (Adapter und
 *        ZoneService) und die tatsächlich erzeugte Zonendatei:
 *        F  Neuanlage Forward-Zone: soa_ns = FQDN des Master-Servers mit abschließendem Punkt, ohne angehängten
 *           Zonennamen (Master innerhalb und außerhalb der Zone, Zonenname beginnt mit dem Servernamen;
 *           Servername mit und ohne Punkt);
 *        U  Bestehende Forward-Zone ändern: ohne Masterwechsel bleibt soa_ns unverändert; bei Masterwechsel
 *           = FQDN des neuen Masters (D2);
 *        R  Reverse-Zonen (IPv4/IPv6): soa_ns = FQDN des Masters wie bei Forward (D1); eine gesendete
 *           SOA-NS-Eingabe wird ignoriert (D5);
 *        X  Ungültige oder unvollständige Master-Namen werden abgelehnt, ohne Zone anzulegen.
 *        Je Zone: gespeicherter Wert, SOA-Zeile der erzeugten Datei, unabhängige Auswertung mit named-checkzone -D
 *        (MNAME, RNAME, Serial, Refresh/Retry/Expire/Minimum, TTL), NS-, Glue- und übrige Records, named-checkzone.
 * Aufruf (über run.sh matrix): php soa_mname.php [Ausgabeverzeichnis für die erzeugten Zonendateien]
 */
require __DIR__ . '/lib.php';
require APP . '/web/inc/deploy/bind_file_generator.php';

$outDir = $argv[1] ?? '';
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $info = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };
$note = function (string $id, string $msg) use (&$info) { $info++; printf("INFO %s %s\n", $id, $msg); };
$q = fn(string $sql) => $GLOBALS['root']->query(str_replace('DB.', '`' . DB . '`.', $sql));

if ($outDir !== '') { @mkdir($outDir, 0700, true); }

/** POST mit dem CSRF-Token der Sitzung */
function postForm(string $path, array $pairs, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}
/** Formularwerte als Paare, überschrieben durch $override (Mehrfachnamen wie server_ids[] einzeln) */
function pairsOf(array $form, array $override): array
{
    $pairs = [];
    foreach ($form['values'] as $n => $v) {
        if ($n === 'csrf_token' || array_key_exists($n, $override)) { continue; }
        foreach ((array)$v as $x) { $pairs[] = [is_array($v) ? $n . '[]' : $n, $x]; }
    }
    foreach ($override as $n => $v) {
        foreach ((array)$v as $x) { $pairs[] = [is_array($v) ? $n . '[]' : $n, $x]; }
    }
    return $pairs;
}
function zoneRow(string $name): array|false
{
    $s = $GLOBALS['root']->prepare('SELECT id, name, type, ttl, soa_ns, soa_mail, soa_serial, soa_refresh, soa_retry, soa_expire, soa_minimum, changed FROM `' . DB . '`.zones WHERE name = ?');
    $s->execute([$name]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
/** Zone über das Anlageformular (No-JS-Weg; Felder wie im Browser) anlegen */
function createZone(array &$c, array $fields): array
{
    $page = 'pages/zones.php?add_new=1';
    if (($fields['type'] ?? 'forward') !== 'forward') {
        // Typübernahme ohne JavaScript, danach das passende Formular
        $r = postForm($page, [['type', $fields['type']], ['zone_prefix', $fields['zone_prefix']], ['apply_type', '1'], ['soa_mail', $fields['soa_mail']]], $c);
        $form = formFor($r['body'], 'actions/zone_add.php');
    } else {
        $form = formFor(cgi('GET', $page, '', $c)['body'], 'actions/zone_add.php');
    }
    return postForm('actions/zone_add.php', pairsOf($form, $fields), $c);
}
/** Zonendatei erzeugen (publish in ein eigenes Verzeichnis der Testkopie) und unabhängig auswerten */
function zoneFile(PDO $pdo, int $zoneId, string $zoneName, string $outDir): array
{
    $dir = T . '/soa-zf';
    if (!is_dir($dir)) { mkdir($dir, 0700); }
    $r = generate_zone_file($zoneId, $dir, 'publish', $pdo);
    if (($r['status'] ?? '') !== 'ok') { return ['error' => (string)($r['output'] ?? 'Fehler')]; }
    $raw = (string)file_get_contents($r['path']);
    $check = (string)shell_exec('named-checkzone ' . escapeshellarg($zoneName) . ' ' . escapeshellarg($r['path']) . ' 2>&1');
    // Unabhängige Auswertung: kanonische Ausgabe von named-checkzone (-D), nicht die eigene Zonendatei-Zeile
    $dump = (string)shell_exec('named-checkzone -q -D -o - ' . escapeshellarg($zoneName) . ' ' . escapeshellarg($r['path']) . ' 2>/dev/null');
    if ($outDir !== '') { copy($r['path'], "$outDir/db.$zoneName"); chmod("$outDir/db.$zoneName", 0600); }
    unlink($r['path']);
    $rr = [];
    foreach (explode("\n", trim($dump)) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) >= 5) { $rr[] = $f; }
    }
    $soa = array_values(array_filter($rr, fn($f) => $f[3] === 'SOA'))[0] ?? null;
    $soaLine = preg_match('/^@\s+IN\s+SOA\s+(\S+)\s+(\S+)/m', $raw, $m) ? [$m[1], $m[2]] : null;
    return [
        'raw' => $raw, 'checkOk' => str_contains($check, 'loaded serial') && str_contains($check, 'OK'), 'check' => trim($check),
        'soaLine' => $soaLine,
        'soa' => $soa ? ['owner' => $soa[0], 'ttl' => (int)$soa[1], 'mname' => $soa[4], 'rname' => $soa[5], 'serial' => (int)$soa[6],
            'refresh' => (int)$soa[7], 'retry' => (int)$soa[8], 'expire' => (int)$soa[9], 'minimum' => (int)$soa[10]] : null,
        'ns' => array_values(array_map(fn($f) => $f[4], array_filter($rr, fn($f) => $f[3] === 'NS'))),
        'glue' => array_values(array_map(fn($f) => "$f[0] $f[3] $f[4]", array_filter($rr, fn($f) => in_array($f[3], ['A', 'AAAA'], true)))),
        'other' => array_values(array_map(fn($f) => "$f[0] $f[3]", array_filter($rr, fn($f) => !in_array($f[3], ['SOA', 'NS', 'A', 'AAAA'], true)))),
    ];
}
/** Gemeinsame Prüfungen einer erzeugten Zone gegen den erwarteten MNAME und die gespeicherten Werte */
function checkZone(callable $ok, PDO $pdo, string $id, string $zone, string $expectMname, array $expectNs, array $expectGlue, string $outDir): void
{
    $row = zoneRow($zone);
    if (!$row) { $ok(false, $id, "$zone: Zone nicht angelegt"); return; }
    $ok($row['soa_ns'] === $expectMname, "$id-db", "$zone: gespeicherter soa_ns '{$row['soa_ns']}' (erwartet '$expectMname')");
    $before = (int)$row['soa_serial'];
    $zf = zoneFile($pdo, (int)$row['id'], $zone, $outDir);
    if (isset($zf['error'])) { $ok(false, "$id-zf", "$zone: Zonendatei nicht erzeugt: {$zf['error']}"); return; }
    $after = zoneRow($zone);
    $s = $zf['soa'];
    $ok($zf['checkOk'], "$id-check", "$zone: named-checkzone OK");
    $ok($zf['soaLine'] !== null && $zf['soaLine'][0] === $expectMname, "$id-soa", "$zone: SOA-Zeile der Datei MNAME '" . ($zf['soaLine'][0] ?? '?') . "'");
    $ok($s !== null && strtolower($s['mname']) === strtolower($expectMname), "$id-mname", "$zone: unabhängig ausgewertet (named-checkzone -D) MNAME '" . ($s['mname'] ?? '?') . "' = Master-FQDN '$expectMname'");
    $ok($s !== null && $s['owner'] === "$zone." && strtolower($s['rname']) === strtolower($row['soa_mail']) && $s['refresh'] === (int)$row['soa_refresh']
        && $s['retry'] === (int)$row['soa_retry'] && $s['expire'] === (int)$row['soa_expire'] && $s['minimum'] === (int)$row['soa_minimum'] && $s['ttl'] === (int)$row['ttl'],
        "$id-fields", "$zone: RNAME, Refresh/Retry/Expire/Minimum und TTL = gespeicherte Werte (" . json_encode($s ? [$s['rname'], $s['refresh'], $s['retry'], $s['expire'], $s['minimum'], $s['ttl']] : null) . ')');
    $ok($s !== null && $s['serial'] === (int)$after['soa_serial'] && (int)$after['soa_serial'] > $before && preg_match('/^\d{10}$/', (string)$after['soa_serial']) === 1,
        "$id-serial", "$zone: Serial der Datei = gespeicherter Serial nach Erzeugung ($before → {$after['soa_serial']}, Strategie unverändert)");
    $ns = $zf['ns']; sort($ns); sort($expectNs);
    $ok($ns === $expectNs, "$id-ns", "$zone: NS-Records " . json_encode($ns));
    $glue = $zf['glue']; sort($glue); sort($expectGlue);
    $ok($glue === $expectGlue, "$id-glue", "$zone: Glue " . json_encode($glue));
    $ok($zf['other'] === [], "$id-other", "$zone: keine weiteren Records");
}

// ---------- Ausgangslage: Fixture plus Server mit Namen wie auf ns1 (nur in der Testdatenbank) ----------
resetDb();
sqlx("INSERT INTO DB.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT 3, 'ns1.example.test', '192.0.2.21', '2001:db8::21', api_ip, api_token, 0, 1 FROM DB.servers WHERE id = 2");
sqlx("INSERT INTO DB.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT 4, 'ns2.example.test.', '192.0.2.22', NULL, api_ip, api_token, 0, 1 FROM DB.servers WHERE id = 2");
sqlx("UPDATE DB.servers SET dns_ip6 = '2001:db8::12' WHERE id = 2");
$c = session('admin');
$base = ['soa_refresh' => '3600', 'soa_retry' => '1200', 'soa_expire' => '1209600', 'soa_minimum' => '3600', 'ttl' => '86400'];

// ---------- F: Neuanlage Forward ----------
$forward = [
    // ID, Zone, Server, Master, erwarteter MNAME, erwartete NS, erwartete Glue (named-checkzone -D, absolut)
    ['F1', 'example.test', ['3', '4'], '3', 'ns1.example.test.', ['ns1.example.test.', 'ns2.example.test.'],
        ['ns1.example.test. A 192.0.2.21', 'ns1.example.test. AAAA 2001:db8::21', 'ns2.example.test. A 192.0.2.22']],
    ['F2', 'zone.example.test', ['3', '4'], '3', 'ns1.example.test.', ['ns1.example.test.', 'ns2.example.test.'], []],
    ['F3', 'twl.example.test', ['3', '4'], '3', 'ns1.example.test.', ['ns1.example.test.', 'ns2.example.test.'], []],
    ['F4', 'twlnet.tld', ['3', '4'], '3', 'ns1.example.test.', ['ns1.example.test.', 'ns2.example.test.'], []],
    ['F5', 'other.org', ['1', '2'], '1', 'ns1.u0.test.', ['ns1.u0.test.', 'ns2.u0.test.'], []],
    ['F6', 'dot.test', ['4'], '4', 'ns2.example.test.', ['ns2.example.test.'], []],
    ['F7', 'ns1.example.test.org', ['3'], '3', 'ns1.example.test.', ['ns1.example.test.'], []],
    ['F8', 'sub.ns.example.org', ['1'], '1', 'ns1.u0.test.', ['ns1.u0.test.'], []],
];
foreach ($forward as [$id, $zone, $servers, $master, $mname, $ns, $glue]) {
    $r = createZone($c, $base + ['type' => 'forward', 'zone_prefix' => $zone, 'soa_mail' => "hostmaster.$zone.", 'server_ids' => $servers, 'master_server_id' => $master]);
    $ok($r['status'] === 302 && zoneRow($zone) !== false, "$id-add", "$zone anlegen (Master-Server " . $q("SELECT name FROM DB.servers WHERE id = $master")->fetchColumn() . "): HTTP {$r['status']}");
    checkZone($ok, $pdo, $id, $zone, $mname, $ns, $glue, $outDir);
}

// ---------- U: Änderung bestehender Forward-Zonen ----------
// U1: ohne Masterwechsel (nur TTL), Serverzuweisung unverändert → soa_ns unverändert, Serial unverändert
$z = zoneRow('zone.example.test');
$form = formFor(cgi('GET', "pages/zones.php?edit_id={$z['id']}", '', $c)['body'], 'actions/zone_update.php', "editForm_{$z['id']}");
$r = postForm('actions/zone_update.php', pairsOf($form, ['ttl' => '7200']), $c);
$z2 = zoneRow('zone.example.test');
$ok($r['status'] === 302 && (int)$z2['ttl'] === 7200 && $z2['soa_ns'] === $z['soa_ns'] && (int)$z2['soa_serial'] === (int)$z['soa_serial'] && (int)$z2['changed'] === 1,
    'U1', "Speichern ohne Masterwechsel: soa_ns unverändert '{$z2['soa_ns']}', Serial unverändert, Zone ausstehend");
// U1b: Speichern ganz ohne Änderung → unverändert
$form = formFor(cgi('GET', "pages/zones.php?edit_id={$z['id']}", '', $c)['body'], 'actions/zone_update.php', "editForm_{$z['id']}");
$snapBefore = snap();
$r = postForm('actions/zone_update.php', pairsOf($form, []), $c);
$ok($r['status'] === 302 && snap()['zones'] === $snapBefore['zones'] && snap()['records'] === $snapBefore['records'], 'U1b', 'Speichern ohne jede Änderung: Zonen und Records unverändert');
// U2: Masterwechsel (ns1.example.test → ns2.example.test.) – Beobachtung der bisherigen Regel
$form = formFor(cgi('GET', "pages/zones.php?edit_id={$z['id']}", '', $c)['body'], 'actions/zone_update.php', "editForm_{$z['id']}");
$r = postForm('actions/zone_update.php', pairsOf($form, ['master_server_id' => '4', 'soa_ns' => 'ns2.example.test.']), $c);
$z3 = zoneRow('zone.example.test');
$master = $q("SELECT s.name FROM DB.zone_servers zs JOIN DB.servers s ON s.id = zs.server_id WHERE zs.zone_id = {$z['id']} AND zs.is_master = 1")->fetchColumn();
$ok($r['status'] === 302 && $master === 'ns2.example.test.', 'U2-assign', "Masterwechsel gespeichert (Master jetzt $master)");
$ok($z3['soa_ns'] === 'ns2.example.test.' && (int)$z3['soa_serial'] === (int)$z2['soa_serial'] && (int)$z3['changed'] === 1, 'U2',
    "Masterwechsel: soa_ns '{$z2['soa_ns']}' → '{$z3['soa_ns']}' (FQDN des neuen Masters, D2), Serial unverändert, Zone ausstehend");
checkZone($ok, $pdo, 'U2z', 'zone.example.test', 'ns2.example.test.', ['ns1.example.test.', 'ns2.example.test.'], [], $outDir);

// ---------- R: Reverse-Zonen (bisherige Regel unverändert) ----------
$reverse = [
    ['R1', 'reverse_ipv4', '20.10', '20.10.in-addr.arpa', ['prefix_length' => '16'], ['1'], '1', 'ns1.u0.test.'],
    ['R2', 'reverse_ipv6', '8.b.d.0.1.0.0.2', '8.b.d.0.1.0.0.2.ip6.arpa', ['prefix_length' => '32'], ['3', '4'], '3', 'ns1.example.test.'],
];
foreach ($reverse as [$id, $type, $prefix, $zone, $extra, $servers, $master, $mname]) {
    $r = createZone($c, $base + $extra + ['type' => $type, 'zone_prefix' => $prefix, 'soa_mail' => 'hostmaster.u0.test.', 'server_ids' => $servers, 'master_server_id' => $master]);
    $row = zoneRow($zone);
    $ok($r['status'] === 302 && $row !== false && $row['soa_ns'] === $mname, "$id-db", "$zone: gespeicherter soa_ns '" . ($row['soa_ns'] ?? '?') . "' = FQDN des Masters (D1)");
    if ($row) {
        $zf = zoneFile($pdo, (int)$row['id'], $zone, $outDir);
        $ok(!isset($zf['error']) && $zf['checkOk'] && ($zf['soa']['mname'] ?? '') === $mname && $zf['glue'] === [], "$id-zf", "$zone: SOA-MNAME der Datei '" . ($zf['soa']['mname'] ?? '?') . "', named-checkzone OK, kein Glue");
    }
}
// R3: Reverse-Zone ändern mit manipuliert gesendetem SOA-NS → ignoriert (D5), übrige Änderung gespeichert
$z = zoneRow('20.10.in-addr.arpa');
$form = formFor(cgi('GET', "pages/zones.php?edit_id={$z['id']}", '', $c)['body'], 'actions/zone_update.php', "editForm_{$z['id']}");
$r = postForm('actions/zone_update.php', pairsOf($form, ['soa_ns' => 'evil.example.', 'ttl' => '7200']), $c);
$z2 = zoneRow('20.10.in-addr.arpa');
$ok($r['status'] === 302 && $z2['soa_ns'] === 'ns1.u0.test.' && (int)$z2['ttl'] === 7200 && !array_key_exists('soa_ns', $form['values']), 'R3',
    "Reverse ändern: Formular ohne Feld soa_ns, gesendeter Wert ignoriert (soa_ns '{$z2['soa_ns']}'), TTL gespeichert");

// ---------- X: ungültige oder unvollständige Master-Namen ----------
// Nur per SQL erzeugbar (ServerService lehnt solche Namen ab); die Neuanlage darf keine Zone anlegen.
foreach ([['X1', 'bad_name.test', 'badname.test'], ['X2', 'nsx', 'single.test'], ['X3', '', 'empty.test']] as [$id, $srvName, $zone]) {
    sqlx("DELETE FROM DB.servers WHERE id = 9");
    $st = $GLOBALS['root']->prepare('INSERT INTO `' . DB . "`.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT 9, ?, '192.0.2.29', NULL, api_ip, api_token, 0, 1 FROM `" . DB . '`.servers WHERE id = 2');
    $st->execute([$srvName]);
    $snapBefore = snap();
    $r = createZone($c, $base + ['type' => 'forward', 'zone_prefix' => $zone, 'soa_mail' => "hostmaster.$zone.", 'server_ids' => ['9'], 'master_server_id' => '9']);
    $after = snap();
    $ok(zoneRow($zone) === false && $after['zones'] === $snapBefore['zones'] && $after['records'] === $snapBefore['records'] && $after['zone_servers'] === $snapBefore['zone_servers'],
        $id, "Master-Name '$srvName' abgelehnt, keine Zone, keine Records (Toast: " . implode(' | ', toasts($c)) . ')');
}
sqlx("DELETE FROM DB.servers WHERE id = 9");

printf("Ergebnis SOA-MNAME: %d bestanden, %d fehlgeschlagen, %d Beobachtungen%s\n", $pass, $fail, $info, $outDir !== '' ? " (Zonendateien: $outDir)" : '');
exit($fail === 0 ? 0 : 1);
