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
 *        SOA-V1: Schlüssel „db~“ gibt im Datenbankzustand ausschließlich die unter „_db_changes“ je Fall-ID genannten
 *        Einzelwerte frei ({"table", "id", "column", "field", "from", "to"}: Feld Nr. field – 0-basiert, Feld 0 = id,
 *        „column“ nur zur Lesbarkeit – der Zeile mit dieser id hat in der Basis exakt „from“ und im Ergebnis exakt
 *        „to“). Nach Anwendung dieser Änderungen auf die Basis muss der
 *        gesamte Datenbankzustand identisch sein; jede weitere Abweichung (Zeilen, Spalten, Tabellen) bleibt eine
 *        Vertragsabweichung.
 * Aufruf: php compare.php <basis.json> <ergebnis.json> [ausnahmen.json]
 */
[$_, $baseFile, $newFile] = $argv + [null, null, null];
$allowFile = $argv[3] ?? null;
if (!$baseFile || !$newFile) { fwrite(STDERR, "Aufruf: php compare.php <basis.json> <ergebnis.json> [ausnahmen.json]\n"); exit(2); }
$base = json_decode((string)file_get_contents($baseFile), true)['cases'] ?? null;
$new = json_decode((string)file_get_contents($newFile), true)['cases'] ?? null;
$allow = $allowFile ? (json_decode((string)file_get_contents($allowFile), true) ?? []) : [];
if (!is_array($base) || !is_array($new)) { fwrite(STDERR, "Ungültige Eingabedatei\n"); exit(2); }

/**
 * Wendet die freigegebenen Einzeländerungen auf den Datenbankzustand der Basis an. Liefert null, wenn eine Änderung
 * nicht exakt passt (Tabelle/Zeile fehlt, Zeile mehrfach, Feldwert der Basis ungleich „from“) – dann keine Freigabe.
 */
function dbWithChanges(?array $db, array $changes): ?array
{
    if ($db === null || $changes === []) { return null; }
    foreach ($changes as $c) {
        $table = (string)($c['table'] ?? ''); $field = $c['field'] ?? null;
        if (!isset($db[$table]) || !is_int($field) || $field < 1 || !isset($c['id'], $c['from'], $c['to'])) { return null; }
        $hits = array_keys(array_filter($db[$table], fn($row) => explode('|', $row)[0] === (string)$c['id']));
        if (count($hits) !== 1) { return null; }
        $cols = explode('|', $db[$table][$hits[0]]);
        if (!array_key_exists($field, $cols) || $cols[$field] !== (string)$c['from']) { return null; }
        $cols[$field] = (string)$c['to'];
        $db[$table][$hits[0]] = implode('|', $cols);
    }
    return $db;
}

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
    // SOA-V1: „db~“ gibt nur die exakt benannten Einzelwerte frei (siehe Dateikopf)
    if (in_array('db', $diff, true) && in_array('db~', $allow[$id] ?? [], true)
        && dbWithChanges($base[$id]['db'] ?? null, $allow['_db_changes'][$id] ?? []) === ($new[$id]['db'] ?? null)) {
        $diff = array_values(array_diff($diff, ['db']));
        $diff[] = 'db~';
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
