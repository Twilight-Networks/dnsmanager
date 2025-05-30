<?php
/**
 * Datei: templates/zone_add_form.php
 * Zweck: HTML-Formular zum Anlegen einer neuen DNS-Zone
 *
 * Voraussetzungen:
 * - IN_APP muss definiert sein.
 * - Formularwerte werden über $_POST vorbelegt.
 * - Dynamisches Verhalten wird durch zone_add_form.js gesteuert.
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}
?>

<hr class="my-4">
<h4>Neue DNS-Zone anlegen</h4>

<form method="post" class="row g-3" action="<?= rtrim(BASE_URL, '/') ?>/actions/zone_add.php">
    <?= csrf_input() ?>

    <div class="col-md-6 colform-name" id="zone_input_wrapper">
        <label class="form-label">Zonenname</label>
        <div class="input-group">
            <input type="text" name="zone_prefix" id="zone_prefix" class="form-control" required
                   placeholder="z. B. example.com"
                   value="<?= htmlspecialchars($_POST['zone_prefix'] ?? '') ?>">
            <span class="input-group-text d-none" id="zone_suffix_wrapper">
                <span id="zone_suffix"></span>
            </span>
        </div>
    </div>

    <div class="col-md-3 colform-type">
        <label class="form-label">Typ</label>
        <select name="type" class="form-select" required id="zone_type">
            <option value="forward" <?= ($_POST['type'] ?? '') === 'forward' ? 'selected' : '' ?>>Forward</option>
            <option value="reverse_ipv4" <?= ($_POST['type'] ?? '') === 'reverse_ipv4' ? 'selected' : '' ?>>Reverse IPv4</option>
            <option value="reverse_ipv6" <?= ($_POST['type'] ?? '') === 'reverse_ipv6' ? 'selected' : '' ?>>Reverse IPv6</option>
        </select>
    </div>

    <div class="col-md-3" id="prefix_length_container" style="display:none;">
        <label class="form-label">Prefix-Length (nur Reverse)</label>
        <input type="number" name="prefix_length" class="form-control" value="<?= htmlspecialchars($_POST['prefix_length'] ?? '') ?>" placeholder="z. B. 24">
    </div>

    <div class="col-md-3 colform-ttl">
        <label class="form-label">TTL (Sekunden)</label>
        <input type="number" name="ttl" class="form-control" value="<?= htmlspecialchars($_POST['ttl'] ?? 86400) ?>">
    </div>

    <fieldset class="mt-4 border p-3">
        <legend class="w-auto px-2">SOA Einstellungen</legend>
        <div class="row g-3">
            <!-- Nur bei Forward-Zone: automatisch gesetzter Primary NS -->
            <div class="col-md-6 colform-name" id="soa_ns_ip_wrapper">
                <label class="form-label">SOA NS</label>
                <input type="text" name="soa_ns_display" id="soa_ns" class="form-control" placeholder="wird automatisch gesetzt"
                       disabled readonly>
            </div>

            <!-- Nur bei Reverse-Zone: manuelle Angabe der SOA-Domain -->
            <div class="col-md-6 d-none" id="soa_domain_wrapper">
                <label class="form-label">Domain des SOA-Servers (nur bei Reverse)</label>
                <input type="text" name="soa_domain" id="soa_domain" class="form-control"
                       placeholder="z. B. example.com."
                       value="<?= htmlspecialchars($_POST['soa_domain'] ?? '') ?>">
            </div>

            <div class="col-md-6 colform-mail">
                <label class="form-label">SOA Mail</label>
                <input type="text" name="soa_mail" id="soa_mail" class="form-control" required placeholder="z. B. hostmaster.example.com.">
            </div>

            <div class="col-md-3 colform-ttl">
                <label class="form-label">Refresh</label>
                <input type="number" name="soa_refresh" class="form-control" value="<?= htmlspecialchars($_POST['soa_refresh'] ?? 3600) ?>">
            </div>
            <div class="col-md-3 colform-ttl">
                <label class="form-label">Retry</label>
                <input type="number" name="soa_retry" class="form-control" value="<?= htmlspecialchars($_POST['soa_retry'] ?? 1200) ?>">
            </div>
            <div class="col-md-3 colform-ttl">
                <label class="form-label">Expire</label>
                <input type="number" name="soa_expire" class="form-control" value="<?= htmlspecialchars($_POST['soa_expire'] ?? 1209600) ?>">
            </div>
            <div class="col-md-3 colform-ttl">
                <label class="form-label">Minimum TTL</label>
                <input type="number" name="soa_minimum" class="form-control" value="<?= htmlspecialchars($_POST['soa_minimum'] ?? 3600) ?>">
            </div>
        </div>
    </fieldset>

    <?php
    // Serverliste laden für die Zuweisung
    $stmt = $pdo->prepare("SELECT id, name, dns_ip4, dns_ip6 FROM servers WHERE active = 1 ORDER BY name");
    $stmt->execute();
    $all_servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="col-12">
        <label class="form-label">DNS-Serverzuweisung</label>
            <table class="table table-sm table-bordered align-middle mb-0 table-fixed">
                <thead class="table-light">
                    <tr>
                        <th class="coltbl-assign">Zuweisen</th>
                        <th class="coltbl-master">Master</th>
                        <th class="coltbl-name-dns">Name</th>
                        <th class="coltbl-ip-dns">DNS-IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_servers as $srv): ?>
                        <tr>
                            <td class="text-center">
                                <input type="checkbox"
                                       name="server_ids[]"
                                       id="server_<?= $srv['id'] ?>"
                                       value="<?= $srv['id'] ?>">
                            </td>
                            <td class="text-center">
                                <input type="radio"
                                       name="master_server_id"
                                       id="master_<?= $srv['id'] ?>"
                                       value="<?= $srv['id'] ?>">
                            </td>
                            <td><label for="server_<?= $srv['id'] ?>"><?= htmlspecialchars($srv['name']) ?></label></td>
                            <td>
                                <?= htmlspecialchars($srv['dns_ip4']) ?>
                                <?php if (!empty($srv['dns_ip6'])): ?>
                                    <br><?= htmlspecialchars($srv['dns_ip6']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <small class="text-muted">Bitte mindestens einen Server auswählen. Genau einer muss als Master definiert sein.</small>
    </div>

    <div class="col-md-9 coltbl-desc">
        <label class="form-label">Beschreibung (optional)</label>
        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>

    <div class="col-12">
        <button class="btn btn-success">Zone erstellen</button>
        <a href="<?= rtrim(BASE_URL, '/') ?>/pages/zones.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>

<hr class="my-4">
<br>
