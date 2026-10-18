<?php
/**
 * Datei: actions/dyndns_delete.php
 * Zweck: DynDNS-Account löschen
 *
 * Beschreibung:
 * Löscht einen bestehenden DynDNS-Account aus der Datenbank.
 * Zusätzlich werden die dem Account zuordenbaren A- und AAAA-Records des Hostnamens entfernt.
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt
 * - CSRF-Schutz aktiviert
 *
 * Verhalten:
 * - Die Zone des Accounts wird als erste Abfrage der Transaktion gesperrt (dyndnsLockZones);
 *   der Account wird danach unter Sperre neu geladen und auf zwischenzeitliche Änderungen geprüft.
 * - Systemgenerierte Records (NS/Glue, server_id IS NOT NULL) werden nie gelöscht.
 * - Verwenden weitere DynDNS-Accounts denselben DNS-Namen (Altbestand), bleiben die gemeinsamen
 *   A/AAAA-Records erhalten; nur der Account wird gelöscht (Warnhinweis, kein Rebuild).
 * - Sonst werden pro Typ nur Records gelöscht, die eindeutig zuordenbar sind (genau ein Record,
 *   Inhalt = zuletzt gemeldete IP, siehe dyndnsClassifyAccountRecords). Andere bleiben erhalten
 *   (Warnhinweis). Ohne Record-Ownership ist das eine Heuristik, kein Besitznachweis.
 * - Wurden Records gelöscht, wird die Zone innerhalb derselben Transaktion validiert und für das
 *   Publizieren markiert (changed=1). Schlägt die Validierung fehl, wird alles zurückgerollt.
 * - Es wird nicht sofort publiziert (Veröffentlichung später über „Alle publizieren“)
 * - Redirect zurück zur Verwaltungsseite `dyndns.php`
 */

declare(strict_types=1);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/dyndns_guard.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    toastError($LANG['dyndns_error_invalid_id']);
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
    exit;
}

// Zone des Accounts vorab ermitteln (nur zur Bestimmung der Sperre; wird unter Sperre erneut geprüft)
$stmt = $pdo->prepare("SELECT zone_id FROM dyndns_accounts WHERE id = ?");
$stmt->execute([$id]);
$preZoneId = $stmt->fetchColumn();

if ($preZoneId === false) {
    toastError($LANG['dyndns_error_not_found'], "DynDNS-Löschung: Account-ID {$id} nicht gefunden.");
    header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
    exit;
}
$preZoneId = (int)$preZoneId;

$warnings = [];

try {
    $pdo->beginTransaction();

    // 1. Zone sperren (erste Abfrage der Transaktion)
    $zones = dyndnsLockZones($pdo, [$preZoneId]);

    // 2. Account unter Sperre neu laden
    $stmt = $pdo->prepare("
        SELECT id, username, zone_id, hostname, current_ipv4, current_ipv6
        FROM dyndns_accounts
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new DyndnsActionError($LANG['dyndns_error_not_found'], "DynDNS-Löschung: Account-ID {$id} nicht (mehr) vorhanden.");
    }

    if ((int)$account['zone_id'] !== $preZoneId || !isset($zones[$preZoneId])) {
        throw new DyndnsActionError(
            $LANG['dyndns_error_concurrent_change'],
            "DynDNS-Löschung: Account-ID {$id} wurde zwischenzeitlich verändert (Zone {$preZoneId} → {$account['zone_id']})."
        );
    }

    $zid      = $preZoneId;
    $zoneName = (string)$zones[$zid]['name'];
    $hostname = (string)$account['hostname'];

    // 3. Bestehende Doppelbelegung? Dann gemeinsame Records nicht anfassen.
    $sharedWith     = dyndnsFindAccountsSharingHostname($pdo, $zid, $zoneName, $hostname, $id);
    $deletedRecords = 0;
    $keptRecords    = 0;

    if (!empty($sharedWith)) {
        $warnings[] = [
            sprintf(
                $LANG['dyndns_shared_hostname_kept'],
                htmlspecialchars($hostname),
                htmlspecialchars(implode(', ', $sharedWith))
            ),
            "DynDNS-Löschung: Hostname '{$hostname}' (Zone-ID {$zid}) wird auch von " . implode(', ', $sharedWith)
                . " verwendet – A/AAAA-Records bleiben erhalten."
        ];
    } else {
        // 4. Nur eindeutig zuordenbare A/AAAA-Records löschen (nie Systemrecords)
        $plan = dyndnsClassifyAccountRecords(
            $pdo,
            $zid,
            $hostname,
            $account['current_ipv4'] !== null ? (string)$account['current_ipv4'] : null,
            $account['current_ipv6'] !== null ? (string)$account['current_ipv6'] : null
        );
        $deletedRecords = dyndnsDeleteRecords($pdo, $plan['delete']);
        $keptRecords    = $plan['kept'];

        if ($keptRecords > 0) {
            $warnings[] = [
                sprintf($LANG['dyndns_records_kept_ambiguous'], $keptRecords, htmlspecialchars($hostname)),
                "DynDNS-Löschung: {$keptRecords} A/AAAA-Record(s) unter '{$hostname}' (Zone-ID {$zid}) nicht eindeutig zuordenbar – erhalten."
            ];
        }
    }

    // 5. DynDNS-Account löschen
    $stmt = $pdo->prepare("DELETE FROM dyndns_accounts WHERE id = ?");
    $stmt->execute([$id]);

    // 6. Zone nur validieren/markieren, wenn tatsächlich Records gelöscht wurden.
    // Gleiche Transaktion (explizites PDO): bei Fehler wird alles zurückgerollt.
    if ($deletedRecords > 0) {
        $result = rebuild_zone_and_flag_if_valid($zid, $pdo);
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

    if (empty($sharedWith) && $keptRecords === 0) {
        toastSuccess($LANG['dyndns_delete_success']);
    } else {
        toastSuccess($LANG['dyndns_delete_success_account_only']);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof DyndnsActionError) {
        toastError($e->toastMessage, $e->logMessage);
    } elseif (dyndnsIsLockError($e)) {
        toastError($LANG['dyndns_error_locked'], "DynDNS-Löschung: Lock-Timeout/Deadlock: " . $e->getMessage());
    } else {
        toastError(
            $LANG['dyndns_error_delete'] . ' – ' . $LANG['dyndns_error_see_log'],
            "DynDNS-Löschung fehlgeschlagen: " . get_class($e) . ': ' . $e->getMessage()
        );
    }
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
