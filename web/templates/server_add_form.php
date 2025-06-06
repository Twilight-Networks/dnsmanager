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
<h4>Neuen DNS-Server hinzufügen</h4>

<form method="post"
    class="row g-3"
    action="<?= rtrim(BASE_URL, '/') ?>/actions/server_add.php">
    <?= csrf_input() ?>

    <div class="col-md-6 colform-name">
        <label for="name" class="form-label">Servername (FQDN)</label>
        <input type="text" class="form-control" id="name" name="name" required maxlength="100"
               placeholder="z. B. ns1.example.com">
    </div>

    <div class="col-md-6 colform-ip">
        <label for="dns_ip4" class="form-label">DNS-IPv4-Adresse (IPv4)</label>
        <input type="text" class="form-control" id="dns_ip4" name="dns_ip4" maxlength="45"
               placeholder="z. B. 192.0.2.1">
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="same_ip_checkbox" onchange="toggleApiIpField()">
            <label class="form-check-label" for="same_ip_checkbox">
                = API-IP
            </label>
        </div>
    </div>

    <div class="col-md-6 colform-ip">
        <label for="dns_ip6" class="form-label">DNS-IP-Adresse (IPv6)</label>
        <input type="text" class="form-control" id="dns_ip6" name="dns_ip6" maxlength="45"
               placeholder="z. B. 2001:db8::1">
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="same_ip6_checkbox" onchange="toggleApiIpField()">
            <label class="form-check-label" for="same_ip6_checkbox">
                = API-IP
            </label>
        </div>
    </div>

    <div class="col-md-6 colform-ip">
        <label for="api_ip" class="form-label">API-IP-Adresse (IPv4/IPv6)</label>
        <input type="text" class="form-control" id="api_ip" name="api_ip" maxlength="45"
               placeholder="z. B. 192.0.2.1">
    </div>

    <div class="col-md-6 colform-key">
        <label for="api_token" class="form-label">API-Key</label>
        <div class="input-group">
            <input type="text" class="form-control" id="api_token" name="api_token" required maxlength="255"
                   placeholder="z. B. 64-stelliger Hexwert">
            <button class="btn btn-outline-secondary" type="button" onclick="generateApiKey()">Generieren</button>
        </div>
    </div>

    <div class="col-md-12 colform-checkbox">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="active" name="active" value="1" checked>
            <label class="form-check-label" for="active">Server ist aktiv</label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="is_local" name="is_local" value="1">
            <label class="form-check-label" for="is_local">Dieser Server ist der lokale (Webinterface-Host)</label>
        </div>
    </div>

    <div class="col-12 mt-2">
        <button type="submit" class="btn btn-success">Server hinzufügen</button>
        <a href="pages/servers.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>

<hr class="my-4">
<br>
