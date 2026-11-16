<?php
/**
 * Datei: tests/ui/rrset_ttl.php
 * Zweck: Regressionstests der RRset-TTL-Konsistenz (RFC 2181 §5.2) gegen die Testkopie:
 *        G  Zonendatei: je RRset (Owner-Name ohne Groß-/Kleinschreibung, '@' bzw. relativ/absolut, Klasse IN, Typ)
 *           genau eine TTL = niedrigste effektive TTL (Record-TTL bzw. Zonen-TTL bei fehlender TTL); konsistente
 *           RRsets unverändert; A/AAAA am selben Namen unabhängig; NS, TXT; keine Warnung „TTL set to prior TTL“;
 *           unabhängige Auswertung mit named-checkzone -D;
 *        R  systemgenerierte und identische manuelle Records, Neuaufbau, geänderte manuelle TTL, veränderte
 *           Reihenfolge: effektive TTL unabhängig von der Reihenfolge;
 *        P  Vorschau (actions/record_preview.php) und Record-Liste zeigen die veröffentlichte TTL; Abweichung zur
 *           gespeicherten TTL als Hinweis.
 * Aufruf (über run.sh matrix): php rrset_ttl.php [Ausgabeverzeichnis]
 */
require __DIR__ . '/lib.php';
require APP . '/web/inc/deploy/bind_file_generator.php';

$outDir = $argv[1] ?? '';
if ($outDir !== '') { @mkdir($outDir, 0700, true); }
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . DB . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };

function postT(string $path, array $pairs, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}
function pairsT(array $form, array $override): array
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
function rec(int $zoneId, string $name, string $type, string $content, ?int $ttl, ?int $serverId = null): int
{
    $s = $GLOBALS['root']->prepare('INSERT INTO `' . DB . '`.records (zone_id, name, type, content, ttl, server_id) VALUES (?, ?, ?, ?, ?, ?)');
    $s->execute([$zoneId, $name, $type, $content, $ttl, $serverId]);
    return (int)$GLOBALS['root']->lastInsertId();
}
/** Zonendatei erzeugen: Zeilen der Datei, Warnungen von named-checkzone, RRsets aus named-checkzone -D */
function ttlEval(PDO $pdo, int $zoneId, string $outDir = '', string $tag = ''): array
{
    $name = (string)$GLOBALS['root']->query('SELECT name FROM `' . DB . "`.zones WHERE id = $zoneId")->fetchColumn();
    $dir = T . '/rrset-ttl-zf';
    if (!is_dir($dir)) { mkdir($dir, 0700); }
    $r = generate_zone_file($zoneId, $dir, 'publish', $pdo);
    if (($r['status'] ?? '') !== 'ok') { return ['ok' => false, 'error' => trim((string)($r['output'] ?? ''))]; }
    $raw = (string)file_get_contents($r['path']);
    $check = (string)shell_exec('named-checkzone ' . escapeshellarg($name) . ' ' . escapeshellarg($r['path']) . ' 2>&1');
    $dump = (string)shell_exec('named-checkzone -q -D -o - ' . escapeshellarg($name) . ' ' . escapeshellarg($r['path']) . ' 2>/dev/null');
    if ($outDir !== '') { copy($r['path'], "$outDir/db.$name$tag"); chmod("$outDir/db.$name$tag", 0600); }
    unlink($r['path']);
    $rr = [];
    foreach (explode("\n", trim($dump)) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) >= 5) { $rr[strtolower($f[0]) . ' ' . $f[3]]['ttl'][(int)$f[1]] = true; $rr[strtolower($f[0]) . ' ' . $f[3]]['n'] = ($rr[strtolower($f[0]) . ' ' . $f[3]]['n'] ?? 0) + 1; }
    }
    $lines = [];
    foreach (explode("\n", $raw) as $l) {
        if (preg_match('/^(\S+)\t(\d+)\tIN\t(\S+)\t(.*)$/', $l, $m)) { $lines[] = ['name' => $m[1], 'ttl' => (int)$m[2], 'type' => $m[3], 'content' => $m[4], 'raw' => $l]; }
    }
    return ['ok' => str_contains($check, 'OK'), 'warn' => array_values(array_filter(explode("\n", $check), fn($l) => str_contains($l, 'TTL set to prior TTL'))),
        'rr' => $rr, 'lines' => $lines, 'raw' => $raw];
}
/** TTLs der Dateizeilen eines Owners/Typs (Owner wie gespeichert, mehrere Schreibweisen möglich) */
function lineTtls(array $e, array $names, string $type): array
{
    return array_values(array_map(fn($l) => $l['ttl'], array_filter($e['lines'], fn($l) => in_array($l['name'], $names, true) && $l['type'] === $type)));
}
function rrTtl(array $e, string $owner, string $type): ?array
{
    $x = $e['rr'][strtolower($owner) . ' ' . $type] ?? null;
    return $x === null ? null : ['ttl' => array_keys($x['ttl']), 'n' => $x['n']];
}

// ---------- Ausgangslage ----------
resetDb();
$c = session('admin');
$form = formFor(cgi('GET', 'pages/zones.php?add_new=1', '', $c)['body'], 'actions/zone_add.php');
postT('actions/zone_add.php', pairsT($form, ['type' => 'forward', 'zone_prefix' => 'ttl.test', 'ttl' => '7200', 'soa_mail' => 'hostmaster.ttl.test.',
    'server_ids' => ['1'], 'master_server_id' => '1']), $c);
$zt = (int)$GLOBALS['root']->query('SELECT id FROM `' . DB . "`.zones WHERE name = 'ttl.test'")->fetchColumn();
$ok($zt > 0, 'S0', "Testzone ttl.test angelegt (Zonen-TTL 7200, NS außerhalb der Zone)");

// Unveränderte Fixture-Zonen (konsistente RRsets) für den Bytevergleich
foreach ([1, 2, 3] as $fz) {
    $e = ttlEval($pdo, $fz, $outDir);
    $ok($e['ok'] && $e['warn'] === [], "Z$fz", "Fixture-Zone $fz: gültig, keine TTL-Warnung");
}

rec($zt, 'single', 'A', '192.0.2.1', 600);                                     // 1
rec($zt, 'same', 'A', '192.0.2.2', 600); rec($zt, 'same', 'A', '192.0.2.3', 600); // 2
rec($zt, 'mixed', 'A', '192.0.2.4', 3600); rec($zt, 'mixed', 'A', '192.0.2.5', 300); // 3
rec($zt, 'same6', 'AAAA', '2001:db8::2', 600); rec($zt, 'same6', 'AAAA', '2001:db8::3', 600); // 5
rec($zt, 'mixed6', 'AAAA', '2001:db8::4', 3600); rec($zt, 'mixed6', 'AAAA', '2001:db8::5', 300);
rec($zt, 'sub', 'NS', 'ns-a.example.', 3600); rec($zt, 'sub', 'NS', 'ns-b.example.', 900); // 6
rec($zt, 'txt', 'TXT', '"a"', 3600); rec($zt, 'txt', 'TXT', '"b"', 120); rec($zt, 'txt', 'TXT', '"c"', 3600); // 7
rec($zt, 'both', 'A', '192.0.2.6', 3600); rec($zt, 'both', 'AAAA', '2001:db8::6', 300); // 8
rec($zt, 'Case', 'A', '192.0.2.7', 3600); rec($zt, 'case.ttl.test.', 'A', '192.0.2.8', 600); rec($zt, 'CASE', 'A', '192.0.2.9', 1800); // 9
rec($zt, '@', 'A', '192.0.2.10', 3600); rec($zt, 'TTL.TEST.', 'A', '192.0.2.11', 1200);
rec($zt, 'nul', 'A', '192.0.2.12', null); rec($zt, 'nul', 'A', '192.0.2.13', 3600);   // 10
rec($zt, 'nul2', 'A', '192.0.2.14', null); rec($zt, 'nul2', 'A', '192.0.2.15', 9000);
rec($zt, 'nul3', 'A', '192.0.2.16', null);

$e = ttlEval($pdo, $zt, $outDir);
$ok($e['ok'], 'G0', 'Zonendatei gültig' . ($e['ok'] ? '' : ': ' . ($e['error'] ?? '')));
$ok($e['warn'] === [], 'G0w', 'keine Warnung „TTL set to prior TTL“ (named-checkzone): ' . json_encode($e['warn']));
$cases = [
    // ID, Schreibweisen in der Datei, Typ, Owner (absolut), erwartete TTL, Anzahl, Beschreibung
    ['G1', ['single'], 'A', 'single.ttl.test.', 600, 1, 'einzelner A-Record ohne Konflikt'],
    ['G2', ['same'], 'A', 'same.ttl.test.', 600, 2, 'mehrere A-Records, gleiche TTL'],
    ['G3', ['mixed'], 'A', 'mixed.ttl.test.', 300, 2, 'mehrere A-Records, TTL 3600/300 → 300'],
    ['G5a', ['same6'], 'AAAA', 'same6.ttl.test.', 600, 2, 'AAAA gleiche TTL'],
    ['G5b', ['mixed6'], 'AAAA', 'mixed6.ttl.test.', 300, 2, 'AAAA TTL 3600/300 → 300'],
    ['G6', ['sub'], 'NS', 'sub.ttl.test.', 900, 2, 'NS-RRset (Delegation) TTL 3600/900 → 900'],
    ['G7', ['txt'], 'TXT', 'txt.ttl.test.', 120, 3, 'TXT-RRset mit drei Records TTL 3600/120/3600 → 120'],
    ['G8a', ['both'], 'A', 'both.ttl.test.', 3600, 1, 'A am selben Namen wie AAAA bleibt 3600 (unabhängig)'],
    ['G8b', ['both'], 'AAAA', 'both.ttl.test.', 300, 1, 'AAAA am selben Namen bleibt 300 (unabhängig)'],
    ['G9a', ['Case', 'case.ttl.test.', 'CASE'], 'A', 'case.ttl.test.', 600, 3, 'Schreibweisen Case / case.ttl.test. / CASE = ein RRset → 600'],
    ['G9b', ['@', 'TTL.TEST.'], 'A', 'ttl.test.', 1200, 2, '@ und TTL.TEST. = Apex-RRset → 1200'],
    ['G10a', ['nul'], 'A', 'nul.ttl.test.', 3600, 2, 'ohne TTL (Zonen-TTL 7200) + 3600 → 3600'],
    ['G10b', ['nul2'], 'A', 'nul2.ttl.test.', 7200, 2, 'ohne TTL (Zonen-TTL 7200) + 9000 → 7200'],
    ['G10c', ['nul3'], 'A', 'nul3.ttl.test.', 7200, 1, 'nur ohne TTL → Zonen-TTL 7200'],
];
foreach ($cases as [$id, $names, $type, $owner, $ttl, $n, $desc]) {
    $lt = lineTtls($e, $names, $type);
    $rr = rrTtl($e, $owner, $type);
    $ok(count($lt) === $n && array_unique($lt) === [$ttl] && $rr !== null && $rr['ttl'] === [$ttl], $id,
        "$desc: Dateizeilen " . json_encode($lt) . ', BIND-RRset ' . json_encode($rr));
}
// Konsistente RRsets: Zeilen exakt wie bisher (gespeicherte bzw. Zonen-TTL), Inhalt/Name/Typ unverändert
$expectSame = ["single\t600\tIN\tA\t192.0.2.1", "same\t600\tIN\tA\t192.0.2.2", "same\t600\tIN\tA\t192.0.2.3", "nul3\t7200\tIN\tA\t192.0.2.16",
    "both\t3600\tIN\tA\t192.0.2.6", "both\t300\tIN\tAAAA\t2001:db8::6", "txt\t120\tIN\tTXT\t\"b\""];
$raws = array_column($e['lines'], 'raw');
$ok(array_diff($expectSame, $raws) === [], 'G11', 'konsistente RRsets: Zeilen unverändert (Name, TTL, Typ, Inhalt): ' . json_encode(array_values(array_diff($expectSame, $raws))));
$ok(array_map(fn($l) => $l['name'] . ' ' . $l['type'] . ' ' . $l['content'], $e['lines'])
    === array_map(fn($r) => $r[0] . ' ' . $r[1] . ' ' . $r[2], $GLOBALS['root']->query('SELECT name, type, content FROM `' . DB . "`.records WHERE zone_id = $zt ORDER BY id")->fetchAll(PDO::FETCH_NUM)),
    'G12', 'Reihenfolge, Namen, Typen und Inhalte der Zeilen = gespeicherte Records (nur TTL normalisiert)');
$dbTtls = $GLOBALS['root']->query('SELECT GROUP_CONCAT(IFNULL(ttl, \'NULL\') ORDER BY id) FROM `' . DB . "`.records WHERE zone_id = $zt")->fetchColumn();
$ok($dbTtls === '600,600,600,3600,300,600,600,3600,300,3600,900,3600,120,3600,3600,300,3600,600,1800,3600,1200,NULL,3600,NULL,9000,NULL', 'G13',
    'gespeicherte TTLs unverändert (keine Korrektur in der Datenbank)');

// ---------- R: systemgenerierte Records, Neuaufbau, Reihenfolge ----------
// u0.test: ns1.u0.test liegt in der Zone → systemgenerierter Adressrecord ns1 A 192.0.2.11 (TTL 300)
$m1 = rec(1, 'ns1', 'A', '192.0.2.11', 3600);                                   // identischer manueller Record
$e = ttlEval($pdo, 1);
$sysTtl = (int)$GLOBALS['root']->query('SELECT ttl FROM `' . DB . "`.records WHERE zone_id = 1 AND name = 'ns1' AND type = 'A' AND server_id IS NOT NULL")->fetchColumn();
$ok($e['ok'] && $e['warn'] === [] && lineTtls($e, ['ns1'], 'A') === [$sysTtl, $sysTtl] && rrTtl($e, 'ns1.u0.test.', 'A') === ['ttl' => [$sysTtl], 'n' => 1], 'R1',
    "identischer manueller (3600) und systemgenerierter (TTL $sysTtl) ns1 A: beide Zeilen $sysTtl, BIND-RRset 1 Adresse, keine Warnung");
$rebuild = function (array $servers) use (&$c): void {
    $f = formFor(cgi('GET', 'pages/zones.php?edit_id=1', '', $c)['body'], 'actions/zone_update.php', 'editForm_1');
    postT('actions/zone_update.php', pairsT($f, ['server_ids' => $servers, 'master_server_id' => '1']), $c);
};
$rebuild(['1']);           // Neuaufbau: systemgenerierte Records entfernt/neu → Reihenfolge der Zeilen ändert sich
$rebuild(['1', '2']);
$e2 = ttlEval($pdo, 1);
$order = array_values(array_map(fn($l) => $l['content'] . '/' . $l['ttl'], array_filter($e2['lines'], fn($l) => $l['name'] === 'ns1' && $l['type'] === 'A')));
$ok($e2['ok'] && $e2['warn'] === [] && rrTtl($e2, 'ns1.u0.test.', 'A') === ['ttl' => [$sysTtl], 'n' => 1], 'R2',
    "nach Neuaufbau (systemgenerierter Record jetzt nach dem manuellen): effektive TTL unverändert $sysTtl: " . json_encode($order));
sqlx("UPDATE DB.records SET ttl = 60 WHERE id = $m1");
$e3 = ttlEval($pdo, 1);
$rebuild(['1']);
$e4 = ttlEval($pdo, 1);
$ok(rrTtl($e3, 'ns1.u0.test.', 'A')['ttl'] === [60] && rrTtl($e4, 'ns1.u0.test.', 'A')['ttl'] === [60] && $e3['warn'] === [] && $e4['warn'] === [], 'R3',
    'manuelle TTL nach Neuaufbau auf 60 geändert: effektiv 60, auch nach weiterem Neuaufbau');
// Veränderte Reihenfolge der manuellen Records (Neuanlage mit neuen ids in umgekehrter Folge)
$before = rrTtl(ttlEval($pdo, $zt), 'mixed.ttl.test.', 'A');
$ids = $GLOBALS['root']->query('SELECT id, content, ttl FROM `' . DB . "`.records WHERE zone_id = $zt AND name = 'mixed' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
sqlx("DELETE FROM DB.records WHERE zone_id = $zt AND name = 'mixed'");
foreach (array_reverse($ids) as [$_, $content, $ttl]) { rec($zt, 'mixed', 'A', $content, (int)$ttl); }
$after = ttlEval($pdo, $zt, $outDir, '.umgekehrt');
$ok($before === ['ttl' => [300], 'n' => 2] && rrTtl($after, 'mixed.ttl.test.', 'A') === $before && lineTtls($after, ['mixed'], 'A') === [300, 300] && $after['warn'] === [], 'R4',
    'umgekehrte Reihenfolge der Records: effektive TTL und Zeilen unverändert 300');

// ---------- P: Vorschau und Record-Liste ----------
$r = postT('actions/record_preview.php', [['zone_id', (string)$zt], ['type', 'A'], ['ttl', '3600'], ['name', 'MIXED'], ['content', '192.0.2.99']], $c);
$j = json_decode($r['body'], true) ?? [];
$ok(($j['status'] ?? '') === 'ok' && ($j['newLine'] ?? '') === "MIXED\t300\tIN\tA\t192.0.2.99" && count($j['hints'] ?? []) === 1 && str_contains($j['hints'][0], '300'), 'P1',
    'Vorschau Anlage in ein RRset mit niedrigerer TTL: Zeile mit veröffentlichter TTL 300, Hinweis: ' . json_encode([$j['newLine'] ?? null, $j['hints'] ?? null], JSON_UNESCAPED_UNICODE));
$r = postT('actions/record_preview.php', [['zone_id', (string)$zt], ['type', 'A'], ['ttl', '600'], ['name', 'fresh'], ['content', '192.0.2.98']], $c);
$j = json_decode($r['body'], true) ?? [];
$ok(($j['newLine'] ?? '') === "fresh\t600\tIN\tA\t192.0.2.98" && ($j['hints'] ?? null) === [], 'P2', 'Vorschau ohne Konflikt: Zeile mit eingegebener TTL, kein Hinweis');
$big = (int)$GLOBALS['root']->query('SELECT id FROM `' . DB . "`.records WHERE zone_id = $zt AND name = 'mixed' AND ttl = 3600")->fetchColumn();
$r = postT('actions/record_preview.php', [['id', (string)$big], ['zone_id', (string)$zt], ['type', 'A'], ['ttl', '3600'], ['name', 'mixed'], ['content', '192.0.2.4']], $c);
$j = json_decode($r['body'], true) ?? [];
$ok(($j['oldLine'] ?? '') === "mixed\t300\tIN\tA\t192.0.2.4" && ($j['newLine'] ?? '') === "mixed\t300\tIN\tA\t192.0.2.4" && ($j['unchanged'] ?? false) === true && count($j['hints'] ?? []) === 1, 'P3',
    'Vorschau Bearbeitung (gespeichert 3600): alte und neue Zeile mit veröffentlichter TTL 300, unverändert, Hinweis');
$r = postT('actions/record_preview.php', [['id', (string)$big], ['zone_id', (string)$zt], ['type', 'A'], ['ttl', '60'], ['name', 'mixed'], ['content', '192.0.2.4']], $c);
$j = json_decode($r['body'], true) ?? [];
$ok(($j['newLine'] ?? '') === "mixed\t60\tIN\tA\t192.0.2.4" && ($j['hints'] ?? null) !== [] && ($j['unchanged'] ?? true) === false, 'P4',
    'Vorschau Bearbeitung auf 60 (neues Minimum): Zeile 60, Hinweis auf die übrigen Records des RRsets');
$page = cgi('GET', "pages/records.php?zone_id=$zt", '', $c)['body'];
$ok(preg_match('#192\.0\.2\.4</span></td><td class="dm-col-ttl">[^<]*<span class="dm-ttl-effective"[^>]*>[^<]*300#', $page) === 1, 'P5',
    'Record-Liste: gespeicherte TTL 3600 mit Kennzeichnung der veröffentlichten TTL 300');
$ok(preg_match('#192\.0\.2\.1</span></td><td class="dm-col-ttl">[^<]*</td>#', $page) === 1, 'P6', 'Record-Liste: konsistenter Record ohne Kennzeichnung');

printf("Ergebnis RRset-TTL: %d bestanden, %d fehlgeschlagen\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
