<?php
/**
 * Datei: templates/dyndns_editor_form.php
 * Zweck: Formular für Anlage und Bearbeitung eines DynDNS-Accounts im rechten Editor (U5b).
 *
 * - Ziele, Feldnamen und CSRF unverändert gegenüber dyndns_add_form.php / dyndns_edit_form.php.
 * - Anlage: nur Zonen, die DynDNS erlauben (listZonesForCreate).
 * - Bearbeitung: freigegebene Zonen und IMMER die aktuelle Zone des Accounts (listZonesForEdit), damit beim Speichern
 *   nicht still die Zone wechselt; Hinweis, wenn DynDNS in der aktuellen Zone inzwischen deaktiviert ist.
 *
 * Variablen aus pages/dyndns.php: $mode 'create'|'update', $edit_account, $dyndnsReadService, $actor, $formId
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit;
}

$isCreate = $mode === 'create';
if ($isCreate) {
    $zones = $dyndnsReadService->listZonesForCreate($actor);
    $currentZoneId = 0;
    $currentZoneEligible = true;
} else {
    $currentZoneId = (int)$edit_account['zone_id'];
    $zones = $dyndnsReadService->listZonesForEdit($actor, $currentZoneId);
    $currentZoneEligible = false;
    foreach ($zones as $z) {
        if ($z['id'] === $currentZoneId) {
            $currentZoneEligible = $z['eligible'];
        }
    }
}
?>
<form method="post" action="<?= $isCreate ? 'actions/dyndns_add.php' : 'actions/dyndns_update.php' ?>" id="<?= ui_e($formId) ?>">
    <?= csrf_input() ?>
    <?php if (!$isCreate): ?>
        <input type="hidden" name="id" value="<?= (int)$edit_account['id'] ?>">
    <?php endif; ?>
    <div class="dm-field-grid">
        <div class="dm-field">
            <label for="dyn_username" class="form-label"><?= ui_e($LANG['username']) ?></label>
            <input type="text" name="username" id="dyn_username" class="form-control dm-mono" required maxlength="64"
                   value="<?= ui_e($isCreate ? '' : (string)$edit_account['username']) ?>">
        </div>
        <?php if ($isCreate): ?>
            <div class="dm-field">
                <label for="dyn_password" class="form-label"><?= ui_e($LANG['password']) ?></label>
                <input type="password" name="password" id="dyn_password" class="form-control" required minlength="6" autocomplete="new-password">
            </div>
        <?php endif; ?>
        <div class="dm-field">
            <label for="dyn_hostname" class="form-label"><?= ui_e($isCreate ? $LANG['dyndns_hostname_label'] : $LANG['hostname']) ?></label>
            <input type="text" name="hostname" id="dyn_hostname" class="form-control dm-mono" required maxlength="255"
                   value="<?= ui_e($isCreate ? '' : (string)$edit_account['hostname']) ?>">
        </div>
        <div class="dm-field">
            <label for="dyn_zone_id" class="form-label"><?= ui_e($isCreate ? $LANG['dyndns_zone_label'] : $LANG['zone']) ?></label>
            <select name="zone_id" id="dyn_zone_id" class="form-select" required>
                <?php if ($isCreate): ?>
                    <option value=""><?= ui_e($LANG['please_select']) ?></option>
                <?php endif; ?>
                <?php foreach ($zones as $z): ?>
                    <?php $isCurrent = !$isCreate && (int)$z['id'] === $currentZoneId; ?>
                    <option value="<?= (int)$z['id'] ?>"<?= $isCurrent ? ' selected' : '' ?>>
                        <?= ui_e((string)$z['name']) ?><?= ($isCurrent && !$currentZoneEligible) ? ' ' . ui_e($LANG['dyndns_zone_disabled_suffix']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isCreate && !$currentZoneEligible): ?>
                <div class="form-text text-warning"><?= ui_e($LANG['dyndns_zone_disabled_hint']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</form>
