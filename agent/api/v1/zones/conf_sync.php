<?php
/**
 * API-Endpunkt: /api/v1/zones/conf_sync.php
 *
 * Zweck:
 * - Empfängt eine zonenspezifische .conf-Datei als base64-kodierten Inhalt
 * - Speichert die Datei unter /etc/bind/zones/conf/
 * - Rekonstruiert die zentrale /etc/bind/zones/zones.conf (atomar)
 * - Entfernt KEINE anderen .conf-Dateien (Orphan-Cleanup erfolgt ausschließlich über zone_cleanup.php)
 * - Führt einen BIND-Reload via rndc durch
 *
 * AS2a (Modi observe/enforce; docs/ARCHITECTURE_HISTORY.md Abschnitt 5f): Prozessfristen (E117), Exit-Code des
 * Reloads (E93) und Wirkungsangabe effect. Der Rollback vor dem Reload bleibt unverändert (rollback = ok/failed nur bei
 * tatsächlich versuchtem Rollback); nach dem Reload-Versuch gibt es keinen Rollback (Vorwärtsstrategie). Im Modus off ist
 * das Verhalten unverändert (E95, E101).
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token (Header: Authorization: Bearer <token>)
 * - Kein Datenbankzugriff erforderlich
 * - Nur serverseitige File- und BIND-Konfigurationsoperationen
 */

require_once __DIR__ . '/../../../inc/init.php';
require_once __DIR__ . '/../../../config/api_config.php';
require_once __DIR__ . '/../../../inc/auth.php';
require_once __DIR__ . '/../../../inc/bind_utils.php';
require_once __DIR__ . '/../../../inc/file_utils.php';
require_once __DIR__ . '/../../../inc/fence.php';
require_once __DIR__ . '/../../../inc/dns_state.php';
require_once __DIR__ . '/../../../inc/response.php';

// === Konfiguration laden und Token prüfen ===
$config = require __DIR__ . '/../../../config/api_config.php';
$validTokens = loadApiTokens($config['token_file']);
requireValidToken($validTokens);

// AS2a: neue Fristen und Angaben nur in observe/enforce
$as2 = fenceMode($config) !== 'off';
if ($as2) {
    agentResponseAttach('effect', 'none');   // bis zur ersten nachgewiesenen Änderung
}

// === Eingabe verarbeiten ===
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['zone_name'], $input['conf_data'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültige Eingabeparameter']);
}

$zoneName = $input['zone_name'];
$confData = base64_decode($input['conf_data']);
if ($confData === false) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Fehler beim Dekodieren der Zonendaten']);
}

$safeName = safeZoneName($zoneName);
$confDir = $config['zone_conf_dir'];
$confPath = "$confDir/$safeName.conf";
$zonesConfPath = $config['zones_conf_file'];

// === Gemeinsamen Agent-Lock erwerben (identisch zu zone_sync.php, blockierend) ===
// Serialisiert zone_sync und conf_sync gegenseitig: alle Änderungen an verwalteten BIND-Dateien
// und der BIND-Reload erfolgen unter diesem Lock. Erst nach reloadBind() freigeben.
$lockPath = agentLockPath($config);
if ($as2) {
    // AS2a (E117): nicht blockierend mit Frist; es müssen mindestens 8 s Arbeitszeit verbleiben
    $lock = agentLockWithDeadline($lockPath, 8.0);
    $lockHandle = $lock['handle'];
    if ($lockHandle === null) {
        if ($lock['reason'] === 'timeout') {
            sendJsonResponse(503, ['status' => 'error', 'message' => 'Agent-Sperre innerhalb der Frist nicht erhalten – keine Änderung', 'code' => 'lock_timeout']);
        }
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für die Konfigurationsübernahme']);
    }
} else {
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für die Konfigurationsübernahme']);
    }
}

// Lock freigeben (vor jedem sendJsonResponse nach Lock-Erwerb; mehrfacher Aufruf unschädlich)
$releaseLock = function () use (&$lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    $lockHandle = null;
};

// === AS1: Fencing-Prüfung unter dem Lock, vor jeder Änderung (F3) ===
$fence = agentFenceCheck($config, $input);
if (!$fence['allow']) {
    $releaseLock();
    sendJsonResponse($fence['http'], $fence['body']);
}

// AS1: Verzeichnisse, deren Einträge eine Übernahme bzw. ein Rollback ändern kann
$syncDirs = [$confDir, dirname($confDir), dirname($zonesConfPath)];

// === Zustand vor der ersten Änderung sichern (für Rollback) ===
// Schlägt ein Backup-Lesevorgang fehl, wird ohne jede Änderung abgebrochen.
// E99: Existenz fail-closed prüfen – ein Prüffehler darf nicht als "nicht vorhanden" gelten (sonst fehlende Sicherung und
// Löschen statt Wiederherstellen im Rollback)
$confState = pathState($confPath);
$zonesConfState = pathState($zonesConfPath);
if ($confState === 'error' || $zonesConfState === 'error') {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Zustand der bestehenden Konfiguration nicht prüfbar – keine Änderung vorgenommen']);
}
$confExisted = $confState === 'present';
$oldConf = null;
$oldConfPerms = null;
if ($confExisted) {
    // E98: nur nachweislich vollständig gelesene Sicherung (sonst Rollback auf einen verkürzten Stand)
    $oldConf = is_file($confPath) ? readFileComplete($confPath) : false;
    if ($oldConf === false) {
        $releaseLock();
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Sicherung der bestehenden .conf-Datei fehlgeschlagen – keine Änderung vorgenommen']);
    }
    $perms = @fileperms($confPath);
    $oldConfPerms = $perms !== false ? ($perms & 0777) : null;
}

$zonesConfExisted = $zonesConfState === 'present';
$oldZonesConf = null;
$oldZonesConfPerms = null;
if ($zonesConfExisted) {
    $oldZonesConf = is_file($zonesConfPath) ? readFileComplete($zonesConfPath) : false;
    if ($oldZonesConf === false) {
        $releaseLock();
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Sicherung der bestehenden zones.conf fehlgeschlagen – keine Änderung vorgenommen']);
    }
    $perms = @fileperms($zonesConfPath);
    $oldZonesConfPerms = $perms !== false ? ($perms & 0777) : null;
}

// Rollback auf den gesicherten Zustand. Reihenfolge: zuerst zones.conf, danach <zone>.conf,
// damit zones.conf nie auf eine bereits entfernte .conf zeigt.
$rollback = function () use (
    $confPath, $confExisted, $oldConf, $oldConfPerms,
    $zonesConfPath, $zonesConfExisted, $oldZonesConf, $oldZonesConfPerms, $syncDirs
): bool {
    $ok = true;

    // 1. zones.conf wiederherstellen (atomar) bzw. entfernen, falls sie vorher nicht existierte
    if ($zonesConfExisted) {
        if (!writeTextFileAtomic($zonesConfPath, $oldZonesConf, $oldZonesConfPerms)) {
            $ok = false;
        }
    } elseif (pathState($zonesConfPath) !== 'absent' && !@unlink($zonesConfPath)) {   // E99
        $ok = false;
    }

    // 2. <zone>.conf wiederherstellen bzw. entfernen, falls sie neu angelegt wurde
    if ($confExisted) {
        if (!writeTextFileAtomic($confPath, $oldConf, $oldConfPerms)) {
            $ok = false;
        }
    } elseif (pathState($confPath) !== 'absent') {   // E99
        // Neue .conf nur entfernen, wenn zones.conf erfolgreich wiederhergestellt wurde;
        // sonst würde die (Kandidaten-)zones.conf auf eine fehlende Datei zeigen.
        if (!$ok || !@unlink($confPath)) {
            $ok = false;
        }
    }

    // 3. AS1: wiederhergestellten Zustand dauerhaft machen
    if ($ok && !syncDirectories($syncDirs)) {
        $ok = false;
    }

    return $ok;
};

// Kontrollierter Fehler nach einer Live-Änderung: Rollback versuchen, kein Reload, Lock freigeben, antworten.
// AS2a: Rollback gelungen ⇒ effect none (Bestand wiederhergestellt, kein Reload); gescheitert ⇒ effect unknown.
$failWithRollback = function (int $httpCode, string $message, array $extra = []) use ($rollback, $releaseLock, $as2, $config): never {
    if (!$rollback()) {
        error_log("[conf_sync] ROLLBACK FEHLGESCHLAGEN nach: $message");
        $releaseLock();
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => "$message – Rollback fehlgeschlagen: manuelle Prüfung der BIND-Konfiguration erforderlich. Kein Reload durchgeführt.",
            'rollback' => 'failed'
        ] + ($as2 ? ['effect' => 'unknown'] : []) + $extra);
    }

    // Diagnose: Ist der wiederhergestellte vorherige Zustand gültig? (löst keinen Reload aus)
    $previous = $as2 ? agentCheckconfTimed($config) : checkBindConfig();
    $extra['rollback'] = 'ok';
    if ($as2) {
        $extra['effect'] = 'none';
    }
    $extra['previous_config_valid'] = $previous['ok'];
    if (!$previous['ok']) {
        $extra['previous_check_output'] = trim($previous['output']);
        $message .= ' (Hinweis: auch der wiederhergestellte vorherige Zustand ist laut named-checkconf ungültig)';
        error_log("[conf_sync] Vorheriger Konfigurationszustand ist ebenfalls ungültig: " . trim($previous['output']));
    }

    $releaseLock();
    sendJsonResponse($httpCode, ['status' => 'error', 'message' => $message] + $extra);
};

// === Zielverzeichnis sicherstellen ===
ensureDirectory($confDir);

// === Neue .conf-Datei schreiben (atomar) ===
// Bei Fehler bleibt die Zieldatei unverändert (writeTextFileAtomic): keine Live-Änderung → kein Rollback, kein Reload.
if (!writeTextFileAtomic($confPath, $confData)) {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben der .conf-Datei – keine Änderung vorgenommen']);
}

// === Ab hier wurde eine Live-Datei verändert: jeder kontrollierte Fehler führt zum Rollback ===

// === Neue zones.conf generieren ===
// S43 (AS1): Lesefehler des Verzeichnisses erkennen (sonst würde zones.conf ohne Includes neu geschrieben und geladen)
$confFiles = listConfFiles($confDir);
if ($confFiles === false) {
    $failWithRollback(500, 'Fehler beim Lesen des Konfigurationsverzeichnisses');
}
$includes = [];
$includedPaths = [];
foreach ($confFiles as $file) {
    if (is_file($file)) {
        $real = realpath($file);
        if ($real === false) {
            $failWithRollback(500, 'Verzeichnisauflistung nicht zweifelsfrei vollständig (Pfad nicht auflösbar) – zones.conf unverändert, Änderung zurückgerollt, kein Reload', ['code' => 'listing_incomplete']);
        }
        $includes[] = 'include "' . $real . '";';
        $includedPaths[] = $real;
    }
}

// E98 (S43-Ergänzung): Vollständigkeitsnachweis der Auflistung. conf_sync fügt genau eine Zone hinzu bzw. ersetzt sie;
// es entfernt nie ein Include. Ein getdents()-Fehler mitten im Durchlauf kann scandir() und glob() gleichermaßen
// verkürzen, ohne dass PHP einen Fehler meldet. Deshalb muss jedes bisherige Include und die eigene Datei in der neuen
// Liste enthalten sein; sonst bleibt die bisherige Konfiguration erhalten (Rollback, kein Reload).
$ownConf = realpath($confPath);
$missingIncludes = array_values(array_diff(zonesConfIncludes((string)$oldZonesConf), $includedPaths));
if ($missingIncludes !== [] || $ownConf === false || !in_array($ownConf, $includedPaths, true)) {
    $failWithRollback(500, 'Verzeichnisauflistung nicht zweifelsfrei vollständig (' . count($missingIncludes)
        . ' bisherige Includes fehlen) – zones.conf unverändert, Änderung zurückgerollt, kein Reload', [
        'code' => 'listing_incomplete',
        'missing_includes' => count($missingIncludes)
    ]);
}

$zonesConfContent = implode("\n", $includes) . "\n";

// zones.conf atomar ersetzen (Temp-Datei im selben Verzeichnis + rename, bisherige Rechte best effort)
if (!writeTextFileAtomic($zonesConfPath, $zonesConfContent)) {
    $failWithRollback(500, 'Fehler beim Schreiben von zones.conf');
}

// === Verbindlicher named-checkconf-Preflight vor dem Reload ===
$preflight = $as2 ? agentCheckconfTimed($config) : checkBindConfig();
if ($as2 && !$preflight['ok'] && $preflight['status'] !== 'ok') {
    $failWithRollback(503, 'BIND-Konfigurationsprüfung nicht abgeschlossen (Frist) – Änderung zurückgerollt, kein Reload', ['code' => 'check_timeout']);
}
if (!$preflight['ok']) {
    $failWithRollback(422, 'BIND-Konfigurationsprüfung (named-checkconf) fehlgeschlagen – Änderung zurückgerollt, kein Reload', [
        'check_output' => trim($preflight['output']),
        'exit_code' => $preflight['exit_code']
    ]);
}

// === AS1: Übernahme dauerhaft machen (fsync der Verzeichnisse) vor dem Reload ===
if (!syncDirectories($syncDirs)) {
    $failWithRollback(500, 'Konfigurationsübernahme nicht dauerhaft bestätigt (fsync des Verzeichnisses fehlgeschlagen) – Änderung zurückgerollt, kein Reload');
}

// === BIND reload (nur nach erfolgreichem Preflight) ===
if ($as2) {
    // AS2a (E93, E117): Exit-Code und Frist; kein Nachweis der Übernahme; kein Rollback nach dem Reload-Versuch
    $reload = agentReloadTimed($config);
    $releaseLock();
    $reloadReport = ['status' => $reload['status'], 'exit_code' => $reload['exit_code']];
    if ($reload['status'] !== 'ok') {
        $codeMap = ['timeout' => 'reload_timeout', 'not_started' => 'reload_not_started'];
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => 'Zonen-Konfiguration übernommen, BIND-Reload nicht erfolgreich – Ladezustand unbestimmt',
            'code' => $codeMap[$reload['status']] ?? 'reload_failed',
            'effect' => 'applied',
            'rollback' => 'not_attempted',
            'bind_state' => 'unknown',
            'reload' => $reloadReport,
            'rndc' => $reload['output']
        ]);
    }
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'Zonen-Konfiguration übernommen, zones.conf neu geschrieben.',
        'rndc' => $reload['output'],
        'effect' => 'applied',
        'reload' => $reloadReport
    ]);
}
$rndcOutput = reloadBind();

// === Lock freigeben (nach dem Reload) ===
$releaseLock();

// === Antwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'message' => 'Zonen-Konfiguration übernommen, zones.conf neu geschrieben.',
    'rndc' => trim($rndcOutput)
]);
