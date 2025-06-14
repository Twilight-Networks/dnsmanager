<?php
/**
 * Datei: zone_update.php
 * Zweck: Aktualisiert eine bestehende DNS-Zone (Metadaten und Serverzuweisung).
 *
 * Funktionen:
 * - Validiert Benutzerrechte (admin oder zugewiesener zoneadmin).
 * - Erkennt gezielt, ob überhaupt relevante Änderungen vorgenommen wurden.
 * - Unterscheidet zwischen:
 *      - Zonen-relevanten Änderungen (z. B. TTL, SOA → führt zu Rebuild)
 *      - nicht kritischen Änderungen (Beschreibung, DynDNS → nur Speichern)
 *      - Änderungen an DNS-Servern (→ führt zu NS/Glue-Rebuild)
 * - Verhindert unnötige Verarbeitung bei unveränderten Daten.
 * - Nutzt gezielte Rebuilds:
 *      - rebuild_ns_and_glue_for_zone_and_flag_if_valid()
 *      - rebuild_zone_and_flag_if_valid()
 * - Gibt Rückmeldung über Toasts und leitet zurück zur Zonenübersicht.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/validators.php';

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

// Vorherige Zonenwerte laden für Vergleich
$stmt = $pdo->prepare("
    SELECT ttl, prefix_length, description, allow_dyndns, soa_ns, soa_mail,
           soa_refresh, soa_retry, soa_expire, soa_minimum
    FROM zones
    WHERE id = ?
");
$stmt->execute([$id]);
$old = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$zone) {
    toastError(
        $LANG['zone_error_not_found'],
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
$allow_dyndns = isset($_POST['allow_dyndns']) ? 1 : 0;

$soa_mail    = rtrim(trim($_POST['soa_mail'] ?? ''), '.') . '.';
$soa_refresh = (int)($_POST['soa_refresh'] ?? 3600);
$soa_retry   = (int)($_POST['soa_retry'] ?? 1800);
$soa_expire  = (int)($_POST['soa_expire'] ?? 1209600);
$soa_minimum = (int)($_POST['soa_minimum'] ?? 86400);

// Eingabevalidierung
$errors = validateZoneInput($_POST, false);
if (!empty($errors)) {
    foreach ($errors as $error) {
        toastError(
            $LANG[$error] ?? $LANG['generic_validation_error'],
            "Zoneneingabe ungültig (ID {$id}): {$error}"
        );
    }
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
    exit;
}

// Reverse-Zonen: Prefix prüfen
if ($type === 'reverse' && ($prefix_length < 8 || $prefix_length > 128)) {
    toastError(
        $LANG['zone_error_invalid_prefix_length'],
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
            $LANG['zone_error_no_servers'],
            "Zone-ID {$id}: keine DNS-Server ausgewählt."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
        exit;
    }

    if (!$master_id || !in_array($master_id, $server_ids, true)) {
        toastError(
            $LANG['zone_error_master_not_in_list'],
            "Zone-ID {$id}: Master-ID {$master_id} nicht in Serverliste."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
        exit;
    }

    // Aktive Server-IDs laden
    $stmt = $pdo->query("SELECT id FROM servers WHERE active = 1");
    $active_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Prüfen: Master muss aktiv sein
    if (!in_array((int)$master_id, $active_ids, true)) {
        toastError(
            $LANG['zone_error_master_inactive'],
            "Zone-ID {$id}: Der gewählte Master-Server ({$master_id}) ist nicht aktiv."
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
        exit;
    }

    // Alte Server-Zuweisung laden: ID + Master-Flag
    $stmt = $pdo->prepare("SELECT server_id, is_master FROM zone_servers WHERE zone_id = ?");
    $stmt->execute([$id]);
    $old_servers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Alte Serverzuweisung als [server_id => is_master] geladen
    $old_server_ids = array_keys($old_servers);
    $old_master_id = array_search(1, $old_servers, true); // erster mit is_master = 1

    $new_master_id = (int)$master_id;

    /**
     * Ergänze inaktive alte Slave-Server für Vergleich und Speicherung,
     * da sie durch das Formular (<input disabled>) nicht übermittelt werden.
     * Nur gültig für Slaves – ein inaktiver Master wäre bereits weiter oben geblockt.
     */
    $preserve_inactive_slaves = [];
    foreach ($old_servers as $sid => $is_master) {
        if (!in_array($sid, $active_ids, true) && (int)$is_master !== 1) {
            $preserve_inactive_slaves[] = (int)$sid;
        }
    }

    // Neue Serverliste inkl. inaktiver Slaves für Vergleich und späteren Insert
    $effective_new_server_ids = array_unique(array_merge(array_map('intval', $server_ids), $preserve_inactive_slaves));
    sort($effective_new_server_ids);
    sort($old_server_ids);

    // Vergleich alt ↔︎ neu
    $serverListChanged = $effective_new_server_ids !== $old_server_ids;
    $masterChanged = (int)$old_master_id !== $new_master_id;

    // Wenn keine Änderungen bei der Serverzuweisung → keine Aktion, SOA-NS unverändert übernehmen
    if (!$serverListChanged && !$masterChanged) {
        // keine Änderung – skippe RebuildNS + Serverupdate
        $soa_ns = $zone['type'] === 'forward' ? $old['soa_ns'] : trim($_POST['soa_ns'] ?? '');
    } else {
        // Server-Zuweisung hat sich geändert → neu schreiben + rebuild
        try {
            // Alte Zuweisungen löschen
            $pdo->prepare("DELETE FROM zone_servers WHERE zone_id = ?")->execute([$id]);

            // Neue setzen
            $stmt = $pdo->prepare("INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (?, ?, ?)");
            foreach ($effective_new_server_ids as $sid) {
                $stmt->execute([$id, $sid, ((int)$sid === $new_master_id) ? 1 : 0]);
            }

            // Rebuild NS + Glue (nur wenn Änderung)
            $result = rebuild_ns_and_glue_for_zone_and_flag_if_valid($pdo, $id);

            if ($result['status'] === 'error') {
                toastError(
                    $LANG['zone_error_ns_glue_failed'],
                    $result['output']
                );
                header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
                exit;
            }

            if ($result['status'] === 'warning') {
                toastWarning(
                    $LANG['zone_warning_ns_glue'],
                    $result['output']
                );
            }

            // neuen SOA-NS setzen (nach Rebuild)
            $stmt = $pdo->prepare("SELECT soa_ns FROM zones WHERE id = ?");
            $stmt->execute([$id]);
            $soa_ns = $stmt->fetchColumn();
        } catch (Exception $e) {
            toastError(
                $LANG['zone_error_server_assignment_failed'],
                "Fehler beim Aktualisieren von Zone-ID {$id}: " . $e->getMessage()
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
            exit;
        }
    }
}

if (!$old) {
    toastError(
        $LANG['zone_error_check_failed'],
        "Zone-ID {$id} existiert nicht mehr."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
    exit;
}

// --- Änderungserkennung: Aufteilung in kritisch (führt zu Rebuild) und unkritisch (nur speichern) ---

// Rebuild der Zonendatei nur bei relevanten Änderungen (TTL, SOA, etc.)
$hasZoneRelevantChanges =
    (int)$old['ttl'] !== $ttl ||
    ($type === 'reverse' && (int)$old['prefix_length'] !== $prefix_length) ||
    rtrim($old['soa_ns'], '.') !== rtrim($soa_ns, '.') ||
    rtrim($old['soa_mail'], '.') !== rtrim($soa_mail, '.') ||
    (int)$old['soa_refresh'] !== $soa_refresh ||
    (int)$old['soa_retry'] !== $soa_retry ||
    (int)$old['soa_expire'] !== $soa_expire ||
    (int)$old['soa_minimum'] !== $soa_minimum;

// Nur fürs Speichern relevant (kein Trigger für Rebuild)
$hasNonCriticalChanges =
    trim((string)($old['description'] ?? '')) !== trim((string)($description ?? '')) ||
    (int)$old['allow_dyndns'] !== $allow_dyndns;

// Wenn keine Änderungen → keine Aktion
if (!$hasZoneRelevantChanges && !$serverListChanged && !$masterChanged && !$hasNonCriticalChanges) {
    toastSuccess($LANG['no_changes'], "Die Zonendaten sind unverändert.");
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
    exit;
}

// --- Nur wenn Änderungen vorhanden: Metadaten-Update + Rebuild ---

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        UPDATE zones SET
            ttl = ?, prefix_length = ?, description = ?,
            allow_dyndns = ?,
            soa_ns = ?, soa_mail = ?,
            soa_refresh = ?, soa_retry = ?, soa_expire = ?, soa_minimum = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $ttl,
        $type === 'reverse' ? $prefix_length : null,
        $description,
        $allow_dyndns,
        $soa_ns,
        $soa_mail,
        $soa_refresh,
        $soa_retry,
        $soa_expire,
        $soa_minimum,
        $id
    ]);

    if ($hasZoneRelevantChanges) {
        $rebuild = rebuild_zone_and_flag_if_valid($id);

        if ($rebuild['status'] === 'error') {
            $pdo->rollBack();
            toastError(
                $LANG['zone_error_zonefile_invalid'],
                $rebuild['output']
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
            exit;
        }

        if ($rebuild['status'] === 'warning') {
            toastWarning(
                $LANG['zone_warning_zonefile_check'],
                $rebuild['output']
            );
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    toastError(
        $LANG['zone_error_db_save_failed'],
        "Zonen-Metadaten konnten für Zone '{$name}' (ID {$id}) nicht gespeichert werden: " . $e->getMessage()
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?edit_id=$id");
    exit;
}

if (empty($_SESSION['toast_errors'])) {
    toastSuccess(
        sprintf($LANG['zone_updated'], htmlspecialchars($name)),
        "Zone '{$name}' (ID {$id}) erfolgreich geändert."
    );
}

header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
exit;
