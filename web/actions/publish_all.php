<?php
/**
 * Datei: publish_all.php
 *
 * Zweck:
 * Veröffentlicht alle als "geändert" markierten DNS-Zonen (oder bei System-Flag `bind_dirty`)
 * durch Erzeugung und Verteilung der Zonendateien sowie zugehöriger Konfigurationsdateien.
 *
 * Ablauf:
 * - Erkennt alle betroffenen Zonen (changed <> 0 oder global bind_dirty <> 0, siehe Flag-Semantik unten)
 * - Für jede Zone:
 *   - Zonendatei erzeugen + named-checkzone-Validierung (via generate_zone_file)
 *   - Zonendatei an zugewiesene Server verteilen (lokal und remote)
 *   - Konfigurationsdatei (.conf) erzeugen und verteilen
 * - Nach vollständig erfolgreicher Verteilung (keine Fehler, keine Teilverteilung):
 *   - Orphan-Cleanup auf allen aktiven Servern (distribute_zone_cleanup → zone_cleanup.php)
 * - Nach erfolgreicher Verteilung:
 *   - BIND reload (lokal)
 *   - Von diesem Lauf übernommene Flags (Status 2) zurücksetzen
 * - Rückmeldung erfolgt über Session-Toasts (Erfolg, Warnung, Fehler)
 *
 * Zugriffsschutz:
 * - Nur für Benutzer mit Rolle 'admin' oder 'zoneadmin'
 * - Nur per POST mit gültigem CSRF-Token (Prüfung vor Lock-Bezug und Flag-Verarbeitung)
 * - Rücksprungziel nur über safe_return_path() (interne Seiten unter BASE_URL/pages/)
 *
 * Abhängigkeiten:
 * - bind_file_generator.php → generate_zone_file()
 * - bind_file_distributor.php → distribute_zone_file(), distribute_zone_conf_file()
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
require_once __DIR__ . '/../inc/deploy/bind_file_generator.php';
require_once __DIR__ . '/../inc/deploy/bind_file_distributor.php';

$errors = [];
$warnings = [];
$published_zone_ids = []; // Zonen, die in diesem Lauf vollständig verteilt wurden
$partial_zone_ids = [];   // Zonen mit Teilfehler bei Zone- oder Conf-Verteilung
$claimed_zone_ids = [];   // Zonen, deren changed dieser Lauf auf 2 zu übernehmen versucht hat
$bind_dirty_claimed = false; // true, wenn dieser Lauf einen ausstehenden Full-Rebuild (bind_dirty <> 0) übernommen hat

// Flag-Semantik von zones.changed und system_status.bind_dirty (TINYINT; bewusst kein reiner Boolean):
//   0 = sauber
//   1 = Änderung wartet auf Publish (wird von allen ändernden Aktionen geschrieben)
//   2 = von einem Publish-Lauf übernommen bzw. Publish nicht sicher abgeschlossen
// 2 ist kein Lock und kein Besitzanspruch, sondern ein persistenter Pending-Zustand: Nach einem harten
// Abbruch bleibt 2 stehen und wird beim nächsten Lauf wie 1 erneut verarbeitet. Eine neue Änderung
// während eines Laufs schreibt wieder 1 und überschreibt damit die 2. Ein Lauf setzt ausschließlich
// 2 -> 0 zurück, niemals 1 -> 0.

// Übernommene Flags bei kontrolliertem Fehler wieder auf 1 setzen, soweit die DB erreichbar ist.
// Scheitert das (z. B. DB-Ausfall), bleibt 2 als gültiger, beim nächsten Lauf erneut verarbeiteter Zustand stehen.
$restore_pending_flags = function () use ($pdo, &$claimed_zone_ids, &$bind_dirty_claimed): void {
    try {
        $stmt_restore = $pdo->prepare("UPDATE zones SET changed = 1 WHERE id = ? AND changed = 2");
        foreach ($claimed_zone_ids as $cid) {
            $stmt_restore->execute([$cid]);
        }
        if ($bind_dirty_claimed) {
            $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1 AND bind_dirty = 2");
        }
    } catch (Throwable $e) {
        appLog('error', "[publish] Publish-Flags konnten nicht wiederhergestellt werden (Status 2 bleibt erhalten): " . $e->getMessage());
    }
};

// Publish All global serialisieren: nur ein Lauf gleichzeitig. Status 2 trägt keinen Owner, daher dürfen
// zwei Läufe nicht parallel 2 setzen bzw. 2 -> 0 zurücksetzen. Der MariaDB/MySQL Advisory Lock ist an
// diese PDO-Verbindung gebunden und wird vor jeder Flag-Übernahme bezogen; bei hartem Prozessabbruch
// gibt die DB ihn mit dem Verbindungsende frei. DynDNS nimmt bewusst nicht an diesem Lock teil.
$publish_lock_name = 'dnsmanager_publish_all';
$publish_lock_acquired = false;
try {
    $stmt_lock = $pdo->prepare("SELECT GET_LOCK(?, 0)");
    $stmt_lock->execute([$publish_lock_name]);
    $lock_result = $stmt_lock->fetchColumn(); // 1 = erhalten, 0 = belegt, NULL = Fehler
    $publish_lock_acquired = $lock_result !== null && (int)$lock_result === 1;
    if (!$publish_lock_acquired) {
        toastWarning(
            $LANG['publish_all_locked'] ?? 'Ein anderer Publish-Lauf ist bereits aktiv. Bitte später erneut versuchen.',
            "[publish] Publish All nicht gestartet: Lock '$publish_lock_name' nicht verfügbar (belegt oder Fehler)"
        );
    }
} catch (Throwable $e) {
    toastError(
        $LANG['publish_all_exception'],
        "Publish-Lock konnte nicht bezogen werden: " . $e->getMessage()
    );
}

// Ohne Lock keine Flags verändern und nichts veröffentlichen
if (!$publish_lock_acquired) {
    $ref = safe_return_path($_POST['return'] ?? null, rtrim(BASE_URL, '/') . '/pages/dashboard.php');
    header("Location: $ref");
    exit;
}

try {
    // Ausstehenden Full-Rebuild (z. B. nach zone_delete) übernehmen: 1 oder 2 -> 2.
    // Ob einer aussteht, wird anschließend gelesen (nicht per rowCount, da 2 -> 2 keine Zeile ändert).
    $pdo->exec("UPDATE system_status SET bind_dirty = 2 WHERE id = 1 AND bind_dirty <> 0");
    $stmt = $pdo->prepare("SELECT bind_dirty FROM system_status WHERE id = :id");
    $stmt->execute(['id' => 1]);
    $bind_dirty_claimed = (int)$stmt->fetchColumn() !== 0;

    // Alle betroffenen Zonen abrufen
    if ($bind_dirty_claimed) {
        $stmt = $pdo->prepare("SELECT id, name FROM zones");
    } else {
        // changed 1 und 2 gelten als ausstehend
        $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE changed <> 0");
    }
    $stmt->execute();
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($zones as $zone) {
        $zone_id = $zone['id'];
        $zone_name = $zone['name'];

        // changed vor der Generierung übernehmen: 1 oder 2 -> 2. Eine danach erfolgende Änderung schreibt
        // wieder 1 und bleibt damit erhalten. Der Status wird gelesen, nicht per rowCount ermittelt.
        $claimed_zone_ids[] = (int)$zone_id;
        try {
            $pdo->prepare("UPDATE zones SET changed = 2 WHERE id = ? AND changed <> 0")->execute([$zone_id]);
            $stmt_changed = $pdo->prepare("SELECT changed FROM zones WHERE id = ?");
            $stmt_changed->execute([$zone_id]);
            $zone_changed = $stmt_changed->fetchColumn();
        } catch (Throwable $e) {
            $errors[] = "Zone $zone_name: Publish-Status konnte nicht übernommen werden: " . $e->getMessage();
            appLog('error', "[publish] Zone $zone_name: Publish-Status konnte nicht übernommen werden: " . $e->getMessage());
            continue;
        }

        // Normaler changed-Lauf: Zone überspringen, wenn sie inzwischen von einem anderen Vorgang
        // veröffentlicht (changed = 0) oder gelöscht wurde. Beim Full-Rebuild werden alle Zonen verarbeitet.
        if (!$bind_dirty_claimed && ($zone_changed === false || (int)$zone_changed === 0)) {
            continue;
        }

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
                appLog('error', "[publish] $e");
            }
        } elseif ($dist_result['status'] === 'warning') {
            foreach ($dist_result['errors'] as $e) {
                $warnings[] = "Zone $zone_name: $e";
                appLog('warning', "[publish] $e");
            }
        }
        if (!empty($dist_result['output'])) {
            appLog('info', "[publish] $dist_result[output]");
        }

         // Konfigurationsdatei (.conf) für Zone erstellen und verteilen
        $conf_result = distribute_zone_conf_file($zone_name);
        if (!empty($conf_result['errors'])) {
            foreach ($conf_result['errors'] as $e) {
                $warnings[] = "Zone $zone_name (conf): $e";
                appLog('warning', "[publish] $e");
            }
        }
        if (!empty($conf_result['output'])) {
            if (($conf_result['status'] ?? '') === 'warning') {
                $warnings[] = "Zone $zone_name (conf): " . $conf_result['output'];
                appLog('warning', "[publish] " . $conf_result['output']);
            } elseif (($conf_result['status'] ?? '') === 'error') {
                $errors[] = "Zone $zone_name (conf): " . $conf_result['output'];
                appLog('error', "[publish] " . $conf_result['output']);
            } else {
                appLog('info', "[publish] " . $conf_result['output']);
            }
        }

        // Zone nur als veröffentlicht werten, wenn Zone- und Conf-Verteilung vollständig erfolgreich waren
        $conf_ok = ($conf_result['status'] ?? '') === 'ok' && empty($conf_result['errors']);
        if (($dist_result['status'] ?? '') === 'ok' && $conf_ok) {
            $published_zone_ids[] = (int)$zone_id;
        } else {
            $partial_zone_ids[] = (int)$zone_id;
        }
    }

    if (empty($errors)) {

        if (empty($partial_zone_ids)) {
            // Orphan-Cleanup nur nach vollständig erfolgreicher Verteilung aller Zonen dieses Laufs
            // (läuft auch bei leerer Zonenliste, z. B. nachdem die letzte Zone gelöscht wurde).
            $cleanup_result = distribute_zone_cleanup();
            if (($cleanup_result['status'] ?? '') !== 'ok') {
                // Cleanup-Fehler = Warnung, kein Publish-Fehler. bind_dirty = 1 erzwingt Retry (Full-Rebuild + Cleanup)
                // beim nächsten Publish; der folgende Reset (WHERE bind_dirty = 2) lässt die 1 unberührt.
                $cleanup_errors = $cleanup_result['errors'] ?? [];
                if (empty($cleanup_errors)) {
                    $cleanup_errors = ['Zonen-Cleanup nicht vollständig erfolgreich'];
                }
                foreach ($cleanup_errors as $e) {
                    $warnings[] = "Cleanup: $e";
                    appLog('warning', "[publish] Cleanup: $e");
                }
                $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
            } elseif (!empty($cleanup_result['output'])) {
                appLog('info', "[publish] Cleanup: " . $cleanup_result['output']);
            }
        } elseif ($bind_dirty_claimed) {
            // Sonderfall: übernommener Full-Rebuild (bind_dirty = 2) mit Teilverteilung → Cleanup wurde
            // nicht ausgeführt. bind_dirty wieder auf 1 setzen, damit der Reset unten (2 -> 0) ihn nicht
            // löscht und Full-Rebuild + Cleanup beim nächsten Publish ausstehen.
            $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
        }

        // Flags zurücksetzen: ausschließlich den übernommenen Status 2 (2 -> 0).
        // Ein während des Laufs neu gesetztes 1 bleibt stehen.
        if ($bind_dirty_claimed) {
            $pdo->exec("UPDATE system_status SET bind_dirty = 0 WHERE id = 1 AND bind_dirty = 2");
        }
        // changed nur für die in diesem Lauf vollständig veröffentlichten Zonen zurücksetzen
        $stmt_reset = $pdo->prepare("UPDATE zones SET changed = 0 WHERE id = ? AND changed = 2");
        foreach ($published_zone_ids as $pid) {
            $stmt_reset->execute([$pid]);
        }

        // Teilweise verteilte Zonen für einen späteren Publish markiert lassen (auch bei bind_dirty-Läufen)
        $stmt_mark = $pdo->prepare("UPDATE zones SET changed = 1 WHERE id = ?");
        foreach ($partial_zone_ids as $pid) {
            $stmt_mark->execute([$pid]);
        }

        // Ergebnis anzeigen
        if (empty($warnings)) {
            toastSuccess(
                $LANG['publish_all_success'],
                "Alle Zonendateien wurden erfolgreich verteilt und BIND wurde neu geladen."
            );
        } else {
            foreach ($warnings as $w) {
                toastWarning(
                    $LANG['publish_all_warning'],
                    "Veröffentlichung abgeschlossen mit Warnung: $w"
                );
            }
        }
    } else {
        // Fehler im Lauf: übernommene Flags wieder als ausstehend (1) markieren
        $restore_pending_flags();

        foreach ($errors as $e) {
            toastError(
                $LANG['publish_all_error'],
                "Veröffentlichung fehlgeschlagen: $e"
            );
        }
    }
} catch (Throwable $e) {
    // Systemfehler: übernommene Flags wieder als ausstehend (1) markieren, soweit möglich
    $restore_pending_flags();

    toastError(
        $LANG['publish_all_exception'],
        "Systemfehler beim Publish: " . $e->getMessage()
    );
} finally {
    // Publish-Lock auf allen normalen Pfaden freigeben (bei hartem Abbruch: Verbindungsende)
    try {
        $stmt_unlock = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt_unlock->execute([$publish_lock_name]);
    } catch (Throwable $e) {
        appLog('warning', "[publish] Lock '$publish_lock_name' konnte nicht freigegeben werden (wird mit Verbindungsende frei): " . $e->getMessage());
    }
}

// Zurück zur vorherigen Seite oder Dashboard
$ref = safe_return_path($_POST['return'] ?? null, rtrim(BASE_URL, '/') . '/pages/dashboard.php');
header("Location: $ref");
exit;
?>
