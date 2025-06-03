<?php
/**
 * Template: zone_edit_form.php
 * Zweck: Bearbeitungsformular für eine bestehende DNS-Zone.
 * Hinweise:
 * - Enthält vollständiges <form>-Tag mit POST-Handling.
 * - Die Variable $zone muss gesetzt sein.
 * - Eingebunden durch zones.php im Bearbeitungsmodus
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

$stmt_all = $pdo->prepare("SELECT id, name, dns_ip4, dns_ip6 FROM servers WHERE active = 1 ORDER BY name");
$stmt_all->execute();
$all_servers = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

$stmt_assigned = $pdo->prepare("SELECT server_id, is_master FROM zone_servers WHERE zone_id = ?");
$stmt_assigned->execute([$zone['id']]);
$assigned = $stmt_assigned->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<tr class="table-warning table-edit-form">
    <td colspan="6">
        <form method="post"
              action="actions/zone_update.php"
              id="editForm_<?= $zone['id'] ?>"
              data-zone-id="<?= $zone['id'] ?>"
              data-zone-name="<?= htmlspecialchars(rtrim($zone['name'], '.')) ?>"
              data-zone-type="<?= $zone['type'] ?>"
              class="d-flex flex-column gap-3">

            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int)$zone['id'] ?>">

            <!-- Formularfelder: TTL, SOA-Werte, Beschreibung -->
            <div class="row g-3">
                <div class="col-md-4 d-flex flex-column colform-name">
                    <label class="form-label">SOA NS</label>
                    <input
                        name="soa_ns"
                        id="soa_ns"
                        class="form-control"
                        value="<?= htmlspecialchars($zone['soa_ns']) ?>"
                        <?= $zone['type'] === 'forward' ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-4 d-flex flex-column colform-zones-mail">
                    <label class="form-label">SOA Mail</label>
                    <input name="soa_mail" class="form-control" value="<?= htmlspecialchars($zone['soa_mail']) ?>">
                </div>

                <div class="col-md-4 d-flex flex-column colform-ttl">
                    <label class="form-label">TTL</label>
                    <input name="ttl" type="number" class="form-control" value="<?= (int)$zone['ttl'] ?>">
                </div>

                <div class="col-md-4 d-flex flex-column colform-ttl">
                    <label class="form-label">SOA Refresh</label>
                    <input name="soa_refresh" type="number" class="form-control" value="<?= (int)$zone['soa_refresh'] ?>">
                </div>

                <div class="col-md-4 d-flex flex-column colform-ttl">
                    <label class="form-label">SOA Retry</label>
                    <input name="soa_retry" type="number" class="form-control" value="<?= (int)$zone['soa_retry'] ?>">
                </div>

                <div class="col-md-4 d-flex flex-column colform-ttl">
                    <label class="form-label">SOA Expire</label>
                    <input name="soa_expire" type="number" class="form-control" value="<?= (int)$zone['soa_expire'] ?>">
                </div>

                <div class="col-md-4 d-flex flex-column colform-ttl">
                    <label class="form-label">SOA Minimum</label>
                    <input name="soa_minimum" type="number" class="form-control" value="<?= (int)$zone['soa_minimum'] ?>">
                </div>

                <?php if ($zone['type'] === 'reverse'): ?>
                <div class="col-md-4 d-flex flex-column colform-prefix">
                    <label class="form-label">Prefix-Length</label>
                    <input name="prefix_length" type="number" class="form-control" value="<?= (int)$zone['prefix_length'] ?>">
                </div>
                <?php endif; ?>

                <!-- Serverzuweisung -->
                <div class="d-flex flex-column">
                    <label class="form-label">DNS-Serverzuweisung</label>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
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
                                    <?php
                                        $is_checked = array_key_exists($srv['id'], $assigned);
                                        $is_master  = $assigned[$srv['id']] ?? 0;
                                    ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" name="server_ids[]" value="<?= $srv['id'] ?>" <?= $is_checked ? 'checked' : '' ?>>
                                        </td>
                                        <td class="text-center">
                                            <input type="radio" name="master_server_id" value="<?= $srv['id'] ?>" <?= $is_master ? 'checked' : '' ?>>
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
                    </div>
                    <small class="text-muted">Bitte mindestens einen Server auswählen. Genau einer muss als Master definiert sein.</small>
                </div>

                <div class="col-md-9 d-flex flex-column mt-2">
                    <label class="form-label">DynDNS erlaubt</label>
                    <div class="form-check form-switch">
                        <input class="form-check-input"
                               type="checkbox" role="switch"
                               name="allow_dyndns"
                               id="allow_dyndns_<?= $zone['id'] ?>"
                               value="1"
                               <?= $zone['allow_dyndns'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allow_dyndns_<?= $zone['id'] ?>">
                            DynDNS-Updates für diese Zone zulassen
                        </label>
                    </div>
                </div>

                <div class="col-md-4 d-flex flex-column coltbl-desc">
                    <label class="form-label">Beschreibung (optional)</label>
                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars((string)($zone['description'] ?? '')) ?></textarea>
                </div>
            </div>
        </form>
    </td>
</tr>
