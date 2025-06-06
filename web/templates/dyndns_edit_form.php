<?php
/**
 * Datei: dyndns_edit_form.php
 * Zweck: Bearbeitungsformular für einen bestehenden DynDNS-Account
 *
 * Beschreibung:
 * Wird innerhalb von `dyndns.php` eingebettet, wenn `edit_id` gesetzt ist.
 * Ermöglicht Administratoren die Änderung folgender Eigenschaften:
 * - Benutzername
 * - Hostname (Subdomain)
 * - Zugehörige Zone
 * - Aktuelle IPv4 / IPv6 (werden automatisch beim nächsten Update ersetzt)
 *
 * Besonderheiten:
 * - Das Formular ist in eine zusätzliche Tabellenzeile unterhalb der bearbeiteten Zeile eingebettet.
 * - Die letzte Update-Zeit wird nicht bearbeitet.
 *
 * Ziel:
 * - POST an `actions/dyndns_update.php`
 */

// Zugriffsschutz bei direktem Aufruf
if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

if (!isset($edit_account)) {
    echo "<div class='alert alert-danger'>Fehlende Daten für das Bearbeitungsformular (edit_account).</div>";
    return;
}

$stmt = $pdo->query("SELECT id, name FROM zones WHERE allow_dyndns = 1 ORDER BY name");
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<tr class="table-warning table-edit-form">
    <td colspan="7">
        <form method="post"
              action="actions/dyndns_update.php"
              id="editForm_<?= $edit_account['id'] ?>"
              class="d-flex flex-column gap-3">

            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int)$edit_account['id'] ?>">

            <div class="row g-3">
                <div class="col-md-3 coltbl-users-name">
                    <label class="form-label">Benutzername</label>
                    <input type="text" class="form-control" name="username" required maxlength="64"
                           value="<?= htmlspecialchars($edit_account['username']) ?>">
                </div>
                <div class="col-md-3 coltbl-dyndns-hostname">
                    <label class="form-label">Hostname</label>
                    <input type="text" class="form-control" name="hostname" required maxlength="255"
                           value="<?= htmlspecialchars($edit_account['hostname']) ?>">
                </div>
                <div class="col-md-3 coltbl-dyndns-zone">
                    <label class="form-label">Zone</label>
                    <select name="zone_id" class="form-select" required>
                        <?php foreach ($zones as $z): ?>
                            <option value="<?= $z['id'] ?>" <?= $z['id'] == $edit_account['zone_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($z['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </td>
</tr>
