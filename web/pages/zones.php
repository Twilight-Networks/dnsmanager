<?php
/**
 * Datei: zones.php
 * Zweck: Zonenliste und Zoneneinstellungen im A+-Design (U5a).
 * Details:
 * - Eine gemeinsame Tabelle mit Gruppen Forward/Reverse: Status (ZoneReadService::zoneDisplayStatus), ausstehende
 *   Veröffentlichung, recordCount, TTL, Serverzuweisung, Beschreibung und die bisherigen Aktionen.
 * - Admins sehen alle Zonen, legen an und löschen; Zone-Admins sehen ihre Zonen und bearbeiten deren Einstellungen
 *   (ohne Serverzuweisung). Vollständige Liste über die bestehende Pagination.
 * - Rechter Editor (edit_id bzw. add_new, D2) mit templates/zone_editor_form.php. Ohne JavaScript übernimmt
 *   „Typ übernehmen“ bei der Anlage den Zonentyp per POST auf diese Seite (CSRF-geprüft, keine Schreiboperation).
 * - Zonenprüfung (named-checkzone-Ergebnisse) als Hinweis mit aufklappbaren Details.
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/zones/ZoneReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
require_once __DIR__ . '/../inc/web/zone_view.php';
include __DIR__ . '/../templates/layout.php';

$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$isAdmin = $_SESSION['role'] === 'admin';
$showAdd = $isAdmin && isset($_GET['add_new']) && $_GET['add_new'] == '1';

// Alle Fachdaten über den ZoneReadService (Sichtbarkeit über ZoneAccess: admin alle, zoneadmin zugewiesene Zonen)
$actor = actorFromSession();
$zoneReadService = new ZoneReadService($pdo, new ZoneAccess($pdo));
$view = zoneViewAll($zoneReadService, $actor);
$zones = $view['zones'];
$overview = $view['overview'];

// Bearbeitung: nur für eine in der Liste sichtbare Zone; getZone prüft die Berechtigung erneut (E26)
$zone_edit = null;
$zone_edit_servers = [];
$zone_edit_assigned = [];
if ($edit_id !== null && in_array($edit_id, array_column($zones, 'id'), true)) {
    $z = $zoneReadService->getZone($actor, $edit_id);
    if ($z !== null) {
        $zone_edit = [
            'id'            => $z['id'],
            'name'          => $z['name'],
            'type'          => $z['type'],
            'ttl'           => $z['ttl'],
            'prefix_length' => $z['prefixLength'],
            'description'   => $z['description'],
            'allow_dyndns'  => $z['allowDyndns'] ? 1 : 0,
            'soa_ns'        => $z['soa']['primaryNs'],
            'soa_mail'      => $z['soa']['mail'],
            'soa_refresh'   => $z['soa']['refresh'],
            'soa_retry'     => $z['soa']['retry'],
            'soa_expire'    => $z['soa']['expire'],
            'soa_minimum'   => $z['soa']['minimum'],
            'edit_version'  => $z['editVersion'],
        ];
        foreach ($z['servers'] as $srv) {
            $zone_edit_assigned[$srv['id']] = $srv['role'] === 'master' ? 1 : 0;
        }
        foreach ($zoneReadService->listAssignableServers($actor, false) as $srv) {
            $zone_edit_servers[] = ['id' => $srv['id'], 'name' => $srv['name'], 'dns_ip4' => $srv['dnsIp4'], 'dns_ip6' => $srv['dnsIp6'], 'active' => $srv['active']];
        }
    }
}

// Anlage (nur admin): aktive Server; No-JS-Typübernahme per POST (nur Darstellung, keine Schreiboperation)
$zone_add_servers = [];
$zoneValues = [];
if ($showAdd) {
    foreach ($zoneReadService->listAssignableServers($actor, true) as $srv) {
        $zone_add_servers[] = ['id' => $srv['id'], 'name' => $srv['name'], 'dns_ip4' => $srv['dnsIp4'], 'dns_ip6' => $srv['dnsIp6']];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['apply_type'] ?? '') === '1') {
        verify_csrf_token();
        foreach (['zone_prefix', 'type', 'prefix_length', 'ttl', 'soa_domain', 'soa_mail', 'soa_refresh', 'soa_retry', 'soa_expire',
                  'soa_minimum', 'allow_dyndns', 'master_server_id', 'description'] as $k) {
            if (isset($_POST[$k]) && is_string($_POST[$k])) {
                $zoneValues[$k] = $_POST[$k];
            }
        }
        $zoneValues['server_ids'] = array_values(array_filter((array)($_POST['server_ids'] ?? []), 'is_string'));
    } elseif (isset($_GET['type']) && is_string($_GET['type'])) {
        $zoneValues['type'] = $_GET['type'];
    }
}

$addButton = $isAdmin ? '<a href="pages/zones.php?add_new=1" class="btn btn-success">+ ' . ui_e($LANG['add_zone']) . '</a>' : '';
echo ui_page_header($LANG['dns_zones'], ['subtitle' => sprintf($LANG['zones_count'], count($zones)), 'actionsHtml' => $addButton]);

// Zonenprüfung: Gesamtbewertung und Problemeinträge aus dem Core (getZoneStatusOverview), hier nur Darstellung
if ($overview['hasErrors'] || $overview['hasWarnings']) {
    $variant = $overview['hasErrors'] ? 'err' : 'warn';
    echo '<details class="dm-card dm-diagnostics dm-diagnostics-' . $variant . '"><summary class="dm-card-head">'
        . ui_icon($overview['hasErrors'] ? 'x-circle' : 'exclamation-triangle') . '<h2 class="dm-card-title">'
        . ui_e($overview['hasErrors'] ? $LANG['zone_errors'] : $LANG['zone_warnings']) . '</h2>'
        . '<span class="dm-diagnostics-more">' . ui_e($LANG['zone_diagnostics_details']) . '</span></summary><div class="dm-card-body">';
    foreach ($overview['problems'] as $problem) {
        echo '<div class="dm-diagnostic"><strong class="dm-mono">' . ui_e((string)($problem['zoneName'] ?? '(unbekannt)')) . ' @ '
            . ui_e((string)($problem['serverName'] ?? '(unbekannt)')) . '</strong><pre class="app-pre-wrap">' . ui_e((string)($problem['message'] ?? '')) . '</pre></div>';
    }
    echo '</div></details>';
} else {
    echo ui_notice('ok', $LANG['zone_ok']);
}

echo ui_card_open($LANG['zones'], ['flush' => true, 'actionsHtml' => ui_chip((string)count($zones))]);
if ($zones === []) {
    echo ui_empty_state($LANG['zones_empty'], ['icon' => 'globe2', 'actionHtml' => $addButton]);
} else {
    echo '<div class="table-responsive"><table class="dm-table dm-zones-table"><thead><tr>'
        . '<th>' . ui_e($LANG['name']) . '</th><th>' . ui_e($LANG['zone_col_status']) . '</th>'
        . '<th class="dm-col-num">' . ui_e($LANG['records']) . '</th><th class="dm-col-num">TTL</th>'
        . '<th>' . ui_e($LANG['dns_servers']) . '</th><th>' . ui_e($LANG['description']) . '</th>'
        . '<th class="dm-col-actions">' . ui_e($LANG['actions']) . '</th></tr></thead><tbody>';
    foreach (['forward' => 'Forward Lookup ' . $LANG['zones'], 'reverse' => 'Reverse Lookup ' . $LANG['zones']] as $type => $title) {
        $group = array_values(array_filter($zones, fn ($z) => $z['type'] === $type));
        if ($group === []) {
            continue;
        }
        echo '<tr class="dm-group-row"><td colspan="7">' . ui_e($title) . ' <span class="dm-group-count">' . count($group) . '</span></td></tr>';
        foreach ($group as $z) {
            $isCurrent = $edit_id === $z['id'] && $zone_edit !== null;
            echo '<tr' . ($isCurrent ? ' class="dm-current"' : '') . '>';
            echo '<td class="dm-mono dm-col-name"><a href="pages/records.php?zone_id=' . $z['id'] . '">' . ui_e($z['name']) . '</a>'
                . ($type === 'reverse' && $z['prefixLength'] !== null ? ' <span class="dm-muted">/' . (int)$z['prefixLength'] . '</span>' : '') . '</td>';
            echo '<td class="dm-col-status">' . zoneViewStatusHtml($z) . '</td>';
            echo '<td class="dm-col-num">' . (int)$z['recordCount'] . '</td>';
            echo '<td class="dm-col-num">' . ($z['ttl'] === null ? '' : (int)$z['ttl']) . '</td>';
            echo '<td class="dm-col-servers">' . zoneViewServersHtml($z) . '</td>';
            echo '<td class="dm-col-desc" title="' . ui_e((string)($z['description'] ?? '')) . '"><span class="dm-truncate">' . ui_e((string)($z['description'] ?? '')) . '</span></td>';
            echo '<td class="dm-col-actions"><a class="btn btn-sm btn-outline-secondary" href="pages/records.php?zone_id=' . $z['id'] . '">' . ui_e($LANG['records']) . '</a> '
                . '<a class="btn btn-sm btn-outline-secondary" href="pages/zones.php?edit_id=' . $z['id'] . '">' . ui_e($LANG['edit']) . '</a>';
            if ($isAdmin) {
                echo ' <form method="post" action="actions/zone_delete.php" class="d-inline confirm-delete">' . csrf_input()
                    . '<input type="hidden" name="id" value="' . $z['id'] . '">'
                    . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
                    . '</form>';
            }
            echo '</td></tr>';
        }
    }
    echo '</tbody></table></div>';
}
echo ui_card_close();

// Rechter Editor (serverseitig geöffnet, D2)
if ($showAdd || $zone_edit !== null) {
    $zoneEditorMode = $showAdd ? 'create' : 'update';
    $zoneFormId = $showAdd ? 'zoneAddForm' : 'editForm_' . $zone_edit['id'];
    echo ui_editor_open($showAdd ? $LANG['add_new_zone'] : $LANG['zone_editor_title_edit'], 'pages/zones.php', [
        'chip' => $showAdd ? '' : ($zone_edit['type'] === 'reverse' ? 'Reverse' : 'Forward'),
        'subtitle' => $showAdd ? '' : rtrim((string)$zone_edit['name'], '.'),
        'closeLabel' => $LANG['close'],
    ]);
    ob_start();
    include __DIR__ . '/../templates/zone_editor_form.php';
    echo (string)ob_get_clean();
    echo '<div class="dm-editor-note">' . ui_icon('info-circle') . '<span>' . ui_e($LANG['saved_not_published']) . '</span></div>';

    $leftHtml = '';
    if (!$showAdd && $isAdmin) {
        $leftHtml = '<form method="post" action="actions/zone_delete.php" class="confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$zone_edit['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form>';
    }
    $saveHtml = $showAdd
        ? '<button type="submit" class="btn btn-outline-primary" form="zoneAddForm" name="apply_type" value="1" formaction="pages/zones.php?add_new=1"'
            . ' formmethod="post" formnovalidate data-dm-zone-apply>' . ui_e($LANG['zone_type_apply']) . '</button>'
            . '<button type="submit" class="btn btn-success" form="zoneAddForm">' . ui_e($LANG['create_zone']) . '</button>'
        : '<button type="submit" class="btn btn-success" form="' . ui_e($zoneFormId) . '">' . ui_e($LANG['save']) . '</button>';
    echo ui_editor_close('pages/zones.php', ['cancelLabel' => $LANG['cancel'], 'leftHtml' => $leftHtml, 'saveHtml' => $saveHtml]);
}

AssetRegistry::enqueueScript('js/zone_editor.js');
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
