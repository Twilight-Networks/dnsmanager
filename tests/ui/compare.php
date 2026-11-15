<?php
/**
 * Datei: tests/ui/compare.php
 * Zweck: Vergleicht ein Ergebnis der UI-Golden-Basis mit einer gespeicherten Basis.
 *        Vertragsrelevant (Abweichung = Fehler, Exit 1): Status, Weiterleitung, Sicherheitsheader, Toasts, Login-Hinweise,
 *        Logs, Datenbankzustand, PHP-Probleme, Seitenvertrag (Formulare inkl. Kodierung, Linkziele), API-/Fehlerantworten.
 *        Informativ (nur gemeldet): normalisierter Seiteninhalt (info.*) – er ändert sich mit der UI-Modernisierung.
 *        Beabsichtigte Abweichungen werden je Fall-ID in einer Ausnahmedatei (JSON: {"<Fall-ID>": ["<Schlüssel>", …]})
 *        freigegeben und so im PR dokumentiert.
 *        U6: Schlüssel „headers+“ erlaubt nur zusätzliche Headerzeilen, die die Ausnahmedatei unter „_headers_added“
 *        exakt nennt (z. B. die durchgesetzte Content-Security-Policy); übrige Header müssen der Basis entsprechen.
 * Aufruf: php compare.php <basis.json> <ergebnis.json> [ausnahmen.json]
 */
[$_, $baseFile, $newFile] = $argv + [null, null, null];
$allowFile = $argv[3] ?? null;
if (!$baseFile || !$newFile) { fwrite(STDERR, "Aufruf: php compare.php <basis.json> <ergebnis.json> [ausnahmen.json]\n"); exit(2); }
$base = json_decode((string)file_get_contents($baseFile), true)['cases'] ?? null;
$new = json_decode((string)file_get_contents($newFile), true)['cases'] ?? null;
$allow = $allowFile ? (json_decode((string)file_get_contents($allowFile), true) ?? []) : [];
if (!is_array($base) || !is_array($new)) { fwrite(STDERR, "Ungültige Eingabedatei\n"); exit(2); }

$fail = 0; $allowed = 0; $info = 0; $same = 0;
foreach (array_unique(array_merge(array_keys($base), array_keys($new))) as $id) {
    if (!isset($base[$id]) || !isset($new[$id])) {
        echo (isset($base[$id]) ? 'FEHLT    ' : 'NEU      ') . "$id\n"; $fail++; continue;
    }
    $diff = []; $infoDiff = [];
    foreach (array_unique(array_merge(array_keys($base[$id]), array_keys($new[$id]))) as $k) {
        if (($base[$id][$k] ?? null) === ($new[$id][$k] ?? null)) { continue; }
        if ($k === 'info') { $infoDiff[] = $k; } else { $diff[] = $k; }
    }
    // U6-V1: „headers+“ gibt ausschließlich das Hinzukommen der unter _headers_added genannten Headerzeilen frei
    // (jede genau einmal, Wert exakt); jede andere Abweichung der Header bleibt eine Vertragsabweichung.
    if (in_array('headers', $diff, true) && in_array('headers+', $allow[$id] ?? [], true)) {
        $rest = $new[$id]['headers'] ?? [];
        $complete = true;
        foreach ($allow['_headers_added'] ?? [] as $line) {
            $pos = array_search($line, $rest, true);
            if ($pos === false) { $complete = false; break; }
            unset($rest[$pos]);
        }
        if ($complete && array_values($rest) === ($base[$id]['headers'] ?? [])) {
            $diff = array_values(array_diff($diff, ['headers']));
            $diff[] = 'headers+';
        }
    }
    $ok = array_diff($diff, $allow[$id] ?? []);
    if ($ok) {
        $fail++;
        echo "ABWEICHUNG $id: " . implode(', ', $ok) . "\n";
        foreach ($ok as $k) {
            echo '    Basis:    ' . substr(json_encode($base[$id][$k] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 600) . "\n";
            echo '    Ergebnis: ' . substr(json_encode($new[$id][$k] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 600) . "\n";
        }
    } elseif ($diff) {
        $allowed++; echo "FREIGEGEBEN $id: " . implode(', ', $diff) . "\n";
    } elseif ($infoDiff) {
        $info++;
    } else {
        $same++;
    }
}
echo "\nVergleich: identisch $same, nur informativ abweichend $info, freigegeben abweichend $allowed, Vertragsabweichungen $fail\n";
exit($fail === 0 ? 0 : 1);
