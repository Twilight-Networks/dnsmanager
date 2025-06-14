<?php
/**
 * Datei: zones.php
 * Zweck: Übersicht aller DNS-Zonen im System.
 * Details:
 * - Admins sehen alle Zonen, können Zonen anlegen und löschen.
 * - Zone-Admins sehen nur ihre eigenen Zonen und können diese bearbeiten.
 * Zusätzliche Funktion: Validierung der Zonendateien via named-checkzone.
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/diagnostics.php';
include __DIR__ . '/../templates/layout.php';

// Edit-ID aus GET-Parametern lesen (für Bearbeitungsmodus)
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;

// Zonen aus der Datenbank laden:
// - Admins sehen alle Zonen
// - Zone-Admins nur ihre zugewiesenen Zonen
if ($_SESSION['role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM zones ORDER BY name");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("
        SELECT z.* FROM zones z
        JOIN user_zones uz ON uz.zone_id = z.id
        WHERE uz.user_id = ?
        ORDER BY z.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
}
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Server-Zuweisungen pro Zone laden (für Master/Secondary-Anzeige)
$stmt_links = $pdo->prepare("
    SELECT zs.zone_id, s.name, zs.is_master, s.active
    FROM zone_servers zs
    JOIN servers s ON s.id = zs.server_id
    ORDER BY s.name
");
$stmt_links->execute();

$server_links = [];
while ($row = $stmt_links->fetch(PDO::FETCH_ASSOC)) {
    $server_links[$row['zone_id']][] = [
        'name' => $row['active'] ? $row['name'] : $row['name'] . ' (' . $LANG['inactive'] . ')',
        'is_master' => (bool)$row['is_master']
    ];
}

// Diagnosedaten aus der Datenbank laden
$diagnostics = getDiagnosticResults($pdo);
$zone_results = $diagnostics['zone'];

// Ergebnisse nach Zonenname gruppieren und besten Status ermitteln
$zone_statuses = [];

foreach ($zone_results as $entry) {
    $zone_name = $entry['zone'] ?? '(unbekannt)';
    $server_name = $entry['server'] ?? '(unbekannt)';
    $status = $entry['zone_status']['status'];
    $output = $entry['zone_status']['message'];

    if (!isset($zone_statuses[$zone_name])) {
        $zone_statuses[$zone_name] = ['status' => $status, 'output' => "($server_name)\n$output"];
    } else {
        $current = $zone_statuses[$zone_name]['status'];
        $overwrite = false;

        if ($current === 'ok' && $status !== 'ok') $overwrite = true;
        if ($current === 'warning' && $status === 'error') $overwrite = true;

        if ($overwrite) {
            $zone_statuses[$zone_name] = ['status' => $status, 'output' => "($server_name)\n$output"];
        }
    }
}

// Statusinformationen den geladenen Zonen zuweisen
foreach ($zones as &$zone) {
    $zname = $zone['name'];

    $zone['status'] = 'ok';
    $zone['output'] = '';

    if (isset($zone_statuses[$zname])) {
        $zone['output'] = $zone_statuses[$zname]['output'];

        if (in_array($zone_statuses[$zname]['status'], ['error', 'not_found'], true)) {
            $zone['status'] = 'error';
        } elseif ($zone_statuses[$zname]['status'] === 'warning') {
            $zone['status'] = 'warning';
        }
    }

    // Geänderte Zonen markieren, sofern keine Fehler vorliegen
    if ($zone['status'] === 'ok' && $zone['changed'] == 1) {
        $zone['status'] = 'changed';
    }

    // Serververknüpfungen zuweisen
    $zone['servers'] = $server_links[$zone['id']] ?? [];
}
unset($zone); // Referenz aufheben

// Zonen nach Typ aufteilen
$forward_zones = array_filter($zones, fn($z) => $z['type'] === 'forward');
$reverse_zones = array_filter($zones, fn($z) => $z['type'] === 'reverse');
?>

<br>
<br>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= $LANG['dns_zones'] ?></h2>
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="pages/zones.php?add_new=1" class="btn btn-success">+ <?= $LANG['add_zone'] ?></a>
    <?php endif; ?>
</div>

<?php if ($_SESSION['role'] === 'admin' && isset($_GET['add_new']) && $_GET['add_new'] == '1'): ?>
    <?php include __DIR__ . '/../templates/zone_add_form.php'; ?>
<?php endif; ?>


<?php
// Zonenstatus-Übersicht
// Initialstatus
$has_zone_errors = false;
$has_zone_warnings = false;
$zone_diagnostics_output = [];

// Einzelbewertungen pro Zonen-Diagnoseeintrag
foreach ($zone_results as $entry) {
    $status = $entry['zone_status']['status'] ?? 'ok';
    $zone = htmlspecialchars($entry['zone'] ?? '(unbekannt)');
    $server = htmlspecialchars($entry['server'] ?? '(unbekannt)');
    $message = htmlspecialchars($entry['zone_status']['message'] ?? '');

    if (in_array($status, ['error', 'not_found'], true)) $has_zone_errors = true;
    elseif ($status === 'warning') $has_zone_warnings = true;

    // Detaillierte Ausgabe nur bei Problemen
    if ($status !== 'ok') {
        $zone_diagnostics_output[] = "<strong>{$zone} @ {$server}</strong><br><pre class='mb-2'>{$message}</pre>";
    }
}

// Zusammenfassung für Statusanzeige oben
$overall_zone_class = $has_zone_errors ? 'danger' : ($has_zone_warnings ? 'warning' : 'success');
$overall_zone_message = $has_zone_errors
    ? '❌ ' . $LANG['zone_errors']
    : ($has_zone_warnings ? '⚠️ ' . $LANG['zone_warnings'] : '✅ ' . $LANG['zone_ok']);
?>

<!-- Visualisierung: Zonenstatus-Box mit optional ausklappbaren Details -->
<div class="card mb-4"<?= $overall_zone_class !== 'success' ? ' onclick="toggleZoneDiagnostics()" style="cursor: pointer;"' : '' ?>>
    <div class="card-body">
        <h5><?= $LANG['zone_status'] ?></h5>
        <div class="alert alert-<?= $overall_zone_class ?> mb-0">
            <?= $overall_zone_message ?>
        </div>
        <div id="zoneDiagnosticsBlock"<?= $overall_zone_class !== 'success' ? ' style="display: none;"' : ' style="display: none;" hidden' ?> class="mt-3 small">
            <?php foreach ($zone_diagnostics_output as $block): ?>
                <div class="alert alert-<?= $overall_zone_class ?>"><?= $block ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<br>

<!-- Forward Lookup Zonen -->
<h4>Forward Lookup <?= $LANG['zones'] ?></h4>
<table class="table table-bordered align-middle">
    <thead class="table-light">
        <tr>
            <th><?= $LANG['name'] ?></th>
            <th class="coltbl-ttl">TTL</th>
            <th class="coltbl-desc"><?= $LANG['description'] ?></th>
            <th class="coltbl-dnssrv"><?= $LANG['dns_servers'] ?></th>
            <th class="coltbl-actions"><?= $LANG['actions'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($forward_zones as $zone): ?>
            <!-- Anzeigezeile -->
            <tr class="<?= ($edit_id === (int)$zone['id']) ? 'table-warning' : '' ?>">
                <td>
                    <?php if ($zone['status'] === 'error'): ?>
                        <span title="<?= $LANG['zone_icon_error'] ?>">❌</span>
                    <?php elseif ($zone['status'] === 'warning'): ?>
                        <span title="<?= $LANG['zone_icon_warning'] ?>">⚠️</span>
                    <?php elseif ($zone['status'] === 'changed'): ?>
                        <span title="<?= $LANG['zone_icon_changed'] ?>">⏳</span>
                    <?php elseif ($zone['status'] === 'ok'): ?>
                        <span title="<?= $LANG['zone_icon_ok'] ?>">✅</span>
                    <?php endif; ?>
                    <?= htmlspecialchars($zone['name']) ?>
                </td>
                <td><?= $zone['ttl'] ?></td>
                <td><?= htmlspecialchars($zone['description'] ?? '') ?></td>
                <td>
                    <?php foreach ($zone['servers'] as $srv): ?>
                        <?php
                        $is_inactive = stripos($srv['name'], ' (' . $LANG['inactive'] . ')') !== false;
                        $badge_class = $srv['is_master'] ? 'bg-primary' : 'bg-secondary';
                        $badge_style = $is_inactive ? 'opacity: 0.5;' : '';
                        ?>
                        <span class="badge <?= $badge_class ?>" style="<?= $badge_style ?>"
                              title="<?= $is_inactive ? $LANG['server_inactive'] : '' ?>">
                            <?= $srv['is_master'] ? 'Master' : 'Slave' ?>:
                            <?= htmlspecialchars($srv['name']) ?>
                        </span>
                    <?php endforeach; ?>
                </td>
                <td class="coltbl-actions">
                    <div class="d-flex flex-wrap gap-1">
                        <?php if ($edit_id === (int)$zone['id']): ?>
                            <button type="submit" form="editForm_<?= $zone['id'] ?>" class="btn btn-sm btn-success"><?= $LANG['save'] ?></button>
                            <a href="pages/zones.php" class="btn btn-sm btn-secondary"><?= $LANG['cancel'] ?></a>
                        <?php else: ?>
                            <?php if (in_array($_SESSION['role'], ['admin', 'zoneadmin'])): ?>
                                <a href="pages/zones.php?edit_id=<?= $zone['id'] ?>" class="btn btn-sm btn-outline-primary"><?= $LANG['edit'] ?></a>
                            <?php endif; ?>
                            <a href="pages/records.php?zone_id=<?= $zone['id'] ?>" class="btn btn-sm btn-outline-warning"><?= $LANG['records'] ?></a>
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <form method="post" action="actions/zone_delete.php" class="d-inline confirm-delete">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= $zone['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><?= $LANG['delete'] ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>

            <!-- Bearbeitungsformular -->
            <?php if ($edit_id === (int)$zone['id']): ?>
                <?php include __DIR__ . '/../templates/zone_edit_form.php'; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </tbody>
</table>
<br>
<br>
<h4>Reverse Lookup <?= $LANG['zones'] ?></h4>
<table class="table table-bordered align-middle">
    <thead class="table-light">
        <tr>
            <th><?= $LANG['name'] ?></th>
            <th class="prefix">Prefix</th>
            <th class="coltbl-ttl">TTL</th>
            <?= $LANG['description'] ?>
            <th class="coltbl-dnssrv"><?= $LANG['dns_servers'] ?></th>
            <th class="coltbl-actions"><?= $LANG['actions'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($reverse_zones as $zone): ?>
            <tr class="<?= ($edit_id === (int)$zone['id']) ? 'table-warning' : '' ?>">
                <td>
                    <?php if ($zone['status'] === 'error'): ?>
                        <span title="Fehlerhafte Zonendatei">❌</span>
                    <?php elseif ($zone['status'] === 'warning'): ?>
                        <span title="Warnung bei named-checkzone">⚠️</span>
                    <?php elseif ($zone['status'] === 'changed'): ?>
                        <span title="Änderung noch nicht veröffentlicht">⏳</span>
                    <?php elseif ($zone['status'] === 'ok'): ?>
                        <span title="Zonendatei gültig">✅</span>
                    <?php endif; ?>
                    <?= htmlspecialchars((string)($zone['name'] ?? '')) ?>
                </td>
                <td class="coltbl-prefix"><?= $zone['prefix_length'] ?? '-' ?></td>
                <td class="coltbl-ttl"><?= $zone['ttl'] ?></td>
                <td class="coltbl-desc"><?= htmlspecialchars((string)($zone['description'] ?? '')) ?></td>
                <td>
                    <?php foreach ($zone['servers'] as $srv): ?>
                        <?php
                        $is_inactive = stripos($srv['name'], ' (' . $LANG['inactive'] . ')') !== false;
                        $badge_class = $srv['is_master'] ? 'bg-primary' : 'bg-secondary';
                        $badge_style = $is_inactive ? 'opacity: 0.5;' : '';
                        ?>
                        <span class="badge <?= $badge_class ?>" style="<?= $badge_style ?>"
                              title="<?= $is_inactive ? 'Dieser Server ist derzeit deaktiviert.' : '' ?>">
                            <?= $srv['is_master'] ? 'Master' : 'Slave' ?>:
                            <?= htmlspecialchars($srv['name']) ?>
                        </span><br>
                    <?php endforeach; ?>
                </td>
                <td class="coltbl-actions">
                    <div class="d-flex flex-wrap gap-1">
                        <?php if ($edit_id === (int)$zone['id']): ?>
                            <button type="submit" form="editForm_<?= $zone['id'] ?>" class="btn btn-sm btn-success"><?= $LANG['save'] ?></button>
                            <a href="pages/zones.php" class="btn btn-sm btn-secondary"><?= $LANG['cancel'] ?></a>
                        <?php else: ?>
                            <?php if (in_array($_SESSION['role'], ['admin', 'zoneadmin'])): ?>
                                <a href="pages/zones.php?edit_id=<?= $zone['id'] ?>" class="btn btn-sm btn-outline-primary"><?= $LANG['edit'] ?></a>
                            <?php endif; ?>
                            <a href="pages/records.php?zone_id=<?= $zone['id'] ?>" class="btn btn-sm btn-outline-warning"><?= $LANG['records'] ?></a>
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <form method="post" action="actions/zone_delete.php" class="d-inline confirm-delete">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= $zone['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><?= $LANG['delete'] ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>

            <!-- Bearbeitungsformular -->
            <?php if ($edit_id === (int)$zone['id']): ?>
                <?php include __DIR__ . '/../templates/zone_edit_form.php'; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- JavaScript auslagern -->
<?php
AssetRegistry::enqueueScript('js/zones.js');
AssetRegistry::enqueueScript('js/zone_add_form.js');
AssetRegistry::enqueueScript('js/zone_edit_form.js');
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
