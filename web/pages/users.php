<?php
/**
 * Datei: users.php
 * Zweck: Benutzerverwaltung im A+-Design (U5b).
 *
 * - Tabelle der sichtbaren Benutzer (UserReadService: admin alle, zoneadmin nur das eigene Konto) mit Rolle, Status
 *   und Zonen; Aktionen nach den bisherigen Rollenregeln.
 * - Rechter Editor: Anlage (add_new) und Bearbeitung (edit_id) nur für admin (U0-B4: zoneadmin erhält kein
 *   Bearbeitungsformular mehr); Passwortänderung (pw_id) für jedes sichtbare Konto – zoneadmin also nur das eigene,
 *   beim eigenen Konto mit Passwortwiederholung. Auch ohne JavaScript; das Öffnen ändert keinen Zustand.
 * - Deaktivieren mit Inline-Bestätigung (auch ohne JavaScript), Aktivieren und Löschen wie bisher. Schutz des
 *   letzten aktiven Administrators und des eigenen Kontos in der Anzeige wie bisher; maßgeblich bleibt der UserService.
 * - Actions, Felder und CSRF unverändert (user_add.php, user_update.php, user_password.php, user_set_active.php,
 *   user_delete.php); Session-Verhalten nach eigener Passwortänderung unverändert (Action).
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/ZoneAccess.php';
require_once __DIR__ . '/../inc/app/zones/ZoneReadService.php';
require_once __DIR__ . '/../inc/app/users/UserReadService.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';
include __DIR__ . '/../templates/layout.php';

$isAdmin = $_SESSION['role'] === 'admin';
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$pw_id = isset($_GET['pw_id']) ? (int)$_GET['pw_id'] : null;
$add_new = $isAdmin && isset($_GET['add_new']) && $_GET['add_new'] == '1';

$actor = actorFromSession();

// Benutzer laden (UserReadService: Sichtbarkeit nach DB-Rolle; admin: alle, zoneadmin: eigener Datensatz), vollständig
$userReadService = new UserReadService($pdo);
$users = [];
$offset = 0;
do {
    $page = $userReadService->listUsers($actor, UserReadService::MAX_LIMIT, $offset);
    $users = array_merge($users, $page['users']);
    $offset += UserReadService::MAX_LIMIT;
} while ($page['hasMore']);
$usersById = array_column($users, null, 'id');

// Verfügbare Zonen (Formulare, nur admin relevant), vollständig, nach Name sortiert
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

// Anzahl aktiver Admins (S27): steuert die Anzeige von „Löschen“ und „Deaktivieren“ wie die Prüfung im UserService
$active_admin_count = count(array_filter($users, static fn (array $u): bool => $u['role'] === 'admin' && $u['active']));
$current_user_id = (int)$_SESSION['user_id'];
$canDisable = static fn (array $u): bool => $isAdmin && $u['active'] && $u['id'] !== $current_user_id && !($u['role'] === 'admin' && $active_admin_count <= 1);
$canDelete = static fn (array $u): bool => $isAdmin && !($u['role'] === 'admin' && $u['active'] && $active_admin_count <= 1);

$addButton = $isAdmin ? '<a href="pages/users.php?add_new=1" class="btn btn-success">+ ' . ui_e($LANG['add_user']) . '</a>' : '';
echo ui_page_header($LANG['user_management'], ['subtitle' => sprintf($LANG['users_count'], count($users)), 'actionsHtml' => $addButton]);

echo ui_card_open($LANG['users'], ['flush' => true, 'actionsHtml' => ui_chip((string)count($users))]);
echo '<div class="table-responsive"><table class="dm-table"><thead><tr>'
    . '<th>' . ui_e($LANG['username']) . '</th><th>' . ui_e($LANG['role']) . '</th><th>' . ui_e($LANG['user_status']) . '</th>'
    . '<th>' . ui_e($LANG['zones']) . '</th><th class="dm-col-actions">' . ui_e($LANG['actions']) . '</th></tr></thead><tbody>';
foreach ($users as $u) {
    $isCurrent = $u['id'] === $edit_id || $u['id'] === $pw_id;
    echo '<tr' . ($isCurrent ? ' class="dm-current"' : ($u['active'] ? '' : ' class="dm-muted-row"')) . '>';
    echo '<td class="dm-mono">' . ui_e($u['username']) . ($u['id'] === $current_user_id ? ' ' . ui_chip($LANG['user_self'], 'info') : '') . '</td>';
    echo '<td>' . ui_chip($u['role'] === 'admin' ? $LANG['role_admin'] : $LANG['role_zoneadmin'], $u['role'] === 'admin' ? 'accent' : 'neutral') . '</td>';
    echo '<td>' . ($u['active'] ? ui_chip($LANG['user_status_active'], 'ok') : ui_chip($LANG['user_status_inactive'])) . '</td>';
    echo '<td>';
    if ($u['role'] === 'admin') {
        echo '<em>' . ui_e($LANG['all_zones']) . '</em>';
    } else {
        foreach (array_column($u['zones'], 'name') as $zname) {
            echo ui_chip((string)$zname) . ' ';
        }
    }
    echo '</td><td class="dm-col-actions">';
    if ($isAdmin) {
        echo '<a class="btn btn-sm btn-outline-secondary" href="pages/users.php?edit_id=' . $u['id'] . '">' . ui_e($LANG['edit']) . '</a> ';
    }
    echo '<a class="btn btn-sm btn-outline-secondary" href="pages/users.php?pw_id=' . $u['id'] . '">' . ui_e($LANG['password']) . '</a> ';
    if ($canDisable($u)) {
        // Deaktivieren (S27): bestehender POST-/CSRF-Vertrag (user_set_active.php, active=0); Bestätigung inline statt Modal
        echo '<form method="post" action="actions/user_set_active.php" class="d-inline">' . csrf_input()
            . '<input type="hidden" name="id" value="' . $u['id'] . '"><input type="hidden" name="active" value="0">'
            . ui_confirm_action_button($LANG['user_disable'], ['prompt' => sprintf($LANG['user_disable_confirm_text'], $u['username']), 'confirm' => $LANG['user_disable']])
            . '</form> ';
    }
    if ($isAdmin && !$u['active']) {
        echo '<form method="post" action="actions/user_set_active.php" class="d-inline">' . csrf_input()
            . '<input type="hidden" name="id" value="' . $u['id'] . '"><input type="hidden" name="active" value="1">'
            . '<button class="btn btn-sm btn-outline-success">' . ui_e($LANG['user_enable']) . '</button></form> ';
    }
    if ($canDelete($u)) {
        echo '<form method="post" action="actions/user_delete.php" class="d-inline confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . $u['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo ui_card_close();

// Rechter Editor: Anlage/Bearbeitung nur admin (U0-B4); Passwort für jedes sichtbare Konto
$edit_user = ($isAdmin && $edit_id !== null && isset($usersById[$edit_id])) ? $usersById[$edit_id] : null;
$pw_user = ($pw_id !== null && isset($usersById[$pw_id])) ? $usersById[$pw_id] : null;
if ($add_new || $edit_user !== null || $pw_user !== null) {
    $mode = $add_new ? 'create' : ($edit_user !== null ? 'update' : 'password');
    $titles = ['create' => $LANG['add_new_user'], 'update' => $LANG['user_editor_title_edit'],
        'password' => sprintf($LANG['change_password_for'], $pw_user['username'] ?? '')];
    echo ui_editor_open($titles[$mode], 'pages/users.php', [
        'subtitle' => $mode === 'update' ? $edit_user['username'] : '',
        'closeLabel' => $LANG['close'],
    ]);
    $formId = 'userForm';
    if ($mode === 'password') {
        $own = $pw_user['id'] === $current_user_id;
        echo '<form method="post" action="actions/user_password.php" id="' . $formId . '">' . csrf_input()
            . '<input type="hidden" name="id" value="' . $pw_user['id'] . '"><div class="dm-field-grid">'
            . '<div class="dm-field dm-field-wide"><label class="form-label" for="usr_password">' . ui_e($LANG['new_password']) . '</label>'
            . '<input type="password" name="password" id="usr_password" class="form-control" required autocomplete="new-password"></div>';
        if ($own) {
            // Eigenes Konto: Passwortwiederholung wie bisher (Prüfung und Session-Verhalten in user_password.php)
            echo '<div class="dm-field dm-field-wide"><label class="form-label" for="usr_password_repeat">' . ui_e($LANG['repeat_password']) . '</label>'
                . '<input type="password" name="password_repeat" id="usr_password_repeat" class="form-control" required autocomplete="new-password"></div>';
        }
        echo '</div></form>';
        if ($own) {
            echo '<div class="dm-editor-note">' . ui_icon('info-circle') . '<span>' . ui_e($LANG['user_password_own_hint']) . '</span></div>';
        }
    } else {
        $selected = $mode === 'update' ? array_column($edit_user['zones'], 'id') : [];
        include __DIR__ . '/../templates/user_editor_form.php';
    }
    $leftHtml = '';
    if ($mode === 'update' && $canDelete($edit_user)) {
        $leftHtml = '<form method="post" action="actions/user_delete.php" class="confirm-delete">' . csrf_input()
            . '<input type="hidden" name="id" value="' . $edit_user['id'] . '">'
            . ui_confirm_delete_button($LANG['delete'], ['prompt' => $LANG['confirm_delete_prompt'], 'confirm' => $LANG['confirm_delete_final']])
            . '</form>';
    }
    echo ui_editor_close('pages/users.php', [
        'formId' => $formId, 'saveLabel' => $mode === 'create' ? $LANG['create_user'] : $LANG['save'],
        'cancelLabel' => $LANG['cancel'], 'leftHtml' => $leftHtml,
    ]);
}

AssetRegistry::enqueueScript('js/users_editor.js');
include __DIR__ . '/../templates/layout_footer.php';
