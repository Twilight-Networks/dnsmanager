<?php
/**
 * API-Endpunkt: /api/v1/zones/zone_cleanup.php
 *
 * Zweck:
 * - Gleicht die vom DNS-Manager verwalteten Zonen dieses Servers mit dem Sollzustand ab
 *   (valid_zones = vollständige Liste der diesem Server in der Datenbank zugewiesenen Zonen)
 * - Verwaist ist eine vorhandene zone_conf_dir/<safe-zone-name>.conf, deren Basename nicht in valid_zones steht
 * - Entfernt verwaiste .conf-Dateien und je verwaister .conf ausschließlich die exakt gepaarte
 *   Zonendatei zone_data_dir/db.<safe-zone-name> (keine sonstigen db.*, keine .jnl, keine fremden Dateien)
 * - Ersetzt vorher zones.conf atomar (ohne die verwaisten Includes), prüft sie mit named-checkconf
 *   (bei Fehler: alte zones.conf wiederherstellen, nichts löschen, kein Reload) und führt danach genau einen
 *   BIND-Reload aus
 * - Ohne verwaiste .conf: keine Änderung, kein Reload (No-op)
 *
 * Request (JSON):
 * - valid_zones: Pflicht, Array nicht-leerer Strings
 * - allow_empty: Eine leere valid_zones-Liste ist nur mit allow_empty === true (JSON-Boolean) zulässig
 *
 * AS2a (Modi observe/enforce; docs/ARCHITECTURE_HISTORY.md Abschnitt 5f): Prozessfristen (E117), Exit-Code des
 * Reloads (E93), Ergebnis je Zone (zones: conf/file = removed|absent|kept|unknown) und aggregierte Wirkung effect nach
 * der Vorrangregel (nachgewiesene Änderung und ein Rest ⇒ partial; nie none nach einer Änderung). Kein Rollback nach dem
 * Löschen bzw. dem Reload-Versuch. Im Modus off ist das Verhalten unverändert (E95, E101).
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token
 * - Kein Datenbankzugriff erforderlich
 * - Serialisiert mit zone_sync.php und conf_sync.php über denselben Agent-Lock
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

// === Eingabe prüfen ===
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !array_key_exists('valid_zones', $input) || !is_array($input['valid_zones'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültige Eingabeparameter: valid_zones (Array) erforderlich']);
}

// Sollzonen als safeZoneName-Menge (Duplikate werden dabei zusammengefasst)
$validSafeNames = [];
foreach ($input['valid_zones'] as $zone) {
    if (!is_string($zone) || trim($zone) === '') {
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültiger Eintrag in valid_zones: nicht-leerer String erwartet']);
    }
    $validSafeNames[safeZoneName($zone)] = true;
}

// Leere Liste ist potentiell destruktiv: nur mit explizitem allow_empty === true
$allowEmpty = array_key_exists('allow_empty', $input) && $input['allow_empty'] === true;
if (empty($validSafeNames) && !$allowEmpty) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Leere valid_zones-Liste nur mit allow_empty=true zulässig']);
}

// === Pfade ===
$confDir = $config['zone_conf_dir'];
$zoneDir = $config['zone_data_dir'];
$zonesConfPath = $config['zones_conf_file'];

// === Gemeinsamen Agent-Lock erwerben (identisch zu zone_sync.php/conf_sync.php, blockierend) ===
// Scan, zones.conf-Ersatz, Löschungen und Reload erfolgen vollständig unter diesem Lock.
$lockPath = agentLockPath($config);
if ($as2) {
    // AS2a (E117): nicht blockierend mit Frist; es müssen mindestens 8 s Arbeitszeit verbleiben
    $lock = agentLockWithDeadline($lockPath, 8.0);
    $lockHandle = $lock['handle'];
    if ($lockHandle === null) {
        if ($lock['reason'] === 'timeout') {
            sendJsonResponse(503, ['status' => 'error', 'message' => 'Agent-Sperre innerhalb der Frist nicht erhalten – keine Änderung', 'code' => 'lock_timeout']);
        }
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für den Zonen-Cleanup']);
    }
} else {
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für den Zonen-Cleanup']);
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

// === Verwaltete .conf-Dateien scannen ===
// S43 (AS1): Lesefehler des Verzeichnisses erkennen (sonst "keine verwaisten Zonen" als Erfolg)
$confFiles = listConfFiles($confDir);
if ($confFiles === false) {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Lesen des Konfigurationsverzeichnisses']);
}

$keepConfFiles = [];   // bleiben in zones.conf (Reihenfolge wie glob, analog conf_sync.php)
$orphanConfFiles = []; // safe-zone-name => Pfad der verwaisten .conf
foreach ($confFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $base = basename($file, '.conf');
    // Nur eindeutig verwaltete Namen (<safe-zone-name>.conf) können verwaist sein
    if ($base !== '' && $base === safeZoneName($base) && !isset($validSafeNames[$base])) {
        $orphanConfFiles[$base] = $file;
    } else {
        $keepConfFiles[] = $file;
    }
}

// === E98 (S43-Ergänzung): bisherige zones.conf vollständig lesen und Vollständigkeit der Auflistung nachweisen ===
// Ein getdents()-Fehler mitten im Durchlauf kann scandir() und glob() gleichermaßen verkürzen, ohne dass PHP einen Fehler
// meldet. Jedes bisherige Include muss deshalb entweder in der Behalten-Liste oder unter den als verwaist erkannten
// Dateien stehen; sonst ohne jede Änderung und ohne Reload abbrechen (auch statt eines No-op-Erfolgs).
// E99: Existenz fail-closed prüfen
$zonesConfState = pathState($zonesConfPath);
if ($zonesConfState === 'error') {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Zustand der bestehenden zones.conf nicht prüfbar – keine Änderung vorgenommen']);
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
$keepPaths = [];
$listedPaths = [];
foreach (array_merge($keepConfFiles, array_values($orphanConfFiles)) as $i => $file) {
    $real = realpath($file);
    if ($real === false) {
        $listedPaths = null;
        break;
    }
    $listedPaths[] = $real;
    if ($i < count($keepConfFiles)) {
        $keepPaths[] = $real;
    }
}
$missingIncludes = $listedPaths === null ? null : array_values(array_diff(zonesConfIncludes((string)$oldZonesConf), $listedPaths));
if ($missingIncludes === null || $missingIncludes !== []) {
    $releaseLock();
    sendJsonResponse(500, [
        'status' => 'error',
        'message' => 'Verzeichnisauflistung nicht zweifelsfrei vollständig – keine Änderung, kein Reload',
        'code' => 'listing_incomplete',
        'missing_includes' => $missingIncludes === null ? null : count($missingIncludes),
        'removed_conf' => [],
        'removed_zone_files' => []
    ]);
}

// === No-op: keine verwaisten .conf ===
if (empty($orphanConfFiles)) {
    $releaseLock();
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'Keine verwaisten Zonen gefunden, keine Änderung.',
        'removed_conf' => [],
        'removed_zone_files' => [],
        'rndc' => ''
    ] + ($as2 ? ['effect' => 'unchanged', 'zones' => []] : []));
}

// === A/B: neue zones.conf ohne verwaiste Includes im Speicher erzeugen ===
$includes = [];
foreach ($keepPaths as $real) {
    $includes[] = 'include "' . $real . '";';
}
$zonesConfContent = implode("\n", $includes) . "\n";
// Die Sicherung der bisherigen zones.conf (für Rollback) liegt bereits vollständig gelesen vor (E98, oben).

// === C: zones.conf atomar ersetzen (Temp-Datei im selben Verzeichnis + rename) ===
// Bei Fehler bleibt die bisherige zones.conf unverändert (kein Rollback nötig).
if (!writeTextFileAtomic($zonesConfPath, $zonesConfContent)) {
    $releaseLock();
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben von zones.conf']);
}

// === Verbindlicher named-checkconf-Preflight – VOR dem Löschen der Orphans und vor dem Reload ===
$preflight = $as2 ? agentCheckconfTimed($config) : checkBindConfig();
if (!$preflight['ok']) {
    $preflightTimeout = $as2 && $preflight['status'] !== 'ok';
    // Alte zones.conf wiederherstellen (atomar) bzw. entfernen, falls sie vorher nicht existierte.
    // Es wurden noch keine Orphan-Dateien gelöscht; kein Reload.
    $restored = $zonesConfExisted
        ? writeTextFileAtomic($zonesConfPath, $oldZonesConf, $oldZonesConfPerms)
        : (pathState($zonesConfPath) === 'absent' || @unlink($zonesConfPath));   // E99
    // AS1: wiederhergestellten Zustand dauerhaft machen
    $restored = $restored && syncDirectory(dirname($zonesConfPath));

    if (!$restored) {
        error_log('[zone_cleanup] ROLLBACK FEHLGESCHLAGEN: zones.conf konnte nach named-checkconf-Fehler nicht wiederhergestellt werden');
        $releaseLock();
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => 'named-checkconf fehlgeschlagen und Wiederherstellung der zones.conf fehlgeschlagen – manuelle Prüfung der BIND-Konfiguration erforderlich. Keine Orphans entfernt, kein Reload.',
            'rollback' => 'failed',
            'check_output' => trim($preflight['output']),
            'exit_code' => $preflight['exit_code']
        ] + ($as2 ? ['effect' => 'unknown'] : []));
    }

    // Diagnose: Ist der wiederhergestellte vorherige Zustand gültig? (löst keinen Reload aus)
    $previous = $as2 ? agentCheckconfTimed($config) : checkBindConfig();
    $message = 'BIND-Konfigurationsprüfung (named-checkconf) fehlgeschlagen – zones.conf wiederhergestellt, keine Orphans entfernt, kein Reload';
    $response = [
        'status' => 'error',
        'check_output' => trim($preflight['output']),
        'exit_code' => $preflight['exit_code'],
        'rollback' => 'ok',
        'previous_config_valid' => $previous['ok'],
        'removed_conf' => [],
        'removed_zone_files' => []
    ];
    if (!$previous['ok']) {
        $message .= ' (Hinweis: auch der wiederhergestellte vorherige Zustand ist laut named-checkconf ungültig)';
        $response['previous_check_output'] = trim($previous['output']);
    }
    $response['message'] = $message;
    if ($as2) {
        $response['effect'] = 'none';   // zones.conf nachweislich wiederhergestellt, nichts gelöscht, kein Reload
        if ($preflightTimeout) {
            $response['code'] = 'check_timeout';
            $response['message'] = 'BIND-Konfigurationsprüfung nicht abgeschlossen (Frist) – zones.conf wiederhergestellt, keine Orphans entfernt, kein Reload';
        }
    }

    $releaseLock();
    sendJsonResponse($as2 && $preflightTimeout ? 503 : 422, $response);
}

// === D: erst jetzt pro Orphan zuerst die exakt gepaarte db.<zone>, danach die verwaiste .conf entfernen ===
// Reihenfolge db vor .conf: Die verwaiste .conf ist der einzige Orphan-Marker (db.* wird bewusst nicht
// gescannt). Scheitert das Löschen der db-Datei, bleibt die .conf bestehen, damit der nächste Cleanup
// denselben Orphan erneut erkennt und erneut versucht.
$removedConf = [];
$removedZoneFiles = [];
$errors = [];
$zoneResults = [];   // AS2a: Ergebnis je Zone (conf/file: removed | absent | kept | unknown)
foreach ($orphanConfFiles as $base => $confFile) {
    $zoneFile = "$zoneDir/db.$base";
    $zr = ['zone' => $base, 'conf' => 'kept', 'file' => 'kept'];
    // E99: Prüffehler ⇒ .conf als Orphan-Marker behalten (Retry beim nächsten Cleanup), nie als "entfernt" werten
    $zoneFileState = pathState($zoneFile);
    if ($zoneFileState === 'error') {
        $errors[] = "Verwaiste Zonendatei nicht prüfbar: " . basename($zoneFile);
        $zr['file'] = 'unknown';
        $zoneResults[] = $zr;
        continue;
    }
    if ($zoneFileState === 'present') {
        if (!@unlink($zoneFile) && ($after = pathState($zoneFile)) !== 'absent') {
            // .conf als Orphan-Marker behalten → Retry beim nächsten Cleanup
            $errors[] = "Verwaiste Zonendatei konnte nicht entfernt werden: " . basename($zoneFile);
            $zr['file'] = $after === 'present' ? 'kept' : 'unknown';
            $zoneResults[] = $zr;
            continue;
        }
        $removedZoneFiles[] = basename($zoneFile);
        $zr['file'] = 'removed';
    } else {
        $zr['file'] = 'absent';
    }

    if (!@unlink($confFile) && ($after = pathState($confFile)) !== 'absent') {
        // Bereits entfernte db-Datei wird nicht wiederhergestellt
        $errors[] = "Verwaiste Konfigurationsdatei konnte nicht entfernt werden: " . basename($confFile);
        $zr['conf'] = $after === 'present' ? 'kept' : 'unknown';
        $zoneResults[] = $zr;
        continue;
    }
    $removedConf[] = basename($confFile);
    $zr['conf'] = 'removed';
    $zoneResults[] = $zr;
}

// === AS1: Änderungen dauerhaft machen (fsync der Verzeichnisse) vor dem Reload ===
// Der Bestand ist bereits verändert; ein Fehler macht den Cleanup unvollständig (Fehlerantwort mit "rndc").
if (!syncDirectories([$confDir, $zoneDir, dirname($zonesConfPath)])) {
    $errors[] = 'Änderungen nicht dauerhaft bestätigt (fsync des Verzeichnisses fehlgeschlagen)';
}

// === E: genau ein BIND-Reload (zones.conf includiert die Orphans bereits nicht mehr) ===
if ($as2) {
    // AS2a (E93, E117): Exit-Code und Frist. zones.conf ist hier bereits ersetzt (nachgewiesene Änderung) – die
    // Wirkung ist daher nie none (Vorrangregel in cleanupAggregateEffect). Kein Rollback.
    $reload = agentReloadTimed($config);
    $releaseLock();
    $effect = cleanupAggregateEffect(true, $zoneResults);
    $reloadReport = ['status' => $reload['status'], 'exit_code' => $reload['exit_code']];
    if (!empty($errors) || $reload['status'] !== 'ok') {
        $codeMap = ['timeout' => 'reload_timeout', 'not_started' => 'reload_not_started', 'failed' => 'reload_failed'];
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => !empty($errors) ? 'Zonen-Cleanup unvollständig: nicht alle verwaisten Dateien konnten entfernt werden.'
                : 'Verwaiste Zonen entfernt, BIND-Reload nicht erfolgreich – Ladezustand unbestimmt',
            'code' => !empty($errors) ? 'cleanup_incomplete' : $codeMap[$reload['status']],
            'errors' => $errors,
            'removed_conf' => $removedConf,
            'removed_zone_files' => $removedZoneFiles,
            'rndc' => $reload['output'],
            'effect' => $effect,
            'rollback' => 'not_attempted',
            'zones' => $zoneResults,
            'reload' => $reloadReport
        ] + ($reload['status'] !== 'ok' ? ['bind_state' => 'unknown'] : []));
    }
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'Verwaiste Zonen entfernt, zones.conf neu geschrieben.',
        'removed_conf' => $removedConf,
        'removed_zone_files' => $removedZoneFiles,
        'rndc' => $reload['output'],
        'effect' => $effect,
        'zones' => $zoneResults,
        'reload' => $reloadReport
    ]);
}
$rndcOutput = reloadBind();

// === F: Lock freigeben ===
$releaseLock();

if (!empty($errors)) {
    sendJsonResponse(500, [
        'status' => 'error',
        'message' => 'Zonen-Cleanup unvollständig: nicht alle verwaisten Dateien konnten entfernt werden.',
        'errors' => $errors,
        'removed_conf' => $removedConf,
        'removed_zone_files' => $removedZoneFiles,
        'rndc' => trim($rndcOutput)
    ]);
}

sendJsonResponse(200, [
    'status' => 'success',
    'message' => 'Verwaiste Zonen entfernt, zones.conf neu geschrieben.',
    'removed_conf' => $removedConf,
    'removed_zone_files' => $removedZoneFiles,
    'rndc' => trim($rndcOutput)
]);
