<?php
/**
 * Datei: system_health.php
 * Zweck: Ausführliche Anzeige des aktuellen Systemstatus.
 *
 * Details:
 * - PHP-Version, PHP-Module, named-Status, Config-Status, Dateirechte, Zonenprüfungen.
 * - Nur für Admins zugänglich.
 *
 * Sicherheit:
 * - Zugriffsschutz über Session (common.php).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/diagnostics.php';
include __DIR__ . '/../templates/layout.php';

// Zugriffsbeschränkung: Nur Administratoren dürfen diese Seite aufrufen
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

// Systemstatus
$php_version = get_php_version();
$php_modules = check_required_php_modules();
$file_issues = check_all_file_permissions(dirname(__DIR__));
$config_issues = check_config_validity();
$php_version_supported = version_compare($php_version, '8.1', '>=');

// Wenn "Status jetzt aktualisieren" gedrückt wurde, Monitoring-Skript direkt ausführen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_update'])) {
    $script = __DIR__ . '/../scripts/monitoring_run-cli.php';

    if (is_file($script) && is_readable($script)) {
        // Monitoring synchron aufrufen – so ist die Datenbank danach garantiert aktuell
        $cmd = "php " . escapeshellarg($script);
        exec($cmd, $outputLines, $exitCode);

        // Fehlerlog + Toast anzeigen
        if ($exitCode !== 0) {
            toastError(
                $LANG['toast_monitoring_failed'],
                "Fehler beim Monitoring-Aufruf: Exit-Code $exitCode"
            );
        } else {
            toastSuccess(
                $LANG['toast_monitoring_success'],
                "Alle Prüfungen wurden ausgeführt. Die Ergebnisse sind jetzt aktuell."
            );
        }

        // Sofortige Umleitung, damit Toast auch greift
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        toastError(
            $LANG['toast_monitoring_missing'],
            "Pfad: <code>{$script}</code>"
        );
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Diagnosedaten aus der Datenbank laden
$diagnostics = getDiagnosticResults($pdo);

$conf_results = $diagnostics['server'];
$zone_results = $diagnostics['zone'];

$conf_errors = [];
$conf_warnings = [];
$conf_outputs = [];

foreach ($conf_results as $entry) {
    $status = $entry['zone_conf_status']['status'];
    $server = htmlspecialchars($entry['zone_conf_status']['server'] ?? 'unbekannt');
    $output = htmlspecialchars($entry['zone_conf_status']['message']);

    if ($status === 'error') {
        $conf_errors[] = true;
    } elseif ($status === 'warning') {
        $conf_warnings[] = true;
    }

    if ($status !== 'ok') {
        $conf_outputs[] = "<strong>$server</strong><br><pre>$output</pre>";
    }
}

$has_errors = false;
$has_warnings = false;
$outputs = [];

foreach ($zone_results as $entry) {
    $status = $entry['zone_status']['status'];
    $zone = htmlspecialchars($entry['zone']);
    $server = htmlspecialchars($entry['server']);
    $output = htmlspecialchars($entry['zone_status']['message']);

    if (in_array($status, ['error', 'not_found'], true)) {
        $has_errors = true;
    } elseif ($status === 'warning') {
        $has_warnings = true;
    }

    if ($status !== 'ok') {
        $outputs[] = "<strong>$zone @ $server</strong><br><pre>$output</pre>";
    }
}

$remote_errors = [];
$remote_outputs = [];

foreach ($conf_results as $entry) {
    if (!isset($entry['server_status'])) {
        continue;
    }

    $status = $entry['server_status']['status'];
    $server = htmlspecialchars($entry['server_status']['server'] ?? 'unbekannt');
    $output = htmlspecialchars($entry['server_status']['message']);

    if ($status === 'error') {
        $remote_errors[] = true;
    }

    if ($status !== 'ok') {
        $remote_outputs[] = "<strong>$server</strong><br><pre>$output</pre>";
    }
}
?>

<br>
<br>
<h2 class="mb-4"><?= $LANG['system_status'] ?></h2>

<form method="post" action="pages/system_health.php" class="mb-3">
    <input type="hidden" name="force_update" value="1">
    <button type="submit" class="btn btn-sm btn-outline-primary">
        🔄 <?= $LANG['system_update_now'] ?>
    </button>
</form>

<div class="card mb-4">
    <div class="card-body">
        <table class="table table-bordered align-middle">
            <tbody>
                <!-- Konfigurationsprüfung -->
                <tr style="cursor: <?= empty($config_issues) ? 'default' : 'pointer' ?>;" <?= empty($config_issues) ? '' : 'onclick="toggleConfigIssues()"' ?>>
                    <th><?= $LANG['config_check'] ?></th>
                    <td><?= empty($config_issues)
                        ? $LANG['config_ok']
                        : sprintf($LANG['config_errors_found'], count($config_issues)) ?></td>
                        <td class="text-<?= empty($config_issues) ? 'success' : 'danger' ?>">
                            <?= empty($config_issues)
                                ? '✅ ' . $LANG['status_ok']
                                : '❌ ' . $LANG['status_error'] ?>
                        </td>
                </tr>
                <?php if (!empty($config_issues)): ?>
                <tr id="config-issues-row" style="display:none;">
                    <td colspan="3">
                    <div class="alert alert-danger small mb-3">
                        <strong>⚠️ <?= $LANG['hint'] ?>:</strong> <?= $LANG['config_hint'] ?> <code>ui_config.php</code>.<br><br>
                        <ul class="mb-0">
                            <?php foreach ($config_issues as $issue): ?>
                                <li><?= htmlspecialchars($issue) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- PHP-Version -->
                <tr>
                    <th><?= $LANG['php_version_local'] ?></th>
                    <td style="width: 20%;"><?= htmlspecialchars($php_version) ?></td>
                    <td class="text-<?= $php_version_supported ? 'success' : 'danger' ?>">
                        <?= $php_version_supported
                            ? '✅ ' . $LANG['status_ok']
                            : '❌ ' . $LANG['status_outdated'] ?>
                    </td>
                </tr>

                <!-- PHP-Module -->
                <tr>
                    <th><?= $LANG['php_modules_local'] ?></th>
                    <td>
                        <?php foreach ($php_modules as $module => $loaded): ?>
                            <?= htmlspecialchars($module) ?><br>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php foreach ($php_modules as $loaded): ?>
                            <div class="text-<?= $loaded ? 'success' : 'danger' ?>">
                                <?= $loaded
                                    ? '✅ ' . $LANG['status_ok']
                                    : '❌ ' . $LANG['status_missing'] ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>

                <!-- Dateiberechtigungen -->
                <tr style="cursor: <?= empty($file_issues) ? 'default' : 'pointer' ?>;" <?= empty($file_issues) ? '' : 'onclick="toggleFileIssues()"' ?>>
                    <th><?= $LANG['file_permissions_local'] ?></th>
                    <td><?= empty($file_issues)
                        ? $LANG['file_permissions_ok']
                        : sprintf($LANG['file_permissions_errors'], count($file_issues)) ?></td>
                    <td class="text-<?= empty($file_issues) ? 'success' : 'danger' ?>">
                        <?= empty($file_issues)
                            ? '✅ ' . $LANG['status_ok']
                            : '❌ ' . $LANG['status_error'] ?>
                    </td>
                </tr>
                <?php if (!empty($file_issues)): ?>
                <tr id="file-issues-row" style="display:none;">
                    <td colspan="3">
                        <div class="alert alert-danger small mb-3">
                            <strong>⚠️ <?= $LANG['hint'] ?>:</strong> <?= $LANG['file_permissions_hint'] ?><br><br>
                                <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Datei</th>
                                        <th>Ist</th>
                                        <th>Soll</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($file_issues as $issue): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($issue['path']) ?></td>
                                            <td><?= htmlspecialchars($issue['actual']) ?></td>
                                            <td><?= htmlspecialchars(
                                                is_array($issue['expected'])
                                                    ? 'Owner: ' . $issue['expected']['owner'] . ', Group: ' . $issue['expected']['group'] . ', Perms: ' . $issue['expected']['perms']
                                                    : $issue['expected']
                                            ) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- Serverstatus -->
                <tr style="cursor: <?= empty($remote_outputs) ? 'default' : 'pointer' ?>;" <?= empty($remote_outputs) ? '' : 'onclick="toggleRemoteCheck()"' ?>>
                    <th><?= $LANG['server_status'] ?></th>
                    <td><?= empty($remote_outputs)
                        ? $LANG['server_ok']
                        : sprintf($LANG['server_errors'], count($remote_outputs)) ?></td>
                    <td class="text-<?= !empty($remote_errors) ? 'danger' : 'success' ?>">
                        <?= !empty($remote_errors)
                            ? '❌ ' . $LANG['status_error']
                            : '✅ ' . $LANG['status_ok'] ?>
                    </td>
                </tr>
                <?php if (!empty($remote_outputs)): ?>
                <tr id="remotecheck-row" style="display:none;">
                    <td colspan="3">
                        <?php foreach ($remote_outputs as $entry): ?>
                            <div class="alert alert-danger small"><?= $entry ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- named-checkconf -->
                <tr style="cursor: <?= empty($conf_outputs) ? 'default' : 'pointer' ?>;" <?= empty($conf_outputs) ? '' : 'onclick="toggleConfCheck()"' ?>>
                    <th><?= $LANG['named_checkconf'] ?></th>
                    <td><?= empty($conf_outputs)
                        ? $LANG['named_checkconf_ok']
                        : sprintf($LANG['named_checkconf_issues'], count($conf_outputs)) ?></td>
                    <td class="text-<?= !empty($conf_errors) ? 'danger' : (!empty($conf_warnings) ? 'warning' : 'success') ?>">
                        <?= !empty($conf_errors)
                            ? '❌ ' . $LANG['status_error']
                            : (!empty($conf_warnings)
                                ? '⚠️ ' . $LANG['status_warning']
                                : '✅ ' . $LANG['status_ok']) ?>
                    </td>
                </tr>
                <?php if (!empty($conf_outputs)): ?>
                <tr id="confcheck-row" style="display:none;">
                    <td colspan="3">
                        <?php
                        $conf_class = !empty($conf_errors) ? 'alert-danger' : (!empty($conf_warnings) ? 'alert-warning' : 'alert-light');
                        foreach ($conf_outputs as $entry): ?>
                            <div class="alert <?= $conf_class ?> small"><?= $entry ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- named-checkzone -->
                <tr style="cursor: <?= empty($outputs) ? 'default' : 'pointer' ?>;" <?= empty($outputs) ? '' : 'onclick="toggleZoneCheck()"' ?>>
                    <th><?= $LANG['named_checkzone'] ?></th>
                    <td><?= empty($outputs)
                        ? $LANG['named_checkzone_ok']
                        : sprintf($LANG['named_checkzone_issues'], count($outputs)) ?></td>
                    <td class="text-<?= $has_errors ? 'danger' : ($has_warnings ? 'warning' : 'success') ?>">
                        <?= $has_errors
                            ? '❌ ' . $LANG['status_error']
                            : ($has_warnings ? '⚠️ ' . $LANG['status_warning'] : '✅ ' . $LANG['status_ok']) ?>
                    </td>
                </tr>
                <?php if (!empty($outputs)): ?>
                <tr id="zonecheck-row" style="display:none;">
                    <td colspan="3">
                        <?php
                        $alert_class = $has_errors ? 'alert-danger' : ($has_warnings ? 'alert-warning' : 'alert-light');
                        foreach ($outputs as $entry): ?>
                            <div class="alert <?= $alert_class ?> small"><?= $entry ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endif; ?>

            </tbody>
        </table>
    </div>
</div>

<!-- JavaScript auslagern -->
<?php
AssetRegistry::enqueueScript('js/system_health.js');
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
