<?php
/**
 * API-Endpunkt: /api/v1/zones/zone_sync.php
 *
 * Zweck:
 * - Empfängt eine base64-kodierte Zonendatei (`db.zone`)
 * - Validiert sie mit named-checkzone
 * - Speichert sie unter /etc/bind/zones/
 * - Führt einen BIND-Reload durch
 * - Entfernt KEINE anderen Zonendateien (Orphan-Cleanup erfolgt ausschließlich über zone_cleanup.php)
 *
 * AS2a (Modi observe/enforce; docs/ARCHITECTURE_HISTORY.md Abschnitt 5f): named-checkzone mit Exit-Code (E94),
 * Vorher-Prüfung von Dateizustand, Konfiguration und ausgelieferter Serial nach E103 (I-A1, E80), Prozessfristen
 * (E117), Exit-Code des Reloads (E93) und Wirkungsangabe effect. Nach dem Reload gibt es keinen Rollback
 * (Vorwärtsstrategie). Im Modus off ist das Verhalten unverändert (E95, E101).
 *
 * Sicherheit:
 * - Zugriff nur mit gültigem Bearer-Token
 * - Kein Datenbankzugriff erforderlich
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

// AS2a: neue Prüfungen nur in observe/enforce (ein unbekannter Modus wird von agentFenceCheck abgelehnt)
$as2 = fenceMode($config) !== 'off';
if ($as2) {
    agentResponseAttach('effect', 'none');   // bis zur ersten nachgewiesenen Änderung
}
// E118: fehlender oder ungültiger fencing_mode ⇒ früher Abbruch (fence_misconfigured, 503) vor jeder Prüfung, jedem
// externen Prozess und jeder Änderung; kein stiller Rückfall auf off
agentRejectMisconfiguredMode($config);

// === Eingabe prüfen ===
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['zone_id'], $input['zone_name'], $input['zone_data'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültige Eingabeparameter']);
}

$zoneName = $input['zone_name'];
if ($as2 && !agentPosixAvailable()) {
    // E100: ohne ext-posix weder Existenzprüfung noch Prozessfristen – Ablehnung wie in agentFenceCheck(), noch vor
    // der ersten Prüfung (sonst würde named-checkzone als "nicht abgeschlossen" gemeldet)
    $fence = agentFenceCheck($config, $input);
    sendJsonResponse($fence['http'], (array)$fence['body']);
}
if ($as2 && dnsNormName($zoneName) === null) {
    // Der Zonenname wird als Argument externer Programme verwendet (ohne Shell) – nur DNS-Namensform zulässig
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Ungültiger Zonenname', 'code' => 'zone_name_invalid']);
}
$safeName = safeZoneName($zoneName);

$zoneData = base64_decode($input['zone_data']);
if ($zoneData === false) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Fehler beim Dekodieren der Zonendaten']);
}

// === Dateipfade vorbereiten ===
$zoneDir = $config['zone_data_dir'];
try {
    // Eindeutiger Temp-Name pro Request, damit parallele Requests sich nicht überschreiben. Im Zielverzeichnis
    // (gleiches Dateisystem), damit die spätere Übernahme per rename() atomar ist (S34b, SP4-E2); als Punktdatei
    // weder Zonendatei für BIND noch Ziel von zone_cleanup.
    $tmpPath = rtrim($zoneDir, '/') . "/.db.$safeName." . bin2hex(random_bytes(8)) . ".tmp";
} catch (Throwable $e) {
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erzeugen des temporären Dateinamens']);
}
$finalPath = "$zoneDir/db.$safeName";
$lockPath = agentLockPath($config);

// === Temporäre Datei schreiben (AS1: vollständig und mit fsync) ===
if (!writeFileDurable($tmpPath, $zoneData)) {
    @unlink($tmpPath);
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Schreiben der temporären Datei']);
}

// === named-checkzone auf temporäre Datei anwenden ===
$newSerial = null;
if ($as2) {
    // AS2a (E94): Exit-Code und genau eine Serial; Frist (E117)
    $check = dnsCheckzone($config, $zoneName, $tmpPath);
    if ($check['state'] === 'timeout') {
        @unlink($tmpPath);
        sendJsonResponse(503, ['status' => 'error', 'message' => 'Zonenprüfung nicht abgeschlossen (Frist) – keine Änderung', 'code' => 'check_timeout']);
    }
    if ($check['state'] !== 'valid') {
        @unlink($tmpPath);
        sendJsonResponse(422, [
            'status' => 'error',
            'message' => 'Zonenprüfung fehlgeschlagen',
            'check_output' => trim($check['output'])
        ]);
    }
    $newSerial = (int)$check['serial'];
} else {
    $check_output = validateZoneFile($zoneName, $tmpPath);
    if (!str_contains($check_output, 'loaded serial')) {
        @unlink($tmpPath);
        sendJsonResponse(422, [
            'status' => 'error',
            'message' => 'Zonenprüfung fehlgeschlagen',
            'check_output' => trim($check_output)
        ]);
    }
}

// === Globalen exklusiven Lock erwerben (Übernahme und Reload serialisieren) ===
if ($as2) {
    // AS2a (E117): nicht blockierend mit Frist; es müssen mindestens 8 s Arbeitszeit verbleiben
    $lock = agentLockWithDeadline($lockPath, 8.0);
    $lockHandle = $lock['handle'];
    if ($lockHandle === null) {
        @unlink($tmpPath);
        if ($lock['reason'] === 'timeout') {
            sendJsonResponse(503, ['status' => 'error', 'message' => 'Agent-Sperre innerhalb der Frist nicht erhalten – keine Änderung', 'code' => 'lock_timeout']);
        }
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für die Zonenübernahme']);
    }
} else {
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        @unlink($tmpPath);
        sendJsonResponse(500, ['status' => 'error', 'message' => 'Fehler beim Erwerben des Sperrmechanismus für die Zonenübernahme']);
    }
}

// === AS1: Fencing-Prüfung unter dem Lock, vor jeder Änderung (F3) ===
$fence = agentFenceCheck($config, $input);
if (!$fence['allow']) {
    @unlink($tmpPath);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    sendJsonResponse($fence['http'], $fence['body']);
}

// === AS2a: Vorher-Prüfung nach E103 (Datei, Konfiguration, ausgelieferte Serial; I-A1, E80) ===
$idempotent = false;
if ($as2) {
    $pre = agentPreTransferGate($config, fenceMode($config), $zoneName, $newSerial, $zoneData, $input['repair'] ?? null);
    agentResponseAttach('serial_check', $pre['report']);
    if ($pre['code'] !== null) {
        error_log(sprintf('[serial] zone=%s decision=%s code=%s row=%s', $safeName, $pre['report']['decision'], $pre['code'], $pre['report']['row']));
    }
    if (!$pre['allow']) {
        @unlink($tmpPath);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        sendJsonResponse($pre['http'], ['status' => 'error', 'message' => $pre['message'], 'code' => $pre['code']]);
    }
    $idempotent = $pre['idempotent'];
}

// === named-checkconf-Preflight (unter dem Lock, vor Übernahme und Reload) ===
$preflight = $as2 ? agentCheckconfTimed($config) : checkBindConfig();
if (!$preflight['ok']) {
    @unlink($tmpPath);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    if ($as2 && $preflight['status'] !== 'ok') {
        sendJsonResponse(503, ['status' => 'error', 'message' => 'BIND-Konfigurationsprüfung nicht abgeschlossen (Frist) – keine Änderung, kein Reload', 'code' => 'check_timeout']);
    }
    sendJsonResponse(422, [
        'status' => 'error',
        'message' => 'BIND-Konfigurationsprüfung (named-checkconf) fehlgeschlagen – Zonendatei nicht übernommen, kein Reload',
        'check_output' => trim($preflight['output']),
        'exit_code' => $preflight['exit_code']
    ]);
}

if ($idempotent) {
    // AS2a: gleiche Serial, byte-identischer Inhalt – keine Übernahme nötig (Bestand unverändert), Reload wie bisher
    @unlink($tmpPath);
} else {
    // === Zonendatei übernehmen ===
    if (!rename($tmpPath, $finalPath)) {
        @unlink($tmpPath);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        sendJsonResponse(500, ['status' => 'error', 'message' => "Fehler beim Verschieben nach $finalPath"]);
    }

    // === AS1: Übernahme dauerhaft machen (fsync des Zonenverzeichnisses) ===
    // Scheitert das, ist die Datei ersetzt, aber nicht dauerhaft bestätigt und nicht zurücknehmbar: kein Reload,
    // Fehlerantwort mit rollback = failed (Ergebnis unbestimmt). AS2a (observe/enforce): rollback = not_attempted
    // (es wurde kein Rollback versucht), effect = unknown.
    if (!syncDirectory($zoneDir)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => "Zonendatei für $zoneName ersetzt, aber nicht dauerhaft bestätigt (fsync des Verzeichnisses fehlgeschlagen) – kein Reload",
            'code' => 'persist_unconfirmed',
            'rollback' => $as2 ? 'not_attempted' : 'failed'
        ] + ($as2 ? ['effect' => 'unknown'] : []));
    }
}

// === BIND reload durchführen ===
if ($as2) {
    // AS2a (E93, E117): Exit-Code und Frist; ein erfolgreicher Reload ist kein Nachweis der Übernahme durch BIND.
    // Kein Rollback nach dem Reload-Versuch (Vorwärtsstrategie): Die geprüfte Datei bleibt.
    $reload = agentReloadTimed($config);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    $effect = $idempotent ? 'unchanged' : 'applied';
    $installed = ['serial' => $newSerial, 'sha256' => hash('sha256', $zoneData)];
    $reloadReport = ['status' => $reload['status'], 'exit_code' => $reload['exit_code']];
    if ($reload['status'] !== 'ok') {
        $codeMap = ['timeout' => 'reload_timeout', 'not_started' => 'reload_not_started'];
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => "Zonendatei für $zoneName übernommen, BIND-Reload nicht erfolgreich – Ladezustand unbestimmt",
            'code' => $codeMap[$reload['status']] ?? 'reload_failed',
            'effect' => $effect,
            'rollback' => 'not_attempted',
            'bind_state' => 'unknown',
            'reload' => $reloadReport,
            'installed' => $installed,
            'rndc' => $reload['output']
        ]);
    }
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => "Zonendatei für $zoneName übernommen.",
        'rndc' => $reload['output'],
        'effect' => $effect,
        'reload' => $reloadReport,
        'installed' => $installed
    ]);
}

$rndcOutput = reloadBind();

// === Lock freigeben ===
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

// === Erfolgsantwort senden ===
sendJsonResponse(200, [
    'status' => 'success',
    'message' => "Zonendatei für $zoneName übernommen.",
    'rndc' => trim($rndcOutput)
]);
