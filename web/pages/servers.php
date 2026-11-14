<?php
/**
 * Datei: servers.php
 * Zweck: Verwaltung der DNS-Server im A+-Design (U5b).
 *
 * Funktionen:
 * - Tabelle aller Server mit Diagnosestatus (ServerReadService::getServerStatusOverview), DNS-/API-Adressen,
 *   lokal/aktiv; Aktionen Bearbeiten, BIND-Reload (Inline-Bestätigung, auch ohne JavaScript) und Löschen.
 * - Rechter Editor (edit_id bzw. add_new, D2) mit templates/server_editor_form.php.
 * - Nur Administratoren (requireRole vor jeder Ausgabe, S39). Actions, Felder und CSRF unverändert.
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/servers/ServerReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Zugriffsbeschränkung: Nur Administratoren (vor jeder Layout-Ausgabe; HTTP 403 und access_denied-Log, S39)
requireRole(['admin']);

include __DIR__ . '/../templates/layout.php';

$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$add_new = isset($_GET['add_new']) && $_GET['add_new'] == '1';

// Server laden (ServerReadService: nur aktive Admins laut Datenbank, explizite Spalten, keine Tokens)
$actor = actorFromSession();
$serverReadService = new ServerReadService($pdo);
$servers = [];
foreach ($serverReadService->listServers($actor) as $s) {
    $servers[] = [
        'id'       => $s['id'],
        'name'     => $s['name'],
        'dns_ip4'  => $s['dnsIp4'],
        'dns_ip6'  => $s['dnsIp6'],
        'api_ip'   => $s['apiIp'],
        'is_local' => $s['isLocal'] ? 1 : 0,
        'active'   => $s['active'] ? 1 : 0,
    ];
}
$serversById = array_column($servers, null, 'id');

// Serverdiagnosen und ihre Auswertung (Core, Paket 6): Status je Server, Kennzeichen, Problemeinträge
$overview = $serverReadService->getServerStatusOverview($actor);

$addButton = '<a href="pages/servers.php?add_new=1" class="btn btn-success">+ ' . ui_e($LANG['add_server']) . '</a>';
echo ui_page_header($LANG['dns_servers'], ['subtitle' => sprintf($LANG['servers_count'], count($servers)), 'actionsHtml' => $addButton]);

if ($overview['hasErrors'] || $overview['hasWarnings']) {
    $variant = $overview['hasErrors'] ? 'err' : 'warn';
    echo '<details class="dm-card dm-diagnostics dm-diagnostics-' . $variant . '"><summary class="dm-card-head">'
        . ui_icon($overview['hasErrors'] ? 'x-circle' : 'exclamation-triangle') . '<h2 class="dm-card-title">'
        . ui_e($overview['hasErrors'] ? $LANG['server_error'] : $LANG['server_warning']) . '</h2>'
        . '<span class="dm-diagnostics-more">' . ui_e($LANG['zone_diagnostics_details']) . '</span></summary><div class="dm-card-body">';
    foreach ($overview['problems'] as $problem) {
        echo '<div class="dm-diagnostic"><strong class="dm-mono">' . ui_e((string)($problem['serverName'] ?? '(unbekannt)')) . '</strong>'
            . '<pre class="app-pre-wrap">' . ui_e((string)($problem['message'] ?? '')) . '</pre></div>';
    }
    echo '</div></details>';
} else {
    echo ui_notice('ok', $LANG['server_ok']);
}

$statusChip = static function (?string $status) use ($LANG): string {
    return match ($status) {
        'ok'      => ui_chip($LANG['status_ok'], 'ok', 'check-circle'),
        'warning' => ui_chip($LANG['status_warning'], 'warn', 'exclamation-triangle'),
        'error'   => ui_chip($LANG['status_error'], 'err', 'x-circle'),
        default   => ui_chip($LANG['status_unknown']),
    };
};

echo ui_card_open($LANG['dns_servers'], ['flush' => true, 'actionsHtml' => ui_chip((string)count($servers))]);
if ($servers === []) {
    echo ui_empty_state($LANG['servers_empty'], ['icon' => 'hdd-network', 'actionHtml' => $addButton]);
} else {
    echo '<div class="table-responsive"><table class="dm-table dm-servers-table"><thead><tr>'
        . '<th>' . ui_e($LANG['name']) . '</th><th>' . ui_e($LANG['zone_col_status']) . '</th><th>DNS-IP</th><th>API-IP</th>'
        . '<th>' . ui_e($LANG['local']) . '</th><th>' . ui_e($LANG['active']) . '</th>'
        . '<th class="dm-col-actions">' . ui_e($LANG['actions']) . '</th></tr></thead><tbody>';
    foreach ($servers as $server) {
        $isCurrent = $edit_id === (int)$server['id'];
        echo '<tr' . ($isCurrent ? ' class="dm-current"' : ($server['active'] ? '' : ' class="dm-muted-row"')) . '>';
        echo '<td class="dm-mono dm-col-name">' . ui_e($server['name']) . '</td>';
        echo '<td>' . $statusChip($overview['serverStatus'][$server['id']] ?? null) . '</td>';
        echo '<td class="dm-mono">' . ui_e((string)$server['dns_ip4']) . (!empty($server['dns_ip6']) ? '<br>' . ui_e((string)$server['dns_ip6']) : '') . '</td>';
        echo '<td class="dm-mono">' . ui_e((string)($server['api_ip'] ?? '-')) . '</td>';
        echo '<td>' . ($server['is_local'] ? ui_chip($LANG['yes'], 'accent') : ui_e($LANG['no'])) . '</td>';
        echo '<td>' . ($server['active'] ? ui_chip($LANG['yes'], 'ok') : ui_chip($LANG['no'])) . '</td>';
        echo '<td class="dm-col-actions"><a class="btn btn-sm btn-outline-secondary" href="pages/servers.php?edit_id=' . (int)$server['id'] . '">' . ui_e($LANG['edit']) . '</a> '
            // BIND-Reload: bestehender POST-/CSRF-Vertrag (actions/bind_reload.php, id); Bestätigung inline statt Modal
            . '<form method="post" action="actions/bind_reload.php" class="d-inline">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$server['id'] . '">'
            . ui_confirm_action_button($LANG['bind_reload'], ['prompt' => $LANG['bind_reload_confirm'], 'confirm' => $LANG['bind_reload'], 'variant' => 'warning'])
            . '</form> '
            . '<form method="post" action="actions/server_delete.php" class="d-inline confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$server['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo ui_card_close();

// Rechter Editor
$edit_server = ($edit_id !== null && isset($serversById[$edit_id])) ? $serversById[$edit_id] : null;
if ($add_new || $edit_server !== null) {
    $serverEditorMode = $edit_server !== null && !$add_new ? 'update' : 'create';
    if ($serverEditorMode === 'create') {
        $edit_server = null;
    }
    $formId = $serverEditorMode === 'create' ? 'serverAddForm' : 'editForm_' . (int)$edit_server['id'];
    echo ui_editor_open($serverEditorMode === 'create' ? $LANG['add_server'] : $LANG['server_editor_title_edit'], 'pages/servers.php', [
        'subtitle' => $serverEditorMode === 'create' ? '' : (string)$edit_server['name'],
        'closeLabel' => $LANG['close'],
    ]);
    include __DIR__ . '/../templates/server_editor_form.php';
    $leftHtml = '';
    if ($serverEditorMode === 'update') {
        $leftHtml = '<form method="post" action="actions/server_delete.php" class="confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$edit_server['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form>';
    }
    echo ui_editor_close('pages/servers.php', [
        'formId' => $formId, 'saveLabel' => $serverEditorMode === 'create' ? $LANG['add_server'] : $LANG['save'],
        'cancelLabel' => $LANG['cancel'], 'leftHtml' => $leftHtml,
    ]);
}

AssetRegistry::enqueueScript('js/servers.js');
AssetRegistry::enqueueScript('js/server_add_form.js');
AssetRegistry::enqueueScript('js/server_edit_form.js');
include __DIR__ . '/../templates/layout_footer.php';
