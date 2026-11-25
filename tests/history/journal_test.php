<?php
/**
 * Datei: tests/history/journal_test.php
 * Zweck: Tests des Publish-Journals (AP1/H2d, J1–J8, E55–E73) unter REPEATABLE READ (innodb_snapshot_isolation = ON)
 *        bzw. READ COMMITTED gegen eine isolierte Datenbank und eine isolierte Zweiserver-Umgebung mit dem echten
 *        Agent-Code (journal_lib.php, journal_agent.py).
 *
 * Aufruf (als root auf dem Testsystem): php journal_test.php <App-Verzeichnis> <RR|RC> [<Basis-App-Verzeichnis>]
 *   H2D_ONLY=P,O,…  beschränkt auf Abschnitte; H2D_SLOW=0 überspringt Fälle mit Zeitüberschreitung (je ~35 s).
 *
 * Abschnitte: K Constraints · P Pfade und Hash-Verträge · O Ergebnis je Endpunkt · F Fehlerinjektion · S Backend-Bindung ·
 *             J Ledger und J4a ·
 *             L Lebendigkeit, Verbindungsabbruch, Prozessende, Verbindungs-ID-Wiederverwendung · N Nebenläufigkeit ·
 *             R Reaktivierung (E70) · X Stresslauf mit Invarianten · B Byte-Gleichheit der Zonendateien mit dem Basis-Commit
 * Schutz (Q1): /etc/bind/zones wird vorher und nachher byte-genau verglichen (Z01).
 * AS4b (E81): Vor J6/J1 fragt der Core je aktivem Server einmal GET system/status.php ab (Restore-Abgleich, lesend).
 * Die Fälle ohne Seiteneffekt (F01–F04, L04) unterscheiden diesen Abgleich von allen übrigen Agentaufrufen
 * (J::splitCalls(), exakte Zeilen, kein pauschaler Filter); F00 belegt die Reihenfolge im erfolgreichen Lauf.
 */

require_once __DIR__ . '/journal_lib.php';

[$app, $iso] = [$argv[1] ?? '', $argv[2] ?? ''];
$baseApp = $argv[3] ?? '';
J::init($app, $iso, 'jr');
$only = array_filter(explode(',', (string)getenv('H2D_ONLY')));
$slow = getenv('H2D_SLOW') !== '0';
$run = static fn (string $s): bool => $only === [] || in_array($s, $only, true);
$bindBefore = J::treeSum('/etc/bind/zones');
echo "== Publish-Journal $iso – Datenbank " . H2b::$db . ', Sandbox ' . J::$sbx . "\n";

/** Gemeinsame Prüfungen nach jedem Lauf */
$clean = static function (PDO $p, string $id): void {
    T::ok(J::locksFree($p) && !H2b::hasOpenTrx($p), "$id.z", 'Publish-Lock und Laufsperren frei, keine offene Transaktion');
};
$zoneName = static fn (PDO $p, int $z): string => (string)H2b::one($p, 'SELECT name FROM zones WHERE id = ?', [$z]);
/**
 * AS4b (E81): genau ein lesender Restore-Abgleich (GET system/status.php) je aktivem Server. Liegt er vor, obwohl der
 * Ablauf bei J6/J1 bzw. J2 abbrach, belegt das zugleich die Reihenfolge „Abgleich vor J6/J1“.
 *
 * @return int Zahl aller übrigen Agentaufrufe (jeder andere Endpunkt, jede andere Methode, jeder weitere Abgleich)
 */
$e81 = static function (PDO $p, string $id): int {
    $s = J::splitCalls($p);
    T::eq($s['expected'], $s['status'], "$id.e81", 'Restore-Abgleich (E81): genau ein GET system/status.php je aktivem Server, vor J6/J1');
    return count($s['other']);
};

/* ================================================================ K Constraints */
if ($run('K')) {
    T::section('K Constraints (NULL-sicher)');
    $p = J::reset();
    $ins = "INSERT INTO publish_runs (kind, requested_zone_id, full_rebuild, connection_id, history_watermark, actor_type, actor_user_id, channel, correlation_id%s) VALUES ('all', NULL, 0, 1, 0, 'user', 21, 'web', '11111111-1111-4111-8111-111111111111'%s)";
    $reject = static function (PDO $p, string $sql, string $id, string $msg): void {
        try {
            $p->exec($sql);
            T::ok(false, $id, "$msg (angenommen)");
        } catch (PDOException $e) {
            T::ok(str_contains($e->getMessage(), '4025') || str_contains($e->getMessage(), 'CONSTRAINT') || str_contains($e->getMessage(), '1452') || str_contains($e->getMessage(), '1062'), $id, $msg);
        }
    };
    $reject($p, sprintf($ins, ', status', ", 'finished'"), 'K01', 'finished ohne finished_at/result abgewiesen');
    $reject($p, sprintf($ins, ', finished_at', ', NOW(6)'), 'K02', 'running mit finished_at abgewiesen');
    $reject($p, "INSERT INTO publish_runs (kind, requested_zone_id, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id) VALUES ('zone', NULL, 0, 1, 0, 'system', 'system', '11111111-1111-4111-8111-111111111111')", 'K03', 'Lauf zone ohne Zone abgewiesen (NULL-sicher)');
    $reject($p, "INSERT INTO publish_runs (kind, requested_zone_id, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id) VALUES ('all', NULL, 0, 1, 0, 'user', 'web', '11111111-1111-4111-8111-111111111111')", 'K04', 'Akteur user ohne Benutzer-ID abgewiesen');
    $p->exec(sprintf($ins, '', ''));
    $rid = (int)$p->lastInsertId();
    $h = str_repeat('a', 64);
    $att = "INSERT INTO zone_publish_attempts (run_id, zone_id, zone_name, revision_no, revision_basis, content_hash, snapshot_contract, render_hash, file_hash, render_contract, soa_serial, target_count) VALUES ($rid, 2, 'beta.h2b.test', %s, '%s', '%s', 'zs1', '$h', '$h', 'rh1', 1, 0)";
    $reject($p, sprintf($att, 'NULL', 'head', $h), 'K05', 'Basis head ohne Revision abgewiesen');
    $reject($p, sprintf($att, '1', 'head_mismatch', $h), 'K06', 'head_mismatch mit Revision abgewiesen');
    $reject($p, sprintf($att, '1', 'head', $h), 'K07', 'Revisionsbezug mit fremdem Hash abgewiesen (FK zone_revisions)');
    $reject($p, sprintf($att, 'NULL', 'no_head', 'XYZ'), 'K08', 'ungültiger Hash abgewiesen');
    $p->exec(sprintf($att, 'NULL', 'no_head', $h));
    $aid = (int)$p->lastInsertId();
    $reject($p, "INSERT INTO zone_publish_targets (attempt_id, server_id, server_name, zone_sync) VALUES ($aid, 1, 'ns1', 'ok')", 'K09', 'Endzustand ohne Zeitpunkt abgewiesen');
    $reject($p, "INSERT INTO zone_publish_targets (attempt_id, server_id, server_name, zone_sync, zone_sync_at, zone_error_code) VALUES ($aid, 1, 'ns1', 'ok', NOW(6), 'x')", 'K10', 'ok mit Fehlercode abgewiesen');
    $reject($p, "INSERT INTO zone_publish_targets (attempt_id, server_id, server_name, zone_sync, zone_sync_at) VALUES ($aid, 1, 'ns1', 'unknown', NOW(6))", 'K11', 'unknown ohne Fehlercode abgewiesen');
    $reject($p, "INSERT INTO publish_cleanup_results (run_id, server_id, server_name, status, finished_at) VALUES ($rid, 1, 'ns1', 'ok', NOW(6))", 'K12', 'Cleanup ok ohne Zähler abgewiesen');
    $reject($p, "INSERT INTO zone_publish_attempts (run_id, zone_id, zone_name, revision_no, revision_basis, content_hash, snapshot_contract, render_hash, file_hash, render_contract, soa_serial, target_count) VALUES ($rid, 2, 'b', NULL, 'no_head', '$h', 'zs1', '$h', '$h', 'rh1', 2, 0)", 'K13', 'zweiter Versuch derselben Zone im selben Lauf abgewiesen (UNIQUE)');
}

/* ================================================================ P Pfade */
if ($run('P')) {
    T::section('P Pfade, Akteurskontext und Hash-Verträge');
    $p = J::reset();
    $headBeta = H2b::head($p, 2);
    $before = J::flags($p);
    $markCs = H2b::mark($p);
    $rep = J::publishAll($p);
    $rid = J::lastRunId($p);
    $run1 = J::runs($p)[0] ?? [];
    T::eq('success', $rep['status'], 'P01', 'Publish All: Erfolg');
    T::eq([$rid, 0], [$rep['runId'], $rep['journal']['writeFailures']], 'P01b', 'Bericht: runId und keine Journal-Schreibfehler');
    T::eq(['all', null, '0', 'user', '21', null, 'web', 'finished', 'success', (string)$markCs],
        [$run1['kind'], $run1['requested_zone_id'], (string)$run1['full_rebuild'], $run1['actor_type'], (string)$run1['actor_user_id'],
         $run1['actor_dyndns_account_id'], $run1['channel'], $run1['status'], $run1['result'], (string)$run1['history_watermark']], 'P01c', 'Lauf: Art, Akteur, Kanal, Abschluss, Wasserstand');
    T::ok(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4/', (string)$run1['correlation_id']) === 1 && (int)$run1['connection_id'] > 0, 'P01d', 'Korrelation (UUIDv4) und Verbindungs-ID');
    $atts = J::attempts($p, $rid);
    T::eq([2], array_map(fn ($a) => (int)$a['zone_id'], $atts), 'P02', 'genau ein Versuch für die ausstehende Zone beta');
    $a = $atts[0];
    $serial = (int)H2b::one($p, 'SELECT soa_serial FROM zones WHERE id = 2');
    T::eq(['head', (string)$headBeta[0], $headBeta[1], (string)$serial, 'published', '1', 'zs1', 'rh1'],
        [$a['revision_basis'], (string)$a['revision_no'], $a['content_hash'], (string)$a['soa_serial'], $a['outcome'], (string)$a['target_count'], $a['snapshot_contract'], $a['render_contract']],
        'P03', 'Versuch: Revision = Head, content_hash = Head-Hash, Serial = DB-Serial, Ergebnis');
    T::eq(H2b::currentHash($p, 2), $a['content_hash'], 'P03b', 'content_hash = unabhängig gelesener DB-Stand (zs1)');
    $file = J::installed(1, 'beta.h2b.test');
    T::ok($file !== null && hash('sha256', $file) === $a['file_hash'], 'P04', 'file_hash = sha256 der vom echten Agenten installierten Datei');
    T::eq($a['render_hash'], PublishHashContract::renderHashFromFile((string)$file, $serial), 'P04b', 'render_hash = rh1 der installierten Datei');
    T::ok(str_contains((string)$file, "\n            $serial   ; Serial\n"), 'P04c', 'Datei trägt die registrierte Serial');
    $t = J::targets($p, (int)$a['id']);
    T::eq([1], array_keys($t), 'P05', 'Zielmenge = aktive zugewiesene Server');
    T::eq(['ns1.h2b.test', 'legacy', 'ok', '200', 'ok', '200', null, null],
        [$t[1]['server_name'], $t[1]['agent_protocol'], $t[1]['zone_sync'], (string)$t[1]['zone_http_status'], $t[1]['conf_sync'], (string)$t[1]['conf_http_status'], $t[1]['zone_error_code'], $t[1]['conf_error_code']],
        'P05b', 'Ziel: zone_sync und conf_sync ok (legacy)');
    T::eq(hash('sha256', (string)J::installedConf(1, 'beta.h2b.test')), $t[1]['conf_hash'], 'P05c', 'conf_hash = sha256 der installierten Konfiguration');
    $c = J::cleanup($p, $rid);
    T::eq(['1:ok:4:0:0', '2:ok:2:0:0'], array_map(fn ($r) => "{$r['server_id']}:{$r['status']}:{$r['valid_zone_count']}:{$r['removed_conf_count']}:{$r['removed_zone_file_count']}", array_values($c)), 'P06', 'Cleanup je aktivem Server mit Zählern');
    $after = J::flags($p);
    T::eq(array_replace($before['changed'], [1 => '2:0']), $after['changed'], 'P07', 'changed: nur beta 1 → 0 (J8)');
    T::eq([], H2b::invariants($p), 'P07b', 'History-Invarianten unverändert erfüllt');
    $clean($p, 'P07');

    // Point 4 der Freigabe: späterer Head ändert den Versuch nicht
    $r = H2b::records($p)->createRecord(H2b::admin(), 2, H2b::record('TXT', 'neu', '"h2d"'));
    T::ok($r->ok, 'P08', 'Fachänderung nach dem Publish (neuer Head)');
    $a2 = J::attempts($p, $rid)[0];
    T::ok($a2 === $a && H2b::head($p, 2)[0] === $headBeta[0] + 1 && H2b::head($p, 2)[1] !== $a2['content_hash'], 'P08b', 'Versuch bleibt „Revision N mit Datei X“, nicht der neuere Head (J3/J4a)');

    // Full Rebuild
    $p->exec('UPDATE system_status SET bind_dirty = 1 WHERE id = 1');
    $rep = J::publishAll($p);
    $rid = J::lastRunId($p);
    $run2 = J::runs($p)[1];
    $atts = J::attempts($p, $rid);
    $zids = array_map(fn ($x) => (int)$x['zone_id'], $atts);
    sort($zids);
    T::eq(['success', '1', [1, 2, 3, 4, 6]], [$rep['status'], (string)$run2['full_rebuild'], $zids], 'P09', 'Full Rebuild: alle Zonen, full_rebuild = 1');
    $okAll = true;
    foreach ($atts as $x) {
        $tt = J::targets($p, (int)$x['id']);
        foreach ($tt as $sid => $tr) {
            $inst = J::installed($sid, $x['zone_name']);
            $okAll = $okAll && $tr['zone_sync'] === 'ok' && $tr['conf_sync'] === 'ok' && $inst !== null && hash('sha256', $inst) === $x['file_hash'];
        }
        $okAll = $okAll && $x['revision_basis'] === 'head' && $x['content_hash'] === H2b::currentHash($p, (int)$x['zone_id']);
    }
    T::ok($okAll, 'P09b', 'jede Zone: Ziele ok, installierte Datei = file_hash auf jedem Zielserver, Revision = Head');
    T::eq(['alpha.h2b.test' => [1, 2], 'delta.h2b.test' => [2]], ['alpha.h2b.test' => array_keys(J::targets($p, (int)J::attemptFor($p, $rid, 1)['id'])), 'delta.h2b.test' => array_keys(J::targets($p, (int)J::attemptFor($p, $rid, 6)['id']))], 'P09c', 'Zielmengen je Zuweisung (alpha zwei Server, delta nur Server 2)');
    T::eq(0, J::flags($p)['bind_dirty'], 'P09d', 'bind_dirty 2 → 0');
    $clean($p, 'P09');

    // Publish Zone: bereits veröffentlicht, Systemkontext
    $rz = J::report(J::service($p)->publishZone(2));
    $rz2 = J::runs($p)[2];
    T::eq(['already_published', 'zone', '2', 'system', 'system', 'already_published', 0], [$rz['status'], $rz2['kind'], (string)$rz2['requested_zone_id'], $rz2['actor_type'], $rz2['channel'], $rz2['result'], count(J::attempts($p, (int)$rz2['id']))], 'P10', 'Publish Zone ohne Kontext: Systemakteur, already_published, kein Versuch');
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 2');
    $rz = J::report(J::service($p)->publishZone(2));
    T::eq(['published', 1], [$rz['status'], count(J::attempts($p, (int)$rz['runId']))], 'P10b', 'Publish Zone veröffentlicht mit genau einem Versuch');

    // DynDNS: gleicher Kontext und gleiche Korrelation wie die Fachänderung; Wiederholung ohne Änderungsmenge
    $mk = H2b::mark($p);
    $dyn = new DyndnsUpdateService($p, new BindZoneBuildBackend($p), J::service($p));
    $dr = $dyn->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.51', null);
    $cs = H2b::since($p, $mk);
    $rz3 = J::runs($p)[count(J::runs($p)) - 1];
    T::ok($dr->ok, 'P11', 'DynDNS-Update veröffentlicht');
    T::eq(['zone', '1', 'dyndns_account', null, '1', 'api', $cs[0]['corr'] ?? '-'], [$rz3['kind'], (string)$rz3['requested_zone_id'], $rz3['actor_type'], $rz3['actor_user_id'], (string)$rz3['actor_dyndns_account_id'], $rz3['channel'], $rz3['correlation_id']], 'P11b', 'DynDNS-Lauf: Konto-Akteur, Kanal api, Korrelation der Fachänderung');
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
    $mk = H2b::mark($p);
    $dr = $dyn->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.51', null);
    $rz4 = J::runs($p)[count(J::runs($p)) - 1];
    T::ok($dr->ok && $rz4['id'] !== $rz3['id'] && $rz4['actor_type'] === 'dyndns_account' && H2b::mark($p) === $mk
        && (int)H2b::one($p, 'SELECT COUNT(*) FROM history_change_sets WHERE correlation_id = ?', [$rz4['correlation_id']]) === 0, 'P11c', 'Wiederholung ohne Änderung: eigener Lauf, keine erfundene Änderungsmenge');

    // Revisionsbezug ohne passenden Head und ohne Head
    $p->exec("UPDATE records SET content = '192.0.2.21' WHERE zone_id = 2 AND name = 'alt'");
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 2');
    J::publishAll($p);
    $x = J::attemptFor($p, J::lastRunId($p), 2);
    T::eq(['head_mismatch', null, H2b::currentHash($p, 2)], [$x['revision_basis'], $x['revision_no'], $x['content_hash']], 'P12', 'Inhalt ohne Historie geändert: head_mismatch, Hash = tatsächlich erzeugter Stand');
    $p->exec('DELETE FROM zone_history_state WHERE zone_id = 4');
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 4');
    J::publishAll($p);
    $x = J::attemptFor($p, J::lastRunId($p), 4);
    T::eq(['no_head', null], [$x['revision_basis'], $x['revision_no']], 'P13', 'Zone ohne Head: no_head');

    // Zielmenge: deaktivierter Server
    $p = J::reset();
    $p->exec('UPDATE servers SET active = 0 WHERE id = 2');
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
    $rep = J::publishAll($p);
    $rid = J::lastRunId($p);
    $a1 = J::attemptFor($p, $rid, 1);
    T::eq(['success', [1], '1'], [$rep['status'], array_keys(J::targets($p, (int)$a1['id'])), (string)$a1['target_count']], 'P14', 'deaktivierter Server ist kein Ziel');
    T::eq([1], array_keys(J::cleanup($p, $rid)), 'P14b', 'Cleanup nur an aktive Server');
    // Zone ohne aktives Ziel (heute fataler Verteilungsfehler; Serial bereits verbraucht)
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 6');
    $s6 = (int)H2b::one($p, 'SELECT soa_serial FROM zones WHERE id = 6');
    $rep = J::publishAll($p);
    $a6 = J::attemptFor($p, J::lastRunId($p), 6);
    T::eq(['error', '0', 'failed', (string)H2b::one($p, 'SELECT soa_serial FROM zones WHERE id = 6'), true, '6:1'],
        [$rep['status'], (string)$a6['target_count'], $a6['outcome'], (string)$a6['soa_serial'], (int)$a6['soa_serial'] > $s6, J::flags($p)['changed'][4]],
        'P15', 'Zone ohne aktives Ziel: Versuch mit 0 Zielen, failed, Serial verbraucht ⇔ Versuch, Zone bleibt ausstehend (Status unverändert error)');
    $clean($p, 'P15');

    // E60/E62: nur die Datei mit dem registrierten file_hash wird gesendet
    $p = J::reset();
    $tmp = J::$sbx . '/p17.db';
    file_put_contents($tmp, "nicht der registrierte Stand
");
    $got = [];
    $r = distribute_zone_file($p, J::client(), 2, $tmp, ['targets' => [1 => 'ns1.h2b.test'], 'fileHash' => hash('sha256', 'anders'),
        'onResult' => function (int $sid, array $c) use (&$got): void { $got[$sid] = $c; }]);
    T::eq(['error', 'failed', 'file_hash_mismatch', 0], [$r['status'], $got[1]['status'] ?? null, $got[1]['errorCode'] ?? null, count(J::agentCalls())],
        'P17', 'abweichende Datei (file_hash): keine Übertragung, Ziel failed, Verteilung als interner Fehler');
}

/* ================================================================ O Ergebnis je Endpunkt */
if ($run('O')) {
    T::section('O Ergebnis je Endpunkt und Fehlerbild (E63, Q3)');
    $case = static function (string $id, array $control, string $msg, callable $check, ?callable $prep = null) use ($clean): void {
        $p = J::reset();
        $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
        if ($prep !== null) {
            $prep($p);
        }
        J::control($control);
        $rep = J::publishAll($p);
        J::control([]);
        $rid = J::lastRunId($p);
        $a = J::attemptFor($p, $rid, 1);
        $check($p, $rep, $a, $a === null ? [] : J::targets($p, (int)$a['id']), J::cleanup($p, $rid));
        $clean($p, $id);
    };
    $case('O01', [2 => ['zone_sync.php' => 'reject']], 'zone_sync abgelehnt', function ($p, $rep, $a, $t) {
        T::eq(['rejected', '422', 'agent_error', 'ok', 'ok', 'partial', 'warning'], [$t[2]['zone_sync'], (string)$t[2]['zone_http_status'], $t[2]['zone_error_code'], $t[2]['conf_sync'], $t[1]['zone_sync'], $a['outcome'], $rep['status']], 'O01', 'zone_sync-Ablehnung: rejected, Server 1 ok, operativ teilweise (unverändert)');
        T::ok(J::installed(2, 'alpha.h2b.test') === null, 'O01b', 'abgelehnte Datei nicht installiert');
    });
    $case('O02', [], 'nicht erreichbar', function ($p, $rep, $a, $t) {
        T::eq(['failed', 'not_delivered', 'failed', 'not_delivered', 'failed'], [$t[2]['zone_sync'], $t[2]['zone_error_code'], $t[2]['conf_sync'], $t[2]['conf_error_code'], $a['outcome']], 'O02', 'Verbindung abgelehnt: failed (sicher nicht zugestellt)');
    }, fn ($p) => $p->exec("UPDATE servers SET api_ip = '127.0.0.6' WHERE id = 2"));
    $case('O03', [2 => ['zone_sync.php' => 'garbage']], 'HTTP 200 ohne Agent-JSON', function ($p, $rep, $a, $t) {
        T::eq(['success', 'published', 'unknown', 'invalid_response', '200'], [$rep['status'], $a['outcome'], $t[2]['zone_sync'], $t[2]['zone_error_code'], (string)$t[2]['zone_http_status']], 'O03', 'operativ veröffentlicht, im Journal unknown (published ≠ Bestätigung, E65)');
        $eff = (new PublishJournalReader($p))->effectiveTargets((int)$a['id']);
        T::eq('unknown', $eff[2]['zone'], 'O03b', 'Lese-Regel: Ziel bleibt unknown, nie synchron');
    });
    $case('O04', [2 => ['zone_sync.php' => 'html500']], 'HTTP 500 ohne Agent-JSON', function ($p, $rep, $a, $t) {
        T::eq(['unknown', 'invalid_response', '500'], [$t[2]['zone_sync'], $t[2]['zone_error_code'], (string)$t[2]['zone_http_status']], 'O04', 'Fremdantwort: unknown');
    });
    $case('O05', [2 => ['conf_sync.php' => 'rollback_failed']], 'conf_sync Rollback gescheitert', function ($p, $rep, $a, $t) {
        T::eq(['ok', 'unknown', 'rollback_failed', 'failed'], [$t[2]['zone_sync'], $t[2]['conf_sync'], $t[2]['conf_error_code'], $a['outcome']], 'O05', 'conf_sync rollback=failed: unknown (Zustand ungewiss)');
    });
    $case('O06', [2 => ['conf_sync.php' => 'reject']], 'conf_sync abgelehnt', function ($p, $rep, $a, $t) {
        T::eq(['rejected', 'agent_error'], [$t[2]['conf_sync'], $t[2]['conf_error_code']], 'O06', 'conf_sync-Ablehnung: rejected');
    });
    $case('O07', [2 => ['zone_cleanup.php' => 'partial_cleanup']], 'Cleanup teilweise', function ($p, $rep, $a, $t, $c) {
        T::eq(['ok', 'partial', '1', '0', 'cleanup_incomplete', 'warning', 1], [$c[1]['status'], $c[2]['status'], (string)$c[2]['removed_conf_count'], (string)$c[2]['removed_zone_file_count'], $c[2]['error_code'], $rep['status'], J::flags($p)['bind_dirty']], 'O07', 'Cleanup teilweise: partial mit Zählern, operativ Warnung und bind_dirty = 1 (unverändert)');
    });
    $case('O08', [2 => ['zone_cleanup.php' => 'reject']], 'Cleanup abgelehnt', function ($p, $rep, $a, $t, $c) {
        T::eq(['rejected', null], [$c[2]['status'], $c[2]['removed_conf_count']], 'O08', 'Cleanup-Ablehnung: rejected ohne Zähler');
    });
    $case('O09', [2 => ['zone_sync.php' => 'close']], 'Verbindung ohne Antwort', function ($p, $rep, $a, $t) {
        T::eq(['unknown', 'transport_unknown'], [$t[2]['zone_sync'], $t[2]['zone_error_code']], 'O09', 'Verbindung ohne Antwort geschlossen: unknown');
    });
    if ($slow && $iso === 'RR') {
        $case('O10', [2 => ['zone_sync.php' => 'delay:33']], 'Zeitüberschreitung', function ($p, $rep, $a, $t) {
            T::eq(['unknown', 'timeout'], [$t[2]['zone_sync'], $t[2]['zone_error_code']], 'O10', 'Zeitüberschreitung: unknown, nicht failed (Q3)');
            sleep(6);
            $inst = J::installed(2, 'alpha.h2b.test');
            T::ok($inst !== null && hash('sha256', $inst) === $a['file_hash'], 'O10b', 'Agent hat die Datei nach der Zeitüberschreitung dennoch übernommen – unknown war korrekt (kein erfundenes failed)');
        });
    } else {
        echo "  --   O10 Zeitüberschreitung übersprungen (nur RR, H2D_SLOW≠0)\n";
    }
}

/* ================================================================ F Fehlerinjektion */
if ($run('F')) {
    T::section('F Fehlerinjektion (J1, J6, J2, J4, Cleanup)');
    // F00 AS4b (E81): Reihenfolge im erfolgreichen Lauf – Restore-Abgleich vor J1 (publish_runs.started_at), alle
    // übrigen (schreibenden) Agentaufrufe erst danach
    $p = J::reset();
    $p->exec('UPDATE system_status SET bind_dirty = 1 WHERE id = 1');
    $rep = J::publishAll($p);
    $s = J::splitCalls($p);
    $t1 = (float)H2b::one($p, 'SELECT UNIX_TIMESTAMP(started_at) FROM publish_runs ORDER BY id DESC LIMIT 1');
    $timed = J::agentCallsTimed();
    $tsStatus = array_column(array_filter($timed, static fn (array $c): bool => in_array($c[1], $s['status'], true)), 0);
    $tsOther = array_column(array_filter($timed, static fn (array $c): bool => !in_array($c[1], $s['status'], true)), 0);
    T::eq(['success', $s['expected']], [$rep['status'], $s['status']], 'F00', 'erfolgreicher Lauf: genau ein Restore-Abgleich je aktivem Server');
    // Zeitstempel des Testagenten auf ms gerundet (±0,5 ms), started_at aus NOW(6) derselben Maschine
    T::ok($tsStatus !== [] && $tsOther !== [] && max($tsStatus) <= $t1 + 0.0005 && $t1 <= min($tsOther) + 0.0005, 'F00b',
        sprintf('Reihenfolge: Abgleich (bis %.3f) vor J1 (%.6f), übrige Aufrufe ab %.3f', $tsStatus === [] ? 0 : max($tsStatus), $t1, $tsOther === [] ? 0 : min($tsOther)));
    $clean($p, 'F00');
    $noSide = static function (PDO $p, array $rep, string $before, int $calls, string $id, string $msg) use ($clean): void {
        T::eq(['exception', true, 'untouched', $before, 0], [$rep['status'], str_starts_with((string)$rep['exception'], 'Publish-Journal:'), $rep['flags']['outcome'], H2b::businessSum($p), $calls], $id, $msg);
        $clean($p, $id);
    };
    // F01 J1
    $p = J::reset();
    $p->exec('UPDATE system_status SET bind_dirty = 1 WHERE id = 1');
    $sum = H2b::businessSum($p);
    J::inject($p, 'h2d_f01', 'BEFORE INSERT', 'publish_runs');
    $rep = J::publishAll($p);
    $noSide($p, $rep, $sum, $e81($p, 'F01'), 'F01', 'J1 gescheitert: exception, Flags/Fachdaten unverändert, kein schreibender Agentaufruf');
    T::eq(0, count(J::runs($p)), 'F01b', 'kein Lauf registriert');
    $rz = J::report(J::service($p)->publishZone(2));
    T::eq(['exception', '2:1'], [$rz['status'], J::flags($p)['changed'][1]], 'F01c', 'Publish Zone: J1 gescheitert ⇒ exception, changed unverändert');
    J::dropInjection($p, 'h2d_f01');

    // F02 J6
    $p = J::reset();
    $p->exec("INSERT INTO publish_runs (kind, requested_zone_id, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id) VALUES ('all', NULL, 0, 999999, 0, 'system', 'cli', '22222222-2222-4222-8222-222222222222')");
    $sum = H2b::businessSum($p);
    J::inject($p, 'h2d_f02', 'BEFORE UPDATE', 'publish_runs');
    $rep = J::publishAll($p);
    $noSide($p, $rep, $sum, $e81($p, 'F02'), 'F02', 'J6 gescheitert: exception vor jedem Seiteneffekt (kein schreibender Agentaufruf)');
    T::eq(['running', 1], [J::runs($p)[0]['status'], count(J::runs($p))], 'F02b', 'alter Lauf unverändert offen, kein neuer Lauf');
    J::dropInjection($p, 'h2d_f02');

    // F03 J2 (Versuch bzw. Zielzeilen)
    foreach (['zone_publish_attempts' => 'F03', 'zone_publish_targets' => 'F04'] as $table => $id) {
        $p = J::reset();
        $s2 = (int)H2b::one($p, 'SELECT soa_serial FROM zones WHERE id = 2');
        J::inject($p, "h2d_$id", 'BEFORE INSERT', $table);
        $rep = J::publishAll($p);
        $rid = J::lastRunId($p);
        T::eq(['error', 'generation_failed', $s2, 0, 0, '2:1', 'finished', 'error'],
            [$rep['status'], $rep['zones'][0]['outcome'], (int)H2b::one($p, 'SELECT soa_serial FROM zones WHERE id = 2'), count(J::attempts($p)), $e81($p, $id), J::flags($p)['changed'][1], J::runs($p)[0]['status'], J::runs($p)[0]['result']],
            $id, "J2 ($table) gescheitert: Rollback, Serial unverändert, kein schreibender Agentaufruf, Zone 2 → 1, Lauf error");
        T::ok(str_contains(implode(' ', $rep['errors']), 'Publish-Journal'), "{$id}b", 'Fehler als bisheriger Generierungsfehler mit Journal-Hinweis');
        T::eq(0, count(glob(sys_get_temp_dir() . '/dnsmanager-work-*/db.beta*') ?: []), "{$id}c", 'keine Zonendatei zurückgeblieben');
        J::dropInjection($p, "h2d_$id");
        $clean($p, $id);
    }

    // F05–F08 J4: operatives Ergebnis identisch zur Referenz
    $reference = static function (callable $prep): array {
        $p = J::reset();
        $prep($p);
        $rep = J::publishAll($p);
        return [J::operational($rep), J::flags($p), J::agentCalls()];
    };
    $prepAll = static fn (PDO $p) => $p->exec('UPDATE system_status SET bind_dirty = 1 WHERE id = 1');
    [$refRep, $refSum, $refCalls] = $reference($prepAll);
    foreach ([
        'F05' => ['zone_publish_targets', 'BEFORE UPDATE', 'Zielergebnisse'],
        'F06' => ['zone_publish_attempts', 'BEFORE UPDATE', 'Versuchsergebnisse'],
        'F07' => ['publish_runs', 'BEFORE UPDATE', 'Laufabschluss'],
        'F08' => ['publish_cleanup_results', 'BEFORE UPDATE', 'Cleanup-Ergebnisse'],
    ] as $id => [$table, $timing, $what]) {
        $p = J::reset();
        $prepAll($p);
        J::inject($p, "h2d_$id", $timing, $table, $table === 'publish_runs' ? "NEW.status = 'finished'" : '1 = 1');
        $rep = J::publishAll($p);
        J::dropInjection($p, "h2d_$id");
        T::eq([$refRep, $refSum, $refCalls], [J::operational($rep), J::flags($p), J::agentCalls()], $id, "J4 ($what) gescheitert: operatives Ergebnis, Flags/Serials und Agentaufrufe identisch zur Referenz");
        T::ok($rep['journal']['writeFailures'] > 0 && count(preg_grep('/^warning \[publish\] Journal: /', J::$logs)) === $rep['journal']['writeFailures'], "{$id}b", 'Bericht journal.writeFailures und je Fehler eine Logzeile (Q4)');
        $open = match ($table) {
            'zone_publish_targets'    => (int)H2b::one($p, "SELECT COUNT(*) FROM zone_publish_targets WHERE zone_sync = 'sending' OR conf_sync = 'sending'"),
            'zone_publish_attempts'   => (int)H2b::one($p, 'SELECT COUNT(*) FROM zone_publish_attempts WHERE outcome IS NULL'),
            'publish_runs'            => (int)H2b::one($p, "SELECT COUNT(*) FROM publish_runs WHERE status = 'running'"),
            'publish_cleanup_results' => (int)H2b::one($p, "SELECT COUNT(*) FROM publish_cleanup_results WHERE status = 'sending'"),
        };
        $reader = new PublishJournalReader($p);
        T::ok($open > 0 && !$reader->runAlive(J::lastRunId($p)), "{$id}c", 'Vorgang bleibt offen; Lauf nicht lebendig ⇒ Lese-Regel unknown (J5)');
        $rep2 = J::publishAll($p);
        $left = (int)H2b::one($p, "SELECT (SELECT COUNT(*) FROM zone_publish_targets WHERE zone_sync = 'sending' OR conf_sync = 'sending') + (SELECT COUNT(*) FROM zone_publish_attempts WHERE outcome IS NULL)"
            . " + (SELECT COUNT(*) FROM publish_runs WHERE status = 'running') + (SELECT COUNT(*) FROM publish_cleanup_results WHERE status = 'sending')");
        $abandoned = (int)H2b::one($p, "SELECT (SELECT COUNT(*) FROM zone_publish_targets WHERE zone_sync = 'abandoned' OR conf_sync = 'abandoned') + (SELECT COUNT(*) FROM zone_publish_attempts WHERE outcome = 'abandoned')"
            . " + (SELECT COUNT(*) FROM publish_runs WHERE status = 'abandoned') + (SELECT COUNT(*) FROM publish_cleanup_results WHERE status = 'abandoned')");
        T::ok($rep2['status'] === 'success' && $left === 0 && $abandoned >= $open, "{$id}d", 'nächster Lauf markiert offene Vorgänge als abandoned (J6)');
        $clean($p, $id);
    }

    // F09 Cleanup-Registrierung gescheitert ⇒ kein Cleanup, Wiederholung über bind_dirty
    $p = J::reset();
    J::inject($p, 'h2d_f09', 'BEFORE INSERT', 'publish_cleanup_results');
    $rep = J::publishAll($p);
    J::dropInjection($p, 'h2d_f09');
    T::eq(['warning', 0, 1, true], [$rep['status'], count(preg_grep('/zone_cleanup/', J::agentCalls())), J::flags($p)['bind_dirty'], str_contains(implode(' ', $rep['cleanup']['errors']), 'Publish-Journal')],
        'F09', 'Cleanup-Registrierung gescheitert: kein zone_cleanup, Cleanup-Warnung, bind_dirty = 1 (E66)');
    $clean($p, 'F09');
}

/* ================================================================ S Backend-Bindung (Review S1) */
if ($run('S')) {
    T::section('S Fehlschlagende Backend-Bindung: Lauf abgeschlossen, Laufsperre frei, Verbindung weiter nutzbar (Review S1)');
    $p = J::reset();
    $p->exec('UPDATE system_status SET bind_dirty = 1 WHERE id = 1');
    $flags = J::flags($p);
    $sum = H2b::businessSum($p);
    $db = (string)H2b::one($p, 'SELECT DATABASE()');
    $failing = new JFailingBindBackend(new LegacyBindPublishBackend($p, J::client()));
    $rep = J::report(J::service($p, $failing)->publishAll(H2b::admin()));
    $r1 = J::runs($p)[0] ?? [];
    T::eq(['exception', true, 'untouched', 1, $flags, $sum, 0],
        [$rep['status'], str_contains((string)$rep['exception'], 'Publish-Journal: Bindung an das Backend fehlgeschlagen'), $rep['flags']['outcome'], $failing->bindAttempts, J::flags($p), H2b::businessSum($p), count(J::agentCalls())],
        'S01', 'Publish All: Bindung scheitert ⇒ exception ohne Flag-Änderung und ohne Agentaufruf');
    T::eq(['finished', 'exception', null], [$r1['status'] ?? null, $r1['result'] ?? null, H2b::one($p, 'SELECT IS_USED_LOCK(?)', [PublishLedger::runLockName($db, (int)($r1['id'] ?? 0))])],
        'S02', 'registrierter Lauf abgeschlossen (exception), laufeigene Sperre auf derselben Verbindung freigegeben');
    T::ok(J::locksFree($p) && !H2b::hasOpenTrx($p) && !(new PublishJournalReader($p))->runAlive((int)$r1['id']), 'S03', 'keine Sperre, keine Transaktion, Lauf nicht lebendig');
    $rz = J::report(J::service($p, $failing)->publishZone(2));
    $r2 = J::runs($p)[1] ?? [];
    T::eq(['exception', '2:1', 'finished', 'exception', null], [$rz['status'], J::flags($p)['changed'][1], $r2['status'] ?? null, $r2['result'] ?? null,
        H2b::one($p, 'SELECT IS_USED_LOCK(?)', [PublishLedger::runLockName($db, (int)($r2['id'] ?? 0))])], 'S04', 'Publish Zone: ebenso, changed unverändert, Laufsperre frei');
    // Derselbe PDO-Handle ist danach uneingeschränkt nutzbar
    $rep = J::publishAll($p);
    $r3 = J::runs($p)[2] ?? [];
    T::eq(['success', 'finished', 'success', '1', 0], [$rep['status'], $r3['status'] ?? null, $r3['result'] ?? null, (string)($r3['full_rebuild'] ?? ''), J::flags($p)['bind_dirty']],
        'S05', 'auf derselben PDO-Verbindung danach ein vollständiger Publish-Lauf möglich');
    $rz = J::report(J::service($p)->publishZone(2));
    T::ok(in_array($rz['status'], ['published', 'already_published'], true) && J::locksFree($p), 'S06', 'ebenso Publish Zone; danach alle Sperren frei');
    $clean($p, 'S06');
}

/* ================================================================ J Ledger und J4a */
if ($run('J')) {
    T::section('J Ledger-Grenzen und Unveränderlichkeit (E57, J4a)');
    $p = J::reset();
    $l = new PublishLedger($p);
    $ctx = HistoryContext::system('publish.test', 'cli');
    T::throws(RuntimeException::class, fn () => $l->registerRun('all', null, false, $ctx), 'J01', 'registerRun ohne Publish-Lock abgewiesen');
    $p->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchColumn();
    $p->beginTransaction();
    T::throws(LogicException::class, fn () => $l->registerRun('all', null, false, $ctx), 'J02', 'registerRun in offener Transaktion abgewiesen');
    $p->rollBack();
    $r = $l->registerRun('zone', 2, false, $ctx);
    $fake = new PublishRun($r->id, 'zone', false, $r->connectionId, $r->runLock);
    $zone = H2b::all($p, 'SELECT * FROM zones WHERE id = 2')[0];
    $recs = H2b::all($p, 'SELECT * FROM records WHERE zone_id = 2');
    $serial = (int)$zone['soa_serial'] + 1;
    $file = zone_file_render($zone, $recs, (string)$serial);
    $mask = zone_file_render($zone, $recs, PublishHashContract::SERIAL_PLACEHOLDER);
    T::throws(LogicException::class, fn () => $l->registerAttempt($r, 2, $zone, $recs, $serial, $file, $mask), 'J03', 'registerAttempt ohne Transaktion abgewiesen');
    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    T::throws(LogicException::class, fn () => $l->registerAttempt($fake, 2, $zone, $recs, $serial, $file, $mask), 'J04', 'fremdes (nicht ausgegebenes) Lauf-Token abgewiesen');
    T::throws(RuntimeException::class, fn () => $l->registerAttempt($r, 2, $zone, $recs, $serial, $file . ' ', $mask), 'J05', 'Datei passt nicht zum maskierten Rendering (rh1) ⇒ abgewiesen');
    $info = $l->registerAttempt($r, 2, $zone, $recs, $serial, $file, $mask);
    T::throws(LogicException::class, fn () => $l->recordZoneTarget($r, $info['attemptId'], 1, PublishTargetClassifier::classify('zone_sync', ['httpCode' => 200, 'body' => '{"status":"success"}'])), 'J06', 'J4-Schreibvorgang in offener Transaktion abgewiesen');
    $p->commit();
    $ok = PublishTargetClassifier::classify('zone_sync', ['httpCode' => 200, 'body' => '{"status":"success"}']);
    $bad = PublishTargetClassifier::classify('zone_sync', ['httpCode' => 422, 'body' => '{"status":"error","message":"x"}']);
    T::ok($l->recordZoneTarget($r, $info['attemptId'], 1, $ok), 'J07', 'Ergebnis eingetragen');
    $row = J::targets($p, $info['attemptId'])[1];
    T::ok(!$l->recordZoneTarget($r, $info['attemptId'], 1, $bad) && J::targets($p, $info['attemptId'])[1] === $row, 'J08', 'Endzustand wird nicht überschrieben (J4a)');
    T::ok($l->finishAttempt($r, $info['attemptId'], 'published') && !$l->finishAttempt($r, $info['attemptId'], 'failed'), 'J09', 'Versuchsergebnis genau einmal');
    T::ok($l->recordConfTarget($r, $info['attemptId'], 1, $bad, str_repeat('b', 64)) && !$l->recordConfTarget($r, $info['attemptId'], 1, $ok, null), 'J10', 'conf_sync genau einmal');
    T::ok($l->finishRun($r, 'published'), 'J11', 'Lauf abgeschlossen');
    T::throws(LogicException::class, fn () => $l->finishRun($r, 'published'), 'J12', 'geschlossenes Token abgewiesen');
    T::throws(LogicException::class, fn () => $l->recordZoneTarget($r, $info['attemptId'], 1, $ok), 'J12b', 'kein Ergebnis nach Laufabschluss');
    $l->releaseRunLock($r);
    $snap = static fn (): array => [H2b::all($p, 'SELECT * FROM zone_publish_targets ORDER BY attempt_id, server_id'), H2b::all($p, 'SELECT * FROM zone_publish_attempts ORDER BY id'), J::runs($p)];
    $terminal = $snap();
    $l->abandonStale();
    T::eq($terminal, $snap(), 'J13', 'J6 verändert keine Endzustände');
    T::eq([$info['revisionNo'], $info['revisionBasis']], [H2b::head($p, 2)[0], 'head'], 'J14', 'Revisionsbezug aus Head bei identischem Inhalt');
    $p->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchAll();
    $clean($p, 'J14');
}

/* ================================================================ L Lebendigkeit, Abbruch, Wiederverwendung */
if ($run('L')) {
    T::section('L Lebendigkeit (J5/E73), Verbindungsabbruch, Prozessende, Verbindungs-ID-Wiederverwendung, J6');
    $abort = static function (string $id, string $how) use ($clean): void {
        $p = J::reset();
        $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
        J::control([2 => ['zone_sync.php' => 'delay:8']]);
        $marker = J::$sbx . "/marker-$id";
        $w = J::spawn('publish_all', $marker);
        H2b::waitFile($marker);
        $conn = (int)file_get_contents($marker);
        // warten, bis Server 1 bestätigt ist (Server 2 hängt im verzögerten Agenten)
        $end = microtime(true) + 15;
        while (microtime(true) < $end && (int)H2b::one($p, "SELECT COUNT(*) FROM zone_publish_targets WHERE server_id = 1 AND zone_sync = 'ok'") === 0) {
            usleep(50000);
        }
        $rid = J::lastRunId($p);
        $reader = new PublishJournalReader($p);
        $aid = (int)H2b::one($p, 'SELECT id FROM zone_publish_attempts WHERE run_id = ?', [$rid]);
        T::eq([true, 'sending', 'ok'], [$reader->runAlive($rid), $reader->effectiveTargets($aid)[2]['zone'], $reader->effectiveTargets($aid)[1]['zone']], "{$id}a", 'während des Laufs: lebendig, offenes Ziel „sending“');
        if ($how === 'kill_connection') {
            $p->exec("KILL $conn");
        } else {
            posix_kill($w['pid'], SIGKILL);
        }
        $res = H2b::join($w);
        usleep(300000);
        T::eq([false, 'running', 'unknown', 'unknown', 'ok', 'unknown'],
            [$reader->runAlive($rid), J::runs($p)[0]['status'], $reader->effectiveTargets($aid)[2]['zone'], $reader->effectiveTargets($aid)[2]['conf'], $reader->effectiveTargets($aid)[1]['zone'], $reader->effectiveOutcome($aid)],
            "{$id}b", "nach $how: Lauf nicht lebendig, offene Ziele und Versuch unknown, Bestätigung von Server 1 bleibt");
        T::ok(J::locksFree($p), "{$id}c", 'Publish-Lock und Laufsperre mit der Verbindung freigegeben');
        T::eq('1:2', J::flags($p)['changed'][0], "{$id}d", 'übernommene Zone bleibt 2 (Tri-State unverändert)');
        J::control([]);
        sleep(9);   // verzögerter Agent schließt ab
        $rep = J::publishAll($p);
        $a = J::targets($p, $aid);
        T::eq(['success', 'abandoned', 'abandoned', 'ok', 'abandoned', 'abandoned', '1:0'],
            [$rep['status'], J::runs($p)[0]['status'], $a[2]['zone_sync'], $a[1]['zone_sync'], $a[2]['conf_sync'], H2b::one($p, 'SELECT outcome FROM zone_publish_attempts WHERE id = ?', [$aid]), J::flags($p)['changed'][0]],
            "{$id}e", 'nächster Lauf: J6 abandoned (Endzustand von Server 1 bleibt), Zone erneut veröffentlicht');
        $clean($p, $id);
    };
    $abort('L01', 'kill_connection');
    $abort('L02', 'sigkill');

    // L03 Verbindungs-ID-Wiederverwendung (z. B. nach Neustart des Datenbankservers): gleiche connection_id, aber ohne
    // laufeigene Sperre ⇒ nicht lebendig; Positivkontrolle mit beiden Sperren
    $p = J::reset();
    $p->query("SELECT GET_LOCK('dnsmanager_publish_all', 0)")->fetchAll();
    $cid = (int)H2b::one($p, 'SELECT CONNECTION_ID()');
    $p->exec("INSERT INTO publish_runs (kind, requested_zone_id, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id) VALUES ('all', NULL, 0, $cid, 0, 'system', 'cli', '33333333-3333-4333-8333-333333333333')");
    $rid = (int)$p->lastInsertId();
    $reader = new PublishJournalReader($p);
    T::ok(!$reader->runAlive($rid), 'L03', 'gleiche Verbindungs-ID hält den Publish-Lock, aber nicht die Laufsperre ⇒ nicht lebendig (konservativ unknown)');
    $lock = PublishLedger::runLockName(H2b::$db, $rid);
    $p->query("SELECT GET_LOCK('$lock', 0)")->fetchAll();
    T::ok($reader->runAlive($rid), 'L03b', 'Positivkontrolle: beide Sperren auf der registrierten Verbindung ⇒ lebendig');
    $p->query("SELECT RELEASE_LOCK('$lock')")->fetchAll();
    $q = H2b::pdo();
    $q->query("SELECT GET_LOCK('$lock', 0)")->fetchAll();
    T::ok(!$reader->runAlive($rid), 'L03c', 'Laufsperre auf fremder Verbindung ⇒ nicht lebendig');
    $p->query("SELECT RELEASE_LOCK('dnsmanager_publish_all')")->fetchAll();
    // L04 J6 verweigert die Markierung, solange die Laufsperre gehalten wird
    $sum = H2b::businessSum($p);
    $rep = J::publishAll($p);
    T::eq(['exception', 'running', $sum, 0], [$rep['status'], J::runs($p)[0]['status'], H2b::businessSum($p), $e81($p, 'L04')], 'L04', 'J6 markiert keinen Lauf mit gehaltener Laufsperre; Abbruch ohne Seiteneffekt (kein schreibender Agentaufruf)');
    $q->query("SELECT RELEASE_LOCK('$lock')")->fetchAll();
    $q = null;
    $rep = J::publishAll($p);
    T::eq(['success', 'abandoned'], [$rep['status'], J::runs($p)[0]['status']], 'L05', 'ohne Sperren (wie nach Neustart): J6 markiert abandoned');
    T::eq(PublishLedger::runLockName('dnsmanager', 7), PublishLedger::runLockName('dnsmanager', 7), 'L06', 'Laufsperrenname deterministisch');
    T::ok(strlen(PublishLedger::runLockName(str_repeat('x', 64), PHP_INT_MAX)) <= 64 && PublishLedger::runLockName('a', 1) !== PublishLedger::runLockName('b', 1), 'L06b', 'Laufsperrenname ≤ 64 Zeichen, je Datenbank verschieden');
    $clean($p, 'L06');
}

/* ================================================================ N Nebenläufigkeit */
if ($run('N')) {
    T::section('N Nebenläufigkeit (Lock-Wait, Fachänderung während der Verteilung, zweiter Lauf)');
    // N01 Generator wartet auf die Zonensperre einer Fachtransaktion; Versuch dokumentiert den danach festgeschriebenen Stand
    $p = J::reset();
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
    $mh = J::$sbx . '/marker-n01h';
    $hold = J::spawn('hold_zone', '1', '3', $mh);
    H2b::waitFile($mh);
    $mp = J::$sbx . '/marker-n01p';
    $pub = J::spawn('publish_all', $mp);
    H2b::waitFile($mp);
    $waits = 0;
    $end = microtime(true) + 2.5;
    while (microtime(true) < $end && $waits === 0) {
        $waits = max($waits, H2b::lockWaits($p));
        usleep(100000);
    }
    $rh = H2b::join($hold);
    $rp = H2b::join($pub);
    $a = J::attemptFor($p, J::lastRunId($p), 1);
    T::ok($waits > 0, 'N01', 'tatsächlicher Lock-Wait des Publish auf die Zonensperre (zones X) der Fachtransaktion nachgewiesen');
    T::eq([H2b::currentHash($p, 1), 'head_mismatch'], [$a['content_hash'], $a['revision_basis']], 'N01b', 'content_hash = nach dem Warten festgeschriebener Stand (ohne Historie geändert ⇒ head_mismatch)');
    T::ok(str_contains((string)J::installed(1, 'alpha.h2b.test'), '192.0.2.99'), 'N01c', 'verteilte Datei enthält den festgeschriebenen Stand');
    $clean($p, 'N01');

    // N02 Fachänderung während der Verteilung: Versuch behält seinen Stand, Zone bleibt ausstehend
    $p = J::reset();
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
    $pre = H2b::head($p, 1);
    J::control([2 => ['zone_sync.php' => 'delay:3']]);
    $mp = J::$sbx . '/marker-n02';
    $pub = J::spawn('publish_all', $mp);
    $end = microtime(true) + 10;
    while (microtime(true) < $end && (int)H2b::one($p, 'SELECT COUNT(*) FROM zone_publish_attempts') === 0) {
        usleep(30000);
    }
    $r = H2b::records($p)->createRecord(H2b::admin(), 1, H2b::record('TXT', 'waehrend', '"n02"'));
    $rp = H2b::join($pub);
    J::control([]);
    $a = J::attemptFor($p, J::lastRunId($p), 1);
    T::ok($r->ok && $a['content_hash'] === $pre[1] && (int)$a['revision_no'] === $pre[0] && H2b::head($p, 1)[0] === $pre[0] + 1, 'N02', 'Versuch dokumentiert Revision N, nicht den währenddessen entstandenen Head N+1');
    T::eq(['1:1', true], [J::flags($p)['changed'][0], in_array(1, $rp['report']['flags']['keptPendingZoneIds'] ?? [], true)], 'N02b', 'Zone bleibt ausstehend (keptPending, unverändert)');
    $clean($p, 'N02');

    // N03 zweiter Lauf während eines laufenden: locked, kein Lauf, keine Markierung
    $p = J::reset();
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
    J::control([2 => ['zone_sync.php' => 'delay:3']]);
    $mp = J::$sbx . '/marker-n03';
    $pub = J::spawn('publish_all', $mp);
    H2b::waitFile($mp);
    usleep(500000);
    $rep = J::publishAll($p);
    $rp = H2b::join($pub);
    J::control([]);
    T::eq(['locked', 1, 'finished', 'success'], [$rep['status'], count(J::runs($p)), J::runs($p)[0]['status'], $rp['report']['status']], 'N03', 'paralleler Lauf: locked ohne Registrierung und ohne J6; erster Lauf unberührt');
    $clean($p, 'N03');

    // N04 E62: Zielmenge ändert sich trotz Lock (nur bei Invariantenbruch, z. B. Lock-Verlust; hier ohne Lock per SQL
    // simuliert): ein registriertes, nicht mehr zugewiesenes Ziel wird nicht beliefert und als failed eingetragen
    $p = J::reset();
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
    J::control([1 => ['zone_sync.php' => 'delay:3']]);
    $mp = J::$sbx . '/marker-n04';
    $pub = J::spawn('publish_all', $mp);
    $end = microtime(true) + 10;
    while (microtime(true) < $end && (int)H2b::one($p, 'SELECT COUNT(*) FROM zone_publish_attempts WHERE zone_id = 1') === 0) {
        usleep(30000);
    }
    $p->exec('DELETE FROM zone_servers WHERE zone_id = 1 AND server_id = 2');
    $rp = H2b::join($pub);
    J::control([]);
    $a = J::attemptFor($p, J::lastRunId($p), 1);
    $t = J::targets($p, (int)$a['id']);
    T::eq(['ok', 'failed', 'target_changed', 0], [$t[1]['conf_sync'], $t[2]['conf_sync'], $t[2]['conf_error_code'], count(preg_grep('/^127\.0\.0\.5 conf_sync\.php zone=alpha/', J::agentCalls()))],
        'N04', 'registriertes Ziel nicht mehr zugewiesen: keine Konfiguration gesendet, Ziel failed (target_changed)');
}

/* ================================================================ R Reaktivierung */
if ($run('R')) {
    T::section('R Reaktivierung (E70): Wasserstand statt Journal-Schreibzugriff aus der Fachtransaktion');
    $p = J::reset();
    // Server 2 darf nicht Master sein (Deaktivierung sonst gesperrt): delta zusätzlich Server 1 als Master
    $p->exec('UPDATE zone_servers SET is_master = 0 WHERE zone_id = 6 AND server_id = 2');
    $p->exec('INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (6, 1, 1)');
    $p->exec('UPDATE zones SET changed = 1 WHERE id = 1');
    J::publishAll($p);
    $run1 = J::runs($p)[0];
    $sv = H2b::servers($p);
    $d = $sv->updateServer(H2b::admin(), 2, new ServerInput('ns2.h2b.test', '192.0.2.2', '', '127.0.0.5', '', false, false));
    T::ok($d->ok, 'R00', 'Server 2 deaktiviert');
    $before = count(J::runs($p));
    $r = $sv->updateServer(H2b::admin(), 2, new ServerInput('ns2.h2b.test', '192.0.2.2', '', '127.0.0.5', '', false, true));
    $reader = new PublishJournalReader($p);
    $re = $reader->reactivations(2);
    T::ok($r->ok && count($re) === 1 && count(J::runs($p)) === $before, 'R01', 'Reaktivierung als Audit-Ereignis; kein Journal-Schreibzugriff aus der Fachtransaktion');
    T::ok(PublishJournalReader::invalidatedByReactivation((int)$run1['history_watermark'], $re), 'R02', 'Bestätigung des früheren Laufs ist für den reaktivierten Server veraltet (unknown)');
    J::publishAll($p);
    $run2 = J::runs($p)[1];
    T::ok(!PublishJournalReader::invalidatedByReactivation((int)$run2['history_watermark'], $re) && (string)$run2['full_rebuild'] === '1', 'R03', 'Lauf nach der Reaktivierung (Full Rebuild) ist nicht betroffen');
    $clean($p, 'R03');
}

/* ================================================================ X Stresslauf */
if ($run('X')) {
    T::section('X Stresslauf mit Invarianten');
    $p = J::reset();
    mt_srand(4711);
    $serialSteps = [];
    for ($i = 0; $i < 10; $i++) {
        $z = [1, 2, 3, 4, 6][mt_rand(0, 4)];
        if ($z !== 3) {
            H2b::records($p)->createRecord(H2b::admin(), $z, H2b::record('TXT', "x$i", "\"s$i\""));
        }
        if (mt_rand(0, 3) === 0) {
            $p->exec('UPDATE system_status SET bind_dirty = 1 WHERE id = 1');
        }
        J::control(mt_rand(0, 2) === 0 ? [2 => ['zone_sync.php' => ['reject', 'garbage', 'close'][mt_rand(0, 2)]]] : []);
        $pre = array_column(H2b::all($p, 'SELECT id, soa_serial FROM zones'), 'soa_serial', 'id');
        J::publishAll($p);
        $rid = J::lastRunId($p);
        foreach (H2b::all($p, 'SELECT id, soa_serial FROM zones') as $zr) {
            $att = J::attemptFor($p, $rid, (int)$zr['id']);
            $changed = (int)$zr['soa_serial'] !== (int)$pre[$zr['id']];
            $serialSteps[] = $changed === ($att !== null) && ($att === null || (int)$att['soa_serial'] === (int)$zr['soa_serial']);
        }
    }
    J::control([]);
    T::ok(!in_array(false, $serialSteps, true), 'X01', 'Serial verbraucht ⇔ genau ein Versuch mit dieser Serial (J2), über alle Läufe');
    T::eq([0, 0, 0, 0], [
        (int)H2b::one($p, "SELECT COUNT(*) FROM publish_runs WHERE status <> 'finished'"),
        (int)H2b::one($p, 'SELECT COUNT(*) FROM zone_publish_attempts WHERE outcome IS NULL'),
        (int)H2b::one($p, "SELECT COUNT(*) FROM zone_publish_targets WHERE zone_sync IN ('sending','abandoned') OR conf_sync IN ('sending','abandoned')"),
        (int)H2b::one($p, "SELECT COUNT(*) FROM publish_cleanup_results WHERE status IN ('sending','abandoned')"),
    ], 'X02', 'ohne Abbruch: alle Läufe abgeschlossen, keine offenen Vorgänge');
    T::eq(0, (int)H2b::one($p, 'SELECT COUNT(*) FROM (SELECT zone_id FROM zone_publish_attempts a GROUP BY zone_id HAVING COUNT(*) <> COUNT(DISTINCT soa_serial)) g'), 'X03', 'keine Serial zweimal je Zone');
    T::eq(0, (int)H2b::one($p, 'SELECT COUNT(*) FROM zone_publish_attempts a JOIN zone_publish_attempts b ON a.zone_id = b.zone_id AND a.id < b.id AND a.soa_serial >= b.soa_serial'), 'X04', 'Serials je Zone streng steigend in Versuchsreihenfolge (J7: Ordnung über IDs)');
    T::eq([], H2b::invariants($p), 'X05', 'History-Invarianten erfüllt');
    $secret = 0;
    foreach (['publish_runs', 'zone_publish_attempts', 'zone_publish_targets', 'publish_cleanup_results'] as $t) {
        foreach (H2b::all($p, "SELECT * FROM `$t`") as $row) {
            $txt = json_encode($row);
            $secret += preg_match('/H2BTOKEN|H2BFIXTURE|\$2y\$|h2b_dynkonto|192\.0\.2\.99|' . preg_quote(H2b::DYN_PASSWORD, '/') . '/', (string)$txt);
        }
    }
    T::eq(0, $secret, 'X06', 'keine Tokens, Passwort-Hashes, DynDNS-Kontonamen oder Client-IPs im Journal');
    $clean($p, 'X06');
}

/* ================================================================ B Byte-Gleichheit mit dem Basis-Commit */
if ($run('B')) {
    T::section('B Zonendateien byte-gleich mit dem Basis-Commit (reine Extraktion zone_file_render)');
    if ($baseApp === '' || !is_file("$baseApp/web/inc/deploy/bind_file_generator.php")) {
        T::ok(false, 'B00', 'Basis-App fehlt (dritter Parameter)');
    } else {
        $render = static function (string $appDir) {
            $p = J::reset();
            // RRset mit abweichenden TTLs (RFC 2181 §5.2) und Systemrecords
            $p->exec("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (1, 'multi', 'A', '192.0.2.70', 600), (1, 'multi', 'A', '192.0.2.71', 300), (1, 'nottl', 'TXT', '\"x\"', NULL)");
            $out = [];
            foreach ([1, 2, 3, 4, 6] as $z) {
                $f = J::$sbx . "/render-$z-" . md5($appDir);
                $cmd = ['php', __DIR__ . '/journal_worker.php', $appDir, H2b::$db, H2b::$iso, J::$sbx, 'render', (string)$z, $f];
                $q = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $res = H2b::join(['proc' => $q, 'pipes' => $pipes, 'start' => microtime(true)]);
                $out[$z] = ($res['ok'] ?? false) ? (string)file_get_contents($f) : 'FEHLER ' . json_encode($res);
            }
            return $out;
        };
        $base = $render($baseApp);
        $new = $render($app);
        T::eq(array_map('md5', $base), array_map('md5', $new), 'B01', 'alle Zonendateien byte-identisch (5 Zonen inkl. RRset-TTL, Reverse, Records ohne TTL)');
        T::ok(!str_starts_with($base[1], 'FEHLER') && str_contains($base[1], "multi\t300\tIN\tA\t192.0.2.70"), 'B01b', 'Basis-Datei erzeugt (RRset-TTL vereinheitlicht)');
    }
}

/* ================================================================ Z Schutz */
T::section('Z Schutz des Bestands (Q1)');
T::eq($bindBefore, J::treeSum('/etc/bind/zones'), 'Z01', '/etc/bind/zones vorher/nachher byte-gleich (keine Wirkung auf die installierten Agenten)');
T::finish("journal $iso");
