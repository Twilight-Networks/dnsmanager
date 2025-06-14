<?php
/**
 * Datei: dashboard.php
 * Zweck: Start-Dashboard nach dem Login für Admins und Zone-Admins.
 *
 * Details:
 * - Admins: Übersicht Benutzer, Zonen, Records, BIND-Reload-Button.
 * - Zone-Admins: Übersicht eigene Zonen.
 * - Hinweis auf Systemstatus-Details bei Problemen (nur für Admins sichtbar).
 *
 * Sicherheit:
 * - Zugriffsschutz über Session (common.php).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/diagnostics.php';
include __DIR__ . '/../templates/layout.php';
?>

<br>
<br>
<h2 class="mb-4"><?= $LANG['welcome'] ?>, <?= htmlspecialchars($_SESSION['username']) ?></h2>

<?php if ($_SESSION['role'] === 'admin'): ?>
    <?php
    // Benutzer, Zonen, Records zählen
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
    $stmt->execute();
    $users = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM zones");
    $stmt->execute();
    $zones = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM records");
    $stmt->execute();
    $records = $stmt->fetchColumn();

    // Systemstatus prüfen (detailliert)
    $php_version_supported = version_compare(get_php_version(), '8.1', '>=');
    $php_modules = check_required_php_modules();
    $file_issues = check_all_file_permissions(dirname(__DIR__));
    $config_issues = check_config_validity();

    $diag = getDiagnosticResults($pdo);
    $conf_results = $diag['server'];
    $zone_results = $diag['zone'];

    $has_conf_errors = false;
    $has_conf_warnings = false;
    foreach ($conf_results as $entry) {
        if (!isset($entry['zone_conf_status'])) {
            continue;
        }

        $status = $entry['zone_conf_status']['status'];
        if ($status === 'error') {
            $has_conf_errors = true;
            break;
        } elseif ($status === 'warning') {
            $has_conf_warnings = true;
        }
    }

    $has_errors = false;
    $has_warnings = false;
    foreach ($zone_results as $entry) {
        if (!isset($entry['zone_status'])) {
            continue;
        }

        $status = $entry['zone_status']['status'];
        if (in_array($status, ['error', 'not_found'], true)) {
            $has_errors = true;
            break;
        } elseif ($status === 'warning') {
            $has_warnings = true;
        }
    }

    $system_has_errors =
        !$php_version_supported ||
        in_array(false, $php_modules, true) ||
        $has_conf_errors ||
        !empty($file_issues) ||
        !empty($config_issues) ||
        $has_errors;

    $system_has_warnings =
        !$system_has_errors &&
        (
            $has_conf_warnings ||
            $has_warnings
        );

    $system_ok = !$system_has_errors && !$system_has_warnings;
    ?>

    <div class="card mb-4">
        <div class="card-body">
            <h5><?= $LANG['system_status'] ?></h5>
            <?php if ($system_has_errors): ?>
                <div class="alert alert-danger mb-0">
                    ❌ <?= $LANG['system_error'] ?><a href="pages/system_health.php"><?= $LANG['system_status'] ?></a>.
                </div>
            <?php elseif ($system_has_warnings): ?>
                <div class="alert alert-warning mb-0">
                    ⚠️ <?= $LANG['system_warning'] ?><a href="pages/system_health.php"><?= $LANG['system_status'] ?></a>.
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-0">
                    ✅ <?= $LANG['system_ok'] ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <br>

    <div class="row mb-4">
        <div class="col"><div class="card text-bg-light mb-3"><div class="card-body">👥 <?= $LANG['users'] ?>: <strong><?= $users ?></strong></div></div></div>
        <div class="col"><div class="card text-bg-light mb-3"><div class="card-body">🌐 <?= $LANG['zones'] ?>: <strong><?= $zones ?></strong></div></div></div>
        <div class="col"><div class="card text-bg-light mb-3"><div class="card-body">📄 <?= $LANG['records'] ?>: <strong><?= $records ?></strong></div></div></div>
    </div>
<?php endif; ?>

<?php if ($_SESSION['role'] === 'zoneadmin'): ?>
    <?php
    // Eigene Zonen abrufen
    $stmt = $pdo->prepare("SELECT z.id, z.name FROM zones z JOIN user_zones uz ON uz.zone_id = z.id WHERE uz.user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $zones = $stmt->fetchAll();
    ?>
    <h4><?= $LANG['my_zones'] ?></h4>
    <ul>
        <?php foreach ($zones as $z): ?>
            <li><a href="pages/records.php?zone_id=<?= $z['id'] ?>"><?= htmlspecialchars($z['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
