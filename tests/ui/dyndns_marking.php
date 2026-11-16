<?php
/**
 * Datei: tests/ui/dyndns_marking.php
 * Zweck: Regressionstests der DynDNS-Kennzeichnung in der Record-Verwaltung (pages/records.php) gegen die Testkopie:
 *        M  eindeutig verwalteter Record: nur Kennzeichen „DynDNS“ (kein „Manuell“), Bearbeiten (TTL) ja, Löschen und
 *           Mehrfachauswahl nein;
 *        A  mehrdeutig reservierter Name (zweiter Record, abweichende Account-IP, loser Collation-Treffer): „DynDNS ·
 *           Konflikt“, keine Bearbeiten-/Lösch-/Auswahlaktion, Hinweis; direkter Editor-Aufruf ohne Formular, mit
 *           Hinweis; serverseitige Ablehnung von Löschen und Ändern unverändert;
 *        N  normaler manueller Record und Systemrecords unverändert;
 *        Z  zoneadmin sieht dieselbe Kennzeichnung.
 *        Die Seite wird serverseitig erzeugt; ohne JavaScript gilt dieselbe Ausgabe (Mehrfachauswahl = Checkboxen).
 * Aufruf (über run.sh matrix): php dyndns_marking.php
 */
require __DIR__ . '/lib.php';

$pass = 0; $fail = 0;
$ok = function (bool $c, string $id, string $msg) use (&$pass, &$fail) { $c ? $pass++ : $fail++; printf("%s %s %s\n", $c ? 'PASS' : 'FAIL', $id, $msg); };

function postD(string $path, array $pairs, array &$c): array
{
    $pairs[] = ['csrf_token', csrfFromSession($c)];
    return cgi('POST', $path, implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs)), $c);
}
/** Tabellenzeile eines Records (über seine ID in Auswahl-, Bearbeiten- oder Lösch-Element bzw. Inhalt) */
function rowOf(string $html, int $id, string $content): string
{
    foreach (preg_split('#(?=<tr)#', $html) as $tr) {
        if (str_contains($tr, 'title="' . $content . '"')) { return strstr($tr, '</tr>', true) ?: $tr; }
    }
    return '';
}
function rowInfo(string $row, int $id): array
{
    return [
        'manual'   => str_contains($row, '>Manuell<'),
        'dyndns'   => preg_match('#>DynDNS</span>#', $row) === 1 || preg_match('#DynDNS</span>#', $row) === 1,
        'conflict' => str_contains($row, 'DynDNS · Konflikt'),
        'edit'     => str_contains($row, 'edit_id=' . $id . '"'),
        'delete'   => preg_match('#name="id" value="' . $id . '"#', $row) === 1,
        'select'   => preg_match('#value="' . $id . '"[^>]*>#', $row) === 1 && str_contains($row, 'type="checkbox"'),
        'hint'     => str_contains($row, 'In der DynDNS-Verwaltung klären'),
    ];
}
$page = fn(array &$c) => cgi('GET', 'pages/records.php?zone_id=1', '', $c)['body'];

// ---------- M: eindeutig verwaltet (Fixture: dyn A 192.0.2.99, Account dyn 192.0.2.99) ----------
resetDb();
$a = session('admin');
$html = $page($a);
$m = rowInfo(rowOf($html, 8, '192.0.2.99'), 8);
$ok($m['dyndns'] && !$m['manual'] && !$m['conflict'] && $m['edit'] && !$m['delete'] && !$m['select'], 'M1',
    'verwalteter Record: nur „DynDNS“, Bearbeiten ja, Löschen/Auswahl nein: ' . json_encode($m));
$ed = cgi('GET', 'pages/records.php?zone_id=1&edit_id=8', '', $a)['body'];
$ok(formFor($ed, 'actions/record_update.php', 'editForm_8') !== null && str_contains($ed, 'Von DynDNS verwaltet: nur die TTL ist änderbar.'), 'M2',
    'Editor des verwalteten Records: Formular (TTL änderbar) und bisheriger Hinweis');
$n = rowInfo(rowOf($html, 3, '192.0.2.80'), 3);
$ok($n['manual'] && !$n['dyndns'] && !$n['conflict'] && $n['edit'] && $n['delete'] && $n['select'], 'N1',
    'normaler manueller Record (www): „Manuell“, Bearbeiten, Löschen, Auswahl unverändert: ' . json_encode($n));
$sys = [];
foreach (preg_split('#(?=<tr)#', $html) as $tr) {
    if (!str_starts_with($tr, '<tr')) { continue; }
    $tr = strstr($tr, '</tr>', true) ?: $tr;
    if (str_contains($tr, 'dm-col-origin') && str_contains($tr, '>System')) { $sys[] = (str_contains($tr, 'DynDNS') ? 'DynDNS!' : 'ok'); }
}
$ok($sys !== [] && !in_array('DynDNS!', $sys, true), 'N2', 'Systemrecords ohne DynDNS-Kennzeichen (' . count($sys) . ' Zeilen)');

// ---------- A: mehrdeutig reserviert ----------
$variants = [
    'A1' => ['sql' => ["INSERT INTO DB.records (id, zone_id, name, type, content, ttl) VALUES (901, 1, 'dyn', 'A', '192.0.2.97', 300)"],
        'rows' => [[8, '192.0.2.99'], [901, '192.0.2.97']], 'desc' => 'zweiter A-Record unter dem Hostnamen'],
    'A2' => ['sql' => ["UPDATE DB.dyndns_accounts SET current_ipv4 = '192.0.2.98' WHERE id = 1"],
        'rows' => [[8, '192.0.2.99']], 'desc' => 'Account-IP weicht vom Record ab'],
    'A3' => ['sql' => ["INSERT INTO DB.dyndns_accounts (username, password_hash, zone_id, hostname, current_ipv4) VALUES ('u0dyn3', 'x', 1, 'dýn', '192.0.2.99')"],
        'rows' => [[8, '192.0.2.99']], 'desc' => 'zusätzlicher Account mit losem Collation-Treffer (dýn)'],
];
foreach ($variants as $id => $v) {
    resetDb();
    foreach ($v['sql'] as $s) { sqlx($s); }
    $a = session('admin');
    $html = $page($a);
    foreach ($v['rows'] as [$rid, $content]) {
        $i = rowInfo(rowOf($html, $rid, $content), $rid);
        $ok($i['conflict'] && !$i['manual'] && !$i['edit'] && !$i['delete'] && !$i['select'] && $i['hint'], "$id-$rid",
            "{$v['desc']}: Record $rid „DynDNS · Konflikt“, keine Bearbeiten-/Lösch-/Auswahlaktion, Hinweis: " . json_encode($i));
    }
}
// A4: direkter Editor-Aufruf und serverseitige Ablehnung (Zustand A1)
resetDb();
sqlx("INSERT INTO DB.records (id, zone_id, name, type, content, ttl) VALUES (901, 1, 'dyn', 'A', '192.0.2.97', 300)");
$a = session('admin');
$ed = cgi('GET', 'pages/records.php?zone_id=1&edit_id=901', '', $a)['body'];
$ok(formFor($ed, 'actions/record_update.php', 'editForm_901') === null && str_contains($ed, 'lässt sich keinem DynDNS-Account eindeutig zuordnen'), 'A4',
    'direkter Editor-Aufruf für den reservierten Record: kein Bearbeitungsformular, Hinweis auf die Klärung');
$before = snap();
cgi('GET', 'pages/records.php?zone_id=1', '', $a);
postD('actions/record_delete.php', [['id', '901'], ['zone_id', '1']], $a);
postD('actions/record_update.php', [['id', '901'], ['zone_id', '1'], ['type', 'A'], ['name', 'dyn'], ['content', '192.0.2.97'], ['ttl', '600']], $a);
$ok(snap()['records'] === $before['records'], 'A5', 'manipuliertes Löschen und Ändern des reservierten Records: serverseitig abgelehnt, Records unverändert');

// ---------- Z: zoneadmin (Zone 1 zugewiesen) ----------
$z = session('zoneadmin');
$html = $page($z);
$i = rowInfo(rowOf($html, 901, '192.0.2.97'), 901);
$ok($i['conflict'] && !$i['edit'] && !$i['delete'] && !$i['select'] && $i['hint'], 'Z1', 'zoneadmin: dieselbe Konflikt-Kennzeichnung ohne Aktionen: ' . json_encode($i));

printf("Ergebnis DynDNS-Kennzeichnung: %d bestanden, %d fehlgeschlagen\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
