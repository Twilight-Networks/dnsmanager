<?php
/**
 * Datei: zone_add.php
 * Zweck: Legt eine neue DNS-Zone im System an (Forward oder Reverse).
 * Details:
 * - Nur Admins dürfen neue Zonen anlegen (geschützt über requireRole(['admin'])).
 * - Unterstützt Forward- und Reverse-Zonen mit dynamischem SOA.
 * - Nach erfolgreichem Insert wird:
 *   - die Zone gespeichert
 *   - NS-Records für alle Server (inkl. Master) werden eingetragen
 *   - A-Records für Glue-Hosts (nur bei Forward-Zonen)
 *   - die Serverzuweisung (inkl. Master) gepflegt
 *   - die Zonendatei regeneriert
 *
 * Web-Adapter: Eingaben lesen, ZoneService::createZone() aufrufen, ServiceMessages in die bisherigen
 * Toasts/Logs/Redirects übersetzen. Validierung, Berechtigung (DB-Rolle), Topologie-Lock und
 * Transaktion liegen im Service.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/TopologyLock.php';
require_once __DIR__ . '/../inc/app/zones/ZoneService.php';
require_once __DIR__ . '/../inc/deploy/LegacyZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Rohwerte aus dem Formular (Normalisierung und Validierung im Service)
$input = new ZoneInput(
    $_POST['type'] ?? null,
    $_POST['zone_prefix'] ?? null,
    $_POST['prefix_length'] ?? null,
    $_POST['ttl'] ?? null,
    $_POST['description'] ?? null,
    $_POST['soa_mail'] ?? null,
    $_POST['soa_refresh'] ?? null,
    $_POST['soa_retry'] ?? null,
    $_POST['soa_expire'] ?? null,
    $_POST['soa_minimum'] ?? null,
    $_POST['soa_domain'] ?? null,
    isset($_POST['allow_dyndns']) && $_POST['allow_dyndns'] === '1',
    $_POST['server_ids'] ?? null,
    $_POST['master_server_id'] ?? null
);

$zoneService = new ZoneService($pdo, new LegacyZoneBuildBackend($pdo), new TopologyLock($pdo));
$result = $zoneService->createZone(actorFromSession(), $input);

$lock = TopologyLock::NAME;

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'zone.create.access_denied':
            logAccessDenied('zone_add', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'zone.create.invalid_input':
            foreach ($ctx['errors'] as $error) {
                toastError(
                    $LANG[$error] ?? $LANG['generic_validation_error'],
                    "Zoneneingabe ungültig: {$error}"
                );
            }
            break;

        case 'zone.create.no_server':
            toastError(
                $LANG['zone_error_no_server_selected'],
                "Zonenanlage fehlgeschlagen: Kein Server ausgewählt."
            );
            break;

        case 'zone.create.master_not_included':
            toastError(
                $LANG['zone_error_master_not_included'],
                "Zonenanlage fehlgeschlagen: Master nicht unter den ausgewählten Servern."
            );
            break;

        case 'zone.create.master_not_found':
            toastError(
                $LANG['zone_error_master_load_failed'],
                "Zonenanlage fehlgeschlagen: Master-ID {$ctx['masterId']} konnte nicht geladen werden."
            );
            break;

        case 'zone.create.invalid_soa_ns':
            toastError(
                $LANG['ERR_ZONE_SOA_NS'],
                "Zoneneingabe ungültig: ERR_ZONE_SOA_NS"
            );
            break;

        case 'zone.create.lock_acquire_error':
            appLog('error', "[zone_add] Lock '$lock' konnte nicht bezogen werden: " . $ctx['detail']);
            break;

        case 'zone.create.topology_locked':
            toastWarning(
                $LANG['topology_locked'] ?? 'Eine Veröffentlichung oder strukturelle Änderung läuft gerade. Bitte in Kürze erneut versuchen.',
                "[zone_add] Zone '{$ctx['name']}' nicht angelegt: Lock '$lock' nicht verfügbar"
            );
            break;

        case 'zone.create.lock_release_unconfirmed':
            appLog('warning', "[zone_add] Freigabe von Lock '$lock' nicht bestätigt (RELEASE_LOCK = " . var_export($ctx['result'], true) . ")");
            break;

        case 'zone.create.lock_release_error':
            appLog('warning', "[zone_add] Lock '$lock' konnte nicht freigegeben werden (wird mit Verbindungsende frei): " . $ctx['detail']);
            break;

        case 'zone.create.zonefile_invalid':
            toastError(
                $LANG['zone_error_zonefile_invalid'],
                $ctx['output']
            );
            break;

        case 'zone.create.zonefile_warning':
            toastWarning(
                $LANG['zone_warning_zonefile_check'],
                $ctx['output']
            );
            break;

        case 'zone.create.failed':
            toastError(
                $LANG['zone_error_db_save_failed'],
                "Datenbankfehler beim Speichern von Zone '{$ctx['name']}': " . $ctx['detail']
            );
            break;

        case 'zone.create.success':
            toastSuccess(
                sprintf($LANG['zone_created'], htmlspecialchars($ctx['name'])),
                "Zone '{$ctx['name']}' wurde erfolgreich erstellt und gespeichert."
            );
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (zone_add)");
            } else {
                toastError(
                    $LANG['zone_error_db_save_failed'],
                    "Unbehandelte ServiceMessage '{$message->code}' (zone_add)"
                );
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . ($result->ok ? "/pages/zones.php" : "/pages/zones.php?add_new=1"));
exit;
?>
