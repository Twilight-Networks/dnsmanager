<?php
/**
 * Datei: login.php
 * Zweck: Authentifizierung der Benutzer für den DNS-Manager.
 *
 * Funktionen:
 * - Zeigt ein Anmeldeformular an.
 * - Validiert Benutzername und Passwort mit `login_user()`.
 * - Leitet bei erfolgreicher Anmeldung ins Dashboard weiter.
 * - Zeigt bei gescheitertem Login eine Fehlermeldung.
 * - Zeigt bei abgelaufener Session oder nach Logout passende Hinweise.
 *
 * Sicherheit:
 * - Keine unautorisierte Nutzung durch Zugriffsschutz in `common.php`.
 * - Caching ist deaktiviert, um Rücknavigation und Session-Reste zu verhindern.
 * - Login erfolgt serverseitig sicher (PDO, `password_verify()`).
 */

// Diese Seite darf auch ohne bestehende Anmeldung aufgerufen werden
define('ALLOW_UNAUTHENTICATED', true);

// Zentrale Initialisierung (inkl. Session, CSRF etc.)
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/web/session_guard.php';

// Schema muss zum Code passen (users.active, users.session_epoch); sonst 503 statt Anmeldung (Fail-Closed)
session_guard_require_schema($pdo);
require_once __DIR__ . '/config/version.php';

// HTTP-Caching explizit deaktivieren
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Statusvariablen initialisieren
$error = false;
$logoutSuccess = false;
$timeoutExpired = false;
$passwordChanged = false;

// Sessionstatus prüfen (nur bei GET-Anfragen relevant)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_SESSION['password_changed_relogin'])) {
        // Eigene Passwortänderung: Session wurde beendet, neue Anmeldung erforderlich (S26)
        $passwordChanged = true;
        unset($_SESSION['password_changed_relogin'], $_SESSION['session_expired'], $_SESSION['logout_success']);
    } elseif (!empty($_SESSION['logout_success'])) {
        $logoutSuccess = true;
        unset($_SESSION['logout_success']);
        unset($_SESSION['session_expired']); // Timeout ignorieren, wenn Logout aktiv war
    } elseif (!empty($_SESSION['session_expired'])) {
        $timeoutExpired = true;
        unset($_SESSION['session_expired']);
    }
}

// Verarbeitung von Login-Daten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // S35 (SP2-E7): Jeder Login-POST benötigt ein gültiges CSRF-Token (fehlend oder ungültig → 403). Der frühere
    // Ausnahmezweig ohne Session-Token war unerreichbar (inc/session.php setzt für jede Session ein Token).
    verify_csrf_token();

    if (login_user($_POST['username'], $_POST['password'])) {
        // Erfolgreiche Anmeldung → Weiterleitung zum Dashboard
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/dashboard.php");
        exit;
    } else {
        // Anmeldung fehlgeschlagen
        $error = true;
    }
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - DNS-Manager</title>
    <?php // U1: gleicher Farbmodus und dieselben Stylesheets wie das Layout (theme.js extern, CSP-tauglich) ?>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <link href="assets/bootstrap/bootstrap.min.css?v=<?= filemtime(__DIR__ . '/assets/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="assets/css/theme-auto.css?v=<?= filemtime(__DIR__ . '/assets/css/theme-auto.css') ?>" rel="stylesheet">
    <link href="assets/fonts/fonts.css?v=<?= filemtime(__DIR__ . '/assets/fonts/fonts.css') ?>" rel="stylesheet">
    <link href="assets/css/custom.css?v=<?= filemtime(__DIR__ . '/assets/css/custom.css') ?>" rel="stylesheet">
    <link rel="icon" href="<?= rtrim(BASE_URL, '/') ?>/assets/branding/favicon.ico" type="image/x-icon">
</head>
<body class="app-login">

<main class="container app-login-main">
    <div class="card shadow-sm p-4 text-center app-login-card">

        <!-- Logo -->
        <div class="text-center">
            <img src="<?= rtrim(BASE_URL, '/') ?>/assets/branding/twlnet_logo.png" alt="Twilight-Networks Logo" class="app-logo app-login-logo">
        </div>

        <!-- Überschrift -->
        <h1 class="h3 mb-4">DNS-Manager</h1>

        <!-- Hinweis: Erfolgreich abgemeldet -->
        <?php if ($logoutSuccess): ?>
            <div class="alert alert-success">✅ <?= $LANG['logout_success'] ?></div>
        <?php endif; ?>
<?php /* Hinweis: Passwort geändert, neu anmelden. Steuerzeilen in Spalte 0: ohne Hinweis keine zusätzliche Ausgabe */ if ($passwordChanged): ?>
            <div class="alert alert-success">✅ <?= $LANG['password_changed_relogin'] ?></div>
<?php endif; ?>

        <!-- Hinweis: Session abgelaufen -->
        <?php if ($timeoutExpired): ?>
            <div class="alert alert-warning">⏳ <?= $LANG['session_expired'] ?></div>
        <?php endif; ?>

        <!-- Hinweis: Fehlgeschlagener Login -->
        <?php if ($error): ?>
            <div class="alert alert-danger">❌ <?= $LANG['login_failed'] ?></div>
        <?php endif; ?>

        <!-- Login-Formular -->
        <div class="mx-auto app-login-form">
            <form method="post">
                <?= csrf_input() ?>

                <div class="mb-3 text-start">
                    <label class="form-label" for="login_username"><?= $LANG['username'] ?></label>
                    <input type="text" id="login_username" name="username" class="form-control" autocomplete="username" required autofocus>
                </div>
                <div class="mb-3 text-start">
                    <label class="form-label" for="login_password"><?= $LANG['password'] ?></label>
                    <input type="password" id="login_password" name="password" class="form-control" autocomplete="current-password" required>
                </div>

                <div class="d-grid app-login-submit">
                    <button type="submit" class="btn btn-success"><?= $LANG['login_button'] ?></button>
                </div>
            </form>
        </div>
    </div>
</main>

</body>
</html>
