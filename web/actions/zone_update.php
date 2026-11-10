<?php
/**
 * Datei: zone_update.php
 * Zweck: Aktualisiert eine bestehende DNS-Zone (Metadaten und Serverzuweisung).
 *
 * Funktionen:
 * - Validiert Benutzerrechte (admin oder zugewiesener zoneadmin).
 * - Erkennt gezielt, ob überhaupt relevante Änderungen vorgenommen wurden.
 * - Unterscheidet zwischen:
 *      - Zonen-relevanten Änderungen (z. B. TTL, SOA → führt zu Rebuild)
 *      - nicht kritischen Änderungen (Beschreibung, DynDNS → nur Speichern)
 *      - Änderungen an DNS-Servern (→ führt zu NS/Glue-Rebuild)
 * - Verhindert unnötige Verarbeitung bei unveränderten Daten.
 * - Gibt Rückmeldung über Toasts und leitet zurück zur Zonenübersicht.
 *
 * Web-Adapter: Eingaben lesen, ZoneService::updateZone() aufrufen, ServiceMessages in die bisherigen
 * Toasts/Logs/Redirects übersetzen. Validierung, Berechtigung (DB-Rolle/Zuweisung), Topologie-Lock,
 * Sperren und Transaktion liegen im Service.
 *
 * Bearbeitungsstand (S21): Das Formular sendet "edit_version" (aus ZoneReadService::getZone()). Fehlt es,
 * ist es ungültig oder veraltet, speichert der Service nichts ("zone.update.conflict"); der Adapter
 * leitet zum Bearbeitungsformular mit dem aktuellen Stand zurück. Verworfene Eingaben werden nicht
 * übernommen.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/TopologyLock.php';
require_once __DIR__ . '/../inc/app/zones/ZoneService.php';
require_once __DIR__ . '/../inc/deploy/LegacyZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$id = (int)($_POST['id'] ?? 0);
requireRole(['admin', 'zoneadmin']);

// Rohwerte aus dem Formular (Normalisierung und Validierung im Service)
$input = new ZoneUpdateInput(
    $_POST['ttl'] ?? null,
    $_POST['prefix_length'] ?? null,
    $_POST['description'] ?? null,
    isset($_POST['allow_dyndns']),
    $_POST['soa_mail'] ?? null,
    $_POST['soa_ns'] ?? null,
    $_POST['soa_refresh'] ?? null,
    $_POST['soa_retry'] ?? null,
    $_POST['soa_expire'] ?? null,
    $_POST['soa_minimum'] ?? null,
    $_POST['server_ids'] ?? null,
    $_POST['master_server_id'] ?? null,
    $_POST['type'] ?? null,
    $_POST['edit_version'] ?? null
);

$zoneService = new ZoneService($pdo, new LegacyZoneBuildBackend($pdo), new TopologyLock($pdo));
$result = $zoneService->updateZone(actorFromSession(), $id, $input);

$lock = TopologyLock::NAME;
$redirect = $result->ok ? "/pages/zones.php" : "/pages/zones.php?edit_id=$id";

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'zone.update.access_denied':
            if (($ctx['reason'] ?? '') === 'db_role' || ($ctx['reason'] ?? '') === 'db_assignment') {
                logAccessDenied('zone_update', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank nicht (mehr) berechtigt, Zone-ID $id zu ändern.");
            } else {
                logAccessDenied('zone_update', "Unbefugter Zugriff auf Zone-ID $id");
            }
            http_response_code(403);
            exit('Zugriff verweigert.');

        case 'zone.update.not_found':
            toastError(
                $LANG['zone_error_not_found'],
                "Zone-ID {$id} konnte nicht geladen werden."
            );
            $redirect = "/pages/zones.php";
            break;

        case 'zone.update.invalid_input':
            foreach ($ctx['errors'] as $error) {
                toastError(
                    $LANG[$error] ?? $LANG['generic_validation_error'],
                    "Zoneneingabe ungültig (ID {$id}): {$error}"
                );
            }
            break;

        case 'zone.update.invalid_prefix_length':
            toastError(
                $LANG['zone_error_invalid_prefix_length'],
                "Zone-ID {$id} hat ungültige Prefix-Länge: {$ctx['prefixLength']}"
            );
            break;

        case 'zone.update.no_servers':
            toastError(
                $LANG['zone_error_no_servers'],
                "Zone-ID {$id}: keine DNS-Server ausgewählt."
            );
            break;

        case 'zone.update.master_not_in_list':
            toastError(
                $LANG['zone_error_master_not_in_list'],
                "Zone-ID {$id}: Master-ID {$ctx['masterId']} nicht in Serverliste."
            );
            break;

        case 'zone.update.master_inactive':
            toastError(
                $LANG['zone_error_master_inactive'],
                "Zone-ID {$id}: Der gewählte Master-Server ({$ctx['masterId']}) ist nicht aktiv."
            );
            break;

        case 'zone.update.invalid_soa_ns':
            toastError(
                $LANG['ERR_ZONE_SOA_NS'],
                "Zoneneingabe ungültig (ID {$id}): ERR_ZONE_SOA_NS"
            );
            break;

        case 'zone.update.lock_acquire_error':
            appLog('error', "[zone_update] Lock '$lock' konnte nicht bezogen werden: " . $ctx['detail']);
            break;

        case 'zone.update.topology_locked':
            toastWarning(
                $LANG['topology_locked'] ?? 'Eine Veröffentlichung oder strukturelle Änderung läuft gerade. Bitte in Kürze erneut versuchen.',
                "[zone_update] Serverzuweisung von Zone-ID {$id} nicht geändert: Lock '$lock' nicht verfügbar"
            );
            break;

        case 'zone.update.lock_release_unconfirmed':
            appLog('warning', "[zone_update] Freigabe von Lock '$lock' nicht bestätigt (RELEASE_LOCK = " . var_export($ctx['result'], true) . ")");
            break;

        case 'zone.update.lock_release_error':
            appLog('warning', "[zone_update] Lock '$lock' konnte nicht freigegeben werden (wird mit Verbindungsende frei): " . $ctx['detail']);
            break;

        case 'zone.update.ns_glue_failed':
            toastError(
                $LANG['zone_error_ns_glue_failed'],
                $ctx['output']
            );
            break;

        case 'zone.update.ns_glue_warning':
            toastWarning(
                $LANG['zone_warning_ns_glue'],
                $ctx['output']
            );
            break;

        case 'zone.update.server_assignment_failed':
            toastError(
                $LANG['zone_error_server_assignment_failed'],
                "Fehler beim Aktualisieren von Zone-ID {$id}: " . $ctx['detail']
            );
            break;

        case 'zone.update.zonefile_invalid':
            toastError(
                $LANG['zone_error_zonefile_invalid'],
                $ctx['output']
            );
            break;

        case 'zone.update.zonefile_warning':
            toastWarning(
                $LANG['zone_warning_zonefile_check'],
                $ctx['output']
            );
            break;

        case 'zone.update.conflict':
            $reasonText = match ($ctx['reason'] ?? '') {
                'missing' => 'Bearbeitungsstand fehlt',
                'invalid' => 'Bearbeitungsstand ungültig',
                default   => 'Bearbeitungsstand veraltet',
            };
            toastError(
                $LANG['zone_error_conflict'],
                "Zone-Update abgelehnt: {$reasonText} (Zone {$ctx['name']}, ID {$id})"
            );
            break;

        case 'zone.update.unchanged':
            toastSuccess($LANG['no_changes'], "Die Zonendaten sind unverändert.");
            break;

        case 'zone.update.failed':
            toastError(
                $LANG['zone_error_db_save_failed'],
                "Zonen-Metadaten konnten für Zone '{$ctx['name']}' (ID {$id}) nicht gespeichert werden: " . $ctx['detail']
            );
            break;

        case 'zone.update.success':
            if (empty($_SESSION['toast_errors'])) {
                toastSuccess(
                    sprintf($LANG['zone_updated'], htmlspecialchars($ctx['name'])),
                    "Zone '{$ctx['name']}' (ID {$id}) erfolgreich geändert."
                );
            }
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (zone_update)");
            } else {
                toastError(
                    $LANG['zone_error_db_save_failed'],
                    "Unbehandelte ServiceMessage '{$message->code}' (zone_update)"
                );
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . $redirect);
exit;
