<?php
/**
 * Datei: tests/ui/showcase/diff_cases.php
 * Zweck: Prüfseite DIFF-Logik (U3, Integrationsplan Test 5). Rendert je Fall die serverseitige Fassung (ui_diff,
 *        ui_diff_compare) und übergibt die Fälle als JSON; diff_cases.js rechnet dieselben Fälle mit diff.js nach,
 *        vergleicht Ergebnis und DOM und schreibt PASS/FAIL nach #dmDiffResults (data-pass/data-fail).
 *        Nur Testwerkzeug, ausgeliefert vom Router unter /dnsmanager-ui/__ui/ im Screenshot-Modus.
 */
require '/tmp/dnsmgr-uig/app/web/templates/layout.php';
AssetRegistry::enqueueScript('js/diff.js');

$long = str_repeat('ABCDEFGHIJ', 40);
$cases = [
    'D01 identisch'                => ['www 3600 IN A 192.0.2.1', 'www 3600 IN A 192.0.2.1'],
    'D02 nur neu'                  => [null, 'neu 300 IN AAAA 2001:db8::1'],
    'D03 nur gespeichert'          => ['alt 300 IN A 192.0.2.9', null],
    'D04 Änderung in der Mitte'    => ['www 3600 IN A 192.0.2.10', 'www 3600 IN A 192.0.2.11'],
    'D05 Änderung am Anfang'       => ['www 3600 IN A 192.0.2.1', 'web 3600 IN A 192.0.2.1'],
    'D06 Änderung am Ende'         => ['txt IN TXT "abc"', 'txt IN TXT "abd"'],
    'D07 Einfügung'                => ['mx 10 mail.example.', 'mx 10 mail2.example.'],
    'D08 Löschung'                 => ['mx 10 mail2.example.', 'mx 10 mail.example.'],
    'D09 Wiederholung (aaa→aa)'    => ['aaa', 'aa'],
    'D10 leer → Wert'              => ['', 'x'],
    'D11 Umlaut/Emoji'             => ['txt "Grüße 😀 A"', 'txt "Grüße 😀 B"'],
    'D12 kombinierende Zeichen'    => ["e\u{0301}x", "e\u{0301}y"],
    'D13 Sonderzeichen'            => ['a <b> & \'c\' "d"', 'a <b> & \'c\' "e"'],
    'D14 Tabulatoren'              => ["www\t3600\tIN\tA\t192.0.2.1", "www\t7200\tIN\tA\t192.0.2.1"],
    'D15 langer Wert, Mitte'       => ['p=' . $long, 'p=' . substr($long, 0, 200) . 'XYZ' . substr($long, 203)],
    'D16 langer Wert, unverändert' => ['p=' . $long, 'p=' . $long],
    'D17 HTML im Wert'             => ['<script>alert(1)</script>', '<script>alert(2)</script>'],
];
$diffTexts = ['chars' => $LANG['diff_chars'], 'summaryChanged' => $LANG['diff_summary_changed'], 'summaryNone' => $LANG['diff_summary_none'],
    'showFull' => $LANG['diff_show_full'], 'showLess' => $LANG['diff_show_less']];
$data = [];
?>
<?= ui_page_header('DIFF-Prüfseite (U3)', ['subtitle' => count($cases) . ' Fälle: PHP (ui_diff) gegen JavaScript (diff.js)']) ?>
<div id="dmDiffResults" class="dm-card"><div class="dm-card-body">wird berechnet …</div></div>
<?php foreach ($cases as $id => [$old, $new]):
    $cmp = ($old !== null && $new !== null) ? ui_diff_compare($old, $new) : null;
    $data[] = ['id' => $id, 'old' => $old, 'new' => $new, 'compare' => $cmp]; ?>
    <?= ui_card_open($id) ?>
    <div class="dm-diff-case" data-case="<?= ui_e($id) ?>"><?= ui_diff([[$old, $new]], $diffTexts) ?></div>
    <?= ui_card_close() ?>
<?php endforeach; ?>
<script type="application/json" id="dmDiffCases"><?= json_encode(['cases' => $data, 'texts' => $diffTexts],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/__ui/diff_cases.js"></script>
<?php include '/tmp/dnsmgr-uig/app/web/templates/layout_footer.php'; ?>
