<?php
/**
 * Datei: actions/dyndns_update.php
 * Zweck: Bestehenden DynDNS-Account aktualisieren
 *
 * Beschreibung:
 * Aktualisiert den Benutzernamen, die zugeordnete Zone und den Hostnamen (Subdomain)
 * eines vorhandenen DynDNS-Accounts. Wird aus dem Inline-Edit-Formular in `dyndns.php` aufgerufen.

 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt
 * - CSRF-Schutz aktiv
 * - Eingabevalidierung auf gültige Zone und Zeichenlängen
 *
 * Verhalten bei Erfolg:
 * - Änderungen werden gespeichert
 * - Erfolgsmeldung per Toast
 *
 * Verhalten bei Fehlern:
 * - Fehler werden per Toast gemeldet
 * - Kein Redirect bei systemischem Fehler, sondern Rücksprung zur Tabelle
 */

declare(strict_types=1);
define('IN_APP', true);
require_once __DIR__ . '/../common.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$id       = (int)($_POST['id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$hostname = trim($_POST['hostname'] ?? '');
$zone_id  = (int)($_POST['zone_id'] ?? 0);

if ($id <= 0 || $hostname === '' || $zone_id <= 0 || $username === '') {
    toastError('Ungültige Eingaben.');
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php?edit_id=' . $id);
    exit;
}

// Prüfen auf erlaubte Zone
$stmt = $pdo->prepare("SELECT id FROM zones WHERE id = ? AND allow_dyndns = 1");
$stmt->execute([$zone_id]);
if (!$stmt->fetch()) {
    toastError('Zone ist nicht für DynDNS freigegeben.');
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php?edit_id=' . $id);
    exit;
}

// Bestehende Werte laden
$stmt = $pdo->prepare("SELECT zone_id, hostname, username FROM dyndns_accounts WHERE id = ?");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    toastError('DynDNS-Account nicht gefunden.');
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
    exit;
}

// Vergleich: Nur dann speichern, wenn sich wirklich etwas geändert hat
$usernameChanged = trim((string)$existing['username']) !== trim($username);
$hostnameChanged = trim((string)$existing['hostname']) !== trim($hostname);
$zoneChanged     = (int)$existing['zone_id'] !== (int)$zone_id;
$passwordChanged = $password !== '';

if (!$hostnameChanged && !$zoneChanged && !$usernameChanged && !$passwordChanged) {
    toastSuccess('Keine Änderungen vorgenommen.', 'Der DynDNS-Account ist unverändert.');
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
    exit;
}

try {
    if ($passwordChanged) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            UPDATE dyndns_accounts
            SET password_hash = ?, hostname = ?, zone_id = ?, username = ?
            WHERE id = ?
        ");
        $stmt->execute([$hash, $hostname, $zone_id, $username, $id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE dyndns_accounts
            SET hostname = ?, zone_id = ?, username = ?
            WHERE id = ?
        ");
        $stmt->execute([$hostname, $zone_id, $username, $id]);
    }
    toastSuccess('DynDNS-Account aktualisiert.');
} catch (PDOException $e) {
    toastError('Fehler beim Speichern: ' . $e->getMessage());
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
