<?php
/**
 * Datei: layout.php
 * Zweck: Definiert das allgemeine Seiten-Layout mit Sidebar, Topbar und Toast-Nachrichten.
 *
 * Details:
 * - Lädt die aktuelle Systemstatus-Information (z.B. ob "Veröffentlichen" nötig ist).
 * - Stellt dynamische Navigationslinks (Sidebar) basierend auf Benutzerrolle bereit.
 * - Zeigt Session-Toast-Meldungen für Erfolg, Fehler und Warnungen an.
 *
 * Sicherheit:
 * - Session-basierter Schutz durch common.php.
 * - Alle Benutzereingaben werden sicher mit htmlspecialchars() oder strip_tags() ausgegeben.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../config/version.php';

require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/status/SystemStatusReadService.php';
require_once __DIR__ . '/../inc/app/status/DiagnosticReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Systemstatus laden, wenn eingeloggt (SystemStatusReadService; 1 und 2 gelten als ausstehend)
if (isset($_SESSION['user_id'])) {
    $publishStatus = (new SystemStatusReadService($pdo))->getPublishStatus(actorFromSession());

    $dirty = $publishStatus !== null && $publishStatus['pending'];
    $btn_class = $dirty ? 'btn-warning' : 'btn-secondary';
    $btn_text = $LANG['publish'];
}

// Prüfen, ob Fehler im Systemstatus vorliegen (DiagnosticReadService, nur Admin)
$system_has_errors = false;

if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    $diagOverview = (new DiagnosticReadService($pdo, new ZoneAccess($pdo)))->getOverview(actorFromSession());
    $system_has_errors = $diagOverview !== null
        && ($diagOverview['summary']['confError'] || $diagOverview['summary']['serverError'] || $diagOverview['summary']['zoneError']);
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DNS-Manager</title>
    <base href="<?= rtrim(BASE_URL, '/') ?>/">
    <link rel="icon" href="<?= rtrim(BASE_URL, '/') ?>/assets/branding/favicon.ico" type="image/x-icon">
    <?php // U1: Farbmodus und JS-Erkennung vor dem ersten Rendern (externe Datei, CSP-tauglich) ?>
    <script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/theme.js?v=<?= filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script>

    <?php
    // Basis-Stylesheets vormerken (theme-auto.css: Dunkelmodus nach Systemvorgabe, falls kein JavaScript)
    AssetRegistry::enqueueStyle('bootstrap/bootstrap.min.css');
    AssetRegistry::enqueueStyle('css/theme-auto.css');
    AssetRegistry::enqueueStyle('fonts/fonts.css');
    AssetRegistry::enqueueStyle('css/custom.css');

    // Stylesheets ausgeben mit Cache-Busting
    foreach (AssetRegistry::getStyles() as $css) {
        $path = __DIR__ . '/../assets/' . $css;
        $version = file_exists($path) ? filemtime($path) : time();
        echo '<link href="' . rtrim(BASE_URL, '/') . '/assets/' . htmlspecialchars($css) . '?v=' . $version . '" rel="stylesheet">' . PHP_EOL;
    }
    ?>
</head>

<body class="app-body">

<a class="visually-hidden-focusable app-skip-link" href="#main"><?= htmlspecialchars($LANG['skip_to_content']) ?></a>

<?php
// U1 (A+): Kopfleiste nur auf schmalen Bildschirmen (< lg) mit kompaktem Original-Logo (Quadrat-Variante je
// Farbmodus). Die Menü-Schaltfläche öffnet die Seitenleiste als Offcanvas; ohne JavaScript ist sie ausgeblendet und
// die Seitenleiste steht als normaler Block darunter (custom.css, .no-js).
?>
<header class="app-topbar d-lg-none">
    <a href="pages/dashboard.php" class="app-topbar-brand">
        <img src="<?= rtrim(BASE_URL, '/') ?>/assets/branding/TWLnet_Logo_Quadrat_CMYK.png" alt="Twilight Networks" class="app-logo-compact logo-on-light">
        <img src="<?= rtrim(BASE_URL, '/') ?>/assets/branding/TWLnet_Logo_Quadrat_neg.png" alt="Twilight Networks" class="app-logo-compact logo-on-dark">
        <span>DNS Manager</span>
    </a>
    <button type="button" class="btn app-menu-toggle" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar">
        <?= ui_icon('list') ?> <span><?= htmlspecialchars($LANG['menu_open']) ?></span>
    </button>
</header>

<?php
// Navigation: Gruppen nach Fachbereich. Sichtbarkeit nach Rolle ist reine Darstellung; die Autorisierung bleibt bei
// requireRole()/ZoneAccess und den Core-Services (UI-Leitfaden, Abschnitt 7).
$isAdmin = ($_SESSION['role'] ?? null) === 'admin';
$changedZones = (int)($publishStatus['changedZones'] ?? 0);
?>
<!-- Seitenleiste: ab lg fest, darunter Offcanvas (mit JS) bzw. Block (ohne JS) -->
<nav id="appSidebar" class="sidebar app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" aria-label="<?= htmlspecialchars($LANG['main_navigation']) ?>">
    <div class="offcanvas-header d-lg-none">
        <span class="h5 mb-0">DNS Manager</span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="<?= htmlspecialchars($LANG['close']) ?>"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <div class="app-brand d-none d-lg-block">
            <a href="https://www.twilight-networks.com" target="_blank" rel="noopener noreferrer" class="app-brand-logo" title="Twilight Networks">
                <img src="<?= rtrim(BASE_URL, '/') ?>/assets/branding/TWLnet_Logo_CMYK.png" alt="Twilight Networks" class="logo-on-light">
                <img src="<?= rtrim(BASE_URL, '/') ?>/assets/branding/TWLnet_Logo_neg.png" alt="Twilight Networks" class="logo-on-dark">
            </a>
            <div class="app-brand-product"><span>DNS Manager</span><span>v<?= htmlspecialchars(DNSMANAGER_VERSION) ?></span></div>
        </div>

        <div class="app-nav">
            <a href="pages/dashboard.php" class="<?= isActive('dashboard.php') ?>"><?= ui_icon('grid-1x2') ?><span><?= $LANG['menu_dashboard'] ?></span></a>

            <div class="app-nav-label"><?= htmlspecialchars($LANG['nav_group_dns']) ?></div>
            <a href="pages/zones.php" class="<?= isActive('zones.php') ?><?= isActive('records.php') !== '' ? ' active' : '' ?>"><?= ui_icon('globe2') ?><span><?= $LANG['zones'] ?></span><?php if ($changedZones > 0): ?><span class="app-nav-count" title="<?= htmlspecialchars(sprintf($LANG['publish_pending_zones'], $changedZones)) ?>"><?= $changedZones ?></span><?php endif; ?></a>
            <?php if ($isAdmin): ?>
                <a href="pages/dyndns.php" class="<?= isActive('dyndns.php') ?>"><?= ui_icon('router') ?><span><?= $LANG['menu_dyndns'] ?></span></a>

                <div class="app-nav-label"><?= htmlspecialchars($LANG['nav_group_infrastructure']) ?></div>
                <a href="pages/servers.php" class="<?= isActive('servers.php') ?>"><?= ui_icon('hdd-network') ?><span><?= $LANG['menu_servers'] ?></span></a>
                <a id="system_healthButton"
                   href="pages/system_health.php"
                   class="<?= isActive('system_health.php') ?><?= $system_has_errors ? ' has-errors' : '' ?>">
                   <?= ui_icon('activity') ?><span><?= $LANG['system_status'] ?></span><?php if ($system_has_errors): ?><span class="app-nav-count app-nav-count-error"><?= ui_icon('exclamation-lg') ?><span class="visually-hidden"> (<?= $LANG['errors'] ?>)</span></span><?php endif; ?>
                </a>
            <?php endif; ?>

            <div class="app-nav-label"><?= htmlspecialchars($LANG['nav_group_admin']) ?></div>
            <a href="pages/users.php" class="<?= isActive('users.php') ?>"><?= ui_icon('people') ?><span><?= $LANG['users'] ?></span></a>
            <?php if ($isAdmin): ?>
                <a href="pages/update.php" class="<?= isActive('update.php') ?>"><?= ui_icon('arrow-repeat') ?><span><?= $LANG['menu_check_updates'] ?></span></a>
            <?php endif; ?>
        </div>

        <!-- Ausstehende Veröffentlichung: bestehendes Publish-Formular (Ziel und Felder unverändert) -->
        <?php if (isset($_SESSION['user_id']) && $dirty): ?>
            <div class="app-publish">
                <div class="app-publish-head"><span class="app-dot app-dot-warn"></span><?= htmlspecialchars($changedZones > 0 ? sprintf($LANG['publish_pending_zones'], $changedZones) : $LANG['publish_pending']) ?></div>
                <p><?= htmlspecialchars($LANG['publish_pending_hint']) ?></p>
                <form method="post" action="actions/publish_all.php" class="m-0">
                    <?= csrf_input() ?>
                    <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                    <button type="submit" id="publishButton" class="btn btn-success btn-sm w-100 dirty">
                        <?= ui_icon('cloud-arrow-up') ?><span><?= $btn_text ?></span>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Benutzer, Farbmodus, Abmelden (bestehendes Logout-Formular) -->
        <div class="app-user">
            <span class="app-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string)($_SESSION['username'] ?? ''), 0, 2))) ?></span>
            <div class="app-user-text">
                <span class="visually-hidden"><?= $LANG['logged_in_as'] ?></span>
                <div class="app-user-name"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="app-user-role"><?= htmlspecialchars($LANG['role_' . ($_SESSION['role'] ?? '')] ?? '') ?></div>
            </div>
            <form method="post" action="logout.php" class="m-0 sidebar-logout">
                <?= csrf_input() ?>
                <button type="submit" class="app-icon-btn" title="<?= htmlspecialchars($LANG['menu_logout']) ?>">
                    <?= ui_icon('box-arrow-right') ?><span class="visually-hidden"><?= $LANG['menu_logout'] ?></span>
                </button>
            </form>
        </div>
        <?php // Farbmodus-Umschalter nur mit JavaScript (ohne JS gilt die Systemvorgabe); keine Serveränderung ?>
        <div class="app-theme-row js-only">
            <span><?= htmlspecialchars($LANG['theme_label']) ?></span>
            <div class="btn-group btn-group-sm app-theme-switch" role="group" aria-label="<?= htmlspecialchars($LANG['theme_label']) ?>">
                <button type="button" class="btn" data-theme-set="light" aria-pressed="false" title="<?= htmlspecialchars($LANG['theme_light']) ?>"><?= ui_icon('sun') ?><span class="visually-hidden"><?= htmlspecialchars($LANG['theme_light']) ?></span></button>
                <button type="button" class="btn" data-theme-set="dark" aria-pressed="false" title="<?= htmlspecialchars($LANG['theme_dark']) ?>"><?= ui_icon('moon-stars') ?><span class="visually-hidden"><?= htmlspecialchars($LANG['theme_dark']) ?></span></button>
                <button type="button" class="btn" data-theme-set="auto" aria-pressed="false" title="<?= htmlspecialchars($LANG['theme_auto']) ?>"><?= ui_icon('circle-half') ?><span class="visually-hidden"><?= htmlspecialchars($LANG['theme_auto']) ?></span></button>
            </div>
        </div>
    </div> <!-- .offcanvas-body -->
</nav> <!-- Ende Seitenleiste -->

<?php
// Kopfleiste des Arbeitsbereichs (ab lg): Pfadangabe nach aktueller Seite. Keine neuen Linkziele. Eine Statusanzeige
// folgt erst mit dem Dashboard (U5), damit sie dieselbe Quelle wie die Systemstatus-Seite nutzt.
$pageKeys = ['dashboard.php' => 'menu_dashboard', 'zones.php' => 'zones', 'records.php' => 'records', 'servers.php' => 'menu_servers',
    'dyndns.php' => 'menu_dyndns', 'users.php' => 'users', 'system_health.php' => 'system_status', 'update.php' => 'menu_check_updates'];
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<div class="app-main">
<header class="app-pagebar d-none d-lg-flex">
    <nav class="app-crumbs" aria-label="<?= htmlspecialchars($LANG['breadcrumb']) ?>">
        <span>DNS Manager</span>
        <?php if ($currentPage === 'records.php'): ?>
            <span class="sep" aria-hidden="true">/</span><a href="pages/zones.php"><?= $LANG['zones'] ?></a>
        <?php endif; ?>
        <?php if (isset($pageKeys[$currentPage])): ?>
            <span class="sep" aria-hidden="true">/</span><span class="here" aria-current="page"><?= htmlspecialchars($LANG[$pageKeys[$currentPage]]) ?></span>
        <?php endif; ?>
    </nav>
</header>

<main id="main" class="content" tabindex="-1">

<!-- Toast: Erfolgsmeldungen -->
<?php if (isset($_SESSION['toast_success'])): ?>
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <?php
                    $allowed_tags = '<br><strong><code>';
                    echo strip_tags($_SESSION['toast_success'], $allowed_tags);
                    ?>
                </div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Schließen"></button>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['toast_success']); ?>
<?php endif; ?>

<!-- Toast: Fehlermeldungen -->
<?php if (!empty($_SESSION['toast_errors'])): ?>
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <strong>❌ <?= $LANG['errors'] ?>:</strong><br>
                    <?php
                    $allowed_tags = '<br><strong><code>';
                    foreach ($_SESSION['toast_errors'] as $error) {
                        echo strip_tags($error, $allowed_tags) . '<br>';
                    }
                    ?>
                </div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Schließen"></button>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['toast_errors']); ?>
<?php endif; ?>

<!-- Toast: Warnungen -->
<?php if (!empty($_SESSION['toast_warnings'])): ?>
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div class="toast align-items-center text-bg-warning border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    ⚠️ <strong><?= $LANG['warnings'] ?>:</strong><br>
                    <?php
                    $allowed_tags = '<br><strong><code>';
                    foreach ($_SESSION['toast_warnings'] as $warning) {
                        echo strip_tags($warning, $allowed_tags) . '<br>';
                    }
                    ?>
                </div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Schließen"></button>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['toast_warnings']); ?>
<?php endif; ?>
