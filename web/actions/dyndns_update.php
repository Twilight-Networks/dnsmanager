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
    toastError($LANG['dyndns_error_invalid_input']);
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php?edit_id=' . $id);
    exit;
}

// Prüfen auf erlaubte Zone
$stmt = $pdo->prepare("SELECT id FROM zones WHERE id = ? AND allow_dyndns = 1");
$stmt->execute([$zone_id]);
if (!$stmt->fetch()) {
    toastError($LANG['dyndns_error_zone_not_allowed']);
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php?edit_id=' . $id);
    exit;
}

// Bestehende Werte laden
$stmt = $pdo->prepare("SELECT zone_id, hostname, username, current_ipv4, current_ipv6 FROM dyndns_accounts WHERE id = ?");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    toastError($LANG['dyndns_error_not_found']);
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
    exit;
}

$current_ipv4 = $existing['current_ipv4'] ?? null;
$current_ipv6 = $existing['current_ipv6'] ?? null;

// Vergleich: Nur dann speichern, wenn sich wirklich etwas geändert hat
$usernameChanged = trim((string)$existing['username']) !== trim($username);
$hostnameChanged = trim((string)$existing['hostname']) !== trim($hostname);
$zoneChanged     = (int)$existing['zone_id'] !== (int)$zone_id;
$passwordChanged = $password !== '';

if (!$hostnameChanged && !$zoneChanged && !$usernameChanged && !$passwordChanged) {
    toastSuccess($LANG['no_changes'], 'Der DynDNS-Account ist unverändert.');
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
    exit;
}

$pdo->beginTransaction();
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

    $affected_zones = [];

    // Wenn Hostname oder Zone geändert wurde, DNS-Records entsprechend anpassen
    if ($hostnameChanged || $zoneChanged) {
        // A/AAAA Records in alter Zone löschen
        $stmt = $pdo->prepare("
            DELETE FROM records WHERE zone_id = ? AND name = ? AND type IN ('A', 'AAAA')
        ");
        $stmt->execute([$existing['zone_id'], $existing['hostname']]);
        $affected_zones[] = $existing['zone_id'];

        // Neue A/AAAA-Records in neuer Zone anlegen – mit bestehenden IPs
        $hasInsertedAny = false;

        if (!empty($current_ipv4)) {
            $stmt = $pdo->prepare("
                INSERT INTO records (zone_id, name, type, content, ttl)
                VALUES (?, ?, 'A', ?, 300)
            ");
            $stmt->execute([$zone_id, $hostname, $current_ipv4]);
            $hasInsertedAny = true;
        }

        if (!empty($current_ipv6)) {
            $stmt = $pdo->prepare("
                INSERT INTO records (zone_id, name, type, content, ttl)
                VALUES (?, ?, 'AAAA', ?, 300)
            ");
            $stmt->execute([$zone_id, $hostname, $current_ipv6]);
            $hasInsertedAny = true;
        }

        if (!$hasInsertedAny) {
            toastWarning($LANG['dyndns_no_ip_warning'], 'Es wurden keine A-/AAAA-Records gesetzt, da keine aktuelle IP vorliegt.');
        }

        $affected_zones[] = $zone_id;
    }

    // Zonen neu bauen – aber nur, wenn mindestens ein Record geschrieben wurde
    if (!empty($affected_zones) && $hasInsertedAny) {
        $affected_zones = array_unique($affected_zones);
        foreach ($affected_zones as $zid) {
            $result = rebuild_zone_and_flag_if_valid((int)$zid);
            if ($result['status'] === 'error') {
                $pdo->rollBack();
                toastError(sprintf($LANG['zone_rebuild_failed'], $zid), $result['output']);
                header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php?edit_id=' . $id);
                exit;
            } elseif ($result['status'] === 'warning') {
                toastWarning(sprintf($LANG['zone_rebuild_warning'], $zid), $result['output']);
            }
        }
    }

    $pdo->commit();
    if (!empty($affected_zones) && $hasInsertedAny) {
        toastSuccess($LANG['dyndns_updated'], 'Änderungen gespeichert und betroffene Zonen rebuildet.');
    } else {
        toastSuccess($LANG['dyndns_updated'], 'Änderungen gespeichert – kein Zonen-Rebuild notwendig.');
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    toastError($LANG['dyndns_error_update'] . ': ' . $e->getMessage());
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
