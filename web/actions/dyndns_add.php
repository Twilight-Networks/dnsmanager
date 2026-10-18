<?php
/**
 * Datei: dyndns_add.php
 * Zweck: DynDNS-Account hinzufügen
 *
 * Beschreibung:
 * Verarbeitet das Formular aus `dyndns_add_form.php` und legt einen neuen DynDNS-Account
 * mit zugehöriger Zone, Subdomain (Hostname) und initialem Passwort in der Datenbank an.
 *
 * Sicherheitsvorgaben:
 * - Nur für Admins erlaubt (Rollenprüfung)
 * - CSRF-Schutz über `verify_csrf_token()`
 * - Passwort-Hashing mit `password_hash()`
 * - Eindeutiger Benutzername (UNIQUE in der Datenbank)
 * - Hostname-Validierung (validateDyndnsHostname) und Normalisierung auf Kleinbuchstaben
 * - Zone muss DynDNS erlauben und eine Forward-Zone sein
 * - Kollisionsprüfung (dyndnsFindHostnameConflicts) unter Zonen-Zeilensperre in derselben
 *   Transaktion wie der INSERT (dyndnsLockZones ist die erste Abfrage der Transaktion)
 *
 * Verhalten bei Erfolg:
 * - DynDNS-Account wird erstellt (A/AAAA-Records entstehen erst beim ersten DynDNS-Update)
 * - Erfolgsmeldung per Toast
 *
 * Verhalten bei Fehlern:
 * - Verständliche Fehlermeldung per Toast, technische Details nur im Log
 * - Redirect zurück zum Anlageformular
 */

declare(strict_types=1);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/dyndns_guard.php';
verify_csrf_token();

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
// Hostname bewusst nicht trimmen: Leer- und Steuerzeichen werden von der Validierung abgelehnt
$hostname = is_string($_POST['hostname'] ?? null) ? $_POST['hostname'] : '';
$zone_id  = (int)($_POST['zone_id'] ?? 0);

$redirectAddForm = rtrim(BASE_URL, '/') . '/pages/dyndns.php?add_new=1';

if ($username === '' || $password === '' || $hostname === '' || $zone_id <= 0) {
    toastError($LANG['dyndns_error_missing_fields']);
    header("Location: " . $redirectAddForm);
    exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo->beginTransaction();

    // 1. Zone sperren (erste Abfrage der Transaktion, serialisiert mit DynDNS-API und Publish)
    $zones = dyndnsLockZones($pdo, [$zone_id]);
    $zone  = $zones[$zone_id] ?? null;

    if ($zone === null) {
        throw new DyndnsActionError($LANG['dyndns_error_zone_not_allowed'], "DynDNS-Anlage: Zone-ID {$zone_id} nicht gefunden.");
    }

    $zoneError = dyndnsZoneAssignmentError($zone);
    if ($zoneError !== null) {
        throw new DyndnsActionError(
            $LANG[dyndnsLangKey($zoneError)],
            "DynDNS-Anlage abgelehnt: {$zoneError} (Zone-ID {$zone_id})."
        );
    }

    // 2. Hostname validieren und normalisieren
    $hostErrors = validateDyndnsHostname($hostname, (string)$zone['name']);
    if (!empty($hostErrors)) {
        throw new DyndnsActionError(
            sprintf($LANG[dyndnsLangKey($hostErrors[0])], htmlspecialchars($hostname)),
            "DynDNS-Anlage abgelehnt: {$hostErrors[0]} (Hostname " . json_encode($hostname, JSON_INVALID_UTF8_SUBSTITUTE) . ", Zone-ID {$zone_id})."
        );
    }
    $hostname = normalizeDyndnsHostname($hostname);

    // 3. Kollisionsprüfung unter Zonensperre (autoritativ)
    $conflicts = dyndnsFindHostnameConflicts($pdo, $zone_id, (string)$zone['name'], $hostname, null);
    if (!empty($conflicts)) {
        $first = $conflicts[0];
        throw new DyndnsActionError(
            sprintf($LANG[dyndnsLangKey($first['code'])], htmlspecialchars($hostname)),
            "DynDNS-Anlage abgelehnt: Konflikte für '{$hostname}' in Zone-ID {$zone_id}: "
                . implode('; ', array_map(fn($c) => $c['code'] . ' (' . $c['detail'] . ')', $conflicts))
        );
    }

    // 4. Account anlegen
    $stmt = $pdo->prepare("
        INSERT INTO dyndns_accounts (username, password_hash, hostname, zone_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$username, $hash, $hostname, $zone_id]);

    $pdo->commit();
    toastSuccess($LANG['dyndns_success_account_created']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof DyndnsActionError) {
        toastError($e->toastMessage, $e->logMessage);
    } elseif (dyndnsIsDuplicateKeyError($e)) {
        toastError(
            sprintf($LANG['dyndns_error_username_exists'], htmlspecialchars($username)),
            "DynDNS-Anlage abgelehnt: Benutzername bereits vergeben: " . $e->getMessage()
        );
    } elseif (dyndnsIsLockError($e)) {
        toastError($LANG['dyndns_error_locked'], "DynDNS-Anlage: Lock-Timeout/Deadlock: " . $e->getMessage());
    } else {
        toastError(
            $LANG['dyndns_error_db'] . ' – ' . $LANG['dyndns_error_see_log'],
            "DynDNS-Anlage fehlgeschlagen: " . get_class($e) . ': ' . $e->getMessage()
        );
    }

    header("Location: " . $redirectAddForm);
    exit;
}

header("Location: " . rtrim(BASE_URL, '/') . '/pages/dyndns.php');
exit;
