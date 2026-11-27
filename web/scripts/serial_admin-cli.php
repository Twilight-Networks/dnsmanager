#!/usr/bin/php
<?php
/**
 * Datei: serial_admin-cli.php
 * Zweck: Administrative Serial-Referenzen (AS5, D17/D25; docs/ARCHITECTURE_HISTORY.md Abschnitt 5k). CLI-Adapter; Fachlogik
 *        in SerialReferenceAdminService.
 * Details:
 * - status [--zone=<name>]: je Zone DB-Serial, Zielserver, verbindliche Referenzen (F/D), Sperren, Entscheidung der
 *   SerialPolicy (wie im Publish), ruhende Paare, Publish-Sperre (fehlende FENCE_DOMAIN). Nur lesend.
 * - record --zone-id=<id> --zone=<name> --server-id=<id> --server=<name> (--file=<serial> | --served=<serial>)
 *   --evidence=<text>: tatsächlich erhobene Datei- bzw. ausgelieferte Serial ausschließlich hinzufügend erfassen (unter dem
 *   Publish-Lock, Audit-Ereignis). Keine Änderung der DB-Serial, kein Löschen, kein Publish, kein L3-Nachweis.
 * - Nur CLI, nicht über Web aufrufbar (cliGuard vor dem Laden von Konfiguration und Datenbank).
 * Exitcodes: 0 erfolgreich (status: keine Sperre, jede Zone auflösbar), 1 status mit Befund,
 *            2 Aufruffehler oder abgelehnt (keine Änderung), 3 Fehler bei der Ausführung
 */

require_once __DIR__ . '/../inc/helpers_cli.php';
cliGuard();

$cmd = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--(zone|zone-id|server|server-id|file|served|evidence)=(.*)$/sD', $a, $m) === 1 && !isset($opt[$m[1]])) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Unbekannte oder doppelte Option: $a\n");
        exit(2);
    }
}
$usage = "Aufruf: php serial_admin-cli.php status [--zone=<name>]\n"
    . "       php serial_admin-cli.php record --zone-id=<id> --zone=<name> --server-id=<id> --server=<name> (--file=<serial> | --served=<serial>) --evidence=<text>\n";
$isId = static fn (?string $v): bool => is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1;
if (!in_array($cmd, ['status', 'record'], true)
    || ($cmd === 'status' && array_diff(array_keys($opt), ['zone']) !== [])
    || ($cmd === 'record' && (!$isId($opt['zone-id'] ?? null) || !$isId($opt['server-id'] ?? null) || !isset($opt['zone'], $opt['server'], $opt['evidence'])
        || (isset($opt['file']) === isset($opt['served']))))) {
    fwrite(STDERR, $usage);
    exit(2);
}

require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';
require_once __DIR__ . '/../inc/app/publish/SerialReferenceAdminService.php';

if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    appLog('error', 'PDO nicht verfügbar – serial_admin abgebrochen');
    exit(2);
}
$pdo = $GLOBALS['pdo'];
$service = new SerialReferenceAdminService($pdo, FenceContext::fromConfig());

try {
    if ($cmd === 'status') {
        $s = $service->status($opt['zone'] ?? null);
        echo 'Publish-Sperre: ' . ($s['publishBlocked'] ? 'AKTIV – ' . $s['publishBlockReason'] : 'nicht aktiv') . "\n";
        echo 'AS5-Epoche: ' . ($s['epoch'] === null ? 'noch keine (nur AS4-Historie)' : "Wasserstand {$s['epoch']['run_key']} seit {$s['epoch']['recorded_at']}") . "\n";
        $finding = false;
        foreach ($s['zones'] as $z) {
            $d = $z['decision'];
            echo "\nZone {$z['name']} (ID {$z['id']}): DB-Serial {$z['db']}" . ($z['changed'] !== 0 ? ', ausstehend' : '')
                . ($z['latestAttempt'] !== null ? ", zuletzt registriert {$z['latestAttempt']['serial']} (Versuch {$z['latestAttempt']['id']})" : '') . "\n";
            foreach ($z['pairs'] as $sid => $p) {
                echo "  Ziel {$p['name']} (ID $sid): F {" . implode(', ', $p['F']) . '}, D {' . implode(', ', $p['D']) . '}' . "\n";
                foreach ($p['blocks'] as $b) {
                    echo "    SPERRE {$b['reason']}: {$b['detail']}\n";
                }
            }
            echo '  Referenzmenge R: {' . implode(', ', $z['refs']) . "}\n";
            if ($d['ok']) {
                echo "  Nächste Serial: {$d['serial']} ({$d['basis']})\n";
            } else {
                $finding = true;
                echo "  KEINE sichere Auswahl: {$d['reason']}" . (($d['detail'] ?? '') !== '' ? " – {$d['detail']}" : '') . "\n";
            }
        }
        if ($s['dormant'] !== []) {
            echo "\nRuhende Paare (kein aktuelles Ziel; binden erst bei erneuter Zuweisung):\n";
            foreach ($s['dormant'] as $p) {
                echo "  {$p['zone']} auf {$p['serverName']} (ID {$p['serverId']}): {$p['rows']} Einträge\n";
            }
        }
        exit($finding ? 1 : 0);
    }
    $id = $service->record((int)$opt['zone-id'], (string)$opt['zone'], (int)$opt['server-id'], (string)$opt['server'],
        $opt['file'] ?? null, $opt['served'] ?? null, (string)$opt['evidence']);
    appLog('info', "[serial] Administrative Serial-Referenz $id erfasst (Zone {$opt['zone']}, Server {$opt['server']})");
    echo "OK: Referenz $id erfasst (hinzufügend; keine Änderung der DB-Serial, kein Publish)\n";
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . "\n");
    exit(3);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'ABGELEHNT: ' . $e->getMessage() . "\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . "\n");
    exit(3);
}
