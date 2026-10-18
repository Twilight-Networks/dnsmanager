<?php
/**
 * Datei: actions/dyndns_delete.php
 * Zweck: DynDNS-Account löschen
 *
 * Beschreibung:
 * Löscht einen bestehenden DynDNS-Account aus der Datenbank.
 * Zusätzlich werden zugehörige A- und AAAA-Records für den Hostnamen automatisch entfernt.
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt
 * - CSRF-Schutz aktiviert
 *
 * Verhalten:
 * - Bei erfolgreicher Löschung: Erfolgsmeldung per Toast
 * - Bei Fehlern: Fehler-Toast mit Exception-Details
 * - Wurden A-/AAAA-Records gelöscht, wird die Zone innerhalb derselben Transaktion
 *   validiert und für das Publizieren markiert (changed=1). Schlägt die Validierung
 *   fehl, wird alles (Records und Account) zurückgerollt.
 * - Es wird nicht sofort publiziert (Veröffentlichung später über „Alle publizieren“)
 * - Redirect zurück zur Verwaltungsseite `dyndns.php`
 */

declare(strict_types=1);
require_once __DIR__ . '/../common.php';
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

try {
    $pdo->beginTransaction();

    // Zone-ID und Hostname ermitteln
    $stmt = $pdo->prepare("SELECT zone_id, hostname FROM dyndns_accounts WHERE id = ?");
    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("DynDNS-Account nicht gefunden.");
    }

    // Zugehörige A/AAAA-Records löschen
    $stmt = $pdo->prepare("
        DELETE FROM records
        WHERE zone_id = ?
          AND name = ?
          AND type IN ('A', 'AAAA')
    ");
    $stmt->execute([$account['zone_id'], $account['hostname']]);
    $deletedRecords = $stmt->rowCount();

    // DynDNS-Account löschen
    $stmt = $pdo->prepare("DELETE FROM dyndns_accounts WHERE id = ?");
    $stmt->execute([$id]);

    // Zone nur validieren/markieren, wenn tatsächlich Records gelöscht wurden.
    // Gleiche Transaktion (explizites PDO): bei Fehler wird alles zurückgerollt.
    if ($deletedRecords > 0) {
        $zid    = (int)$account['zone_id'];
        $result = rebuild_zone_and_flag_if_valid($zid, $pdo);
        if ($result['status'] === 'error') {
            $pdo->rollBack();
            toastError(sprintf($LANG['zone_rebuild_failed'], $zid), $result['output']);
            header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
            exit;
        } elseif ($result['status'] === 'warning') {
            toastWarning(sprintf($LANG['zone_rebuild_warning'], $zid), $result['output']);
        }
    }

    $pdo->commit();

    toastSuccess($LANG['dyndns_delete_success']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    toastError($LANG['dyndns_error_delete'] . ': ' . $e->getMessage());
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
