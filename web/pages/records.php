<?php
/**
 * Datei: records.php
 * Zweck: Record-Verwaltung einer DNS-Zone im A+-Design (U4-UI).
 * Details:
 * - Zonenauswahl über ZoneReadService::listZones() (nur berechtigte Zonen, mit recordCount; alle Seiten der
 *   vorhandenen Pagination werden gelesen, sodass jede berechtigte Zone erreichbar bleibt).
 * - Tabelle mit Herkunft (System, System · Glue, Manuell, Glue; Schloss bei Schutz; DynDNS nur bei isDyndns) und
 *   fachlicher Klassifizierung (SPF, DKIM, DMARC nach TXT-Semantik).
 * - Löschen und Auswahl nur, wo der RecordService es zulässt (nicht bei Glue, geschütztem NS, DynDNS).
 *   Einzellöschen mit Bestätigung, Massenlöschung über bulk_ids (record_delete.php, unverändert).
 * - Rechter Editor (edit_id bzw. add_new) mit einheitlichem Formular (templates/record_editor_form.php) und
 *   serverseitiger Vorschau/DIFF. Ohne JavaScript: „Vorschau“ sendet das Formular per POST an diese Seite
 *   (CSRF-geprüft, dieselbe Vorschaufunktion wie actions/record_preview.php, keine Schreibzugriffe).
 * Zugriff: requireRole(['admin', 'zoneadmin']) und Zonenberechtigung (ZoneAccess); fremde Zonen 403.
 * Aufruf: GET /pages/records.php?zone_id={id}[&edit_id={id}|&add_new=1[&type=X]]; POST mit preview=1 (Vorschau)
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/ttl_defaults.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/zones/ZoneReadService.php';
require_once __DIR__ . '/../inc/app/records/RecordReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
require_once __DIR__ . '/../inc/web/record_editor.php';
include __DIR__ . '/../templates/layout.php';

// Zugriffskontrolle: Nur Admins oder Zone-Admins erlaubt
requireRole(['admin', 'zoneadmin']);

// Eingangsparameter lesen
$zone_id = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;
$edit_record_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$show_add_form = isset($_GET['add_new']) && $_GET['add_new'] == '1';

// Zone und Records über die Read-Services (Berechtigung nach ZoneAccess in derselben Abfrage).
// Nicht vorhandene und nicht berechtigte Zonen werden wie bisher gleich behandelt (403).
$actor = actorFromSession();
$zoneAccess = new ZoneAccess($pdo);
$zoneReadService = new ZoneReadService($pdo, $zoneAccess);
$zoneData = $zoneReadService->getZone($actor, $zone_id);
$recordView = $zoneData === null ? null : (new RecordReadService($pdo, $zoneAccess))->getZoneRecordView($actor, $zone_id);

if ($zoneData === null || $recordView === null) {
    logAccessDenied('records', 'Benutzer-ID ' . ($_SESSION['user_id'] ?? 'unbekannt') . ' versucht unbefugten Zugriff auf Zone-ID ' . $zone_id);
    http_response_code(403);
    exit('Zugriff verweigert: Keine Berechtigung für diese Zone.');
}

$zone = ['id' => $zoneData['id'], 'name' => $zoneData['name'], 'type' => $zoneData['type']];
$zoneUrl = 'pages/records.php?zone_id=' . (int)$zone['id'];
$records = $recordView['records'];
$recordsById = array_column($records, null, 'id');

// Editor: Bearbeitung (edit_id) oder Anlage (add_new)
$editRec = ($edit_record_id !== null && isset($recordsById[$edit_record_id])) ? $recordsById[$edit_record_id] : null;
$editorMode = $editRec !== null ? 'update' : ($show_add_form ? 'create' : null);

// No-JS-Vorschau: POST des Editorformulars auf diese Seite (nur passend zu Zone und Editor der URL)
$preview = null;
$postedValues = null;
if ($editorMode !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['preview'] ?? '') === '1') {
    verify_csrf_token();
    $postMatches = (int)($_POST['zone_id'] ?? 0) === (int)$zone['id']
        && ($editorMode === 'update' ? (int)($_POST['id'] ?? 0) === (int)$editRec['id'] : !isset($_POST['id']));
    if ($postMatches) {
        $preview = recordEditorPreview($pdo, $actor, $zoneAccess, $_POST, $_FILES);
        if ($preview['status'] === 'denied') {
            logAccessDenied('records', 'Benutzer-ID ' . $actor->userId . ' – Vorschau ohne Berechtigung (Zone-ID ' . $zone_id . ')');
            http_response_code(403);
            exit('Zugriff verweigert: Keine Berechtigung für diese Zone.');
        }
        $postedValues = $_POST;
    }
}

// Gruppierung (Darstellung): NS, Mail (MX und Klassifizierung SPF/DKIM/DMARC), übrige Einträge
$groups = ['NS' => [], 'MAIL' => [], 'OTHER' => []];
foreach ($records as $i => $r) {
    $records[$i]['classes'] = recordEditorClassify($r);
    if ($r['type'] === 'NS') {
        $groups['NS'][] = $records[$i];
    } elseif ($r['type'] === 'MX' || $records[$i]['classes'] !== []) {
        $groups['MAIL'][] = $records[$i];
    } else {
        $groups['OTHER'][] = $records[$i];
    }
}
$groupTitles = [
    'NS' => $LANG['records_ns'],
    'MAIL' => $LANG['records_mail'],
    'OTHER' => $zone['type'] === 'reverse' ? $LANG['records_host_reverse'] : $LANG['records_host'],
];

// Zonenauswahl: alle berechtigten Zonen (vollständig über die Pagination von listZones())
$allZones = recordEditorAllZones($zoneReadService, $actor);
$zoneSelect = '<form method="get" action="pages/records.php" class="dm-zone-select" data-dm-zone-select>'
    . '<label class="visually-hidden" for="dmZoneSelect">' . ui_e($LANG['zone_select_label']) . '</label>'
    . '<select name="zone_id" id="dmZoneSelect" class="form-select">';
foreach ($allZones as $z) {
    $zoneSelect .= '<option value="' . $z['id'] . '"' . ($z['id'] === (int)$zone['id'] ? ' selected' : '') . '>'
        . ui_e(sprintf($LANG['zone_select_option'], rtrim($z['name'], '.'), $z['recordCount'])) . '</option>';
}
$zoneSelect .= '</select><button type="submit" class="btn btn-outline-secondary" data-dm-zone-open>' . ui_e($LANG['zone_select_open']) . '</button></form>';

$addButton = '<a href="' . ui_e($zoneUrl) . '&amp;add_new=1" class="btn btn-success">' . '+ ' . ui_e($LANG['add_record']) . '</a>';

echo ui_page_header(sprintf($LANG['dns_records_for'], rtrim($zone['name'], '.')), [
    'subtitle' => sprintf($LANG['records_count'], count($records)),
    'actionsHtml' => $zoneSelect . $addButton,
]);

// Zonendiagnosen dieser Zone (ZoneReadService, Berechtigung in derselben Abfrage)
$diagnostics = [];
foreach ($zoneReadService->listZoneDiagnostics($actor) as $entry) {
    if ($entry['zoneId'] === (int)$zone['id'] && $entry['checkType'] === 'zone_status') {
        $diagnostics[] = $entry;
    }
}
if ($diagnostics !== []) {
    echo '<details class="dm-card dm-diagnostics"><summary class="dm-card-head"><h2 class="dm-card-title">'
        . ui_e($LANG['zone_diagnostic_title']) . '</h2>';
    foreach ($diagnostics as $d) {
        $variant = match ($d['status']) { 'ok' => 'ok', 'error', 'not_found' => 'err', default => 'warn' };
        echo ' ' . ui_chip((string)($d['serverName'] ?? '(unbekannt)'), $variant, $variant === 'ok' ? 'check-circle' : ($variant === 'err' ? 'x-circle' : 'exclamation-triangle'));
    }
    echo '</summary><div class="dm-card-body">';
    foreach ($diagnostics as $d) {
        // U0-B2: Diagnosen ohne Meldungstext (NULL) ergeben einen leeren Text statt einer Deprecation
        echo '<div class="dm-diagnostic"><strong>' . ui_e((string)($d['serverName'] ?? '(unbekannt)')) . '</strong>'
            . '<pre class="app-pre-wrap">' . ui_e((string)($d['message'] ?? '')) . '</pre></div>';
    }
    echo '</div></details>';
}

// Massenlöschung (bestehender Vertrag: record_delete.php mit bulk_ids, Bestätigung)
$bulkForm = '<form id="bulkDeleteForm" method="post" action="actions/record_delete.php" class="d-inline confirm-delete">' . csrf_input()
    . '<input type="hidden" name="bulk_ids" value="" data-dm-bulk-ids>'
    . ui_confirm_delete_button($LANG['delete_records'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
    . '</form>';

echo ui_card_open($LANG['records'], ['flush' => true, 'actionsHtml' => ui_chip((string)count($records))]);
echo ui_bulk_bar('records', $LANG['bulk_selected'], $bulkForm);

if ($records === []) {
    echo ui_empty_state($LANG['record_empty'], ['icon' => 'inbox', 'actionHtml' => $addButton]);
} else {
    echo '<div class="table-responsive"><table class="dm-table dm-records-table"><thead><tr>'
        . '<th class="dm-col-select">' . ui_select_all('records', $LANG['select_all']) . '</th>'
        . '<th>' . ui_e($LANG['name']) . '</th><th>' . ui_e($LANG['record_type']) . '</th>'
        . '<th>' . ui_e($LANG['record_content']) . '</th><th>' . ui_e($LANG['ttl']) . '</th>'
        . '<th>' . ui_e($LANG['record_col_origin']) . '</th><th class="dm-col-actions">' . ui_e($LANG['actions']) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($groups as $key => $groupRecords) {
        if ($groupRecords === []) {
            continue;
        }
        echo '<tr class="dm-group-row"><td colspan="7">' . ui_e($groupTitles[$key]) . ' <span class="dm-group-count">' . count($groupRecords) . '</span></td></tr>';
        foreach ($groupRecords as $r) {
            $deletable = recordEditorDeletable($r);
            $isCurrent = $editRec !== null && $editRec['id'] === $r['id'];
            echo '<tr' . ($isCurrent ? ' class="dm-current"' : '') . '>';
            echo '<td class="dm-col-select">' . ($deletable ? ui_select_row('records', (string)$r['id'], $LANG['select_row'] . ' ' . $r['name'])
                : '<span class="dm-lock" title="' . ui_e($LANG['record_not_deletable']) . '">' . ui_icon('shield-lock') . '</span>') . '</td>';
            echo '<td class="dm-mono dm-col-name">' . ui_e($r['name']) . '</td>';
            echo '<td>' . ui_chip($r['type'], 'accent');
            foreach ($r['classes'] as $class) {
                echo ' ' . ui_chip($class, 'info');
            }
            echo '</td>';
            echo '<td class="dm-mono dm-col-content" title="' . ui_e($r['content']) . '"><span class="dm-truncate">' . ui_e($r['content']) . '</span></td>';
            echo '<td class="dm-col-ttl">' . ui_e(recordEditorTtlLabel($r['type'], (int)$r['ttl'])) . '</td>';
            echo '<td>' . recordEditorOriginHtml($r) . '</td>';
            echo '<td class="dm-col-actions"><a class="btn btn-sm btn-outline-secondary" href="' . ui_e($zoneUrl . '&edit_id=' . $r['id']) . '">'
                . ui_e($LANG['edit']) . '</a>';
            if ($deletable) {
                echo ' <form method="post" action="actions/record_delete.php" class="d-inline confirm-delete">' . csrf_input()
                    . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                    . '<input type="hidden" name="zone_id" value="' . (int)$zone['id'] . '">'
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
if ($editorMode !== null) {
    $formId = $editorMode === 'update' ? 'editForm_' . $editRec['id'] : 'recordForm';
    $formValues = recordEditorFormValues($editorMode, $editRec, (string)$zone['name'], $postedValues);
    $selectedType = $editorMode === 'create'
        ? (string)($postedValues['type'] ?? ($_GET['type'] ?? ''))
        : (string)$editRec['type'];
    $previewUrl = $zoneUrl . ($editorMode === 'update' ? '&edit_id=' . $editRec['id'] : '&add_new=1');
    $storedLine = $editorMode === 'update' ? zone_file_record_line($editRec, $zoneData['ttl']) : null;

    $title = $editorMode === 'update' ? $LANG['record_editor_title_edit'] : $LANG['add_record'];
    echo ui_editor_open($title, $zoneUrl, [
        'chip' => $editorMode === 'update' ? (!empty($editRec['isDkim']) ? 'DKIM' : $editRec['type']) : '',
        'subtitle' => ($editorMode === 'update' ? $editRec['name'] . ' · ' : '') . sprintf($LANG['record_editor_zone'], rtrim($zone['name'], '.')),
        'closeLabel' => $LANG['close'],
    ]);
    if ($editorMode === 'update') {
        if ($editRec['isDyndns']) {
            echo ui_notice('info', $LANG['record_lock_dyndns'], ['icon' => 'shield-lock']);
        } elseif ($editRec['isProtectedNs']) {
            echo ui_notice('info', $LANG['record_lock_ns'], ['icon' => 'shield-lock']);
        } elseif ($editRec['isGlue']) {
            echo ui_notice('info', $LANG['record_lock_glue'], ['icon' => 'shield-lock']);
        }
    }
    ob_start();
    include __DIR__ . '/../templates/record_editor_form.php';
    echo ui_editor_section($LANG['record_editor_section_input'], (string)ob_get_clean());

    $editorTexts = recordEditorDiffTexts() + [
        'pending' => $LANG['record_preview_pending'], 'failed' => $LANG['record_preview_failed'],
        'invalid' => $LANG['record_preview_invalid'], 'denied' => $LANG['record_preview_denied'],
        'unchanged' => $LANG['record_preview_unchanged'], 'initial' => $LANG['record_preview_hint_initial'],
    ];
    echo ui_editor_section($LANG['record_editor_section_preview'],
        '<div class="dm-record-preview" data-dm-record-preview aria-live="polite">' . recordEditorPreviewHtml($preview, $storedLine) . '</div>'
        . '<script type="application/json" id="dmRecordEditorTexts">'
        . json_encode($editorTexts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>'
        // Vorlagen für die Darstellung mit JavaScript (Symbole aus dem Sprite, Texte per textContent)
        . '<template id="dmPreviewNoticeTpl">' . ui_notice('err', '', ['role' => 'alert']) . '</template>'
        . '<template id="dmPreviewHintTpl"><p class="dm-record-preview-hint">' . ui_icon('info-circle') . ' <span></span></p></template>');
    echo '<div class="dm-editor-note">' . ui_icon('info-circle') . '<span>' . ui_e($LANG['record_preview_disclaimer']) . '</span></div>';
    echo '<div class="dm-editor-note">' . ui_icon('info-circle') . '<span>' . ui_e($LANG['saved_not_published']) . '</span></div>';

    $leftHtml = '';
    if ($editorMode === 'update' && recordEditorDeletable($editRec)) {
        $leftHtml = '<form method="post" action="actions/record_delete.php" class="confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . (int)$editRec['id'] . '">'
            . '<input type="hidden" name="zone_id" value="' . (int)$zone['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form>';
    }
    echo ui_editor_close($zoneUrl, [
        'cancelLabel' => $LANG['cancel'],
        'leftHtml' => $leftHtml,
        'saveHtml' => '<button type="submit" class="btn btn-outline-primary" form="' . ui_e($formId) . '" name="preview" value="1"'
            . ' formaction="' . ui_e($previewUrl) . '" formmethod="post" data-dm-preview-button>' . ui_e($LANG['record_preview_button']) . '</button>'
            . '<button type="submit" class="btn btn-success" form="' . ui_e($formId) . '">' . ui_e($LANG['save']) . '</button>',
    ]);
}

AssetRegistry::enqueueScript('js/diff.js');
AssetRegistry::enqueueScript('js/record_editor.js');
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
