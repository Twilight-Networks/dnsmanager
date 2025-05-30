<?php
/**
 * Template: user_edit_form.php
 * Zweck: Bearbeitungsformular für einen bestehenden Benutzer.
 * Voraussetzungen:
 * - $u: Array mit Benutzerdaten (id, username, role, ...)
 * - $zones: Liste aller verfügbaren Zonen
 * - $selected: Array mit IDs der dem Benutzer zugewiesenen Zonen
 * - $admin_count: Anzahl der Admins im System
 * - $csrf_token: CSRF-Eingabefeld
 */
?>

<tr>
    <td>
        <form method="post" action="actions/user_update.php" class="d-flex">
            <?= $csrf_token ?>
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <input name="username" value="<?= htmlspecialchars($u['username']) ?>" class="form-control" required>
    </td>
    <td>
        <select name="role" class="form-select">
            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
            <option value="zoneadmin" <?= $u['role'] === 'zoneadmin' ? 'selected' : '' ?>>zoneadmin</option>
        </select>
    </td>
    <td>
        <div id="zoneInfo<?= $u['id'] ?>" class="<?= $u['role'] === 'zoneadmin' ? 'd-none' : '' ?>">
            <div class="form-control-plaintext">Alle Zonen</div>
        </div>

        <div id="zoneSelect<?= $u['id'] ?>" class="<?= $u['role'] === 'zoneadmin' ? '' : 'd-none' ?>">
            <select name="zones[]" class="form-select" multiple>
                <?php foreach ($zones as $z): ?>
                    <option value="<?= $z['id'] ?>" <?= in_array($z['id'], $selected) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($z['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </td>
    <td>
        <button type="submit" class="btn btn-sm btn-success">Speichern</button>
        <a href="pages/users.php" class="btn btn-sm btn-secondary">Abbrechen</a>
        </form>
    </td>
</tr>

<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/user_edit_form.js"></script>
