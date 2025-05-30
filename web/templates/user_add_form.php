<?php
/**
 * Template: user_add_form.php
 * Zweck: Formular zur Anlage eines neuen Benutzers.
 * Voraussetzungen:
 * - $zones: Liste aller verfügbaren Zonen (id, name)
 * - $csrf_token: CSRF-Eingabefeld (vorberechnet, z. B. über csrf_input())
 */
?>

<hr class="my-4">
<h4 class="mt-4">Neuen Benutzer anlegen</h4>

<form method="post" action="actions/user_add.php" class="row g-3">
    <?= $csrf_token ?>

    <div class="col-md-3">
        <label>Benutzername</label>
        <input name="username" class="form-control" required>
    </div>

    <div class="col-md-3">
        <label>Passwort</label>
        <input name="password" type="password" class="form-control" required>
    </div>

    <div class="col-md-3">
        <label>Rolle</label>
        <select name="role" class="form-select" id="new_user_role" onchange="toggleZoneOptions(this)">
            <option value="admin" selected>admin</option>
            <option value="zoneadmin">zoneadmin</option>
        </select>
    </div>

    <div class="col-md-3">
        <label>Zonen</label>
        <select name="zones[]" multiple class="form-select" id="zone_select">
            <option value="all" id="admin-placeholder">Alle Zonen</option>
            <?php foreach ($zones as $z): ?>
                <option value="<?= $z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12">
        <button class="btn btn-success">Benutzer anlegen</button>
        <a href="pages/users.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>

<hr class="my-4">
<br>

<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/user_add_form.js"></script>
