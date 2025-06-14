<?php
/**
 * Datei: servers.php
 * Zweck: Verwaltungsseite für DNS-Server im System.
 *
 * Funktionen:
 * - Zeigt eine Übersicht aller DNS-Server aus der Datenbank.
 * - Ermöglicht Administratoren das Hinzufügen, Bearbeiten und Löschen von Servereinträgen.
 * - Zeigt dem Benutzer das Bearbeitungsformular inline innerhalb der Tabelle.
 * - Nur Benutzer mit Rolle „admin“ oder „zoneadmin“ sehen die Bearbeitungsoption.
 * - Nur „admin“ darf neue Server hinzufügen oder bestehende löschen.
 *
 * Abhängigkeiten:
 * - templates/layout.php            (Layout-Struktur)
 * - templates/server_add_form.php (Formular zur Server-Erstellung)
 * - templates/server_edit_form.php (Formular zur Server-Bearbeitung)
 * - js/server_add_form.js         (Client-Logik für Hinzufügen)
 * - js/server_edit_form.js        (Client-Logik für Bearbeiten)
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
include __DIR__ . '/../templates/layout.php';

// Zugriffsbeschränkung: Nur Administratoren dürfen diese Seite aufrufen
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

// Parameter verarbeiten
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$add_new = isset($_GET['add_new']) && $_GET['add_new'] == '1';

// Server laden (nur Admins sehen alle Server)
$stmt = $pdo->prepare("SELECT * FROM servers ORDER BY name");
$stmt->execute();
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$diagnostics = getDiagnosticResults($pdo);
$server_diag = $diagnostics['server'];

$server_status = [];
foreach ($server_diag as $serverId => $checks) {
    if (isset($checks['server_status']['status'])) {
        $server_status[$serverId] = $checks['server_status']['status'];
    }
}

// Statusindikatoren für Diagnoseauswertung
$has_server_errors = false;
$has_server_warnings = false;
$server_diagnostics_output = [];

foreach ($server_diag as $entry) {
    foreach (['server_status', 'zone_conf_status'] as $check) {
        if (!isset($entry[$check])) {
            continue;
        }

        $status = $entry[$check]['status'] ?? 'ok';
        $server = htmlspecialchars($entry[$check]['server'] ?? '(unbekannt)');
        $message = htmlspecialchars($entry[$check]['message'] ?? '');

        if ($status === 'error') $has_server_errors = true;
        elseif ($status === 'warning') $has_server_warnings = true;

        if ($status !== 'ok') {
            $server_diagnostics_output[] = "<strong>$server</strong><br><pre class='mb-2'>{$message}</pre>";
        }
    }
}

if ($has_server_errors) {
    $overall_server_class = 'danger';
    $overall_server_message = '❌ ' . $LANG['server_error'];
} elseif ($has_server_warnings) {
    $overall_server_class = 'warning';
    $overall_server_message = '⚠️ ' . $LANG['server_warning'];
} else {
    $overall_server_class = 'success';
    $overall_server_message = '✅ ' . $LANG['server_ok'];
}
?>

<br>
<br>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= $LANG['dns_servers'] ?></h2>
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="pages/servers.php?add_new=1" class="btn btn-success">+ <?= $LANG['add_server'] ?></a>
    <?php endif; ?>
</div>

<div class="card mb-4"<?= $overall_server_class !== 'success' ? ' onclick="toggleServerDiagnostics()" style="cursor: pointer;"' : '' ?>>
    <div class="card-body">
        <h5><?= $LANG['server_status'] ?></h5>
            <div class="alert alert-<?= $overall_server_class ?> mb-0">
                <?= htmlspecialchars($overall_server_message) ?>
            </div>
        <div id="serverDiagnosticsBlock"<?= $overall_server_class !== 'success' ? ' style="display: none;"' : ' style="display: none;" hidden' ?> class="mt-3 small">
            <?php foreach ($server_diagnostics_output as $block): ?>
                <div class="alert alert-<?= $overall_server_class ?>"><?= $block ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<br>

<?php if ($_SESSION['role'] === 'admin' && $add_new): ?>
    <?php include __DIR__ . '/../templates/server_add_form.php'; ?>
<?php endif; ?>

<table class="table table-bordered align-middle">
    <thead class="table-light">
        <tr>
            <th class="coltbl-name"><?= $LANG['name'] ?></th>
            <th class="coltbl-ip">DNS-IP</th>
            <th class="coltbl-ip">API-IP</th>
            <th class="coltbl-server-local"><?= $LANG['local'] ?></th>
            <th class="coltbl-server-activ"><?= $LANG['active'] ?></th>
            <th class="coltbl-actions"><?= $LANG['actions'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($servers as $server): ?>
            <?php if ($edit_id === (int)$server['id']): ?>
                <!-- Anzeigezeile (gelb) -->
                <tr class="table-warning">
                    <td><?= htmlspecialchars($server['name']) ?></td>
                    <td>
                        <?= htmlspecialchars($server['dns_ip4']) ?><br>
                        <?php if (!empty($server['dns_ip6'])): ?>
                            <?= htmlspecialchars($server['dns_ip6']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($server['api_ip'] ?? '-') ?></td>
                        <td><?= $server['is_local'] ? $LANG['yes'] : $LANG['no'] ?></td>
                        <td><?= $server['active'] ? $LANG['yes'] : $LANG['no'] ?></td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="submit" form="editForm_<?= $server['id'] ?>" class="btn btn-sm btn-success"><?= $LANG['save'] ?></button>
                            <a href="pages/servers.php" class="btn btn-sm btn-secondary"><?= $LANG['cancel'] ?></a>
                        </div>
                    </td>
                </tr>

                <!-- Bearbeitungsformular darunter -->
                <?php $edit_server = $server; include __DIR__ . '/../templates/server_edit_form.php'; ?>
            <?php else: ?>
                <tr>
                    <td>
                        <?php
                        $status_icon = '';
                        $status = $server_status[$server['id']] ?? null;

                        if ((int)$server['is_local'] === 1) {
                            $base_icon = '🖥️';
                        } else {
                            $base_icon = '';
                        }

                        if ($status === 'ok') {
                            $status_icon = '✅';
                        } elseif ($status === 'warning') {
                            $status_icon = '⚠️';
                        } elseif ($status === 'error') {
                            $status_icon = '❌';
                        } else {
                            $status_icon = '⚪';
                        }

                        echo "{$status_icon} {$base_icon} " . htmlspecialchars($server['name']);
                        ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($server['dns_ip4']) ?><br>
                        <?php if (!empty($server['dns_ip6'])): ?>
                            <?= htmlspecialchars($server['dns_ip6']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($server['api_ip'] ?? '-') ?></td>
                    <td><?= $server['is_local'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= $server['active'] ? 'Ja' : 'Nein' ?></td>
                    <td class="coltbl-actions">
                        <div class="d-flex flex-wrap gap-1">
                            <?php if (in_array($_SESSION['role'], ['admin', 'zoneadmin'])): ?>
                                <a href="pages/servers.php?edit_id=<?= $server['id'] ?>" class="btn btn-sm btn-outline-primary"><?= $LANG['edit'] ?></a>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <form class="d-inline">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-warning btn-bind-reload"
                                            data-server-id="<?= $server['id'] ?>">
                                        <?= $LANG['bind_reload'] ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <form method="post" action="actions/server_delete.php" class="d-inline confirm-delete">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= $server['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= $LANG['delete'] ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Modal: BIND Reload bestätigen -->
<!-- (wird nur auf dieser Seite benötigt – daher lokal definiert -->
<div class="modal fade" id="reloadConfirmModal" tabindex="-1" aria-labelledby="reloadConfirmLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="reloadConfirmLabel"><?= $LANG['bind_reload_title'] ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
          </div>
          <div class="modal-body">
            <?= $LANG['bind_reload_confirm'] ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $LANG['cancel'] ?></button>
            <form id="reloadConfirmForm" method="post" action="actions/bind_reload.php" class="d-inline">
                <?= csrf_input() ?>
                <input type="hidden" name="id" id="reload_server_id">
                <button type="submit" class="btn btn-primary"><?= $LANG['bind_reload'] ?></button>
            </form>
          </div>
        </div>
      </div>
    </div>
</div>
<?php
AssetRegistry::enqueueScript('js/servers.js');
AssetRegistry::enqueueScript('js/server_add_form.js');
AssetRegistry::enqueueScript('js/server_edit_form.js');

include __DIR__ . '/../templates/layout_footer.php';
