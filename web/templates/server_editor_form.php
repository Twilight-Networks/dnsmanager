<?php
/**
 * Datei: templates/server_editor_form.php
 * Zweck: Formular für Anlage und Bearbeitung eines DNS-Servers im rechten Editor (U5b).
 *
 * - Ziele, Feldnamen und CSRF unverändert gegenüber server_add_form.php / server_edit_form.php.
 * - Element-IDs (dns_ip4, dns_ip6, api_ip, same_ip_checkbox, same_ip6_checkbox, api_token, toggleTokenVisibility,
 *   generateBtn, is_local, active) bleiben erhalten; server_add_form.js und server_edit_form.js arbeiten unverändert.
 * - Bearbeitung: Der gespeicherte API-Token wird nie ausgegeben (S29, SP1); leer = unverändert.
 *
 * Variablen aus pages/servers.php: $serverEditorMode 'create'|'update', $edit_server (Bearbeitung)
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

$isCreate = $serverEditorMode === 'create';
$s = $isCreate ? ['name' => '', 'dns_ip4' => '', 'dns_ip6' => '', 'api_ip' => '', 'active' => 1, 'is_local' => 0] : $edit_server;
$eg = $LANG['for_example'] . ' ';
?>
<form method="post"
      action="<?= $isCreate ? rtrim(BASE_URL, '/') . '/actions/server_add.php' : 'actions/server_update.php' ?>"
      id="<?= $isCreate ? 'serverAddForm' : 'editForm_' . (int)$s['id'] ?>"
      class="dm-server-form">
    <?= csrf_input() ?>
    <?php if (!$isCreate): ?>
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
    <?php endif; ?>

    <div class="dm-field-grid">
        <div class="dm-field dm-field-wide">
            <label class="form-label" for="name"><?= ui_e($LANG['server_name']) ?></label>
            <input type="text" class="form-control dm-mono" id="name" name="name" required maxlength="100"
                   placeholder="<?= ui_e($eg . 'ns1.example.com') ?>" value="<?= ui_e((string)$s['name']) ?>">
        </div>
        <div class="dm-field">
            <label class="form-label" for="dns_ip4"><?= ui_e($LANG['dns_ipv4']) ?></label>
            <input type="text" class="form-control dm-mono" id="dns_ip4" name="dns_ip4" maxlength="45"
                   placeholder="<?= ui_e($eg . '192.0.2.1') ?>" value="<?= ui_e((string)$s['dns_ip4']) ?>">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="same_ip_checkbox">
                <label class="form-check-label" for="same_ip_checkbox"><?= ui_e($LANG['same_as_api_ip']) ?></label>
            </div>
        </div>
        <div class="dm-field">
            <label class="form-label" for="dns_ip6"><?= ui_e($LANG['dns_ipv6']) ?></label>
            <input type="text" class="form-control dm-mono" id="dns_ip6" name="dns_ip6" maxlength="45"
                   placeholder="<?= ui_e($eg . '2001:db8::1') ?>" value="<?= ui_e((string)($s['dns_ip6'] ?? '')) ?>">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="same_ip6_checkbox">
                <label class="form-check-label" for="same_ip6_checkbox"><?= ui_e($LANG['same_as_api_ip']) ?></label>
            </div>
        </div>
        <div class="dm-field">
            <label class="form-label" for="api_ip"><?= ui_e($LANG['api_ip']) ?></label>
            <input type="text" class="form-control dm-mono" id="api_ip" name="api_ip" maxlength="45"
                   placeholder="<?= ui_e($eg . '192.0.2.1') ?>" value="<?= ui_e((string)($s['api_ip'] ?? '')) ?>">
        </div>
        <div class="dm-field dm-field-wide">
            <label class="form-label" for="api_token"><?= ui_e($LANG['api_token']) ?></label>
            <div class="input-group">
                <?php if ($isCreate): ?>
                    <input type="text" class="form-control dm-mono" id="api_token" name="api_token" required maxlength="255" autocomplete="off"
                           placeholder="<?= ui_e($LANG['api_token_placeholder']) ?>">
                <?php else: ?>
                    <?php // S29 (SP1): gespeicherter Token wird nie ausgegeben; leer = unverändert ?>
                    <input type="password" class="form-control dm-mono" id="api_token" name="api_token" maxlength="255" autocomplete="new-password"
                           placeholder="<?= ui_e($LANG['api_token_keep_placeholder']) ?>" value="">
                    <button class="btn btn-outline-secondary" type="button" id="toggleTokenVisibility" title="<?= ui_e($LANG['token_toggle_visibility']) ?>">👁️</button>
                <?php endif; ?>
                <button class="btn btn-outline-secondary js-generate-api-key" type="button" id="generateBtn"><?= ui_e($LANG['generate']) ?></button>
            </div>
            <noscript><div class="form-text"><?= ui_e($LANG['server_token_nojs_hint']) ?></div></noscript>
        </div>
        <div class="dm-field dm-field-wide">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="<?= $isCreate ? 'active' : 'edit_active_' . (int)$s['id'] ?>" name="active" value="1"<?= $s['active'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="<?= $isCreate ? 'active' : 'edit_active_' . (int)$s['id'] ?>"><?= ui_e($LANG['server_active']) ?></label>
            </div>
            <div class="form-check mt-1">
                <input type="checkbox" class="form-check-input" id="<?= $isCreate ? 'is_local' : 'edit_is_local_' . (int)$s['id'] ?>" name="is_local" value="1"<?= $s['is_local'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="<?= $isCreate ? 'is_local' : 'edit_is_local_' . (int)$s['id'] ?>"><?= ui_e($LANG['server_is_local']) ?></label>
            </div>
        </div>
    </div>
</form>
