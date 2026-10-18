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

// Auswählbare Zonen: freigegebene Forward-Zonen sowie IMMER die aktuelle Zone des Accounts.
// Fehlt die aktuelle Zone in der Liste (z. B. DynDNS dort inzwischen deaktiviert), würde der Browser
// stillschweigend die erste Option wählen und beim Speichern die Zone wechseln.
$currentZoneId = (int)$edit_account['zone_id'];
$stmt = $pdo->prepare("
    SELECT id, name, type, allow_dyndns
    FROM zones
    WHERE (allow_dyndns = 1 AND type = 'forward') OR id = ?
    ORDER BY name
");
$stmt->execute([$currentZoneId]);
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentZoneEligible = false;
foreach ($zones as $z) {
    if ((int)$z['id'] === $currentZoneId) {
        $currentZoneEligible = (int)$z['allow_dyndns'] === 1 && $z['type'] === 'forward';
    }
}
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
                    <label class="form-label"><?= $LANG['username'] ?></label>
                    <input type="text" class="form-control" name="username" required maxlength="64"
                           value="<?= htmlspecialchars($edit_account['username']) ?>">
                </div>
                <div class="col-md-3 coltbl-dyndns-hostname">
                    <label class="form-label"><?= $LANG['hostname'] ?></label>
                    <input type="text" class="form-control" name="hostname" required maxlength="255"
                           value="<?= htmlspecialchars($edit_account['hostname']) ?>">
                </div>
                <div class="col-md-3 coltbl-dyndns-zone">
                    <label class="form-label"><?= $LANG['zone'] ?></label>
                    <select name="zone_id" class="form-select" required>
                        <?php foreach ($zones as $z): ?>
                            <?php $isCurrent = (int)$z['id'] === $currentZoneId; ?>
                            <option value="<?= (int)$z['id'] ?>" <?= $isCurrent ? 'selected' : '' ?>>
                                <?= htmlspecialchars($z['name']) ?><?= ($isCurrent && !$currentZoneEligible) ? ' ' . htmlspecialchars($LANG['dyndns_zone_disabled_suffix']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$currentZoneEligible): ?>
                        <div class="form-text text-warning"><?= htmlspecialchars($LANG['dyndns_zone_disabled_hint']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </td>
</tr>
