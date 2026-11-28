<?php
/**
 * Datei: tests/history/as5_pure_test.php
 * Zweck: Reine Tests der AS5-Serial-Strategie (docs/ARCHITECTURE_HISTORY.md Abschnitt 5k) ohne Datenbank:
 *   R  RFC-1982-Vektoren (tests/rfc1982_vectors.json, rfc1982-v1) gegen SerialNumber (E110; dieselben Vektoren wie der Agent)
 *   V  Policy-Vektoren (tests/serial_policy_vectors.json, serial-policy-v1): Kalenderkandidat, Wrap, 0 gültig, Grenzen
 *   B  Brute-Force im reduzierten Modell: 8 Bit vollständig für |R| ≤ 2, 6 Bit vollständig für |R| = 3, 8 Bit zufällig
 *      für |R| = 3 … 6 – Ergebnis = einzige Lösung laut Vollsuche (K bevorzugt, sonst kleinster Nachfolger), unauflösbar
 *      genau dann, wenn keine Lösung existiert; Eindeutigkeit des Nachfolgerkandidaten
 *   M  Referenzmodell (SerialReferenceReader::compute): S1–S5, D18 (angenommener Legacy-Zustand), getrennte Ablösung von
 *      F- und D-Konflikten, Rohwerte aus unknown, R1 bei gescheitertem Reload, ungeordnete Werte (D16/D23), Admin (D17),
 *      Fall M1/M2/M3 (300 bleibt), Fall Monitoring 100 → Publish 200 → Monitoring 100 (zweite Messung bleibt Anker)
 *   E  Ereignisabbildung einzelner Quellzeilen (Journal, Referenzen) inkl. not_auth nur bei K = nein
 *   P  Parser serial_check (AgentResponseParser::serialCheck, R3-Strenge)
 *   A  AuditPolicy-Fakten der administrativen Referenz; Sperrauflösung nur mit F und D nach dem Ereignis
 *   C  SerialPolicy-Sperren und Invarianten (leere Menge, Kalenderdatum)
 *
 * Aufruf: php as5_pure_test.php <App-Verzeichnis>
 */

$app = rtrim($argv[1] ?? '', '/');
if (!is_dir("$app/web/inc/app/publish")) {
    fwrite(STDERR, "Aufruf: php as5_pure_test.php <App-Verzeichnis>\n");
    exit(2);
}
foreach (['SerialNumber', 'SerialPolicy', 'SerialReferenceStore', 'SerialReferenceReader', 'AgentResponseParser', 'PublishTargetClassifier'] as $c) {
    require_once "$app/web/inc/app/publish/$c.php";
}
require_once "$app/web/inc/app/history/AuditPolicy.php";

$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $id . ($detail !== '' && !$ok ? ' – ' . $detail : '') . "\n";
}
echo "AS5 reine Tests\n";

// ---------------------------------------------------------------- R: RFC-1982-Vektoren
$v = json_decode((string)file_get_contents("$app/tests/rfc1982_vectors.json"), true);
$bad = [];
foreach ($v['parse'] as $i => $p) {
    if (SerialNumber::parse($p['in']) !== $p['out']) {
        $bad[] = "parse#$i";
    }
}
foreach ($v['compare'] as $i => $c) {
    if (SerialNumber::compare($c['a'], $c['b']) !== $c['result']) {
        $bad[] = "compare#$i";
    }
}
foreach ($v['compare_invalid'] as $i => [$a, $b]) {
    try {
        SerialNumber::compare($a, $b);
        $bad[] = "invalid#$i";
    } catch (InvalidArgumentException $e) {
    }
}
t('R01 alle Vektoren rfc1982-v1 (' . count($v['parse']) . ' parse, ' . count($v['compare']) . ' compare, ' . count($v['compare_invalid']) . ' ungültig)',
    $v['contract'] === 'rfc1982-v1' && $bad === [], implode(',', $bad));
t('R02 Abstand genau 2^31 ist undefiniert (beide Richtungen), 0 ist gültig', SerialNumber::compare(0, 2147483648) === 'undefined'
    && SerialNumber::compare(2147483648, 0) === 'undefined' && SerialNumber::parse(0) === 0 && SerialNumber::parse('0') === 0);

// ---------------------------------------------------------------- V: Policy-Vektoren
$pv = json_decode((string)file_get_contents("$app/tests/serial_policy_vectors.json"), true);
$bad = [];
foreach ($pv['vectors'] as $x) {
    try {
        $d = SerialPolicy::decide($x['refs'], $x['calendar']);
    } catch (LogicException $e) {
        $bad[] = $x['id'] . ' Ausnahme: ' . $e->getMessage();
        continue;
    }
    $ok = isset($x['reason']) ? (!$d['ok'] && $d['reason'] === $x['reason'])
        : ($d['ok'] && $d['serial'] === $x['serial'] && $d['basis'] === $x['basis'] && SerialNumber::newerThanAll($d['serial'], $x['refs']));
    if (!$ok) {
        $bad[] = $x['id'] . '=' . json_encode($d);
    }
}
t('V01 alle Vektoren serial-policy-v1 (' . count($pv['vectors']) . ')', $pv['contract'] === 'serial-policy-v1' && $bad === [], implode(' ', $bad));
t('V02 Gegenbeispiel Review: R = {2147483649, 4294967295} ⇒ 0; 1 ist gegenüber 2147483649 undefiniert',
    SerialPolicy::decide([2147483649, 4294967295], null)['serial'] === 0 && SerialNumber::compare(1, 2147483649) === 'undefined');
t('V03 Kalenderkandidat aus dem Datum', SerialPolicy::calendarCandidate('20261125') === 2026112501
    && SerialPolicy::calendarCandidate('20261231') === 2026123101);
$e = 0;
foreach (['2026113', '20261131', 'abcdefgh', '99991232'] as $d) {
    try {
        SerialPolicy::calendarCandidate($d);
    } catch (InvalidArgumentException $x) {
        $e++;
    }
}
t('V04 ungültiges Kalenderdatum abgewiesen', $e === 4);
// Rückwärtskompatibilität: R = {c} wie generate_next_serial() für c ∈ [0, K + 2^31]
$old = static fn (int $c, int $k): int => $c < $k - 1 ? $k : $c + 1;   // bisherige Regel (K = Ymd·100 + 1)
$bad = [];
$K = 2026112501;
foreach ([0, 1, 5, 2026112400, $K - 2, $K - 1, $K, $K + 1, 2147483647, 2147483648, 3000000000, $K + 2147483647, $K + 2147483648] as $c) {
    $d = SerialPolicy::decide([$c], $K);
    if (!$d['ok'] || $d['serial'] !== $old($c, $K)) {
        $bad[] = "$c";
    }
}
for ($i = 0; $i < 20000; $i++) {
    $c = random_int(0, $K + 2147483648);
    if (SerialPolicy::decide([$c], $K)['serial'] !== $old($c, $K)) {
        $bad[] = "$c";
        break;
    }
}
t('V05 R = {c}: identisch mit der bisherigen Regel für c ∈ [0, K + 2^31] (Stichproben und Grenzen)', $bad === [], implode(',', $bad));
$d = SerialPolicy::decide([4294967295], $K);
t('V06 c = 4294967295: bisher Abbruch, jetzt RFC-konformer Wrap auf K', $d['ok'] && $d['serial'] === $K && $d['basis'] === 'calendar');

// ---------------------------------------------------------------- B: Brute-Force im reduzierten Modell
$brute = static function (array $refs, ?int $k, int $bits): array {
    $max = SerialNumber::mask($bits);
    $sol = [];
    for ($n = 0; $n <= $max; $n++) {
        if (SerialNumber::newerThanAll($n, $refs, $bits)) {
            $sol[] = $n;
        }
    }
    return $sol;
};
$check = static function (array $refs, ?int $k, int $bits) use ($brute): ?string {
    $sol = $brute($refs, $k, $bits);
    $d = SerialPolicy::decide($refs, $k, [], $bits);
    if ($sol === []) {
        return $d['ok'] ? 'Lösung ohne Existenz' : null;
    }
    if (!$d['ok']) {
        return 'unauflösbar trotz Lösung';
    }
    if ($k !== null && in_array($k, $sol, true)) {
        return $d['serial'] === $k && $d['basis'] === 'calendar' ? null : 'K nicht bevorzugt';
    }
    // kleinster Nachfolger: einzige Lösung n mit (n − 1) mod 2^b ∈ R
    $max = SerialNumber::mask($bits);
    $succ = array_values(array_filter($sol, static fn (int $n): bool => in_array(($n - 1) & $max, $refs, true)));
    if (count($succ) !== 1) {
        return 'Nachfolgerkandidat nicht eindeutig (' . count($succ) . ')';
    }
    return $d['serial'] === $succ[0] && $d['basis'] === 'increment' ? null : 'falscher Nachfolger';
};
$err = null;
$n8 = 0;
for ($a = 0; $a < 256 && $err === null; $a++) {
    foreach ([null, 0, 128, 200] as $k) {
        $n8++;
        $err ??= ($r = $check([$a], $k, 8)) !== null ? "[$a] K=$k: $r" : null;
    }
    for ($b = $a + 1; $b < 256 && $err === null; $b++) {
        foreach ([null, ($a + $b) & 255] as $k) {
            $n8++;
            $err ??= ($r = $check([$a, $b], $k, 8)) !== null ? "[$a,$b] K=$k: $r" : null;
        }
    }
}
t("B01 8 Bit vollständig |R| ≤ 2 mit Kalenderkandidaten ($n8 Fälle)", $err === null, (string)$err);
$err = null;
$n6 = 0;
for ($a = 0; $a < 64 && $err === null; $a++) {
    for ($b = $a + 1; $b < 64 && $err === null; $b++) {
        for ($c = $b + 1; $c < 64 && $err === null; $c++) {
            foreach ([null, ($a * 7 + $c) & 63] as $k) {
                $n6++;
                $err ??= ($r = $check([$a, $b, $c], $k, 6)) !== null ? "[$a,$b,$c] K=$k: $r" : null;
            }
        }
    }
}
t("B02 6 Bit vollständig |R| = 3 ($n6 Fälle)", $err === null, (string)$err);
$err = null;
for ($i = 0; $i < 30000 && $err === null; $i++) {
    $size = random_int(3, 6);
    $base = random_int(0, 255);
    $spread = [8, 64, 127, 128, 200, 255][random_int(0, 5)];
    $refs = [];
    for ($j = 0; $j < $size; $j++) {
        $refs[] = ($base + random_int(0, $spread)) & 255;
    }
    $k = random_int(0, 3) === 0 ? null : random_int(0, 255);
    $err = ($r = $check($refs, $k, 8)) !== null ? json_encode($refs) . " K=$k: $r" : null;
}
t('B03 8 Bit zufällig |R| = 3 … 6 (30000 Fälle)', $err === null, (string)$err);

// ---------------------------------------------------------------- M: Referenzmodell
$ev = static fn (string $c, string $kind, array $v, array $key, string $at = '') => ['comp' => $c, 'kind' => $kind, 'values' => $v, 'key' => $key, 'at' => $at];
$vals = static function (array $events, string $c): array {
    $r = SerialReferenceReader::compute($events)[$c]['values'];
    sort($r);
    return $r;
};
// M1/M2/M3: verified 100/100, serial_differs 100/300, unknown ohne Werte
$m = [$ev('F', 'anchor', [100], [5, 5, 1]), $ev('D', 'anchor', [100], [5, 5, 1]), $ev('F', 'anchor', [100], [6, 5, 2]),
    $ev('D', 'anchor', [300], [6, 5, 2])];
t('M01 Fall M1/M2/M3: F {100}, D {300}; unknown ohne Werte löst nichts ab', $vals($m, 'F') === [100] && $vals($m, 'D') === [300]);
// Monitoring 100 (W5) → Publish 200 (Lauf 6, as2 ok, D möglich) → Monitoring 100 (W6)
$m = [$ev('D', 'anchor', [100], [5, 5, 1]), $ev('F', 'anchor', [200], [6, 2, 10]), $ev('D', 'possible', [200], [6, 2, 10]),
    $ev('D', 'anchor', [100], [6, 5, 2]), $ev('F', 'anchor', [100], [6, 5, 2])];
t('M02 Monitoring 100 → Publish 200 → Monitoring 100: zweite Messung bleibt Anker, löst die neuere 200 aber nicht ab (RFC)', $vals($m, 'D') === [100, 200] && $vals($m, 'F') === [100, 200]);
$m2 = array_slice($m, 0, 3);
t('M03 ohne zweite Messung bleibt der mögliche Wert 200 neben dem Anker 100', $vals($m2, 'D') === [100, 200]);
// S2: späterer Anker löst possible/additive ab, früherer nicht
$m = [$ev('D', 'possible', [50], [3, 2, 1]), $ev('D', 'additive', [60], [3, 5, 2]), $ev('D', 'anchor', [70], [4, 5, 3]), $ev('D', 'possible', [80], [5, 2, 4])];
t('M04 S1/S2: Anker 70 löst frühere possible/additive ab, spätere bleiben', $vals($m, 'D') === [70, 80]);
// S3: Konflikt nur durch späteren Anker mit neuerem Wert abgelöst, getrennt je Bestandteil
$m = [$ev('D', 'conflict', [300], [5, 1, 1]), $ev('F', 'conflict', [250], [5, 1, 1]), $ev('D', 'anchor', [200], [5, 3, 2]),
    $ev('F', 'anchor', [400], [5, 3, 2])];
t('M05 S3 je Bestandteil: D-Konflikt 300 bleibt (Anker 200 nicht neuer), F-Konflikt 250 abgelöst (Anker 400 neuer)',
    $vals($m, 'D') === [200, 300] && $vals($m, 'F') === [400]);
$m = [$ev('D', 'conflict', [300], [5, 1, 1]), $ev('D', 'anchor', [400], [4, 5, 1])];
t('M06 S3: früherer Anker mit neuerem Wert löst nicht ab', $vals($m, 'D') === [300, 400]);
// D18: assumed
$m = [$ev('D', 'anchor', [300], [5, 5, 1]), $ev('D', 'assumed', [301], [6, 2, 1]), $ev('D', 'assumed', [302], [7, 2, 2]), $ev('D', 'possible', [299], [6, 2, 1])];
t('M07 D18: assumed löst nur frühere assumed ab; gemessener Anker und mögliche Werte bleiben', $vals($m, 'D') === [299, 300, 302]);
$m[] = $ev('D', 'anchor', [302], [7, 5, 3]);
t('M08 D18: späterer gemessener Anker löst assumed ab', $vals($m, 'D') === [302]);
$m = [$ev('D', 'assumed', [10], [2, 2, 1]), $ev('D', 'anchor', [5], [1, 5, 1])];
t('M09 D18: assumed nach dem letzten Anker bleibt neben ihm', $vals($m, 'D') === [5, 10]);
// D16/D23: ungeordnete Werte
$m = [$ev('D', 'uncertain', [500], [9, 1, 1], '2026-11-27 10:00:00'), $ev('D', 'anchor', [600], [20, 5, 1], '2026-11-27 09:00:00')];
t('M10 D16: ungeordneter Wert bleibt, wenn der Anker vorher aufgezeichnet wurde (Ordnung unbekannt)', $vals($m, 'D') === [500, 600]);
$m = [$ev('D', 'uncertain', [500], [9, 1, 1], '2026-11-27 10:00:00'), $ev('D', 'anchor', [600], [5, 5, 1], '2026-11-27 11:00:00')];
t('M11 D16: ungeordneter Wert wird nur durch später aufgezeichneten Anker mit neuerem Wert abgelöst – nie durch die Ordnung', $vals($m, 'D') === [600]);
$m = [$ev('D', 'uncertain', [500], [9, 1, 1], '2026-11-27 10:00:00'), $ev('D', 'anchor', [400], [99, 5, 1], '2026-11-27 11:00:00')];
t('M12 D16: späterer Anker mit älterem Wert löst nicht ab', $vals($m, 'D') === [400, 500]);
$m = [$ev('D', 'uncertain', [500], [99, 1, 1]), $ev('D', 'possible', [10], [1, 2, 1])];
t('M13 S4: ungeordnetes Ereignis löst nichts ab', $vals($m, 'D') === [10, 500]);
// Admin
$m = [$ev('F', 'admin', [700], [3, 6, 1]), $ev('F', 'anchor', [650], [4, 5, 1]), $ev('D', 'admin', [700], [3, 6, 1]), $ev('D', 'anchor', [800], [4, 5, 1])];
t('M14 D17: Admin-Referenz ist kein Anker; Ablösung nur nach S3 (F bleibt, D abgelöst)', $vals($m, 'F') === [650, 700] && $vals($m, 'D') === [800]);
// R1 bei gescheitertem Reload: F-Anker, D bleibt
$j = SerialReferenceReader::journalEvents(['soa_serial' => 42, 'run_id' => 7, 'attempt_id' => 3, 'zone_sync' => 'unknown', 'zone_sync_at' => 'x',
    'zone_protocol' => 'as2', 'zone_installed_serial' => 42, 'conf_verify_state' => null]);
$m = array_merge([$ev('D', 'anchor', [40], [6, 5, 1]), $ev('F', 'anchor', [40], [6, 5, 1])], $j);
t('M15 R1 bei gescheitertem Reload: F-Anker {42}, D behält Anker 40 und möglich 42', $vals($m, 'F') === [42] && $vals($m, 'D') === [40, 42]);
// Rohwerte aus unknown additiv
$m = [$ev('D', 'anchor', [10], [1, 5, 1]), $ev('D', 'additive', [77], [2, 5, 1]), $ev('F', 'additive', [76], [2, 5, 1])];
t('M16 D21: gültige Rohwerte aus unknown bleiben additiv bindend', $vals($m, 'D') === [10, 77] && $vals($m, 'F') === [76]);
t('M17 leere Ereignismenge ⇒ keine Paarreferenz', SerialReferenceReader::compute([])['F']['values'] === []);
// Review AS5 Punkt 1: Ablösung nur durch einen späteren Anker mit nach RFC 1982 neuerem Wert, F und D unabhängig
$m = [$ev('F', 'anchor', [4000000000], [1, 5, 1]), $ev('D', 'anchor', [4000000000], [1, 5, 1]),
    $ev('F', 'anchor', [3000000000], [2, 5, 2]), $ev('D', 'anchor', [3000000000], [2, 5, 2])];
t('M18 F/D 4.000.000.000 → späterer Anker 3.000.000.000 (older): beide bleiben bindend', $vals($m, 'F') === [3000000000, 4000000000]
    && $vals($m, 'D') === [3000000000, 4000000000]);
$d = SerialPolicy::decide(array_merge($vals($m, 'F'), [3000000000]), null);
t('M18b Policy: neue Serial gegenüber beiden bindenden Werten newer', $d['ok'] && SerialNumber::newerThanAll($d['serial'], [3000000000, 4000000000]) && $d['serial'] === 4000000001);
$m = [$ev('D', 'anchor', [4294967295], [1, 5, 1]), $ev('D', 'anchor', [0], [2, 5, 2]), $ev('F', 'anchor', [4294967295], [1, 5, 1])];
t('M19 RFC-Wrap 4.294.967.295 → späterer Anker 0 (newer): abgelöst; F unabhängig', $vals($m, 'D') === [0] && $vals($m, 'F') === [4294967295]);
$m = [$ev('D', 'anchor', [0], [1, 5, 1]), $ev('D', 'anchor', [2147483648], [2, 5, 2])];
$d = SerialPolicy::decide($vals($m, 'D'), null);
t('M20 Abstand genau 2^31 (undefined): beide bleiben bindend ⇒ serial_unresolvable', $vals($m, 'D') === [0, 2147483648] && !$d['ok'] && $d['reason'] === 'serial_unresolvable');
$m = [$ev('D', 'possible', [4000000000], [1, 2, 1]), $ev('D', 'anchor', [3000000000], [2, 5, 2])];
t('M21 possible 4.000.000.000 → späterer Anker 3.000.000.000: beide bleiben', $vals($m, 'D') === [3000000000, 4000000000]);
$m = [$ev('D', 'additive', [4000000000], [1, 5, 1]), $ev('D', 'anchor', [3000000000], [2, 5, 2])];
t('M22 unknown-Rohwert 4.000.000.000 → späterer Anker 3.000.000.000: beide bleiben', $vals($m, 'D') === [3000000000, 4000000000]);
$m = [$ev('D', 'anchor', [300], [1, 5, 1]), $ev('D', 'possible', [301], [2, 2, 1]), $ev('D', 'anchor', [], [3, 4, 2])];
t('M23 leerer Anker (absent) löscht keine numerischen Referenzen', $vals($m, 'D') === [300, 301]);
$m = [$ev('D', 'anchor', [100], [1, 5, 1]), $ev('D', 'anchor', [100], [2, 5, 2])];
t('M24 equal: gleicher Wert bleibt ohne zusätzliche Referenzwirkung', $vals($m, 'D') === [100]);
$m = [$ev('D', 'assumed', [4000000000], [1, 2, 1]), $ev('D', 'anchor', [3000000000], [2, 5, 2]), $ev('D', 'assumed', [3500000000], [3, 2, 3])];
t('M25 assumed 4.000.000.000 bleibt gegenüber späterem älterem Anker und älterer Annahme', $vals($m, 'D') === [3000000000, 3500000000, 4000000000]);
$m = [$ev('D', 'uncertain', [4000000000], [9, 1, 1], '2026-11-27 10:00:00'), $ev('D', 'anchor', [3000000000], [10, 5, 1], '2026-11-27 11:00:00')];
t('M26 ungeordneter Wert 4.000.000.000 bleibt gegenüber später aufgezeichnetem älterem Anker', $vals($m, 'D') === [3000000000, 4000000000]);

// ---------------------------------------------------------------- E: Ereignisabbildung
$base = ['id' => 1, 'run_key' => 5, 'order_certain' => 1, 'recorded_at' => 'x', 'file_serial' => null, 'served_serial' => null, 'file_state' => null,
    'served_state' => null, 'config_state' => null, 'decision' => null, 'code' => null, 'obs_state' => null];
$k = static fn (array $evs): array => array_map(static fn (array $e): string => $e['comp'] . ':' . $e['kind'] . ':' . implode('/', $e['values']), $evs);
$r = SerialReferenceReader::referenceEvents(['source' => 'agent_check', 'file_state' => 'valid', 'file_serial' => 300, 'served_state' => 'not_auth',
    'config_state' => 'undeterminable', 'decision' => 'accept'] + $base);
t('E01 not_auth ohne K = nein ist kein Entfernungsnachweis', $k($r) === ['F:anchor:300']);
$r = SerialReferenceReader::referenceEvents(['source' => 'agent_check', 'file_state' => 'missing', 'served_state' => 'not_auth',
    'config_state' => 'no', 'decision' => 'accept'] + $base);
t('E02 not_auth mit K = nein: D-Anker ∅; fehlende Datei: F-Anker ∅', $k($r) === ['F:anchor:', 'D:anchor:']);
$r = SerialReferenceReader::referenceEvents(['source' => 'agent_check', 'file_state' => 'valid', 'file_serial' => 300, 'served_state' => 'served',
    'served_serial' => 310, 'config_state' => 'yes', 'decision' => 'would_reject', 'code' => 'stale_serial'] + $base);
t('E03 would_reject stale_serial: Anker und getrennte F-/D-Konfliktreferenzen', $k($r) === ['F:anchor:300', 'D:anchor:310', 'F:conflict:300', 'D:conflict:310']);
$r = SerialReferenceReader::referenceEvents(['source' => 'agent_check', 'file_state' => 'valid', 'file_serial' => 300, 'served_state' => 'unknown',
    'config_state' => 'yes', 'decision' => 'reject', 'code' => 'served_state_unknown'] + $base);
t('E04 Ablehnung ohne Serial-Code: kein Konflikt', $k($r) === ['F:anchor:300']);
$r = SerialReferenceReader::referenceEvents(['source' => 'agent_check', 'order_certain' => 0, 'file_state' => 'valid', 'file_serial' => 300,
    'served_state' => 'served', 'served_serial' => 310, 'config_state' => 'yes', 'decision' => 'accept'] + $base);
t('E05 verspäteter Bericht: nur ungeordnete Werte', $k($r) === ['F:uncertain:300', 'D:uncertain:310']);
$r = SerialReferenceReader::referenceEvents(['source' => 'monitoring', 'obs_state' => 'absent'] + $base);
t('E06 absent: D-Anker ∅, F unberührt', $k($r) === ['D:anchor:']);
$r = SerialReferenceReader::referenceEvents(['source' => 'monitoring', 'obs_state' => 'unknown', 'file_serial' => 9, 'served_serial' => 8] + $base);
t('E07 unknown mit Rohwerten: additiv', $k($r) === ['F:additive:9', 'D:additive:8']);
$r = SerialReferenceReader::referenceEvents(['source' => 'admin', 'served_serial' => 0] + $base);
t('E08 Admin D mit Serial 0', $k($r) === ['D:admin:0']);
foreach (['ok|legacy' => ['F:anchor:42', 'D:assumed:42'], 'ok|' => ['F:anchor:42', 'D:assumed:42'], 'failed|' => [], 'rejected|as2' => [],
    'sending|' => ['F:possible:42', 'D:possible:42'], 'abandoned|' => ['F:possible:42', 'D:possible:42'], 'unknown|' => ['F:possible:42', 'D:possible:42']] as $case => $want) {
    [$zs, $proto] = explode('|', $case);
    $r = SerialReferenceReader::journalEvents(['soa_serial' => 42, 'run_id' => 7, 'attempt_id' => 3, 'zone_sync' => $zs, 'zone_sync_at' => null,
        'zone_protocol' => $proto === '' ? null : $proto, 'zone_installed_serial' => null, 'conf_verify_state' => null]);
    t("E09 Journal zone_sync $case", $k($r) === $want, json_encode($k($r)));
}
$r = SerialReferenceReader::journalEvents(['soa_serial' => 42, 'run_id' => 7, 'attempt_id' => 3, 'zone_sync' => 'ok', 'zone_sync_at' => null,
    'zone_protocol' => 'as2', 'zone_installed_serial' => 42, 'conf_verify_state' => 'serial_differs', 'conf_sync_at' => null,
    'conf_verify_file_serial' => 42, 'conf_verify_served_serial' => 41]);
t('E10 as2 ok mit serial_differs: F-Anker, D möglich, danach Beobachtungsanker', $k($r) === ['F:anchor:42', 'D:possible:42', 'F:anchor:42', 'D:anchor:41']);

// ---------------------------------------------------------------- P: Parser serial_check
$resp = static fn (array $sc): array => ['body' => json_encode(['status' => 'error', 'effect' => 'none', 'serial_check' => $sc]), 'httpCode' => 409];
$good = ['mode' => 'enforce', 'decision' => 'reject', 'code' => 'stale_serial', 'row' => 10, 'new_serial' => 100, 'file_state' => 'valid', 'file_serial' => 120,
    'file_sha256' => str_repeat('a', 64), 'file_reason' => null, 'config_state' => 'yes', 'config_reason' => null, 'served_state' => 'served',
    'served_serial' => 120, 'served_reason' => null, 'served_rcode' => 'NOERROR', 'cmp_file' => 'older', 'cmp_served' => 'older'];
$p = AgentResponseParser::serialCheck($resp($good), 100);
t('P01 gültiger Bericht', $p['state'] === 'valid' && $p['report']['fileSerial'] === 120 && $p['report']['servedSerial'] === 120 && $p['report']['code'] === 'stale_serial');
t('P02 kein Bericht (Legacy, keine Antwort)', AgentResponseParser::serialCheck(['body' => '{"status":"success"}'], 100)['state'] === 'absent'
    && AgentResponseParser::serialCheck(null, 100)['state'] === 'absent' && AgentResponseParser::serialCheck(['body' => false], 100)['state'] === 'absent');
$cases = [
    'Feld fehlt' => array_diff_key($good, ['served_serial' => 1]),
    'new_serial weicht ab' => ['new_serial' => 101] + $good,
    'decision ungültig' => ['decision' => 'maybe'] + $good,
    'accept mit code' => ['decision' => 'accept'] + $good,
    'reject ohne code' => ['code' => null] + $good,
    'file_serial bei invalid' => ['file_state' => 'invalid'] + $good,
    'valid ohne file_serial' => ['file_serial' => null] + $good,
    'served ohne Serial' => ['served_serial' => null] + $good,
    'not_auth mit Serial' => ['served_state' => 'not_auth', 'config_state' => 'no'] + $good,
    'not_auth ohne K = nein' => ['served_state' => 'not_auth', 'served_serial' => null] + $good,
    'Serial außerhalb' => ['file_serial' => 4294967296] + $good,
    'Hash ungültig' => ['file_sha256' => 'ABC'] + $good,
    'config_state ungültig' => ['config_state' => 'vielleicht'] + $good,
    'kein Objekt' => null,
];
$bad = [];
foreach ($cases as $name => $sc) {
    $r = $sc === null ? AgentResponseParser::serialCheck(['body' => json_encode(['serial_check' => 'x'])], 100) : AgentResponseParser::serialCheck($resp($sc), 100);
    if ($r['state'] !== 'invalid') {
        $bad[] = $name;
    }
}
// Fließkommazahl im JSON-Text (json_encode würde sie je nach serialize_precision als Ganzzahl schreiben)
$floatBody = str_replace('"file_serial":120,', '"file_serial":120.0,', json_encode(['serial_check' => $good]));
if (!str_contains($floatBody, '120.0') || AgentResponseParser::serialCheck(['body' => $floatBody], 100)['state'] !== 'invalid') {
    $bad[] = 'Serial als Fließkommazahl';
}
t('P03 jede Abweichung ⇒ ungültig (R3-Strenge, ' . (count($cases) + 1) . ' Fälle)', $bad === [], implode(', ', $bad));
$p = AgentResponseParser::serialCheck($resp(['decision' => 'accept', 'code' => null, 'file_state' => 'missing', 'file_serial' => null, 'file_sha256' => null,
    'served_state' => 'not_auth', 'served_serial' => null, 'config_state' => 'no'] + $good), 100);
t('P04 Neuinstallation (Zeile 6) gültig', $p['state'] === 'valid' && $p['report']['fileState'] === 'missing' && $p['report']['servedState'] === 'not_auth');
$p = AgentResponseParser::serialCheck($resp(['new_serial' => 0, 'served_serial' => 0, 'file_serial' => 0] + $good), 0);
t('P05 Serial 0 gültig', $p['state'] === 'valid' && $p['report']['servedSerial'] === 0);

// ---------------------------------------------------------------- A: Audit-Fakten und Sperrauflösung
$c = AuditPolicy::changes('zone', 'update', [], [], [], ['serial_reference_server' => 2, 'serial_reference_served' => 0]);
t('A01 Audit-Fakten der Admin-Referenz', $c === '{"serial_reference_server":2,"serial_reference_served":0}', (string)$c);
$e = 0;
foreach ([['serial_reference_server' => 0], ['serial_reference_file' => 4294967296], ['serial_reference_file' => '5'], ['serial_reference_served' => -1]] as $f) {
    try {
        AuditPolicy::changes('zone', 'update', [], [], [], $f);
    } catch (LogicException $x) {
        $e++;
    }
}
t('A02 ungültige Fakten abgewiesen', $e === 4);
$adm = static fn (int $id, ?int $f, ?int $d, int $rk = 9, string $at = '2026-11-27 12:00:00') => ['id' => $id, 'run_key' => $rk, 'recorded_at' => $at,
    'file_serial' => $f, 'served_serial' => $d];
t('A03 Sperre aufgelöst nur mit F UND D nach dem Ereignis', !SerialReferenceReader::resolvedByAdmin(['id' => 10], [$adm(11, 5, null)])
    && SerialReferenceReader::resolvedByAdmin(['id' => 10], [$adm(11, 5, null), $adm(12, null, 5)])
    && !SerialReferenceReader::resolvedByAdmin(['id' => 10], [$adm(9, 5, null), $adm(12, null, 5)]));
t('A04 Lücke eines Laufs bzw. einer Beobachtung: Erfassung danach (Wasserstand bzw. Zeitpunkt)',
    SerialReferenceReader::resolvedByAdmin(['run_key' => 9], [$adm(1, 5, null, 9), $adm(2, null, 5, 9)])
    && !SerialReferenceReader::resolvedByAdmin(['run_key' => 10], [$adm(1, 5, null, 9), $adm(2, null, 5, 9)])
    && !SerialReferenceReader::resolvedByAdmin(['at' => '2026-11-27 13:00:00'], [$adm(1, 5, null), $adm(2, null, 5)]));

// ---------------------------------------------------------------- C: Sperren und Invarianten der Policy
$d = SerialPolicy::decide([5], 100, [['reason' => 'serial_reference_lost', 'detail' => 'x']]);
t('C01 Sperre verhindert jede Auswahl (auch bei auflösbarer Menge)', !$d['ok'] && $d['reason'] === 'serial_reference_lost');
try {
    SerialPolicy::decide([], 100);
    t('C02 leere Referenzmenge ⇒ LogicException', false);
} catch (LogicException $x) {
    t('C02 leere Referenzmenge ⇒ LogicException', true);
}
t('C03 keine numerische Sortierung: R = {4294967295, 5} ⇒ Nachfolger von 5', SerialPolicy::decide([4294967295, 5], null)['serial'] === 6);
t('C04 normName: Großbuchstaben und abschließender Punkt', SerialReferenceStore::normName('Beta.H2B.Test.') === 'beta.h2b.test'
    && SerialReferenceStore::normName('../x') === null);

echo "Ergebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
