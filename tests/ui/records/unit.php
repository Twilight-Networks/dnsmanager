<?php
/**
 * Datei: tests/ui/records/unit.php
 * Zweck: Gezielte Prüfungen von RecordContentBuilder, RecordContentParser und der DKIM-Validierung (U4-Core):
 *        TXT-Aufteilung (Grenzfälle), DKIM-Parameter (Negativfälle), verlustfreier Bearbeitungs-Roundtrip für alle
 *        Records der Golden- und Matrix-Fixture, BIND-Prüfung erzeugter DKIM-Zeilen mit named-checkzone.
 *        Ohne Datenbank; liest die Fixture-Records aus den SQL-Dateien.
 * Aufruf: php unit.php <Pfad der Anwendungskopie (enthält web/)> <Harness-Verzeichnis tests/ui>
 */
[$_, $app, $harness] = $argv + [null, null, null];
if (!$app || !is_dir("$app/web")) { fwrite(STDERR, "Aufruf: php unit.php <Anwendungskopie> <tests/ui>\n"); exit(2); }
require "$app/web/inc/app/records/RecordContentBuilder.php";
require "$app/web/inc/validators.php";

$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };
$B = 'RecordContentBuilder';
$strings = fn(string $content) => preg_match_all('/"([^"]*)"/', $content, $m) ? $m[1] : [];

// ---------- 1. TXT-Aufteilung ----------
foreach ([[0, 1], [1, 1], [254, 1], [255, 1], [256, 2], [510, 2], [511, 3], [765, 3], [766, 4]] as [$len, $n]) {
    $v = str_repeat('a', $len);
    $s = $strings($B::txtStrings($v));
    $ok(count($s) === $n && implode('', $s) === $v && max(array_map('strlen', $s)) <= 255, "T-$len", "$len Bytes → " . count($s) . " Zeichenkette(n), je ≤ 255, verlustfrei zusammensetzbar");
}

// ---------- 2. DKIM-Anlage ----------
$keys = require __DIR__ . '/unit_keys.php';
$dk = function (array $f) use ($B) { return $B::build('DKIM', $f + ['name' => '', 'content' => '', 'is_dkim' => '1'], ['mode' => 'create', 'zoneName' => 'u0.test']); };
foreach (['rsa1024', 'rsa2048', 'rsa4096', 'ed25519', 'b252', 'b256'] as $kn) {
    $r = $dk(['dkim_selector' => 'sel', 'dkim_key' => $keys[$kn], 'dkim_key_type' => $kn === 'ed25519' ? 'ed25519' : 'rsa', 'dkim_hash_algos' => 'sha256']);
    $s = $strings($r['content'] ?? '');
    $joined = implode('', array_slice($s, 1));
    $val = validateDnsRecord('DKIM', $r['name'] ?? '', $r['content'] ?? '');
    $ok(!isset($r['errorCode']) && $r['type'] === 'TXT' && $r['validationType'] === 'DKIM' && $r['isDkim'] === true
        && $s[0] === 'v=DKIM1; k=' . ($kn === 'ed25519' ? 'ed25519' : 'rsa') . '; h=sha256;' && $joined === 'p=' . $keys[$kn]
        && max(array_map('strlen', $s)) <= 255 && $val === [],
        "D-$kn", sprintf('Schlüssel %d Zeichen → %d Zeichenketten (längste %d Bytes), Validierung %s', strlen($keys[$kn]), count($s), max(array_map('strlen', $s)), $val === [] ? 'ok' : implode(',', $val)));
}
$r = $dk(['dkim_selector' => 's2', 'dkim_subdomain' => 'mail', 'dkim_key' => " p=\n" . $keys['ed25519'] . " \n", 'dkim_key_type' => 'ed25519', 'dkim_flags' => 'y']);
$ok(($r['name'] ?? '') === 's2._domainkey.mail' && ($r['content'] ?? '') === '"v=DKIM1; k=ed25519; t=y;" "p=' . $keys['ed25519'] . '"', 'D-form',
    'Subdomain im Namen, Leerraum/Zeilenumbrüche und führendes p= im Schlüssel entfernt, t ohne h: ' . ($r['content'] ?? json_encode($r)));
$r = $dk(['dkim_selector' => 's1', 'dkim_key' => $keys['rsa2048']]);
$ok(str_starts_with($r['content'] ?? '', '"v=DKIM1; k=rsa;" "p='), 'D-default', 'ohne Typ: k=rsa, kein h, kein t, kein s=email');

// ---------- 3. DKIM-Negativfälle ----------
$neg = [
    'N-empty-sel'  => [['dkim_selector' => '', 'dkim_key' => $keys['rsa1024']], $B::DKIM_FIELDS_EMPTY],
    'N-empty-key'  => [['dkim_selector' => 's', 'dkim_key' => '  '], $B::DKIM_FIELDS_EMPTY],
    'N-k'          => [['dkim_selector' => 's', 'dkim_key' => $keys['rsa1024'], 'dkim_key_type' => 'dsa'], $B::DKIM_KEY_TYPE_INVALID],
    'N-h-inject'   => [['dkim_selector' => 's', 'dkim_key' => $keys['rsa1024'], 'dkim_hash_algos' => 'sha256; s=*'], $B::DKIM_HASH_INVALID],
    'N-h-quote'    => [['dkim_selector' => 's', 'dkim_key' => $keys['rsa1024'], 'dkim_hash_algos' => 'sha256"'], $B::DKIM_HASH_INVALID],
    'N-t'          => [['dkim_selector' => 's', 'dkim_key' => $keys['rsa1024'], 'dkim_flags' => 'y;p=x'], $B::DKIM_FLAGS_INVALID],
    'N-key-quote'  => [['dkim_selector' => 's', 'dkim_key' => 'AAAA" "x'], $B::DKIM_KEY_INVALID],
    'N-key-semi'   => [['dkim_selector' => 's', 'dkim_key' => 'AAAA;k=x'], $B::DKIM_KEY_INVALID],
    'N-sel-space'  => [['dkim_selector' => 'a b', 'dkim_key' => $keys['rsa1024']], $B::DKIM_SELECTOR_INVALID],
    'N-sel-quote'  => [['dkim_selector' => 'a"b', 'dkim_key' => $keys['rsa1024']], $B::DKIM_SELECTOR_INVALID],
    'N-sub-at'     => [['dkim_selector' => 's', 'dkim_subdomain' => 'a@b', 'dkim_key' => $keys['rsa1024']], $B::DKIM_SELECTOR_INVALID],
];
foreach ($neg as $id => [$f, $code]) {
    $r = $dk($f);
    $ok(($r['errorCode'] ?? null) === $code, $id, 'erwartet ' . $code . ', erhalten ' . ($r['errorCode'] ?? 'kein Fehler: ' . ($r['content'] ?? '')));
}
$val = validateDnsRecord('DKIM', 'x._domainkey', '"v=DKIM1; k=rsa; p=AAAA"');
$ok(in_array('ERR_DKIM_KEY_MISSING', $val, true), 'N-val-short', 'Validator: zu kurzer Schlüssel weiterhin abgelehnt');
$val = validateDnsRecord('DKIM', 'x._domainkey', '"v=DKIM1; k=rsa; p=' . substr($keys['rsa2048'], 0, -1) . '"');
$ok(in_array('ERR_DKIM_KEY_INVALID', $val, true), 'N-val-b64', 'Validator: ungültiges Base64 (falsche Länge) weiterhin abgelehnt');
$val = validateDnsRecord('DKIM', 'x._domainkey', '"v=DKIM1; k=ed25519; p=' . $keys['ed25519'] . '"');
$ok($val === [], 'V-pad', 'Validator: Ed25519 mit Padding = angenommen (vorher ERR_DKIM_KEY_INVALID)');

// ---------- 3b. Bereits zusammengesetzter DKIM-TXT (bisheriges Formularskript, U4C-R2) ----------
// Wie record_add_form.js: Typ TXT, is_dkim=1, Name selector._domainkey[.sub], Inhalt "Kopf;" + "p=…" in 255er-Stücken
$jsContent = function (string $head, string $key): string {
    $kp = 'p=' . $key; $c = ['"' . $head . '"'];
    for ($i = 0; $i < strlen($kp); $i += 255) { $c[] = '"' . substr($kp, $i, 255) . '"'; }
    return implode(' ', $c);
};
$js = function (string $name, string $content, ?array $existing = null) use ($B) {
    $ctx = $existing === null ? ['mode' => 'create', 'zoneName' => 'u0.test'] : ['mode' => 'update', 'zoneName' => 'u0.test', 'existing' => $existing];
    return $B::build('TXT', ['name' => $name, 'content' => $content, 'is_dkim' => '1'], $ctx);
};
foreach ([['rsa2048', 'v=DKIM1; k=rsa; h=sha256;', 's1._domainkey'], ['rsa4096', 'v=DKIM1; k=rsa;', 's5._domainkey'],
          ['ed25519', 'v=DKIM1; k=ed25519; t=y;', 's2._domainkey.mail'], ['b256', 'v=DKIM1; k=rsa; h=sha1:sha256; t=y:s;', 's4._domainkey']] as [$kn, $head, $nm]) {
    $c = $jsContent($head, $keys[$kn]);
    $r = $js($nm, $c);
    $ok(!isset($r['errorCode']) && $r['name'] === $nm && $r['content'] === $c && $r['validationType'] === 'DKIM' && $r['isDkim'] === true,
        "J-ok-$kn", 'JS-Format gültig: Name und Inhalt unverändert übernommen (' . ($r['errorCode'] ?? 'ok') . ')');
}
$c2048 = $jsContent('v=DKIM1; k=rsa;', $keys['rsa2048']);
$p2048 = '"p=' . $keys['rsa2048'] . '"';
$jsNeg = [
    // manipulierte h-/t-Parameter
    'J-h-inject'   => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; h=sha256 s=*;', $keys['rsa2048']), $B::DKIM_HASH_INVALID],
    'J-h-format'   => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; h=sha-256;', $keys['rsa2048']), $B::DKIM_HASH_INVALID],
    'J-h-empty'    => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; h=;', $keys['rsa2048']), $B::DKIM_HASH_INVALID],
    'J-h-long'     => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; h=' . str_repeat('a', 65) . ';', $keys['rsa2048']), $B::DKIM_HASH_INVALID],
    'J-t-format'   => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; t=yes;', $keys['rsa2048']), $B::DKIM_FLAGS_INVALID],
    'J-t-inject'   => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; t=y p=AAAA;', $keys['rsa2048']), $B::DKIM_FLAGS_INVALID],
    'J-t-long'     => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; t=' . implode(':', array_fill(0, 9, 'y')) . ';', $keys['rsa2048']), $B::DKIM_FLAGS_INVALID],
    // unzulässiger DKIM-Inhalt
    'J-k'          => ['s1._domainkey', $jsContent('v=DKIM1; k=dsa;', $keys['rsa2048']), $B::DKIM_KEY_TYPE_INVALID],
    'J-tag-s'      => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; s=email;', $keys['rsa2048']), $B::DKIM_CONTENT_INVALID],
    'J-tag-other'  => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; x=1;', $keys['rsa2048']), $B::DKIM_CONTENT_INVALID],
    'J-tag-dup'    => ['s1._domainkey', $jsContent('v=DKIM1; k=rsa; k=ed25519;', $keys['rsa2048']), $B::DKIM_CONTENT_INVALID],
    'J-p-dup'      => ['s1._domainkey', $c2048 . ' "; p=AAAA"', $B::DKIM_CONTENT_INVALID],
    'J-no-tag'     => ['s1._domainkey', '"v=DKIM1; k=rsa; foo;" ' . $p2048, $B::DKIM_CONTENT_INVALID],
    'J-256'        => ['s1._domainkey', '"v=DKIM1; k=rsa;" "p=' . $keys['b256'] . '"', $B::DKIM_CONTENT_INVALID],
    'J-unquoted'   => ['s1._domainkey', '"v=DKIM1; k=rsa;" p=' . $keys['rsa2048'], $B::DKIM_CONTENT_INVALID],
    'J-trailing'   => ['s1._domainkey', $c2048 . ' ; evil', $B::DKIM_CONTENT_INVALID],
    'J-backslash'  => ['s1._domainkey', '"v=DKIM1; k=rsa;\\" x" ' . $p2048, $B::DKIM_CONTENT_INVALID],
    'J-p-missing'  => ['s1._domainkey', '"v=DKIM1; k=rsa;"', $B::DKIM_FIELDS_EMPTY],
    'J-p-empty'    => ['s1._domainkey', '"v=DKIM1; k=rsa;" "p="', $B::DKIM_FIELDS_EMPTY],
    'J-p-chars'    => ['s1._domainkey', '"v=DKIM1; k=rsa;" "p=AAAA!BBB"', $B::DKIM_KEY_INVALID],
    'J-p-space'    => ['s1._domainkey', '"v=DKIM1; k=rsa;" "p=AAAA BBBB"', $B::DKIM_KEY_INVALID],
    'J-p-pad'      => ['s1._domainkey', '"v=DKIM1; k=rsa;" "p=AAAA===="', $B::DKIM_KEY_INVALID],
    // unzulässiger Name
    'J-name-space' => ['s 1._domainkey', $c2048, $B::DKIM_SELECTOR_INVALID],
    'J-name-abs'   => ['s1._domainkey.u0.test.', $c2048, $B::DKIM_SELECTOR_INVALID],
    'J-name-plain' => ['www', $c2048, $B::DKIM_SELECTOR_INVALID],
    'J-name-long'  => [str_repeat('a', 64) . '._domainkey', $c2048, $B::DKIM_SELECTOR_INVALID],
];
foreach ($jsNeg as $id => [$nm, $c, $code]) {
    $r = $js($nm, $c);
    $ok(($r['errorCode'] ?? null) === $code, $id, 'erwartet ' . $code . ', erhalten ' . ($r['errorCode'] ?? 'kein Fehler: ' . substr($r['content'] ?? '', 0, 60)));
}
// Ohne v=DKIM1 am Anfang der ersten Zeichenkette kein DKIM-Inhalt: als TXT geprüft, nicht als DKIM gekennzeichnet (wie bisher)
foreach (['J-v-order' => '"k=rsa; v=DKIM1;" ' . $p2048, 'J-v-value' => '"v=DKIM2; k=rsa;" ' . $p2048] as $id => $c) {
    $r = $js('s1._domainkey', $c);
    $ok(!isset($r['errorCode']) && $r['validationType'] === 'TXT' && $r['isDkim'] === false, $id, 'kein DKIM-Inhalt → TXT-Prüfung, is_dkim wirkungslos');
}
// Bearbeitung: gespeicherter Altinhalt (s=email) unverändert gesendet → exakt erhalten; geänderter Inhalt wird geprüft
$old = ['id' => 109, 'name' => 'old._domainkey', 'type' => 'TXT', 'content' => '"v=DKIM1; k=rsa; s=email; p=' . $keys['rsa1024'] . '"'];
$r = $js($old['name'], $old['content'], $old);
$ok(!isset($r['errorCode']) && $r['name'] === $old['name'] && $r['content'] === $old['content'], 'J-upd-keep', 'Bearbeitung: unveränderter Altinhalt mit s=email exakt erhalten');
$r = $js($old['name'], '"v=DKIM1; k=rsa; h=sha256 s=*; p=' . $keys['rsa1024'] . '"', $old);
$ok(($r['errorCode'] ?? null) === $B::DKIM_HASH_INVALID, 'J-upd-h', 'Bearbeitung: manipuliertes h im geänderten Inhalt abgelehnt (' . ($r['errorCode'] ?? 'kein Fehler') . ')');
$r = $js($old['name'], '"hello"', $old);
$ok(($r['errorCode'] ?? null) === $B::DKIM_CONTENT_INVALID, 'J-upd-plain', 'Bearbeitung: DKIM-Record mit Nicht-DKIM-Inhalt abgelehnt (' . ($r['errorCode'] ?? 'kein Fehler') . ')');

// ---------- 4. DKIM-Erkennung ----------
$ok($B::isDkimTxt('"v=DKIM1; k=rsa;" "p=AAAA"') && $B::isDkimTxt('"v=DKIM1; k=rsa; s=email; p=X"') && !$B::isDkimTxt('"v=spf1 -all"')
    && !$B::isDkimTxt('"hello v=DKIM1"') && !$B::isDkimTxt(''), 'K-isdkim', 'DKIM-TXT erkannt, SPF/Text nicht');
$r = $B::build('TXT', ['name' => 't', 'content' => '"hello"', 'is_dkim' => '1'], ['mode' => 'create', 'zoneName' => 'u0.test']);
$ok(($r['validationType'] ?? '') === 'TXT' && $r['isDkim'] === false, 'K-u0b1', 'TXT mit verstecktem is_dkim=1 (ohne JS) wird als TXT geprüft (U0-B1)');

// ---------- 5. Verlustfreier Bearbeitungs-Roundtrip aller Fixture-Records ----------
// Felder wie templates/record_edit_form.php sie aus dem Parser rendert (ohne Änderung abgesendet)
$zones = [1 => 'u0.test', 2 => 'other.u0.test', 3 => '2.0.192.in-addr.arpa'];
$records = [];
$sql = (string)file_get_contents("$harness/records/matrix_fixture.sql") . "\n" . (string)shell_exec('php ' . escapeshellarg("$harness/fixture.php") . ' 2>/dev/null');
preg_match_all("/\((\d+), (\d+), '((?:[^'\\\\]|\\\\.|'')*)', '([A-Z]+)', '((?:[^'\\\\]|\\\\.|'')*)', (\d+), (NULL|\d+)\)/", $sql, $mm, PREG_SET_ORDER);
foreach ($mm as $m) {
    $un = fn(string $s) => str_replace(["''", '\\"', "\\'"], ["'", '"', "'"], $s);
    $records[(int)$m[1]] = ['zone' => (int)$m[2], 'name' => $un($m[3]), 'type' => $m[4], 'content' => $un($m[5])];
}
$rt = 0; $rtFail = [];
foreach ($records as $id => $rec) {
    $zoneName = $zones[$rec['zone']];
    $isDkim = $rec['type'] === 'TXT' && str_contains($rec['content'], 'v=DKIM1') && str_contains($rec['name'], '._domainkey');
    $p = RecordContentParser::parse($rec['type'], $rec['name'], $rec['content'], $isDkim, $zoneName);
    $f = ['id' => (string)$id, 'name' => $p['name'], 'type' => $rec['type'], 'content' => $p['content'], 'ttl' => '3600',
        'mx_priority' => $p['mxPriority'], 'srv_protocol' => '_' . ($p['srvProto'] ?: 'tcp'), 'srv_priority' => $p['srvPriority'],
        'srv_weight' => $p['srvWeight'], 'srv_port' => $p['srvPort'], 'srv_target' => $p['srvTarget'],
        'naptr_order' => $p['naptrOrder'], 'naptr_pref' => $p['naptrPref'], 'naptr_flags' => $p['naptrFlags'], 'naptr_service' => $p['naptrService'],
        'naptr_regexp' => $p['naptrRegexp'], 'naptr_replacement' => $p['naptrReplace'],
        'uri_service' => $p['uriService'], 'uri_protocol' => $p['uriProto'] ?: '_tcp', 'uri_priority' => $p['uriPriority'], 'uri_weight' => $p['uriWeight'], 'uri_target' => $p['uriTarget'],
        'edit_name_prefix' => $p['caaPrefix'], 'is_dkim' => '1',
        'dkim_selector' => $p['dkimSelector'], 'dkim_subdomain' => $p['dkimSubdomain'], 'dkim_key_type' => $p['dkimKeyType'],
        'dkim_hash_algos' => $p['dkimHashAlgos'], 'dkim_flags' => $p['dkimFlags'], 'dkim_key' => $p['dkimKey']];
    $r = $B::build($rec['type'], $f, ['mode' => 'update', 'zoneName' => $zoneName, 'existing' => $rec]);
    $same = !isset($r['errorCode']) && $r['name'] === $rec['name'] && $r['content'] === $rec['content'] && $r['type'] === ($rec['type'] === 'SPF' ? 'TXT' : $rec['type']);
    $rt++;
    if (!$same) { $rtFail[] = "$id {$rec['type']} {$rec['name']} → " . json_encode($r, JSON_UNESCAPED_SLASHES); }
}
$ok($rtFail === [] && $rt >= 30, 'R-all', "$rt Records (Golden + Matrix): Bearbeitung ohne Änderung erhält Name, Typ und Inhalt exakt" . ($rtFail ? ': ' . implode(' | ', $rtFail) : ''));

// Parser: DKIM-Schlüssel mit Padding, Tags getrennt (t= nicht im Schlüssel), Zusatztags ignoriert
$p = RecordContentParser::parse('TXT', 'ed._domainkey.sub', '"v=DKIM1; k=ed25519; t=y;" "p=' . $keys['ed25519'] . '"', true, 'u0.test');
$ok($p['dkimKey'] === $keys['ed25519'] && $p['dkimKeyType'] === 'ed25519' && $p['dkimFlags'] === 'y' && $p['dkimSelector'] === 'ed' && $p['dkimSubdomain'] === 'sub', 'P-pad', 'Parser behält Padding, k/t/Selector/Subdomain korrekt');
$p = RecordContentParser::parse('TXT', 's._domainkey', '"v=DKIM1; k=rsa; s=email; h=sha256; p=MIIBt=/Xt=AQAB"', true, 'u0.test');
$ok($p['dkimFlags'] === '' && $p['dkimHashAlgos'] === 'sha256', 'P-tags', 'kein t= aus dem Schlüssel und kein t aus s=email gelesen (tag-basiert)');

// ---------- 6. BIND: named-checkzone für erzeugte DKIM-Zeilen ----------
$dir = sys_get_temp_dir() . '/u4core-bind-' . getmypid();
mkdir($dir, 0700);
$zone = "\$TTL 3600\n@ IN SOA ns1.u0.test. hostmaster.u0.test. ( 1 3600 900 1209600 300 )\n@ IN NS ns1.u0.test.\nns1 IN A 192.0.2.11\n";
foreach (['rsa1024', 'rsa2048', 'rsa4096', 'ed25519', 'b252', 'b256'] as $kn) {
    $r = $dk(['dkim_selector' => "k$kn", 'dkim_key' => $keys[$kn], 'dkim_key_type' => $kn === 'ed25519' ? 'ed25519' : 'rsa', 'dkim_hash_algos' => 'sha256', 'dkim_flags' => 'y']);
    $zone .= "{$r['name']}\t3600\tIN\tTXT\t{$r['content']}\n";   // Zeilenformat wie bind_file_generator.php
}
file_put_contents("$dir/ok.zone", $zone);
$out = (string)shell_exec('named-checkzone u0.test ' . escapeshellarg("$dir/ok.zone") . ' 2>&1');
$ok(str_contains($out, 'loaded serial') && str_contains($out, 'OK'), 'B-dkim', '6 DKIM-Zeilen (RSA 1024/2048/4096, Ed25519, 252/256 Zeichen): ' . trim(str_replace("\n", ' ', $out)));
file_put_contents("$dir/bad.zone", $zone . "bad._domainkey\t3600\tIN\tTXT\t\"" . str_repeat('A', 256) . "\"\n");
$out = (string)shell_exec('named-checkzone u0.test ' . escapeshellarg("$dir/bad.zone") . ' 2>&1');
$ok(!str_contains($out, 'loaded serial') && str_contains($out, 'syntax error'), 'B-neg', 'Gegenprobe: eine einzelne Zeichenkette mit 256 Bytes wird von BIND abgelehnt');
array_map('unlink', glob("$dir/*.zone")); rmdir($dir);

printf("== Builder/Parser/BIND: %d/%d bestanden\n", $pass, $pass + $fail);
exit($fail ? 1 : 0);
