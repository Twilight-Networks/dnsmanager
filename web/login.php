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
require_once __DIR__ . '/config/version.php';

// HTTP-Caching explizit deaktivieren
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Statusvariablen initialisieren
$error = false;
$logoutSuccess = false;
$timeoutExpired = false;

// Sessionstatus prüfen (nur bei GET-Anfragen relevant)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_SESSION['logout_success'])) {
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
    if (empty($_SESSION['csrf_token'])) {
        // Session war abgelaufen oder CSRF-Token fehlt → Tokenprüfung überspringen, neues Token setzen
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        verify_csrf_token();
    }

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
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Login - DNS Manager</title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="<?= rtrim(BASE_URL, '/') ?>/assets/branding/favicon.ico" type="image/x-icon">
</head>
<body class="bg-light">

<div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="card shadow-sm p-4 text-center" style="width: 100%; max-width: 500px;">

        <!-- Logo -->
        <div style="text-align: center;">
            <img src="<?= rtrim(BASE_URL, '/') ?>/assets/branding/twlnet_logo.png" alt="Twilight-Networks Logo" style="width: 375px; height: auto; margin-bottom: 1rem;">
        </div>

        <!-- Überschrift -->
        <h3 class="mb-4">DNS-Manager</h3>

        <!-- Hinweis: Erfolgreich abgemeldet -->
        <?php if ($logoutSuccess): ?>
            <div class="alert alert-success">✅ Sie wurden erfolgreich abgemeldet.</div>
        <?php endif; ?>

        <!-- Hinweis: Session abgelaufen -->
        <?php if ($timeoutExpired): ?>
            <div class="alert alert-warning">⏳ Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.</div>
        <?php endif; ?>

        <!-- Hinweis: Fehlgeschlagener Login -->
        <?php if ($error): ?>
            <div class="alert alert-danger">❌ Login fehlgeschlagen. Bitte überprüfen Sie Benutzername und Passwort.</div>
        <?php endif; ?>

        <!-- Login-Formular -->
        <div class="mx-auto" style="width: 100%; max-width: 300px;">
            <form method="post">
                <?= csrf_input() ?>

                <div class="mb-3 text-start">
                    <label class="form-label">Benutzername</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3 text-start">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="password" class="form-control" required>
                </div>

                <div class="d-grid" style="max-width: 120px; margin: 0 auto;">
                    <button type="submit" class="btn btn-primary">Anmelden</button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
