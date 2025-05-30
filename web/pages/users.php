<?php
/**
 * Datei: users.php
 * Zweck: Benutzerverwaltung mit Passwort-Modalfunktion
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
include __DIR__ . '/../templates/layout.php';

$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;

// Benutzer laden
if ($_SESSION['role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM users ORDER BY role");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $pdo->prepare("SELECT id, name FROM zones ORDER BY name");
$stmt->execute();
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = :role");
$stmt->execute(['role' => 'admin']);
$admin_count = $stmt->fetchColumn();

$csrf_token = csrf_input();
?>

<br>
<br>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Benutzerverwaltung</h2>
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="pages/users.php?add_new=1" class="btn btn-success">+ Neuer Benutzer</a>
    <?php endif; ?>
</div>

<?php if ($_SESSION['role'] === 'admin' && isset($_GET['add_new']) && $_GET['add_new'] == '1'): ?>
    <?php include __DIR__ . '/../templates/user_add_form.php'; ?>
<?php endif; ?>

<table class="table table-bordered align-middle">
    <thead class="table-light">
        <tr>
            <th class="coltbl-users-name">Benutzername</th>
            <th class="coltbl-users-role">Rolle</th>
            <th class="coltbl-users-zones">Zonen</th>
            <th class="coltbl-actions">Aktionen</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <?php if ($edit_id === (int)$u['id']): ?>
            <!-- Bearbeitungszeile -->
            <tr>
                <td colspan="4">
                    <?php
                    $user_zone_ids = $pdo->prepare("SELECT zone_id FROM user_zones WHERE user_id = ?");
                    $user_zone_ids->execute([$u['id']]);
                    $selected = array_column($user_zone_ids->fetchAll(PDO::FETCH_ASSOC), 'zone_id');
                    include __DIR__ . '/../templates/user_edit_form.php';
                    ?>
                </td>
            </tr>
        <?php else: ?>
            <!-- Normale Benutzeranzeige -->
            <tr>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['role']) ?></td>
                <td>
                    <?php if ($u['role'] === 'admin'): ?>
                        <em>Alle Zonen</em>
                    <?php else: ?>
                        <?php
                        $zstmt = $pdo->prepare("SELECT z.name FROM zones z JOIN user_zones uz ON uz.zone_id = z.id WHERE uz.user_id = ?");
                        $zstmt->execute([$u['id']]);
                        $zones = $zstmt->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($zones as $zname): ?>
                            <span class="badge bg-primary"><?= htmlspecialchars($zname) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td class="d-flex flex-wrap gap-1">
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <a href="pages/users.php?edit_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pwModal<?= $u['id'] ?>">Passwort</button>
                    <?php if ($_SESSION['role'] === 'admin' && !($u['role'] === 'admin' && $admin_count <= 1)): ?>
                        <form method="post" action="actions/user_delete.php" class="d-inline confirm-delete">
                            <?= $csrf_token ?>
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Löschen</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>

        <!-- Passwort Modal (immer anzeigen, unabhängig vom Bearbeiten-Zustand) -->
        <div class="modal fade" id="pwModal<?= $u['id'] ?>" tabindex="-1" aria-labelledby="pwModalLabel<?= $u['id'] ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="actions/user_password.php">
                        <?= $csrf_token ?>
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title" id="pwModalLabel<?= $u['id'] ?>">Passwort ändern für <?= htmlspecialchars($u['username']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Neues Passwort</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <?php if ($_SESSION['user_id'] === $u['id']): ?>
                                <div class="mb-3">
                                    <label class="form-label">Passwort wiederholen</label>
                                    <input type="password" name="password_repeat" class="form-control" required>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
