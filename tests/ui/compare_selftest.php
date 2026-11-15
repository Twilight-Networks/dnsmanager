<?php
/**
 * Datei: tests/ui/compare_selftest.php
 * Zweck: Negative Selbsttests der spaltenbezogenen Golden-Ausnahme „db~“ (SOA-V1, compare.php):
 *        Nur die exakt freigegebene Einzeländerung wird akzeptiert; jede zusätzliche oder abweichende
 *        Datenbankänderung muss den Vergleich fehlschlagen lassen (Exit 1).
 *        S1–S9 synthetisch; mit Ergebnisdatei zusätzlich R1/R2 gegen den echten Golden-Lauf.
 * Aufruf: php compare_selftest.php [<basis.json> <ergebnis.json> <ausnahmen.json>]
 */
$cmp = __DIR__ . '/compare.php';
$tmp = sys_get_temp_dir() . '/uig-cmpself-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$pass = 0; $fail = 0;
$run = function (array $base, array $new, array $allow) use ($cmp, $tmp): int {
    file_put_contents("$tmp/b.json", json_encode(['cases' => $base]));
    file_put_contents("$tmp/n.json", json_encode(['cases' => $new]));
    file_put_contents("$tmp/a.json", json_encode($allow));
    exec('php ' . escapeshellarg($cmp) . " $tmp/b.json $tmp/n.json $tmp/a.json", $out, $rc);
    return $rc;
};
$check = function (string $id, int $rc, int $expect, string $msg) use (&$pass, &$fail) {
    $ok = $rc === $expect; $ok ? $pass++ : $fail++;
    printf("%s %s %s (Exit %d, erwartet %d)\n", $ok ? 'PASS' : 'FAIL', $id, $msg, $rc, $expect);
};

$row = fn(string $soa, string $ttl = '86400', string $serial = 'neu') => "4|neu.u0.test|forward|$ttl|NULL|U0|$soa|hostmaster.neu.u0.test.|3600|1200|1209600|3600|$serial|1|0";
$db = fn(string $zoneRow, array $records = ['1|1|@|NS|ns1.u0.test.']) => ['zones' => ['1|u0.test|forward', $zoneRow], 'records' => $records];
$case = fn(array $d) => ['zone/add_admin' => ['status' => 302, 'db' => $d], 'other/case' => ['status' => 200, 'db' => ['zones' => ['x']]]];
$allow = ['_db_changes' => ['zone/add_admin' => [['table' => 'zones', 'id' => '4', 'column' => 'soa_ns', 'field' => 6, 'from' => 'ns1.u0.test.neu.u0.test.', 'to' => 'ns1.u0.test.']]],
    'zone/add_admin' => ['db~']];
$base = $case($db($row('ns1.u0.test.neu.u0.test.')));

$check('S1', $run($base, $case($db($row('ns1.u0.test.'))), $allow), 0, 'nur die freigegebene soa_ns-Änderung');
$check('S2', $run($base, $case($db($row('ns1.u0.test.'), ['1|1|@|NS|ns1.u0.test.', '2|4|@|NS|evil.test.'])), $allow), 1, 'zusätzlicher Record');
$check('S3', $run($base, $case($db($row('ns1.u0.test.'), [])), $allow), 1, 'fehlender Record');
$check('S4', $run($base, $case($db($row('ns9.u0.test.'))), $allow), 1, 'soa_ns mit anderem Zielwert');
$check('S5', $run($base, $case($db($row('ns1.u0.test.', '7200'))), $allow), 1, 'weitere Spalte derselben Zeile (TTL)');
$check('S6', $run($base, $case($db($row('ns1.u0.test.', '86400', 'wie-Fixture'))), $allow), 1, 'Serial-Status derselben Zeile');
$d = $db($row('ns1.u0.test.')); $d['zone_servers'] = ['4|1|1'];
$check('S7', $run($base, $case($d), $allow), 1, 'zusätzliche Tabelle/Zuweisung');
$d = $db($row('ns1.u0.test.')); $d['zones'][] = '5|neu2.u0.test|forward';
$check('S8', $run($base, $case($d), $allow), 1, 'zusätzliche Zonenzeile');
$check('S9', $run($base, $case($db($row('ns1.u0.test.'))), ['zone/add_admin' => ['db~']]), 1, '„db~“ ohne passende _db_changes-Angabe');
$b2 = $base; $b2['zone/add_admin']['db']['zones'][1] = $row('ns1.u0.test.anders.');
$check('S10', $run($b2, $case($db($row('ns1.u0.test.'))), $allow), 1, 'Basiswert ungleich „from“');

// R: gegen den echten Golden-Lauf (unverändert muss bestehen, manipuliert muss scheitern)
if (count($argv) === 4) {
    [$_, $bf, $nf, $af] = $argv;
    $B = json_decode(file_get_contents($bf), true)['cases']; $N = json_decode(file_get_contents($nf), true)['cases']; $A = json_decode(file_get_contents($af), true);
    $check('R1', $run($B, $N, $A), 0, 'echter Golden-Lauf mit exceptions-soa.json');
    $M = $N; $M['zone/add_admin']['db']['records'][] = '999|4|manip|A|192.0.2.250|300|NULL';
    $check('R2', $run($B, $M, $A), 1, 'echter Lauf + manipulierter zusätzlicher Record in zone/add_admin');
    $M = $N; $M['zone/add_admin']['db']['zone_servers'] = array_slice($M['zone/add_admin']['db']['zone_servers'] ?? [], 1);
    $check('R3', $run($B, $M, $A), 1, 'echter Lauf + fehlende Serverzuweisung in zone/add_admin');
    $M = $N; foreach ($M['zone/add_admin']['db']['zones'] as $i => $r) { if (str_starts_with($r, '4|')) { $M['zone/add_admin']['db']['zones'][$i] = str_replace('|forward|86400|', '|forward|7200|', $r); } }
    $check('R4', $run($B, $M, $A), 1, 'echter Lauf + geänderte TTL der neuen Zone');
}
array_map('unlink', glob("$tmp/*")); rmdir($tmp);
printf("Ergebnis Selbsttest db~: %d bestanden, %d fehlgeschlagen\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
