<?php
/**
 * Datei: zone_update.php
 * Zweck: Aktualisiert eine bestehende DNS-Zone.
 *
 * Funktionen:
 * - Validiert Rechte des Benutzers (admin oder zugewiesener zoneadmin).
 * - Aktualisiert TTL, Beschreibung, SOA-Werte und ggf. Prefix-Länge.
 * - Übernimmt die zugewiesenen DNS-Server inklusive Master-Zuweisung.
 * - Führt Zonendatei-Rebuild und Validierung durch.
 * - Gibt Rückmeldung über Toast-Nachricht und leitet zur Übersicht zurück.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$id = (int)($_POST['id'] ?? 0);
requireRole(['admin', 'zoneadmin']);

// Rechteprüfung für zoneadmin
if ($_SESSION['role'] !== 'admin') {
    $stmt = $pdo->prepare("SELECT 1 FROM user_zones WHERE zone_id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        logAccessDenied("Unbefugter Zugriff auf Zone-ID $id");
        http_response_code(403);
        exit('Zugriff verweigert.');
    }
}

// Zone laden
$stmt = $pdo->prepare("SELECT name, type FROM zones WHERE id = ?");
$stmt->execute([$id]);
$zone = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$zone) {
    toastError(
        "Zone nicht gefunden.",
        "Zone-ID {$id} konnte nicht geladen werden."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
    exit;
}

$name = $zone['name'];
$type = $zone['type'];

// Eingaben
$ttl           = (int)($_POST['ttl'] ?? 3600);
$prefix_length = isset($_POST['prefix_length']) ? (int)$_POST['prefix_length'] : null;
$description   = trim($_POST['description'] ?? '') ?: null;

$soa_mail    = rtrim(trim($_POST['soa_mail'] ?? ''), '.') . '.';
$soa_refresh = (int)($_POST['soa_refresh'] ?? 3600);
$soa_retry   = (int)($_POST['soa_retry'] ?? 1800);
$soa_expire  = (int)($_POST['soa_expire'] ?? 1209600);
$soa_minimum = (int)($_POST['soa_minimum'] ?? 86400);

// Reverse-Zonen: Prefix prüfen
if ($type === 'reverse' && ($prefix_length < 8 || $prefix_length > 128)) {
    toastError(
        "Ungültiger Prefix-Length.",
        "Zone-ID {$id} hat ungültige Prefix-Länge: {$prefix_length}"
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
    exit;
}

$server_ids = $_POST['server_ids'] ?? [];
$master_id  = $_POST['master_server_id'] ?? null;

if ($_SESSION['role'] === 'admin') {
    // Validierung der Serverwahl
    if (!is_array($server_ids) || count($server_ids) < 1) {
        toastError(
            "Mindestens ein DNS-Server muss gewählt sein.",
            "Zone-ID {$id}: keine DNS-Server ausgewählt."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
        exit;
    }

    if (!$master_id || !in_array($master_id, $server_ids, true)) {
        toastError(
            "Der Master-Server muss unter den gewählten Servern sein.",
            "Zone-ID {$id}: Master-ID {$master_id} nicht in Serverliste."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
        exit;
    }

    try {
        // Alte Server-Zuweisung sichern
        $stmt = $pdo->prepare("SELECT server_id FROM zone_servers WHERE zone_id = ?");
        $stmt->execute([$id]);
        $old_server_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'server_id');

        // Server-Zuweisungen aktualisieren
        $pdo->prepare("DELETE FROM zone_servers WHERE zone_id = ?")->execute([$id]);
        $stmt = $pdo->prepare("INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (?, ?, ?)");

        foreach ($server_ids as $sid) {
            $stmt->execute([$id, $sid, ((int)$sid === (int)$master_id) ? 1 : 0]);
        }

        // Gelöschte Server identifizieren
        $removed_servers = array_diff($old_server_ids, $server_ids);
        if (!empty($removed_servers)) {
            $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
        }

        // Zonenstruktur auf Basis der neuen Server rekonstruieren
        $result = rebuild_ns_and_glue_for_zone_and_flag_if_valid($pdo, $id);

        if ($result['status'] === 'error') {
            toastError(
                "Zonenstruktur konnte nicht neu aufgebaut werden.",
                $result['output']
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
            exit;
        }

        if ($result['status'] === 'warning') {
            toastWarning(
                "Zone wurde aktualisiert – Warnung beim Zonen-Rebuild.",
                $result['output']
            );
        }

        // SOA-NS übernehmen (für UPDATE unten)
        $stmt = $pdo->prepare("SELECT soa_ns FROM zones WHERE id = ?");
        $stmt->execute([$id]);
        $soa_ns = $stmt->fetchColumn();
    } catch (Exception $e) {
        toastError(
            "Fehler beim Aktualisieren der Server-Zuweisungen.",
            "Fehler beim Aktualisieren von Zone-ID {$id}: " . $e->getMessage()
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
        exit;
    }
} else {
    // Kein Admin → SOA-NS übernehmen
    $soa_ns = trim($_POST['soa_ns'] ?? '');
}

// Zonen-Metadaten aktualisieren mit Transaktion
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        UPDATE zones SET
            ttl = ?, prefix_length = ?, description = ?,
            soa_ns = ?, soa_mail = ?,
            soa_refresh = ?, soa_retry = ?, soa_expire = ?, soa_minimum = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $ttl,
        $type === 'reverse' ? $prefix_length : null,
        $description,
        $soa_ns,
        $soa_mail,
        $soa_refresh,
        $soa_retry,
        $soa_expire,
        $soa_minimum,
        $id
    ]);

    $rebuild = rebuild_zone_and_flag_if_valid($id);

    if ($rebuild['status'] === 'error') {
        $pdo->rollBack();
        toastError(
            "Zone konnte nicht gespeichert werden, da die Zonendatei ungültig wäre.",
            $rebuild['output']
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
        exit;
    }

    if ($rebuild['status'] === 'warning') {
        toastWarning(
            "Zone gespeichert – Warnung beim Zonendatei-Check.",
            $rebuild['output']
        );
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    toastError(
        "Fehler beim Speichern der Zone.",
        "Zonen-Metadaten konnten für Zone '{$name}' (ID {$id}) nicht gespeichert werden: " . $e->getMessage()
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
    exit;
}

if (empty($_SESSION['toast_errors'])) {
    toastSuccess(
        "Zone <strong>" . htmlspecialchars($name) . "</strong> erfolgreich aktualisiert.",
        "Zone '{$name}' (ID {$id}) erfolgreich geändert."
    );
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
exit;
