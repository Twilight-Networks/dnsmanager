<?php
/**
 * S42-E4 – einmaliger Einzelzonen-Publish für die Abnahme (nur ns1, nur Testzone zone.example.test).
 *
 * Ruft ausschließlich den vorhandenen PublishService::publishZone() auf – dieselbe Zonenlogik, dieselbe
 * Publish-Sperre (GET_LOCK 'dnsmanager_publish_all') und dieselbe Flag-Verwaltung wie der DynDNS-Publish.
 * Kein Publish All: kein Orphan-Cleanup, kein bind_dirty-Neuaufbau, keine anderen Zonen.
 *
 * Autorisierung (publishZone() ist eine interne Systemoperation ohne Benutzerbezug; die Berechtigung prüft der
 * Aufrufer, hier dieses Skript):
 * - nur CLI; nur als www-data (wie Webserver und DynDNS-Publish), und nur von root per sudo gestartet
 *   (SUDO_UID = 0) – also nur durch einen root-berechtigten Betreiber auf ns1;
 * - nur die fest eingetragene Testzone (ID UND Name müssen übereinstimmen, zusätzlich als Argumente bestätigt);
 * - Ausführung nur mit --execute; ohne Argument bzw. mit --dry-run nur lesende Vorprüfung.
 *
 * Vorprüfungen (lesend, vor jedem Lock): Zone vorhanden, Name passt, keine DynDNS-Freigabe und keine
 * DynDNS-Konten, Änderung ausstehend (changed 1|2), zugewiesene aktive Server genau die erwarteten Test-VM-Agenten
 * (kein anderes Ziel), keine weitere aktive Server-Adresse, Publish-Sperre frei. Jede Abweichung: Abbruch (Exit 2).
 *
 * Produktionskonfiguration: wird nur gelesen (ui_config.php über inc/db.php); keine Konfigurationswerte, Tokens
 * oder Zugangsdaten werden ausgegeben. Protokollierung über appLog() wie im Webpfad.
 *
 * Aufruf (als root auf ns1):
 *   sudo -u www-data php s42-publish-zone.php 3 zone.example.test --dry-run
 *   sudo -u www-data php s42-publish-zone.php 3 zone.example.test --execute
 * Exit-Codes: 0 = veröffentlicht (bzw. Vorprüfung bestanden bei --dry-run), 1 = Publish nicht erfolgreich,
 *             2 = Vorprüfung nicht bestanden, 3 = Aufruf/Autorisierung abgelehnt.
 */

// --- Fest eingetragene Abnahmeparameter (Änderung nur mit neuer Freigabe) ---
const APP_ROOT = '/usr/share/dnsmanager/web';
const ZONE_ID = 3;
const ZONE_NAME = 'zone.example.test';
/** Erwartete aktive Agent-Adressen der Testzone (ns1 lokal, ns2) – keine anderen Ziele zulässig */
const EXPECTED_AGENTS = ['127.0.0.1', '192.168.53.125'];
const LOCK_WAIT_SECONDS = 5;

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function stop(int $code, string $s): never { fwrite(STDOUT, "ABBRUCH: $s\n"); exit($code); }

// --- Aufruf und Autorisierung ---
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Zugriff verweigert.\n"); }
$user = posix_getpwuid(posix_geteuid())['name'] ?? '';
if ($user !== 'www-data') { stop(3, "muss als www-data laufen (sudo -u www-data …), läuft als '$user'"); }
if (getenv('SUDO_UID') !== '0') { stop(3, 'muss von root per sudo gestartet werden'); }
$args = array_slice($argv, 1);
$mode = $args[2] ?? '--dry-run';
if (count($args) < 2 || count($args) > 3 || !in_array($mode, ['--dry-run', '--execute'], true)) {
    stop(3, 'Aufruf: s42-publish-zone.php <Zonen-ID> <Zonenname> [--dry-run|--execute]');
}
if ($args[0] !== (string)ZONE_ID || $args[1] !== ZONE_NAME) {
    stop(3, 'nur die freigegebene Testzone ' . ZONE_ID . ' ' . ZONE_NAME . ' ist zulässig');
}

// --- Produktionskonfiguration und Abhängigkeiten (nur lesen) ---
require_once APP_ROOT . '/inc/db.php';            // lädt config/ui_config.php, stellt $pdo bereit
require_once APP_ROOT . '/inc/logging.php';
require_once APP_ROOT . '/inc/app/publish/PublishService.php';
require_once APP_ROOT . '/inc/deploy/LegacyBindPublishBackend.php';
date_default_timezone_set('UTC');
/** @var PDO $pdo */
if (!($pdo instanceof PDO) || (int)$pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
    stop(2, 'keine PDO-Verbindung im Exception-Modus');
}

// --- Vorprüfungen (lesend, ohne Lock) ---
$z = $pdo->prepare('SELECT id, name, changed, allow_dyndns, soa_serial FROM zones WHERE id = ?');
$z->execute([ZONE_ID]);
$zone = $z->fetch(PDO::FETCH_ASSOC);
if ($zone === false) { stop(2, 'Zone ' . ZONE_ID . ' nicht vorhanden'); }
if ($zone['name'] !== ZONE_NAME) { stop(2, 'Zonenname in der Datenbank weicht ab: ' . $zone['name']); }
out('Zone: ' . ZONE_ID . ' ' . ZONE_NAME . ", changed={$zone['changed']}, allow_dyndns={$zone['allow_dyndns']}, Serial (DB)={$zone['soa_serial']}");
if ((int)$zone['allow_dyndns'] !== 0) { stop(2, 'Zone ist für DynDNS freigegeben'); }
$d = $pdo->prepare('SELECT COUNT(*) FROM dyndns_accounts WHERE zone_id = ?');
$d->execute([ZONE_ID]);
if ((int)$d->fetchColumn() !== 0) { stop(2, 'Zone hat DynDNS-Konten'); }

$s = $pdo->prepare('SELECT s.id, s.name, s.api_ip FROM zone_servers zs JOIN servers s ON s.id = zs.server_id WHERE zs.zone_id = ? AND s.active = 1 ORDER BY s.id');
$s->execute([ZONE_ID]);
$targets = $s->fetchAll(PDO::FETCH_ASSOC);
$ips = array_map(fn($r) => (string)$r['api_ip'], $targets);
foreach ($targets as $t) { out("Ziel: Server {$t['id']} {$t['name']} api_ip={$t['api_ip']}"); }
$sortedIps = $ips; sort($sortedIps); $expected = EXPECTED_AGENTS; sort($expected);
if ($sortedIps !== $expected) { stop(2, 'aktive Ziele der Zone entsprechen nicht genau den erwarteten Test-VM-Agenten (' . implode(', ', EXPECTED_AGENTS) . ')'); }
$all = array_map('strval', $pdo->query('SELECT api_ip FROM servers WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN));
$foreign = array_values(array_diff($all, EXPECTED_AGENTS));
if ($foreign !== []) { stop(2, 'weitere aktive Server-Adressen vorhanden: ' . implode(', ', $foreign)); }
$others = (int)$pdo->query('SELECT COUNT(*) FROM zones WHERE changed <> 0 AND id <> ' . ZONE_ID)->fetchColumn();
out("Weitere Zonen mit ausstehender Änderung (von publishZone nicht berührt): $others");
$free = $pdo->query("SELECT IS_FREE_LOCK('" . PublishService::LOCK_NAME . "')")->fetchColumn();
if ((int)$free !== 1) { stop(2, 'Publish-Sperre ' . PublishService::LOCK_NAME . ' ist belegt (laufender Publish)'); }
out('Publish-Sperre ' . PublishService::LOCK_NAME . ': frei');

// zuletzt, damit ein Probelauf ohne ausstehende Änderung alle übrigen Prüfungen zeigt
if (!in_array((int)$zone['changed'], [1, 2], true)) { stop(2, 'keine ausstehende Änderung in der Zone (changed = 0) – nichts zu veröffentlichen'); }

if ($mode === '--dry-run') {
    out('VORPRÜFUNG BESTANDEN (--dry-run, nichts ausgeführt)');
    exit(0);
}

// --- Ausführung: vorhandener Einzelzonen-Publish ---
appLog('info', 'S42-Abnahme: Einzelzonen-Publish per CLI gestartet (Zone ' . ZONE_ID . ' ' . ZONE_NAME . ', Aufrufer root via sudo)');
$service = new PublishService(
    $pdo,
    new LegacyBindPublishBackend($pdo),
    static function (string $level, string $message): void {
        appLog($level, $message);
    }
);
$result = $service->publishZone(ZONE_ID, LOCK_WAIT_SECONDS);

$final = null;
foreach ($result->messages as $m) {
    if ($m->code === 'publish.zone_result') { $final = $m->context; continue; }
    out("Meldung {$m->kind} {$m->code}: " . json_encode($m->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if ($final === null) { stop(1, 'kein Ergebnis von publishZone()'); }
out('Ergebnis: status=' . $final['status'] . ', changedReset=' . json_encode($final['changedReset']) . ', keptPending=' . json_encode($final['keptPending'])
    . ', Lock erhalten=' . json_encode($final['lock']['acquired']) . ', freigegeben=' . json_encode($final['lock']['released']));
foreach ($final['errors'] as $e) { out("Fehler: $e"); }
foreach ($final['warnings'] as $w) { out("Warnung: $w"); }
$z->execute([ZONE_ID]);
$after = $z->fetch(PDO::FETCH_ASSOC);
out("Danach: changed={$after['changed']}, Serial (DB)={$after['soa_serial']}");
appLog($result->ok ? 'info' : 'error', 'S42-Abnahme: Einzelzonen-Publish beendet, status=' . $final['status']);
exit($final['status'] === 'published' ? 0 : 1);
