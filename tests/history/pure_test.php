<?php
/**
 * Datei: tests/history/pure_test.php
 * Zweck: Reine Tests (ohne Datenbank) für ZoneStateSnapshot (Vertrag zs1), ZoneSnapshotDiff und die
 *        Strukturbewertung von SchemaCheck (AP1/H2a).
 *
 * Aufruf: php pure_test.php <App-Verzeichnis>
 */

require_once __DIR__ . '/lib.php';
$app = rtrim($argv[1] ?? '', '/');
if ($app === '' || !is_dir("$app/web/inc/app/history")) {
    fwrite(STDERR, "Aufruf: php pure_test.php <App-Verzeichnis>\n");
    exit(2);
}
require_once "$app/web/inc/app/history/ZoneStateSnapshot.php";
require_once "$app/web/inc/app/history/ZoneSnapshotDiff.php";
require_once "$app/web/inc/app/SchemaCheck.php";

/* ---------------------------------------------------------------------------------------------------------------- */
/* Fixture                                                                                                          */
/* ---------------------------------------------------------------------------------------------------------------- */

function zoneRow(array $override = []): array
{
    return $override + [
        'id' => '7', 'name' => 'example.test', 'type' => 'forward', 'ttl' => '3600', 'prefix_length' => null,
        'description' => 'Beschreibung', 'soa_ns' => 'ns1.example.test.', 'soa_mail' => 'hostmaster.example.test.',
        'soa_refresh' => '3600', 'soa_retry' => '1800', 'soa_expire' => '1209600', 'soa_minimum' => '86400',
        'soa_serial' => '2026111801', 'changed' => '1', 'created_at' => '2026-11-18 10:00:00', 'allow_dyndns' => '1',
    ];
}

function assignments(): array
{
    return [['server_id' => '2', 'is_master' => '0'], ['server_id' => '1', 'is_master' => '1']];
}

function records(): array
{
    return [
        ['id' => '10', 'zone_id' => '7', 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.10', 'ttl' => '3600', 'server_id' => null],
        ['id' => '11', 'zone_id' => '7', 'name' => '@', 'type' => 'NS', 'content' => 'ns1.example.test.', 'ttl' => null, 'server_id' => '1'],
        ['id' => '12', 'zone_id' => '7', 'name' => 'txt', 'type' => 'TXT', 'content' => '"v=spf1 -all" ä/ü', 'ttl' => '300', 'server_id' => null],
        ['id' => '13', 'zone_id' => '7', 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.10', 'ttl' => '3600', 'server_id' => null],
    ];
}

function snap(?array $zone = null, ?array $assign = null, ?array $recs = null): ZoneStateSnapshot
{
    return ZoneStateSnapshot::fromRows($zone ?? zoneRow(), $assign ?? assignments(), $recs ?? records());
}

/** Vertragsvektor zs1 (eingefroren; Hash unabhängig mit sha256sum ermittelt) */
const VECTOR_PAYLOAD = '{"contract":"zs1","zone":{"name":"example.test","type":"forward","ttl":3600,"prefix_length":null,'
    . '"soa_ns":"ns1.example.test.","soa_mail":"hostmaster.example.test.","soa_refresh":3600,"soa_retry":1800,'
    . '"soa_expire":1209600,"soa_minimum":86400},"servers":[[1,1],[2,0]],"records":[["@","NS","ns1.example.test.",null,1],'
    . '["txt","TXT","\"v=spf1 -all\" ä/ü",300,null],["www","A","192.0.2.10",3600,null],["www","A","192.0.2.10",3600,null]]}';
const VECTOR_HASH = '0a959f5a4fc23ad1100e9411822addd4a5156f5a7f378e88289d2664408e733e';

// Vertragsvektor roh ausgeben (unabhängige Hashprüfung mit sha256sum, siehe run.sh)
if (($argv[2] ?? '') === '--dump-vector') {
    echo VECTOR_PAYLOAD;
    exit(0);
}

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('ZoneStateSnapshot – Vertrag zs1');
/* ---------------------------------------------------------------------------------------------------------------- */

$base = snap();
T::eq(VECTOR_PAYLOAD, $base->payload(), 'S01', 'kanonische Payload entspricht dem Vertragsvektor');
T::eq(VECTOR_HASH, $base->contentHash(), 'S02', 'Hash entspricht dem eingefrorenen Vertragsvektor');
T::eq(hash('sha256', VECTOR_PAYLOAD), $base->contentHash(), 'S03', 'Hash = SHA-256 über die Payload-Bytes');
T::ok(preg_match('/^[0-9a-f]{64}$/D', $base->contentHash()) === 1, 'S04', 'Hash 64 Zeichen hex klein');
T::eq('zs1', $base->contract(), 'S05', 'Vertragskennung');
T::eq(4, $base->recordCount(), 'S06', 'Duplikate bleiben erhalten (4 Records)');
T::eq('example.test', $base->zoneName(), 'S07', 'Zonenname');

// Reihenfolgeunabhängigkeit
$recs = records();
$shuffled = [$recs[3], $recs[1], $recs[0], $recs[2]];
T::eq($base->contentHash(), snap(null, array_reverse(assignments()), $shuffled)->contentHash(), 'S08', 'unabhängig von der Eingabereihenfolge');

// Ganzzahlen als int oder Zeichenkette (PDO emuliert/nativ) gleichwertig
$intZone = zoneRow(['ttl' => 3600, 'soa_refresh' => 3600, 'soa_retry' => 1800, 'soa_expire' => 1209600, 'soa_minimum' => 86400]);
$intRecs = array_map(static fn (array $r): array => ['ttl' => $r['ttl'] === null ? null : (int)$r['ttl'], 'server_id' => $r['server_id'] === null ? null : (int)$r['server_id']] + $r, records());
$intAssign = [['server_id' => 2, 'is_master' => 0], ['server_id' => 1, 'is_master' => 1]];
T::eq($base->contentHash(), snap($intZone, $intAssign, $intRecs)->contentHash(), 'S09', 'int und Ziffernfolge ergeben denselben Hash');

// Ausgeschlossene Felder ändern den Hash nicht
$excluded = [
    'id' => '8', 'soa_serial' => '2026111899', 'changed' => '0', 'created_at' => '2020-01-01 00:00:00',
    'description' => null, 'allow_dyndns' => '0', 'password_hash' => '$2y$12$geheim', 'api_token' => 'tok',
];
foreach ($excluded as $field => $value) {
    T::eq($base->contentHash(), snap(zoneRow([$field => $value]))->contentHash(), 'S10', "Zonenfeld $field nicht im Hash");
}
$recsExtra = array_map(static fn (array $r): array => ['id' => '99' . $r['id'], 'zone_id' => '1', 'password_hash' => 'x'] + $r, records());
T::eq($base->contentHash(), snap(null, null, $recsExtra)->contentHash(), 'S11', 'Record-ID, zone_id und Fremdfelder nicht im Hash');
foreach (['password', 'token', 'serial', 'changed', 'description', 'allow_dyndns', 'created_at', '"id"'] as $needle) {
    T::ok(stripos($base->payload(), $needle) === false, 'S12', "Payload enthält kein '$needle'");
}

// Jedes enthaltene Feld ändert den Hash
$zoneChanges = [
    'name' => 'other.test', 'type' => 'reverse', 'ttl' => '3601', 'prefix_length' => '24', 'soa_ns' => 'ns2.example.test.',
    'soa_mail' => 'admin.example.test.', 'soa_refresh' => '3601', 'soa_retry' => '1801', 'soa_expire' => '1209601', 'soa_minimum' => '86401',
];
foreach ($zoneChanges as $field => $value) {
    T::ok(snap(zoneRow([$field => $value]))->contentHash() !== $base->contentHash(), 'S13', "Zonenfeld $field im Hash");
}
foreach (['name' => 'web', 'type' => 'AAAA', 'content' => '192.0.2.11', 'ttl' => '60', 'server_id' => '2'] as $field => $value) {
    $r = records();
    $r[0][$field] = $value;
    T::ok(snap(null, null, $r)->contentHash() !== $base->contentHash(), 'S14', "Recordfeld $field im Hash");
}
T::ok(snap(null, [['server_id' => '1', 'is_master' => '1']])->contentHash() !== $base->contentHash(), 'S15', 'Serverzuweisung im Hash');
T::ok(snap(null, [['server_id' => '2', 'is_master' => '1'], ['server_id' => '1', 'is_master' => '0']])->contentHash() !== $base->contentHash(), 'S16', 'Master-Kennzeichen im Hash');

// NULL, "" und 0 unterscheidbar; Werte wie gespeichert
$h = static function (array $patch): string {
    $r = records();
    $r[0] = $patch + $r[0];
    return snap(null, null, $r)->contentHash();
};
T::ok($h(['ttl' => null]) !== $h(['ttl' => '0']), 'S17', 'Record-TTL NULL ≠ 0');
T::ok($h(['server_id' => null]) !== $h(['server_id' => '0']), 'S18', 'server_id NULL ≠ 0');
T::ok($h(['content' => '']) !== $h(['content' => '0']), 'S19', 'content "" ≠ "0"');
T::ok(snap(zoneRow(['prefix_length' => null]))->contentHash() !== snap(zoneRow(['prefix_length' => '0']))->contentHash(), 'S20', 'prefix_length NULL ≠ 0');
T::ok($h(['type' => 'a']) !== $h(['type' => 'A']), 'S21', 'Typ-Schreibweise bleibt erhalten');
T::ok($h(['name' => 'www ']) !== $h(['name' => 'www']), 'S22', 'kein Trimmen');
T::ok($h(['name' => 'WWW']) !== $h(['name' => 'www']), 'S23', 'Groß-/Kleinschreibung bleibt erhalten');
T::ok(snap(null, null, array_slice(records(), 0, 3))->contentHash() !== $base->contentHash(), 'S24', 'Duplikat zählt (Multimenge)');
T::ok(str_contains($base->payload(), 'ä/ü'), 'S25', 'UTF-8 und "/" unmaskiert');

// Strenge Typprüfung
foreach (['12a', '1.0', ' 1', '01', '+1', '-0', '', '99999999999999999999'] as $bad) {
    T::throws(InvalidArgumentException::class, static fn () => snap(zoneRow(['soa_refresh' => $bad])), 'S26', "Ganzzahl '$bad' abgelehnt");
}
foreach ([1.5, true, []] as $bad) {
    T::throws(InvalidArgumentException::class, static fn () => snap(zoneRow(['soa_retry' => $bad])), 'S27', 'Ganzzahl vom Typ ' . gettype($bad) . ' abgelehnt');
}
T::eq(snap(zoneRow(['soa_minimum' => '-5']))->contentHash(), snap(zoneRow(['soa_minimum' => -5]))->contentHash(), 'S28', 'negative Ganzzahl als Ziffernfolge zulässig');
T::throws(InvalidArgumentException::class, static fn () => snap(zoneRow(['soa_refresh' => null])), 'S29', 'NULL in NOT-NULL-Zonenfeld abgelehnt');
T::throws(InvalidArgumentException::class, static fn () => snap(zoneRow(['name' => 5])), 'S30', 'Zahl als Zonenname abgelehnt');
$z = zoneRow();
unset($z['soa_mail']);
T::throws(InvalidArgumentException::class, static fn () => snap($z), 'S31', 'fehlendes Zonenfeld abgelehnt');
$r = records();
unset($r[1]['server_id']);
T::throws(InvalidArgumentException::class, static fn () => snap(null, null, $r), 'S32', 'fehlendes Recordfeld abgelehnt');
T::throws(InvalidArgumentException::class, static fn () => snap(null, [['server_id' => '1', 'is_master' => '1'], ['server_id' => '1', 'is_master' => '0']]), 'S33', 'doppelte Serverzuweisung abgelehnt');
T::throws(InvalidArgumentException::class, static fn () => snap(null, [['server_id' => '1', 'is_master' => '2']]), 'S34', 'is_master ≠ 0/1 abgelehnt');
$r = records();
$r[0]['content'] = "ung\xC3ltig";
T::throws(JsonException::class, static fn () => snap(null, null, $r), 'S35', 'ungültiges UTF-8 abgelehnt');

// Leere Zone
$empty = ZoneStateSnapshot::fromRows(zoneRow(), [], []);
T::ok(str_ends_with($empty->payload(), '"servers":[],"records":[]}'), 'S36', 'leere Listen als []');

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('ZoneStateSnapshot – fromPayload');
/* ---------------------------------------------------------------------------------------------------------------- */

$round = ZoneStateSnapshot::fromPayload($base->payload());
T::eq($base->contentHash(), $round->contentHash(), 'P01', 'Payload → Snapshot → gleicher Hash');
T::eq($base->data(), $round->data(), 'P02', 'Payload → Snapshot → gleiche Daten');
T::eq($empty->contentHash(), ZoneStateSnapshot::fromPayload($empty->payload())->contentHash(), 'P03', 'leere Zone rundlauffähig');
$bad = [
    'Leerraum'               => str_replace('"zone":{', '"zone": {', VECTOR_PAYLOAD),
    'Schlüsselreihenfolge'   => str_replace('"name":"example.test","type":"forward"', '"type":"forward","name":"example.test"', VECTOR_PAYLOAD),
    'unsortierte Records'    => str_replace('["@","NS","ns1.example.test.",null,1],["txt","TXT","\"v=spf1 -all\" ä/ü",300,null]', '["txt","TXT","\"v=spf1 -all\" ä/ü",300,null],["@","NS","ns1.example.test.",null,1]', VECTOR_PAYLOAD),
    'maskierter Schrägstrich' => str_replace('ä/ü', 'ä\/ü', VECTOR_PAYLOAD),
    'maskiertes Unicode'     => str_replace('ä', chr(92) . 'u00e4', VECTOR_PAYLOAD),
    'fremder Vertrag'        => str_replace('"zs1"', '"zs2"', VECTOR_PAYLOAD),
    'Zahl als Zeichenkette'  => str_replace('"ttl":3600,"prefix', '"ttl":"3600","prefix', VECTOR_PAYLOAD),
    'zusätzlicher Schlüssel' => str_replace('{"contract":"zs1",', '{"contract":"zs1","x":1,', VECTOR_PAYLOAD),
    'kein JSON'              => 'kein json',
    'Fließkommazahl'         => str_replace('"soa_retry":1800', '"soa_retry":1800.0', VECTOR_PAYLOAD),
];
foreach ($bad as $label => $payload) {
    T::throws(InvalidArgumentException::class, static fn () => ZoneStateSnapshot::fromPayload($payload), 'P04', "nicht kanonisch abgelehnt: $label");
}

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('ZoneSnapshotDiff');
/* ---------------------------------------------------------------------------------------------------------------- */

$d = ZoneSnapshotDiff::between($base, snap(zoneRow(['soa_serial' => '1', 'changed' => '0']), array_reverse(assignments()), array_reverse(records())));
T::ok(ZoneSnapshotDiff::isEmpty($d), 'D01', 'gleicher Inhalt (andere Serial, Reihenfolge) ⇒ kein Unterschied');

$d = ZoneSnapshotDiff::between(null, $base);
T::eq(10, count($d['zone']), 'D02', 'Anlage: alle Zonenfelder mit old = null');
T::eq(null, $d['zone']['soa_ns']['old'], 'D02b', 'Anlage: old = null');
T::eq(4, count($d['records']['added']), 'D03', 'Anlage: alle Records hinzugefügt (inkl. Duplikat)');
T::eq([[1, 1], [2, 0]], $d['servers']['added'], 'D04', 'Anlage: Server hinzugefügt, aufsteigend');

$r = records();
$r[] = ['name' => 'mail', 'type' => 'MX', 'content' => '10 mx.example.test.', 'ttl' => null, 'server_id' => null];
unset($r[2]);
$d = ZoneSnapshotDiff::between($base, snap(null, null, $r));
T::eq([['mail', 'MX', '10 mx.example.test.', null, null]], $d['records']['added'], 'D05', 'Record hinzugefügt');
T::eq([['txt', 'TXT', '"v=spf1 -all" ä/ü', 300, null]], $d['records']['removed'], 'D06', 'Record entfernt');
T::eq([], $d['records']['changed'], 'D07', 'keine Änderung bei Add/Remove');

$r = records();
$r[2]['ttl'] = '600';
$d = ZoneSnapshotDiff::between($base, snap(null, null, $r));
T::eq([['key' => ['txt', 'TXT', '"v=spf1 -all" ä/ü'], 'old' => [300, null], 'new' => [600, null]]], $d['records']['changed'], 'D08', 'TTL-Änderung als changed über Inhaltsschlüssel');
T::eq([], $d['records']['added'], 'D08b', 'TTL-Änderung: nichts hinzugefügt');

$r = records();
$r[1]['server_id'] = '2';
$d = ZoneSnapshotDiff::between($base, snap(null, null, $r));
T::eq([['key' => ['@', 'NS', 'ns1.example.test.'], 'old' => [null, 1], 'new' => [null, 2]]], $d['records']['changed'], 'D09', 'Serverbezug-Änderung als changed');

// Multimenge
$r = records();
unset($r[3]);
$d = ZoneSnapshotDiff::between($base, snap(null, null, $r));
T::eq([['www', 'A', '192.0.2.10', 3600, null]], $d['records']['removed'], 'D10', 'Duplikat entfernt: genau ein Eintrag removed');
T::eq([], $d['records']['changed'], 'D10b', 'Duplikat entfernt: keine Änderung');
$r = records();
$r[0]['ttl'] = '60';
$r[3]['ttl'] = '7200';
$d = ZoneSnapshotDiff::between($base, snap(null, null, $r));
T::eq(2, count($d['records']['changed']), 'D11', 'zwei Duplikate mit neuen TTLs: zwei Änderungen');
T::eq([[3600, null], [3600, null]], array_column($d['records']['changed'], 'old'), 'D11b', 'alte Werte');
T::eq([[60, null], [7200, null]], array_column($d['records']['changed'], 'new'), 'D11c', 'neue Werte paarweise in kanonischer Reihenfolge');
$r = records();
$r[0]['ttl'] = '60';
$d = ZoneSnapshotDiff::between($base, snap(null, null, $r));
T::eq([['key' => ['www', 'A', '192.0.2.10'], 'old' => [3600, null], 'new' => [60, null]]], $d['records']['changed'], 'D12', 'ein Duplikat geändert: unverändertes bleibt zugeordnet');

// Zonenfelder und Server
$d = ZoneSnapshotDiff::between($base, snap(zoneRow(['soa_mail' => 'admin.example.test.'])));
T::eq(['soa_mail' => ['old' => 'hostmaster.example.test.', 'new' => 'admin.example.test.']], $d['zone'], 'D13', 'nur geändertes Zonenfeld');
$d = ZoneSnapshotDiff::between($base, snap(null, [['server_id' => '1', 'is_master' => '0'], ['server_id' => '3', 'is_master' => '1']]));
T::eq([[3, 1]], $d['servers']['added'], 'D14', 'Server hinzugefügt');
T::eq([[2, 0]], $d['servers']['removed'], 'D15', 'Server entfernt');
T::eq([['server_id' => 1, 'old' => 1, 'new' => 0]], $d['servers']['changed'], 'D16', 'Master-Kennzeichen geändert');

// Schlüssel byte-genau und kollisionsfrei
$a = ZoneStateSnapshot::fromRows(zoneRow(), [], [['name' => 'a', 'type' => 'b|c', 'content' => 'd', 'ttl' => null, 'server_id' => null]]);
$b = ZoneStateSnapshot::fromRows(zoneRow(), [], [['name' => 'a|b', 'type' => 'c', 'content' => 'd', 'ttl' => null, 'server_id' => null]]);
$d = ZoneSnapshotDiff::between($a, $b);
T::eq([1, 1, 0], [count($d['records']['added']), count($d['records']['removed']), count($d['records']['changed'])], 'D17', 'Trennzeichen im Inhalt erzeugen keine Schlüsselkollision');

// Zusammenfassung ohne Inhalte
$r = records();
$r[2]['ttl'] = '600';
$r[] = ['name' => 'geheim-inhalt', 'type' => 'TXT', 'content' => 'inhalt', 'ttl' => null, 'server_id' => null];
$sum = ZoneSnapshotDiff::summary(ZoneSnapshotDiff::between($base, snap(zoneRow(['soa_ns' => 'x.']), null, $r)));
T::eq(['zone_fields' => ['soa_ns'], 'servers' => ['added' => 0, 'removed' => 0, 'changed' => 0], 'records' => ['added' => 1, 'removed' => 0, 'changed' => 1]], $sum, 'D18', 'Zusammenfassung: Feldnamen und Zähler');
T::ok(!str_contains(json_encode($sum), 'geheim'), 'D19', 'Zusammenfassung ohne Record-Inhalte');

// Determinismus unabhängig von der Eingabereihenfolge
$r1 = records();
$r1[0]['ttl'] = '60';
$r2 = array_reverse($r1);
T::eq(ZoneSnapshotDiff::between($base, snap(null, null, $r1)), ZoneSnapshotDiff::between(snap(null, null, array_reverse(records())), snap(null, null, $r2)), 'D20', 'Diff deterministisch');

/* ---------------------------------------------------------------------------------------------------------------- */
T::section('SchemaCheck – Strukturbewertung (rein)');
/* ---------------------------------------------------------------------------------------------------------------- */

$rows = [];
foreach (SchemaCheck::HISTORY_TABLES as $t => $lines) {
    foreach ($lines as $l) {
        $rows[] = [$t, $l];
    }
}
$all = static fn (array $res): array => array_map(static fn (array $x): string => $x['state'], $res);
T::eq(['history_change_sets' => 'ok', 'zone_snapshots' => 'ok', 'zone_revisions' => 'ok', 'zone_history_state' => 'ok', 'history_dependencies' => 'ok'], $all(SchemaCheck::evaluateHistory($rows)), 'C01', 'Erwartung ⇒ alle ok');
T::eq(['history_change_sets' => 'absent', 'zone_snapshots' => 'absent', 'zone_revisions' => 'absent', 'zone_history_state' => 'absent', 'history_dependencies' => 'absent'], $all(SchemaCheck::evaluateHistory([])), 'C02', 'leer ⇒ alle absent');
$mut = $rows;
$mut[] = ['zone_snapshots', 'idx|idx_extra|1|1|contract|~|BTREE'];
$res = SchemaCheck::evaluateHistory($mut);
T::eq('mismatch', $res['zone_snapshots']['state'], 'C03', 'zusätzliche Zeile ⇒ mismatch');
T::ok(str_contains($res['zone_snapshots']['detail'], 'idx_extra'), 'C03b', 'Detail nennt die Abweichung');
T::eq('ok', $res['zone_revisions']['state'], 'C03c', 'andere Tabellen unberührt');
$mut = array_values(array_filter($rows, static fn (array $r): bool => !str_starts_with($r[1], 'chk|chk_zs_payload|')));
T::eq('mismatch', SchemaCheck::evaluateHistory($mut)['zone_snapshots']['state'], 'C04', 'fehlende Zeile ⇒ mismatch');
$mut = array_map(static fn (array $r): array => [$r[0], str_replace('int(10) unsigned', 'int(11)', $r[1])], $rows);
T::eq('mismatch', SchemaCheck::evaluateHistory($mut)['zone_revisions']['state'], 'C05', 'geänderter Typ ⇒ mismatch');
$mut = array_map(static fn (array $r): array => [$r[0], str_starts_with($r[1], 'chk|chk_zr_revision_no|') ? 'chk|chk_zr_revision_no|Table|`revision_no` >= 0' : $r[1]], $rows);
$res = SchemaCheck::evaluateHistory($mut);
T::eq('mismatch', $res['zone_revisions']['state'], 'C05b', 'geänderter CHECK-Ausdruck bei gleichem Namen ⇒ mismatch (S1)');
T::ok(str_contains($res['zone_revisions']['detail'], '`revision_no` >= 0'), 'C05c', 'Detail nennt den abweichenden Ausdruck');
$mut = [['zone_history_state', 'table|VIEW|~']];
T::eq('mismatch', SchemaCheck::evaluateHistory($mut)['zone_history_state']['state'], 'C06', 'Sicht statt Tabelle ⇒ mismatch');
$sql = SchemaCheck::historySignatureSql();
T::ok(!str_contains($sql, '{TABLES}') && substr_count($sql, "'zone_history_state'") === 5, 'C07', 'Signaturabfrage mit festen Tabellennamen');

T::finish('pure');
