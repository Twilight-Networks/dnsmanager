<?php
/**
 * Datei: user_delete.php
 * Zweck: Löscht einen Benutzer aus dem System.
 *
 * Details:
 * - Nur Admins dürfen Benutzer löschen.
 * - Es wird geprüft, ob der Benutzer existiert.
 * - Admin-Benutzer können nur gelöscht werden, wenn noch andere Admins existieren.
 * - Bei Löschung werden auch alle Zonen-Zuweisungen des Benutzers entfernt.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    if ($id <= 0) {
        toastError(
            $LANG['user_error_invalid_id'],
            "Löschvorgang abgebrochen: Ungültige Benutzer-ID übergeben ({$id})."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Rolle abrufen
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetchColumn();

        if (!$role) {
            toastError(
                $LANG['user_error_not_found'],
                "Benutzer-Delete fehlgeschlagen: Benutzer-ID {$id} nicht gefunden."
            );
            $pdo->rollBack();
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
            exit;
        }

        if ($role === 'admin') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admin_count = $stmt->fetchColumn();

            if ($admin_count <= 1) {
                toastError(
                    $LANG['user_error_last_admin'],
                    "Benutzer-ID {$id} war letzter Administrator – Löschung abgebrochen."
                );
                $pdo->rollBack();
                header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
                exit;
            }
        }

        // Benutzer und Zonen-Zuweisungen löschen
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM user_zones WHERE user_id = ?")->execute([$id]);

        $pdo->commit();

        toastSuccess(
            $LANG['user_deleted'],
            "Benutzer-ID {$id} wurde erfolgreich entfernt, inklusive aller Zonen-Zuweisungen."
        );
    } catch (Throwable $e) {
        $pdo->rollBack();
        toastError(
            $LANG['user_error_delete'],
            "Systemfehler beim Löschen von Benutzer-ID {$id}: " . $e->getMessage()
        );
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/users.php");
    exit;
}
?>
