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
require_once __DIR__ . '/../inc/app/status/SystemCheckService.php';
require_once __DIR__ . '/../inc/web/system_check_messages.php';
require_once __DIR__ . '/../inc/monitoring_runner.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/status/DiagnosticReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Zugriffsbeschränkung: Nur Administratoren dürfen diese Seite aufrufen (vor jeder Layout-Ausgabe)
requireRole(['admin']);

// POST (z. B. "Status jetzt aktualisieren") nur mit gültigem CSRF-Token; GET bleibt unberührt
verify_csrf_token();

include __DIR__ . '/../templates/layout.php';

// Systemstatus (SystemCheckService; Texte der Konfigurationsprüfung im Web)
$systemChecks = new SystemCheckService();
$php_version = $systemChecks->phpVersion();
$php_modules = $systemChecks->requiredModules();
$file_issues = $systemChecks->filePermissionIssues(dirname(__DIR__));
$config_issues = systemConfigIssueTexts($LANG, $systemChecks->configIssues());
$php_version_supported = $systemChecks->phpVersionSupported();

// Wenn "Status jetzt aktualisieren" gedrückt wurde, Monitoring direkt ausführen (S37, Paket 5b): derselbe
// MonitoringService wie der Cron-Lauf, im Webprozess statt per exec; weiterhin synchron, damit die Datenbank
// danach aktuell ist. Läuft gerade der Cron-Lauf, wird bis zu 60 s auf dessen Ende gewartet.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_update'])) {
    $exitCode = runMonitoringWithLog($pdo, 60)['exitCode'];

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
}

// Diagnosen und ihre Auswertung über den DiagnosticReadService (nur aktiver Admin; Paket 6, K2): Kennzeichen aus
// summary, Problemeinträge aus problems. Hier nur Übersetzung in die bisherige Darstellung (HTML, Texte).
$diagnostics = (new DiagnosticReadService($pdo, new ZoneAccess($pdo)))->getOverview(actorFromSession());
$diag_summary = $diagnostics['summary'] ?? ['confError' => false, 'confWarning' => false, 'serverError' => false, 'serverWarning' => false, 'zoneError' => false, 'zoneWarning' => false];
$diag_problems = $diagnostics['problems'] ?? ['server' => [], 'conf' => [], 'zone' => []];

$conf_errors = $diag_summary['confError'];
$conf_warnings = $diag_summary['confWarning'];
$conf_outputs = [];

foreach ($diag_problems['conf'] as $problem) {
    $server = htmlspecialchars($problem['serverName'] ?? 'unbekannt');
    $output = htmlspecialchars($problem['message']);
    $conf_outputs[] = "<strong>$server</strong><br><pre>$output</pre>";
}

$has_errors = $diag_summary['zoneError'];
$has_warnings = $diag_summary['zoneWarning'];
$outputs = [];

foreach ($diag_problems['zone'] as $problem) {
    $zone = htmlspecialchars($problem['zoneName'] ?? '(unbekannt)');
    $server = htmlspecialchars($problem['serverName'] ?? '(unbekannt)');
    $output = htmlspecialchars($problem['message']);
    $outputs[] = "<strong>$zone @ $server</strong><br><pre>$output</pre>";
}

$remote_errors = $diag_summary['serverError'];
// S42-E6: Warnungen (z. B. Zertifikatsablauf) sichtbar als Warnung, solange kein Serverfehler vorliegt
$remote_warnings = !$remote_errors && !empty($diag_summary['serverWarning']);
$remote_outputs = [];
$remote_output_classes = [];

foreach ($diag_problems['server'] as $problem) {
    $server = htmlspecialchars($problem['serverName'] ?? 'unbekannt');
    $output = htmlspecialchars($problem['message']);
    $remote_outputs[] = "<strong>$server</strong><br><pre>$output</pre>";
    $remote_output_classes[] = ($problem['status'] ?? null) === 'warning' ? 'alert-warning' : 'alert-danger';
}

/**
 * Prüfzeile (U5b): Bezeichnung, Ergebnis, Status; Details aufklappbar (<details>, ohne JavaScript).
 * $variant: ok|warn|err; $bodyHtml leer = keine Details
 */
$checkRow = static function (string $label, string $value, string $variant, string $statusText, string $bodyHtml = ''): string {
    $chip = ui_chip($statusText, $variant, ['ok' => 'check-circle', 'warn' => 'exclamation-triangle', 'err' => 'x-circle'][$variant] ?? null);
    $head = '<span class="dm-check-label">' . ui_e($label) . '</span><span class="dm-check-value">' . $value . '</span><span class="dm-check-status">' . $chip . '</span>';
    if ($bodyHtml === '') {
        return '<div class="dm-check">' . $head . '</div>';
    }
    return '<details class="dm-check dm-check-' . $variant . '"><summary>' . $head . '</summary><div class="dm-check-body">' . $bodyHtml . '</div></details>';
};
$entries = static function (array $items, string $variant): string {
    $html = '';
    foreach ($items as $entry) {
        $html .= '<div class="dm-diagnostic dm-diagnostic-' . $variant . '">' . $entry . '</div>';
    }
    return $html;
};

$refresh = '<form method="post" action="pages/system_health.php" class="d-inline">' . csrf_input()
    . '<input type="hidden" name="force_update" value="1">'
    . '<button type="submit" class="btn btn-outline-primary">' . ui_icon('arrow-repeat') . ' ' . ui_e($LANG['system_update_now']) . '</button></form>';
echo ui_page_header($LANG['system_status'], ['subtitle' => $LANG['system_health_sub'], 'actionsHtml' => $refresh]);

echo ui_card_open($LANG['system_health_local'], ['flush' => true]);
// Konfigurationsprüfung
$configBody = '';
if (!empty($config_issues)) {
    $configBody = '<p class="mb-2"><strong>' . ui_e($LANG['hint']) . ':</strong> ' . $LANG['config_hint'] . ' <code>ui_config.php</code>.</p><ul class="mb-0">';
    foreach ($config_issues as $issue) {
        $configBody .= '<li>' . htmlspecialchars($issue) . '</li>';
    }
    $configBody .= '</ul>';
}
echo $checkRow($LANG['config_check'], ui_e(empty($config_issues) ? $LANG['config_ok'] : sprintf($LANG['config_errors_found'], count($config_issues))),
    empty($config_issues) ? 'ok' : 'err', empty($config_issues) ? $LANG['status_ok'] : $LANG['status_error'], $configBody);
// PHP-Version
echo $checkRow($LANG['php_version_local'], '<span class="dm-mono">' . ui_e($php_version) . '</span>', $php_version_supported ? 'ok' : 'err',
    $php_version_supported ? $LANG['status_ok'] : $LANG['status_outdated']);
// PHP-Module
$missing = array_keys(array_filter($php_modules, static fn ($loaded) => !$loaded));
$moduleBody = '<ul class="dm-module-list">';
foreach ($php_modules as $module => $loaded) {
    $moduleBody .= '<li><span class="dm-mono">' . ui_e((string)$module) . '</span> ' . ($loaded ? ui_chip($LANG['status_ok'], 'ok') : ui_chip($LANG['status_missing'], 'err')) . '</li>';
}
$moduleBody .= '</ul>';
echo $checkRow($LANG['php_modules_local'], ui_e(sprintf($LANG['php_modules_summary'], count($php_modules) - count($missing), count($php_modules))),
    $missing === [] ? 'ok' : 'err', $missing === [] ? $LANG['status_ok'] : $LANG['status_missing'], $moduleBody);
// Dateiberechtigungen
$fileBody = '';
if (!empty($file_issues)) {
    $fileBody = '<p class="mb-2"><strong>' . ui_e($LANG['hint']) . ':</strong> ' . $LANG['file_permissions_hint'] . '</p>'
        . '<div class="table-responsive"><table class="dm-table"><thead><tr><th>Datei</th><th>Ist</th><th>Soll</th></tr></thead><tbody>';
    foreach ($file_issues as $issue) {
        $fileBody .= '<tr><td class="dm-mono">' . htmlspecialchars($issue['path']) . '</td><td class="dm-mono">' . htmlspecialchars($issue['actual']) . '</td><td class="dm-mono">'
            . htmlspecialchars(is_array($issue['expected'])
                ? 'Owner: ' . $issue['expected']['owner'] . ', Group: ' . $issue['expected']['group'] . ', Perms: ' . $issue['expected']['perms']
                : $issue['expected']) . '</td></tr>';
    }
    $fileBody .= '</tbody></table></div>';
}
echo $checkRow($LANG['file_permissions_local'], ui_e(empty($file_issues) ? $LANG['file_permissions_ok'] : sprintf($LANG['file_permissions_errors'], count($file_issues))),
    empty($file_issues) ? 'ok' : 'err', empty($file_issues) ? $LANG['status_ok'] : $LANG['status_error'], $fileBody);
echo ui_card_close();

echo ui_card_open($LANG['system_health_dns'], ['flush' => true]);
// Serverstatus (S42-E6: Warnungen sichtbar, solange kein Serverfehler vorliegt)
$remoteBody = '';
foreach ($remote_outputs as $i => $entry) {
    $remoteBody .= '<div class="dm-diagnostic dm-diagnostic-' . ($remote_output_classes[$i] === 'alert-warning' ? 'warn' : 'err') . '">' . $entry . '</div>';
}
echo $checkRow($LANG['server_status'], ui_e(empty($remote_outputs) ? $LANG['server_ok'] : sprintf($LANG[$remote_warnings ? 'server_warnings' : 'server_errors'], count($remote_outputs))),
    !empty($remote_errors) ? 'err' : ($remote_warnings ? 'warn' : 'ok'),
    !empty($remote_errors) ? $LANG['status_error'] : ($remote_warnings ? $LANG['status_warning'] : $LANG['status_ok']), $remoteBody);
// named-checkconf
$confVariant = !empty($conf_errors) ? 'err' : (!empty($conf_warnings) ? 'warn' : 'ok');
echo $checkRow($LANG['named_checkconf'], ui_e(empty($conf_outputs) ? $LANG['named_checkconf_ok'] : sprintf($LANG['named_checkconf_issues'], count($conf_outputs))),
    $confVariant, $confVariant === 'err' ? $LANG['status_error'] : ($confVariant === 'warn' ? $LANG['status_warning'] : $LANG['status_ok']),
    $entries($conf_outputs, $confVariant));
// named-checkzone
$zoneVariant = $has_errors ? 'err' : ($has_warnings ? 'warn' : 'ok');
echo $checkRow($LANG['named_checkzone'], ui_e(empty($outputs) ? $LANG['named_checkzone_ok'] : sprintf($LANG['named_checkzone_issues'], count($outputs))),
    $zoneVariant, $zoneVariant === 'err' ? $LANG['status_error'] : ($zoneVariant === 'warn' ? $LANG['status_warning'] : $LANG['status_ok']),
    $entries($outputs, $zoneVariant));
echo ui_card_close();
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
