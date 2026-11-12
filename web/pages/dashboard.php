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
require_once __DIR__ . '/../inc/app/status/SystemCheckService.php';
require_once __DIR__ . '/../inc/web/system_check_messages.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/zones/ZoneReadService.php';
require_once __DIR__ . '/../inc/app/status/SystemStatusReadService.php';
require_once __DIR__ . '/../inc/app/status/DiagnosticReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
include __DIR__ . '/../templates/layout.php';

$dashboardActor = actorFromSession();
?>

<br>
<br>
<h2 class="mb-4"><?= $LANG['welcome'] ?>, <?= htmlspecialchars($_SESSION['username']) ?></h2>

<?php if ($_SESSION['role'] === 'admin'): ?>
    <?php
    // Benutzer, Zonen, Records zählen (SystemStatusReadService, nur aktiver Admin)
    $counts = (new SystemStatusReadService($pdo))->getDashboardCounts($dashboardActor)
        ?? ['users' => 0, 'zones' => 0, 'records' => 0];
    $users = $counts['users'];
    $zones = $counts['zones'];
    $records = $counts['records'];

    // Systemstatus prüfen (detailliert)
    $systemChecks = new SystemCheckService();
    $php_version_supported = $systemChecks->phpVersionSupported();
    $php_modules = $systemChecks->requiredModules();
    $file_issues = $systemChecks->filePermissionIssues(dirname(__DIR__));
    $config_issues = systemConfigIssueTexts($LANG, $systemChecks->configIssues());

    // Monitoring-Diagnosen: fachliche Auswertung im DiagnosticReadService
    $diagSummary = (new DiagnosticReadService($pdo, new ZoneAccess($pdo)))->getOverview($dashboardActor)['summary']
        ?? ['confError' => false, 'confWarning' => false, 'serverError' => false, 'zoneError' => false, 'zoneWarning' => false];
    $has_conf_errors = $diagSummary['confError'];
    $has_conf_warnings = $diagSummary['confWarning'];
    $has_errors = $diagSummary['zoneError'];
    $has_warnings = $diagSummary['zoneWarning'];

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
    // Eigene Zonen abrufen (ZoneReadService: aktuelle Zuweisung, vollständige Liste, nach Name sortiert)
    $zoneReadService = new ZoneReadService($pdo, new ZoneAccess($pdo));
    $zones = [];
    $offset = 0;
    do {
        $page = $zoneReadService->listZones($dashboardActor, ZoneReadService::MAX_LIMIT, $offset);
        foreach ($page['zones'] as $z) {
            $zones[] = ['id' => $z['id'], 'name' => $z['name']];
        }
        $offset += ZoneReadService::MAX_LIMIT;
    } while ($page['hasMore']);
    ?>
    <h4><?= $LANG['my_zones'] ?></h4>
    <ul>
        <?php foreach ($zones as $z): ?>
            <li><a href="pages/records.php?zone_id=<?= $z['id'] ?>"><?= htmlspecialchars($z['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
