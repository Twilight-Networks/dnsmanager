<?php
/**
 * Datei: templates/zone_editor_form.php
 * Zweck: Formular für Anlage (nur admin) und Bearbeitung einer DNS-Zone im rechten Editor (U5a).
 *
 * - Ziele, Feldnamen, CSRF und edit_version (S21) unverändert gegenüber den bisherigen Formularen
 *   (zone_add_form.php, zone_edit_form.php).
 * - Anlage: Typabhängige Felder (Präfixlänge, SOA-Domain, angezeigter Primary NS) werden je Zonentyp ein- bzw.
 *   ausgeblendet, aber wie bisher immer gesendet; der ZoneService wertet sie nur für Reverse-Zonen aus. Ohne
 *   JavaScript übernimmt „Typ übernehmen“ den gewählten Typ per POST auf pages/zones.php (keine Schreiboperation)
 *   und alle eingegebenen Werte bleiben erhalten.
 * - Bearbeitung: Serverzuweisung nur für admin als Eingabe (server_ids[], master_server_id); zoneadmin sieht die
 *   vorhandene Zuweisung nur als Anzeige (der ZoneService ignoriert Serverangaben eines zoneadmin).
 * - Die Prüfung der Master-Auswahl im Browser (zone_editor.js) ist nur eine Bedienhilfe; maßgeblich ist der ZoneService.
 *
 * Variablen aus pages/zones.php:
 * - $zoneEditorMode 'create'|'update', $zoneFormId, $isAdmin
 * - Anlage: $zoneValues (Feldwerte, auch aus dem No-JS-POST), $zone_add_servers
 * - Bearbeitung: $zone_edit, $zone_edit_servers, $zone_edit_assigned
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

$isCreate = $zoneEditorMode === 'create';
$val = static fn (string $k, $default = ''): string => (string)($zoneValues[$k] ?? $default);

/** Eingabefeld (Raster des Editors) */
$field = static function (string $name, string $label, string $value, array $opts = []): string {
    $id = $opts['id'] ?? 'zf_' . preg_replace('/[^a-z0-9_]/', '_', $name);
    $attrs = '';
    foreach (['type' => $opts['type'] ?? 'text', 'placeholder' => $opts['placeholder'] ?? null] as $k => $v) {
        if ($v !== null) { $attrs .= ' ' . $k . '="' . ui_e($v) . '"'; }
    }
    if (!empty($opts['readonly'])) { $attrs .= ' readonly'; }
    if (!empty($opts['required'])) { $attrs .= ' required'; }
    if (!empty($opts['data'])) { $attrs .= ' ' . $opts['data']; }
    return '<div class="dm-field ' . ($opts['wide'] ?? '') . '"' . ($opts['wrap'] ?? '') . '><label class="form-label" for="' . ui_e($id) . '">' . ui_e($label) . '</label>'
        . (isset($opts['suffixHtml']) ? '<div class="input-group">' : '')
        . '<input name="' . ui_e($name) . '" id="' . ui_e($id) . '" class="form-control' . (!empty($opts['mono']) ? ' dm-mono' : '') . '" value="' . ui_e($value) . '"' . $attrs . '>'
        . ($opts['suffixHtml'] ?? '') . (isset($opts['suffixHtml']) ? '</div>' : '')
        . (isset($opts['hint']) ? '<div class="form-text">' . ui_e($opts['hint']) . '</div>' : '') . '</div>';
};

$servers = $isCreate ? ($zone_add_servers ?? []) : ($zone_edit_servers ?? []);
$assigned = $isCreate ? [] : ($zone_edit_assigned ?? []);
if ($isCreate) {
    // Anlage: Auswahl aus dem No-JS-POST übernehmen
    foreach ((array)($zoneValues['server_ids'] ?? []) as $sid) {
        if (is_string($sid) && ctype_digit($sid)) {
            $assigned[(int)$sid] = 0;
        }
    }
}
$masterChecked = static function (int $sid) use ($assigned, $isCreate, $zoneValues): bool {
    if ($isCreate) {
        return isset($zoneValues['master_server_id']) && (string)$zoneValues['master_server_id'] === (string)$sid;
    }
    return ($assigned[$sid] ?? 0) === 1;
};

$type = $isCreate ? $val('type', 'forward') : (string)$zone_edit['type'];
if ($isCreate && !in_array($type, ['forward', 'reverse_ipv4', 'reverse_ipv6'], true)) {
    $type = 'forward';
}
$isReverseCreate = $isCreate && $type !== 'forward';
$suffix = ['forward' => '', 'reverse_ipv4' => '.in-addr.arpa', 'reverse_ipv6' => '.ip6.arpa'][$type] ?? '';
$prefixPlaceholder = ['forward' => 'example.com', 'reverse_ipv4' => '1.168.192', 'reverse_ipv6' => 'b.a.9.8.7'][$type] ?? 'example.com';
$prefixLength = $val('prefix_length');
if ($isReverseCreate && $prefixLength === '') {
    // Bedienhilfe wie bisher im Formularskript: Vorgabe der Präfixlänge je Reverse-Typ
    $prefixLength = $type === 'reverse_ipv6' ? '56' : '24';
}
?>
<form method="post"
      action="<?= $isCreate ? rtrim(BASE_URL, '/') . '/actions/zone_add.php' : 'actions/zone_update.php' ?>"
      id="<?= ui_e($zoneFormId) ?>"
      class="dm-zone-form"
      data-dm-zone-form="<?= $isCreate ? 'create' : 'update' ?>"
      data-zone-type="<?= ui_e($type) ?>"
      <?= $isCreate ? '' : 'data-zone-name="' . ui_e(rtrim((string)$zone_edit['name'], '.')) . '"' ?>>
    <?= csrf_input() ?>
    <?php if (!$isCreate): ?>
        <input type="hidden" name="id" value="<?= (int)$zone_edit['id'] ?>">
        <input type="hidden" name="edit_version" value="<?= ui_e((string)($zone_edit['edit_version'] ?? '')) ?>">
    <?php endif; ?>

    <?php if ($isCreate): ?>
        <div class="dm-field-grid">
            <div class="dm-field dm-field-wide">
                <label class="form-label" for="zf_zone_prefix"><?= ui_e($LANG['zone_name']) ?></label>
                <div class="input-group">
                    <input type="text" name="zone_prefix" id="zf_zone_prefix" class="form-control dm-mono" required
                           placeholder="<?= ui_e($LANG['for_example'] . ' ' . $prefixPlaceholder) ?>" value="<?= ui_e($val('zone_prefix')) ?>">
                    <span class="input-group-text dm-mono" id="zf_zone_suffix" data-dm-zone-suffix<?= $suffix === '' ? ' hidden' : '' ?>><?= ui_e($suffix) ?></span>
                </div>
            </div>
            <div class="dm-field">
                <label class="form-label" for="zf_type"><?= ui_e($LANG['zone_type']) ?></label>
                <select name="type" id="zf_type" class="form-select" required data-dm-zone-type>
                    <?php foreach (['forward' => $LANG['zone_type_forward'], 'reverse_ipv4' => $LANG['zone_type_reverse4'], 'reverse_ipv6' => $LANG['zone_type_reverse6']] as $v => $label): ?>
                        <option value="<?= $v ?>"<?= $type === $v ? ' selected' : '' ?>><?= ui_e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><div class="form-text"><?= ui_e($LANG['zone_type_nojs_hint']) ?></div></noscript>
            </div>
            <?= $field('prefix_length', $LANG['prefix_length'], $prefixLength, ['type' => 'number', 'placeholder' => $LANG['for_example'] . ' 24',
                'wrap' => ' data-dm-zone-reverse-only' . ($isReverseCreate ? '' : ' hidden')]) ?>
            <?= $field('ttl', 'TTL (' . $LANG['seconds'] . ')', $val('ttl', '86400'), ['type' => 'number']) ?>
        </div>
    <?php else: ?>
        <div class="dm-field-grid">
            <?= $field('ttl', 'TTL', (string)(int)$zone_edit['ttl'], ['type' => 'number']) ?>
            <?php if ($zone_edit['type'] === 'reverse'): ?>
                <?= $field('prefix_length', $LANG['prefix_length'], (string)(int)$zone_edit['prefix_length'], ['type' => 'number']) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <fieldset class="dm-form-group">
        <legend class="dm-editor-section-label"><?= ui_e($LANG['soa_settings']) ?></legend>
        <div class="dm-field-grid">
            <?php if ($isCreate): ?>
                <?= $field('soa_ns_display', $LANG['soa_ns'], '', ['id' => 'zf_soa_ns', 'placeholder' => $LANG['auto_filled'], 'readonly' => true, 'mono' => true,
                    'wrap' => ' data-dm-zone-forward-only' . ($isReverseCreate ? ' hidden' : '')]) ?>
                <?= $field('soa_domain', $LANG['soa_domain'], $val('soa_domain'), ['placeholder' => $LANG['zone_prefix_placeholder'], 'mono' => true,
                    'wrap' => ' data-dm-zone-reverse-only' . ($isReverseCreate ? '' : ' hidden')]) ?>
                <?= $field('soa_mail', $LANG['soa_mail'], $val('soa_mail'), ['required' => true, 'placeholder' => $LANG['for_example'] . ' hostmaster.example.com.', 'mono' => true]) ?>
                <?= $field('soa_refresh', $LANG['soa_refresh'], $val('soa_refresh', '3600'), ['type' => 'number']) ?>
                <?= $field('soa_retry', $LANG['soa_retry'], $val('soa_retry', '1200'), ['type' => 'number']) ?>
                <?= $field('soa_expire', $LANG['soa_expire'], $val('soa_expire', '1209600'), ['type' => 'number']) ?>
                <?= $field('soa_minimum', $LANG['soa_minimum'], $val('soa_minimum', '3600'), ['type' => 'number']) ?>
            <?php else: ?>
                <?= $field('soa_ns', $LANG['soa_ns'], (string)$zone_edit['soa_ns'], ['readonly' => $zone_edit['type'] === 'forward', 'mono' => true,
                    'hint' => $zone_edit['type'] === 'forward' ? $LANG['zone_soa_ns_forward_hint'] : null]) ?>
                <?= $field('soa_mail', $LANG['soa_mail'], (string)$zone_edit['soa_mail'], ['mono' => true]) ?>
                <?= $field('soa_refresh', $LANG['soa_refresh'], (string)(int)$zone_edit['soa_refresh'], ['type' => 'number']) ?>
                <?= $field('soa_retry', $LANG['soa_retry'], (string)(int)$zone_edit['soa_retry'], ['type' => 'number']) ?>
                <?= $field('soa_expire', $LANG['soa_expire'], (string)(int)$zone_edit['soa_expire'], ['type' => 'number']) ?>
                <?= $field('soa_minimum', $LANG['soa_minimum'], (string)(int)$zone_edit['soa_minimum'], ['type' => 'number']) ?>
            <?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="dm-form-group">
        <legend class="dm-editor-section-label"><?= ui_e($LANG['assign_dns_servers']) ?></legend>
        <?php if ($isCreate || $isAdmin): ?>
            <div class="table-responsive">
                <table class="dm-table dm-server-assign">
                    <thead><tr><th class="dm-col-select"><?= ui_e($LANG['assign']) ?></th><th class="dm-col-select">Master</th>
                        <th><?= ui_e($LANG['name']) ?></th><th>DNS-IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($servers as $srv): ?>
                        <?php
                        $sid = (int)$srv['id'];
                        $active = $srv['active'] ?? true;
                        $checked = array_key_exists($sid, $assigned);
                        ?>
                        <tr<?= $active ? '' : ' class="dm-muted-row" title="' . ui_e($LANG['server_ignored_on_publish']) . '"' ?>>
                            <td class="dm-col-select"><input type="checkbox" class="form-check-input" name="server_ids[]" id="zf_server_<?= $sid ?>" value="<?= $sid ?>"
                                <?= $checked ? ' checked' : '' ?><?= $active ? '' : ' disabled' ?> aria-label="<?= ui_e($LANG['assign'] . ' ' . $srv['name']) ?>"></td>
                            <td class="dm-col-select"><input type="radio" class="form-check-input" name="master_server_id" id="zf_master_<?= $sid ?>" value="<?= $sid ?>"
                                <?= $masterChecked($sid) ? ' checked' : '' ?><?= $active ? '' : ' disabled' ?> aria-label="<?= ui_e('Master ' . $srv['name']) ?>"
                                data-server-name="<?= ui_e((string)$srv['name']) ?>"></td>
                            <td class="dm-mono"><label for="zf_server_<?= $sid ?>"><?= ui_e((string)$srv['name']) ?></label><?= $active ? '' : ' ' . ui_chip($LANG['inactive']) ?></td>
                            <td class="dm-mono"><?= ui_e((string)$srv['dns_ip4']) ?><?= !empty($srv['dns_ip6']) ? '<br>' . ui_e((string)$srv['dns_ip6']) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-text"><?= ui_e($LANG['assign_hint']) ?></div>
            <div class="dm-zone-form-check" data-dm-zone-check role="alert" hidden></div>
        <?php else: ?>
            <?php // zoneadmin: vorhandene Zuweisung nur als Anzeige; keine Eingabefelder (Serverzuweisung nur durch admin) ?>
            <ul class="dm-server-list">
                <?php foreach ($servers as $srv): ?>
                    <?php if (!array_key_exists((int)$srv['id'], $assigned)) { continue; } ?>
                    <li><?= ui_chip(($assigned[(int)$srv['id']] === 1 ? 'Master' : 'Slave'), $assigned[(int)$srv['id']] === 1 ? 'accent' : 'neutral') ?>
                        <span class="dm-mono"><?= ui_e((string)$srv['name']) ?></span><?= ($srv['active'] ?? true) ? '' : ' ' . ui_chip($LANG['inactive']) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="form-text"><?= ui_icon('shield-lock') ?> <?= ui_e($LANG['zone_servers_admin_only']) ?></div>
        <?php endif; ?>
    </fieldset>

    <fieldset class="dm-form-group">
        <legend class="dm-editor-section-label"><?= ui_e($LANG['allow_dyndns']) ?></legend>
        <div class="form-check form-switch">
            <?php $dynChecked = $isCreate ? ($val('allow_dyndns') === '1') : !empty($zone_edit['allow_dyndns']); ?>
            <input class="form-check-input" type="checkbox" role="switch" name="allow_dyndns" id="zf_allow_dyndns" value="1"<?= $dynChecked ? ' checked' : '' ?>>
            <label class="form-check-label" for="zf_allow_dyndns"><?= ui_e($LANG['dyndns_zone_hint']) ?></label>
        </div>
    </fieldset>

    <div class="dm-field dm-field-wide dm-form-group">
        <label class="form-label" for="zf_description"><?= ui_e($LANG['description_optional']) ?></label>
        <textarea name="description" id="zf_description" class="form-control" rows="2"><?= ui_e($isCreate ? $val('description') : (string)($zone_edit['description'] ?? '')) ?></textarea>
    </div>
</form>
