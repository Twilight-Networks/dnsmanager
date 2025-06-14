<?php
/**
 * Datei: actions/dyndns_password.php
 * Zweck: Passwort eines bestehenden DynDNS-Accounts ändern
 *
 * Beschreibung:
 * Diese Datei verarbeitet POST-Anfragen zur Passwortänderung eines DynDNS-Accounts.
 * Sie wird typischerweise aus einem Modal heraus aufgerufen, das in `dyndns.php` definiert ist.
 *
 * Ablauf:
 * - Überprüfung der Benutzerrolle (nur Admin)
 * - CSRF-Token-Verifikation
 * - Validierung des neuen Passworts
 * - Hashing und Speicherung in der Datenbank
 *
 * Sicherheitsmaßnahmen:
 * - HTTP 403 bei unberechtigtem Zugriff
 * - CSRF-Schutz
 * - Passwort wird nur in gehashter Form gespeichert (bcrypt)
 *
 * Rückmeldung:
 * - Erfolgreiche Änderung führt zu einem Toast mit Bestätigung
 * - Fehlerhafte Eingabe oder Systemfehler lösen Fehlermeldungen aus
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    toastError(
        $LANG['dyndns_error_unauthorized'],
        "Nicht-Admin versucht DynDNS-Passwort zu ändern."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/dyndns.php");
    exit;
}

$account_id = (int)($_POST['id'] ?? 0);
$password = trim($_POST['password'] ?? '');

if (!validatePassword($password)) {
    toastError(
        sprintf($LANG['error_password_too_short'], PASSWORD_MIN_LENGTH),
        "Passwort-Änderung für DynDNS-ID {$account_id} abgelehnt: Passwort zu kurz."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/dyndns.php");
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    toastError(
        $LANG['error_password_processing'],
        "Passwort-Hashing fehlgeschlagen für DynDNS-ID {$account_id}."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/dyndns.php");
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE dyndns_accounts SET password_hash = ? WHERE id = ?");
    $stmt->execute([$hash, $account_id]);

    $pdo->commit();

    toastSuccess(
        $LANG['password_changed'],
        "DynDNS-Passwort aktualisiert für ID {$account_id}."
    );

} catch (Throwable $e) {
    $pdo->rollBack();
    toastError(
        $LANG['error_password_save'],
        "Fehler beim Ändern des DynDNS-Passworts für ID {$account_id}: " . $e->getMessage()
    );
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/dyndns.php");
exit;
