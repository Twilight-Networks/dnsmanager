<?php
/**
 * Datei: tests/history/static_rh8_test.php
 * Zweck: Statischer Nachweis von R-H8 (AP1/H2b): Jede Methode bzw. Funktion unter web/, die DNS-Inhalt über
 *        ZoneBuildBackend festschreibt (validateAndFlag, rebuildNsGlueAndFlag, rebuildZone) oder eine Zone löscht
 *        (DELETE FROM zones), öffnet eine History-Erfassung (ZoneHistoryRecorder::open), erfasst die Zonen
 *        (beginZones bzw. zoneCreated), schreibt die Historie vor dem Commit (finish) und gibt die Klammer frei (close).
 *        Die Menge der Fundstellen ist festgeschrieben: Eine neue Schreibstelle macht den Test rot und muss bewusst
 *        aufgenommen werden. Ergänzt den Laufzeitwächter in BindZoneBuildBackend und die Pfadtests.
 *
 *        AP1/H2c (R-A1, R-A6): Jede Methode, die in den Verwaltungs-Services Benutzer, Zuweisungen, Server, DynDNS-Konten
 *        oder Zonen schreibt und committet, öffnet einen AuditRecorder, erfasst Ereignisse, schließt ihn vor dem Commit
 *        ab (bei Mischoperationen nach ZoneHistoryRecorder::finish()) und gibt ihn frei. Die Menge ist festgeschrieben;
 *        bewusst ohne Audit (E43): DynDNS-API (Client-IP, DNS-Wirkung als Revision). Der BIND-Reload trägt nach dem
 *        Agentenaufruf über recordStandalone() nach.
 *
 * Aufruf: php static_rh8_test.php <App-Verzeichnis>
 */

require_once __DIR__ . '/lib.php';

$app = rtrim($argv[1] ?? '', '/');
if (!is_dir("$app/web")) {
    fwrite(STDERR, "Aufruf: php static_rh8_test.php <App-Verzeichnis>\n");
    exit(2);
}

const EXPECTED = [
    'web/inc/app/dyndns/DyndnsAccountService.php::deleteAccount',
    'web/inc/app/dyndns/DyndnsAccountService.php::updateAccount',
    'web/inc/app/dyndns/DyndnsUpdateService.php::update',
    'web/inc/app/records/RecordService.php::createAutoPtr',
    'web/inc/app/records/RecordService.php::createRecord',
    'web/inc/app/records/RecordService.php::deleteRecords',
    'web/inc/app/records/RecordService.php::updateRecord',
    'web/inc/app/servers/ServerService.php::updateServer',
    'web/inc/app/zones/ZoneService.php::createZone',
    'web/inc/app/zones/ZoneService.php::deleteZone',
    'web/inc/app/zones/ZoneService.php::updateZone',
];
// Definitionen und reine Weiterleitungen (keine Fachtransaktion)
const EXEMPT = [
    'web/inc/deploy/BindZoneBuildBackend.php::validateAndFlag',
    'web/inc/deploy/BindZoneBuildBackend.php::rebuildNsGlueAndFlag',
    'web/inc/app/records/RecordService.php::rebuildZone',
];

/** @return array<string, string> "Datei::Funktion" => Quelltext des Rumpfs */
function functionBodies(string $file, string $rel): array
{
    $tokens = token_get_all((string)file_get_contents($file));
    $out = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            continue;   // Closure
        }
        $name = $tokens[$j][1];
        while ($j < $n && $tokens[$j] !== '{' && $tokens[$j] !== ';') { $j++; }
        if ($j >= $n || $tokens[$j] === ';') {
            continue;   // abstrakt / Interface
        }
        $depth = 0;
        $body = '';
        for ($k = $j; $k < $n; $k++) {
            $t = $tokens[$k];
            $text = is_array($t) ? $t[1] : $t;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
            if ($t === '}') { $depth--; }
            $body .= $text;
            if ($depth === 0) { break; }
        }
        $out["$rel::$name"] = $body;
    }
    return $out;
}

T::section('R-H8 statisch');
$found = [];
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$app/web", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($app) + 1);
    foreach (functionBodies($f->getPathname(), $rel) as $key => $body) {
        $writes = preg_match('/->(validateAndFlag|rebuildNsGlueAndFlag|rebuildZone)\s*\(|DELETE\s+FROM\s+zones\b/i', $body) === 1;
        if (!$writes || in_array($key, EXEMPT, true)) {
            continue;
        }
        $found[] = $key;
        $need = ['ZoneHistoryRecorder::open(', '->finish()', '->close()'];
        $missing = array_values(array_filter($need, fn ($n) => !str_contains($body, $n)));
        if (!str_contains($body, '->beginZones(') && !str_contains($body, '->zoneCreated(')) {
            $missing[] = 'beginZones/zoneCreated';
        }
        if (preg_match('/DELETE\s+FROM\s+zones\b/i', $body) === 1 && !str_contains($body, '->zoneDeleting(')) {
            $missing[] = 'zoneDeleting';
        }
        // finish() muss vor jedem commit() der Methode stehen
        $posFinish = strpos($body, '->finish()');
        $posCommit = strpos($body, '->commit()');
        if ($posFinish !== false && $posCommit !== false && $posCommit < $posFinish) {
            $missing[] = 'finish vor commit';
        }
        if ($missing !== []) {
            $bad[$key] = $missing;
        }
    }
}
sort($found);
T::eq(EXPECTED, $found, 'R01', 'Schreibstellen mit Zonenprüfung oder Zonenlöschung (festgeschriebene Menge)');
T::eq([], $bad, 'R02', 'jede Schreibstelle: open, beginZones/zoneCreated, (zoneDeleting), finish vor commit, close');
// Wächter im Backend vorhanden
$b = (string)file_get_contents("$app/web/inc/deploy/BindZoneBuildBackend.php");
T::ok(substr_count($b, 'ZoneHistoryRecorder::assertOpenFor($this->pdo, $zoneId)') === 2, 'R03', 'Laufzeitwächter in validateAndFlag und rebuildNsGlueAndFlag');
// Kein Fach- oder Adaptercode außerhalb von web/inc/app/history schreibt History-Tabellen direkt
$direct = [];
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), '/inc/app/history/')) {
        continue;
    }
    if (preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(history_change_sets|zone_snapshots|zone_revisions|zone_history_state|history_dependencies|audit_events)\b/i', (string)file_get_contents($f->getPathname())) === 1) {
        $direct[] = substr($f->getPathname(), strlen($app) + 1);
    }
}
T::eq([], $direct, 'R04', 'History-Tabellen werden nur unter web/inc/app/history geschrieben');

/* ---------------------------------------------------------------- AP1/H2c: Audit-Ereignisse statisch */
T::section('R-A statisch (H2c)');
const AUDIT_EXPECTED = [
    'web/inc/app/dyndns/DyndnsAccountService.php::changePassword',
    'web/inc/app/dyndns/DyndnsAccountService.php::createAccount',
    'web/inc/app/dyndns/DyndnsAccountService.php::deleteAccount',
    'web/inc/app/dyndns/DyndnsAccountService.php::updateAccount',
    'web/inc/app/servers/ServerService.php::createServer',
    'web/inc/app/servers/ServerService.php::deleteServer',
    'web/inc/app/servers/ServerService.php::updateServer',
    'web/inc/app/users/UserService.php::changePassword',
    'web/inc/app/users/UserService.php::createUser',
    'web/inc/app/users/UserService.php::deleteUser',
    'web/inc/app/users/UserService.php::setActive',
    'web/inc/app/users/UserService.php::updateUser',
    'web/inc/app/zones/ZoneService.php::createZone',
    'web/inc/app/zones/ZoneService.php::deleteZone',
    'web/inc/app/zones/ZoneService.php::updateZone',
];
// Bewusst ohne Audit-Ereignis (E43): DNS-Wirkung als Revision, Client-IP darf nicht erfasst werden
const AUDIT_EXEMPT = ['web/inc/app/dyndns/DyndnsUpdateService.php::update'];
$writers = [];
$auditBad = [];
$opened = [];
foreach (['users', 'servers', 'dyndns', 'zones'] as $dir) {
    foreach (glob("$app/web/inc/app/$dir/*.php") as $file) {
        $rel = substr($file, strlen($app) + 1);
        foreach (functionBodies($file, $rel) as $key => $body) {
            if (str_contains($body, 'AuditRecorder::open(')) {
                $opened[] = $key;
            }
            $writes = preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(users|user_zones|servers|dyndns_accounts|zones|zone_servers)\b/i', $body) === 1
                && str_contains($body, '->commit()');
            if (!$writes || in_array($key, AUDIT_EXEMPT, true)) {
                continue;
            }
            $writers[] = $key;
            $missing = [];
            foreach (['AuditRecorder::open(', '->record(', '->close()'] as $need) {
                if (!str_contains($body, $need)) {
                    $missing[] = $need;
                }
            }
            // $audit->finish() vor jedem commit(); bei Mischoperation nach dem finish() des ZoneHistoryRecorder
            $posAudit = strpos($body, '$audit->finish()');
            $posCommit = strpos($body, '->commit()');
            if ($posAudit === false || ($posCommit !== false && $posCommit < $posAudit)) {
                $missing[] = 'audit finish vor commit';
            }
            $posHist = strpos($body, 'history->finish()') !== false ? strpos($body, 'history->finish()') : strpos($body, 'history?->finish()');
            if ($posHist !== false && $posAudit !== false && $posAudit < $posHist) {
                $missing[] = 'audit finish nach history finish';
            }
            if (str_contains($body, 'ZoneHistoryRecorder::open(') && preg_match('/AuditRecorder::open\(\$this->pdo, \$historyCtx, \$history\)/', $body) !== 1) {
                $missing[] = 'gemeinsamer Kontext und Recorder (R-A6)';
            }
            if ($missing !== []) {
                $auditBad[$key] = $missing;
            }
        }
    }
}
sort($writers);
sort($opened);
T::eq(AUDIT_EXPECTED, $writers, 'R05', 'auditpflichtige Schreibstellen der Verwaltungs-Services (festgeschriebene Menge, E43)');
T::eq([], $auditBad, 'R06', 'jede auditpflichtige Stelle: open, record, finish vor commit (nach history finish), close; Mischoperation mit gemeinsamem Kontext');
T::eq(AUDIT_EXPECTED, $opened, 'R07', 'AuditRecorder::open() nur in den festgeschriebenen Stellen');
$srv = functionBodies("$app/web/inc/app/servers/ServerService.php", 'web/inc/app/servers/ServerService.php')['web/inc/app/servers/ServerService.php::reloadBind'] ?? '';
$posCall = strpos($srv, '->reloadBind(');
$posAudit = strpos($srv, 'AuditRecorder::recordStandalone(');
T::ok($posCall !== false && $posAudit !== false && $posCall < $posAudit && !str_contains($srv, '->beginTransaction()'), 'R08', 'BIND-Reload: Nachtrag nach dem Agentenaufruf (E51), keine Fachtransaktion');
$pol = (string)file_get_contents("$app/web/inc/app/history/AuditRecorder.php");
T::ok(str_contains($pol, 'AuditPolicy::changes(') && str_contains($pol, 'AuditPolicy::label('), 'R09', 'AuditRecorder bildet Inhalte ausschließlich über AuditPolicy (R-A3)');
T::finish('static');
