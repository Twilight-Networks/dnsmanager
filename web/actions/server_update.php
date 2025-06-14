<?php
/**
 * Datei: server_update.php
 * Zweck: Aktualisiert einen bestehenden DNS-Servereintrag im System.
 *
 * Funktionen:
 * - Validiert die übermittelten Eingaben (Name, IP-Adressen, API-Token).
 * - Verhindert das Deaktivieren eines Servers, der als Master-Server in einer Zone verwendet wird.
 * - Stellt sicher, dass nur ein Server als "local" markiert ist.
 * - Aktualisiert die Datenbank mit den neuen Serverinformationen.
 * - Bei Änderungen an Name oder IP-Adresse: führt Zonendatei-Rebuilds für alle betroffenen Zonen aus.
 * - Gibt Rückmeldung über Toast-Nachrichten bei Fehlern oder Erfolg.
 */
require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/validators.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Nur POST erlaubt');
}

$id         = (int)($_POST['id'] ?? 0);
$name       = trim($_POST['name'] ?? '');
$dns_ip4    = trim($_POST['dns_ip4'] ?? '');
$dns_ip6    = trim($_POST['dns_ip6'] ?? '');
$api_ip     = trim($_POST['api_ip'] ?? '');
$api_token  = trim($_POST['api_token'] ?? '');
$is_local   = isset($_POST['is_local']) ? 1 : 0;
$active     = isset($_POST['active']) ? 1 : 0;

$errors = [];

// Eingabevalidierung
if ($id < 1) {
    $errors[] = $LANG['server_error_invalid_id'];
}

if ($name === '' || strlen($name) > 100 || !isValidFqdn($name) || substr_count(rtrim($name, '.'), '.') < 1) {
    $errors[] = $LANG['server_error_invalid_name'];
}

$valid_dns_ip4 = filter_var($dns_ip4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
$valid_dns_ip6 = filter_var($dns_ip6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

if (!$valid_dns_ip4 && !$valid_dns_ip6) {
    $errors[] = $LANG['server_error_invalid_dns_ip'];
}

if ($dns_ip4 !== '' && !$valid_dns_ip4) {
    $errors[] = $LANG['server_error_invalid_ipv4'];
}
if ($dns_ip6 !== '' && !$valid_dns_ip6) {
    $errors[] = $LANG['server_error_invalid_ipv6'];
}

if ($api_ip !== '' && !filter_var($api_ip, FILTER_VALIDATE_IP)) {
    $errors[] = $LANG['server_error_invalid_api_ip'];
}

if (!$is_local && $api_ip === '') {
    $errors[] = $LANG['server_error_api_ip_required'];
}

if (!$is_local && ($api_token === '' || strlen($api_token) < 32)) {
    $errors[] = $LANG['server_error_api_token_required'];
}

if (!empty($errors)) {
    foreach ($errors as $e) {
        toastError(
            $LANG['server_error_invalid_input'] . ': ' . htmlspecialchars($e),
            "Validierungsfehler beim Server-Update '{$name}' (ID {$id}): {$e}"
        );
    }
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;
}

// Bestehende Daten abrufen
$stmt = $pdo->prepare("
    SELECT name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active
    FROM servers
    WHERE id = ?
");$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    toastError(
        $LANG['error_server_not_found'],
        "Server-ID {$id} konnte nicht geladen werden."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;
}

// Vorbereitete neue Werte – werden auch später für das UPDATE verwendet
$update_data = [
    'name'      => $name,
    'dns_ip4'   => $valid_dns_ip4 ? $dns_ip4 : null,
    'dns_ip6'   => $valid_dns_ip6 ? $dns_ip6 : null,
    'api_ip'    => $api_ip !== '' ? $api_ip : null,
    'api_token' => $api_token,
    'is_local'  => $is_local,
    'active'    => $active
];

// Vergleich auf Änderungen (nur Name und IPs – Statusänderung wird separat geprüft)
$server_data_changed = (
    $existing['name']    !== $update_data['name'] ||
    $existing['dns_ip4'] !== $update_data['dns_ip4'] ||
    $existing['dns_ip6'] !== $update_data['dns_ip6']
);

// Prüfen, ob sich etwas geändert hat (inkl. API, Status etc.)
$nonCriticalChanged =
    $existing['active'] !== $update_data['active'] ||
    $existing['is_local'] !== $update_data['is_local'] ||
    $existing['api_ip'] !== $update_data['api_ip'] ||
    $existing['api_token'] !== $update_data['api_token'];

// Wenn gar nichts verändert wurde → sofortiger Abbruch
if (!$server_data_changed && !$nonCriticalChanged) {
    toastSuccess(
        $LANG['no_changes'],
        "Die Serverdaten sind unverändert."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;
}

// Verhindern, dass ein aktiver Master-Server deaktiviert wird
if ($active === 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM zone_servers WHERE server_id = ? AND is_master = 1");
    $stmt->execute([$id]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        toastError(
            $LANG['server_error_master_deactivation_blocked'],
            "Aktiver Master-Server '{$name}' (ID {$id}) sollte deaktiviert werden – abgebrochen."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
        exit;
    }
}

$pdo->beginTransaction();

try {
    if ($is_local) {
        $stmt = $pdo->prepare("UPDATE servers SET is_local = 0 WHERE is_local = 1 AND id != ?");
        $stmt->execute([$id]);
    }

    $stmt = $pdo->prepare("
        UPDATE servers
        SET name = :name,
            dns_ip4 = :dns_ip4,
            dns_ip6 = :dns_ip6,
            api_ip = :api_ip,
            api_token = :api_token,
            is_local = :is_local,
            active = :active
        WHERE id = :id
    ");
    $stmt->execute($update_data + [':id' => $id]);

    $pdo->commit();

    // DNS-Rebuild für alle betroffenen Zonen, wenn sich etwas geändert hat
    if ($server_data_changed) {
        $stmt = $pdo->prepare("SELECT zone_id FROM zone_servers WHERE server_id = ?");
        $stmt->execute([$id]);
        $zone_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($zone_ids as $zid) {
            $result = rebuild_ns_and_glue_for_zone_and_flag_if_valid($pdo, (int)$zid);

            if ($result['status'] === 'error') {
                toastError(
                    sprintf($LANG['zone_rebuild_failed'], $zid),
                    $result['output']
                );
            } elseif ($result['status'] === 'warning') {
                toastWarning(
                    sprintf($LANG['zone_rebuild_warning'], $zid),
                    $result['output']
                );
            }
        }
    }

    toastSuccess(
        sprintf($LANG['server_updated_success'], htmlspecialchars($name)),
        "Server '{$name}' (ID {$id}) erfolgreich aktualisiert mit neuen Daten."
    );
} catch (Exception $e) {
    $pdo->rollBack();
    toastError(
        $LANG['server_error_db_update_failed'],
        "Fehler beim Speichern von Server '{$name}' (ID {$id}): " . $e->getMessage()
    );
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
exit;
