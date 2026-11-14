<?php
/**
 * Datei: tests/ui/records/matrix.php
 * Zweck: Record-Typ-Matrix (U4-Core): misst die tatsächlich gespeicherten Ergebnisse der bestehenden Record-Actions
 *        je Typ für Anlage und Bearbeitung, ohne JS (Formular wie ausgeliefert, nur benannte Felder) und mit JS
 *        (mit capture.js aus den echten Formularskripten erfasste POST-Daten, js_posts.json).
 *        Ergebnis je Fall: Status, Weiterleitung, Toasts, gespeicherte bzw. geänderte Records, PHP-Probleme.
 * Aufruf (über run.sh matrix): php matrix.php <Ausgabedatei> [js_posts.json]
 *        php matrix.php --cases-json   gibt die Fälle für capture.js als JSON aus
 */
require __DIR__ . '/../lib.php';

$cases = require __DIR__ . '/matrix_cases.php';
if (($argv[1] ?? '') === '--cases-json') {
    echo json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}
[$_, $outFile] = $argv + [null, null];
$jsFile = $argv[2] ?? __DIR__ . '/js_posts.json';
$jsPosts = is_file($jsFile) ? (json_decode((string)file_get_contents($jsFile), true)['posts'] ?? []) : [];
if (!$outFile) { fwrite(STDERR, "Aufruf: php matrix.php <Ausgabedatei> [js_posts.json]\n"); exit(2); }

const FIXTURE_MAX_ID = 1000;
$fixtureSql = (string)file_get_contents(__DIR__ . '/matrix_fixture.sql');

/** Zurücksetzen auf die Vorlage (Golden-Fixture + Matrix-Fixture, von run.sh matrix geladen); neue IDs über der Fixture */
function matrixReset(string $sql): void
{
    resetDb();
    $n = (int)$GLOBALS['root']->query('SELECT COUNT(*) FROM `' . DB . '`.records WHERE id BETWEEN 101 AND 199')->fetchColumn();
    if ($n === 0) { fwrite(STDERR, "ABBRUCH: Matrix-Fixture fehlt in der Vorlage (run.sh matrix)
"); exit(3); }
    sqlx('ALTER TABLE DB.records AUTO_INCREMENT = ' . (FIXTURE_MAX_ID + 1));
}

/** Records als vergleichbare Zeilen */
function recordRows(string $where): array
{
    $st = $GLOBALS['root']->query('SELECT id, zone_id, name, type, content, ttl, server_id FROM `' . DB . "`.records WHERE $where ORDER BY id");
    return array_map(fn($r) => [
        'id' => (int)$r['id'] > FIXTURE_MAX_ID ? 'neu' : (int)$r['id'], 'zone' => (int)$r['zone_id'], 'name' => $r['name'], 'type' => $r['type'],
        'content' => $r['content'], 'ttl' => (int)$r['ttl'], 'system' => $r['server_id'] !== null,
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** POST-Felder als Liste [Name, Wert] in Formular-Reihenfolge (wie ein Browser, inkl. Mehrfachnamen) */
function encodePairs(array $pairs): string
{
    return implode('&', array_map(fn($p) => rawurlencode((string)$p[0]) . '=' . rawurlencode((string)$p[1]), $pairs));
}

$res = [];
foreach ($cases as $id => $case) {
    foreach (['nojs', 'js'] as $variant) {
        $key = "$id/$variant";
        $pairs = null;
        if ($variant === 'js') {
            if (!isset($jsPosts[$id])) { $res[$key] = ['skipped' => 'keine erfassten JS-Daten']; continue; }
            if (!empty($jsPosts[$id]['blocked'])) { $res[$key] = ['blockedByJs' => $jsPosts[$id]['blocked']]; continue; }
        }
        matrixReset($fixtureSql);
        $c = session('admin');
        $page = cgi('GET', $case['page'], '', $c);
        $form = formFor($page['body'], $case['action'], $case['form']);
        if ($form === null) { $res[$key] = ['error' => 'Formular nicht gefunden']; continue; }
        $csrf = csrfFromSession($c);

        if ($variant === 'nojs') {
            $fields = $form['values'];
            foreach ($case['nojs'] ?? $case['ops'] as [$sel, $value, $name]) {
                if ($name !== null && array_key_exists($name, $fields)) { $fields[$name] = $value; }
            }
            $pairs = array_map(null, array_keys($fields), array_values($fields));
        } else {
            $pairs = array_map(fn($p) => $p[0] === 'csrf_token' ? ['csrf_token', $csrf] : $p, $jsPosts[$id]['pairs']);
        }

        $before = $case['mode'] === 'edit' ? recordRows('id = ' . (int)$case['record']) : [];
        $off = logOffset();
        $r = cgi('POST', $case['action'], encodePairs($pairs), $c);
        $log = logSince($off);
        $after = $case['mode'] === 'edit' ? recordRows('id = ' . (int)$case['record']) : recordRows('id > ' . FIXTURE_MAX_ID);
        $sent = [];
        foreach ($pairs as [$n, $v]) { if ($n !== 'csrf_token') { $sent[$n] = $v; } }
        $res[$key] = [
            'status' => $r['status'], 'location' => $r['location'], 'toasts' => toasts($c),
            'stored' => $after, 'changed' => $case['mode'] === 'edit' ? ($before !== $after) : ($after !== []),
            'sent' => $sent, 'problems' => phpProblems($r['body'], $log),
        ];
        printf("%-22s %-5s %s %s\n", $id, $variant, $r['status'], json_encode(array_map(fn($x) => $x['name'] . ' ' . $x['type'] . ' ' . $x['content'] . ' ' . $x['ttl'], $after), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
file_put_contents($outFile, json_encode(['cases' => $res], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
echo "Fälle: " . count($res) . " → $outFile\n";
