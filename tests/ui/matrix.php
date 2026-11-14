<?php
/**
 * Datei: tests/ui/matrix.php
 * Zweck: Erzeugt aus einem Ergebnis der UI-Golden-Basis die Rollenmatrix (Markdown): Seitenzugriff je Rolle und
 *        Ergebnis der Action-Fälle. Grundlage für docs/UI_ROLE_MATRIX.md.
 * Aufruf: php matrix.php <ergebnis.json>
 */
$d = json_decode((string)file_get_contents($argv[1] ?? ''), true);
if (!is_array($d)) { fwrite(STDERR, "Aufruf: php matrix.php <ergebnis.json>\n"); exit(2); }
$cases = $d['cases'];
$cell = function (?array $c): string {
    if ($c === null) { return '–'; }
    $s = (string)$c['status'];
    if ($c['status'] === 302) { $s .= ' → ' . (str_contains((string)$c['location'], 'login.php') ? 'Login' : basename((string)parse_url((string)$c['location'], PHP_URL_PATH))); }
    if ($c['problems'] ?? []) { $s .= ' ⚠'; }
    return $s;
};
echo "### Seiten (GET)\n\n| Seite | Anfrage | anonym | admin | zoneadmin |\n|---|---|---|---|---|\n";
$keys = [];
foreach ($cases as $id => $c) { if ($c['kind'] === 'page') { $keys[explode('/', $id, 3)[2]] = $c['request']; } }
foreach ($keys as $k => $req) {
    echo "| $k | `" . substr($req, 4) . '` | ' . $cell($cases["page/anon/$k"] ?? null) . ' | ' . $cell($cases["page/admin/$k"] ?? null) . ' | ' . $cell($cases["page/zoneadmin/$k"] ?? null) . " |\n";
}
echo "\n### Actions, Login/Logout und DynDNS-API\n\n| Fall | Rolle | Anfrage | Ergebnis | Meldung |\n|---|---|---|---|---|\n";
foreach ($cases as $id => $c) {
    if ($c['kind'] !== 'action') { continue; }
    $msg = $c['toasts'] ?? ($c['alerts'] ?? []);
    $msg = $msg ? implode(' / ', array_map(fn($t) => strip_tags(str_replace('<br>', ' ', $t)), $msg)) : (($c['body'] ?? '') !== '' ? $c['body'] : '');
    echo "| $id | {$c['role']} | `" . str_replace('|', '\\|', $c['request']) . '` | ' . $cell($c) . ' | ' . str_replace('|', '\\|', mb_strimwidth($msg, 0, 140, '…')) . " |\n";
}
echo "\n⚠ = PHP-Meldung im Log oder in der Antwort (siehe Ergebnisdatei, Feld `problems`).\n";
