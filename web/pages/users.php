<?php
/**
 * Datei: users.php
 * Zweck: Benutzerverwaltung mit Passwort-Modalfunktion
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/zones/ZoneReadService.php';
require_once __DIR__ . '/../inc/app/users/UserReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
include __DIR__ . '/../templates/layout.php';

$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;

$actor = actorFromSession();

// Benutzer laden (UserReadService: Sichtbarkeit nach DB-Rolle; admin: alle, zoneadmin: eigener Datensatz).
// Vollständige Liste: alle Seiten abrufen, keine Begrenzung auf eine Seite.
$userReadService = new UserReadService($pdo);
$users = [];
$offset = 0;
do {
    $page = $userReadService->listUsers($actor, UserReadService::MAX_LIMIT, $offset);
    $users = array_merge($users, $page['users']);
    $offset += UserReadService::MAX_LIMIT;
} while ($page['hasMore']);

// Liste der verfügbaren Zonen (Formulare; nur für Admins sichtbar), vollständig, nach Name sortiert
$zoneReadService = new ZoneReadService($pdo, new ZoneAccess($pdo));
$zones = [];
$offset = 0;
do {
    $page = $zoneReadService->listZones($actor, ZoneReadService::MAX_LIMIT, $offset);
    foreach ($page['zones'] as $z) {
        $zones[] = ['id' => $z['id'], 'name' => $z['name']];
    }
    $offset += ZoneReadService::MAX_LIMIT;
} while ($page['hasMore']);

// Anzahl der aktiven Admins (S27, PR B): steuert die Anzeige von „Löschen“ und „Deaktivieren“ wie die
// Prüfung im UserService (last_admin) – deaktivierte Admins zählen nicht und bleiben löschbar.
// Maßgeblich bleibt in allen Fällen der Service.
$active_admin_count = count(array_filter($users, static fn (array $u): bool => $u['role'] === 'admin' && $u['active']));
$current_user_id = (int)$_SESSION['user_id'];
?>

<br><br>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= $LANG['user_management'] ?></h2>
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="pages/users.php?add_new=1" class="btn btn-success">+ <?= $LANG['add_user'] ?></a>
    <?php endif; ?>
</div>

<?php if ($_SESSION['role'] === 'admin' && isset($_GET['add_new']) && $_GET['add_new'] == '1'): ?>
    <?php include __DIR__ . '/../templates/user_add_form.php'; ?>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th class="coltbl-users-name"><?= $LANG['username'] ?></th>
                <th class="coltbl-users-role">Rolle</th>
                <th><?= $LANG['user_status'] ?></th>
                <th class="coltbl-users-zones"><?= $LANG['zones'] ?></th>
                <th class="coltbl-actions"><?= $LANG['actions'] ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <?php if ($edit_id === (int)$u['id']): ?>
                <?php
                // Zugewiesene Zonen-IDs für das Bearbeitungsformular (aus dem UserReadService)
                $selected = array_column($u['zones'], 'id');
                ?>
                <tr class="table-warning">
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['role']) ?></td>
                    <td><?= $u['active'] ? '<span class="badge bg-success">' . $LANG['user_status_active'] . '</span>' : '<span class="badge bg-secondary">' . $LANG['user_status_inactive'] . '</span>' ?></td>
                    <td><em><?= $LANG['edit_mode'] ?></em></td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="submit" form="editForm_<?= $u['id'] ?>" class="btn btn-sm btn-success"><?= $LANG['save'] ?></button>
                            <a href="pages/users.php" class="btn btn-sm btn-secondary"><?= $LANG['cancel'] ?></a>
                        </div>
                    </td>
                </tr>
                <?php
                include __DIR__ . '/../templates/user_edit_form.php';
                ?>
            <?php else: ?>
                <tr<?= $u['active'] ? '' : ' class="text-muted"' ?>>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['role']) ?></td>
                    <td><?= $u['active'] ? '<span class="badge bg-success">' . $LANG['user_status_active'] . '</span>' : '<span class="badge bg-secondary">' . $LANG['user_status_inactive'] . '</span>' ?></td>
                    <td>
                        <?php if ($u['role'] === 'admin'): ?>
                            <em><?= $LANG['all_zones'] ?></em>
                        <?php else: ?>
                            <?php
                            $userZoneNames = array_column($u['zones'], 'name');
                            foreach ($userZoneNames as $zname): ?>
                                <span class="badge bg-primary"><?= htmlspecialchars($zname) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="d-flex flex-wrap gap-1">
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <a href="pages/users.php?edit_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary"><?= $LANG['edit'] ?></a>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pwModal<?= $u['id'] ?>"><?= $LANG['password'] ?></button>
                        <?php if ($_SESSION['role'] === 'admin' && $u['active'] && $u['id'] !== $current_user_id && !($u['role'] === 'admin' && $active_admin_count <= 1)): ?>
                            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#disableModal<?= $u['id'] ?>"><?= $LANG['user_disable'] ?></button>
                        <?php endif; ?>
                        <?php if ($_SESSION['role'] === 'admin' && !$u['active']): ?>
                            <form method="post" action="actions/user_set_active.php" class="d-inline">
                                <?= csrf_input() ?>
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="active" value="1">
                                <button class="btn btn-sm btn-outline-success"><?= $LANG['user_enable'] ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($_SESSION['role'] === 'admin' && !($u['role'] === 'admin' && $u['active'] && $active_admin_count <= 1)): ?>
                            <form method="post" action="actions/user_delete.php" class="d-inline confirm-delete">
                                <?= csrf_input() ?>
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><?= $LANG['delete'] ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php if ($_SESSION['role'] === 'admin' && $u['active'] && $u['id'] !== $current_user_id && !($u['role'] === 'admin' && $active_admin_count <= 1)): ?>
            <!-- Deaktivieren-Modal (S27, PR B) -->
            <div class="modal fade" id="disableModal<?= $u['id'] ?>" tabindex="-1" aria-labelledby="disableModalLabel<?= $u['id'] ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="actions/user_set_active.php">
                            <?= csrf_input() ?>
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="active" value="0">
                            <div class="modal-header">
                                <h5 class="modal-title" id="disableModalLabel<?= $u['id'] ?>"><?= $LANG['user_disable_confirm_title'] ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $LANG['close'] ?>"></button>
                            </div>
                            <div class="modal-body">
                                <?= sprintf($LANG['user_disable_confirm_text'], htmlspecialchars($u['username'])) ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= $LANG['cancel'] ?></button>
                                <button type="submit" class="btn btn-sm btn-warning"><?= $LANG['user_disable'] ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Passwort-Modal -->
            <div class="modal fade" id="pwModal<?= $u['id'] ?>" tabindex="-1" aria-labelledby="pwModalLabel<?= $u['id'] ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="actions/user_password.php">
                            <?= csrf_input() ?>
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <div class="modal-header">
                                <h5 class="modal-title" id="pwModalLabel<?= $u['id'] ?>">
                                    <?= sprintf($LANG['change_password_for'], htmlspecialchars($u['username'])) ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label"><?= $LANG['new_password'] ?></label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <?php if ((int)$_SESSION['user_id'] === $u['id']): ?>
                                    <div class="mb-3">
                                        <label class="form-label"><?= $LANG['repeat_password'] ?></label>
                                        <input type="password" name="password_repeat" class="form-control" required>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= $LANG['cancel'] ?></button>
                                <button type="submit" class="btn btn-sm btn-success"><?= $LANG['save'] ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
