<?php
/**
 * Datei: actions/dyndns_update.php
 * Zweck: Bestehenden DynDNS-Account aktualisieren
 *
 * Beschreibung:
 * Aktualisiert den Benutzernamen, die zugeordnete Zone und den Hostnamen (Subdomain)
 * eines vorhandenen DynDNS-Accounts. Wird aus dem Inline-Edit-Formular in `dyndns.php` aufgerufen.
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt
 * - CSRF-Schutz aktiv
 * - Hostname-Validierung und Zonenprüfung (allow_dyndns, Forward-Zone) nur bei neuer Zuordnung,
 *   d. h. wenn sich Hostname oder Zone ändern. Reine Benutzername-/Passwortänderungen scheitern
 *   nicht an Alt-Hostnamen oder an einer inzwischen deaktivierten Zone.
 * - Kollisionsprüfung unter Zonen-Zeilensperre (alte und neue Zone, aufsteigende ID-Reihenfolge)
 * - Account wird nach Erwerb der Sperren neu geladen (FOR UPDATE); zwischenzeitliche Änderungen
 *   an Zone/Hostname führen zum Abbruch, aktuelle IPs stammen aus dem gesperrten Datensatz.
 *
 * Record-Behandlung bei Wechsel des DNS-Namens:
 * - Systemrecords (server_id IS NOT NULL) werden nie gelöscht.
 * - Verwenden weitere Accounts den alten DNS-Namen (Altbestand), bleiben die Records dort erhalten.
 * - Sonst werden am alten Namen nur eindeutig zuordenbare Records gelöscht
 *   (dyndnsClassifyAccountRecords); andere bleiben erhalten (Warnhinweis).
 * - Am neuen Namen werden A/AAAA-Records aus den zuletzt gemeldeten IPs angelegt.
 * - Betroffene Zonen werden in derselben Transaktion validiert und markiert (PR #35);
 *   jeder Fehler führt zum vollständigen Rollback.
 *
 * Verhalten bei Fehlern:
 * - Verständliche Fehlermeldung per Toast, technische Details nur im Log
 */

declare(strict_types=1);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/dyndns_guard.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$id       = (int)($_POST['id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
// Hostname bewusst nicht trimmen: Leer- und Steuerzeichen werden von der Validierung abgelehnt
$hostname = is_string($_POST['hostname'] ?? null) ? $_POST['hostname'] : '';
$zone_id  = (int)($_POST['zone_id'] ?? 0);

$redirectEdit = rtrim(BASE_URL, '/') . '/pages/dyndns.php?edit_id=' . $id;
$redirectList = rtrim(BASE_URL, '/') . '/pages/dyndns.php';

if ($id <= 0 || $hostname === '' || $zone_id <= 0 || $username === '') {
    toastError($LANG['dyndns_error_invalid_input']);
    header("Location: " . $redirectEdit);
    exit;
}

// Bestehende Werte vorläufig laden (ohne Sperre): nur zur Bestimmung der zu sperrenden Zonen
// und der Änderungsart. Unter Sperre wird der Account erneut geladen und verglichen.
$stmt = $pdo->prepare("SELECT zone_id, hostname, username FROM dyndns_accounts WHERE id = ?");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    toastError($LANG['dyndns_error_not_found']);
    header("Location: " . $redirectList);
    exit;
}

$oldZoneId = (int)$existing['zone_id'];

// Vergleich: Nur dann speichern, wenn sich wirklich etwas geändert hat
$usernameChanged = trim((string)$existing['username']) !== $username;
$hostnameChanged = (string)$existing['hostname'] !== $hostname;
$zoneChanged     = $oldZoneId !== $zone_id;
$passwordChanged = $password !== '';
$nameChanged     = $hostnameChanged || $zoneChanged; // neue Zuordnung → Validierung/Kollisionsprüfung

if (!$hostnameChanged && !$zoneChanged && !$usernameChanged && !$passwordChanged) {
    toastSuccess($LANG['no_changes'], 'Der DynDNS-Account ist unverändert.');
    header("Location: " . $redirectList);
    exit;
}

$warnings       = [];
$zonesToRebuild = [];

try {
    $pdo->beginTransaction();

    // 1. Alte und neue Zone sperren (erste Abfrage der Transaktion, aufsteigende ID-Reihenfolge)
    $zones = dyndnsLockZones($pdo, [$oldZoneId, $zone_id]);

    // 2. Account unter Sperre neu laden und auf zwischenzeitliche Änderungen prüfen
    $stmt = $pdo->prepare("
        SELECT id, zone_id, hostname, username, current_ipv4, current_ipv6
        FROM dyndns_accounts
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new DyndnsActionError($LANG['dyndns_error_not_found'], "DynDNS-Update: Account-ID {$id} nicht (mehr) vorhanden.");
    }

    if (
        (int)$account['zone_id'] !== $oldZoneId
        || (string)$account['hostname'] !== (string)$existing['hostname']
        || !isset($zones[$oldZoneId])
    ) {
        throw new DyndnsActionError(
            $LANG['dyndns_error_concurrent_change'],
            "DynDNS-Update: Account-ID {$id} wurde zwischenzeitlich verändert."
        );
    }

    $oldHostname  = (string)$account['hostname'];
    $oldZoneName  = (string)$zones[$oldZoneId]['name'];
    $current_ipv4 = $account['current_ipv4'] !== null ? (string)$account['current_ipv4'] : null;
    $current_ipv6 = $account['current_ipv6'] !== null ? (string)$account['current_ipv6'] : null;

    $newHostname = $oldHostname; // reine Benutzername-/Passwortänderung: Alt-Hostname unverändert lassen
    $newZoneId   = $oldZoneId;
    $sameDnsName = true;

    // 3. Neue Zuordnung prüfen (nur bei Änderung von Hostname oder Zone)
    if ($nameChanged) {
        $targetZone = $zones[$zone_id] ?? null;

        if ($targetZone === null) {
            throw new DyndnsActionError($LANG['dyndns_error_zone_not_allowed'], "DynDNS-Update: Ziel-Zone-ID {$zone_id} nicht gefunden.");
        }

        $zoneError = dyndnsZoneAssignmentError($targetZone);
        if ($zoneError !== null) {
            throw new DyndnsActionError(
                $LANG[dyndnsLangKey($zoneError)],
                "DynDNS-Update abgelehnt: {$zoneError} (Account-ID {$id}, Ziel-Zone-ID {$zone_id})."
            );
        }

        $hostErrors = validateDyndnsHostname($hostname, (string)$targetZone['name']);
        if (!empty($hostErrors)) {
            throw new DyndnsActionError(
                sprintf($LANG[dyndnsLangKey($hostErrors[0])], htmlspecialchars($hostname)),
                "DynDNS-Update abgelehnt: {$hostErrors[0]} (Hostname " . json_encode($hostname, JSON_INVALID_UTF8_SUBSTITUTE) . ", Account-ID {$id})."
            );
        }

        $newHostname = normalizeDyndnsHostname($hostname);
        $newZoneId   = $zone_id;

        // Gleicher DNS-Name (z. B. nur Groß-/Kleinschreibung geändert): keine Record-Änderung nötig
        $sameDnsName = !$zoneChanged && dyndnsCanonicalName($oldHostname, $oldZoneName) === $newHostname;

        if (!$sameDnsName) {
            $conflicts = dyndnsFindHostnameConflicts($pdo, $newZoneId, (string)$targetZone['name'], $newHostname, $id);
            if (!empty($conflicts)) {
                $first = $conflicts[0];
                throw new DyndnsActionError(
                    sprintf($LANG[dyndnsLangKey($first['code'])], htmlspecialchars($newHostname)),
                    "DynDNS-Update abgelehnt: Konflikte für '{$newHostname}' in Zone-ID {$newZoneId} (Account-ID {$id}): "
                        . implode('; ', array_map(fn($c) => $c['code'] . ' (' . $c['detail'] . ')', $conflicts))
                );
            }
        }
    }

    // 4. Account aktualisieren
    if ($passwordChanged) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            UPDATE dyndns_accounts
            SET password_hash = ?, hostname = ?, zone_id = ?, username = ?
            WHERE id = ?
        ");
        $stmt->execute([$hash, $newHostname, $newZoneId, $username, $id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE dyndns_accounts
            SET hostname = ?, zone_id = ?, username = ?
            WHERE id = ?
        ");
        $stmt->execute([$newHostname, $newZoneId, $username, $id]);
    }

    // 5. DNS-Records anpassen, wenn sich der DNS-Name tatsächlich ändert
    if ($nameChanged && !$sameDnsName) {
        // 5a. Alter Name: gemeinsame Records bei Doppelbelegung erhalten, sonst nur eindeutig zuordenbare löschen
        $sharedWith = dyndnsFindAccountsSharingHostname($pdo, $oldZoneId, $oldZoneName, $oldHostname, $id);

        if (!empty($sharedWith)) {
            $warnings[] = [
                sprintf(
                    $LANG['dyndns_shared_hostname_kept'],
                    htmlspecialchars($oldHostname),
                    htmlspecialchars(implode(', ', $sharedWith))
                ),
                "DynDNS-Update: alter Hostname '{$oldHostname}' (Zone-ID {$oldZoneId}) wird auch von "
                    . implode(', ', $sharedWith) . " verwendet – A/AAAA-Records bleiben erhalten."
            ];
        } else {
            $plan = dyndnsClassifyAccountRecords($pdo, $oldZoneId, $oldHostname, $current_ipv4, $current_ipv6);
            $deletedOldRecords = dyndnsDeleteRecords($pdo, $plan['delete']);

            if ($plan['kept'] > 0) {
                $warnings[] = [
                    sprintf($LANG['dyndns_records_kept_ambiguous'], $plan['kept'], htmlspecialchars($oldHostname)),
                    "DynDNS-Update: {$plan['kept']} A/AAAA-Record(s) unter '{$oldHostname}' (Zone-ID {$oldZoneId}) nicht eindeutig zuordenbar – erhalten."
                ];
            }

            // Alte Zone nur markieren, wenn dort tatsächlich Records gelöscht wurden
            if ($deletedOldRecords > 0) {
                $zonesToRebuild[] = $oldZoneId;
            }
        }

        // 5b. Neue A/AAAA-Records in neuer Zone anlegen – mit den zuletzt gemeldeten IPs
        $hasInsertedAny = false;

        if (!empty($current_ipv4)) {
            $stmt = $pdo->prepare("
                INSERT INTO records (zone_id, name, type, content, ttl)
                VALUES (?, ?, 'A', ?, 300)
            ");
            $stmt->execute([$newZoneId, $newHostname, $current_ipv4]);
            $hasInsertedAny = true;
        }

        if (!empty($current_ipv6)) {
            $stmt = $pdo->prepare("
                INSERT INTO records (zone_id, name, type, content, ttl)
                VALUES (?, ?, 'AAAA', ?, 300)
            ");
            $stmt->execute([$newZoneId, $newHostname, $current_ipv6]);
            $hasInsertedAny = true;
        }

        if (!$hasInsertedAny) {
            $warnings[] = [$LANG['dyndns_no_ip_warning'], 'Es wurden keine A-/AAAA-Records gesetzt, da keine aktuelle IP vorliegt.'];
        }

        // Neue Zone nur markieren, wenn dort tatsächlich Records angelegt wurden
        if ($hasInsertedAny) {
            $zonesToRebuild[] = $newZoneId;
        }
    }

    // 6. Betroffene Zonen (je einmal) in derselben Transaktion validieren und markieren
    $zonesToRebuild = array_values(array_unique($zonesToRebuild));
    foreach ($zonesToRebuild as $zid) {
        $result = rebuild_zone_and_flag_if_valid((int)$zid, $pdo);
        if ($result['status'] === 'error') {
            throw new DyndnsActionError(sprintf($LANG['zone_rebuild_failed'], $zid), $result['output']);
        } elseif ($result['status'] === 'warning') {
            $warnings[] = [sprintf($LANG['zone_rebuild_warning'], $zid), $result['output']];
        }
    }

    $pdo->commit();

    foreach ($warnings as [$toast, $log]) {
        toastWarning($toast, $log);
    }

    if (!empty($zonesToRebuild)) {
        toastSuccess($LANG['dyndns_updated'], 'Änderungen gespeichert und betroffene Zonen rebuildet.');
    } else {
        toastSuccess($LANG['dyndns_updated'], 'Änderungen gespeichert – kein Zonen-Rebuild notwendig.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof DyndnsActionError) {
        toastError($e->toastMessage, $e->logMessage);
        header("Location: " . $redirectEdit);
        exit;
    }

    if (dyndnsIsDuplicateKeyError($e)) {
        toastError(
            sprintf($LANG['dyndns_error_username_exists'], htmlspecialchars($username)),
            "DynDNS-Update abgelehnt: Benutzername bereits vergeben: " . $e->getMessage()
        );
        header("Location: " . $redirectEdit);
        exit;
    }

    if (dyndnsIsLockError($e)) {
        toastError($LANG['dyndns_error_locked'], "DynDNS-Update: Lock-Timeout/Deadlock: " . $e->getMessage());
        header("Location: " . $redirectEdit);
        exit;
    }

    toastError(
        $LANG['dyndns_error_update'] . ' – ' . $LANG['dyndns_error_see_log'],
        "DynDNS-Update fehlgeschlagen: " . get_class($e) . ': ' . $e->getMessage()
    );
}

header("Location: " . $redirectList);
exit;
