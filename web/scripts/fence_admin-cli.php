#!/usr/bin/php
<?php
/**
 * Datei: fence_admin-cli.php
 * Zweck: Administrative Fencing-Operationen (AS4b, E74/O2, E81/O3). CLI-Adapter; Fachlogik in FenceAdminService.
 * Details:
 * - status: Domäne in Datenbank und Web-Konfiguration, nächste Lauf-ID, Fencing-Status der Agenten (nur lesend)
 * - init-domain --domain=<uuid>: Domäne der Installation einmalig setzen (nur wenn NULL; muss FENCE_DOMAIN entsprechen)
 * - raise-runs --above=<n>: nächste Lauf-ID nach einem Datenbank-Restore auf n + 1 anheben (nie senken; Recht ALTER)
 * - Schreibende Operationen unter dem Publish-Lock (Try-Lock; belegt ⇒ keine Änderung). Kein automatischer Abgleich.
 * - Nur CLI, nicht über Web aufrufbar (cliGuard vor dem Laden von Konfiguration und Datenbank).
 * Exitcodes: 0 erfolgreich (status: Domäne konsistent und kein Restore-Konflikt), 1 status mit Befund,
 *            2 Aufruffehler oder abgelehnt (keine Änderung), 3 Fehler bei der Ausführung
 */

require_once __DIR__ . '/../inc/helpers_cli.php';
cliGuard();

$cmd = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--(domain|above)=(.+)$/D', $a, $m) === 1) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Unbekannte Option: $a\n");
        exit(2);
    }
}
if (!in_array($cmd, ['status', 'init-domain', 'raise-runs'], true)
    || ($cmd === 'init-domain' && !isset($opt['domain'])) || ($cmd === 'raise-runs' && !isset($opt['above']))) {
    fwrite(STDERR, "Aufruf: php fence_admin-cli.php status | init-domain --domain=<uuid> | raise-runs --above=<n>\n");
    exit(2);
}

require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';
require_once __DIR__ . '/../inc/app/publish/FenceAdminService.php';
require_once __DIR__ . '/../inc/deploy/LegacyBindPublishBackend.php';

if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    appLog('error', 'PDO nicht verfügbar – fence_admin abgebrochen');
    exit(2);
}
$pdo = $GLOBALS['pdo'];
$fence = FenceContext::fromConfig();
$configured = defined('FENCE_DOMAIN') && is_string(FENCE_DOMAIN) ? FENCE_DOMAIN : null;
$agents = $cmd === 'status' ? new LegacyBindPublishBackend($pdo) : null;
$service = new FenceAdminService($pdo, $fence, $configured, $agents);

try {
    switch ($cmd) {
        case 'status':
            $s = $service->status();
            echo 'Domäne Datenbank:   ' . ($s['dbDomain'] ?? 'NULL (nicht initialisiert)') . "\n";
            echo 'Domäne Konfiguration: ' . ($s['configDomain'] ?? 'nicht gesetzt (FENCE_DOMAIN)') . "\n";
            echo 'Abgleich: ' . ($s['consistent'] ? 'ok' : 'NICHT konsistent – kein Publish möglich') . "\n";
            echo "Nächste Lauf-ID: {$s['nextRunId']}\n";
            foreach ($s['agents'] as $sid => $a) {
                echo "Agent {$a['name']} (ID $sid): " . ($a['reachable'] ? 'erreichbar' : 'nicht erreichbar')
                    . ', Modus ' . ($a['mode'] ?? 'legacy/off') . ', Höchststand ' . ($a['high'] ?? '–')
                    . (isset($s['servers'][$sid]) ? ' – ' . $s['servers'][$sid] : '') . "\n";
            }
            echo "Restore-Abgleich: {$s['restore']}\n";
            exit($s['consistent'] && $s['restore'] === 'ok' ? 0 : 1);
        case 'init-domain':
            $service->initDomain((string)$opt['domain']);
            appLog('info', '[fence] Domäne der Installation initialisiert');
            echo "OK: Domäne gesetzt\n";
            exit(0);
        case 'raise-runs':
            if (preg_match('/^[1-9][0-9]{0,18}$/D', (string)$opt['above']) !== 1) {
                fwrite(STDERR, "--above erwartet eine positive Ganzzahl\n");
                exit(2);
            }
            $next = $service->raiseRuns((int)$opt['above']);
            appLog('info', "[fence] Nächste Lauf-ID auf $next angehoben (E81)");
            echo "OK: nächste Lauf-ID $next\n";
            exit(0);
    }
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
