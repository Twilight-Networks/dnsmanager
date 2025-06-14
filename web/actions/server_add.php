<?php
/**
 * Datei: server_add.php
 * Zweck: Fügt einen neuen DNS-Server in die Datenbank ein.
 *
 * Funktionen:
 * - Erwartet POST-Anfrage mit Serverdaten.
 * - Führt Validierung der Eingaben durch (Name, IP-Adressen, Token).
 * - Erzwingt, dass nur ein Server als "lokal" markiert sein darf.
 * - Fügt neuen Datensatz in die Tabelle `servers` ein.
 * - Setzt Feedback-Nachricht und leitet zurück zur Serverübersicht.
 *
 * Zugriff:
 * - Nur eingeloggte Benutzer (admin) dürfen diesen Endpunkt aufrufen.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/validators.php';

// Nur POST-Anfragen erlaubt
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Nur POST erlaubt');
}

// Felder einlesen und bereinigen
$name       = trim($_POST['name'] ?? '');
$dns_ip4     = trim($_POST['dns_ip4'] ?? '');
$dns_ip6    = trim($_POST['dns_ip6'] ?? '');
$api_ip     = trim($_POST['api_ip'] ?? '');
$api_token  = trim($_POST['api_token'] ?? '');
$is_local   = isset($_POST['is_local']) ? 1 : 0;
$active     = isset($_POST['active']) ? 1 : 0;

// Eingabevalidierung
$errors = [];

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

// Nur ein lokaler Server erlaubt
if ($is_local) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE is_local = 1");
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        $errors[] = $LANG['server_error_local_exists'];
    }
}

if (!empty($errors)) {
    foreach ($errors as $err) {
        toastError(
            $LANG['server_error_invalid_input'] . ': ' . htmlspecialchars($err),
            "Validierungsfehler beim Hinzufügen von Server: {$err}"
        );
    }
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php?add_new=1");
    exit;
}

// Server in Datenbank einfügen
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO servers (name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active)
        VALUES (:name, :dns_ip4, :dns_ip6, :api_ip, :api_token, :is_local, :active)
    ");
    $stmt->execute([
        ':name'      => $name,
        ':dns_ip4'    => $valid_dns_ip4 ? $dns_ip4 : null,
        ':dns_ip6'   => $valid_dns_ip6 ? $dns_ip6 : null,
        ':api_ip'    => $api_ip !== '' ? $api_ip : null,
        ':api_token' => $api_token,
        ':is_local'  => $is_local,
        ':active'    => $active,
    ]);

    $pdo->commit();

    toastSuccess(
        sprintf($LANG['server_created_success'], htmlspecialchars($name)),
        "Neuer Server hinzugefügt: {$name} ({$dns_ip4}) ({$dns_ip6}), lokal={$is_local}, aktiv={$active}"
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php");
    exit;

} catch (PDOException $e) {
    $pdo->rollBack();
    toastError(
        $LANG['server_error_db_save_failed'],
        "Datenbankfehler beim Hinzufügen von Server '{$name}': " . $e->getMessage()
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/servers.php?add_new=1");
    exit;
}
