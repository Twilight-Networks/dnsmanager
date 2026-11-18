#!/usr/bin/php
<?php
/**
 * Datei: history_baseline-cli.php
 * Zweck: Baseline-Initialisierung der Änderungshistorie für bestehende Zonen (AP1/H2a, E24) und Abgleich der Heads
 *        gelöschter Zonen (AP1/H2b, E36).
 *
 * Details:
 * - CLI-Adapter: Die Fachlogik liegt in HistoryBaselineService (inc/app/history).
 * - Voraussetzung: Migration 002 (sql/migrations/002_history_foundation.sql) ist angewendet.
 * - Je Zone ohne History-Head entsteht eine Revision "baseline" mit Status "baseline_unverified". Es wird keine
 *   frühere Historie konstruiert und kein Veröffentlichungsstand angenommen. Fachdaten, Serials, Publish-Flags und
 *   Agenten bleiben unverändert. Wiederholbar: Zonen mit Head werden übersprungen.
 * - Läuft unter dem Advisory-Lock 'dnsmanager_publish_all' (Try-Lock); ist er belegt, endet der Lauf ohne Änderung.
 * - Nur CLI, nicht über Web aufrufbar (cliGuard vor dem Laden von Konfiguration und Datenbank).
 *
 * Aufruf:
 *   php history_baseline-cli.php --check      Zonen ohne History-Head auflisten (ändert nichts)
 *   php history_baseline-cli.php --dry-run    Baseline je Zone berechnen, nichts schreiben
 *   php history_baseline-cli.php --apply      Baseline schreiben
 *   php history_baseline-cli.php --reconcile-dry-run   Heads gelöschter Zonen ermitteln, nichts schreiben (AP1/H2b, E36)
 *   php history_baseline-cli.php --reconcile           Heads gelöschter Zonen als gelöscht kennzeichnen (Revision "delete")
 *
 * Exitcodes:
 *   0  --check: alle Zonen haben einen Head; übrige Modi: erfolgreich
 *   1  --check: mindestens eine Zone ohne Head
 *   2  Aufruffehler, Schema nicht passend, Lock belegt oder Prüffehler (keine Änderung)
 *   3  Abbruch während --apply bzw. --reconcile (abgeschlossene Zonen bleiben bestehen; erneut ausführen)
 */

require_once __DIR__ . '/../inc/helpers_cli.php';
cliGuard();

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--check', '--dry-run', '--apply', '--reconcile-dry-run', '--reconcile'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Aufruf: php history_baseline-cli.php --check|--dry-run|--apply|--reconcile-dry-run|--reconcile\n");
    exit(2);
}

require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';
require_once __DIR__ . '/../inc/app/history/HistoryBaselineService.php';

date_default_timezone_set('UTC');

if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    appLog('error', 'PDO nicht verfügbar – History-Baseline abgebrochen');
    exit(2);
}

$service = new HistoryBaselineService($GLOBALS['pdo']);

if ($mode === '--check') {
    $result = $service->status();
    $exit = 2;
    foreach ($result->messages as $m) {
        $ctx = $m->context;
        switch ($m->code) {
            case 'history.baseline.status':
                echo "Zonen: {$ctx['total']}, mit History-Head: {$ctx['withHead']}, ohne: " . count($ctx['missing']) . "\n";
                foreach ($ctx['missing'] as $z) {
                    echo "  ohne Head: {$z['id']} {$z['name']}\n";
                }
                $exit = $ctx['missing'] === [] ? 0 : 1;
                break;
            case 'history.baseline.schema_incompatible':
                echo "[Fehler] Schema nicht passend: " . implode('; ', $ctx['problems']) . "\n";
                break;
            case 'history.baseline.status_failed':
                echo "[Fehler] Abfrage fehlgeschlagen ({$ctx['errorClass']} {$ctx['errorCode']})\n";
                break;
            default:
                echo "[Fehler] Unbehandelte Meldung {$m->code}\n";
                appLog('error', "event=history_baseline unbehandelte_meldung={$m->code}");
        }
    }
    exit($exit);
}

if ($mode === '--reconcile' || $mode === '--reconcile-dry-run') {
    $apply = $mode === '--reconcile';
    $result = $service->reconcile($apply);
    $exit = $result->ok ? 0 : 2;
    foreach ($result->messages as $m) {
        $ctx = $m->context;
        switch ($m->code) {
            case 'history.reconcile.zone_planned':
                echo "geplant:       {$ctx['zoneId']} {$ctx['zoneName']} revision={$ctx['revisionNo']} (Zone gelöscht)
";
                break;
            case 'history.reconcile.zone_recorded':
                echo "gekennzeichnet: {$ctx['zoneId']} {$ctx['zoneName']} revision={$ctx['revisionNo']}
";
                appLog('info', "event=history_reconcile_zone zone_id={$ctx['zoneId']} zone={$ctx['zoneName']} revision={$ctx['revisionNo']} change_set={$ctx['changeSetId']}");
                break;
            case 'history.reconcile.success':
                $line = "mode={$ctx['mode']} heads={$ctx['total']} gekennzeichnet={$ctx['recorded']} geplant={$ctx['planned']} lauf={$ctx['correlationId']}";
                echo "OK: $line
";
                appLog('info', "event=history_reconcile_done $line");
                break;
            case 'history.reconcile.failed':
                $exit = $apply ? 3 : 2;
                $zone = $ctx['zoneId'] ?? '-';
                echo "[Fehler] Abbruch bei Zone $zone: {$ctx['errorClass']} {$ctx['errorCode']} {$ctx['detail']}
";
                appLog('error', "event=history_reconcile_failed zone_id=$zone error={$ctx['errorClass']} code={$ctx['errorCode']}");
                break;
            case 'history.reconcile.locked':
                echo "[Fehler] Advisory-Lock dnsmanager_publish_all belegt (Publish oder Topologieänderung aktiv) – keine Änderung
";
                appLog('warning', 'event=history_reconcile_locked');
                break;
            case 'history.reconcile.lock_failed':
                echo "[Fehler] Advisory-Lock nicht beziehbar – keine Änderung
";
                appLog('error', 'event=history_reconcile_lock_failed');
                break;
            case 'history.reconcile.schema_incompatible':
                echo "[Fehler] Schema nicht passend: " . implode('; ', $ctx['problems']) . "
";
                appLog('error', 'event=history_reconcile_schema_incompatible detail=' . implode('; ', $ctx['problems']));
                break;
            case 'history.reconcile.in_transaction':
                echo "[Fehler] Interner Fehler: offene Transaktion
";
                break;
            default:
                $exit = $exit === 0 ? 2 : $exit;
                echo "[Fehler] Unbehandelte Meldung {$m->code}
";
                appLog('error', "event=history_reconcile unbehandelte_meldung={$m->code}");
        }
    }
    exit($exit);
}

$apply = $mode === '--apply';
$result = $service->run($apply);
$exit = $result->ok ? 0 : 2;

foreach ($result->messages as $m) {
    $ctx = $m->context;
    switch ($m->code) {
        case 'history.baseline.zone_planned':
            echo "geplant:       {$ctx['zoneId']} {$ctx['zoneName']} records={$ctx['recordCount']} hash={$ctx['contentHash']}\n";
            break;
        case 'history.baseline.zone_recorded':
            echo "angelegt:      {$ctx['zoneId']} {$ctx['zoneName']} revision={$ctx['revisionNo']} records={$ctx['recordCount']} hash={$ctx['contentHash']}\n";
            appLog('info', "event=history_baseline_zone zone_id={$ctx['zoneId']} zone={$ctx['zoneName']} revision={$ctx['revisionNo']} change_set={$ctx['changeSetId']} hash={$ctx['contentHash']}");
            break;
        case 'history.baseline.zone_skipped':
            $name = $ctx['zoneName'] ?? '';
            echo "übersprungen:  {$ctx['zoneId']} $name ({$ctx['reason']})\n";
            break;
        case 'history.baseline.success':
            $line = "mode={$ctx['mode']} zonen={$ctx['total']} angelegt={$ctx['recorded']} geplant={$ctx['planned']} übersprungen={$ctx['skipped']} lauf={$ctx['correlationId']}";
            echo "OK: $line\n";
            appLog('info', "event=history_baseline_done $line");
            break;
        case 'history.baseline.failed':
            $exit = $apply ? 3 : 2;
            $zone = $ctx['zoneId'] ?? '-';
            echo "[Fehler] Abbruch bei Zone $zone: {$ctx['errorClass']} {$ctx['errorCode']} {$ctx['detail']}\n";
            appLog('error', "event=history_baseline_failed zone_id=$zone error={$ctx['errorClass']} code={$ctx['errorCode']}");
            break;
        case 'history.baseline.locked':
            echo "[Fehler] Advisory-Lock dnsmanager_publish_all belegt (Publish oder Topologieänderung aktiv) – keine Änderung\n";
            appLog('warning', 'event=history_baseline_locked');
            break;
        case 'history.baseline.lock_failed':
            echo "[Fehler] Advisory-Lock nicht beziehbar – keine Änderung\n";
            appLog('error', 'event=history_baseline_lock_failed');
            break;
        case 'history.baseline.schema_incompatible':
            echo "[Fehler] Schema nicht passend: " . implode('; ', $ctx['problems']) . "\n";
            appLog('error', 'event=history_baseline_schema_incompatible detail=' . implode('; ', $ctx['problems']));
            break;
        case 'history.baseline.in_transaction':
            echo "[Fehler] Interner Fehler: offene Transaktion\n";
            break;
        default:
            $exit = $exit === 0 ? 2 : $exit;
            echo "[Fehler] Unbehandelte Meldung {$m->code}\n";
            appLog('error', "event=history_baseline unbehandelte_meldung={$m->code}");
    }
}

exit($exit);
