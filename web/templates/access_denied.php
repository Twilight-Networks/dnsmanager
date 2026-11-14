<?php
/**
 * Datei: templates/access_denied.php
 * Zweck: Fehlerseite „Zugriff verweigert“ im Layout (U3, U0-B6).
 *
 * - Eingebunden von requireRole() (inc/helpers.php) für Seitenaufrufe per GET bei unzureichender Rolle; der
 *   HTTP-Status 403 und die Protokollierung bleiben unverändert und erfolgen vor der Ausgabe.
 * - Actions und andere Anfragen erhalten weiterhin die bisherige Textantwort.
 * - Erwartet $LANG, $pdo und $lang aus common.php (in requireRole() als global übernommen).
 */
include __DIR__ . '/layout.php';
?>
<div class="dm-error-page">
    <?= ui_card_open('') ?>
    <?= ui_empty_state($LANG['access_denied_title'], [
        'icon' => 'shield-lock',
        'text' => $LANG['access_denied_text'],
        'actionHtml' => '<a class="btn btn-outline-secondary" href="pages/dashboard.php">' . ui_icon('arrow-left') . ' '
            . ui_e($LANG['back_to_dashboard']) . '</a>',
    ]) ?>
    <?= ui_card_close() ?>
</div>
<?php include __DIR__ . '/layout_footer.php'; ?>
