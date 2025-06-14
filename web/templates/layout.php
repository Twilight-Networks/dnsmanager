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

// Systemstatus laden, wenn eingeloggt
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT
            (SELECT bind_dirty FROM system_status WHERE id = :id) AS bind_dirty,
            (SELECT COUNT(*) FROM zones WHERE changed = :changed) AS changed_zones
    ");
    $stmt->execute([
        'id' => 1,
        'changed' => 1
    ]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC);

    $dirty = $status['bind_dirty'] || $status['changed_zones'] > 0;
    $btn_class = $dirty ? 'btn-warning' : 'btn-secondary';
    $btn_text = $LANG['publish'];
}

// Prüfen, ob Fehler im Systemstatus vorliegen
$system_has_errors = false;

if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    $diag = getDiagnosticResults($pdo);

    // Server-Prüfungen
    foreach ($diag['server'] as $entry) {
        if (
            ($entry['zone_conf_status']['status'] ?? null) === 'error' ||
            ($entry['server_status']['status'] ?? null) === 'error'
        ) {
            $system_has_errors = true;
            break;
        }
    }

    // Zonen-Prüfungen
    if (!$system_has_errors) {
        foreach ($diag['zone'] as $entry) {
            $status = $entry['zone_status']['status'] ?? null;
            if (in_array($status, ['error', 'not_found'], true)) {
                $system_has_errors = true;
                break;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <title>DNS-Manager</title>
    <base href="<?= rtrim(BASE_URL, '/') ?>/">
    <link rel="icon" href="<?= rtrim(BASE_URL, '/') ?>/assets/branding/favicon.ico" type="image/x-icon">

    <?php
    // Basis-Stylesheets vormerken
    AssetRegistry::enqueueStyle('bootstrap/bootstrap.min.css');
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

<body>

<!-- Sidebar Navigation -->
<div class="sidebar d-flex flex-column p-3">
    <div class="text-center mb-2">
        <img src="<?= rtrim(BASE_URL, '/') ?>/assets/branding/twl_net_logo_small.png" alt="Logo" style="max-width: 70px; height: auto;">
        <h4 class="mt-2 mb-0">DNS-Manager</h4>
        <small class="text-muted d-block" style="font-size: 0.8rem;">
            <a href="https://www.twilight-networks.com" target="_blank" rel="noopener noreferrer" class="text-muted text-decoration-none">by Twilight-Networks</a>
        </small>
    </div>
    <div class="text-center" style="font-size: 0.8rem; padding-right: 8px;">
        v<?= htmlspecialchars(DNSMANAGER_VERSION) ?>
    </div>
    <hr>
    <a href="pages/dashboard.php" class="<?= isActive('dashboard.php') ?>">🏠 <?= $LANG['menu_dashboard'] ?></a>
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="pages/servers.php" class="<?= isActive('servers.php') ?>">🖥️ <?= $LANG['menu_servers'] ?></a>
    <?php endif; ?>
    <a href="pages/zones.php" class="<?= isActive('zones.php') ?>">🌐 <?= $LANG['zones'] ?></a>
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="pages/dyndns.php" class="<?= isActive('dyndns.php') ?>">🌐 <?= $LANG['menu_dyndns'] ?></a>
    <?php endif; ?>
    <a href="pages/users.php" class="<?= isActive('users.php') ?>">👥 <?= $LANG['users'] ?></a>
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <a id="system_healthButton"
           href="pages/system_health.php"
           class="<?= isActive('system_health.php') ?><?= $system_has_errors ? ' has-errors' : '' ?>">
           🩺 <?= $LANG['system_status'] ?><?= $system_has_errors ? ' ❗' : '' ?>
        </a>
    <?php endif; ?>
    <hr>

    <!-- "Veröffentlichen"-Button, falls Zonen geändert wurden -->
    <?php if (isset($_SESSION['user_id']) && $dirty): ?>
        <a id="publishButton"
           href="actions/publish_all.php?return=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
           class="nav-link dirty">
           🔄 <?= $btn_text ?>
        </a>
    <?php endif; ?>

    <!-- Benutzerinfos + Logout -->
    <div class="mt-auto p-2">
        <div class="small mb-2">
            <?= $LANG['logged_in_as'] ?> <br><strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
        </div>
        <a href="logout.php" class="<?= isActive('logout.php') ?>">
            🚪 <?= $LANG['menu_logout'] ?>
        </a>
    </div>
    <hr>
    <div class="text-center small">
        <a href="pages/update.php" class="text-muted text-decoration-none">
            🔄 <?= $LANG['menu_check_updates'] ?>
        </a>
    </div>
</div> <!-- Ende Sidebar -->


<div class="content">

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
