<?php
/**
 * Datei: actions/user_password.php
 * Zweck: Passwort eines Benutzers ändern.
 *
 * - Admins dürfen jedes Passwort ändern (nur 1x-Eingabe nötig)
 * - Benutzer dürfen ihr eigenes ändern (2x-Eingabe erforderlich)
 * - Passwörter werden per bcrypt sicher gehasht
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();

$user_id = (int)($_POST['id'] ?? 0);
$current_user_id = (int)$_SESSION['user_id'];
$is_self = ($user_id === $current_user_id);
$is_admin = ($_SESSION['role'] === 'admin');

if (!$is_admin && !$is_self) {
    toastError(
        $LANG['error_password_unauthorized'],
        "Verbotener Passwort-Änderungsversuch für Benutzer-ID {$user_id} durch Benutzer-ID {$current_user_id}."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

$password = trim($_POST['password'] ?? '');
$password_repeat = trim($_POST['password_repeat'] ?? '');

if (!validatePassword($password)) {
    toastError(
        sprintf($LANG['error_password_too_short'], PASSWORD_MIN_LENGTH),
        "Passwort-Änderung abgelehnt: Passwort zu kurz für Benutzer-ID {$user_id}."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

if ($is_self && $password !== $password_repeat) {
    toastError(
        $LANG['error_password_mismatch'],
        "Passwort-Änderung durch Benutzer-ID {$user_id} abgebrochen: Eingaben nicht identisch."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

// Passwort hashen vor der Transaktion
$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    toastError(
        $LANG['error_password_processing'],
        "Passwort-Hashing für Benutzer-ID {$user_id} fehlgeschlagen."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$hash, $user_id]);

    $pdo->commit();

    toastSuccess(
        $is_self
            ? $LANG['password_changed']
            : $LANG['password_changed_admin'],
        "Passwort erfolgreich geändert für Benutzer-ID {$user_id}" . ($is_self ? " (eigenes Konto)" : " (durch Admin)")
    );

} catch (Exception $e) {
    $pdo->rollBack();
    toastError(
        $LANG['error_password_save'],
        "Fehler beim Speichern des Passworts für Benutzer-ID {$user_id}: " . $e->getMessage()
    );
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
exit;
