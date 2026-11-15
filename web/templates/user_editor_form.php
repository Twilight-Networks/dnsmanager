<?php
/**
 * Datei: templates/user_editor_form.php
 * Zweck: Formular für Anlage und Bearbeitung eines Benutzers im rechten Editor (U5b, nur admin).
 *
 * - Ziele, Feldnamen und CSRF unverändert gegenüber user_add_form.php / user_edit_form.php
 *   (username, password [Anlage], role, zones[]).
 * - Zonenauswahl: Anlage mit dem bisherigen Eintrag „alle Zonen“ (value="all"); users_editor.js deaktiviert wie bisher
 *   die Zonenoptionen bei Rolle admin (Anlage) bzw. blendet die Auswahl aus (Bearbeitung). Ohne JavaScript bleibt die
 *   Auswahl sichtbar (wirksam nur für Zone-Admins, Hinweis).
 *
 * Variablen aus pages/users.php: $mode 'create'|'update', $edit_user, $zones, $selected, $formId
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

$isCreate = $mode === 'create';
$role = $isCreate ? 'admin' : (string)$edit_user['role'];
?>
<form method="post" action="<?= $isCreate ? 'actions/user_add.php' : 'actions/user_update.php' ?>" id="<?= ui_e($formId) ?>"
      data-dm-user-form="<?= $isCreate ? 'create' : 'update' ?>">
    <?= csrf_input() ?>
    <?php if (!$isCreate): ?>
        <input type="hidden" name="id" value="<?= (int)$edit_user['id'] ?>">
    <?php endif; ?>
    <div class="dm-field-grid">
        <div class="dm-field">
            <label class="form-label" for="usr_username"><?= ui_e($LANG['username']) ?></label>
            <input name="username" id="usr_username" class="form-control dm-mono" required<?= $isCreate ? '' : ' maxlength="100"' ?>
                   value="<?= ui_e($isCreate ? '' : (string)$edit_user['username']) ?>">
        </div>
        <?php if ($isCreate): ?>
            <div class="dm-field">
                <label class="form-label" for="usr_password"><?= ui_e($LANG['password']) ?></label>
                <input name="password" id="usr_password" type="password" class="form-control" required autocomplete="new-password">
            </div>
        <?php endif; ?>
        <div class="dm-field">
            <label class="form-label" for="usr_role"><?= ui_e($LANG['role']) ?></label>
            <select name="role" id="usr_role" class="form-select" data-dm-user-role>
                <option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>><?= ui_e($LANG['role_admin']) ?></option>
                <option value="zoneadmin"<?= $role === 'zoneadmin' ? ' selected' : '' ?>><?= ui_e($LANG['role_zoneadmin']) ?></option>
            </select>
        </div>
        <div class="dm-field dm-field-wide">
            <label class="form-label" for="usr_zones"><?= ui_e($LANG['zones']) ?></label>
            <div class="form-control-plaintext" data-dm-user-allzones hidden><?= ui_e($LANG['all_zones']) ?></div>
            <div data-dm-user-zones>
                <select name="zones[]" id="usr_zones" class="form-select" multiple size="<?= max(4, min(10, count($zones) + ($isCreate ? 1 : 0))) ?>">
                    <?php if ($isCreate): ?>
                        <option value="all" data-dm-all-placeholder><?= ui_e($LANG['all_zones']) ?></option>
                    <?php endif; ?>
                    <?php foreach ($zones as $z): ?>
                        <option value="<?= (int)$z['id'] ?>"<?= in_array($z['id'], $selected, true) ? ' selected' : '' ?>><?= ui_e((string)$z['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?= ui_e($LANG['user_zones_hint']) ?></div>
            </div>
        </div>
    </div>
</form>
