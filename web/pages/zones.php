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
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/zones/ZoneReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
include __DIR__ . '/../templates/layout.php';

// Edit-ID aus GET-Parametern lesen (für Bearbeitungsmodus)
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;

// Alle Fachdaten dieser Seite stammen aus dem ZoneReadService (Sichtbarkeit über ZoneAccess:
// admin alle Zonen, zoneadmin zugewiesene Zonen). Die Übersetzung des normalisierten Ergebnisses in die
// bisherigen Template-Felder erfolgt ausschließlich hier im Web-Layer.
$actor = actorFromSession();
$zoneReadService = new ZoneReadService($pdo, new ZoneAccess($pdo));

// Zonen inkl. Serverzuweisungen laden – vollständige Liste über alle Seiten
$zones = [];
$offset = 0;
do {
    $page = $zoneReadService->listZones($actor, ZoneReadService::MAX_LIMIT, $offset);
    foreach ($page['zones'] as $z) {
        $zone_servers = [];
        foreach ($z['servers'] as $srv) {
            $zone_servers[] = [
                'name'      => $srv['active'] ? $srv['name'] : $srv['name'] . ' (' . $LANG['inactive'] . ')',
                'is_master' => $srv['role'] === 'master',
            ];
        }
        $zones[] = [
            'id'            => $z['id'],
            'name'          => $z['name'],
            'type'          => $z['type'],
            'ttl'           => $z['ttl'],
            'prefix_length' => $z['prefixLength'],
            'description'   => $z['description'],
            'changed'       => $z['pendingPublish'] ? 1 : 0,
            'servers'       => $zone_servers,
        ];
    }
    $offset += ZoneReadService::MAX_LIMIT;
} while ($page['hasMore']);

// Bearbeitungsformular: nur für eine in der Liste sichtbare Zone. Zone, Serverzuweisung und wählbare
// Server über den ZoneReadService (getZone prüft die Berechtigung erneut in derselben Abfrage; ist die
// Zuweisung inzwischen entzogen, wird kein Formular ausgegeben – E26).
$zone_edit = null;
$zone_edit_servers = [];
$zone_edit_assigned = [];
if ($edit_id !== null && in_array($edit_id, array_column($zones, 'id'), true)) {
    $z = $zoneReadService->getZone($actor, $edit_id);
    if ($z !== null) {
        $zone_edit = [
            'id'            => $z['id'],
            'name'          => $z['name'],
            'type'          => $z['type'],
            'ttl'           => $z['ttl'],
            'prefix_length' => $z['prefixLength'],
            'description'   => $z['description'],
            'allow_dyndns'  => $z['allowDyndns'] ? 1 : 0,
            'soa_ns'        => $z['soa']['primaryNs'],
            'soa_mail'      => $z['soa']['mail'],
            'soa_refresh'   => $z['soa']['refresh'],
            'soa_retry'     => $z['soa']['retry'],
            'soa_expire'    => $z['soa']['expire'],
            'soa_minimum'   => $z['soa']['minimum'],
            'edit_version'  => $z['editVersion'],
        ];
        foreach ($z['servers'] as $srv) {
            $zone_edit_assigned[$srv['id']] = $srv['role'] === 'master' ? 1 : 0;
        }
        foreach ($zoneReadService->listAssignableServers($actor, false) as $srv) {
            $zone_edit_servers[] = ['id' => $srv['id'], 'name' => $srv['name'], 'dns_ip4' => $srv['dnsIp4'], 'dns_ip6' => $srv['dnsIp6'], 'active' => $srv['active']];
        }
    }
}

// Anlageformular (nur admin): aktive Server zur Zuweisung
$zone_add_servers = [];
if ($_SESSION['role'] === 'admin' && isset($_GET['add_new']) && $_GET['add_new'] == '1') {
    foreach ($zoneReadService->listAssignableServers($actor, true) as $srv) {
        $zone_add_servers[] = ['id' => $srv['id'], 'name' => $srv['name'], 'dns_ip4' => $srv['dnsIp4'], 'dns_ip6' => $srv['dnsIp6']];
    }
}

// Zonendiagnosen und ihre Auswertung über den ZoneReadService (Berechtigung in derselben Abfrage, ZoneAccess,
// S25; Statusregeln seit Paket 6 im Core: getZoneStatusOverview, zoneDisplayStatus). Hier nur Übersetzung in
// die bisherige Darstellung.
$zone_status_overview = $zoneReadService->getZoneStatusOverview($actor);

// Statusinformationen den geladenen Zonen zuweisen
// changed: 0 = sauber, 1 = wartet auf Publish, 2 = von Publish übernommen bzw. nicht sicher abgeschlossen
foreach ($zones as &$zone) {
    $zone['status'] = ZoneReadService::zoneDisplayStatus(
        $zone_status_overview['zones'][$zone['id']]['status'] ?? null,
        (int)$zone['changed'] !== 0
    );
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
$zone_diagnostics_output = [];

// Gesamtbewertung und Problemeinträge aus dem Core (getZoneStatusOverview); hier nur Darstellung
$has_zone_errors = $zone_status_overview['hasErrors'];
$has_zone_warnings = $zone_status_overview['hasWarnings'];

// Detaillierte Ausgabe nur bei Problemen
foreach ($zone_status_overview['problems'] as $problem) {
    $zone = htmlspecialchars($problem['zoneName'] ?? '(unbekannt)');
    $server = htmlspecialchars($problem['serverName'] ?? '(unbekannt)');
    $message = htmlspecialchars($problem['message'] ?? '');
    $zone_diagnostics_output[] = "<strong>{$zone} @ {$server}</strong><br><pre class='mb-2'>{$message}</pre>";
}

// Zusammenfassung für Statusanzeige oben
$overall_zone_class = $has_zone_errors ? 'danger' : ($has_zone_warnings ? 'warning' : 'success');
$overall_zone_message = $has_zone_errors
    ? '❌ ' . $LANG['zone_errors']
    : ($has_zone_warnings ? '⚠️ ' . $LANG['zone_warnings'] : '✅ ' . $LANG['zone_ok']);
?>

<!-- Visualisierung: Zonenstatus-Box mit optional ausklappbaren Details -->
<div class="card mb-4<?= $overall_zone_class !== 'success' ? ' app-clickable' : '' ?>"<?= $overall_zone_class !== 'success' ? ' data-zone-diagnostics-toggle' : '' ?>>
    <div class="card-body">
        <h5><?= $LANG['zone_status'] ?></h5>
        <div class="alert alert-<?= $overall_zone_class ?> mb-0">
            <?= $overall_zone_message ?>
        </div>
        <div id="zoneDiagnosticsBlock"<?= $overall_zone_class !== 'success' ? '' : ' hidden' ?> class="mt-3 small app-hidden">
            <?php foreach ($zone_diagnostics_output as $block): ?>
                <div class="alert alert-<?= $overall_zone_class ?>"><?= $block ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<br>

<!-- Forward Lookup Zonen -->
<h4>Forward Lookup <?= $LANG['zones'] ?></h4>
<div class="table-responsive">
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
                            $badge_inactive_class = $is_inactive ? ' app-badge-inactive' : '';
                            ?>
                            <span class="badge <?= $badge_class ?><?= $badge_inactive_class ?>"
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
                                        <?= ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']]) ?>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- Bearbeitungsformular -->
                <?php if ($edit_id === (int)$zone['id'] && $zone_edit !== null): ?>
                    <?php $zone = $zone_edit; include __DIR__ . '/../templates/zone_edit_form.php'; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<br>
<br>
<h4>Reverse Lookup <?= $LANG['zones'] ?></h4>
<div class="table-responsive">
    <table class="table table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th><?= $LANG['name'] ?></th>
                <th class="coltbl-prefix">Prefix</th>
                <th class="coltbl-ttl">TTL</th>
                <th class="coltbl-desc"><?= $LANG['description'] ?></th>
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
                            $badge_inactive_class = $is_inactive ? ' app-badge-inactive' : '';
                            ?>
                            <span class="badge <?= $badge_class ?><?= $badge_inactive_class ?>"
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
                                        <?= ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']]) ?>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- Bearbeitungsformular -->
                <?php if ($edit_id === (int)$zone['id'] && $zone_edit !== null): ?>
                    <?php $zone = $zone_edit; include __DIR__ . '/../templates/zone_edit_form.php'; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- JavaScript auslagern -->
<?php
AssetRegistry::enqueueScript('js/zones.js');
AssetRegistry::enqueueScript('js/zone_add_form.js');
AssetRegistry::enqueueScript('js/zone_edit_form.js');
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
