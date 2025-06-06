<?php
/**
 * Datei: server_edit_form.php
 *
 * Zweck:
 * - Stellt ein bearbeitbares HTML-Formular zur Verfügung, um die Eigenschaften eines bestehenden DNS-Servers zu ändern.
 * - Eingabefelder: Name, DNS-IP, API-IP, API-Key, Lokaler Server (is_local), Aktivitätsstatus (active).
 * - Die Feldwerte sind vorausgefüllt mit den bestehenden Daten aus `$edit_server`.
 *
 * Besonderheiten:
 * - Zugriffsschutz über `IN_APP`.
 * - Optionales Setzen der API-IP gleich DNS-IP über eine Checkbox (wird per JS verarbeitet).
 * - Einbindung erfolgt dynamisch innerhalb einer Tabellenzeile in `servers.php`, wenn `edit_id` gesetzt ist.
 * - Das Formular sendet die Daten an `server_update.php`.
 */

// Zugriffsschutz bei direktem Aufruf
if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

if (!isset($edit_server)) {
    echo "<div class='alert alert-danger'>Fehlende Daten für das Bearbeitungsformular (edit_account).</div>";
    return;
}
?>

<tr class="table-warning table-edit-form">
    <td colspan="6">
        <form method="post"
              action="actions/server_update.php"
              id="editForm_<?= $edit_server['id'] ?>"
              class="d-flex flex-column gap-3">

            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int)$edit_server['id'] ?>">

            <div class="row g-3">
                <div class="col-md-4 colform-name">
                    <label class="form-label">Servername</label>
                    <input type="text" class="form-control" name="name" required maxlength="100"
                            placeholder="z. B. ns1"
                            value="<?= htmlspecialchars($edit_server['name']) ?>">
                </div>

                <div class="col-md-4 colform-ip">
                    <label class="form-label">DNS-IP-Adresse (IPv4)</label>
                    <input type="text" class="form-control" id="dns_ip4" name="dns_ip4" maxlength="45"
                            placeholder="z. B. 192.0.2.1"
                            value="<?= htmlspecialchars($edit_server['dns_ip4']) ?>">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="same_ip_checkbox">
                        <label class="form-check-label" for="same_ip_checkbox">= API-IP</label>
                    </div>
                </div>

                <div class="col-md-4 colform-ip">
                    <label class="form-label">DNS-IP-Adresse (IPv6)</label>
                    <input type="text" class="form-control" id="dns_ip6" name="dns_ip6" maxlength="45"
                           placeholder="z. B. 2001:db8::1"
                           value="<?= htmlspecialchars($edit_server['dns_ip6'] ?? '') ?>">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="same_ip6_checkbox">
                        <label class="form-check-label" for="same_ip6_checkbox">= API-IP</label>
                    </div>
                </div>

                <div class="col-md-4 colform-ip">
                    <label class="form-label" style="white-space: nowrap;">API-IP-Adresse (IPv4/IPv6)</label>
                    <input type="text" class="form-control" id="api_ip" name="api_ip" maxlength="45"
                            placeholder="z. B. 192.0.2.1"
                            value="<?= htmlspecialchars($edit_server['api_ip'] ?? '') ?>">
                </div>

                <div class="col-md-6 colform-key">
                    <label class="form-label">API-Key</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="api_token" name="api_token" required maxlength="255"
                               placeholder="z. B. 64-stelliger Hexwert"
                               value="<?= htmlspecialchars($edit_server['api_token']) ?>">
                        <button class="btn btn-outline-secondary" type="button" id="toggleTokenVisibility" title="Anzeigen/Ausblenden">👁️</button>
                        <button class="btn btn-outline-secondary" type="button" onclick="generateApiKey()" id="generateBtn">Generieren</button>
                    </div>
                </div>

                <div class="col-md-6 d-flex flex-column gap-2">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="edit_active_<?= $edit_server['id'] ?>"
                               name="active" value="1" <?= $edit_server['active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="edit_active_<?= $edit_server['id'] ?>">Server ist aktiv</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="edit_is_local_<?= $edit_server['id'] ?>"
                               name="is_local" value="1" <?= $edit_server['is_local'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="edit_is_local_<?= $edit_server['id'] ?>">Dieser Server ist der lokale (Webinterface-Host)</label>
                    </div>
                </div>
            </div>
        </form>
    </td>
</tr>

