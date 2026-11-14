<?php
/**
 * Datei: dyndns.php
 * Zweck: Verwaltung der DynDNS-Accounts im A+-Design (U5b).
 *
 * Funktionen:
 * - Tabelle aller Accounts (Benutzername, Hostname, Zone, IPv4/IPv6, letzte Aktualisierung) mit Aktionen
 *   Bearbeiten, Passwort, Löschen.
 * - Rechter Editor: Anlage (add_new), Bearbeitung (edit_id) und Passwortänderung (pw_id) – auch ohne JavaScript;
 *   das Öffnen eines Editors ändert keinen Zustand. Actions, Felder und CSRF unverändert
 *   (dyndns_add.php, dyndns_update.php, dyndns_password.php, dyndns_delete.php).
 * - Hinweise zur DynDNS-Schnittstelle als aufklappbarer Bereich.
 * - Nur Administratoren (requireRole vor jeder Ausgabe, S39).
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';

// Zugriff: Nur Admin (vor jeder Layout-Ausgabe; HTTP 403 und access_denied-Log, S39)
requireRole(['admin']);

include __DIR__ . '/../templates/layout.php';

$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$pw_id = isset($_GET['pw_id']) ? (int)$_GET['pw_id'] : null;
$add_new = isset($_GET['add_new']) && $_GET['add_new'] === '1';

// DynDNS-Accounts laden (DyndnsReadService: nur aktive Admins laut Datenbank, explizite Spalten, kein password_hash)
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/dyndns/DyndnsReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
$actor = actorFromSession();
$dyndnsReadService = new DyndnsReadService($pdo);
$accounts = [];
foreach ($dyndnsReadService->listAccounts($actor) as $a) {
    $accounts[] = [
        'id'           => $a['id'],
        'username'     => $a['username'],
        'hostname'     => $a['hostname'],
        'zone_id'      => $a['zoneId'],
        'zone_name'    => $a['zoneName'],
        'current_ipv4' => $a['currentIpv4'],
        'current_ipv6' => $a['currentIpv6'],
        'last_update'  => $a['lastUpdate'],
    ];
}
$accountsById = array_column($accounts, null, 'id');

$addButton = '<a href="pages/dyndns.php?add_new=1" class="btn btn-success">+ ' . ui_e($LANG['add_dyndns_account']) . '</a>';
echo ui_page_header($LANG['dyndns_accounts'], ['subtitle' => sprintf($LANG['dyndns_count'], count($accounts)), 'actionsHtml' => $addButton]);
?>
<details class="dm-card dm-info-card">
    <summary class="dm-card-head"><?= ui_icon('info-circle') ?><h2 class="dm-card-title"><?= ui_e($LANG['dyndns_info_toggle']) ?></h2></summary>
    <div class="dm-card-body">
        <p><strong><?= $LANG['dyndns_info_title'] ?></strong></p>
        <p><?= $LANG['dyndns_info_text_1'] ?></p>
        <div class="input-group input-group-sm app-mw-700">
            <input type="text" id="dyndnsUrl" class="form-control form-control-sm dm-mono" readonly aria-label="<?= ui_e($LANG['dyndns_info_title']) ?>"
                   value="https://example.com<?= rtrim(BASE_URL, '/') ?>/api/v1/dyndns/update.php?myip=&lt;ipaddr&gt;&amp;myip6=&lt;ip6addr&gt;">
            <button class="btn btn-outline-secondary btn-sm" type="button" id="copyDynDnsUrlBtn" title="<?= ui_e($LANG['dyndns_copy_title']) ?>">📋</button>
        </div>
        <p class="mt-3 mb-1"><strong><?= $LANG['dyndns_supported_parameters'] ?></strong></p>
        <ul class="mb-2">
            <li><code>myip</code> – <?= $LANG['ipv4_address'] ?></li>
            <li><code>myip6</code> – <?= $LANG['ipv6_address'] ?></li>
        </ul>
        <small class="text-muted"><strong><?= $LANG['important'] ?></strong> <?= $LANG['dyndns_info_auth'] ?></small>
    </div>
</details>
<?php
echo ui_card_open($LANG['dyndns_accounts'], ['flush' => true, 'actionsHtml' => ui_chip((string)count($accounts))]);
if ($accounts === []) {
    echo ui_empty_state($LANG['dyndns_empty'], ['icon' => 'arrow-repeat', 'actionHtml' => $addButton]);
} else {
    echo '<div class="table-responsive"><table class="dm-table"><thead><tr>'
        . '<th>' . ui_e($LANG['username']) . '</th><th>' . ui_e($LANG['hostname']) . '</th><th>' . ui_e($LANG['zone']) . '</th>'
        . '<th>IPv4</th><th>IPv6</th><th>' . ui_e($LANG['last_update']) . '</th><th class="dm-col-actions">' . ui_e($LANG['actions']) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($accounts as $acc) {
        $isCurrent = (int)$acc['id'] === $edit_id || (int)$acc['id'] === $pw_id;
        echo '<tr' . ($isCurrent ? ' class="dm-current"' : '') . '>'
            . '<td class="dm-mono">' . ui_e($acc['username']) . '</td>'
            . '<td class="dm-mono">' . ui_e($acc['hostname']) . '</td>'
            . '<td class="dm-mono">' . ui_e($acc['zone_name']) . '</td>'
            . '<td class="dm-mono">' . ui_e($acc['current_ipv4'] ?? '-') . '</td>'
            . '<td class="dm-mono">' . ui_e($acc['current_ipv6'] ?? '-') . '</td>'
            . '<td>' . ($acc['last_update'] ? ui_e((string)$acc['last_update']) : '-') . '</td>'
            . '<td class="dm-col-actions"><a class="btn btn-sm btn-outline-secondary" href="pages/dyndns.php?edit_id=' . (int)$acc['id'] . '">' . ui_e($LANG['edit']) . '</a> '
            . '<a class="btn btn-sm btn-outline-secondary" href="pages/dyndns.php?pw_id=' . (int)$acc['id'] . '">' . ui_e($LANG['password']) . '</a> '
            . '<form method="post" action="actions/dyndns_delete.php" class="d-inline confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$acc['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo ui_card_close();

// Rechter Editor: Anlage, Bearbeitung oder Passwort (nur für vorhandene Accounts)
$edit_account = ($edit_id !== null && isset($accountsById[$edit_id])) ? $accountsById[$edit_id] : null;
$pw_account = ($pw_id !== null && isset($accountsById[$pw_id])) ? $accountsById[$pw_id] : null;
if ($add_new || $edit_account !== null || $pw_account !== null) {
    $mode = $add_new ? 'create' : ($edit_account !== null ? 'update' : 'password');
    $titles = ['create' => $LANG['dyndns_add_heading'], 'update' => $LANG['dyndns_editor_title_edit'],
        'password' => sprintf($LANG['change_password_for'], $pw_account['username'] ?? '')];
    echo ui_editor_open($titles[$mode], 'pages/dyndns.php', [
        'subtitle' => $mode === 'update' ? $edit_account['hostname'] . ' · ' . $edit_account['zone_name'] : ($mode === 'password' ? $pw_account['hostname'] . ' · ' . $pw_account['zone_name'] : ''),
        'closeLabel' => $LANG['close'],
    ]);
    $formId = 'dyndnsForm';
    if ($mode === 'password') {
        echo '<form method="post" action="actions/dyndns_password.php" id="' . $formId . '">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$pw_account['id'] . '">'
            . '<div class="dm-field-grid"><div class="dm-field dm-field-wide"><label class="form-label" for="dyn_password">' . ui_e($LANG['new_password']) . '</label>'
            . '<input type="password" name="password" id="dyn_password" class="form-control" required autocomplete="new-password"></div></div></form>';
    } else {
        include __DIR__ . '/../templates/dyndns_editor_form.php';
    }
    $leftHtml = '';
    if ($mode === 'update') {
        $leftHtml = '<form method="post" action="actions/dyndns_delete.php" class="confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$edit_account['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form>';
    }
    echo ui_editor_close('pages/dyndns.php', [
        'formId' => $formId, 'saveLabel' => $mode === 'create' ? $LANG['dyndns_add_submit'] : $LANG['save'],
        'cancelLabel' => $LANG['cancel'], 'leftHtml' => $leftHtml,
    ]);
}

AssetRegistry::enqueueScript('js/dyndns.js');
include __DIR__ . '/../templates/layout_footer.php';
