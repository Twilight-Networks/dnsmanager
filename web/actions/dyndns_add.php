<?php
/**
 * Datei: dyndns_add.php
 * Zweck: DynDNS-Account hinzufügen
 *
 * Beschreibung:
 * Verarbeitet das Formular aus `dyndns_add_form.php` und legt einen neuen DynDNS-Account
 * mit zugehöriger Zone, Subdomain (Hostname) und initialem Passwort in der Datenbank an.
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt (Rollenprüfung)
 * - CSRF-Schutz über `verify_csrf_token()`
 * - Passwort-Hashing mit `password_hash()`
 * - Vorabprüfung auf eindeutigen Benutzernamen

 * Verhalten bei Erfolg:
 * - DynDNS-Account wird erstellt
 * - Erfolgsmeldung per Toast

 * Verhalten bei Fehlern:
 * - Fehlermeldung per Toast bei ungültigen Eingaben oder DB-Verletzungen
 * - Redirect zurück zur Verwaltungsseite
 */

declare(strict_types=1);
require_once __DIR__ . '/../common.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$hostname = trim($_POST['hostname'] ?? '');
$zone_id  = (int)($_POST['zone_id'] ?? 0);

if ($username === '' || $password === '' || $hostname === '' || $zone_id <= 0) {
    toastError($LANG['dyndns_error_missing_fields']);
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php?add_new=1');
    exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);

// Prüfen auf erlaubte Zone
$stmt = $pdo->prepare("SELECT id FROM zones WHERE id = ? AND allow_dyndns = 1");
$stmt->execute([$zone_id]);
if (!$stmt->fetch()) {
    toastError($LANG['dyndns_error_zone_not_allowed']);
    ("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php?add_new=1');
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO dyndns_accounts (username, password_hash, hostname, zone_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$username, $hash, $hostname, $zone_id]);
    toastSuccess($LANG['dyndns_success_account_created']);
} catch (PDOException $e) {
    toastError($LANG['dyndns_error_db'] . ': ' . $e->getMessage());
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
