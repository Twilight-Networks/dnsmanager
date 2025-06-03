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
 * - Redirect zurück zur Verwaltungsseite `dyndns.php`
 */

declare(strict_types=1);
define('IN_APP', true);
require_once __DIR__ . '/../common.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    toastError('Ungültige ID.');
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

    // DynDNS-Account löschen
    $stmt = $pdo->prepare("DELETE FROM dyndns_accounts WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();

    toastSuccess('DynDNS-Account und zugehörige A/AAAA-Records gelöscht.');

} catch (Throwable $e) {
    $pdo->rollBack();
    toastError('Fehler beim Löschen: ' . $e->getMessage());
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
