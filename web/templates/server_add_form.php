<?php
/**
 * Datei: server_add_form.php
 *
 * Zweck:
 * - Stellt ein HTML-Formular zur Verfügung, um einen neuen DNS-Server im System anzulegen.
 * - Ermöglicht die Eingabe von Servername, DNS-IP, API-IP und API-Key.
 * - Optional: Markierung als lokaler Server (Webinterface-Host) sowie Aktiv-Status.
 * - Das Formular wird nur geladen, wenn `IN_APP` definiert ist (Zugriffsschutz).
 *
 * Besonderheiten:
 * - Bei gesetztem Haken „API-IP = DNS-IP“ wird das API-IP-Feld automatisch mit der DNS-IP befüllt und readonly geschaltet (via JS).
 * - Der API-Key kann clientseitig per Button als 256-Bit Hexstring generiert werden.
 */

// Zugriffsschutz bei direktem Aufruf
if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}
?>

<hr class="my-4">
<h4><?= $LANG['add_new_server'] ?></h4>

<form method="post"
    class="row g-3"
    action="<?= rtrim(BASE_URL, '/') ?>/actions/server_add.php">
    <?= csrf_input() ?>

    <div class="col-md-6 colform-name">
        <label for="name" class="form-label"><?= $LANG['server_name'] ?></label>
        <input type="text" class="form-control" id="name" name="name" required maxlength="100"
               placeholder="<?= $LANG['for_example'] ?> ns1.example.com">
    </div>

    <div class="col-md-6 colform-ip">
        <label for="dns_ip4" class="form-label"><?= $LANG['dns_ipv4'] ?></label>
        <input type="text" class="form-control" id="dns_ip4" name="dns_ip4" maxlength="45"
               placeholder="<?= $LANG['for_example'] ?> 192.0.2.1">
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="same_ip_checkbox" onchange="toggleApiIpField()">
            <label class="form-check-label" for="same_ip_checkbox">
                <?= $LANG['same_as_api_ip'] ?>
            </label>
        </div>
    </div>

    <div class="col-md-6 colform-ip">
        <label for="dns_ip6" class="form-label"><?= $LANG['dns_ipv6'] ?></label>
        <input type="text" class="form-control" id="dns_ip6" name="dns_ip6" maxlength="45"
               placeholder="<?= $LANG['for_example'] ?> 2001:db8::1">
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="same_ip6_checkbox" onchange="toggleApiIpField()">
            <label class="form-check-label" for="same_ip6_checkbox">
                = API-IP
            </label>
        </div>
    </div>

    <div class="col-md-6 colform-ip">
        <label for="api_ip" class="form-label"><?= $LANG['api_ip'] ?></label>
        <input type="text" class="form-control" id="api_ip" name="api_ip" maxlength="45"
               placeholder="<?= $LANG['for_example'] ?> 192.0.2.1">
    </div>

    <div class="col-md-6 colform-key">
        <label for="api_token" class="form-label"><?= $LANG['api_token'] ?></label>
        <div class="input-group">
            <input type="text" class="form-control" id="api_token" name="api_token" required maxlength="255"
                   placeholder="<?= $LANG['api_token_placeholder'] ?>">
            <button class="btn btn-outline-secondary" type="button" onclick="generateApiKey()"><?= $LANG['generate'] ?></button>
        </div>
    </div>

    <div class="col-md-12 colform-checkbox">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="active" name="active" value="1" checked>
            <label class="form-check-label" for="active"><?= $LANG['server_active'] ?></label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="is_local" name="is_local" value="1">
            <label class="form-check-label" for="is_local"><?= $LANG['server_is_local'] ?></label>
        </div>
    </div>

    <div class="col-12 mt-2">
        <button type="submit" class="btn btn-success"><?= $LANG['add_server'] ?></button>
        <a href="pages/servers.php" class="btn btn-secondary"><?= $LANG['cancel'] ?></a>
    </div>
</form>

<hr class="my-4">
<br>
