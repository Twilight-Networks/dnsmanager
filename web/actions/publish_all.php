<?php
/**
 * Datei: publish_all.php
 *
 * Zweck:
 * Veröffentlicht alle als "geändert" markierten DNS-Zonen (oder bei System-Flag `bind_dirty`)
 * durch Erzeugung und Verteilung der Zonendateien sowie zugehöriger Konfigurationsdateien.
 *
 * Ablauf:
 * - Erkennt alle betroffenen Zonen (changed=1 oder global bind_dirty=1)
 * - Für jede Zone:
 *   - Zonendatei erzeugen + named-checkzone-Validierung (via generate_zone_file)
 *   - Zonendatei an zugewiesene Server verteilen (lokal und remote)
 *   - Konfigurationsdatei (.conf) erzeugen und verteilen
 * - Nach erfolgreicher Verteilung:
 *   - BIND reload (lokal)
 *   - Flags (bind_dirty, changed) zurücksetzen
 * - Rückmeldung erfolgt über Session-Toasts (Erfolg, Warnung, Fehler)
 *
 * Zugriffsschutz:
 * - Nur für Benutzer mit Rolle 'admin' oder 'zoneadmin'
 *
 * Abhängigkeiten:
 * - bind_file_generator.php → generate_zone_file()
 * - bind_file_distributor.php → distribute_zone_file(), distribute_zone_conf_file()
 */

require_once __DIR__ . '/../common.php';
requireRole(['admin', 'zoneadmin']);
require_once __DIR__ . '/../inc/deploy/bind_file_generator.php';
require_once __DIR__ . '/../inc/deploy/bind_file_distributor.php';

$errors = [];
$warnings = [];

try {
    // Prüfen, ob ein vollständiger Rebuild nötig ist (z. B. nach Serveränderungen)
    $stmt = $pdo->prepare("SELECT bind_dirty FROM system_status WHERE id = :id");
    $stmt->execute(['id' => 1]);
    $bind_dirty = $stmt->fetchColumn();

    // Alle betroffenen Zonen abrufen
    if ($bind_dirty) {
        $stmt = $pdo->prepare("SELECT id, name FROM zones");
    } else {
        $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE changed = 1");
    }
    $stmt->execute();
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($zones as $zone) {
        $zone_id = $zone['id'];
        $zone_name = $zone['name'];

        // Zonendatei generieren und mit named-checkzone validieren
        $validation = generate_zone_file(
            $zone_id,
            '/tmp',
            'publish'
        );

        // Fehler bei Generierung → überspringen
        if ($validation['status'] === 'error') {
            $errors[] = "Zone $zone_name: " . $validation['output'];
            continue;
        }

        // named-checkzone meldet Warnungen (kein Abbruch)
        if ($validation['status'] === 'warning') {
            $warnings[] = "Zone $zone_name: " . $validation['output'];
        }

        $path = $validation['path'] ?? null;

        // Zonendatei an alle Server verteilen (lokal und remote)
        $dist_result = distribute_zone_file($zone_id, $path);
        if ($dist_result['status'] === 'error') {
            foreach ($dist_result['errors'] as $e) {
                $errors[] = "Zone $zone_name: $e";
                error_log("[publish] $e");
            }
        } elseif ($dist_result['status'] === 'warning') {
            foreach ($dist_result['errors'] as $e) {
                $warnings[] = "Zone $zone_name: $e";
                error_log("[publish] WARNUNG: $e");
            }
        }
        if (!empty($dist_result['output'])) {
            error_log("[publish] $dist_result[output]");
        }

         // Konfigurationsdatei (.conf) für Zone erstellen und verteilen
        $conf_result = distribute_zone_conf_file($zone_name);
        if (!empty($conf_result['errors'])) {
            foreach ($conf_result['errors'] as $e) {
                $warnings[] = "Zone $zone_name (conf): $e";
                error_log("[publish] WARNUNG: $e");
            }
        }
        if (!empty($conf_result['output'])) {
            error_log("[publish] $conf_result[output]");
        }
    }

    if (empty($errors)) {

        // Flags zurücksetzen
        $pdo->exec("UPDATE system_status SET bind_dirty = 0 WHERE id = 1");
        $pdo->exec("UPDATE zones SET changed = 0 WHERE changed = 1");

        // Ergebnis anzeigen
        if (empty($warnings)) {
            toastSuccess(
                "Alle Zonen erfolgreich veröffentlicht.",
                "Alle Zonendateien wurden erfolgreich verteilt und BIND wurde neu geladen."
            );
        } else {
            foreach ($warnings as $w) {
                toastWarning(
                    "Warnung bei Veröffentlichung: $w",
                    "Veröffentlichung abgeschlossen mit Warnung: $w"
                );
            }
        }
    } else {
        foreach ($errors as $e) {
            toastError(
                "Fehler bei Veröffentlichung: $e",
                "Veröffentlichung fehlgeschlagen: $e"
            );
        }
    }
} catch (Throwable $e) {
    toastError(
        "Veröffentlichung fehlgeschlagen.",
        "Systemfehler beim Publish: " . $e->getMessage()
    );
}

// Zurück zur vorherigen Seite oder Dashboard
$ref = $_GET['return'] ?? $_SERVER['HTTP_REFERER'] ?? (rtrim(BASE_URL, '/') . '/pages/dashboard.php');
header("Location: $ref");
exit;
?>
