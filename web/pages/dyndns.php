<?php
/**
 * Datei: dyndns.php
 * Zweck: Verwaltungsseite für DynDNS-Accounts
 *
 * Beschreibung:
 * Diese Seite listet alle vorhandenen DynDNS-Accounts auf und erlaubt dem Administrator:
 * - Neue Accounts anzulegen
 * - Bestehende Accounts zu bearbeiten
 * - Passwörter zu ändern (via Modal)
 * - Accounts zu löschen (mit CSRF-Schutz)
 *
 * Struktur:
 * - Zeigt eine tabellarische Übersicht aller Accounts mit Hostname, Zone und IP-Adressen
 * - Bei Klick auf „Bearbeiten“ wird ein Inline-Formular in die Tabelle eingeblendet
 * - Die Passwortänderung erfolgt über ein separates Modal mit Passwortfeld
 * - Die Anlage eines neuen Accounts blendet oberhalb der Tabelle ein Formular ein
 *
 * Anforderungen:
 * - Nur Benutzer mit Rolle `admin` haben Zugriff
 * - CSRF-Schutz ist aktiv für POST-Aktionen
 *
 * Abhängigkeiten:
 * - templates/dyndns_add_form.php
 * - templates/dyndns_edit_form.php
 * - actions/dyndns_delete.php
 * - actions/dyndns_password.php
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';

// Zugriff: Nur Admin (vor jeder Layout-Ausgabe; HTTP 403 und access_denied-Log, S39)
requireRole(['admin']);

include __DIR__ . '/../templates/layout.php';

// GET-Parameter
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$add_new = isset($_GET['add_new']) && $_GET['add_new'] === '1';

// DynDNS-Accounts laden (DyndnsReadService: nur aktive Admins laut Datenbank, explizite Spalten, kein
// password_hash). Übersetzung in die bisherige Struktur für die unveränderte Darstellung und die Formulare.
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/dyndns/DyndnsReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
$actor = actorFromSession();
$dyndnsReadService = new DyndnsReadService($pdo);
$accounts = [];
foreach ($dyndnsReadService->listAccounts($actor) as $a) {
    $accounts[] = [
        'id'           => $a['id'],
        'username'     => $a['username'],
        'hostname'     => $a['hostname'],
        'zone_id'      => $a['zoneId'],
        'zone_name'    => $a['zoneName'],
        'current_ipv4' => $a['currentIpv4'],
        'current_ipv6' => $a['currentIpv6'],
        'last_update'  => $a['lastUpdate'],
    ];
}
?>

<br><br>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= $LANG['dyndns_accounts'] ?></h2>
    <a href="pages/dyndns.php?add_new=1" class="btn btn-success">+ <?= $LANG['add_dyndns_account'] ?></a>
</div>

<!-- Ausklappbare Info-Box zur DynDNS-Integration -->
<div class="mb-3">
    <button class="btn btn-outline-info btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#dyndnsInfoBox">
        ℹ️ <?= $LANG['dyndns_info_toggle'] ?>
    </button>
    <div class="collapse mt-2" id="dyndnsInfoBox">
        <div class="alert alert-info mb-0" role="alert">
            <strong><?= $LANG['dyndns_info_title'] ?></strong><br><br>

            <?= $LANG['dyndns_info_text_1'] ?><br><br>

            <div class="input-group input-group-sm mt-2" style="max-width: 700px;">
                <input type="text"
                       id="dyndnsUrl"
                       class="form-control form-control-sm"
                       readonly
                       value="https://example.com<?= rtrim(BASE_URL, '/') ?>/api/v1/dyndns/update.php?myip=&lt;ipaddr&gt;&amp;myip6=&lt;ip6addr&gt;">
                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyDynDnsUrl()" title="<?= $LANG['dyndns_copy_title'] ?>">
                    📋
                </button>
            </div>

            <br>
            <strong><?= $LANG['dyndns_supported_parameters'] ?></strong>
            <ul class="mb-2">
                <li><code>myip</code> – <?= $LANG['ipv4_address'] ?></li>
                <li><code>myip6</code> – <?= $LANG['ipv6_address'] ?></li>
            </ul>

            <br>
            <small class="text-muted">
                <strong><?= $LANG['important'] ?></strong> <?= $LANG['dyndns_info_auth'] ?>
            </small>
        </div>
    </div>
</div>

<?php if ($add_new): ?>
    <?php include __DIR__ . '/../templates/dyndns_add_form.php'; ?>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th><?= $LANG['username'] ?></th>
                <th><?= $LANG['hostname'] ?></th>
                <th><?= $LANG['zone'] ?></th>
                <th>IPv4</th>
                <th>IPv6</th>
                <th><?= $LANG['last_update'] ?></th>
                <th><?= $LANG['actions'] ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $acc): ?>
                <?php if ((int)$acc['id'] === $edit_id): ?>
                    <tr class="table-warning">
                        <td><?= htmlspecialchars($acc['username']) ?></td>
                        <td><?= htmlspecialchars($acc['hostname']) ?></td>
                        <td><?= htmlspecialchars($acc['zone_name']) ?></td>
                        <td><?= htmlspecialchars($acc['current_ipv4'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($acc['current_ipv6'] ?? '-') ?></td>
                        <td><?= $acc['last_update'] ? htmlspecialchars($acc['last_update']) : '-' ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <button type="submit" form="editForm_<?= $acc['id'] ?>" class="btn btn-sm btn-success"><?= $LANG['save'] ?></button>
                                <a href="pages/dyndns.php" class="btn btn-sm btn-secondary"><?= $LANG['cancel'] ?></a>
                            </div>
                        </td>
                    </tr>
                    <?php $edit_account = $acc; include __DIR__ . '/../templates/dyndns_edit_form.php'; ?>
                <?php else: ?>
                    <tr>
                        <td><?= htmlspecialchars($acc['username']) ?></td>
                        <td><?= htmlspecialchars($acc['hostname']) ?></td>
                        <td><?= htmlspecialchars($acc['zone_name']) ?></td>
                        <td><?= htmlspecialchars($acc['current_ipv4'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($acc['current_ipv6'] ?? '-') ?></td>
                        <td><?= $acc['last_update'] ? htmlspecialchars($acc['last_update']) : '-' ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="pages/dyndns.php?edit_id=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-primary"><?= $LANG['edit'] ?></a>
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pwModal<?= $acc['id'] ?>"><?= $LANG['password'] ?></button>
                                <form method="post" action="actions/dyndns_delete.php" class="d-inline confirm-delete">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= $LANG['delete'] ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <!-- Passwort Modal für DynDNS -->
                <div class="modal fade" id="pwModal<?= $acc['id'] ?>" tabindex="-1" aria-labelledby="pwModalLabel<?= $acc['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form method="post" action="actions/dyndns_password.php">
                                <?= csrf_input() ?>
                                <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="pwModalLabel<?= $acc['id'] ?>">
                                        <?= sprintf($LANG['change_password_for'], htmlspecialchars($acc['username'])) ?>
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label"><?= $LANG['new_password'] ?></label>
                                        <input type="password" name="password" class="form-control" required>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= $LANG['cancel'] ?></button>
                                    <button type="submit" class="btn btn-sm btn-success"><?= $LANG['save'] ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
AssetRegistry::enqueueScript('js/dyndns.js');

include __DIR__ . '/../templates/layout_footer.php';
?>
