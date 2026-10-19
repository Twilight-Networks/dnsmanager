<?php
/**
 * Datei: publish_all.php
 *
 * Zweck:
 * Web-Adapter für "Publish All". Die fachliche Veröffentlichungslogik (Advisory-Lock, Flag-Verwaltung
 * changed/bind_dirty, Zonengenerierung, Zone-/Conf-Verteilung, Orphan-Cleanup, Recovery) liegt im
 * PublishService (inc/app/publish/PublishService.php) und ist ohne Web-Bootstrap nutzbar.
 *
 * Dieser Adapter übernimmt ausschließlich:
 * - Web-Bootstrap (common.php), POST- und CSRF-Prüfung, requireRole (HTTP 403 + Access-Denied-Logging)
 * - Actor-Erstellung aus der Session, Backend- und Service-Initialisierung, Service-Aufruf
 * - Übersetzung des ServiceResult in die bestehenden Toasts und Logs
 * - sicheren Redirect über safe_return_path()
 *
 * Zugriffsschutz:
 * - Nur für Benutzer mit Rolle 'admin' oder 'zoneadmin' (zusätzlich im Service geprüft)
 * - Nur per POST mit gültigem CSRF-Token (Prüfung vor Lock-Bezug und Flag-Verarbeitung)
 * - Rücksprungziel nur über safe_return_path() (interne Seiten unter BASE_URL/pages/)
 *
 * Abhängigkeiten:
 * - inc/app/publish/PublishService.php
 * - inc/deploy/LegacyBindPublishBackend.php → generate_zone_file(), distribute_zone_file(),
 *   distribute_zone_conf_file(), distribute_zone_cleanup()
 */

require_once __DIR__ . '/../common.php';

// Zustandsändernde Aktion: nur POST mit gültigem CSRF-Token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
verify_csrf_token();

requireRole(['admin', 'zoneadmin']);
require_once __DIR__ . '/../inc/web/web_adapter.php';
require_once __DIR__ . '/../inc/app/publish/PublishService.php';
require_once __DIR__ . '/../inc/deploy/LegacyBindPublishBackend.php';

$publish_lock_name = PublishService::LOCK_NAME;
$result = null;

try {
    $publishService = new PublishService(
        $pdo,
        new LegacyBindPublishBackend($pdo),
        static function (string $level, string $message): void {
            appLog($level, $message);
        }
    );
    $serviceResult = $publishService->publishAll(actorFromSession());

    // Abschließende Ergebnismeldung (immer genau eine, letzte Meldung)
    foreach ($serviceResult->messages as $message) {
        if ($message->code === 'publish.result') {
            $result = $message->context;
        }
    }
} catch (Throwable $e) {
    // Initialisierungsfehler (z. B. fehlende Konfiguration): kein Lock bezogen, keine Flags verändert
    toastError(
        $LANG['publish_all_exception'],
        "Systemfehler beim Publish: " . $e->getMessage()
    );
}

if ($result !== null) {
    switch ($result['status']) {
        case 'denied':
            // Nicht erreichbar, solange requireRole() dieselben Rollen prüft; Verhalten wie requireRole()
            logAccessDenied('publish_all', 'Unzureichende Berechtigungen für Rolle: ' . ($_SESSION['role'] ?? ''));
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'locked':
            toastWarning(
                $LANG['publish_all_locked'] ?? 'Ein anderer Publish-Lauf ist bereits aktiv. Bitte später erneut versuchen.',
                "[publish] Publish All nicht gestartet: Lock '$publish_lock_name' nicht verfügbar (belegt oder Fehler)"
            );
            break;

        case 'lock_failed':
            $lockError = '';
            foreach ($serviceResult->messages as $message) {
                if ($message->code === 'publish.lock_failed') {
                    $lockError = (string)$message->context['detail'];
                }
            }
            toastError(
                $LANG['publish_all_exception'],
                "Publish-Lock konnte nicht bezogen werden: " . $lockError
            );
            break;

        case 'success':
            toastSuccess(
                $LANG['publish_all_success'],
                "Alle Zonendateien wurden erfolgreich verteilt und BIND wurde neu geladen."
            );
            break;

        case 'warning':
            foreach ($result['warnings'] as $w) {
                toastWarning(
                    $LANG['publish_all_warning'],
                    "Veröffentlichung abgeschlossen mit Warnung: $w"
                );
            }
            break;

        case 'error':
            // Bei Fehlern ausschließlich Fehler-Toasts (keine zusätzlichen Warn-Toasts)
            foreach ($result['errors'] as $e) {
                toastError(
                    $LANG['publish_all_error'],
                    "Veröffentlichung fehlgeschlagen: $e"
                );
            }
            break;

        case 'exception':
            toastError(
                $LANG['publish_all_exception'],
                "Systemfehler beim Publish: " . $result['exception']
            );
            break;

        default:
            // invalid_state und unbekannte Status: nie still verwerfen
            toastError(
                $LANG['publish_all_exception'],
                "Systemfehler beim Publish: unerwarteter Publish-Status '" . $result['status'] . "'"
            );
            break;
    }

    // Nicht bestätigte Lock-Freigabe (Reihenfolge wie bisher: nach den Ergebnis-Meldungen)
    if (($result['lock']['released'] ?? null) === false) {
        if ($result['lock']['releaseException'] ?? false) {
            appLog('warning', "[publish] Lock '$publish_lock_name' konnte nicht freigegeben werden (wird mit Verbindungsende frei): " . $result['lock']['releaseError']);
        } else {
            // RELEASE_LOCK lieferte 0 oder NULL (Format wie bei den Topologie-Aktionen)
            appLog('warning', "[publish] Freigabe von Lock '$publish_lock_name' nicht bestätigt (RELEASE_LOCK = " . var_export($result['lock']['releaseResult'], true) . "): " . $result['lock']['releaseError']);
        }
    }
}

// Zurück zur vorherigen Seite oder Dashboard
$ref = safe_return_path($_POST['return'] ?? null, rtrim(BASE_URL, '/') . '/pages/dashboard.php');
header("Location: $ref");
exit;
?>
