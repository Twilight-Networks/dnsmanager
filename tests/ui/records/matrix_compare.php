<?php
/**
 * Datei: tests/ui/records/matrix_compare.php
 * Zweck: Vergleicht zwei Läufe der Record-Typ-Matrix (z. B. main gegen U4-Core): je Fall und Variante (nojs/js)
 *        gespeicherte bzw. geänderte Records, Status, Weiterleitung, Toasts und PHP-Probleme.
 *        Abweichungen sind nur zulässig, wenn sie in der Abweichungsliste (JSON: {"<Fall>/<Variante>": "<Abweichungs-ID>"})
 *        stehen; sonst Exit 1. Dokumentierte Abweichungen, die nicht (mehr) auftreten, werden ebenfalls gemeldet.
 * Aufruf: php matrix_compare.php <basis.json> <ergebnis.json> [abweichungen.json]
 */
[$_, $baseFile, $newFile] = $argv + [null, null, null];
$allowFile = $argv[3] ?? null;
$base = json_decode((string)file_get_contents((string)$baseFile), true)['cases'] ?? null;
$new = json_decode((string)file_get_contents((string)$newFile), true)['cases'] ?? null;
$allow = $allowFile ? (json_decode((string)file_get_contents($allowFile), true) ?? []) : [];
if (!is_array($base) || !is_array($new)) { fwrite(STDERR, "Aufruf: php matrix_compare.php <basis.json> <ergebnis.json> [abweichungen.json]\n"); exit(2); }

$norm = fn(array $c) => ['status' => $c['status'] ?? null, 'location' => $c['location'] ?? null, 'stored' => $c['stored'] ?? null,
    'toasts' => $c['toasts'] ?? null, 'problems' => $c['problems'] ?? null, 'skipped' => $c['skipped'] ?? null, 'blocked' => $c['blockedByJs'] ?? null];
$fmt = fn(?array $rows) => $rows === null ? '-' : ($rows === [] ? '(nichts gespeichert)' : implode(' | ', array_map(fn($r) => "{$r['name']} {$r['type']} {$r['content']} ttl={$r['ttl']}", $rows)));

$same = 0; $dev = 0; $fail = 0; $seen = [];
foreach (array_unique(array_merge(array_keys($base), array_keys($new))) as $k) {
    $a = isset($base[$k]) ? $norm($base[$k]) : null;
    $b = isset($new[$k]) ? $norm($new[$k]) : null;
    if ($a === $b) { $same++; continue; }
    $id = $allow[$k] ?? null;
    $seen[$k] = true;
    if ($id !== null) { $dev++; $tag = "ABWEICHUNG $id"; } else { $fail++; $tag = 'NICHT DOKUMENTIERT'; }
    echo "$tag  $k\n";
    echo "    vorher:  " . $fmt($a['stored'] ?? null) . (($a['toasts'] ?? []) ? '  [' . implode(' || ', array_map('strip_tags', $a['toasts'])) . ']' : '') . "\n";
    echo "    nachher: " . $fmt($b['stored'] ?? null) . (($b['toasts'] ?? []) ? '  [' . implode(' || ', array_map('strip_tags', $b['toasts'])) . ']' : '') . "\n";
    if (($a['problems'] ?? null) !== ($b['problems'] ?? null)) { echo '    PHP-Probleme: ' . json_encode($b['problems'] ?? null, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$unused = array_diff(array_keys($allow), array_keys($seen));
foreach ($unused as $k) { echo "DOKUMENTIERT, ABER NICHT AUFGETRETEN  $k ({$allow[$k]})\n"; }
echo "\nMatrix-Vergleich: identisch $same, dokumentierte Abweichungen $dev, nicht dokumentiert $fail, nicht aufgetreten " . count($unused) . "\n";
exit(($fail === 0 && $unused === []) ? 0 : 1);
