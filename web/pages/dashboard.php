<?php
/**
 * Datei: dashboard.php
 * Zweck: Start-Dashboard nach dem Login im A+-Design (U5a).
 *
 * Details:
 * - Admins: Systemstatus (bisherige Auswertung: SystemCheckService, DiagnosticReadService), globale Kennzahlen
 *   (SystemStatusReadService::getDashboardCounts), ausstehende Veröffentlichungen und Zonen mit Prüfproblemen sowie
 *   die Zonen mit Handlungsbedarf.
 * - Zone-Admins: ausschließlich Daten ihrer berechtigten Zonen (ZoneReadService, vollständige Liste über die
 *   bestehende Pagination): Kennzahlen und Tabelle „Meine Zonen“.
 * - Anzeigestatus je Zone ausschließlich über ZoneReadService::zoneDisplayStatus(); Diagnosefehler/-warnungen und
 *   ausstehende Veröffentlichungen werden getrennt ausgewiesen (inc/web/zone_view.php).
 *
 * Sicherheit:
 * - Zugriffsschutz über Session (common.php); Sichtbarkeit über die Read-Services (ZoneAccess, ActorAccess).
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
require_once __DIR__ . '/../inc/web/zone_view.php';
include __DIR__ . '/../templates/layout.php';

$dashboardActor = actorFromSession();
$isAdmin = $_SESSION['role'] === 'admin';
$isZoneadmin = $_SESSION['role'] === 'zoneadmin';

echo ui_page_header($LANG['welcome'] . ', ' . (string)$_SESSION['username'], [
    'subtitle' => $isAdmin ? $LANG['dashboard_sub_admin'] : $LANG['dashboard_sub_zoneadmin'],
]);

if ($isAdmin) {
    // Systemstatus (unveränderte Auswertung)
    $systemChecks = new SystemCheckService();
    $php_version_supported = $systemChecks->phpVersionSupported();
    $php_modules = $systemChecks->requiredModules();
    $file_issues = $systemChecks->filePermissionIssues(dirname(__DIR__));
    $config_issues = systemConfigIssueTexts($LANG, $systemChecks->configIssues());
    $diagSummary = (new DiagnosticReadService($pdo, new ZoneAccess($pdo)))->getOverview($dashboardActor)['summary']
        ?? ['confError' => false, 'confWarning' => false, 'serverError' => false, 'zoneError' => false, 'zoneWarning' => false];
    $system_has_errors = !$php_version_supported || in_array(false, $php_modules, true) || $diagSummary['confError']
        || !empty($file_issues) || !empty($config_issues) || $diagSummary['zoneError'];
    $system_has_warnings = !$system_has_errors && ($diagSummary['confWarning'] || $diagSummary['zoneWarning']);

    $statusLink = '<a class="btn btn-sm btn-outline-secondary" href="pages/system_health.php">' . ui_e($LANG['system_status']) . '</a>';
    if ($system_has_errors) {
        echo ui_notice('err', $LANG['system_status'] . ':', ['text' => $LANG['dashboard_system_error'], 'role' => 'alert', 'actionsHtml' => $statusLink]);
    } elseif ($system_has_warnings) {
        echo ui_notice('warn', $LANG['system_status'] . ':', ['text' => $LANG['dashboard_system_warning'], 'actionsHtml' => $statusLink]);
    } else {
        echo ui_notice('ok', $LANG['system_ok']);
    }
}

if ($isAdmin || $isZoneadmin) {
    // Zonen: admin alle, zoneadmin nur berechtigte (ZoneReadService, vollständige Liste)
    $view = zoneViewAll(new ZoneReadService($pdo, new ZoneAccess($pdo)), $dashboardActor);
    $zones = $view['zones'];
    $pending = count(array_filter($zones, fn ($z) => $z['pendingPublish']));
    $errors = count(array_filter($zones, fn ($z) => $z['status'] === 'error'));
    $warnings = count(array_filter($zones, fn ($z) => $z['status'] === 'warning'));

    if ($isAdmin) {
        $counts = (new SystemStatusReadService($pdo))->getDashboardCounts($dashboardActor) ?? ['users' => 0, 'zones' => 0, 'records' => 0];
        $kpis = [
            ui_kpi($LANG['zones'], (string)$counts['zones'], ['icon' => 'globe2', 'href' => 'pages/zones.php']),
            ui_kpi($LANG['records'], (string)$counts['records'], ['icon' => 'list']),
            ui_kpi($LANG['users'], (string)$counts['users'], ['icon' => 'people', 'href' => 'pages/users.php']),
        ];
    } else {
        $kpis = [
            ui_kpi($LANG['my_zones'], (string)count($zones), ['icon' => 'globe2', 'href' => 'pages/zones.php']),
            ui_kpi($LANG['records'], (string)array_sum(array_column($zones, 'recordCount')), ['icon' => 'list']),
        ];
    }
    $kpis[] = ui_kpi($LANG['dashboard_pending'], (string)$pending, ['icon' => 'cloud-arrow-up', 'variant' => $pending > 0 ? 'warn' : 'ok',
        'foot' => $pending > 0 ? $LANG['dashboard_pending_foot'] : $LANG['dashboard_pending_none']]);
    $kpis[] = ui_kpi($LANG['dashboard_zone_check'], (string)($errors + $warnings), ['icon' => 'activity',
        'variant' => $errors > 0 ? 'err' : ($warnings > 0 ? 'warn' : 'ok'),
        'foot' => sprintf($LANG['dashboard_zone_check_foot'], $errors, $warnings), 'href' => 'pages/zones.php']);
    echo '<div class="dm-kpis' . (count($kpis) === 5 ? ' dm-kpis-5' : '') . '">' . implode('', $kpis) . '</div>';

    // Tabelle: admin – Zonen mit Handlungsbedarf (Prüfproblem oder ausstehend); zoneadmin – alle eigenen Zonen
    $list = $isAdmin ? array_values(array_filter($zones, fn ($z) => $z['status'] !== 'ok' || $z['pendingPublish'])) : $zones;
    echo ui_card_open($isAdmin ? $LANG['dashboard_attention'] : $LANG['my_zones'], ['flush' => true, 'actionsHtml' => ui_chip((string)count($list))
        . ' <a class="btn btn-sm btn-outline-secondary" href="pages/zones.php">' . ui_e($LANG['dashboard_all_zones']) . '</a>']);
    if ($list === []) {
        echo ui_empty_state($isAdmin ? $LANG['dashboard_attention_none'] : $LANG['zones_empty'], ['icon' => $isAdmin ? 'check-circle' : 'globe2']);
    } else {
        echo '<div class="table-responsive"><table class="dm-table dm-zones-table"><thead><tr><th>' . ui_e($LANG['name']) . '</th>'
            . '<th>' . ui_e($LANG['zone_col_status']) . '</th><th class="dm-col-num">' . ui_e($LANG['records']) . '</th>'
            . '<th class="dm-col-actions">' . ui_e($LANG['actions']) . '</th></tr></thead><tbody>';
        foreach ($list as $z) {
            echo '<tr><td class="dm-mono dm-col-name"><a href="pages/records.php?zone_id=' . $z['id'] . '">' . ui_e($z['name']) . '</a></td>'
                . '<td class="dm-col-status">' . zoneViewStatusHtml($z) . '</td><td class="dm-col-num">' . (int)$z['recordCount'] . '</td>'
                . '<td class="dm-col-actions"><a class="btn btn-sm btn-outline-secondary" href="pages/records.php?zone_id=' . $z['id'] . '">' . ui_e($LANG['records']) . '</a> '
                . '<a class="btn btn-sm btn-outline-secondary" href="pages/zones.php?edit_id=' . $z['id'] . '">' . ui_e($LANG['dashboard_zone_settings']) . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo ui_card_close();
}
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
