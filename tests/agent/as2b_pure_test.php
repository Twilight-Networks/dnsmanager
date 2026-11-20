<?php
/**
 * Datei: tests/agent/as2b_pure_test.php
 * Zweck: Tests für AS2b ohne BIND (docs/ARCHITECTURE_HISTORY.md Abschnitt 5g).
 *   Rein (ohne Ein-/Ausgabe):
 *   B  Rundenfolge (50 ms, verdoppelt bis 500 ms)
 *   W  Fensterende min(Reload-Ende + 10 s, TW − 1 s)
 *   P  Polling-Steuerung mit simulierter Uhr (keine Runde nach dem Fensterende, keine Wartezeit darüber hinaus)
 *   DL Entscheidungsmatrix conf_sync (E121, R1–R3)
 *   DR Entscheidungsmatrix Cleanup (E92, E122, E124)
 *   F  Endergebnis nach R3/R4 (letzte vollständige Runde)
 *   N  Zonennamen aus dem Konfigurationsnachweis (E123)
 *   Mit Ersatzprogrammen für dig, named-checkconf, named-checkzone und rndc (Linux, root; kein BIND, kein Netzwerk):
 *   C  Rundenspeicher der Kontrollzone (Wiederverwendung nur in derselben Runde und für denselben Nachweis)
 *   S  Abschnittsfrist (inc/proc.php)
 *   L  Abläufe verifyLoaded()/verifyRemoved() mit Antwortfolgen (kein Übertrag über Runden, R4, E122)
 *   BU Vorabnachweis im Cleanup: Eintrittsschwelle 20 s knapp unter-/oberhalb, maximaler Zeitverbrauch, Restzeit danach
 *
 * Aufruf (root, Test-VM): php tests/agent/as2b_pure_test.php [<Checkout>] [<Gruppen, z. B. BWPDLDRFNCSLBU>]
 * Exit-Code 0 nur ohne Fehlschlag.
 */

$root = $argv[1] ?? dirname(__DIR__, 2);
$only = $argv[2] ?? null;
if (!function_exists('safeZoneName')) {
    // wie agent/inc/bind_utils.php (dort mit Konfigurationsabhängigkeit beim Einbinden)
    function safeZoneName(string $name): string {
        return preg_replace('/[^a-zA-Z0-9._\-]/', '_', $name);
    }
}
require $root . '/agent/inc/file_utils.php';
require $root . '/agent/inc/dns_verify.php';

$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $id . ($detail !== '' ? " $detail" : '') . "\n";
}
function run(string $g): bool {
    global $only;
    return $only === null || str_contains($only, $g);
}
$dec = static fn (array $o): string => ($o['final'] ? 'F' : '-') . ($o['complete'] ? 'C' : '-') . ':' . $o['state'] . ':' . ($o['reason'] ?? '');

// ---------------------------------------------------------------- B: Rundenfolge
if (run('B')) {
    $seq = array_map('verifyBackoff', range(1, 7));
    t('B01 50/100/200/400/500/500/500 ms', $seq === [0.05, 0.1, 0.2, 0.4, 0.5, 0.5, 0.5], json_encode($seq));
}

// ---------------------------------------------------------------- W: Fensterende
if (run('W')) {
    t('W01 Fenster 10 s', verifyPollDeadline(100.0, 200.0) === 110.0);
    t('W02 begrenzt durch TW − 1 s', verifyPollDeadline(100.0, 105.0) === 104.0);
    t('W03 Reload-Ende nach TW − 1 s ⇒ Fensterende vor Reload-Ende', verifyPollDeadline(100.0, 100.5) < 100.0);
    t('W04 Reserve Vorabnachweis = Preflight + Reload + Antwort + Nachlauf', VERIFY_PREPROOF_RESERVE === 10.0 && VERIFY_PREPROOF_AFTER_MIN === 9.0
        && VERIFY_PREPROOF_MIN_REMAINING === 20.0, VERIFY_PREPROOF_RESERVE . '/' . VERIFY_PREPROOF_AFTER_MIN);
}

// ---------------------------------------------------------------- P: Polling-Steuerung (simulierte Uhr)
if (run('P')) {
    $sim = static function (float $deadline, callable $done, float $cost = 0.0) {
        $clock = 0.0;
        $starts = [];
        $sleeps = [];
        $p = verifyPoll($deadline, static function (int $n) use (&$clock, &$starts, $done, $cost): bool {
            $starts[] = $clock;
            $clock += $cost;
            return $done($n);
        }, static function () use (&$clock): float {
            return $clock;
        }, static function (float $s) use (&$clock, &$sleeps): void {
            $sleeps[] = $s;
            $clock += $s;
        });
        return [$p, $starts, $sleeps, $clock];
    };
    [$p, $st, $sl] = $sim(10.0, static fn (int $n): bool => true);
    t('P01 erste Runde sofort, fertig ⇒ eine Runde, keine Wartezeit', $p === ['rounds' => 1, 'done' => true] && $st === [0.0] && $sl === []);
    [$p, $st, $sl, $end] = $sim(10.0, static fn (int $n): bool => false);
    $ok = max($st) < 10.0 && $end <= 10.0 && array_slice($sl, 0, 5) === [0.05, 0.1, 0.2, 0.4, 0.5] && $p['done'] === false;
    t('P02 nie fertig ⇒ keine Runde und keine Wartezeit über das Fensterende', $ok, "rounds={$p['rounds']} letzte={$st[count($st) - 1]} ende=$end");
    [$p] = $sim(-1.0, static fn (int $n): bool => true);
    t('P03 Fensterende schon erreicht ⇒ keine Runde', $p === ['rounds' => 0, 'done' => false]);
    [$p, $st] = $sim(10.0, static fn (int $n): bool => false, 3.0);
    // Runden beginnen bei 0; 3,05; 6,15; 9,35 s (Rundendauer 3 s plus Wartezeit) – alle vor dem Fensterende
    t('P04 Runde dauert 3 s ⇒ keine Runde beginnt nach dem Fensterende', max($st) < 10.0 && $p['rounds'] === 4, json_encode($st));
    [$p] = $sim(10.0, static fn (int $n): bool => $n === 3);
    t('P05 fertig in Runde 3', $p === ['rounds' => 3, 'done' => true]);
}

// ---------------------------------------------------------------- DL: conf_sync (E121)
$fV = ['state' => 'valid', 'serial' => 5, 'sha256' => str_repeat('a', 64), 'reason' => null];
$kY = ['state' => 'yes', 'reason' => null, 'ancestor' => null];
$kN = ['state' => 'no', 'reason' => null, 'ancestor' => null];
$kU = static fn (string $r): array => ['state' => 'undeterminable', 'reason' => $r, 'ancestor' => null];
$dS = static fn (int $s): array => ['state' => 'served', 'serial' => $s, 'reason' => null, 'rcode' => 'NOERROR', 'control' => null];
$dU = static fn (string $r, string $rc = 'SERVFAIL'): array => ['state' => 'unknown', 'serial' => null, 'reason' => $r, 'rcode' => $rc, 'control' => null];
$dNA = ['state' => 'not_auth', 'serial' => null, 'reason' => 'refused_control_ok', 'rcode' => 'REFUSED', 'control' => 'ok'];
if (run('DL')) {
    $cases = [
        ['DL01 Datei-Prüfung abgebrochen ⇒ unvollständig', [['state' => 'unreadable', 'serial' => null, 'reason' => 'check_timeout'], null, false, null], '--:unknown:file_check_timeout'],
        ['DL02 Datei fehlt ⇒ unknown, fest', [['state' => 'missing', 'serial' => null, 'reason' => null], null, false, null], 'FC:unknown:file_missing'],
        ['DL03 Datei ungültig ⇒ unknown, fest', [['state' => 'invalid', 'serial' => null, 'reason' => null], $kY, false, $dS(5)], 'FC:unknown:file_invalid'],
        ['DL04 Datei unlesbar ⇒ unknown, fest', [['state' => 'unreadable', 'serial' => null, 'reason' => 'read_incomplete'], null, false, null], 'FC:unknown:file_unreadable'],
        ['DL05 K vorübergehend (Zonenzahl) ⇒ weiter, vollständig', [$fV, $kU('zone_count_mismatch'), true, null], '-C:unknown:config_zone_count_mismatch'],
        ['DL06 K nicht gestartet (Frist) ⇒ unvollständig', [$fV, $kU('rndc_status_not_started'), true, null], '--:unknown:config_rndc_status_not_started'],
        ['DL07 K strukturell (Views) ⇒ unknown, fest', [$fV, $kU('views_present'), false, null], 'FC:unknown:config_views_present'],
        ['DL08 K = nein ⇒ unknown, fest', [$fV, $kN, false, $dS(5)], 'FC:unknown:config_not_configured'],
        ['DL09 D nicht abgefragt ⇒ unvollständig', [$fV, $kY, false, null], '--:unknown:served_not_queried'],
        ['DL10 dig nicht gestartet ⇒ unvollständig', [$fV, $kY, false, $dU('dig_not_started', 'error')], '--:unknown:dig_not_started'],
        ['DL11 SOA = Datei-Serial ⇒ verified', [$fV, $kY, false, $dS(5)], 'FC:verified:'],
        ['DL12 SOA ≠ Datei-Serial ⇒ serial_differs, weiter', [$fV, $kY, false, $dS(4)], '-C:serial_differs:'],
        ['DL13 SERVFAIL ⇒ unknown, weiter (nie positiv)', [$fV, $kY, false, $dU('configured_not_served')], '-C:unknown:configured_not_served'],
        ['DL14 Timeout ⇒ unknown, weiter (nie positiv)', [$fV, $kY, false, $dU('dns_timeout', 'timeout')], '-C:unknown:dns_timeout'],
        ['DL15 Antwort nicht auswertbar ⇒ unknown', [$fV, $kY, false, $dU('header_ambiguous', 'error')], '-C:unknown:header_ambiguous'],
    ];
    foreach ($cases as [$id, $args, $exp]) {
        $got = $dec(verifyLoadedDecide(...$args));
        t($id, $got === $exp, "got=$got");
    }
}

// ---------------------------------------------------------------- DR: Cleanup (E92, E122, E124)
if (run('DR')) {
    $cases = [
        ['DR01 Reload ok, K nein, not_auth ⇒ absent', [true, $kN, false, $dNA], 'FC:absent:refused_control_ok'],
        ['DR02 Reload gescheitert ⇒ nie absent', [false, $kN, false, $dNA], 'FC:unknown:refused_control_ok'],
        ['DR03 K nein, ausgeliefert ⇒ still_served, weiter', [true, $kN, false, $dS(1)], '-C:still_served:'],
        ['DR04 K ja, ausgeliefert ⇒ still_served, fest', [true, $kY, false, $dS(1)], 'FC:still_served:still_configured'],
        ['DR05 K ja, nicht ausgeliefert ⇒ unknown, fest (absent ausgeschlossen)', [true, $kY, false, $dU('configured_not_served')], 'FC:unknown:still_configured'],
        ['DR06 K vorübergehend, ausgeliefert ⇒ still_served, weiter', [true, $kU('zone_count_mismatch'), true, $dS(1)], '-C:still_served:config_zone_count_mismatch'],
        ['DR07 K vorübergehend, nicht ausgeliefert ⇒ unknown, weiter', [true, $kU('zone_count_mismatch'), true, $dU('config_undeterminable', 'REFUSED')], '-C:unknown:config_zone_count_mismatch'],
        ['DR08 K strukturell ⇒ unknown, fest', [true, $kU('views_present'), false, $dU('config_undeterminable', 'REFUSED')], 'FC:unknown:config_views_present'],
        ['DR09 Kontrollzone fehlt ⇒ unknown, fest', [true, $kN, false, $dU('control_zone_missing', 'REFUSED')], 'FC:unknown:control_zone_missing'],
        ['DR10 Eltern-ACL REFUSED ⇒ unknown, fest', [true, $kN, false, $dU('refused_with_parent', 'REFUSED')], 'FC:unknown:refused_with_parent'],
        ['DR11 Timeout ⇒ unknown, weiter', [true, $kN, false, $dU('dns_timeout', 'timeout')], '-C:unknown:dns_timeout'],
        ['DR12 SERVFAIL (K nein) ⇒ unknown, weiter, nie absent', [true, $kN, false, $dU('answer_unexpected')], '-C:unknown:answer_unexpected'],
        ['DR13 dig nicht gestartet ⇒ unvollständig', [true, $kN, false, $dU('dig_not_started', 'error')], '--:unknown:dig_not_started'],
        ['DR14 K vorübergehend und nicht gestartet ⇒ unvollständig', [true, $kU('checkconf_list_not_started'), true, $dU('config_undeterminable', 'REFUSED')], '--:unknown:config_checkconf_list_not_started'],
        ['DR15 not_auth ohne K = nein ⇒ nie absent', [true, $kU('zone_count_mismatch'), true, $dNA], '-C:unknown:config_zone_count_mismatch'],
    ];
    foreach ($cases as [$id, $args, $exp]) {
        $got = $dec(verifyRemovedDecide(...$args));
        t($id, $got === $exp, "got=$got");
    }
}

// ---------------------------------------------------------------- F: Endergebnis (R3/R4)
if (run('F')) {
    $o = static fn (bool $f, string $s, ?string $r = null): array => ['final' => $f, 'complete' => true, 'state' => $s, 'reason' => $r];
    // Simulation über Runden: nur vollständige Runden ersetzen die letzte Beobachtung
    $seqEnd = static function (array $rounds): array {
        $final = null;
        $last = null;
        foreach ($rounds as $r) {
            if ($r['final']) {
                $final = $r;
                break;
            }
            if ($r['complete']) {
                $last = $r;
            }
        }
        return verifyFinalize($final, $last);
    };
    $inc = ['final' => false, 'complete' => false, 'state' => 'unknown', 'reason' => 'dig_not_started'];
    t('F01 festes Ergebnis gilt', verifyFinalize($o(true, 'verified'), $o(false, 'serial_differs'))['state'] === 'verified');
    t('F02 keine vollständige Runde ⇒ budget_exhausted', verifyFinalize(null, null) === ['state' => 'unknown', 'reason' => 'budget_exhausted']);
    $r = $seqEnd([$o(false, 'serial_differs'), $o(false, 'unknown', 'dns_timeout')]);
    t('F03 Abweichung, danach unknown ⇒ unknown (last_round_unknown)', $r['state'] === 'unknown' && $r['reason'] === 'last_round_unknown' && $r['last_reason'] === 'dns_timeout', json_encode($r));
    $r = $seqEnd([$o(false, 'unknown', 'configured_not_served'), $o(false, 'serial_differs')]);
    t('F04 letzte vollständige Runde serial_differs ⇒ serial_differs', $r['state'] === 'serial_differs', json_encode($r));
    $r = $seqEnd([$o(false, 'serial_differs'), $inc]);
    t('F05 unvollständige letzte Runde ersetzt die vollständige nicht', $r['state'] === 'serial_differs', json_encode($r));
    $r = $seqEnd([$o(false, 'still_served'), $o(false, 'unknown', 'dns_timeout')]);
    t('F06 still_served, danach unknown ⇒ unknown', $r['state'] === 'unknown', json_encode($r));
    $r = $seqEnd([$o(false, 'unknown', 'dns_timeout'), $o(true, 'absent', 'refused_control_ok'), $o(false, 'still_served')]);
    t('F07 absent ist endgültig (R3)', $r['state'] === 'absent', json_encode($r));
}

// ---------------------------------------------------------------- N: Zonennamen (E123)
$tmp = sys_get_temp_dir() . '/as2b-pure-' . getmypid();
@mkdir("$tmp/zones/conf", 0755, true);
@mkdir("$tmp/cache", 0755, true);
$proofOf = static function (array $names, string $state = 'ok', ?string $reason = null) use ($tmp): array {
    $list = [];
    $zones = [];
    foreach ($names as $n => $file) {
        $list[] = [$n, 'IN', '_default', 'master'];
        $zones[] = [$n, 'IN', ['type' => ['master'], 'file' => [$file]]];
    }
    return ['state' => $state, 'reason' => $reason, 'list' => $list, 'zones' => $zones, 'directory' => "$tmp/cache"];
};
if (run('N')) {
    $cfg = ['zone_data_dir' => "$tmp/zones", 'zone_conf_dir' => "$tmp/zones/conf"];
    $p = $proofOf(['a.test' => "$tmp/zones/db.a.test", 'b.test' => "$tmp/other/db.b.test", 'c.test' => "$tmp/zones/db.c.test"]);
    $n = verifyOrphanNames($cfg, $p, ['a.test', 'A.test', 'b.test', 'x.test', 'c.test.']);
    t('N01 eindeutig ⇒ Name', $n['a.test'] === ['name' => 'a.test', 'reason' => null]);
    t('N02 Großbuchstaben ⇒ offen (keine Ableitung aus dem Dateinamen)', $n['A.test']['name'] === null && $n['A.test']['reason'] === 'zone_name_unresolved');
    t('N03 Zone mit fremdem Dateipfad ⇒ offen', $n['b.test']['name'] === null);
    t('N04 nicht konfiguriert ⇒ offen', $n['x.test']['name'] === null);
    t('N05 abschließender Punkt ⇒ offen', $n['c.test.']['name'] === null);
    $p2 = $p;
    $p2['list'][] = ['a.test', 'IN', '_default', 'master'];   // doppelter Eintrag ⇒ K unbestimmbar ⇒ kein Kandidat
    t('N06 mehrdeutige Konfiguration ⇒ offen', verifyOrphanNames($cfg, $p2, ['a.test'])['a.test']['name'] === null);
    t('N07 Nachweis unbestimmbar ⇒ offen (config_undeterminable)', verifyOrphanNames($cfg, $proofOf([], 'undeterminable', 'views_present'), ['a.test'])['a.test']
        === ['name' => null, 'reason' => 'config_undeterminable']);
    t('N08 Nachweis wegen Frist nicht gestartet ⇒ budget_exhausted', verifyOrphanNames($cfg, $proofOf([], 'undeterminable', 'checkconf_list_not_started'), ['a.test'])['a.test']['reason'] === 'budget_exhausted');
    t('N09 numerischer Basisname', array_keys(verifyOrphanNames($cfg, $p, [123])) === ['123'] || array_keys(verifyOrphanNames($cfg, $p, [123])) === [123]);
}

// ---------------------------------------------------------------- Ersatzprogramme (C, S, L, BU)
$fake = "$tmp/fake";
@mkdir($fake, 0755, true);
$dig = <<<'SH'
#!/bin/sh
# Ersatz für dig: Antwort aus der Folge $D/seq.<name> (je Aufruf eine Zeile, danach die letzte Zeile wiederholt)
D=__D__
prev=""; name=""
for a in "$@"; do name=$prev; prev=$a; done
n=$(printf '%s' "$name" | sed 's/\.$//')
echo "$n" >> $D/dig.log
f=$D/seq.$n; [ -f "$f" ] || f=$D/seq.default
c=$(cat "$f.ptr" 2>/dev/null || echo 0); c=$((c+1)); echo $c > "$f.ptr"
t=$(wc -l < "$f"); i=$c; [ $i -gt $t ] && i=$t
l=$(sed -n "${i}p" "$f")
hdr() { printf ';; Got answer:\n;; ->>HEADER<<- opcode: QUERY, status: %s, id: 4711\n;; flags: %s; QUERY: 1, ANSWER: %s, AUTHORITY: %s, ADDITIONAL: 1\n\n' "$1" "$2" "$3" "$4"; }
case "$l" in
  refused) hdr REFUSED qr 0 0 ;;
  servfail) hdr SERVFAIL qr 0 0 ;;
  timeout) sleep 5 ;;
  soa:*) s=${l#soa:}; hdr NOERROR "qr aa" 1 0; printf ';; ANSWER SECTION:\n%s.\t300\tIN\tSOA\tns.%s. h.%s. %s 3600 900 604800 300\n\n' "$n" "$n" "$n" "$s" ;;
  nx:*) p=${l#nx:}; hdr NXDOMAIN "qr aa" 0 1; printf ';; AUTHORITY SECTION:\n%s.\t300\tIN\tSOA\tns.%s. h.%s. 1 3600 900 604800 300\n\n' "$p" "$p" "$p" ;;
esac
exit 0
SH;
$checkconf = <<<'SH'
#!/bin/sh
# Ersatz für named-checkconf: -l und -px aus Dateien, Verzögerung aus $D/checkconf.sleep
D=__D__
echo "$*" >> $D/checkconf.log
[ -f $D/checkconf.sleep ] && sleep $(cat $D/checkconf.sleep)
case "$1" in
  -l) cat $D/list.txt ;;
  -p) cat $D/px.txt ;;
esac
exit 0
SH;
$checkzone = <<<'SH'
#!/bin/sh
D=__D__
echo "$*" >> $D/checkzone.log
printf 'zone %s/IN: loaded serial %s\nOK\n' "$1" "$(cat $D/fileserial)"
exit 0
SH;
$rndc = <<<'SH'
#!/bin/sh
# Ersatz für rndc status: Zonenzahl aus der Folge $D/rndc.seq, Verzögerung aus $D/rndc.sleep
D=__D__
echo "$*" >> $D/rndc.log
[ -f $D/rndc.sleep ] && sleep $(cat $D/rndc.sleep)
f=$D/rndc.seq
c=$(cat "$f.ptr" 2>/dev/null || echo 0); c=$((c+1)); echo $c > "$f.ptr"
t=$(wc -l < "$f"); i=$c; [ $i -gt $t ] && i=$t
printf 'number of zones: %s (0 automatic)\nserver is up and running\n' "$(sed -n "${i}p" "$f")"
exit 0
SH;
foreach (['dig' => $dig, 'checkconf' => $checkconf, 'checkzone' => $checkzone, 'rndc' => $rndc] as $n => $s) {
    file_put_contents("$fake/$n", str_replace('__D__', $fake, $s));
    chmod("$fake/$n", 0755);
}
$fcfg = ['zone_data_dir' => "$tmp/zones", 'zone_conf_dir' => "$tmp/zones/conf", 'l3_control_zone' => 'ctl.test',
    'bind_dig' => "$fake/dig", 'bind_named_checkconf' => "$fake/checkconf", 'bind_named_checkzone' => "$fake/checkzone",
    'bind_rndc' => "$fake/rndc", 'bind_named_conf_path' => "$tmp/named.conf"];
/** Ersatz-Konfiguration: Zonen (Name ⇒ Datei) für -l/-px, Zonenzahl, Antwortfolgen je Name */
$scene = static function (array $zones, array $seqs, array $rndcSeq = [], int $fileSerial = 5) use ($fake, $tmp): void {
    foreach (glob("$fake/*.log") ?: [] as $f) {
        unlink($f);
    }
    foreach (glob("$fake/seq.*") ?: [] as $f) {
        unlink($f);
    }
    foreach (glob("$fake/*.ptr") ?: [] as $f) {
        unlink($f);
    }
    @unlink("$fake/checkconf.sleep");
    @unlink("$fake/rndc.sleep");
    $zones['ctl.test'] ??= "$tmp/ctl/db.ctl.test";
    $l = '';
    $px = "options {\n\tdirectory \"$tmp/cache\";\n};\n";
    foreach ($zones as $n => $f) {
        $l .= "$n IN _default master\n";
        $px .= "zone \"$n\" {\n\ttype master;\n\tfile \"$f\";\n};\n";
        if (str_starts_with($f, "$tmp/zones/")) {
            file_put_contents($f, "; Testdatei\n");
        }
    }
    file_put_contents("$fake/list.txt", $l);
    file_put_contents("$fake/px.txt", $px);
    file_put_contents("$fake/rndc.seq", implode("\n", $rndcSeq ?: [count($zones)]) . "\n");
    file_put_contents("$fake/fileserial", (string)$fileSerial);
    $seqs['ctl.test'] ??= ['soa:1'];
    $seqs['default'] ??= ['refused'];
    foreach ($seqs as $n => $s) {
        file_put_contents("$fake/seq.$n", implode("\n", $s) . "\n");
    }
};
$count = static function (string $log, ?string $match = null) use ($fake): int {
    $lines = is_file("$fake/$log.log") ? file("$fake/$log.log", FILE_IGNORE_NEW_LINES) : [];
    return count($match === null ? $lines : array_filter($lines, static fn ($l) => $l === $match));
};
$linux = PHP_OS_FAMILY === 'Linux' && function_exists('posix_getuid') && posix_getuid() === 0 && is_executable('/usr/bin/setsid');

// ---------------------------------------------------------------- C: Rundenspeicher der Kontrollzone
if (run('C') && $linux) {
    $scene([], ['z1.solo' => ['refused'], 'z2.solo' => ['refused']]);
    $p = dnsConfigProof($fcfg, true);
    t('C00 Ersatz-Nachweis ok', $p['state'] === 'ok', json_encode([$p['state'], $p['reason']]));
    $k1 = dnsZoneConfigState($fcfg, 'z1.solo', $p);
    $k2 = dnsZoneConfigState($fcfg, 'z2.solo', $p);
    $round = [];
    $d1 = dnsServedState($fcfg, 'z1.solo', $k1, $p, $round);
    $d2 = dnsServedState($fcfg, 'z2.solo', $k2, $p, $round);
    t('C01 zwei Zonen, eine Runde ⇒ eine Kontrollabfrage', $d1['state'] === 'not_auth' && $d2['state'] === 'not_auth'
        && $count('dig', 'ctl.test') === 1 && ($round['control_queries'] ?? 0) === 1, 'ctl=' . $count('dig', 'ctl.test'));
    $round2 = [];
    dnsServedState($fcfg, 'z1.solo', $k1, $p, $round2);
    t('C02 neue Runde ⇒ neue Kontrollabfrage', $count('dig', 'ctl.test') === 2);
    $p3 = $p;
    $p3['list'][] = ['other.test', 'IN', '_default', 'master'];
    dnsServedState($fcfg, 'z1.solo', $k1, $p3, $round2);
    t('C03 anderer Nachweis in derselben Runde ⇒ keine Wiederverwendung', $count('dig', 'ctl.test') === 3);
    dnsServedState($fcfg, 'z1.solo', $k1, $p);
    dnsServedState($fcfg, 'z2.solo', $k2, $p);
    t('C04 ohne Rundenspeicher (zone_sync) ⇒ je Aufruf geprüft wie bisher', $count('dig', 'ctl.test') === 5);
    file_put_contents("$fake/seq.ctl.test", "refused\n");
    @unlink("$fake/seq.ctl.test.ptr");
    $round3 = [];
    $d = dnsServedState($fcfg, 'z1.solo', $k1, $p, $round3);
    t('C05 Kontrollzone nicht ausgeliefert ⇒ unknown, nie not_auth', $d['state'] === 'unknown' && $d['reason'] === 'control_zone_not_served', json_encode($d));
}

// ---------------------------------------------------------------- S: Abschnittsfrist
if (run('S') && $linux) {
    $base = agentRemaining();
    $t0 = microtime(true);
    $r = agentWithSectionDeadline(microtime(true) + 1.0, static fn () => agentRunProcess(['/bin/sleep', '5'], 8.0));
    $dt = microtime(true) - $t0;
    t('S01 Prozess endet mit der Abschnittsfrist', $r['status'] === 'timeout' && $dt >= 0.9 && $dt < 2.2, sprintf('%s %.2f s', $r['status'], $dt));
    t('S02 danach wieder TW', abs(agentRemaining() - ($base - $dt)) < 0.3, sprintf('%.2f', agentRemaining()));
    $in = agentWithSectionDeadline(microtime(true) + 1.0, static fn () => agentWithSectionDeadline(microtime(true) + 5.0, static fn () => agentRemaining()));
    t('S03 innerer Abschnitt verlängert nicht', $in <= 1.0, sprintf('%.2f', $in));
    try {
        agentWithSectionDeadline(microtime(true) + 1.0, static function (): void {
            throw new RuntimeException('x');
        });
    } catch (RuntimeException $e) {
    }
    t('S04 nach Ausnahme wiederhergestellt', agentRemaining() > 2.0, sprintf('%.2f', agentRemaining()));
    $r = agentWithSectionDeadline(microtime(true) + 0.2, static fn () => agentRunProcess(['/bin/true'], 8.0));
    t('S05 Restzeit unter Mindestlaufzeit ⇒ nicht gestartet', $r['status'] === 'not_started');
    $r = agentWithSectionDeadline(microtime(true) + 100.0, static fn () => agentRemaining());
    t('S06 Abschnitt nie über TW hinaus', $r <= agentWorkDeadline() - microtime(true) + 0.01, sprintf('%.2f', $r));
}

// ---------------------------------------------------------------- L: Abläufe mit Antwortfolgen
if (run('L') && $linux) {
    $zf = "$tmp/zones/db.a.test";
    $scene(['a.test' => $zf], ['a.test' => ['servfail', 'servfail', 'soa:5']]);
    $v = verifyLoaded($fcfg, 'a.test', microtime(true));
    t('L01 SERVFAIL, SERVFAIL, SOA = Datei ⇒ verified in Runde 3', $v['state'] === 'verified' && $v['rounds'] === 3 && $v['served_serial'] === 5
        && $v['file_serial'] === 5 && $v['file_sha256'] === hash_file('sha256', $zf), json_encode([$v['state'], $v['rounds'], $v['reason']]));
    t('L01b K und Datei je einmal ermittelt', $count('checkzone') === 1 && $count('checkconf') === 2, 'checkzone=' . $count('checkzone') . ' checkconf=' . $count('checkconf'));
    $scene(['a.test' => $zf], ['a.test' => ['soa:4']]);
    $t0 = microtime(true);
    $v = verifyLoaded($fcfg, 'a.test', microtime(true) - 9.0);   // Fenster ≈ 1 s
    t('L02 dauerhaft andere Serial ⇒ serial_differs am Fensterende', $v['state'] === 'serial_differs' && $v['served_serial'] === 4 && $v['file_serial'] === 5
        && microtime(true) - $t0 < 1.6 && $v['rounds'] >= 3, json_encode([$v['state'], $v['rounds'], round(microtime(true) - $t0, 2)]));
    $scene(['a.test' => $zf], ['a.test' => ['soa:4', 'soa:4', 'soa:4', 'servfail']]);
    $v = verifyLoaded($fcfg, 'a.test', microtime(true) - 9.0);
    t('L03 erst Abweichung, letzte Runde SERVFAIL ⇒ unknown (R4)', $v['state'] === 'unknown' && $v['reason'] === 'last_round_unknown'
        && $v['observations']['serial_differs'] === 3, json_encode([$v['state'], $v['reason'], $v['observations']]));
    $scene(['a.test' => $zf], ['a.test' => ['soa:5']], [9, 2]);   // Zonenzahl zuerst falsch (9 ≠ 2)
    $v = verifyLoaded($fcfg, 'a.test', microtime(true));
    t('L04 K vorübergehend ⇒ erneut geprüft ⇒ verified', $v['state'] === 'verified' && $v['rounds'] === 2 && $count('rndc') === 2, json_encode([$v['state'], $v['rounds']]));
    $scene(['a.test' => $zf], ['a.test' => ['soa:5']]);
    $v = verifyLoaded($fcfg, 'a.test', microtime(true) - 11.0);   // Fenster schon abgelaufen
    t('L05 kein Budget ⇒ unknown budget_exhausted, keine Abfrage', $v['state'] === 'unknown' && $v['reason'] === 'budget_exhausted' && $v['rounds'] === 0
        && $count('dig') === 0, json_encode([$v['state'], $v['reason']]));
    t('L06 ungültiger Zonenname ⇒ unknown', verifyLoaded($fcfg, 'a..b', microtime(true))['reason'] === 'zone_name_invalid');
    // Cleanup
    $names = ['o1.solo' => ['name' => 'o1.solo', 'reason' => null], 'o2.solo' => ['name' => 'o2.solo', 'reason' => null],
        'O3.solo' => ['name' => null, 'reason' => 'zone_name_unresolved']];
    $scene([], ['o1.solo' => ['soa:1', 'refused'], 'o2.solo' => ['refused']]);
    $r = verifyRemoved($fcfg, $names, 'ok', microtime(true));
    t('L07 absent nach Reload, Kontrollabfrage je Runde höchstens eine', $r['zones']['o1.solo']['served'] === 'absent' && $r['zones']['o2.solo']['served'] === 'absent'
        && $r['zones']['O3.solo']['served'] === 'unknown' && $r['meta']['rounds'] === 2 && $r['meta']['control_queries'] === 2
        && $count('dig', 'ctl.test') === 2, json_encode($r));
    // kein Übertrag: Runde 1 Kontrollzone ok, Zone noch ausgeliefert; Runde 2 REFUSED, Kontrollzone nicht mehr ok ⇒ kein absent
    $scene([], ['o1.solo' => ['soa:1', 'refused'], 'ctl.test' => ['soa:1', 'refused'], 'o2.solo' => ['refused']]);
    $r = verifyRemoved($fcfg, ['o2.solo' => $names['o2.solo'], 'o1.solo' => $names['o1.solo']], 'ok', microtime(true) - 9.0);
    t('L08 kein Übertrag des Kontrollzonen-Ergebnisses über Runden', $r['zones']['o2.solo']['served'] === 'absent'
        && $r['zones']['o1.solo']['served'] !== 'absent', json_encode($r['zones']));
    $scene([], ['o1.solo' => ['soa:1'], 'o2.solo' => ['refused']]);
    $r = verifyRemoved($fcfg, ['o1.solo' => $names['o1.solo'], 'o2.solo' => $names['o2.solo']], 'timeout', microtime(true));
    t('L09 Reload gescheitert ⇒ still_served bzw. unknown, nie absent, ohne K und Kontrollabfrage', $r['zones']['o1.solo']['served'] === 'still_served'
        && $r['zones']['o2.solo']['served'] === 'unknown' && $r['zones']['o2.solo']['served_reason'] === 'reload_timeout'
        && $count('checkconf') === 0 && $count('dig', 'ctl.test') === 0 && $r['meta']['rounds'] === 1, json_encode($r));
    $scene([], ['o1.solo' => ['soa:1']]);
    $r = verifyRemoved($fcfg, ['o1.solo' => $names['o1.solo']], 'ok', microtime(true) - 9.0);
    t('L10 nach dem Reload weiter ausgeliefert (K nein) ⇒ still_served aus letzter Runde', $r['zones']['o1.solo']['served'] === 'still_served', json_encode($r['zones']));
    $scene([], ['o1.solo' => ['soa:1', 'timeout']]);
    $r = verifyRemoved($fcfg, ['o1.solo' => $names['o1.solo']], 'ok', microtime(true) - 7.5);
    t('L11 ausgeliefert, dann Timeout ⇒ unknown (R4)', $r['zones']['o1.solo']['served'] === 'unknown', json_encode($r['zones']));
}

// ---------------------------------------------------------------- BU: Vorabnachweis (Eintrittsschwelle, Zeitverbrauch)
if (run('BU') && $linux) {
    $zf = "$tmp/zones/db.a.test";
    $bu = static function (float $elapsed, ?float $checkconfSleep, ?float $rndcSleep) use ($scene, $fcfg, $root, $tmp, $fake, $zf): array {
        $scene(['a.test' => $zf], []);
        if ($checkconfSleep !== null) {
            file_put_contents("$fake/checkconf.sleep", (string)$checkconfSleep);
        }
        if ($rndcSleep !== null) {
            file_put_contents("$fake/rndc.sleep", (string)$rndcSleep);
        }
        $code = '$_SERVER["REQUEST_TIME_FLOAT"] = microtime(true) - ' . $elapsed . ';'
            . 'function safeZoneName(string $n): string { return preg_replace("/[^a-zA-Z0-9._\\-]/", "_", $n); }'
            . 'require ' . var_export("$root/agent/inc/file_utils.php", true) . ';'
            . 'require ' . var_export("$root/agent/inc/dns_verify.php", true) . ';'
            . '$t0 = microtime(true); $r = verifyCleanupNames(' . var_export($fcfg, true) . ', ["a.test"]);'
            . '$r["wall"] = microtime(true) - $t0; $r["rem"] = agentRemaining(); echo json_encode($r);';
        $out = shell_exec('php -r ' . escapeshellarg($code));
        return json_decode((string)$out, true) ?: ['raw' => $out];
    };
    $r = $bu(4.1, null, null);   // Restzeit ≈ 19,9 s
    t('BU01 knapp unter 20 s ⇒ übersprungen, budget_exhausted, kein Prozess', ($r['proof']['state'] ?? null) === 'skipped'
        && $r['names']['a.test']['reason'] === 'budget_exhausted' && $count('checkconf') === 0 && $count('rndc') === 0, json_encode($r['proof'] ?? $r));
    $r = $bu(3.9, null, null);   // Restzeit ≈ 20,1 s
    t('BU02 knapp über 20 s ⇒ Nachweis, Name eindeutig', ($r['proof']['state'] ?? null) === 'ok' && $r['names']['a.test']['name'] === 'a.test', json_encode($r['proof'] ?? $r));
    $r = $bu(3.9, 2.9, 2.9);   // -l, -px und rndc status je knapp unter der Obergrenze
    t('BU03 größter Verbrauch innerhalb der Obergrenzen ⇒ Restzeit danach ≥ 9 s', ($r['proof']['state'] ?? null) === 'ok' && $r['rem'] >= VERIFY_PREPROOF_AFTER_MIN
        && $r['wall'] > 8.4, sprintf('wall=%.2f rem=%.2f', $r['wall'] ?? -1, $r['rem'] ?? -1));
    $r = $bu(3.9, 30, null);   // named-checkconf hängt
    t('BU04 hängender Nachweis ⇒ Abbruch nach Obergrenze, offen, Restzeit ≥ 9 s', ($r['proof']['state'] ?? null) === 'undeterminable'
        && $r['names']['a.test']['name'] === null && $r['rem'] >= VERIFY_PREPROOF_AFTER_MIN && $r['wall'] < 4.5, sprintf('wall=%.2f rem=%.2f %s', $r['wall'] ?? -1, $r['rem'] ?? -1, json_encode($r['proof'] ?? null)));
    $r = $bu(3.9, 2.9, 30);   // -l/-px knapp, rndc status hängt: insgesamt Obergrenzen ausgeschöpft
    t('BU05 alle drei Schritte bis zur Obergrenze ⇒ Restzeit danach ≥ 9 s', $r['rem'] >= VERIFY_PREPROOF_AFTER_MIN && $r['names']['a.test']['name'] === null
        && $r['wall'] > 8.4, sprintf('wall=%.2f rem=%.2f', $r['wall'] ?? -1, $r['rem'] ?? -1));
    $r = $bu(14.5, null, null);   // Restzeit ≈ 9,5 s (Sperre spät erhalten)
    t('BU06 späte Sperre ⇒ übersprungen', ($r['proof']['state'] ?? null) === 'skipped', json_encode($r['proof'] ?? $r));
}

exec('rm -rf ' . escapeshellarg($tmp));
echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
