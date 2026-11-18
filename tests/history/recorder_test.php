<?php
/**
 * Datei: tests/history/recorder_test.php
 * Zweck: Datenbanktests der History-Erfassung in den Schreibpfaden (AP1/H2b, R-H1–R-H9, E29–E42) auf einer isolierten
 *        Datenbank dnsmgr_t21_r<RUN>_rec{rr|rc}, unter REPEATABLE READ (innodb_snapshot_isolation = ON) bzw.
 *        READ COMMITTED. Eigentum wie tests/history/owned.sh.
 *
 * Abschnitte (Fall-IDs):
 *   P  alle elf Schreibpfade, No-Change-Pfade, Pfade ohne DNS-Wirkung
 *   A  Berechtigung: abgelehnte Zugriffe ohne Historie
 *   D  Drift, Lazy-Baseline, Abgleich gelöschter Zonen, fehlender Head in der Fachtransaktion
 *   G  Wächter: Bindung an Verbindung und Transaktion (E39)
 *   S  ZoneSnapshotStore: Duplicate-Key-Pfad, Nebenläufigkeit, Abweichung, Rollback (E33)
 *   F  Fehlerinjektion (Trigger) je History-Schreibvorgang: vollständiger Rollback, bisheriger Fehlercode
 *   N  Nebenläufigkeit mit nachgewiesenem Lock-Wait; Zonen ohne Head parallel (E35) mit Gegenprobe
 *   X  Stresslauf mit Invarianten; Suche nach Geheimnissen
 *
 * Aufruf (als root über den MariaDB-Socket): php recorder_test.php <App-Verzeichnis> <RR|RC>
 */

require_once __DIR__ . '/h2b_lib.php';

H2b::init($argv[1] ?? '', $argv[2] ?? '', 'rec');
$iso = H2b::$iso;
$W = rtrim(sys_get_temp_dir(), '/') . '/dnsmgr-h2a-db-rec' . strtolower($iso) . '-' . getmypid();
mkdir($W, 0700);
register_shutdown_function(static function () use ($W): void { exec('rm -rf ' . escapeshellarg($W)); });
$only = getenv('H2B_ONLY') ?: '';   // optional: nur Abschnitte, z. B. "P,D" (Mutationsproben)
$run = static fn (string $s): bool => $only === '' || in_array($s, explode(',', $only), true);
$codes = static fn (ServiceResult $r): array => array_map(fn ($m) => $m->code, $r->messages);

echo "Recorder-Tests H2b ($iso) – Datenbank " . H2b::$db . "\n";

/* ============================================================================================ */
if ($run('P')) {
    T::section("P Schreibpfade ($iso)");
    $p = H2b::reset();
    T::eq([], H2b::invariants($p), 'P00', 'Ausgangslage: Baseline vollständig, Invarianten erfüllt');
    $a = H2b::admin();

    // P01 Zonenanlage
    $m = H2b::mark($p);
    $r = H2b::zones($p)->createZone($a, H2b::newZone('neu.h2b.test', [1, 2], 1));
    $zid = (int)$r->lastZoneId;
    T::ok($r->ok, 'P01', 'createZone erfolgreich');
    T::eq(["zone.create user:21/h2b_admin web: $zid#1:create"], H2b::brief($p, $m), 'P01b', 'eine Änderungsmenge zone.create, Revision 1 create, Label aus der DB');
    $h = H2b::head($p, $zid);
    T::eq([1, H2b::currentHash($p, $zid), 'tracked', false], $h, 'P01c', 'Head tracked = aktueller Inhalt');
    $sum = json_decode((string)H2b::one($p, 'SELECT diff_summary FROM zone_revisions WHERE zone_id = ?', [$zid]), true);
    T::eq(['added' => 2, 'removed' => 0, 'changed' => 0], $sum['servers'] ?? null, 'P01d', 'Diff-Zusammenfassung der Anlage (2 Server)');

    // P02 Zonenänderung TTL (admin)
    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['ttl' => '7200']));
    T::ok($r->ok && in_array('zone.update.success', $codes($r), true), 'P02', 'updateZone TTL erfolgreich');
    T::eq(['zone.update user:21/h2b_admin web: 2#2:change'], H2b::brief($p, $m), 'P02b', 'Revision change in Zone 2');
    T::eq(['ttl'], json_decode((string)H2b::one($p, 'SELECT diff_summary FROM zone_revisions WHERE zone_id = 2 AND revision_no = 2'), true)['zone_fields'], 'P02c', 'Zusammenfassung nennt nur Feldnamen');

    // P03 nur Beschreibung (kein DNS-Inhalt) → keine Revision; P04 unverändert → keine Historie
    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['description' => 'nur Text']));
    // AP1/H2c: ohne Revision (R-H4), aber mit genau einer Änderungsmenge für das Audit-Ereignis
    T::ok($r->ok && H2b::brief($p, $m) === ['zone.update user:21/h2b_admin web: '], 'P03', 'nur Beschreibung: keine Revision, nur Änderungsmenge des Audit-Ereignisses (H2c)');
    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2));
    T::ok($r->ok && in_array('zone.update.unchanged', $codes($r), true) && H2b::brief($p, $m) === [], 'P04', 'unverändert: keine Historie');

    // P05 Serverzuweisung (NS-/Glue-Neuaufbau)
    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['serverIds' => [1, 2]]));
    T::ok($r->ok, 'P05', 'Zuweisung Server 2 zu Zone 2');
    T::eq(['zone.update user:21/h2b_admin web: 2#3:change'], H2b::brief($p, $m), 'P05b', 'Revision mit Zuweisung');
    $sum = json_decode((string)H2b::one($p, 'SELECT diff_summary FROM zone_revisions WHERE zone_id = 2 AND revision_no = 3'), true);
    T::eq([['added' => 1, 'removed' => 0, 'changed' => 0], 1], [$sum['servers'], $sum['records']['added']], 'P05c', 'Server und NS-Record hinzugefügt');

    // P06 zoneadmin
    $m = H2b::mark($p);
    $z = H2b::zoneadmin();
    $r = H2b::zones($p)->updateZone($z, 1, H2b::zoneInput($p, $z, 1, ['ttl' => '1800']));
    T::ok($r->ok, 'P06', 'zoneadmin ändert eigene Zone');
    T::eq(['zone.update user:22/h2b_zoneadmin web: 1#2:change'], H2b::brief($p, $m), 'P06b', 'Akteur zoneadmin mit Label');

    // P07 Zonenlöschung
    $m = H2b::mark($p);
    $before = H2b::head($p, 6);
    $r = H2b::zones($p)->deleteZone($a, 6);
    T::ok($r->ok, 'P07', 'deleteZone erfolgreich');
    T::eq(['zone.delete user:21/h2b_admin web: 6#2:delete'], H2b::brief($p, $m), 'P07b', 'Revision delete');
    $h = H2b::head($p, 6);
    T::ok($h !== null && $h[0] === 2 && $h[1] === $before[1] && $h[3] === true, 'P07c', 'Head: Inhalt vor dem DELETE, deleted_at gesetzt (R-H5)');
    T::eq(1, (int)H2b::one($p, 'SELECT bind_dirty FROM system_status'), 'P07d', 'bind_dirty wie bisher');

    // P08–P10 Records
    $m = H2b::mark($p);
    $r = H2b::records($p)->createRecord($a, 4, H2b::record('A', 'host', '192.0.2.40'));
    T::eq(['record.create user:21/h2b_admin web: 4#2:change'], H2b::brief($p, $m), 'P08', 'createRecord: Revision change');
    $rid = (int)H2b::one($p, "SELECT id FROM records WHERE zone_id = 4 AND name = 'host'");
    $m = H2b::mark($p);
    $r = H2b::records($p)->updateRecord($a, $rid, 4, H2b::record('A', 'host', '192.0.2.41'));
    T::eq(['record.update user:21/h2b_admin web: 4#3:change'], H2b::brief($p, $m), 'P09', 'updateRecord: Revision change');
    $m = H2b::mark($p);
    $r = H2b::records($p)->updateRecord($a, $rid, 4, H2b::record('A', 'host', '192.0.2.41'));
    T::ok($r->ok && in_array('record.update.no_changes', $codes($r), true) && H2b::brief($p, $m) === [], 'P09b', 'no_changes: keine Historie');
    $ids = [(int)H2b::one($p, "SELECT id FROM records WHERE zone_id = 2 AND name = 'alt'"), $rid];
    $m = H2b::mark($p);
    $r = H2b::records($p)->deleteRecords($a, $ids);
    T::ok($r->ok, 'P10', 'deleteRecords über zwei Zonen');
    T::eq(['record.delete user:21/h2b_admin web: 2#4:change 4#4:change'], H2b::brief($p, $m), 'P10b', 'eine Änderungsmenge, je Zone eine Revision (R-H6)');

    // P11–P13 AutoPTR
    $m = H2b::mark($p);
    $r = H2b::records($p)->createRecord($a, 1, H2b::record('A', 'srv', '192.0.2.77'), true);
    $outcome = array_values(array_filter(array_map(fn ($x) => $x->context['ptrOutcome'] ?? null, $r->messages)));
    T::eq(['created'], $outcome, 'P11', 'AutoPTR angelegt');
    $cs = H2b::since($p, $m);
    T::eq(['record.create user:21/h2b_admin web: 1#3:change', 'record.auto_ptr user:21/h2b_admin web: 3#2:change'], H2b::brief($p, $m), 'P11b', 'T1 und T2 mit eigenen Änderungsmengen (R-H7)');
    T::ok(count($cs) === 2 && $cs[0]['corr'] === $cs[1]['corr'], 'P11c', 'T2 trägt die Korrelation von T1');
    $dep = H2b::all($p, 'SELECT change_set_id, zone_id, revision_no, record_name, record_type, record_content, dep_change_set_id, dep_zone_id, dep_revision_no, dep_record_name, dep_record_type, dep_record_content, kind FROM history_dependencies');
    T::eq([[(string)$cs[1]['id'], '3', '2', '77', 'PTR', 'srv.alpha.h2b.test.', (string)$cs[0]['id'], '1', '3', 'srv', 'A', '192.0.2.77', 'auto_ptr']],
        array_map(fn ($d) => array_map('strval', array_values($d)), $dep), 'P11d', 'history_dependencies: PTR ↔ Forward-Record mit Revisionen');
    $m = H2b::mark($p);
    $r = H2b::records($p)->createRecord($a, 1, H2b::record('A', 'srv2', '192.0.2.77'), true);
    T::ok(in_array('record.create.ptr_duplicate', $codes($r), true) && H2b::brief($p, $m) === ['record.create user:21/h2b_admin web: 1#4:change']
        && (int)H2b::one($p, 'SELECT COUNT(*) FROM history_dependencies') === 1, 'P12', 'AutoPTR abweichend vorhanden: nur T1, keine Abhängigkeit');
    $m = H2b::mark($p);
    $p->exec("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (3, '79', 'PTR', 'srv4.alpha.h2b.test.', 3600)");
    $p->exec("UPDATE zone_history_state SET head_revision_no = head_revision_no WHERE zone_id = 3");
    $m = H2b::mark($p);
    $r = H2b::records($p)->createRecord($a, 4, H2b::record('A', 'srv4', '192.0.2.79'), true);
    $r2 = H2b::records($p)->createRecord($a, 4, H2b::record('A', '*', '192.0.2.88'), true);
    T::ok(in_array('record.create.ptr_duplicate', $codes($r), true) && in_array('record.create.ptr_skipped_wildcard', $codes($r2), true)
        && (int)H2b::one($p, 'SELECT COUNT(*) FROM history_dependencies') === 1
        && count(array_filter(H2b::brief($p, $m), fn ($b) => str_starts_with($b, 'record.auto_ptr'))) === 0, 'P13', 'AutoPTR abweichend bzw. übersprungen: keine T2-Änderungsmenge, keine Abhängigkeit');
    $p->exec("DELETE FROM records WHERE zone_id = 3 AND name = '79'");
    $p->exec("DELETE FROM records WHERE zone_id = 4 AND name IN ('srv4', '*')");
    H2b::records($p)->createRecord($a, 3, H2b::record('TXT', 'resync', '"r"'));   // Drift der Testvorbereitung erfassen
    H2b::records($p)->deleteRecords($a, [(int)H2b::one($p, "SELECT id FROM records WHERE zone_id = 3 AND name = 'resync'")]);
    H2b::records($p)->createRecord($a, 4, H2b::record('TXT', 'resync', '"r"'));
    H2b::records($p)->deleteRecords($a, [(int)H2b::one($p, "SELECT id FROM records WHERE zone_id = 4 AND name = 'resync'")]);

    // P14 Serverumbenennung (mehrzonig), P15 nur api_ip
    $nx = fn (int $z): int => H2b::head($p, $z)[0] + 1;
    [$n1, $n2, $n7] = [$nx(1), $nx(2), $nx($zid)];
    $m = H2b::mark($p);
    $s = H2b::all($p, 'SELECT * FROM servers WHERE id = 2')[0];
    $r = H2b::servers($p)->updateServer($a, 2, new ServerInput('ns2b.h2b.test', $s['dns_ip4'], '', $s['api_ip'], '', false, true));
    T::ok($r->ok, 'P14', 'Server 2 umbenannt');
    T::eq(["server.update user:21/h2b_admin web: 1#$n1:change 2#$n2:change $zid#$n7:change"], H2b::brief($p, $m), 'P14b', 'eine Änderungsmenge server.update, Revisionen in allen zugewiesenen Zonen');
    $m = H2b::mark($p);
    $r = H2b::servers($p)->updateServer($a, 2, new ServerInput('ns2b.h2b.test', $s['dns_ip4'], '', '192.0.2.222', '', false, true));
    T::ok($r->ok && H2b::brief($p, $m) === ['server.update user:21/h2b_admin web: '], 'P15', 'nur api_ip: keine Revision, nur Änderungsmenge des Audit-Ereignisses (H2c)');

    // P16–P17 DynDNS-Konten, P18–P19 DynDNS-API, P20 ohne DNS-Wirkung
    [$n1, $n4] = [$nx(1), $nx(4)];
    $m = H2b::mark($p);
    $r = H2b::dyndns($p)->updateAccount($a, 1, new DyndnsAccountInput('h2b_dynkonto', '', 'dyn', 4));
    T::ok($r->ok, 'P16', 'DynDNS-Konto in Zone 4 verschoben');
    T::eq(["dyndns_account.update user:21/h2b_admin web: 1#$n1:change 4#$n4:change"], H2b::brief($p, $m), 'P16b', 'eine Änderungsmenge mit Revisionen in alter und neuer Zone');
    $r = H2b::dyndns($p)->updateAccount($a, 1, new DyndnsAccountInput('h2b_dynkonto', '', 'dyn', 1));
    $n1 = $nx(1);
    $m = H2b::mark($p);
    $r = H2b::dyndnsApi($p)->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.51', null);
    $b = H2b::brief($p, $m);
    T::eq(["dyndns.api_update dyn:1 api: 1#$n1:change"], $b, 'P18', 'DynDNS-API: Akteur dyndns_account, Kanal api, kein Label');
    T::eq(1, (int)H2b::one($p, 'SELECT changed FROM zones WHERE id = 1'), 'P18b', 'Publish-Flag wie bisher (Test-Backend: ausstehend)');
    $m = H2b::mark($p);
    $r = H2b::dyndnsApi($p)->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.51', null);
    T::eq([], H2b::brief($p, $m), 'P19', 'DynDNS-API ohne Änderung: keine Historie');
    $m = H2b::mark($p);
    H2b::dyndns($p)->changePassword($a, 1, 'Neu-H2B-Passwort-99');
    $r = H2b::dyndns($p)->createAccount($a, new DyndnsAccountInput('h2b_zweit', 'Zweit-H2B-Passwort-1', 'zwei', 1));
    T::ok($r->ok && H2b::brief($p, $m) === ['dyndns_account.password user:21/h2b_admin web: ', 'dyndns_account.create user:21/h2b_admin web: '], 'P20', 'Passwortänderung und Kontoanlage: keine Revision, je eine Änderungsmenge der Audit-Ereignisse (H2c)');
    $n1 = $nx(1);
    $m = H2b::mark($p);
    $r = H2b::dyndns($p)->deleteAccount($a, 1);
    T::eq(["dyndns_account.delete user:21/h2b_admin web: 1#$n1:change"], H2b::brief($p, $m), 'P17', 'DynDNS-Konto gelöscht: Records entfernt, Revision');
    T::eq([], H2b::invariants($p), 'P99', 'Invarianten nach allen Pfaden');
}

/* ============================================================================================ */
if ($run('A')) {
    T::section("A Berechtigung ($iso)");
    $p = H2b::reset();
    $counts = H2b::historyCounts($p);
    $sum = H2b::businessSum($p);
    $z = H2b::zoneadmin();
    $r1 = H2b::records($p)->createRecord($z, 2, H2b::record('A', 'x', '192.0.2.5'));
    $r2 = H2b::zones($p)->updateZone($z, 2, new ZoneUpdateInput('100'));
    $r3 = H2b::records($p)->createRecord(H2b::inactive(), 1, H2b::record('A', 'x', '192.0.2.5'));
    $r4 = H2b::zones($p)->deleteZone(H2b::inactive(), 2);
    $r5 = H2b::servers($p)->updateServer($z, 1, new ServerInput('nsx.h2b.test', '192.0.2.1', '', '192.0.2.1', '', true, true));
    T::ok(!$r1->ok && in_array('record.create.access_denied', $codes($r1), true), 'A01', 'zoneadmin ohne Zuweisung: abgelehnt');
    T::ok(!$r2->ok && in_array('zone.update.access_denied', $codes($r2), true), 'A02', 'zoneadmin fremde Zone: abgelehnt');
    T::ok(!$r3->ok && !$r4->ok && !$r5->ok, 'A03', 'deaktivierter Akteur bzw. falsche Rolle: abgelehnt');
    T::eq($counts, H2b::historyCounts($p), 'A04', 'keine Historienzeilen durch abgelehnte Zugriffe');
    T::eq($sum, H2b::businessSum($p), 'A05', 'Fachtabellen unverändert');
    // Rechteentzug unter der Sperre: zoneadmin verliert Zuweisung vor der Transaktion
    $p->exec('DELETE FROM user_zones WHERE user_id = 22');
    $r = H2b::records($p)->createRecord($z, 1, H2b::record('A', 'y', '192.0.2.6'));
    T::ok(!$r->ok && H2b::historyCounts($p) === $counts, 'A06', 'entzogene Zuweisung: abgelehnt, keine Historie');
}

/* ============================================================================================ */
if ($run('D')) {
    T::section("D Drift, Lazy-Baseline, Abgleich ($iso)");
    $p = H2b::reset();
    $a = H2b::admin();

    // D01 Fremdänderung (Code ohne Historie) → Drift vor der Akteursrevision
    $p->exec("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (2, 'fremd', 'TXT', '\"ohne Historie\"', 300)");
    $m = H2b::mark($p);
    $r = H2b::records($p)->createRecord($a, 2, H2b::record('A', 'neu', '192.0.2.30'));
    $cs = H2b::since($p, $m);
    T::eq(['history.drift system web: 2#2:drift', 'record.create user:21/h2b_admin web: 2#3:change'], H2b::brief($p, $m), 'D01', 'Drift (system) vor der Revision des Akteurs (R-H3)');
    T::ok($cs[0]['corr'] === $cs[1]['corr'], 'D01b', 'Drift und Akteursrevision gleich korreliert');
    $d = json_decode((string)H2b::one($p, 'SELECT diff_summary FROM zone_revisions WHERE zone_id = 2 AND revision_no = 2'), true);
    $c = json_decode((string)H2b::one($p, 'SELECT diff_summary FROM zone_revisions WHERE zone_id = 2 AND revision_no = 3'), true);
    T::eq([1, 1], [$d['records']['added'], $c['records']['added']], 'D01c', 'Drift enthält nur die Fremdänderung, Akteursrevision nur die eigene');

    // D02 Drift bei Vorgang ohne DNS-Änderung
    $p->exec("UPDATE records SET ttl = 999 WHERE zone_id = 4");
    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 4, H2b::zoneInput($p, $a, 4, ['description' => 'D02']));
    T::eq(['history.drift system web: 4#2:drift', 'zone.update user:21/h2b_admin web: '], H2b::brief($p, $m), 'D02', 'nur Drift, keine Akteursrevision (H2c: Änderungsmenge des Audit-Ereignisses, gleiche Korrelation)');
    $p->exec("UPDATE records SET ttl = 998 WHERE zone_id = 4");
    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 4, H2b::zoneInput($p, $a, 4));
    T::ok(in_array('zone.update.unchanged', $codes($r), true) && H2b::brief($p, $m) === [], 'D02b', 'Rollback (unverändert): Drift nicht festgeschrieben');
    $r = H2b::zones($p)->updateZone($a, 4, H2b::zoneInput($p, $a, 4, ['description' => 'D02c']));
    T::eq(['history.drift system web: 4#3:drift', 'zone.update user:21/h2b_admin web: '], H2b::brief($p, $m), 'D02c', 'beim nächsten festgeschriebenen Vorgang erneut erkannt');

    // D03 Lazy-Baseline (Zone ohne Head, z. B. von altem Code angelegt)
    $p->exec("INSERT INTO zones (id, name, type, ttl, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns)
              VALUES (9, 'lazy.h2b.test', 'forward', 3600, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 0)");
    $p->exec("INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (9, 1, 1)");
    $p->exec("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (9, '@', 'NS', 'ns1.h2b.test.', 3600, 1)");
    $m = H2b::mark($p);
    $r = H2b::records($p)->createRecord($a, 9, H2b::record('A', 'www', '192.0.2.90'));
    T::eq(['history.baseline_lazy system web: 9#1:baseline', 'record.create user:21/h2b_admin web: 9#2:change'], H2b::brief($p, $m), 'D03', 'Lazy-Baseline vor der Fachtransaktion, danach Akteursrevision');
    T::eq('baseline_unverified', H2b::head($p, 9)[2], 'D03b', 'tracking baseline_unverified');

    // D04 Lazy-Baseline bleibt bei fachlicher Ablehnung bestehen (eigene, abgeschlossene Systemtransaktion)
    $p->exec("INSERT INTO zones (id, name, type, ttl, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns)
              VALUES (10, 'lazy2.h2b.test', 'forward', 3600, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 0)");
    $p->exec("INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (10, 1, 1)");
    $p->exec("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (10, '@', 'NS', 'ns1.h2b.test.', 3600, 1), (10, 'd', 'A', '192.0.2.91', 3600, NULL)");
    $m = H2b::mark($p);
    $r = H2b::records($p)->createRecord($a, 10, H2b::record('A', 'd', '192.0.2.91'));
    T::ok(in_array('record.create.duplicate', $codes($r), true) && H2b::brief($p, $m) === ['history.baseline_lazy system web: 10#1:baseline'], 'D04', 'Duplikat abgelehnt; Baseline (Systemvorgang) bleibt');

    // D05 Abgleich gelöschter Zonen
    $p->exec('DELETE FROM zones WHERE id = 10');
    $svc = new HistoryBaselineService($p);
    $dry = $svc->reconcile(false);
    $planned = array_values(array_filter($dry->messages, fn ($x) => $x->code === 'history.reconcile.zone_planned'));
    T::ok($dry->ok && count($planned) === 1 && $planned[0]->context['zoneId'] === 10 && H2b::head($p, 10)[3] === false, 'D05', 'Abgleich-Prüflauf nennt Zone 10, schreibt nichts');
    $m = H2b::mark($p);
    $res = $svc->reconcile(true);
    T::eq(['history.reconcile_deleted system cli: 10#2:delete'], H2b::brief($p, $m), 'D05b', 'Abgleich: Revision delete mit letztem bekannten Inhalt');
    T::ok(H2b::head($p, 10)[3] === true && H2b::head($p, 10)[1] === H2b::one($p, 'SELECT content_hash FROM zone_revisions WHERE zone_id = 10 AND revision_no = 1'), 'D05c', 'Head gelöscht, Hash unverändert');
    $m = H2b::mark($p);
    $res = $svc->reconcile(true);
    T::ok($res->ok && H2b::brief($p, $m) === [], 'D05d', 'Abgleich wiederholbar');

    // D06 Fehlender Head in der Fachtransaktion ⇒ Fehler, keine wartende Gap-Sperre
    $p->exec("INSERT INTO zones (id, name, type, ttl, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns)
              VALUES (11, 'ohnehead.h2b.test', 'forward', 3600, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 0)");
    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id = 11 FOR UPDATE')->fetchAll();
    $h = ZoneHistoryRecorder::open($p, HistoryContext::forUser($a, 'record.create'));
    T::throws(RuntimeException::class, fn () => $h->beginZones([11]), 'D06', 'beginZones: Zone ohne Head ⇒ RuntimeException');
    $p->rollBack();
    $h->close();
    $q = H2b::pdo();
    $q->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $q->beginTransaction();
    $q->query('SELECT id FROM zones WHERE id = 11 FOR UPDATE')->fetchAll();
    $snap = (new ZoneSnapshotReader($q))->lockAndRead(11);
    HistoryWriter::baselineZone($q, 11, $snap, HistoryContext::system('history.test', 'cli'));
    $q->commit();
    T::ok(H2b::head($q, 11) !== null, 'D06b', 'nach dem Rollback keine zurückbleibende Sperre auf den Head (andere Verbindung legt ihn ohne Wartezeit an)');
    T::eq([], H2b::invariants($p), 'D99', 'Invarianten');
}

/* ============================================================================================ */
if ($run('G')) {
    T::section("G Wächter und Lebenszyklus der Klammer ($iso)");
    $p = H2b::reset();
    $a = H2b::admin();
    $b = new BindZoneBuildBackend($p);
    $ctx = HistoryContext::forUser($a, 'record.create');

    $p->beginTransaction();
    T::throws(LogicException::class, fn () => $b->validateAndFlag(2), 'G01', 'ohne Erfassung: validateAndFlag abgelehnt (R-H8)');
    $p->rollBack();

    // G02 frühere Klammer (Rollback ohne close) autorisiert keine Folgetransaktion
    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    $h = ZoneHistoryRecorder::open($p, $ctx);
    $h->beginZones([2]);
    T::eq('ok', $b->validateAndFlag(2)['status'] === 'error' ? 'error' : 'ok', 'G02a', 'mit offener Klammer: zulässig');
    $p->rollBack();               // kein close()
    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    T::throws(LogicException::class, fn () => $b->validateAndFlag(2), 'G02', 'Klammer einer zurückgerollten Transaktion autorisiert die nächste nicht (Savepoint)');
    $p->rollBack();

    // G03 Commit ohne finish()/close(): ebenso
    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    $h = ZoneHistoryRecorder::open($p, $ctx);
    $h->beginZones([2]);
    $p->commit();                 // ohne finish() und close()
    $p->beginTransaction();
    T::throws(LogicException::class, fn () => $b->rebuildNsGlueAndFlag(2), 'G03', 'Klammer einer committeten Transaktion autorisiert die nächste nicht');
    $p->rollBack();

    // G04 nicht erfasste Zone, G05 nach finish(), G06 nach close()
    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id IN (2, 4) FOR UPDATE')->fetchAll();
    $h = ZoneHistoryRecorder::open($p, $ctx);
    $h->beginZones([2]);
    T::throws(LogicException::class, fn () => $b->validateAndFlag(4), 'G04', 'nicht erfasste Zone abgelehnt');
    $h->finish();
    T::throws(LogicException::class, fn () => $b->validateAndFlag(2), 'G05', 'nach finish() abgelehnt');
    $p->rollBack();
    $h->close();
    $p->beginTransaction();
    $h = ZoneHistoryRecorder::open($p, $ctx);
    $h->beginZones([2]);
    $h->close();
    T::throws(LogicException::class, fn () => $b->validateAndFlag(2), 'G06', 'nach close() abgelehnt');
    $p->rollBack();

    // G07 andere Verbindung: Klammer gilt nur für ihre Verbindung
    $q = H2b::pdo();
    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    $h = ZoneHistoryRecorder::open($p, $ctx);
    $h->beginZones([2]);
    $q->beginTransaction();
    T::throws(LogicException::class, fn () => (new BindZoneBuildBackend($q))->validateAndFlag(1), 'G07', 'Klammer der Verbindung A gilt nicht für Verbindung B');
    $q->rollBack();
    $p->rollBack();
    $h->close();

    // G08 neue Klammer derselben Verbindung entwertet die frühere
    $p->beginTransaction();
    $h1 = ZoneHistoryRecorder::open($p, $ctx);
    $h2 = ZoneHistoryRecorder::open($p, $ctx);
    T::throws(LogicException::class, fn () => $h1->beginZones([2]), 'G08', 'frühere Klammer nach Öffnen einer neuen unbrauchbar');
    $p->rollBack();
    $h2->close();
    T::throws(LogicException::class, fn () => ZoneHistoryRecorder::open($p, $ctx), 'G09', 'open() ohne Transaktion abgelehnt');
    T::throws(LogicException::class, function () use ($p, $ctx) { $p->beginTransaction(); try { ZoneHistoryRecorder::ensureHeads($p, [2], $ctx); } finally { $p->rollBack(); } }, 'G10', 'ensureHeads() in offener Transaktion abgelehnt');
}

/* ============================================================================================ */
if ($run('S')) {
    T::section("S ZoneSnapshotStore ($iso)");
    $p = H2b::reset();
    $mk = fn (string $n) => ZoneStateSnapshot::fromRows(['name' => $n, 'type' => 'forward', 'ttl' => 3600, 'prefix_length' => null, 'soa_ns' => 'ns1.h2b.test.',
        'soa_mail' => 'hostmaster.h2b.test.', 'soa_refresh' => 3600, 'soa_retry' => 1800, 'soa_expire' => 1209600, 'soa_minimum' => 86400], [], []);
    $st = new ZoneSnapshotStore($p);
    $p->beginTransaction();
    T::eq([true, false], [$st->store($mk('s1.test')), $st->store($mk('s1.test'))], 'S01', 'neu, danach vorhanden');
    $p->rollBack();
    T::eq(0, (int)H2b::one($p, 'SELECT COUNT(*) FROM zone_snapshots WHERE payload LIKE ?', ['%s1.test%']), 'S05', 'Rollback entfernt den Snapshot');

    // S02 nach dem Read-View von einer anderen Verbindung gespeichert (RR: 1020, RC: 1062) ⇒ byte-identisch ⇒ Erfolg
    $q = H2b::pdo();
    $p->beginTransaction();
    $p->query('SELECT COUNT(*) FROM zone_snapshots')->fetchAll();   // Read-View
    $q->beginTransaction();
    (new ZoneSnapshotStore($q))->store($mk('s2.test'));
    $q->commit();
    $p->exec("INSERT INTO history_change_sets (operation, actor_type, channel) VALUES ('history.test', 'system', 'cli')");
    $before = (int)H2b::one($q, 'SELECT COUNT(*) FROM history_change_sets');
    $res = null; $err = null;
    try { $res = $st->store($mk('s2.test')); } catch (Throwable $e) { $err = $e->getMessage(); }
    $srvTrx = (int)H2b::one($p, 'SELECT @@in_transaction');
    if ($iso === 'RR') {
        T::ok($res === null && $err !== null && str_contains($err, 'zurückgesetzt') && $srvTrx === 0, 'S02', 'RR/Snapshot-Isolation: Eintrag jünger als der Read-View ⇒ Server setzt die Transaktion zurück (1020), Store bricht fail-closed ab');
        try { $p->rollBack(); } catch (Throwable) {}
        T::eq($before, (int)H2b::one($q, 'SELECT COUNT(*) FROM history_change_sets'), 'S02b', 'keine Weiterarbeit im Autocommit: vorherige Schreibvorgänge der Transaktion verworfen');
    } else {
        T::ok($res === false && $err === null && $srvTrx === 1, 'S02', 'RC: konkurrierend gespeicherter identischer Snapshot ⇒ 1062, byte-identisch, kein Fehler');
        $p->commit();
        T::eq($before + 1, (int)H2b::one($q, 'SELECT COUNT(*) FROM history_change_sets'), 'S02b', 'Transaktion nach dem Duplicate-Key-Pfad fortgesetzt und committet');
    }

    // S03 nebenläufig, unbestätigt: Warten auf die andere Transaktion, danach identisch (commit) bzw. neu (rollback)
    // Fall 1: ohne vorherigen Read-View (Existenzprüfung erzeugt ihn erst; Zeile dort noch unbestätigt)
    foreach (['commit' => false, 'rollback' => true] as $end => $expectNew) {
        $flag = "$W/s3-$end";
        $w = H2b::spawn('snap-insert', "s3$end.test", '1.5', $flag, $end);
        H2b::waitFile($flag);
        $p->beginTransaction();
        $t0 = microtime(true);
        $res = null; $err = null;
        try { $res = $st->store($mk("s3$end.test")); } catch (Throwable $e) { $err = $e->getMessage(); }
        $waited = microtime(true) - $t0;
        $srvTrx = (int)H2b::one($p, 'SELECT @@in_transaction');
        try { $p->commit(); } catch (Throwable) {}
        $wr = H2b::join($w);
        $msg = "parallel eingefügt, andere Transaktion $end: Wartezeit " . round($waited, 2) . ' s, Ergebnis ' . var_export($res, true) . ($err ? " ($err)" : '');
        if ($end === 'rollback' || $iso === 'RC') {
            T::ok($res === $expectNew && $err === null && $waited > 1.0 && ($wr['ok'] ?? false), "S03-$end", $msg);
        } else {
            // Der Read-View entstand vor dem Commit der anderen Transaktion: Snapshot-Isolation verlangt Abbruch
            T::ok($res === null && $err !== null && $srvTrx === 0 && $waited > 1.0, "S03-$end", "RR: $msg – fail-closed, Transaktion vom Server zurückgesetzt");
        }
    }
    // Fall 2 (fachlicher Normalfall, E34): identischer Snapshot vor dem Read-View bestätigt ⇒ sichtbar, kein INSERT
    $q->beginTransaction();
    (new ZoneSnapshotStore($q))->store($mk('s3vis.test'));
    $q->commit();
    $p->beginTransaction();
    $p->query('SELECT COUNT(*) FROM zone_snapshots')->fetchAll();
    T::eq(false, $st->store($mk('s3vis.test')), 'S03c', 'vor dem Read-View bestätigter identischer Snapshot: wiederverwendet (alle Isolationsstufen)');
    $p->commit();

    // S04 gleicher Hash, abweichender Inhalt ⇒ fail-closed (auch im Duplicate-Key-Pfad)
    $s = $mk('s4.test');
    $p->exec('INSERT INTO zone_snapshots (content_hash, contract, payload, record_count) VALUES (' . $p->quote($s->contentHash()) . ", 'zs1', '{\"manipuliert\":true}', 0)");
    $p->beginTransaction();
    T::throws(RuntimeException::class, fn () => $st->store($s), 'S04', 'abweichender gespeicherter Inhalt ⇒ RuntimeException');
    $p->rollBack();
    $p->beginTransaction();
    $p->query('SELECT 1')->fetchAll();
    $q->exec('INSERT INTO zone_snapshots (content_hash, contract, payload, record_count) VALUES (' . $q->quote($mk('s4b.test')->contentHash()) . ", 'zs1', '{\"x\":1}', 0)");
    T::throws(RuntimeException::class, fn () => $st->store($mk('s4b.test')), 'S04b', 'abweichender Inhalt im Duplicate-Key-Pfad ⇒ RuntimeException');
    $p->rollBack();
}

/* ============================================================================================ */
if ($run('F')) {
    T::section("F Fehlerinjektion ($iso)");
    $cases = [
        // [Fall, Tabelle, Ereignis, Bedingung, Vorgang, erwarteter Code]
        ['F01', 'history_change_sets', 'INSERT', "NEW.operation = 'record.create'", 'rec', 'record.create.db_save_failed'],
        ['F02', 'zone_snapshots', 'INSERT', '1', 'rec', 'record.create.db_save_failed'],
        ['F03', 'zone_revisions', 'INSERT', "NEW.kind = 'change'", 'rec', 'record.create.db_save_failed'],
        ['F04', 'zone_history_state', 'UPDATE', '1', 'rec', 'record.create.db_save_failed'],
        ['F05', 'history_change_sets', 'INSERT', "NEW.operation = 'history.drift'", 'drift', 'record.create.db_save_failed'],
        ['F06', 'zone_history_state', 'INSERT', '1', 'zone', 'zone.create.failed'],
        ['F07', 'zone_revisions', 'INSERT', "NEW.kind = 'delete'", 'del', 'zone.delete.failed'],
        ['F08', 'zone_revisions', 'INSERT', '1', 'server', 'server.update.failed'],
        ['F09', 'zone_revisions', 'INSERT', '1', 'api', 'dyndns.api.exception'],
        ['F10', 'zone_revisions', 'INSERT', '1', 'dynacc', 'dyndns.update.failed'],
        ['F11', 'history_dependencies', 'INSERT', '1', 'ptr', 'record.create.ptr_db_failed'],
        ['F12', 'zone_revisions', 'INSERT', '1', 'zupd', 'zone.update.failed'],
    ];
    foreach ($cases as [$id, $table, $event, $cond, $opn, $code]) {
        $p = H2b::reset();
        if ($opn === 'drift') {
            $p->exec("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (2, 'fremd', 'TXT', '\"x\"', 300)");
        }
        $p->exec("CREATE TRIGGER h2b_inject BEFORE $event ON $table FOR EACH ROW IF $cond THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'H2B-Injektion'; END IF");
        $counts = H2b::historyCounts($p);
        $sum = H2b::businessSum($p);
        $x = H2b::pdo();
        $s = H2b::all($x, 'SELECT * FROM servers WHERE id = 1')[0];
        $r = match ($opn) {
            'rec', 'drift' => H2b::records($x)->createRecord(H2b::admin(), 2, H2b::record('A', 'inj', '192.0.2.33')),
            'zone' => H2b::zones($x)->createZone(H2b::admin(), H2b::newZone('inj.h2b.test')),
            'del' => H2b::zones($x)->deleteZone(H2b::admin(), 6),
            'server' => H2b::servers($x)->updateServer(H2b::admin(), 1, new ServerInput('nsinj.h2b.test', $s['dns_ip4'], (string)$s['dns_ip6'], $s['api_ip'], '', true, true)),
            'api' => H2b::dyndnsApi($x)->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.52', null),
            'dynacc' => H2b::dyndns($x)->updateAccount(H2b::admin(), 1, new DyndnsAccountInput('h2b_dynkonto', '', 'dyn', 4)),
            'ptr' => H2b::records($x)->createRecord(H2b::admin(), 1, H2b::record('A', 'inj', '192.0.2.78'), true),
            'zupd' => H2b::zones($x)->updateZone(H2b::admin(), 2, H2b::zoneInput($x, H2b::admin(), 2, ['ttl' => '7000'])),
        };
        $got = $codes($r);
        $open = H2b::hasOpenTrx($x);
        if ($opn === 'ptr') {
            // T1 bleibt bestehen (Forward-Record mit Revision), T2 vollständig zurückgerollt
            T::ok($r->ok && in_array($code, $got, true), $id, "Injektion $table/$event (AutoPTR T2): $code, Forward-Record bleibt");
            T::ok((int)H2b::one($p, "SELECT COUNT(*) FROM records WHERE zone_id = 3 AND name = '78'") === 0
                && (int)H2b::one($p, "SELECT COUNT(*) FROM records WHERE zone_id = 1 AND name = 'inj'") === 1
                && H2b::historyCounts($p)['zone_revisions'] === $counts['zone_revisions'] + 1 && !$open, "{$id}b", 'nur T1 festgeschrieben, T2 ohne Rückstände');
        } else {
            T::ok(!$r->ok && in_array($code, $got, true), $id, "Injektion $table/$event: bisheriger Fehlercode $code");
            T::ok(H2b::historyCounts($p) === $counts && H2b::businessSum($p) === $sum && !$open, "{$id}b", 'vollständiger Rollback: Fach- und History-Tabellen unverändert, keine offene Transaktion');
        }
        $p->exec('DROP TRIGGER h2b_inject');
        // F05: die vorbereitete Fremdänderung bleibt als noch nicht erfasste Drift bestehen
        T::eq($opn === 'drift' ? ['Zone 2: Head-Hash ≠ aktueller DB-Inhalt'] : [], H2b::invariants($p), "{$id}c", 'Invarianten nach Injektion');
    }
}

/* ============================================================================================ */
if ($run('N')) {
    T::section("N Nebenläufigkeit ($iso)");

    // N01 Fremdänderung unter Zonensperre während eines Record-Vorgangs: Lock-Wait, danach Drift + eigene Revision
    $p = H2b::reset();
    $w = H2b::spawn('hold-zone', '2', '2', "$W/n01", 'legacy-insert');
    H2b::waitFile("$W/n01");
    $m = H2b::mark($p);
    $t0 = microtime(true);
    $r = H2b::records($p)->createRecord(H2b::admin(), 2, H2b::record('A', 'n01', '192.0.2.61'));
    $waited = microtime(true) - $t0;
    H2b::join($w);
    T::ok($r->ok && $waited > 1.5, 'N01', 'Record-Vorgang wartet auf die Zonensperre (' . round($waited, 2) . ' s) und gelingt');
    T::eq(['history.drift system web: 2#2:drift', 'record.create user:21/h2b_admin web: 2#3:change'], H2b::brief($p, $m), 'N01b', 'während der Wartezeit festgeschriebene Fremdänderung als Drift, nicht dem Akteur zugeschrieben');

    // N02 Serverumbenennung wartet auf eine Zone mit Fremdänderung: kein 1020 (E34)
    $p = H2b::reset();
    $w = H2b::spawn('hold-zone', '1', '2', "$W/n02", 'legacy-insert');
    H2b::waitFile("$W/n02");
    $t0 = microtime(true);
    $s = H2b::all($p, 'SELECT * FROM servers WHERE id = 2')[0];
    $m = H2b::mark($p);
    $r = H2b::servers($p)->updateServer(H2b::admin(), 2, new ServerInput('ns2n.h2b.test', $s['dns_ip4'], '', $s['api_ip'], '', false, true));
    $waited = microtime(true) - $t0;
    H2b::join($w);
    $detail = implode(' ', array_map(fn ($x) => (string)($x->context['detail'] ?? ''), $r->messages));
    T::ok($r->ok && $waited > 1.5, 'N02', 'Serverumbenennung nach Lock-Wait (' . round($waited, 2) . ' s) erfolgreich' . ($r->ok ? '' : " – $detail"));
    T::eq(['history.drift system web: 1#2:drift', 'server.update user:21/h2b_admin web: 1#3:change 6#2:change'], H2b::brief($p, $m), 'N02b', 'Drift in Zone 1, Änderung in allen zugewiesenen Zonen');

    // N03 DynDNS-Konto parallel ohne Zonensperre geändert: beginZones erzeugt keinen Read-View ⇒ kein 1020 (R-H2)
    $p = H2b::reset();
    $w = H2b::spawn('hold-account', '1', '2', "$W/n03");
    H2b::waitFile("$W/n03");
    $t0 = microtime(true);
    $r = H2b::dyndnsApi($p)->update('h2b_dynkonto', H2b::DYN_PASSWORD, '192.0.2.63', null);
    $waited = microtime(true) - $t0;
    H2b::join($w);
    $msg = implode(' ', array_map(fn ($x) => (string)($x->context['message'] ?? $x->context['detail'] ?? ''), $r->messages));
    T::ok(!in_array('dyndns.api.exception', $codes($r), true) && $waited > 1.5 && (int)H2b::one($p, "SELECT COUNT(*) FROM records WHERE zone_id = 1 AND content = '192.0.2.63'") === 1,
        'N03', 'DynDNS-API wartet auf das Konto (' . round($waited, 2) . ' s), kein 1020 nach der History-Sperre' . ($msg !== '' ? " – $msg" : ''));

    // N04 Zonen ohne Head parallel (E35): ensureHeads in zwei Prozessen, Verzögerung beim INSERT des Heads
    $p = H2b::reset();
    foreach ([7, 8] as $zid) {
        $p->exec("INSERT INTO zones (id, name, type, ttl, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns)
                  VALUES ($zid, 'p$zid.h2b.test', 'forward', 3600, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 0)");
    }
    $p->exec("CREATE TRIGGER h2b_delay BEFORE INSERT ON zone_history_state FOR EACH ROW DO SLEEP(1)");
    $w1 = H2b::spawn('ensure', '7');
    $w2 = H2b::spawn('ensure', '8');
    $r1 = H2b::join($w1); $r2 = H2b::join($w2);
    $p->exec('DROP TRIGGER h2b_delay');
    T::ok(($r1['done'] ?? null) === [7] && ($r2['done'] ?? null) === [8] && max($r1['_seconds'], $r2['_seconds']) < 1.9,
        'N04', 'zwei Zonen ohne Head parallel: beide Baselines ohne Deadlock und ohne gegenseitiges Warten (' . round($r1['_seconds'], 2) . ' / ' . round($r2['_seconds'], 2) . ' s)');

    // N04b Gegenprobe: Head-Prüfung mit FOR UPDATE (Gap-Sperre) ⇒ unter RR Deadlock, unter RC nicht
    foreach ([12, 13] as $zid) {
        $p->exec("INSERT INTO zones (id, name, type, ttl, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns)
                  VALUES ($zid, 'g$zid.h2b.test', 'forward', 3600, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 0)");
    }
    $w1 = H2b::spawn('ensure-forupdate', '12', "$W/g12", "$W/go");
    $w2 = H2b::spawn('ensure-forupdate', '13', "$W/g13", "$W/go");
    H2b::waitFile("$W/g12"); H2b::waitFile("$W/g13");
    touch("$W/go");
    $r1 = H2b::join($w1); $r2 = H2b::join($w2);
    $deadlocks = count(array_filter([$r1, $r2], fn ($x) => (int)($x['code'] ?? 0) === 1213));
    if ($iso === 'RR') {
        T::eq(1, $deadlocks, 'N04b', 'Gegenprobe RR: FOR UPDATE auf fehlende Heads führt zum Deadlock (1213) – deshalb Lazy-Baseline ohne Gap-Sperre');
    } else {
        T::eq(0, $deadlocks, 'N04b', 'Gegenprobe RC: ohne Gap-Sperren kein Deadlock');
    }

    ZoneHistoryRecorder::ensureHeads($p, [12, 13], HistoryContext::system('history.test', 'cli'));   // Rest der Gegenprobe
    T::eq([], H2b::invariants($p), 'N99', 'Invarianten');
}

/* ============================================================================================ */
if ($run('X')) {
    T::section("X Stresslauf und Geheimnisse ($iso)");
    $p = H2b::reset();
    $workers = [];
    for ($i = 1; $i <= 4; $i++) {
        $workers[] = H2b::spawn('stress', '6', (string)$i);
    }
    $stats = array_map(fn ($w) => H2b::join($w), $workers);
    $okOps = array_sum(array_column($stats, 'ok'));
    $codesSeen = [];
    foreach ($stats as $s) { foreach ($s['codes'] ?? [] as $c => $n) { $codesSeen[$c] = ($codesSeen[$c] ?? 0) + $n; } }
    ksort($codesSeen);
    echo '       Stress: ' . $okOps . ' erfolgreich, Fehlercodes ' . json_encode($codesSeen) . "\n";
    T::ok($okOps > 20, 'X01', 'Stresslauf mit vier Prozessen ausgeführt');
    T::eq([], H2b::invariants($p), 'X02', 'Invarianten nach dem Stresslauf (Head = DB-Inhalt je Zone, lückenlose Revisionen)');
    $unexpected = array_diff(array_keys($codesSeen), ['dyndns.api.publish_pending', 'dyndns.api.done', 'record.delete.failed', 'record.create.db_save_failed', 'dyndns.api.exception', 'zone.update.failed', 'zone.update.conflict']);
    T::eq([], array_values($unexpected), 'X03', 'nur erwartete Fehlerarten (Publish-Test-Backend, Bearbeitungs- und Lock-Konflikte)');
    $dump = H2b::sh('mysqldump --skip-comments ' . H2b::$db . ' ' . implode(' ', H2b::HISTORY_TABLES));
    T::ok(!str_contains($dump, 'H2BTOKEN') && !str_contains($dump, 'H2BFIXTURE') && !str_contains($dump, '$2y$') && !str_contains($dump, H2b::DYN_PASSWORD)
        && !str_contains($dump, 'h2b_dynkonto') && !str_contains($dump, '192.0.2.99'), 'X04', 'keine Tokens, Passwort-Hashes, Passwörter, DynDNS-Benutzernamen oder Client-IP in der Historie');
}

T::finish("recorder $iso");
