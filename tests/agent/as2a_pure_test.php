<?php
/**
 * Datei: tests/agent/as2a_pure_test.php
 * Zweck: Reine Tests für AS2a (ohne BIND, ohne Netzwerk):
 *   R  RFC-1982-Vektoren (tests/rfc1982_vectors.json) gegen serialParse()/serialCompare() des Agenten
 *   Z  checkzoneSerial()
 *   P  strenger dig-Parser (Ausgaben von BIND 9.20 / dig 9.20 auf ns2, 19.11.2026) und Auswertung autoritativer SOA
 *   K  Parser für named-checkconf -px und eindeutige Pfadauflösung
 *   A  Vorrangregel der aggregierten Cleanup-Wirkung
 *   KL Klassifizierer im Core (PublishTargetClassifier, E113) einschließlich unveränderter Einordnung ohne effect
 *
 * Aufruf: php tests/agent/as2a_pure_test.php [<Checkout>]   (Exit-Code 0 nur ohne Fehlschlag)
 */

$root = $argv[1] ?? dirname(__DIR__, 2);
if (!function_exists('safeZoneName')) {
    // wie agent/inc/bind_utils.php (dort mit Konfigurationsabhängigkeit beim Einbinden)
    function safeZoneName(string $name): string {
        return preg_replace('/[^a-zA-Z0-9._\-]/', '_', $name);
    }
}
require $root . '/agent/inc/dns_state.php';
require $root . '/web/inc/app/publish/PublishTargetClassifier.php';

$pass = 0;
$fail = 0;
function t(string $id, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $id . ($detail !== '' ? " $detail" : '') . "\n";
}

// ---------------------------------------------------------------- R: RFC 1982
$v = json_decode(file_get_contents($root . '/tests/rfc1982_vectors.json'), true, 512, JSON_BIGINT_AS_STRING * 0);
t('R00 Vektordatei', is_array($v) && ($v['contract'] ?? null) === 'rfc1982-v1');
foreach ($v['parse'] as $i => $c) {
    $got = serialParse($c['in']);
    t(sprintf('R1%02d parse %s', $i, json_encode($c['in'])), $got === $c['out'], 'got=' . json_encode($got));
}
foreach ($v['compare'] as $i => $c) {
    $got = serialCompare($c['a'], $c['b']);
    t(sprintf('R2%02d compare %s→%s', $i, $c['b'], $c['a']), $got === $c['result'], "got=$got");
}
foreach ($v['compare_invalid'] as $i => [$a, $b]) {
    try {
        serialCompare($a, $b);
        t(sprintf('R3%02d invalid %s', $i, json_encode([$a, $b])), false, 'keine Exception');
    } catch (InvalidArgumentException $e) {
        t(sprintf('R3%02d invalid %s', $i, json_encode([$a, $b])), true);
    }
}

// ---------------------------------------------------------------- Z: named-checkzone
t('Z01 normal', checkzoneSerial("zone example.test/IN: loaded serial 2026111804\nOK\n") === 2026111804);
t('Z02 fehlend', checkzoneSerial("dns_rdata_fromtext: x:6: near '999.1.1.1': bad dotted quad\nzone x/IN: loading from master file x failed: bad dotted quad\n") === null);
t('Z03 doppelt', checkzoneSerial("zone a/IN: loaded serial 1\nzone a/IN: loaded serial 2\nOK\n") === null);
t('Z04 zu groß', checkzoneSerial("zone a/IN: loaded serial 4294967296\nOK\n") === null);
t('Z05 Text in Zeile', checkzoneSerial("x zone a/IN: loaded serial 5\n") === null);

// ---------------------------------------------------------------- P: dig-Parser
$noerr = <<<'DIG'
;; Got answer:
;; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 23660
;; flags: qr aa; QUERY: 1, ANSWER: 1, AUTHORITY: 2, ADDITIONAL: 3

;; OPT PSEUDOSECTION:
; EDNS: version: 0, flags:; udp: 1232
;; ANSWER SECTION:
example.test.		86400	IN	SOA	ns1.example.test. hostmaster.example.test. 2026111804 3600 1200 1209600 3600

;; AUTHORITY SECTION:
example.test.		3600	IN	NS	ns2.example.test.
example.test.		3600	IN	NS	ns1.example.test.

DIG;
$nx = <<<'DIG'
;; Got answer:
;; ->>HEADER<<- opcode: QUERY, status: NXDOMAIN, id: 52391
;; flags: qr aa; QUERY: 1, ANSWER: 0, AUTHORITY: 1, ADDITIONAL: 1

;; OPT PSEUDOSECTION:
; EDNS: version: 0, flags:; udp: 1232
;; AUTHORITY SECTION:
example.test.		0	IN	SOA	ns1.example.test. hostmaster.example.test. 2026111804 3600 1200 1209600 3600

DIG;
$refused = <<<'DIG'
;; Got answer:
;; ->>HEADER<<- opcode: QUERY, status: REFUSED, id: 31815
;; flags: qr; QUERY: 1, ANSWER: 0, AUTHORITY: 0, ADDITIONAL: 1

;; OPT PSEUDOSECTION:
; EDNS: version: 0, flags:; udp: 1232
DIG;
$referral = <<<'DIG'
;; Got answer:
;; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 4711
;; flags: qr; QUERY: 1, ANSWER: 0, AUTHORITY: 1, ADDITIONAL: 2

;; OPT PSEUDOSECTION:
; EDNS: version: 0, flags:; udp: 1232
;; AUTHORITY SECTION:
c1.as2probe.test.	300	IN	NS	ns.c1.as2probe.test.

DIG;
$timeout = ";; communications error to 127.0.0.1#53: timed out\n;; no servers could be reached\n";
$conn = ";; communications error to 127.0.0.1#53: connection refused\n;; no servers could be reached\n";

$r = dnsParseDig($noerr);
t('P01 NOERROR aa', $r['kind'] === 'response' && $r['rcode'] === 'NOERROR' && $r['aa'] && count($r['answer']) === 1 && count($r['authority']) === 2);
t('P02 SOA-Serial', dnsAuthoritativeSoa($r, 'example.test') === 2026111804);
t('P03 SOA fremder Owner', dnsAuthoritativeSoa($r, 'zone.example.test') === null);
$r = dnsParseDig($nx);
t('P04 NXDOMAIN', $r['rcode'] === 'NXDOMAIN' && $r['aa'] && $r['answer'] === [] && $r['authority'][0][0] === 'example.test' && $r['authority'][0][1] === 'SOA');
t('P05 NXDOMAIN keine SOA für Kind', dnsAuthoritativeSoa($r, 'nix.example.test') === null);
$r = dnsParseDig($refused);
t('P06 REFUSED', $r['kind'] === 'response' && $r['rcode'] === 'REFUSED' && !$r['aa'] && $r['answer'] === [] && $r['authority'] === []);
$r = dnsParseDig($referral);
t('P07 Referral', $r['rcode'] === 'NOERROR' && !$r['aa'] && $r['authority'][0] === ['c1.as2probe.test', 'NS', ['ns.c1.as2probe.test.']]);
t('P08 Timeout', dnsParseDig($timeout)['kind'] === 'timeout');
t('P09 Verbindung abgelehnt', dnsParseDig($conn)['kind'] === 'conn_refused');
t('P10 Prozess-Timeout', dnsParseDig('', 'timeout')['kind'] === 'timeout');
t('P11 Abschnittszahl falsch', dnsParseDig(str_replace('ANSWER: 1', 'ANSWER: 2', $noerr))['kind'] === 'error');
t('P12 zwei Header', dnsParseDig($noerr . $noerr)['kind'] === 'error');
t('P13 leere Ausgabe', dnsParseDig('')['kind'] === 'error');
t('P14 abgeschnitten', dnsParseDig(substr($noerr, 0, 160))['kind'] === 'error');
t('P15 aa ohne qr', dnsParseDig(str_replace('flags: qr aa;', 'flags: aa;', $noerr))['kind'] === 'error');
t('P16 Zeile außerhalb Abschnitt', dnsParseDig($refused . "\nexample.test. 1 IN A 192.0.2.1\n")['kind'] === 'error');
$two = str_replace("ANSWER: 1,", "ANSWER: 2,", $noerr);
$two = str_replace("\n\n;; AUTHORITY", "\nexample.test.\t\t86400\tIN\tSOA\tns1.example.test. h.example.test. 7 1 1 1 1\n\n;; AUTHORITY", $two);
t('P17 zwei SOA ⇒ keine autoritative SOA', dnsAuthoritativeSoa(dnsParseDig($two), 'example.test') === null);
t('P18 Owner Großschreibung', dnsAuthoritativeSoa(dnsParseDig(str_replace("example.test.\t\t86400", "EXAMPLE.Test.\t\t86400", $noerr)), 'example.test') === 2026111804);
t('P19 SOA-Serial ungültig', dnsAuthoritativeSoa(dnsParseDig(str_replace('2026111804 3600', '4294967296 3600', $noerr)), 'example.test') === null);
t('P20 Opcode', dnsParseDig(str_replace('opcode: QUERY', 'opcode: NOTIFY', $noerr))['kind'] === 'error');
t('P21 Namensnormalisierung', dnsNormName('Example.TEST.') === 'example.test' && dnsNormName('-x.test') === null
    && dnsNormName('+norec') === null && dnsNormName('a..b') === null && dnsNormName('') === null && dnsNormName('a b') === null);
t('P22 dnsIsBelow', dnsIsBelow('a.b.c', 'b.c') && !dnsIsBelow('ab.c', 'b.c') && !dnsIsBelow('b.c', 'b.c') && dnsIsBelow('x', ''));

// ---------------------------------------------------------------- K: named-checkconf -px
$px = <<<'PX'
options {
	directory "/var/cache/bind";
	listen-on port 53 {
		127.0.0.1/32;
	};
	allow-query {
		127.0.0.1/32;
	};
	recursion no;
};
key "as2probe-key" {
	algorithm "hmac-sha256";
	secret "????";
};
zone "fp.as2probe-solo.test" {
	type master;
	file "/etc/bind/as2probe/db.other";
};
zone "rel.as2probe-solo.test" {
	type master;
	file "db.rel";
};
zone "sec.as2probe-solo.test" {
	type secondary;
	file "db.sec";
	primaries {
		192.0.2.53;
	};
};
zone "." {
	type hint;
	file "/usr/share/dns/root.hints";
};
PX;
$p = dnsParseCheckconf($px);
t('K01 Struktur', $p['ok'] && $p['options']['directory'] === ['/var/cache/bind'] && count($p['zones']) === 4);
t('K02 Zone fp', $p['zones'][0][0] === 'fp.as2probe-solo.test' && $p['zones'][0][2]['file'] === ['/etc/bind/as2probe/db.other'] && $p['zones'][0][2]['type'] === ['master']);
t('K03 secondary-Optionen', array_keys($p['zones'][2][2]) === ['type', 'file', 'primaries']);
t('K04 Hint-Zone', $p['zones'][3][0] === '');
t('K05 Schlüssel nicht als Zone', in_array('key', $p['top'], true));
t('K06 view erkannt', in_array('view', dnsParseCheckconf("view \"a\" {\n\tmatch-clients {\n\t\tany;\n\t};\n};\n")['top'], true));
t('K07 unbalanciert', dnsParseCheckconf("options {\n\tdirectory \"/x\";\n")['ok'] === false);
t('K08 allow-new-zones', dnsParseCheckconf("options {\n\tallow-new-zones yes;\n};\n")['options']['allow-new-zones'] === ['yes']);
t('K09 new-zones-directory', dnsParseCheckconf("options {\n\tnew-zones-directory \"nzd\";\n};\n")['options']['new-zones-directory'] === ['nzd']);
t('K10 Pfad absolut', dnsResolveConfPath('/etc/bind/zones/db.a', '/var/cache/bind') === '/etc/bind/zones/db.a');
t('K11 Pfad relativ', dnsResolveConfPath('db.rel', '/var/cache/bind') === '/var/cache/bind/db.rel');
t('K12 Pfad ..', dnsResolveConfPath('../../etc/bind/zones/db.a', '/var/cache/bind') === null
    && dnsResolveConfPath('/etc/bind/zones/../zones/db.a', null) === null);
t('K13 Pfad . und //', dnsResolveConfPath('/etc/bind/./zones/db.a', null) === null && dnsResolveConfPath('/etc//bind/db', null) === null);
t('K14 relativ ohne directory', dnsResolveConfPath('db.rel', null) === null);
t('K15 Zone mit Klasse', dnsParseCheckconf("zone \"a.test\" IN {\n\ttype master;\n\tfile \"/x/db.a\";\n};\n")['zones'][0][1] === 'IN');

// ---------------------------------------------------------------- A: aggregierte Cleanup-Wirkung
$z = static fn (string $c, string $f): array => ['zone' => 'x', 'conf' => $c, 'file' => $f];
t('A01 Beispiel 3 entfernt, 1 kept, 1 unknown ⇒ partial', cleanupAggregateEffect(true, [
    $z('removed', 'removed'), $z('removed', 'removed'), $z('removed', 'absent'), $z('kept', 'kept'), $z('kept', 'unknown')]) === 'partial');
t('A02 alles entfernt ⇒ applied', cleanupAggregateEffect(true, [$z('removed', 'removed'), $z('removed', 'absent')]) === 'applied');
t('A03 nur zones.conf ⇒ applied', cleanupAggregateEffect(true, []) === 'applied');
t('A04 keine Änderung, unknown ⇒ unknown', cleanupAggregateEffect(false, [$z('kept', 'unknown')]) === 'unknown');
t('A05 keine Änderung ⇒ none', cleanupAggregateEffect(false, [$z('kept', 'kept')]) === 'none');
t('A06 Änderung + nur kept ⇒ partial', cleanupAggregateEffect(false, [$z('kept', 'removed')]) === 'partial');

// ---------------------------------------------------------------- KL: Klassifizierer (E113)
$cls = static function (string $kind, int $http, array $body): array {
    return PublishTargetClassifier::classify($kind, ['body' => json_encode($body), 'httpCode' => $http, 'curlError' => '', 'curlErrno' => 0]);
};
$Z = PublishTargetClassifier::KIND_ZONE;
$C = PublishTargetClassifier::KIND_CONF;
$L = PublishTargetClassifier::KIND_CLEANUP;
$cl = ['removed_conf' => ['a.conf'], 'removed_zone_files' => ['db.a'], 'rndc' => ''];
$cases = [
    // [ID, Kanal, HTTP, Body, erwartet]
    ['KL01', $Z, 200, ['status' => 'success', 'effect' => 'applied'], 'ok'],
    ['KL02', $Z, 200, ['status' => 'success', 'effect' => 'unchanged'], 'ok'],
    ['KL03', $Z, 200, ['status' => 'success', 'effect' => 'partial'], 'unknown'],
    ['KL04', $Z, 200, ['status' => 'success', 'effect' => 'none'], 'unknown'],
    ['KL05', $Z, 200, ['status' => 'success', 'effect' => 'unknown'], 'unknown'],
    ['KL06', $Z, 409, ['status' => 'error', 'effect' => 'none', 'code' => 'stale_serial'], 'rejected'],
    ['KL07', $Z, 409, ['status' => 'error', 'effect' => 'unchanged'], 'unknown'],
    ['KL08', $Z, 500, ['status' => 'error', 'effect' => 'applied', 'code' => 'reload_failed', 'rollback' => 'not_attempted'], 'unknown'],
    ['KL09', $Z, 500, ['status' => 'error', 'effect' => 'partial'], 'unknown'],
    ['KL10', $Z, 500, ['status' => 'error', 'effect' => 'unknown', 'code' => 'persist_unconfirmed', 'rollback' => 'not_attempted'], 'unknown'],
    ['KL11', $Z, 200, ['status' => 'success', 'effect' => 'bogus'], 'unknown'],
    ['KL12', $Z, 200, ['status' => 'error', 'effect' => 'applied'], 'unknown'],
    ['KL13', $Z, 500, ['status' => 'success', 'effect' => 'applied'], 'unknown'],
    ['KL14', $C, 422, ['status' => 'error', 'effect' => 'none', 'rollback' => 'ok'], 'rejected'],
    ['KL15', $C, 500, ['status' => 'error', 'effect' => 'unknown', 'rollback' => 'failed'], 'unknown'],
    ['KL16', $C, 500, ['status' => 'error', 'effect' => 'applied', 'code' => 'reload_timeout'], 'unknown'],
    ['KL17', $L, 200, ['status' => 'success', 'effect' => 'applied'] + $cl, 'ok'],
    ['KL18', $L, 200, ['status' => 'success', 'effect' => 'unchanged', 'removed_conf' => [], 'removed_zone_files' => [], 'rndc' => ''], 'ok'],
    ['KL19', $L, 200, ['status' => 'success', 'effect' => 'partial'] + $cl, 'partial'],
    ['KL20', $L, 200, ['status' => 'success', 'effect' => 'none'] + $cl, 'unknown'],
    ['KL21', $L, 500, ['status' => 'error', 'effect' => 'none', 'removed_conf' => [], 'removed_zone_files' => []], 'rejected'],
    ['KL22', $L, 500, ['status' => 'error', 'effect' => 'partial', 'code' => 'cleanup_incomplete'] + $cl, 'partial'],
    ['KL23', $L, 500, ['status' => 'error', 'effect' => 'applied', 'code' => 'reload_failed'] + $cl, 'partial'],
    ['KL24', $L, 500, ['status' => 'error', 'effect' => 'unknown', 'rollback' => 'failed'], 'unknown'],
    ['KL25', $L, 500, ['status' => 'error', 'effect' => 'unchanged'], 'unknown'],
    ['KL26', $L, 200, ['status' => 'success', 'effect' => 'applied'], 'unknown'],   // ohne Entfernt-Listen
    // ohne effect: unverändert E63
    ['KL30', $Z, 200, ['status' => 'success', 'message' => 'x'], 'ok'],
    ['KL31', $Z, 422, ['status' => 'error', 'message' => 'x'], 'rejected'],
    ['KL32', $Z, 500, ['status' => 'error', 'message' => 'x', 'rollback' => 'failed', 'code' => 'persist_unconfirmed'], 'unknown'],
    ['KL33', $L, 500, ['status' => 'error', 'message' => 'x', 'removed_conf' => [], 'removed_zone_files' => [], 'rndc' => ''], 'partial'],
    ['KL34', $L, 500, ['status' => 'error', 'message' => 'x'], 'rejected'],
];
foreach ($cases as [$id, $kind, $http, $body, $exp]) {
    $got = $cls($kind, $http, $body)['status'];
    t("$id $kind $http " . ($body['effect'] ?? '-'), $got === $exp, "got=$got exp=$exp");
}
$r = $cls($Z, 409, ['status' => 'error', 'effect' => 'none', 'code' => 'stale_serial', 'message' => 'm']);
t('KL40 Fehlercode übernommen', $r['errorCode'] === 'stale_serial');
$r = $cls($L, 500, ['status' => 'error', 'effect' => 'partial', 'code' => 'cleanup_incomplete'] + $cl);
t('KL41 Cleanup-Zähler', $r['removedConf'] === 1 && $r['removedZoneFiles'] === 1);
$r = PublishTargetClassifier::classify($Z, ['body' => '', 'httpCode' => 0, 'curlError' => 'Operation timed out', 'curlErrno' => 28]);
t('KL42 Transport-Timeout unverändert', $r['status'] === 'unknown' && $r['errorCode'] === 'timeout');

// ---------------------------------------------------------------- L: Sperrfrist (Review AS2a, Befund 2)
// Anfragebeginn 20 s zurück ⇒ Restzeit etwa 4 s < Reserve 8 s; die Sperre ist frei. Erwartet: timeout ohne Sperrversuch.
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 20.0;
$lockFile = tempnam(sys_get_temp_dir(), 'as2a-lock');
$probe = fopen($lockFile, 'c');
t('L00 Sperre vorher frei', flock($probe, LOCK_EX | LOCK_NB) && flock($probe, LOCK_UN));
$l = agentLockWithDeadline($lockFile, 8.0);
t('L01 Reserve unterschritten, Sperre frei ⇒ timeout', $l['handle'] === null && $l['reason'] === 'timeout', json_encode(['reason' => $l['reason'], 'handle' => $l['handle'] !== null]));
t('L02 keine Sperre gehalten', flock($probe, LOCK_EX | LOCK_NB));
flock($probe, LOCK_UN);
fclose($probe);
@unlink($lockFile);
t('L03 Restzeit', agentRemaining() < 8.0 && agentRemaining() > 0.0, sprintf('%.1f', agentRemaining()));

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
