<?php
/**
 * Datei: inc/web/record_editor.php
 * Zweck: Web-Hilfen der Record-Verwaltung (U4-UI): gemeinsame Eingabeaufbereitung von Speichern und Vorschau,
 *        serverseitige Vorschau mit Zonendateizeile, Herkunfts- und Klassifizierungskennzeichen für die Darstellung.
 *
 * - Speichern (actions/record_add.php, record_update.php) und Vorschau (actions/record_preview.php sowie der
 *   No-JS-POST auf pages/records.php) verwenden dieselben Funktionen für DKIM-Upload und TTL-Auflösung und
 *   denselben Builder-Kontext. Die Zeile der Zonendatei liefert zone_file_record_line() aus
 *   inc/deploy/bind_file_generator.php (E3) – keine eigene DNS-Formatierung im Web-Layer.
 * - Die Vorschau ist ausschließlich lesend (ZoneAccess, ZoneReadService, RecordReadService, Builder, Validator):
 *   keine Datenbankänderung, keine Veröffentlichung, keine Toasts, keine Sitzungsänderung.
 * - Die Vorschau ist nicht autoritativ: Duplikate, DynDNS-Reservierungen und die Zonendateiprüfung bewertet
 *   weiterhin der RecordService beim Speichern.
 */

require_once __DIR__ . '/../ttl_defaults.php';
require_once __DIR__ . '/../validators.php';
require_once __DIR__ . '/../dkim_helpers.php';
require_once __DIR__ . '/../app/Actor.php';
require_once __DIR__ . '/../app/ZoneAccess.php';
require_once __DIR__ . '/../app/records/RecordContentBuilder.php';
require_once __DIR__ . '/../app/records/RecordContentParser.php';
require_once __DIR__ . '/../app/records/RecordReadService.php';
require_once __DIR__ . '/../app/zones/ZoneReadService.php';
require_once __DIR__ . '/../deploy/bind_file_generator.php';
require_once __DIR__ . '/record_messages.php';

/** Record-Typen je Zonenausrichtung (Anlage; unverändert aus dem bisherigen Formular) */
function recordEditorAllowedTypes(string $zoneType): array
{
    return $zoneType === 'reverse'
        ? ['PTR', 'NAPTR', 'TXT']
        : ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SPF', 'DKIM', 'LOC', 'CAA', 'SRV', 'NAPTR', 'URI'];
}

/**
 * TTL bei der Anlage wie bisher in record_add.php: „auto“ → Vorgabe des eingegebenen Typs; danach intval,
 * 0 bzw. leer → 3600.
 */
function recordEditorTtlCreate(array $post, string $rawType): int
{
    $ttl = $post['ttl'] ?? null;
    if ($ttl === 'auto') {
        $ttl = getAutoTTL($rawType);
    }
    return intval($ttl) ?: 3600;
}

/** TTL bei der Bearbeitung wie bisher in record_update.php: „auto“ → Vorgabe des gespeicherten Typs, sonst (int) */
function recordEditorTtlUpdate(array $post, string $type): int
{
    $ttlRaw = $post['ttl'] ?? '';
    return $ttlRaw === 'auto' ? getAutoTTL($type) : (int)$ttlRaw;
}

/**
 * Hochgeladene DKIM-Datei prüfen und Selector/Schlüssel in die Eingabe übernehmen (bisher in record_add.php).
 *
 * @return array{error: string, log: string}|array{}|null null = keine Datei; [] = übernommen; sonst Sprachschlüssel
 *         und Logtext wie bisher
 */
function recordEditorApplyDkimUpload(array $files, array &$post): ?array
{
    if (!isset($files['dkim_file']) || ($files['dkim_file']['error'] ?? null) !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($files['dkim_file']['size'] > 2048) {
        return ['error' => 'record_error_dkim_file_too_large', 'log' => 'DKIM-Upload abgebrochen: Datei überschreitet 2 KB Limit.'];
    }
    $fileExtension = strtolower((string)(pathinfo((string)$files['dkim_file']['name'])['extension'] ?? ''));
    if ($fileExtension !== 'txt') {
        return ['error' => 'record_error_dkim_invalid_extension', 'log' => "DKIM-Upload abgelehnt: Dateiendung '{$fileExtension}' ist nicht erlaubt."];
    }
    $filePath = (string)$files['dkim_file']['tmp_name'];
    $fileContent = (string)file_get_contents($filePath);
    if (preg_match('/<\?php|<script|<\/script>/', $fileContent)) {
        return ['error' => 'record_error_dkim_invalid_code', 'log' => 'DKIM-Datei abgewiesen: Enthält verbotene Zeichenfolgen.'];
    }
    $dkimData = parseDKIMFile($filePath);
    if (!$dkimData) {
        return ['error' => 'record_error_dkim_parse_failed', 'log' => 'Fehler beim Parsen der hochgeladenen DKIM-Datei.'];
    }
    $post['dkim_selector'] = $dkimData['selector'];
    $post['dkim_key'] = $dkimData['key'];
    return [];
}

/** Sprachtext als reiner Text (Meldungen enthalten teils HTML-Auszeichnung) */
function recordEditorPlainText(string $html): string
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Serverseitige Vorschau eines Formularzustands (Anlage oder Bearbeitung), ausschließlich lesend.
 *
 * Bearbeitung, wenn das Feld id gesendet wird (Bearbeitungsformular), sonst Anlage. Zone und gespeicherter
 * Record werden nur über die Read-Services mit Berechtigung in derselben Abfrage gelesen; nicht vorhandene,
 * fremde Zonen und fremde Records ergeben einheitlich status 'denied' ohne weitere Angaben.
 *
 * @return array{status: string, mode: string, zoneId: int, recordId: int, oldLine: ?string, newLine: ?string,
 *               unchanged: bool, errors: string[], hints: string[]}
 */
function recordEditorPreview(PDO $pdo, Actor $actor, ZoneAccess $zoneAccess, array $post, array $files = []): array
{
    global $LANG;

    $mode = isset($post['id']) ? 'update' : 'create';
    $zoneId = (int)($post['zone_id'] ?? 0);
    $recordId = $mode === 'update' ? (int)$post['id'] : 0;
    $result = [
        'status' => 'denied', 'mode' => $mode, 'zoneId' => $zoneId, 'recordId' => $recordId,
        'oldLine' => null, 'newLine' => null, 'unchanged' => false, 'errors' => [], 'hints' => [],
    ];

    if ($zoneId <= 0 || !$zoneAccess->canWriteZone($actor, $zoneId)) {
        return $result;
    }
    $zone = (new ZoneReadService($pdo, $zoneAccess))->getZone($actor, $zoneId);
    if ($zone === null) {
        return $result;
    }

    $rawType = (string)($post['type'] ?? '');
    $existing = null;
    $existingView = null;
    if ($mode === 'update') {
        $view = (new RecordReadService($pdo, $zoneAccess))->getZoneRecordView($actor, $zoneId);
        foreach ($view['records'] ?? [] as $rec) {
            if ($rec['id'] === $recordId) {
                $existingView = $rec;
                break;
            }
        }
        if ($existingView === null) {
            return $result;
        }
        $existing = ['name' => $existingView['name'], 'type' => $existingView['type'], 'content' => $existingView['content']];
        $result['oldLine'] = zone_file_record_line($existingView, $zone['ttl']);
    }

    $result['status'] = 'invalid';
    $input = $post;

    if ($mode === 'create') {
        // Wie record_add.php: DKIM-Datei, dann TTL „auto“ auflösen, dann zusammensetzen
        $upload = recordEditorApplyDkimUpload($files, $input);
        if ($upload !== null && isset($upload['error'])) {
            $result['errors'][] = recordEditorPlainText((string)($LANG[$upload['error']] ?? $upload['error']));
            return $result;
        }
        if (isset($input['ttl']) && $input['ttl'] === 'auto') {
            $input['ttl'] = getAutoTTL($rawType);
        }
        $built = RecordContentBuilder::build($rawType, $input, ['mode' => 'create', 'zoneName' => (string)$zone['name']]);
    } else {
        $built = RecordContentBuilder::build($rawType, $input, [
            'mode' => 'update', 'zoneName' => (string)($view['zone']['name'] ?? ''), 'existing' => $existing,
        ]);
    }

    if (isset($built['errorCode'])) {
        $result['errors'][] = recordBuildErrorText($built['errorCode']);
        return $result;
    }

    $ttl = $mode === 'create' ? recordEditorTtlCreate($input, $rawType) : recordEditorTtlUpdate($input, $built['type']);

    // Typprüfung wie im RecordService (validateDnsRecord mit dem Prüftyp des Builders)
    foreach (validateDnsRecord($built['validationType'], $built['name'], $built['content'], $ttl) as $code) {
        $result['errors'][] = recordEditorPlainText((string)($LANG[$code] ?? $LANG['generic_validation_error'] ?? $code));
    }
    // Steuerzeichen: Die Zonendatei würde nicht erzeugt (generate_zone_file)
    if (zone_file_value_has_control_chars($built['name']) || zone_file_value_has_control_chars($built['type'])
        || zone_file_value_has_control_chars($built['content'])) {
        $result['errors'][] = recordEditorPlainText((string)$LANG['record_preview_control_chars']);
    }

    // Bekannte Schutzregeln des RecordService (Darstellung; maßgeblich bleibt die Prüfung beim Speichern)
    if ($existingView !== null) {
        $nameChanged = $built['name'] !== $existingView['name'];
        if ($existingView['isGlue'] && ($nameChanged || $built['type'] !== $existingView['type'])) {
            $result['errors'][] = recordEditorPlainText((string)$LANG[$nameChanged ? 'record_error_glue_name_change' : 'record_error_glue_type_change']);
        }
        if ($existingView['isProtectedNs'] && ($nameChanged || $built['content'] !== $existingView['content'])) {
            $result['errors'][] = recordEditorPlainText((string)$LANG[$nameChanged ? 'record_error_ns_name_change' : 'record_error_ns_content_change']);
        }
        if ($existingView['isDyndns'] && ($nameChanged || $built['type'] !== $existingView['type'] || $built['content'] !== $existingView['content'])) {
            $result['errors'][] = recordEditorPlainText(sprintf((string)$LANG['record_error_dyndns_managed_record'], $existingView['name']));
        }
    }

    if ($result['errors'] !== []) {
        return $result;
    }

    $result['status'] = 'ok';
    $result['newLine'] = zone_file_record_line(
        ['name' => $built['name'], 'type' => $built['type'], 'content' => $built['content'], 'ttl' => $ttl],
        $zone['ttl']
    );
    $result['unchanged'] = $result['oldLine'] !== null && $result['oldLine'] === $result['newLine'];
    if ($mode === 'create' && ($post['auto_ptr'] ?? '') === 'on' && in_array($built['type'], ['A', 'AAAA'], true)) {
        $result['hints'][] = recordEditorPlainText((string)$LANG['record_preview_autoptr']);
    }
    return $result;
}

/** Texte des DIFF-Bausteins (U3) */
function recordEditorDiffTexts(): array
{
    global $LANG;
    return [
        'chars' => $LANG['diff_chars'], 'summaryChanged' => $LANG['diff_summary_changed'], 'summaryNone' => $LANG['diff_summary_none'],
        'showFull' => $LANG['diff_show_full'], 'showLess' => $LANG['diff_show_less'], 'label' => $LANG['diff_label'],
    ];
}

/**
 * Vorschaubereich (serverseitig, auch ohne JavaScript): Status, Fehler bzw. DIFF aus dem Vorschauergebnis.
 * Bei ungültiger Eingabe wird kein DIFF als speicherfähiges Ergebnis gezeigt. Ohne Vorschau (erster Aufruf):
 * Bearbeitung zeigt die gespeicherte Zeile unverändert, Anlage einen Hinweis.
 *
 * @param array|null $preview Ergebnis von recordEditorPreview() oder null
 */
function recordEditorPreviewHtml(?array $preview, ?string $storedLine): string
{
    global $LANG;
    $html = '<div class="dm-record-preview-status" data-dm-preview-status>';
    $diff = '';
    if ($preview === null) {
        if ($storedLine !== null) {
            $diff = ui_diff([[$storedLine, $storedLine]], recordEditorDiffTexts());
        } else {
            $html .= '<p class="dm-record-preview-empty">' . ui_e($LANG['record_preview_hint_initial']) . '</p>';
        }
    } elseif ($preview['status'] === 'ok') {
        if ($preview['unchanged']) {
            $html .= '<p class="dm-record-preview-same">' . ui_e($LANG['record_preview_unchanged']) . '</p>';
        }
        $diff = ui_diff([[$preview['oldLine'], $preview['newLine']]], recordEditorDiffTexts());
    } else {
        $html .= ui_notice('err', $preview['status'] === 'denied' ? $LANG['record_preview_denied'] : $LANG['record_preview_invalid'], ['role' => 'alert']);
        if ($preview['errors'] !== []) {
            $html .= '<ul class="dm-record-preview-errors">';
            foreach ($preview['errors'] as $error) {
                $html .= '<li>' . ui_e($error) . '</li>';
            }
            $html .= '</ul>';
        }
    }
    foreach ($preview['hints'] ?? [] as $hint) {
        $html .= '<p class="dm-record-preview-hint">' . ui_icon('info-circle') . ' ' . ui_e($hint) . '</p>';
    }
    $html .= '</div>';
    // DIFF-Bereich immer vorhanden (für die Aktualisierung mit JavaScript); ohne Inhalt verborgen
    if ($diff === '') {
        $diff = '<div class="dm-diff" aria-live="off" aria-label="' . ui_e($LANG['diff_label']) . '" data-dm-diff hidden>'
            . '<div class="dm-diff-summary"></div><div class="dm-diff-lines"></div></div>';
    }
    return $html . $diff;
}

/** Formularfelder des Editors (Rohfelder für Builder und Actions) */
const RECORD_EDITOR_FIELDS = [
    'name', 'content', 'ttl', 'fqdn_mode', 'mx_priority', 'mx_target_mode', 'srv_protocol', 'srv_priority', 'srv_weight',
    'srv_port', 'srv_target', 'srv_target_mode', 'naptr_order', 'naptr_pref', 'naptr_flags', 'naptr_service', 'naptr_regexp',
    'naptr_replacement', 'uri_service', 'uri_protocol', 'uri_priority', 'uri_weight', 'uri_target', 'edit_name_prefix',
    'dkim_selector', 'dkim_subdomain', 'dkim_key_type', 'dkim_hash_algos', 'dkim_flags', 'dkim_key', 'auto_ptr',
];

/**
 * Feldwerte des Editors: Anlage mit Vorgaben, Bearbeitung aus der Zerlegung des gespeicherten Records
 * (RecordContentParser, wie bisher im Bearbeitungsformular). Bei der No-JS-Vorschau überschreiben die gesendeten
 * Werte diese vollständig, damit noch nicht gespeicherte Eingaben erhalten bleiben.
 *
 * TTL: „auto“, wenn sie der Vorgabe des Typs entspricht; eine TTL außerhalb der Auswahlliste bleibt als eigener
 * Auswahlwert erhalten (siehe ttlCustom), statt beim Speichern still auf „auto“ zu fallen.
 */
function recordEditorFormValues(string $mode, ?array $rec, string $zoneName, ?array $post): array
{
    $values = ['ttl' => 'auto', 'fqdn_mode' => 'dot', 'mx_target_mode' => 'dot', 'srv_target_mode' => 'dot',
        'srv_protocol' => '_tcp', 'srv_priority' => '0', 'uri_protocol' => '_tcp', 'dkim_key_type' => 'rsa'];
    if ($mode === 'update' && $rec !== null) {
        $p = RecordContentParser::parse((string)$rec['type'], (string)$rec['name'], (string)$rec['content'], !empty($rec['isDkim']), $zoneName);
        $ttl = $rec['ttl'];
        $values = [
            'name' => $p['name'], 'content' => $p['content'],
            'ttl' => ($ttl === null || (int)$ttl === getAutoTTL((string)$rec['type'])) ? 'auto' : (string)(int)$ttl,
            'mx_priority' => $p['mxPriority'],
            'srv_protocol' => '_' . ($p['srvProto'] !== '' ? $p['srvProto'] : 'tcp'), 'srv_priority' => $p['srvPriority'],
            'srv_weight' => $p['srvWeight'], 'srv_port' => $p['srvPort'], 'srv_target' => $p['srvTarget'],
            'naptr_order' => $p['naptrOrder'], 'naptr_pref' => $p['naptrPref'], 'naptr_flags' => $p['naptrFlags'],
            'naptr_service' => $p['naptrService'], 'naptr_regexp' => $p['naptrRegexp'], 'naptr_replacement' => $p['naptrReplace'],
            'uri_service' => $p['uriService'], 'uri_protocol' => $p['uriProto'] !== '' ? $p['uriProto'] : '_tcp',
            'uri_priority' => $p['uriPriority'], 'uri_weight' => $p['uriWeight'], 'uri_target' => $p['uriTarget'],
            'edit_name_prefix' => $p['caaPrefix'],
            'dkim_selector' => $p['dkimSelector'], 'dkim_subdomain' => $p['dkimSubdomain'],
            'dkim_key_type' => $p['dkimKeyType'] !== '' ? $p['dkimKeyType'] : 'rsa', 'dkim_hash_algos' => $p['dkimHashAlgos'],
            'dkim_flags' => $p['dkimFlags'], 'dkim_key' => $p['dkimKey'],
        ];
    }
    if ($post !== null) {
        if ($mode === 'create') {
            unset($values['auto_ptr']);
        }
        foreach (RECORD_EDITOR_FIELDS as $field) {
            if (isset($post[$field]) && is_string($post[$field])) {
                $values[$field] = $post[$field];
            }
        }
    }
    return array_map('strval', $values);
}

/** TXT-Inhalt als zusammengesetzte Zeichenkette (alle Zeichenketten in Anführungszeichen verbunden) */
function recordEditorTxtValue(string $content): ?string
{
    if (!preg_match('/^\s*"/', $content) || !preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $content, $m)) {
        return null;
    }
    return implode('', $m[1]);
}

/**
 * Fachliche Klassifizierung (Darstellung): SPF, DKIM, DMARC nach der TXT-Semantik.
 * - SPF: Typ SPF oder TXT, dessen zusammengesetzter Wert mit "v=spf1" beginnt (gefolgt von Leerzeichen oder Ende);
 * - DMARC: TXT unter dem Namen _dmarc bzw. _dmarc.<Subdomain>, Wert beginnt mit "v=DMARC1" (gefolgt von ";",
 *   Leerzeichen oder Ende);
 * - DKIM: TXT mit DKIM-Kennzeichen des RecordReadService und DKIM-Inhalt ("v=DKIM1" am Beginn einer Zeichenkette).
 *
 * @return string[] Kennzeichen in fester Reihenfolge
 */
function recordEditorClassify(array $rec): array
{
    $type = strtoupper((string)$rec['type']);
    $classes = [];
    $txt = $type === 'TXT' || $type === 'SPF' ? recordEditorTxtValue((string)$rec['content']) : null;
    if ($type === 'SPF' || ($txt !== null && preg_match('/^v=spf1(\s|$)/i', $txt))) {
        $classes[] = 'SPF';
    }
    if ($type === 'TXT' && !empty($rec['isDkim']) && RecordContentBuilder::isDkimTxt((string)$rec['content'])) {
        $classes[] = 'DKIM';
    }
    if ($type === 'TXT' && $txt !== null && preg_match('/^_dmarc(\.|$)/i', (string)$rec['name']) && preg_match('/^v=DMARC1(;|\s|$)/i', $txt)) {
        $classes[] = 'DMARC';
    }
    return $classes;
}

/** Schutz nach den Regeln des RecordService: nicht löschbar (Glue, geschützter NS, DynDNS verwaltet) */
function recordEditorDeletable(array $rec): bool
{
    return !$rec['isGlue'] && !$rec['isProtectedNs'] && !$rec['isDyndns'];
}

/**
 * Herkunftskennzeichen (HTML): System bzw. Manuell, jeweils mit Glue; Schloss bei Schutz; DynDNS nur bei isDyndns.
 */
function recordEditorOriginHtml(array $rec): string
{
    global $LANG;
    $protected = $rec['isGlue'] || $rec['isProtectedNs'];
    if ($rec['systemManaged']) {
        $label = $rec['isGlue'] ? $LANG['record_origin_system_glue'] : $LANG['record_origin_system'];
    } else {
        $label = $rec['isGlue'] ? $LANG['record_origin_glue'] : $LANG['record_origin_manual'];
    }
    $html = '<span class="dm-origin"' . ($protected ? ' title="' . ui_e($LANG['record_origin_protected']) . '"' : '') . '>'
        . ui_chip($label, $rec['systemManaged'] ? 'accent' : 'neutral', $protected ? 'shield-lock' : null);
    if ($rec['isDyndns']) {
        $html .= ' <span title="' . ui_e($LANG['record_lock_dyndns']) . '">' . ui_chip($LANG['record_origin_dyndns'], 'info', 'shield-lock') . '</span>';
    }
    return $html . '</span>';
}

/** TTL-Anzeige wie bisher in pages/records.php */
function recordEditorTtlLabel(string $type, int $ttl): string
{
    global $LANG;
    if ($ttl === getAutoTTL($type)) {
        return $LANG['ttl_auto'];
    }
    $map = recordEditorTtlOptions();
    return $map[$ttl] ?? $ttl . ' ' . $LANG['seconds'];
}

/** Auswahlwerte der TTL (unverändert aus den bisherigen Formularen) */
function recordEditorTtlOptions(): array
{
    global $LANG;
    return [
        60 => '1 ' . $LANG['minute'], 120 => '2 ' . $LANG['minutes'], 300 => '5 ' . $LANG['minutes'],
        600 => '10 ' . $LANG['minutes'], 900 => '15 ' . $LANG['minutes'], 1800 => '30 ' . $LANG['minutes'],
        3600 => '1 ' . $LANG['hour'], 7200 => '2 ' . $LANG['hours'], 18000 => '5 ' . $LANG['hours'],
        43200 => '12 ' . $LANG['hours'], 86400 => '1 ' . $LANG['day'],
    ];
}

/**
 * Alle für den Akteur sichtbaren Zonen für die Zonenauswahl, über die vorhandene Pagination von
 * ZoneReadService::listZones() vollständig gelesen (Seiten zu je MAX_LIMIT, bis hasMore false ist).
 *
 * @return array<int, array{id: int, name: string, type: string, recordCount: int}>
 */
function recordEditorAllZones(ZoneReadService $zoneReadService, Actor $actor): array
{
    $zones = [];
    $offset = 0;
    do {
        $page = $zoneReadService->listZones($actor, ZoneReadService::MAX_LIMIT, $offset);
        foreach ($page['zones'] as $z) {
            $zones[] = ['id' => (int)$z['id'], 'name' => (string)$z['name'], 'type' => (string)$z['type'], 'recordCount' => (int)$z['recordCount']];
        }
        $offset += ZoneReadService::MAX_LIMIT;
    } while ($page['hasMore']);
    return $zones;
}
