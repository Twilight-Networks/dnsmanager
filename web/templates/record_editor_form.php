<?php
/**
 * Datei: templates/record_editor_form.php
 * Zweck: Einheitliches Formular für Anlage und Bearbeitung eines DNS-Records im rechten Editor (U4-UI, E5).
 *
 * - Alle für den RecordContentBuilder erforderlichen Felder haben einen name und werden auch ohne JavaScript
 *   gesendet (auch DKIM). Kein Zusammensetzen im Browser: Name und Inhalt setzt ausschließlich der Server zusammen.
 * - Je Record-Typ ein eigener Feldbereich (<fieldset data-dm-type>). Bereiche anderer Typen sind verborgen und
 *   deaktiviert, ihre Felder werden daher nicht gesendet. Bei der Anlage schaltet record_editor.js beim Typwechsel um;
 *   ohne JavaScript übernimmt „Vorschau“ den gewählten Typ (POST auf pages/records.php).
 * - Bearbeitung: nur der Bereich des gespeicherten Typs; Typ unverändert als verborgenes Feld. Gesperrte Felder nach
 *   den Schutzregeln des RecordService (Glue: Name; geschützter NS und DynDNS: Name und Inhalt) sind schreibgeschützt.
 * - Ziele und Felder der Actions (record_add.php, record_update.php) bleiben unverändert.
 *
 * Variablen aus pages/records.php:
 * - $editorMode 'create'|'update', $formId, $zone (id, name, type), $formValues (Feldwerte), $selectedType (Anlage),
 *   $editRec (Record mit Kennzeichen, nur Bearbeitung), $previewUrl (No-JS-Vorschau, URL der Seite)
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

$zoneType = (string)$zone['type'];
$zoneSuffix = rtrim((string)$zone['name'], '.');
$isUpdate = $editorMode === 'update';
$v = static fn (string $k): string => (string)($formValues[$k] ?? '');

if ($isUpdate) {
    $sectionType = !empty($editRec['isDkim']) ? 'DKIM' : (string)$editRec['type'];
    $types = [$sectionType];
    $lockName = $editRec['isGlue'] || $editRec['isProtectedNs'] || $editRec['isDyndns'] || $sectionType === 'DKIM' || $sectionType === 'URI';
    $lockContent = $editRec['isProtectedNs'] || $editRec['isDyndns'];
} else {
    $types = recordEditorAllowedTypes($zoneType);
    $sectionType = in_array($selectedType, $types, true) ? $selectedType : $types[0];
    $lockName = false;
    $lockContent = false;
}

$attrs = static function (array $a): string {
    $out = '';
    foreach ($a as $k => $val) {
        if ($val === true) {
            $out .= ' ' . $k;
        } elseif ($val !== false && $val !== null) {
            $out .= ' ' . $k . '="' . ui_e((string)$val) . '"';
        }
    }
    return $out;
};
/** Eingabefeld mit Beschriftung */
$input = static function (string $type, string $field, string $label, array $opts = []) use ($v, $attrs): string {
    $id = 'rf_' . $type . '_' . $field;
    $a = ['type' => $opts['inputType'] ?? 'text', 'name' => $field, 'id' => $id, 'value' => $opts['value'] ?? $v($field),
        'class' => 'form-control' . (!empty($opts['mono']) ? ' dm-mono' : ''), 'placeholder' => $opts['placeholder'] ?? null,
        'readonly' => !empty($opts['readonly']), 'autocomplete' => 'off', 'inputmode' => $opts['inputmode'] ?? null];
    $html = '<div class="dm-field ' . ($opts['wide'] ?? '') . '"><label class="form-label" for="' . $id . '">' . ui_e($label) . '</label>';
    if (isset($opts['suffix'])) {
        $html .= '<div class="input-group"><input' . $attrs($a) . '><span class="input-group-text">' . ui_e($opts['suffix']) . '</span></div>';
    } else {
        $html .= '<input' . $attrs($a) . '>';
    }
    if (!empty($opts['readonly']) && isset($opts['lockNote'])) {
        $html .= '<div class="form-text">' . ui_icon('shield-lock') . ' ' . ui_e($opts['lockNote']) . '</div>';
    }
    return $html . '</div>';
};
/** Auswahlfeld mit Beschriftung */
$select = static function (string $type, string $field, string $label, array $options, array $opts = []) use ($v): string {
    $id = 'rf_' . $type . '_' . $field;
    $current = $v($field);
    $html = '<div class="dm-field ' . ($opts['wide'] ?? '') . '"><label class="form-label" for="' . $id . '">' . ui_e($label) . '</label>'
        . '<select name="' . ui_e($field) . '" id="' . $id . '" class="form-select">';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . ui_e((string)$value) . '"' . ((string)$value === $current ? ' selected' : '') . '>' . ui_e($text) . '</option>';
    }
    return $html . '</select></div>';
};

$nameLabel = $zoneType === 'reverse' ? $LANG['record_name_reverse'] : $LANG['record_name'];
$eg = $LANG['for_example'] . ' ';
$targetModes = ['dot' => '.', $zoneSuffix . '.' => $zoneSuffix];
$protocols = ['_tcp' => 'TCP', '_udp' => 'UDP'];
$nameLockNote = $isUpdate ? ($editRec['isDyndns'] ? $LANG['record_lock_dyndns'] : ($editRec['isProtectedNs'] ? $LANG['record_lock_ns'] : ($editRec['isGlue'] ? $LANG['record_lock_glue'] : null))) : null;
$nameField = static fn (string $t, string $label = '', string $ph = '') => $input($t, 'name', $label !== '' ? $label : $nameLabel,
    ['readonly' => $lockName, 'lockNote' => $nameLockNote, 'placeholder' => $ph !== '' ? $ph : null, 'mono' => true]);
$contentField = static fn (string $t, string $label = '', string $ph = '') => $input($t, 'content', $label !== '' ? $label : $LANG['record_content'],
    ['readonly' => $lockContent, 'lockNote' => $nameLockNote, 'placeholder' => $ph !== '' ? $ph : null, 'mono' => true, 'wide' => 'dm-field-wide']);

/** Felder je Typ (Anlage und Bearbeitung) */
$fieldsFor = function (string $t) use ($input, $select, $nameField, $contentField, $isUpdate, $zoneType, $zoneSuffix, $eg, $targetModes, $protocols, $LANG, $v): string {
    switch ($t) {
        case 'A':
        case 'AAAA':
            $html = $nameField($t, '', $eg . 'www') . $contentField($t, '', $eg . ($t === 'A' ? '192.0.2.1' : '2001:db8::1'));
            if (!$isUpdate && $zoneType === 'forward') {
                $html .= '<div class="dm-field dm-field-wide"><div class="form-check"><input class="form-check-input" type="checkbox" name="auto_ptr" id="rf_' . $t . '_auto_ptr"'
                    . ($v('auto_ptr') === 'on' ? ' checked' : '') . '><label class="form-check-label" for="rf_' . $t . '_auto_ptr">' . ui_e($LANG['auto_ptr']) . '</label></div></div>';
            }
            return $html;
        case 'CNAME':
        case 'NS':
        case 'PTR':
            $html = $nameField($t) . $contentField($t, '', $eg . ($t === 'PTR' ? 'host.example.com' : 'ziel.example.com'));
            if (!$isUpdate) {
                // Ziel bei der Anlage ergänzen (wie bisher fqdn_mode); in Reverse-Zonen bisher fest „.“
                $html .= $zoneType === 'reverse'
                    ? '<input type="hidden" name="fqdn_mode" value="dot">'
                    : $select($t, 'fqdn_mode', $LANG['domain'], $targetModes);
            }
            return $html;
        case 'MX':
            return $nameField($t) . $input($t, 'mx_priority', $LANG['priority'], ['inputType' => 'number', 'placeholder' => $eg . '10'])
                . $contentField($t, $LANG['mx_target'], $eg . 'mail.example.com')
                . (!$isUpdate ? $select($t, 'mx_target_mode', $LANG['domain'], $targetModes) : '');
        case 'SRV':
            return $nameField($t, $LANG['service'], $eg . '_sip')
                . $select($t, 'srv_protocol', $LANG['protocol'], $protocols)
                . $input($t, 'srv_priority', $LANG['priority'], ['inputType' => 'number'])
                . $input($t, 'srv_weight', $LANG['weight'], ['inputType' => 'number', 'placeholder' => $eg . '10'])
                . $input($t, 'srv_port', $LANG['port'], ['inputType' => 'number', 'placeholder' => $eg . '5060'])
                . $input($t, 'srv_target', $LANG['target'], ['placeholder' => $eg . 'sip.example.com', 'mono' => true])
                . (!$isUpdate ? $select($t, 'srv_target_mode', $LANG['domain'], $targetModes) : '');
        case 'NAPTR':
        case 'CAA':
            $html = $isUpdate
                // Bearbeitung: Präfix vor dem Zonennamen (edit_name_prefix), bisheriger Name unverändert mitgesendet
                ? '<input type="hidden" name="name" value="' . ui_e($v('name')) . '">'
                    . $input($t, 'edit_name_prefix', $LANG['record_name'], ['suffix' => '.' . $zoneSuffix, 'placeholder' => $eg . 'www', 'mono' => true])
                : $nameField($t, '', $eg . '@');
            if ($t === 'CAA') {
                return $html . $contentField($t, '', $eg . '0 issue "letsencrypt.org"');
            }
            return $html . $input($t, 'naptr_order', $LANG['order'], ['inputType' => 'number', 'placeholder' => $eg . '100'])
                . $input($t, 'naptr_pref', $LANG['preference'], ['inputType' => 'number', 'placeholder' => $eg . '10'])
                . $input($t, 'naptr_flags', $LANG['flags'], ['placeholder' => $eg . 'U'])
                . $input($t, 'naptr_service', $LANG['service'], ['placeholder' => $eg . 'E2U+sip'])
                . $input($t, 'naptr_regexp', $LANG['regexp'], ['placeholder' => $eg . '!^.*$!sip:info@example.com!', 'mono' => true, 'wide' => 'dm-field-wide'])
                . $input($t, 'naptr_replacement', $LANG['replacement'], ['placeholder' => $eg . '.', 'mono' => true]);
        case 'DKIM':
            $html = $isUpdate ? '<input type="hidden" name="name" value="' . ui_e($v('name')) . '"><input type="hidden" name="is_dkim" value="1">' : '';
            $html .= $input($t, 'dkim_selector', $LANG['dkim_selector'], ['placeholder' => $eg . 'default', 'mono' => true])
                . $input($t, 'dkim_subdomain', $LANG['dkim_subdomain'], ['suffix' => '.' . $zoneSuffix,'placeholder' => $eg . 'sub', 'mono' => true])
                . $select($t, 'dkim_key_type', $LANG['dkim_key_type'] . ' (k=)', ['rsa' => 'RSA', 'ed25519' => 'Ed25519'])
                . $input($t, 'dkim_hash_algos', $LANG['dkim_hash'] . ' (h=)', ['placeholder' => $eg . 'sha256'])
                . $input($t, 'dkim_flags', $LANG['dkim_flags'] . ' (t=)', ['placeholder' => $eg . 'y'])
                . '<div class="dm-field dm-field-wide"><label class="form-label" for="rf_DKIM_dkim_key">' . ui_e($LANG['dkim_key']) . '</label>'
                . '<textarea name="dkim_key" id="rf_DKIM_dkim_key" class="form-control dm-mono" rows="6" placeholder="' . ui_e($LANG['record_dkim_key_placeholder']) . '">'
                . ui_e($v('dkim_key')) . '</textarea></div>';
            if (!$isUpdate) {
                $html .= '<div class="dm-field dm-field-wide"><label class="form-label" for="rf_DKIM_dkim_file">' . ui_e($LANG['dkim_upload_label']) . '</label>'
                    . '<input type="file" name="dkim_file" id="rf_DKIM_dkim_file" class="form-control" accept=".txt">'
                    . '<div class="form-text">' . ui_e($LANG['record_dkim_upload_hint']) . '</div></div>';
            }
            return $html;
        case 'URI':
            $html = $isUpdate ? '<input type="hidden" name="name" value="' . ui_e($v('name')) . '">' : '';
            return $html . $input($t, 'uri_service', $LANG['service'], ['placeholder' => $eg . '_ftp', 'mono' => true])
                . $select($t, 'uri_protocol', $LANG['protocol'], $protocols)
                . $input($t, 'uri_priority', $LANG['priority'], ['inputType' => 'number', 'placeholder' => $eg . '10'])
                . $input($t, 'uri_weight', $LANG['weight'], ['inputType' => 'number', 'placeholder' => $eg . '1'])
                . $input($t, 'uri_target', $LANG['uri_target'], ['placeholder' => $eg . 'ftp://ftp.example.com/public', 'mono' => true, 'wide' => 'dm-field-wide']);
        default: // TXT, SPF, LOC
            return $nameField($t) . $contentField($t, '', $t === 'LOC' ? $eg . '52 31 0 N 13 24 0 E 34m' : $eg . '"v=spf1 -all"');
    }
};

$typeInfo = static function (string $t) use ($zoneType, $LANG): string {
    $key = 'record_info_' . ($zoneType === 'reverse' ? 'reverse' : 'forward') . '_' . strtolower($t);
    if (!isset($LANG[$key])) {
        return '';
    }
    // Sprachtexte enthalten Auszeichnung (<strong>, <br>) aus den Sprachdateien
    return '<details class="dm-type-info"><summary>' . ui_icon('info-circle') . ' ' . ui_e($LANG['record_type_info_button']) . '</summary>'
        . '<div class="dm-type-info-body">' . $LANG[$key] . '</div></details>';
};
?>
<form method="post"
      action="<?= $isUpdate ? 'actions/record_update.php' : 'actions/record_add.php' ?>"
      id="<?= ui_e($formId) ?>"
      class="dm-record-form"
      data-dm-record-form
      data-dm-preview-url="actions/record_preview.php"
      <?= $isUpdate ? '' : 'enctype="multipart/form-data"' ?>>
    <?= csrf_input() ?>
    <?php if ($isUpdate): ?>
        <input type="hidden" name="id" value="<?= (int)$editRec['id'] ?>">
    <?php endif; ?>
    <input type="hidden" name="zone_id" value="<?= (int)$zone['id'] ?>">

    <div class="dm-field-grid">
        <?php if ($isUpdate): ?>
            <input type="hidden" name="type" value="<?= ui_e((string)$editRec['type']) ?>">
            <div class="dm-field">
                <span class="form-label"><?= ui_e($LANG['record_type']) ?></span>
                <div class="dm-static-value"><?= ui_chip($sectionType, 'accent') ?></div>
            </div>
        <?php else: ?>
            <div class="dm-field">
                <label class="form-label" for="record_type"><?= ui_e($LANG['record_type']) ?></label>
                <select name="type" id="record_type" class="form-select" data-dm-type-select>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= ui_e($t) ?>"<?= $t === $sectionType ? ' selected' : '' ?>><?= ui_e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><div class="form-text"><?= ui_e($LANG['record_type_nojs_hint']) ?></div></noscript>
            </div>
        <?php endif; ?>
        <?php
        // TTL (gemeinsam für alle Typen)
        $ttlCurrent = $v('ttl');
        ?>
        <div class="dm-field">
            <label class="form-label" for="rf_ttl"><?= ui_e($LANG['ttl']) ?></label>
            <select name="ttl" id="rf_ttl" class="form-select">
                <option value="auto"<?= $ttlCurrent === 'auto' ? ' selected' : '' ?>><?= ui_e($LANG['ttl_auto']) ?></option>
                <?php $ttlOptions = recordEditorTtlOptions(); ?>
                <?php foreach ($ttlOptions as $val => $label): ?>
                    <option value="<?= $val ?>"<?= $ttlCurrent === (string)$val ? ' selected' : '' ?>><?= ui_e($label) ?></option>
                <?php endforeach; ?>
                <?php if (ctype_digit($ttlCurrent) && !isset($ttlOptions[(int)$ttlCurrent])): ?>
                    <?php // Gespeicherte TTL außerhalb der Auswahl bleibt wählbar (kein stilles Zurückfallen auf „auto“) ?>
                    <option value="<?= ui_e($ttlCurrent) ?>" selected><?= ui_e($ttlCurrent . ' ' . $LANG['seconds']) ?></option>
                <?php endif; ?>
            </select>
        </div>
    </div>

    <?php foreach ($types as $t): $active = $t === $sectionType; ?>
        <fieldset class="dm-type-fields" data-dm-type="<?= ui_e($t) ?>"<?= $active ? '' : ' hidden disabled' ?>>
            <legend class="visually-hidden"><?= ui_e($t) ?></legend>
            <?= $typeInfo($t) ?>
            <div class="dm-field-grid"><?= $fieldsFor($t) ?></div>
        </fieldset>
    <?php endforeach; ?>
</form>
