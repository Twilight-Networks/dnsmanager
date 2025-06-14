<?php
/**
 * Template: user_add_form.php
 * Zweck: Formular zur Anlage eines neuen Benutzers.
 * Voraussetzungen:
 * - $zones: Liste aller verfügbaren Zonen (id, name)
 * - $csrf_input: CSRF-Eingabefeld
 */

// Zugriffsschutz bei direktem Aufruf
if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}
?>

<hr class="my-4">
<h4 class="mt-4"><?= $LANG['add_new_user'] ?></h4>

<form method="post" action="actions/user_add.php" class="row g-3">
    <?= csrf_input() ?>

    <div class="col-md-3">
        <label><?= $LANG['username'] ?></label>
        <input name="username" class="form-control" required>
    </div>

    <div class="col-md-3">
        <label><?= $LANG['password'] ?></label>
        <input name="password" type="password" class="form-control" required>
    </div>

    <div class="col-md-3">
        <label><?= $LANG['role'] ?></label>
        <select name="role" class="form-select" id="new_user_role" onchange="toggleZoneOptions(this)">
            <option value="admin" selected><?= $LANG['role_admin'] ?></option>
            <option value="zoneadmin"><?= $LANG['role_zoneadmin'] ?></option>
        </select>
    </div>

    <div class="col-md-3">
        <label><?= $LANG['zones'] ?></label>
        <select name="zones[]" multiple class="form-select" id="zone_select">
            <option value="all" id="admin-placeholder"><?= $LANG['all_zones'] ?></option>
            <?php foreach ($zones as $z): ?>
                <option value="<?= $z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12">
        <button class="btn btn-success"><?= $LANG['create_user'] ?></button>
        <a href="pages/users.php" class="btn btn-secondary"><?= $LANG['cancel'] ?></a>
    </div>
</form>

<hr class="my-4">
<br>

<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/user_add_form.js"></script>
