<?php
/**
 * Datei: tests/ui/showcase/showcase.php
 * Zweck: Bausteinübersicht (U3) für visuelle Nachweise im Screenshot-Modus der Testkopie (run.sh serve).
 *        Rendert alle UI-Bausteine mit dem echten Layout, den echten Stilen und Skripten der Testkopie.
 *        Nur Testwerkzeug: liegt nicht in web/, wird vom Router unter /dnsmanager-ui/__ui/ ausgeliefert.
 *        Keine Schreibzugriffe: alle Formulare zeigen auf diese Seite selbst (GET).
 * Parameter: ?editor=1 öffnet den Editor-Rahmen (wie ?edit_id= auf den Fachseiten, D2).
 */
require '/tmp/dnsmgr-uig/app/web/templates/layout.php';
AssetRegistry::enqueueScript('js/diff.js');

$self = rtrim(BASE_URL, '/') . '/__ui/showcase.php';
$diffTexts = [
    'chars' => $LANG['diff_chars'], 'summaryChanged' => $LANG['diff_summary_changed'], 'summaryNone' => $LANG['diff_summary_none'],
    'showFull' => $LANG['diff_show_full'], 'showLess' => $LANG['diff_show_less'], 'label' => $LANG['diff_label'],
];
$key1 = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu4Qx2Nf0r7Zp1q8sVh3b5kLmT9yXc2Wd6eRt4uIo8pAsDf'
    . 'GhJkLzXcVbNm1Q2w3E4r5T6y7U8i9O0pAaSsDdFfGgHhJjKkLlZzXxCcVvBbNnMm0123456789abcdefghijklmnopqrstuv';
$key2 = substr($key1, 0, 120) . 'NEU7x' . substr($key1, 125);
?>
<?= ui_page_header('Bausteine (U3)', [
    'subtitle' => 'Übersicht aller UI-Bausteine – nur Testkopie, keine Fachseite',
    'actionsHtml' => '<a class="btn btn-outline-secondary" href="' . ui_e($self) . '?editor=1">Editor öffnen</a>'
        . '<button type="button" class="btn btn-success">+ Neuer Eintrag</button>',
]) ?>

<?= ui_notice('warn', 'Ausstehende Veröffentlichung:', [
    'text' => '2 Zonen sind gespeichert, aber noch nicht auf den DNS-Servern.',
    'actionsHtml' => '<button type="button" class="btn btn-sm btn-warning">Veröffentlichen</button>',
]) ?>
<?= ui_notice('info', 'Hinweis:', ['text' => $LANG['saved_not_published']]) ?>
<?= ui_notice('err', 'Fehler:', ['text' => 'Ein Server ist nicht erreichbar.', 'role' => 'alert']) ?>
<?= ui_notice('ok', 'Alles in Ordnung.') ?>

<?= ui_kpi_grid([
    ui_kpi('Zonen', '12', ['icon' => 'globe2', 'foot' => '2 ausstehend']),
    ui_kpi('Einträge', '348', ['icon' => 'list']),
    ui_kpi('Server', '2', ['icon' => 'hdd-network', 'unit' => 'aktiv', 'variant' => 'ok']),
    ui_kpi('Warnungen', '1', ['icon' => 'exclamation-triangle', 'variant' => 'warn', 'href' => 'pages/system_health.php']),
]) ?>

<?= ui_card_open('Status-Chips') ?>
<p class="mb-0">
    <?= ui_chip('OK', 'ok', 'check-circle') ?> <?= ui_chip('Warnung', 'warn', 'exclamation-triangle') ?>
    <?= ui_chip('Fehler', 'err', 'x-circle') ?> <?= ui_chip('Info', 'info', 'info-circle') ?>
    <?= ui_chip('Master', 'accent') ?> <?= ui_chip('Slave') ?>
</p>
<?= ui_card_close() ?>

<?php
// Kompakte Tabelle mit Gruppenzeilen, Auswahl und Massenaktionsleiste (Formular zeigt auf diese Seite, GET)
$bulkForm = '<form method="get" action="' . ui_e($self) . '" class="d-inline"><input type="hidden" name="bulk_ids" value="" data-dm-bulk-ids>'
    . '<button type="submit" class="btn btn-sm btn-outline-danger">' . ui_icon('trash') . ' ' . ui_e($LANG['delete']) . '</button></form>';
?>
<?= ui_card_open('Kompakte Tabelle', ['flush' => true, 'actionsHtml' => ui_chip('5 Einträge')]) ?>
<?= ui_bulk_bar('demo', $LANG['bulk_selected'], $bulkForm) ?>
<div class="table-responsive">
<table class="dm-table">
    <thead><tr><th class="dm-col-select"><?= ui_select_all('demo', $LANG['select_all']) ?></th><th>Name</th><th>Typ</th><th>Inhalt</th><th>TTL</th><th class="dm-col-actions">Aktionen</th></tr></thead>
    <tbody>
        <tr class="dm-group-row"><td colspan="6">System (NS/Glue)</td></tr>
        <tr><td class="dm-col-select"></td><td class="dm-mono">@</td><td><?= ui_chip('NS') ?></td><td class="dm-mono">ns1.example.test.</td><td>3600</td><td class="dm-col-actions"><?= ui_chip('geschützt', 'neutral') ?></td></tr>
        <tr class="dm-group-row"><td colspan="6">Einträge</td></tr>
        <?php foreach ([[11, 'www', 'A', '192.0.2.10'], [12, 'mail', 'MX', '10 mail.example.test.'], [13, '_sip._tcp', 'SRV', '10 5 5060 sip.example.test.']] as $i => [$id, $n, $t, $c]): ?>
        <tr class="<?= $i === 0 ? 'dm-current' : '' ?>"><td class="dm-col-select"><?= ui_select_row('demo', (string)$id, $LANG['select_row'] . ' ' . $n) ?></td>
            <td class="dm-mono"><?= ui_e($n) ?></td><td><?= ui_chip($t, 'accent') ?></td><td class="dm-mono"><?= ui_e($c) ?></td><td>3600</td>
            <td class="dm-col-actions">
                <a class="btn btn-sm btn-outline-secondary" href="<?= ui_e($self) ?>?editor=1">Bearbeiten</a>
                <form method="get" action="<?= ui_e($self) ?>" class="d-inline confirm-delete"><input type="hidden" name="geloescht" value="<?= $id ?>">
                    <?= ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']]) ?>
                </form>
            </td></tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?= ui_card_close() ?>

<?= ui_card_open('Leerzustand') ?>
<?= ui_empty_state('Noch keine Einträge', ['icon' => 'inbox', 'text' => 'Lege den ersten Eintrag für diese Zone an.',
    'actionHtml' => '<button type="button" class="btn btn-success">+ Neuer Eintrag</button>']) ?>
<?= ui_card_close() ?>

<?= ui_card_open('DIFF-Darstellung') ?>
<h3 class="dm-editor-section-label">Geänderter Wert</h3>
<?= ui_diff([["www\t3600\tIN\tA\t192.0.2.10", "www\t3600\tIN\tA\t192.0.2.11"]], $diffTexts) ?>
<h3 class="dm-editor-section-label mt-3">Neuer Eintrag und unveränderter Eintrag</h3>
<?= ui_diff([[null, "neu\t300\tIN\tAAAA\t2001:db8::1"], ["mail\t3600\tIN\tMX\t10 mail.example.test.", "mail\t3600\tIN\tMX\t10 mail.example.test."]], $diffTexts) ?>
<h3 class="dm-editor-section-label mt-3">Langer Wert (DKIM, Änderung in der Mitte; mit JavaScript eingeklappt)</h3>
<?= ui_diff([["sel1._domainkey\t3600\tIN\tTXT\t\"v=DKIM1; k=rsa; s=email; h=sha256; p=$key1\"",
              "sel1._domainkey\t3600\tIN\tTXT\t\"v=DKIM1; k=rsa; s=email; h=sha256; p=$key2\""]], $diffTexts) ?>
<h3 class="dm-editor-section-label mt-3">Sonderzeichen</h3>
<?= ui_diff([["txt\t3600\tIN\tTXT\t\"a <b> & 'c' \\\"d\\\"\"", "txt\t3600\tIN\tTXT\t\"a <b> & 'c' \\\"e\\\" ü\""]], $diffTexts) ?>
<?= ui_card_close() ?>

<?php if (($_GET['editor'] ?? '') === '1'): ?>
    <?= ui_editor_open('Eintrag bearbeiten', $self, ['chip' => 'A', 'subtitle' => 'www.example.test · Zone example.test', 'closeLabel' => $LANG['close']]) ?>
        <form id="demoEditorForm" method="get" action="<?= ui_e($self) ?>">
            <div class="row g-3">
                <div class="col-6"><label class="form-label" for="demoName">Name</label><input class="form-control" id="demoName" name="name" value="www"></div>
                <div class="col-6"><label class="form-label" for="demoTtl">TTL</label><input class="form-control" id="demoTtl" name="ttl" value="3600"></div>
                <div class="col-12"><label class="form-label" for="demoContent">Inhalt</label><input class="form-control dm-mono" id="demoContent" name="content" value="192.0.2.11"></div>
            </div>
        </form>
        <?= ui_editor_section('Vorschau der Zonendatei', ui_diff([["www\t3600\tIN\tA\t192.0.2.10", "www\t3600\tIN\tA\t192.0.2.11"]], $diffTexts)) ?>
        <div class="dm-editor-note"><?= ui_icon('info-circle') ?><span><?= ui_e($LANG['saved_not_published']) ?></span></div>
    <?= ui_editor_close($self, [
        'formId' => 'demoEditorForm', 'saveLabel' => $LANG['save'], 'cancelLabel' => $LANG['cancel'],
        'leftHtml' => '<form method="get" action="' . ui_e($self) . '" class="confirm-delete"><input type="hidden" name="geloescht" value="11">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']]) . '</form>',
    ]) ?>
<?php endif; ?>

<?php include '/tmp/dnsmgr-uig/app/web/templates/layout_footer.php'; ?>
