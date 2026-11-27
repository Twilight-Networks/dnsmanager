<?php
/**
 * Datei: tests/ui/ns_apex.php
 * Zweck: Regressionstests der systemgenerierten Adressrecords von Nameservern in der eigenen Forward-Zone (PR C, D7):
 *        N  Neuanlage: Nameserver am Zonen-Apex ('@'), Groß-/Kleinschreibung, Labelgrenze, tieferer Name,
 *           Servername mit Punkt; Reverse-Zonen ohne Adressrecords;
 *        B  NS-/Glue-Neuaufbau (Serverzuweisung, Umbenennung): '@'-Records entstehen und verschwinden mit der
 *           Zuweisung, manuelle Records bleiben unberührt, systemgenerierte bleiben über server_id unterscheidbar;
 *        K  Manuelle und systemgenerierte Apex-Adressrecords (D7-K = K-a): identisch/abweichend, gleiche/andere TTL,
 *           A und AAAA, Neuaufbau nach manueller Änderung – Datenbank und Ownership, Zonendatei, Warnungen von
 *           named-checkzone, effektives RRset (Adressen, TTL nach RFC 2181);
 *        Z  Zonendateien der Fixture-Zonen (unverändert) unter OUT_DIR zum Bytevergleich.
 *        Ausgewertet wird jeweils die erzeugte Zonendatei unabhängig mit named-checkzone -D (NS, A, AAAA).
 * Aufruf (über run.sh matrix): php ns_apex.php [Ausgabeverzeichnis]
 */
require __DIR__ . '/lib.php';
require APP . '/web/inc/deploy/bind_file_generator.php';

$outDir = $argv[1] ?? '';
if ($outDir !== '') { @mkdir($outDir, 0700, true); }
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $info = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };
$note = function (string $id, string $msg) use (&$info) { $info++; printf("INFO %s %s\n", $id, $msg); };

function postN(string $path, array $pairs, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}
function pairsN(array $form, array $override): array
{
    $out = [];
    foreach ($form['values'] as $n => $v) {
        if ($n === 'csrf_token' || array_key_exists($n, $override)) { continue; }
        foreach ((array)$v as $x) { $out[] = [is_array($v) ? $n . '[]' : $n, $x]; }
    }
    foreach ($override as $n => $v) {
        foreach ((array)$v as $x) { $out[] = [is_array($v) ? $n . '[]' : $n, $x]; }
    }
    return $out;
}
function zoneId(string $name): int
{
    $s = $GLOBALS['root']->prepare('SELECT id FROM `' . DB . '`.zones WHERE name = ?');
    $s->execute([$name]);
    return (int)$s->fetchColumn();
}
function addForward(array &$c, string $zone, array $servers, string $master): array
{
    $form = formFor(cgi('GET', 'pages/zones.php?add_new=1', '', $c)['body'], 'actions/zone_add.php');
    $r = postN('actions/zone_add.php', pairsN($form, ['type' => 'forward', 'zone_prefix' => $zone, 'soa_mail' => "hostmaster.$zone.", 'server_ids' => $servers, 'master_server_id' => $master]), $c);
    return [$r, zoneId($zone), toasts($c)];
}
/** Systemgenerierte (server_id gesetzt) und manuelle Adressrecords einer Zone */
function addrRows(int $zoneId): array
{
    return $GLOBALS['root']->query('SELECT name, type, content, IF(server_id IS NULL, \'manuell\', CONCAT(\'server \', server_id)) FROM `' . DB . "`.records WHERE zone_id = $zoneId AND type IN ('A', 'AAAA') ORDER BY name, type, content, 4")
        ->fetchAll(PDO::FETCH_FUNC, fn(...$f) => implode(' ', $f));
}
/** Zonendatei unabhängig auswerten: ['ok', 'ns' => [...], 'addr' => ['owner TYP wert', ...]] */
function zoneEval(PDO $pdo, int $zoneId, string $outDir = ''): array
{
    $name = (string)$GLOBALS['root']->query('SELECT name FROM `' . DB . "`.zones WHERE id = $zoneId")->fetchColumn();
    $dir = T . '/ns-apex-zf';
    if (!is_dir($dir)) { mkdir($dir, 0700); }
    $r = generate_zone_file($zoneId, $dir, 'publish', $pdo, static fn (array $g): array => []);   // AS5 (G1): Publish nur mit Journal-Hook
    if (($r['status'] ?? '') !== 'ok') { return ['ok' => false, 'error' => trim((string)($r['output'] ?? ''))]; }
    $dump = (string)shell_exec('named-checkzone -q -D -o - ' . escapeshellarg($name) . ' ' . escapeshellarg($r['path']) . ' 2>/dev/null');
    if ($outDir !== '') { copy($r['path'], "$outDir/db.$name"); chmod("$outDir/db.$name", 0600); }
    unlink($r['path']);
    $ns = []; $addr = [];
    foreach (explode("\n", trim($dump)) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) < 5) { continue; }
        if ($f[3] === 'NS') { $ns[] = strtolower($f[4]); }
        if (in_array($f[3], ['A', 'AAAA'], true)) { $addr[] = strtolower($f[0]) . " $f[3] $f[4]"; }
    }
    sort($ns); sort($addr);
    return ['ok' => true, 'ns' => $ns, 'addr' => $addr];
}
function server(int $id, string $name, string $ip4, ?string $ip6): void
{
    $s = $GLOBALS['root']->prepare('INSERT INTO `' . DB . "`.servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) SELECT ?, ?, ?, ?, api_ip, api_token, 0, 1 FROM `" . DB . '`.servers WHERE id = 2');
    $s->execute([$id, $name, $ip4, $ip6]);
}

// ---------- Z: unveränderte Fixture-Zonen (Bytevergleich main ↔ PR über OUT_DIR) ----------
resetDb();
foreach ([1, 2, 3] as $zid) {
    $e = zoneEval($pdo, $zid, $outDir);
    $ok($e['ok'], "Z$zid", "Fixture-Zone $zid erzeugt (named-checkzone OK)" . ($outDir !== '' ? ', Datei abgelegt' : ''));
}

// ---------- N: Neuanlage ----------
resetDb();
server(5, 'apex.test', '192.0.2.23', '2001:db8::23');
server(6, 'NS1.Case.Test', '192.0.2.24', null);
server(7, 'ns1.myapex.test', '192.0.2.25', null);
server(8, 'ns1.sub.deep.test', '192.0.2.26', '2001:db8::26');
server(9, 'apex2.test.', '192.0.2.27', null);
$c = session('admin');

[$r, $z, $t] = addForward($c, 'apex.test', ['5'], '5');
$e = $z ? zoneEval($pdo, $z) : ['ok' => false];
$ok($z > 0 && addrRows($z) === ['@ A 192.0.2.23 server 5', '@ AAAA 2001:db8::23 server 5'], 'N1-db', 'Nameserver = Zonen-Apex: Anlage gelingt, systemgenerierte A/AAAA unter @ (server_id 5): ' . json_encode($z ? addrRows($z) : $t));
$ok($e['ok'] && $e['ns'] === ['apex.test.'] && $e['addr'] === ['apex.test. A 192.0.2.23', 'apex.test. AAAA 2001:db8::23'], 'N1-zf', 'Zonendatei: NS apex.test., Apex-Adressen (named-checkzone -D): ' . json_encode($e['addr'] ?? $e['error'] ?? null));

[$r, $z, $t] = addForward($c, 'case.test', ['6'], '6');
$e = $z ? zoneEval($pdo, $z) : ['ok' => false];
$ok($z > 0 && addrRows($z) === ['NS1 A 192.0.2.24 server 6'] && $e['ok'] && $e['addr'] === ['ns1.case.test. A 192.0.2.24'], 'N2',
    'Servername in anderer Schreibweise (NS1.Case.Test in case.test): Adressrecord NS1, Zonendatei gültig: ' . json_encode($z ? addrRows($z) : $t));

[$r, $z, $t] = addForward($c, 'apex.test.example', ['7', '5'], '5');
$ok($z > 0 && addrRows($z) === [], 'N3a', 'Nameserver außerhalb der Zone: keine Adressrecords: ' . json_encode($z ? addrRows($z) : $t));
[$r, $z, $t] = addForward($c, 'myapex.test', ['7'], '7');
$e = $z ? zoneEval($pdo, $z) : ['ok' => false];
$ok($z > 0 && addrRows($z) === ['ns1 A 192.0.2.25 server 7'] && $e['ok'], 'N3b', 'ns1.myapex.test in myapex.test: Adressrecord ns1 (Labelgrenze): ' . json_encode($z ? addrRows($z) : $t));
// N3c: Servername endet nur als Zeichenfolge auf den Zonennamen (apex.test / pex.test), keine Labelgrenze. Die Zone ist
// gültig (NS außerhalb der Zone braucht keine Adresse) und muss angelegt werden – ohne In-Zone-Adressrecord.
[$r, $z, $t] = addForward($c, 'pex.test', ['5'], '5');
$e = $z ? zoneEval($pdo, $z) : ['ok' => false];
$ok($z > 0 && addrRows($z) === [] && $e['ok'] && $e['ns'] === ['apex.test.'] && $e['addr'] === [], 'N3c',
    'apex.test als Nameserver von pex.test (Endung ohne Labelgrenze): Zone angelegt, NS apex.test., kein Adressrecord in DB und Zonendatei: '
    . json_encode($z ? [addrRows($z), $e['addr'] ?? null] : $t));

[$r, $z, $t] = addForward($c, 'deep.test', ['8'], '8');
$e = $z ? zoneEval($pdo, $z) : ['ok' => false];
$ok($z > 0 && addrRows($z) === ['ns1.sub A 192.0.2.26 server 8', 'ns1.sub AAAA 2001:db8::26 server 8'] && $e['ok'], 'N4', 'tieferer Name ns1.sub.deep.test: Adressrecords ns1.sub: ' . json_encode($z ? addrRows($z) : $t));

[$r, $z, $t] = addForward($c, 'apex2.test', ['9'], '9');
$e = $z ? zoneEval($pdo, $z) : ['ok' => false];
$ok($z > 0 && addrRows($z) === ['@ A 192.0.2.27 server 9'] && $e['ok'] && $e['ns'] === ['apex2.test.'], 'N5', 'Servername mit Punkt (apex2.test.) am Apex: @ A, Zonendatei gültig: ' . json_encode($z ? addrRows($z) : $t));

$form = formFor(postN('pages/zones.php?add_new=1', [['type', 'reverse_ipv4'], ['zone_prefix', '40.10'], ['apply_type', '1'], ['soa_mail', 'hostmaster.u0.test.']], $c)['body'], 'actions/zone_add.php');
postN('actions/zone_add.php', pairsN($form, ['type' => 'reverse_ipv4', 'zone_prefix' => '40.10', 'prefix_length' => '16', 'soa_mail' => 'hostmaster.u0.test.', 'soa_domain' => 'u0.test',
    'server_ids' => ['1'], 'master_server_id' => '1']), $c);
$z = zoneId('40.10.in-addr.arpa');
$ok($z > 0 && addrRows($z) === [], 'N6', 'Reverse-Zone: keine Adressrecords (unverändert)');

// ---------- B: NS-/Glue-Neuaufbau (setzt die Zonen aus N voraus) ----------
$zb = zoneId('case.test');
if ($zb === 0 || zoneId('apex.test') === 0) {
    $ok(false, 'B0', 'Ausgangszonen case.test/apex.test fehlen (Neuanlage abgelehnt) – B und K nicht ausführbar');
    printf("Ergebnis NS-Apex: %d bestanden, %d fehlgeschlagen, %d Beobachtungen\n", $pass, $fail, $info);
    exit(1);
}
sqlx("INSERT INTO DB.records (zone_id, name, type, content, ttl, server_id) VALUES ($zb, 'www', 'A', '198.51.100.10', 300, NULL)");
$f = formFor(cgi('GET', "pages/zones.php?edit_id=$zb", '', $c)['body'], 'actions/zone_update.php', "editForm_$zb");
postN('actions/zone_update.php', pairsN($f, ['server_ids' => ['6', '5'], 'master_server_id' => '6']), $c);
$ok(addrRows($zb) === ['NS1 A 192.0.2.24 server 6', 'www A 198.51.100.10 manuell'], 'B1', 'Neuaufbau mit zusätzlichem Server außerhalb der Zone: Adressrecord NS1 neu erzeugt, manueller Record www unberührt: ' . json_encode(addrRows($zb)));
$zp = zoneId('pex.test');
$f = formFor(cgi('GET', "pages/zones.php?edit_id=$zp", '', $c)['body'], 'actions/zone_update.php', "editForm_$zp");
postN('actions/zone_update.php', pairsN($f, ['server_ids' => ['5', '6'], 'master_server_id' => '6']), $c);
$e = zoneEval($pdo, $zp);
$ok(addrRows($zp) === [] && $e['ok'] && $e['ns'] === ['apex.test.', 'ns1.case.test.'], 'N3d', 'Neuaufbau (pex.test, Server apex.test und NS1.Case.Test): kein Adressrecord ohne Labelgrenze: ' . json_encode(addrRows($zp)));
$za = zoneId('apex.test');
$f = formFor(cgi('GET', "pages/zones.php?edit_id=$za", '', $c)['body'], 'actions/zone_update.php', "editForm_$za");
postN('actions/zone_update.php', pairsN($f, ['server_ids' => ['5', '7'], 'master_server_id' => '7']), $c);
$e = zoneEval($pdo, $za);
$ok(addrRows($za) === ['@ A 192.0.2.23 server 5', '@ AAAA 2001:db8::23 server 5'] && $e['ok'] && $e['ns'] === ['apex.test.', 'ns1.myapex.test.'], 'B2',
    'Apex-Zone: Neuaufbau (Master gewechselt, Server ergänzt) erzeugt @ A/AAAA erneut, Zonendatei gültig: ' . json_encode(addrRows($za)));
$f = formFor(cgi('GET', "pages/zones.php?edit_id=$za", '', $c)['body'], 'actions/zone_update.php', "editForm_$za");
postN('actions/zone_update.php', pairsN($f, ['server_ids' => ['7'], 'master_server_id' => '7']), $c);
$ok(addrRows($za) === [], 'B3', 'Apex-Server aus der Zuweisung entfernt: seine @-Adressrecords entfernt: ' . json_encode(addrRows($za)));
sqlx("UPDATE DB.servers SET name = 'old5.test' WHERE id = 5"); // Namen apex.test für die Umbenennung von Server 7 freigeben
$sf = formFor(cgi('GET', 'pages/servers.php?edit_id=7', '', $c)['body'], 'actions/server_update.php');
postN('actions/server_update.php', pairsN($sf, ['name' => 'apex.test']), $c);
$e = zoneEval($pdo, $za);
$ok(addrRows($za) === ['@ A 192.0.2.25 server 7'] && $e['ok'], 'B4', 'Umbenennung des Servers auf den Zonennamen: Neuaufbau erzeugt @ A, Zonendatei gültig: ' . json_encode(addrRows($za)));

// ---------- K: manuelle und systemgenerierte Apex-Adressrecords (D7-K = K-a) ----------
// Server 7 = apex.test (192.0.2.25, 2001:db8::25) ist Master von apex.test; systemgenerierte @-Records (server_id 7).
sqlx("UPDATE DB.servers SET dns_ip6 = '2001:db8::25' WHERE id = 7");
$toggle = 0;
$rebuild = function () use (&$c, $za, &$toggle): void {
    // NS-/Glue-Neuaufbau über eine Zuweisungsänderung (abwechselnd mit/ohne zusätzlichen Server 6 außerhalb der Zone)
    $f = formFor(cgi('GET', "pages/zones.php?edit_id=$za", '', $c)['body'], 'actions/zone_update.php', "editForm_$za");
    postN('actions/zone_update.php', pairsN($f, ['server_ids' => ($toggle++ % 2 === 0) ? ['7', '6'] : ['7'], 'master_server_id' => '7']), $c);
};
$rebuild();
/** Zustand der @-Adressrecords: DB (id, TTL, Ownership), Zeilen der Zonendatei, Warnungen, effektives RRset je Typ */
$apex = function () use ($pdo, $za): array {
    $db = $GLOBALS['root']->query('SELECT id, type, content, ttl, IFNULL(server_id, 0) FROM `' . DB . "`.records WHERE zone_id = $za AND name = '@' AND type IN ('A', 'AAAA') ORDER BY id")
        ->fetchAll(PDO::FETCH_NUM);
    $dir = T . '/ns-apex-zf';
    if (!is_dir($dir)) { mkdir($dir, 0700); }
    $r = generate_zone_file($za, $dir, 'publish', $pdo, static fn (array $g): array => []);   // AS5 (G1): Publish nur mit Journal-Hook
    if (($r['status'] ?? '') !== 'ok') { return ['ok' => false, 'db' => $db, 'error' => (string)($r['output'] ?? '')]; }
    $raw = (string)file_get_contents($r['path']);
    $check = (string)shell_exec('named-checkzone apex.test ' . escapeshellarg($r['path']) . ' 2>&1');
    $dump = (string)shell_exec('named-checkzone -q -D -o - apex.test ' . escapeshellarg($r['path']) . ' 2>/dev/null');
    unlink($r['path']);
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => preg_match('/^@\t\d+\tIN\t(A|AAAA)\t/', $l) === 1));
    $warn = array_values(array_filter(array_map('trim', explode("\n", $check)), fn($l) => $l !== '' && $l !== 'OK' && !str_contains($l, 'loaded serial')));
    $rr = ['A' => ['ttl' => [], 'addr' => []], 'AAAA' => ['ttl' => [], 'addr' => []]];
    foreach (explode("\n", trim($dump)) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) >= 5 && $f[0] === 'apex.test.' && isset($rr[$f[3]])) { $rr[$f[3]]['ttl'][] = (int)$f[1]; $rr[$f[3]]['addr'][] = $f[4]; }
    }
    foreach ($rr as &$x) { sort($x['addr']); $x['ttl'] = array_values(array_unique($x['ttl'])); }
    unset($x);
    return ['ok' => str_contains($check, 'OK'), 'db' => $db, 'lines' => array_map(fn($l) => str_replace("\t", ' ', $l), $lines), 'warn' => $warn, 'rr' => $rr];
};
$fmt = fn(array $s) => 'DB ' . json_encode(array_map(fn($r) => "#$r[0] $r[1] $r[2] ttl $r[3] " . ($r[4] ? "server $r[4]" : 'manuell'), $s['db']))
    . ' | Datei ' . json_encode($s['lines'] ?? []) . ' | Warnungen ' . json_encode($s['warn'] ?? []) . ' | RRset A ' . json_encode($s['rr']['A'] ?? null) . ' AAAA ' . json_encode($s['rr']['AAAA'] ?? null);
$manual = fn(array $s) => array_values(array_filter($s['db'], fn($r) => (int)$r[4] === 0));
$system = fn(array $s) => array_values(array_map(fn($r) => "$r[1] $r[2]", array_filter($s['db'], fn($r) => (int)$r[4] === 7)));

$cases = [
    ['K1', 'A', '192.0.2.25', 300, 'A identisch, gleiche TTL'],
    ['K2', 'A', '192.0.2.25', 3600, 'A identisch, andere TTL'],
    ['K3', 'A', '198.51.100.80', 300, 'A abweichend (z. B. Website), gleiche TTL'],
    ['K4', 'A', '198.51.100.80', 3600, 'A abweichend, andere TTL'],
    ['K5', 'AAAA', '2001:db8::25', 3600, 'AAAA identisch, andere TTL'],
    ['K6', 'AAAA', '2001:db8::80', 300, 'AAAA abweichend, gleiche TTL'],
    ['K7', 'AAAA', '2001:db8::80', 3600, 'AAAA abweichend, andere TTL'],
];
foreach ($cases as [$id, $type, $content, $ttl, $desc]) {
    sqlx("DELETE FROM DB.records WHERE zone_id = $za AND name = '@' AND server_id IS NULL");
    $st = $GLOBALS['root']->prepare('INSERT INTO `' . DB . "`.records (zone_id, name, type, content, ttl, server_id) VALUES (?, '@', ?, ?, ?, NULL)");
    $st->execute([$za, $type, $content, $ttl]);
    $s1 = $apex();             // systemgenerierte Records älter (niedrigere id) als der manuelle
    $rebuild();
    $s2 = $apex();             // nach dem Neuaufbau: systemgenerierte Records neu (höhere id)
    $sysExpected = ['A 192.0.2.25', 'AAAA 2001:db8::25'];
    $union = array_values(array_unique(array_merge([$type === 'A' ? '192.0.2.25' : '2001:db8::25'], [$content]))); sort($union);
    $m1 = $manual($s1); $m2 = $manual($s2);
    $ok($s1['ok'] && $s2['ok'] && count($m2) === 1 && $m1 === $m2 && $system($s1) === $sysExpected && $system($s2) === $sysExpected
        && $s2['rr'][$type]['addr'] === $union && count($s1['rr'][$type]['ttl']) === 1 && count($s2['rr'][$type]['ttl']) === 1,
        $id, "$desc: Zone gültig, manueller Record unverändert erhalten (id/Inhalt/TTL), systemgenerierte @ A/AAAA (server_id 7) neu erzeugt, effektives RRset = "
        . json_encode($union) . ', je RRset genau eine TTL');
    $note("$id-vor", $fmt($s1));
    $note("$id-nach", $fmt($s2));
}
// K8: manuelle Änderung (TTL) und erneuter Neuaufbau – manueller Record bleibt mit neuer TTL erhalten
sqlx("UPDATE DB.records SET ttl = 600 WHERE zone_id = $za AND name = '@' AND server_id IS NULL");
$rebuild();
$s = $apex();
$m = $manual($s);
$ok($s['ok'] && count($m) === 1 && (int)$m[0][3] === 600 && $system($s) === ['A 192.0.2.25', 'AAAA 2001:db8::25'], 'K8',
    'manuelle TTL-Änderung, danach Neuaufbau: manueller Record mit TTL 600 erhalten, systemgenerierte Records neu: ' . $fmt($s));

printf("Ergebnis NS-Apex: %d bestanden, %d fehlgeschlagen, %d Beobachtungen\n", $pass, $fail, $info);
exit($fail === 0 ? 0 : 1);
