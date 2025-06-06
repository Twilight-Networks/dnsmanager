<?php
/**
 * Template: user_edit_form.php
 * Zweck: Bearbeitungsformular für einen bestehenden Benutzer.
 * Voraussetzungen:
 * - $u: Array mit Benutzerdaten (id, username, role, ...)
 * - $zones: Liste aller verfügbaren Zonen
 * - $selected: Array mit IDs der dem Benutzer zugewiesenen Zonen
 * - $admin_count: Anzahl der Admins im System
 * - $csrf_input: CSRF-Eingabefeld
 */

// Zugriffsschutz bei direktem Aufruf
if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

// Prüfen auf erforderliche Datenbasis
if (!isset($u) || !isset($zones) || !isset($selected)) {
    echo "<div class='alert alert-danger'>Fehlende Daten für das Bearbeitungsformular.</div>";
    return;
}
?>

<tr class="table-warning table-edit-form">
    <td colspan="7">
        <form method="post"
              action="actions/user_update.php"
              class="d-flex flex-column gap-3"
              id="editForm_<?= $u['id'] ?>">


            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">

            <div class="row g-3">
                <div class="col-md-4 coltbl-users-name">
                    <label class="form-label">Benutzername</label>
                    <input name="username" value="<?= htmlspecialchars($u['username']) ?>"
                           class="form-control" required maxlength="100">
                </div>

                <div class="col-md-4 coltbl-users-role">
                    <label class="form-label">Rolle</label>
                    <select name="role" class="form-select">
                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                        <option value="zoneadmin" <?= $u['role'] === 'zoneadmin' ? 'selected' : '' ?>>zoneadmin</option>
                    </select>
                </div>

                <div class="col-md-4 coltbl-users-zones">
                    <label class="form-label">Zonen</label>
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
                </div>
            </div>
        </form>
    </td>
</tr>

<?php
AssetRegistry::enqueueScript('js/user_edit_form.js');
?>
