<?php
/**
 * Datei: tests/history/audit_test.php
 * Zweck: Tests der fachlichen Audit-Ereignisse (AP1/H2c, R-A1–R-A8, E43–E54) auf einer isolierten Datenbank
 *        dnsmgr_t21_r<RUN>_aud{rr|rc}, unter REPEATABLE READ (innodb_snapshot_isolation = ON) bzw. READ COMMITTED.
 *        Eigentum wie tests/history/owned.sh.
 *
 * Abschnitte (Fall-IDs):
 *   Q  AuditPolicy rein: Whitelist, Typprüfung, Kennzeichen, Determinismus, Längengrenzen, Schwärzung
 *   K  SQL-Constraints von audit_events (NULL-Semantik, seq, Eindeutigkeit, JSON, Länge) – Negativfälle
 *   P  alle auditierten Pfade mit exakten Ereignissen und Änderungsmengen (Zonen, Server, Reload, DynDNS, Benutzer)
 *   A  Berechtigung: abgelehnte Zugriffe ohne Audit- und History-Zeilen
 *   R  Schwärzung von Freitext, Längengrenze, Suche nach Geheimnissen
 *   F  Fehlerinjektion (Trigger) je Pfad: vollständiger Rollback, bisheriger Fehlercode; Reload unverändert
 *   G  Bindung an Transaktion und Reihenfolge (Savepoint, finish nach ZoneHistoryRecorder, Kontext)
 *   N  Nebenläufigkeit: Serverstand unter Sperre (E50), lokaler Server, Kaskadenzähler – mit Lock-Wait
 *   X  Stresslauf mit Invarianten und Geheimnissuche
 *
 * Aufruf (als root über den MariaDB-Socket): php audit_test.php <App-Verzeichnis> <RR|RC>
 * Optional: H2C_ONLY=P,G (Abschnittsauswahl, Mutationsproben).
 */

require_once __DIR__ . '/h2b_lib.php';

if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', 12);
}

H2b::init($argv[1] ?? '', $argv[2] ?? '', 'aud');
require_once H2b::$app . '/web/inc/app/servers/ServerControlBackend.php';
require_once H2b::$app . '/web/inc/app/SchemaCheck.php';
$iso = H2b::$iso;
$W = rtrim(sys_get_temp_dir(), '/') . '/dnsmgr-h2c-aud' . strtolower($iso) . '-' . getmypid();
mkdir($W, 0700);
register_shutdown_function(static function () use ($W): void { exec('rm -rf ' . escapeshellarg($W)); });
$only = getenv('H2C_ONLY') ?: '';
$run = static fn (string $s): bool => $only === '' || in_array($s, explode(',', $only), true);
$codes = static fn (ServiceResult $r): array => array_map(fn ($m) => $m->code, $r->messages);

/** Test-Backend des BIND-Reloads: feste Antwort, Antworttext mit erkennbarem Muster (darf nie gespeichert werden) */
final class H2cFakeControl implements ServerControlBackend
{
    public int $calls = 0;
    public function __construct(private readonly string $status) {}
    public function reloadBind(?string $apiIp, string $apiToken): array
    {
        $this->calls++;
        return ['status' => $this->status, 'message' => 'H2C-AGENTENANTWORT-' . $this->status];
    }
}

/** Audit-Ereignisse nach der Marke: "op#seq typ:id/label z=zone aktion changes" */
function ev(PDO $p, int $mark): array
{
    return array_map(fn ($e) => "{$e['operation']}#{$e['seq']} {$e['entity_type']}:{$e['entity_id']}/{$e['entity_label']} z="
        . ($e['zone_id'] ?? '-') . " {$e['action']} {$e['changes']}",
        H2b::all($p, 'SELECT c.operation, e.seq, e.entity_type, e.entity_id, e.entity_label, e.zone_id, e.action, e.changes'
            . ' FROM audit_events e JOIN history_change_sets c ON c.id = e.change_set_id WHERE c.id > ? ORDER BY e.change_set_id, e.seq', [$mark]));
}

/** Änderungsmengen nach der Marke: [id => [op, Revisionen, Ereignisse]] */
function sets(PDO $p, int $mark): array
{
    $out = [];
    foreach (H2b::all($p, 'SELECT id, operation FROM history_change_sets WHERE id > ? ORDER BY id', [$mark]) as $c) {
        $out[(int)$c['id']] = [$c['operation'],
            (int)H2b::one($p, 'SELECT COUNT(*) FROM zone_revisions WHERE change_set_id = ?', [$c['id']]),
            (int)H2b::one($p, 'SELECT COUNT(*) FROM audit_events WHERE change_set_id = ?', [$c['id']])];
    }
    return $out;
}

/** Geheimnisse in Audit-Ereignissen und Änderungsmengen (Zeichenketten und Muster) */
function secretFindings(PDO $p, array $extra = []): array
{
    $dump = H2b::sh('mysqldump --skip-comments --no-create-info ' . H2b::$db . ' audit_events history_change_sets');
    $needles = array_merge(['H2BTOKEN', 'H2BFIXTURE', '$2y$', '$argon2', H2b::DYN_PASSWORD, 'h2b_dynkonto', '192.0.2.50', '192.0.2.99', 'H2C-AGENTENANTWORT'], $extra);
    $hits = array_values(array_filter($needles, fn ($n) => str_contains($dump, $n)));
    foreach (H2b::all($p, 'SELECT changes FROM audit_events') as $r) {
        foreach (json_decode($r['changes'], true) as $field => $entry) {
            foreach ((is_array($entry) ? $entry : [$entry]) as $v) {
                if (is_string($v) && AuditPolicy::looksSecret($v)) {
                    $hits[] = "Muster in $field";
                }
            }
        }
    }
    return $hits;
}

echo "Audit-Tests H2c ($iso) – Datenbank " . H2b::$db . "\n";
$a = H2b::admin();

/* ============================================================================================ */
if ($run('Q')) {
    T::section('Q AuditPolicy (rein)');
    $c = fn (...$x) => AuditPolicy::changes(...$x);
    T::eq('{"name":{"new":"ns9.test"},"dns_ip4":{"new":"192.0.2.9"},"is_local":{"new":false},"active":{"new":true},"api_token":"set"}',
        $c('server', 'create', [], ['active' => 1, 'is_local' => '0', 'name' => 'ns9.test', 'dns_ip4' => '192.0.2.9', 'dns_ip6' => null, 'api_ip' => ''], ['api_token' => 'set']),
        'Q01', 'Anlage: Whitelist-Reihenfolge, NULL/leer entfällt, Flags normalisiert, Token nur als Kennzeichen');
    T::eq($c('server', 'update', ['name' => 'a.test', 'active' => 1], ['name' => 'b.test', 'active' => '1']),
        $c('server', 'update', ['active' => '1', 'name' => 'a.test'], ['active' => 1, 'name' => 'b.test']), 'Q02', 'deterministisch unabhängig von Eingabereihenfolge und Typdarstellung');
    T::eq(null, $c('user', 'update', ['role' => 'admin'], ['role' => 'admin']), 'Q03', 'keine Änderung ⇒ kein Ereignis');
    T::throws(LogicException::class, fn () => $c('user', 'update', ['password_hash' => 'x'], ['password_hash' => 'y']), 'Q04', 'nicht freigegebenes Feld (password_hash) ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('dyndns_account', 'update', ['username' => 'a'], ['username' => 'b']), 'Q05', 'Geheimnisfeld als Wert (DynDNS-Kontoname) ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('dyndns_account', 'update', ['current_ipv4' => '192.0.2.1'], ['current_ipv4' => '192.0.2.2']), 'Q06', 'Client-IP (current_ipv4) ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('server', 'update', [], [], ['api_token' => 'abc123']), 'Q07', 'Kennzeichen mit Wert ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('server', 'update', [], [], ['name' => 'changed']), 'Q08', 'Kennzeichen für Nicht-Geheimnisfeld ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('server', 'update', ['name' => 'a.test'], ['name' => '$2y$12$abc']), 'Q09', 'Bezeichner mit "$" (Hash) ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('server', 'update', ['dns_ip4' => '192.0.2.1'], ['dns_ip4' => 'kein-ip']), 'Q10', 'ungültige IP ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('user', 'update', ['role' => 'admin'], ['role' => 'root']), 'Q11', 'ungültige Rolle ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('user', 'update', ['username' => 'a'], []), 'Q12', 'Änderung mit abweichenden Feldern ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('user', 'reload', [], [], [], ['result' => 'ok']), 'Q13', 'reload nur für Server');
    T::throws(LogicException::class, fn () => $c('server', 'reload', [], [], [], ['result' => 'H2C-AGENTENANTWORT']), 'Q14', 'reload-Ergebnis nur ok/failed (kein Antworttext)');
    T::throws(LogicException::class, fn () => $c('ufo', 'create', [], []), 'Q15', 'unbekannter Entitätstyp');
    T::throws(LogicException::class, fn () => $c('zone', 'update', ['description' => "x\xff"], ['description' => 'y']), 'Q16', 'ungültiges UTF-8 ⇒ LogicException');
    T::throws(LogicException::class, fn () => $c('zone', 'delete', [], [], [], ['dyndns_accounts_removed' => -1]), 'Q17', 'negative Anzahl ⇒ LogicException');
    T::eq('{"description":{"old":"alt","new":null,"new_redacted":true}}', $c('zone', 'update', ['description' => 'alt'], ['description' => 'Passwort $2y$12$abcdefghijklmnopqrstuv']),
        'Q18', 'Freitext mit Hash ⇒ geschwärzt (Wert NULL, Kennzeichen)');
    T::eq('{"description":{"old":null,"new":null,"new_redacted":true}}', $c('zone', 'update', ['description' => null], ['description' => 'token 0123456789abcdef0123456789abcdef01']),
        'Q19', 'Freitext mit Token-ähnlicher Zeichenfolge ⇒ geschwärzt');
    $long = str_repeat('ä', 600);
    $j = json_decode((string)$c('zone', 'update', ['description' => null], ['description' => $long]), true);
    T::ok(mb_strlen($j['description']['new']) === AuditPolicy::MAX_TEXT_CHARS && $j['description']['new_truncated'] === true, 'Q20', 'Freitext auf ' . AuditPolicy::MAX_TEXT_CHARS . ' Zeichen gekürzt (mehrbyte-sicher)');
    T::eq('{"zones":{"added":[4],"removed":[1]}}', $c('user', 'update', ['zones' => [2, 1, '3']], ['zones' => [4, 3, 2, 2]]), 'Q21', 'Zonenmengen: sortiert, dedupliziert, hinzugefügt/entfernt');
    T::eq('{"zones":{"added_count":250}}', $c('user', 'update', ['zones' => []], ['zones' => range(1, 250)]), 'Q22', 'große Zonenmenge nur als Anzahl');
    T::eq('{"zones":{"mode":"all","count":7}}', $c('user', 'create', [], ['zones' => ['mode' => 'all', 'count' => 7]]), 'Q23', 'alle Zonen: mode all mit Anzahl');
    T::throws(LogicException::class, fn () => $c('user', 'update', ['zones' => []], ['zones' => ['mode' => 'all', 'count' => 7]]), 'Q24', 'mode all nur bei Anlage');
    T::eq('{"result":"failed"}', $c('server', 'reload', [], [], [], ['result' => 'failed']), 'Q25', 'reload: nur Ergebnis');
    T::throws(LogicException::class, fn () => AuditPolicy::label("a b"), 'Q26', 'Label mit Leerraum ⇒ LogicException');
    T::throws(LogicException::class, fn () => AuditPolicy::label(str_repeat('a', 256)), 'Q27', 'Label über 255 Zeichen ⇒ LogicException');
    $all = $c('server', 'update',
        ['name' => str_repeat('a', 255), 'dns_ip4' => null, 'dns_ip6' => null, 'api_ip' => null, 'is_local' => 0, 'active' => 0],
        ['name' => str_repeat('b', 255), 'dns_ip4' => '192.0.2.1', 'dns_ip6' => '2001:db8::1', 'api_ip' => '192.0.2.2', 'is_local' => 1, 'active' => 1], ['api_token' => 'rotated']);
    T::ok(strlen((string)$all) < AuditPolicy::MAX_JSON_BYTES, 'Q28', 'größtes Server-Ereignis unter der Längengrenze (' . strlen((string)$all) . ' Bytes)');
    $zz = $c('zone', 'update', ['description' => str_repeat('€', 600)], ['description' => str_repeat('😀', 600)]);
    T::ok(strlen((string)$zz) < AuditPolicy::MAX_JSON_BYTES, 'Q29', 'größtes Freitext-Ereignis unter der Längengrenze (' . strlen((string)$zz) . ' Bytes)');
    T::ok(AuditPolicy::looksSecret('-----BEGIN PRIVATE KEY-----') && AuditPolicy::looksSecret('$argon2id$v=19') && !AuditPolicy::looksSecret('Kundenzone Köln, Ansprechpartner: Admin-Team'),
        'Q30', 'Geheimnismuster: Schlüssel, argon2; normaler Text nicht');
}

/* ============================================================================================ */
if ($run('K')) {
    T::section("K SQL-Constraints audit_events ($iso)");
    $p = H2b::reset(false);
    $p->exec("INSERT INTO history_change_sets (operation, actor_type, channel) VALUES ('h2c.test', 'system', 'cli')");
    $cs = (int)$p->lastInsertId();
    $ins = function (array $v) use ($p, $cs): ?int {
        $row = $v + ['cs' => $cs, 'seq' => 1, 'type' => 'server', 'id' => 5, 'label' => 'x', 'zone' => null, 'action' => 'update', 'changes' => '{"a":1}'];
        try {
            $s = $p->prepare('INSERT INTO audit_events (change_set_id, seq, entity_type, entity_id, entity_label, zone_id, action, changes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $s->execute([$row['cs'], $row['seq'], $row['type'], $row['id'], $row['label'], $row['zone'], $row['action'], $row['changes']]);
            return 0;
        } catch (PDOException $e) {
            return (int)($e->errorInfo[1] ?? -1);
        }
    };
    T::eq(0, $ins(['seq' => 1]), 'K00', 'gültige Zeile (Server ohne Zone) angenommen');
    T::eq(0, $ins(['seq' => 2, 'type' => 'zone', 'id' => 7, 'zone' => 7]), 'K00b', 'gültige Zeile (Zone mit zone_id = entity_id)');
    T::eq(4025, $ins(['seq' => 3, 'type' => 'zone', 'id' => 7, 'zone' => null]), 'K01', 'Zone mit zone_id NULL abgelehnt (NULL-Semantik: Bedingung ergibt FALSE, nicht UNKNOWN)');
    T::eq(4025, $ins(['seq' => 3, 'type' => 'zone', 'id' => 7, 'zone' => 8]), 'K02', 'Zone mit zone_id ≠ entity_id abgelehnt');
    T::eq(4025, $ins(['seq' => 3, 'type' => 'dyndns_account', 'zone' => null]), 'K03', 'DynDNS-Konto ohne Zone abgelehnt');
    T::eq(4025, $ins(['seq' => 3, 'type' => 'user', 'zone' => 4]), 'K04', 'Benutzer mit Zone abgelehnt');
    T::eq(1048, $ins(['seq' => null]), 'K05', 'seq NULL abgelehnt (NOT NULL)');
    T::eq(4025, $ins(['seq' => 0]), 'K06', 'seq 0 abgelehnt');
    T::eq(1062, $ins(['seq' => 1]), 'K07', 'seq doppelt je Änderungsmenge abgelehnt (UNIQUE)');
    T::eq(4025, $ins(['seq' => 3, 'id' => 0]), 'K08', 'entity_id 0 abgelehnt');
    T::eq(4025, $ins(['seq' => 3, 'label' => '']), 'K09', 'leeres Label abgelehnt');
    T::eq(1048, $ins(['seq' => 3, 'label' => null]), 'K10', 'Label NULL abgelehnt');
    T::eq(4025, $ins(['seq' => 3, 'type' => 'user', 'action' => 'reload']), 'K11', 'reload nur für Server');
    T::eq(4025, $ins(['seq' => 3, 'changes' => '{"a":']), 'K12', 'ungültiges JSON abgelehnt');
    T::eq(4025, $ins(['seq' => 3, 'changes' => '[1]']), 'K13', 'JSON-Array abgelehnt (nur Objekt)');
    T::eq(4025, $ins(['seq' => 3, 'changes' => '{}']), 'K14', 'leeres Objekt abgelehnt');
    T::eq(4025, $ins(['seq' => 3, 'changes' => '{"a":"' . str_repeat('x', 16400) . '"}']), 'K15', 'mehr als 16384 Bytes abgelehnt');
    T::eq(1048, $ins(['seq' => 3, 'changes' => null]), 'K16', 'changes NULL abgelehnt');
    T::eq(1452, $ins(['seq' => 3, 'cs' => 999999]), 'K17', 'unbekannte Änderungsmenge abgelehnt (FK)');
    T::ok(in_array($ins(['seq' => 3, 'type' => 'ufo']), [1265, 4025], true), 'K18', 'unbekannter Entitätstyp abgelehnt');
    T::eq(1451, (function () use ($p, $cs): int { try { $p->exec("DELETE FROM history_change_sets WHERE id = $cs"); return 0; } catch (PDOException $e) { return (int)$e->errorInfo[1]; } })(),
        'K19', 'Änderungsmenge mit Ereignissen nicht löschbar (RESTRICT)');
    T::eq([], SchemaCheck::problems($p), 'K20', 'SchemaCheck: Schema ohne Befund');
}

/* ============================================================================================ */
if ($run('P')) {
    T::section("P Pfade ($iso)");

    // ---- Zonen
    $p = H2b::reset();
    $m = H2b::mark($p);
    $r = H2b::zones($p)->createZone($a, H2b::newZone('aud.h2b.test', [1], 1));
    $zid = (int)$r->lastZoneId;
    T::eq(["zone.create#1 zone:$zid/aud.h2b.test z=$zid create {\"description\":{\"new\":\"H2B neu\"},\"allow_dyndns\":{\"new\":false}}"], ev($p, $m), 'P01', 'Zonenanlage: Metadaten als Ereignis');
    T::eq(['zone.create', 1, 1], array_values(sets($p, $m))[0] ?? null, 'P01b', 'eine Änderungsmenge mit Revision und Ereignis (E49)');
    T::eq(1, count(sets($p, $m)), 'P01c', 'genau eine Änderungsmenge');

    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['description' => 'Neu']));
    T::eq(['zone.update#1 zone:2/beta.h2b.test z=2 update {"description":{"old":null,"new":"Neu"}}'], ev($p, $m), 'P02', 'nur Beschreibung: Ereignis');
    T::eq([['zone.update', 0, 1]], array_values(sets($p, $m)), 'P02b', 'genau eine neue Änderungsmenge ohne Revision');

    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['description' => 'Mix', 'ttl' => '7100', 'allowDyndns' => true]));
    T::eq(['zone.update#1 zone:2/beta.h2b.test z=2 update {"description":{"old":"Neu","new":"Mix"},"allow_dyndns":{"old":false,"new":true}}'], ev($p, $m), 'P03', 'Mischoperation: Ereignis der Metadaten');
    T::eq([['zone.update', 1, 1]], array_values(sets($p, $m)), 'P03b', 'Revision und Ereignis in derselben Änderungsmenge');

    $m = H2b::mark($p);
    $r = H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['ttl' => '7200']));
    T::eq([[], [['zone.update', 1, 0]]], [ev($p, $m), array_values(sets($p, $m))], 'P04', 'nur DNS-Inhalt: kein Ereignis');

    $m = H2b::mark($p);
    $z = H2b::zoneadmin();
    $r = H2b::zones($p)->updateZone($z, 1, H2b::zoneInput($p, $z, 1, ['description' => 'Alpha2']));
    T::eq(['zone.update#1 zone:1/alpha.h2b.test z=1 update {"description":{"old":"Alpha","new":"Alpha2"}}'], ev($p, $m), 'P05', 'zoneadmin: Ereignis');
    T::eq('h2b_zoneadmin', H2b::one($p, 'SELECT actor_label FROM history_change_sets WHERE id > ?', [$m]), 'P05b', 'Akteur zoneadmin mit Label');

    $m = H2b::mark($p);
    $r = H2b::zones($p)->deleteZone($a, 1);
    T::ok($r->ok, 'P06', 'Zone 1 gelöscht');
    T::eq(['zone.delete#1 zone:1/alpha.h2b.test z=1 delete {"description":{"old":"Alpha2"},"allow_dyndns":{"old":true},"dyndns_accounts_removed":1,"user_assignments_removed":1}'],
        ev($p, $m), 'P06b', 'Zonenlöschung: letzte Metadaten und Kaskadenzähler (E53)');
    T::eq([['zone.delete', 1, 1]], array_values(sets($p, $m)), 'P06c', 'Revision delete und Ereignis in derselben Änderungsmenge');
    $m = H2b::mark($p);
    H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2));
    T::eq([], sets($p, $m), 'P07', 'unverändert: keine Änderungsmenge');
    T::eq([], secretFindings($p), 'P08', 'keine Geheimnisse (Zonen)');
    T::eq([], H2b::invariants($p), 'P09', 'Invarianten (Zonen)');

    // ---- Server und Reload
    $p = H2b::reset();
    $sv = H2b::servers($p);
    $m = H2b::mark($p);
    $r = $sv->createServer($a, new ServerInput('ns3.h2b.test', '192.0.2.3', '', '192.0.2.3', 'H2BTOKEN-drei-0123456789abcdef0123456789', false, false));
    $s3 = (int)H2b::one($p, "SELECT id FROM servers WHERE name = 'ns3.h2b.test'");
    T::eq(["server.create#1 server:$s3/ns3.h2b.test z=- create {\"name\":{\"new\":\"ns3.h2b.test\"},\"dns_ip4\":{\"new\":\"192.0.2.3\"},\"api_ip\":{\"new\":\"192.0.2.3\"},\"is_local\":{\"new\":false},\"active\":{\"new\":false},\"api_token\":\"set\"}"],
        ev($p, $m), 'P10', 'Serveranlage: Werte, Token nur als Kennzeichen');
    $m = H2b::mark($p);
    $sv->updateServer($a, $s3, new ServerInput('ns3.h2b.test', '192.0.2.3', '', '192.0.2.33', '', false, false));
    T::eq(["server.update#1 server:$s3/ns3.h2b.test z=- update {\"api_ip\":{\"old\":\"192.0.2.3\",\"new\":\"192.0.2.33\"}}"], ev($p, $m), 'P11', 'nur api_ip (Token unverändert): Ereignis ohne Token');
    $m = H2b::mark($p);
    $sv->updateServer($a, $s3, new ServerInput('ns3.h2b.test', '192.0.2.3', '', '192.0.2.33', 'H2BTOKEN-neu-0123456789abcdef01234567890', false, false));
    T::eq(["server.update#1 server:$s3/ns3.h2b.test z=- update {\"api_token\":\"rotated\"}"], ev($p, $m), 'P12', 'Token-Rotation nur als Kennzeichen');
    $m = H2b::mark($p);
    $r = $sv->updateServer($a, $s3, new ServerInput('ns3.h2b.test', '192.0.2.3', '', '192.0.2.33', 'H2BTOKEN-neu-0123456789abcdef01234567890', false, false));
    T::ok(in_array('server.update.unchanged', $codes($r), true) && sets($p, $m) === [], 'P13', 'gleicher Token: unchanged, keine Änderungsmenge');
    $m = H2b::mark($p);
    $sv->updateServer($a, $s3, new ServerInput('ns3.h2b.test', '192.0.2.3', '', '192.0.2.33', '', false, true));
    T::eq(["server.update#1 server:$s3/ns3.h2b.test z=- update {\"active\":{\"old\":false,\"new\":true}}"], ev($p, $m), 'P14', 'Aktivierung');
    T::eq(1, (int)H2b::one($p, 'SELECT bind_dirty FROM system_status'), 'P14b', 'Reaktivierung setzt bind_dirty wie bisher');

    $m = H2b::mark($p);
    $s = H2b::all($p, 'SELECT * FROM servers WHERE id = 2')[0];
    $r = $sv->updateServer($a, 2, new ServerInput('ns2r.h2b.test', $s['dns_ip4'], '', $s['api_ip'], '', false, true));
    T::eq(['server.update#1 server:2/ns2r.h2b.test z=- update {"name":{"old":"ns2.h2b.test","new":"ns2r.h2b.test"}}'], ev($p, $m), 'P15', 'Umbenennung: Ereignis');
    $st = array_values(sets($p, $m));
    T::ok(count($st) === 1 && $st[0][0] === 'server.update' && $st[0][1] === 2 && $st[0][2] === 1, 'P15b', 'Revisionen (Zonen 1, 6) und Ereignis in derselben Änderungsmenge (E49)');

    $m = H2b::mark($p);
    $r = $sv->updateServer($a, 2, new ServerInput('ns2r.h2b.test', $s['dns_ip4'], '', $s['api_ip'], '', true, true));
    T::eq(['server.update#1 server:1/ns1.h2b.test z=- update {"is_local":{"old":true,"new":false}}',
        'server.update#2 server:2/ns2r.h2b.test z=- update {"is_local":{"old":false,"new":true}}'], ev($p, $m), 'P16', 'lokaler Server: impliziter Wechsel des bisherigen als eigenes Ereignis');
    T::eq([2], array_map('intval', array_column(H2b::all($p, 'SELECT id FROM servers WHERE is_local = 1'), 'id')), 'P16b', 'genau ein lokaler Server');

    $sv2 = new ServerService($p, new BindZoneBuildBackend($p), new TopologyLock($p), $ok = new H2cFakeControl('ok'));
    $m = H2b::mark($p);
    $r = $sv2->reloadBind($a, 2);
    T::eq(['server.reload#1 server:2/ns2r.h2b.test z=- reload {"result":"ok"}'], ev($p, $m), 'P17', 'BIND-Reload ok: Nachtrag nur mit Ergebnis');
    T::ok($r->ok && $codes($r) === ['server.reload.success'] && !array_key_exists('audit', $r->messages[0]->context), 'P17b', 'Ergebnis unverändert');
    $m = H2b::mark($p);
    $r = (new ServerService($p, new BindZoneBuildBackend($p), new TopologyLock($p), new H2cFakeControl('error')))->reloadBind($a, 2);
    T::eq(['server.reload#1 server:2/ns2r.h2b.test z=- reload {"result":"failed"}'], ev($p, $m), 'P18', 'BIND-Reload fehlgeschlagen: Ergebnis failed');
    T::ok(!$r->ok && $codes($r) === ['server.reload.failed'], 'P18b', 'Fehlermeldung unverändert');
    $m = H2b::mark($p);
    $r = $sv2->reloadBind($a, 999);
    T::ok($codes($r) === ['server.reload.not_found'] && sets($p, $m) === [], 'P19', 'Reload unbekannter Server: kein Ereignis');

    $m = H2b::mark($p);
    $sv->updateServer($a, $s3, new ServerInput('ns3.h2b.test', '192.0.2.3', '', '192.0.2.33', '', false, false));
    $m = H2b::mark($p);
    $r = $sv->deleteServer($a, $s3);
    T::eq(["server.delete#1 server:$s3/ns3.h2b.test z=- delete {\"name\":{\"old\":\"ns3.h2b.test\"},\"dns_ip4\":{\"old\":\"192.0.2.3\"},\"api_ip\":{\"old\":\"192.0.2.33\"},\"is_local\":{\"old\":false},\"active\":{\"old\":false}}"],
        ev($p, $m), 'P20', 'Serverlöschung: letzte Werte aus gesperrtem Stand');
    T::eq([], secretFindings($p, ['H2BTOKEN-neu', 'H2BTOKEN-drei']), 'P21', 'keine Geheimnisse und kein Agenten-Antworttext (Server)');
    T::eq([], H2b::invariants($p), 'P22', 'Invarianten (Server)');

    // ---- DynDNS-Konten
    $p = H2b::reset();
    $dy = H2b::dyndns($p);
    $m = H2b::mark($p);
    $r = $dy->createAccount($a, new DyndnsAccountInput('h2b_neukonto', 'Neu-H2C-Passwort-1', 'neu', 1));
    $nid = (int)H2b::one($p, "SELECT id FROM dyndns_accounts WHERE username = 'h2b_neukonto'");
    $host = (string)H2b::one($p, 'SELECT hostname FROM dyndns_accounts WHERE id = ?', [$nid]);
    T::eq(["dyndns_account.create#1 dyndns_account:$nid/$host z=1 create {\"hostname\":{\"new\":\"$host\"},\"zone_id\":{\"new\":1},\"username\":\"set\",\"password\":\"set\"}"],
        ev($p, $m), 'P30', 'Kontoanlage: Hostname als Label, Kontoname und Passwort nur als Kennzeichen');
    $m = H2b::mark($p);
    $dy->changePassword($a, $nid, 'Neu-H2C-Passwort-2');
    T::eq(["dyndns_account.password#1 dyndns_account:$nid/$host z=1 update {\"password\":\"changed\"}"], ev($p, $m), 'P31', 'Passwortänderung als Kennzeichen');
    $m = H2b::mark($p);
    $r = $dy->updateAccount($a, 1, new DyndnsAccountInput('h2b_dynkonto2', '', 'dyn', 4));
    T::eq(['dyndns_account.update#1 dyndns_account:1/dyn z=4 update {"zone_id":{"old":1,"new":4},"username":"changed"}'], ev($p, $m), 'P32', 'Zonenwechsel und Umbenennung: Kontoname nur als Kennzeichen');
    $st = array_values(sets($p, $m));
    T::ok(count($st) === 1 && $st[0][1] === 2 && $st[0][2] === 1, 'P32b', 'Revisionen beider Zonen und Ereignis in derselben Änderungsmenge');
    $m = H2b::mark($p);
    $r = $dy->updateAccount($a, 1, new DyndnsAccountInput('h2b_dynkonto2', '', 'dyn', 4));
    T::ok(in_array('dyndns.update.unchanged', $codes($r), true) && sets($p, $m) === [], 'P33', 'unverändert: keine Änderungsmenge');
    $m = H2b::mark($p);
    $r = $dy->deleteAccount($a, 1);
    T::eq(['dyndns_account.delete#1 dyndns_account:1/dyn z=4 delete {"hostname":{"old":"dyn"},"zone_id":{"old":4}}'], ev($p, $m), 'P34', 'Kontolöschung');
    $st = array_values(sets($p, $m));
    T::ok(count($st) === 1 && $st[0][1] === 1 && $st[0][2] === 1, 'P34b', 'Revision (Records entfernt) und Ereignis in derselben Änderungsmenge');
    T::eq([], secretFindings($p, ['h2b_neukonto', 'h2b_dynkonto2', 'Neu-H2C-Passwort']), 'P35', 'keine DynDNS-Kontonamen, Passwörter oder Client-IPs');
    T::eq([], H2b::invariants($p), 'P36', 'Invarianten (DynDNS)');

    // ---- Benutzer
    $p = H2b::reset();
    $us = new UserService($p);
    $m = H2b::mark($p);
    $r = $us->createUser($a, new UserInput('h2b_neu', 'zoneadmin', [2, 1]), 'H2C-Benutzer-Passwort-1');
    $u1 = (int)$r->messages[0]->context['userId'];
    T::eq(["user.create#1 user:$u1/h2b_neu z=- create {\"username\":{\"new\":\"h2b_neu\"},\"role\":{\"new\":\"zoneadmin\"},\"active\":{\"new\":true},\"zones\":{\"added\":[1,2]},\"password\":\"set\"}"],
        ev($p, $m), 'P40', 'Benutzeranlage mit Zonen');
    $m = H2b::mark($p);
    $r = $us->createUser($a, new UserInput('h2b_alle', 'zoneadmin', [], true), 'H2C-Benutzer-Passwort-2');
    $u2 = (int)$r->messages[0]->context['userId'];
    $nz = (int)H2b::one($p, 'SELECT COUNT(*) FROM zones');
    T::eq(["user.create#1 user:$u2/h2b_alle z=- create {\"username\":{\"new\":\"h2b_alle\"},\"role\":{\"new\":\"zoneadmin\"},\"active\":{\"new\":true},\"zones\":{\"mode\":\"all\",\"count\":$nz},\"password\":\"set\"}"],
        ev($p, $m), 'P41', 'alle Zonen: mode all mit tatsächlicher Anzahl (Q4)');
    $m = H2b::mark($p);
    $us->updateUser($a, $u1, new UserInput('h2b_neu2', 'zoneadmin', [2, 4]));
    T::eq(["user.update#1 user:$u1/h2b_neu2 z=- update {\"username\":{\"old\":\"h2b_neu\",\"new\":\"h2b_neu2\"},\"zones\":{\"added\":[4],\"removed\":[1]}}"], ev($p, $m), 'P42', 'Umbenennung und Zonenänderung');
    $m = H2b::mark($p);
    $us->updateUser($a, $u1, new UserInput('h2b_neu2', 'admin', []));
    T::eq(["user.update#1 user:$u1/h2b_neu2 z=- update {\"role\":{\"old\":\"zoneadmin\",\"new\":\"admin\"},\"zones\":{\"removed\":[2,4]}}"], ev($p, $m), 'P43', 'Rollenwechsel entfernt Zuweisungen');
    $m = H2b::mark($p);
    $r = $us->updateUser($a, $u1, new UserInput('h2b_neu2', 'admin', []));
    T::ok(in_array('user.update.unchanged', $codes($r), true) && sets($p, $m) === [], 'P44', 'unverändert: keine Änderungsmenge');
    $m = H2b::mark($p);
    $us->changePassword($a, $u2, 'H2C-Benutzer-Passwort-3', null);
    $us->changePassword(H2b::zoneadmin(), 22, 'H2C-Benutzer-Passwort-4', 'H2C-Benutzer-Passwort-4');
    T::eq(["user.password#1 user:$u2/h2b_alle z=- update {\"password\":\"changed\"}", 'user.password#1 user:22/h2b_zoneadmin z=- update {"password":"changed"}'], ev($p, $m), 'P45', 'Passwortänderung (admin und selbst) nur als Kennzeichen');
    T::eq(['h2b_admin', 'h2b_zoneadmin'], array_column(H2b::all($p, 'SELECT actor_label FROM history_change_sets WHERE id > ? ORDER BY id', [$m]), 'actor_label'), 'P45b', 'Akteure');
    $m = H2b::mark($p);
    $us->setActive($a, $u2, false);
    $us->setActive($a, $u2, false);
    $us->setActive($a, $u2, true);
    T::eq(["user.disable#1 user:$u2/h2b_alle z=- update {\"active\":{\"old\":true,\"new\":false}}", "user.enable#1 user:$u2/h2b_alle z=- update {\"active\":{\"old\":false,\"new\":true}}"],
        ev($p, $m), 'P46', 'Deaktivierung und Reaktivierung; unchanged ohne Ereignis');
    $m = H2b::mark($p);
    $us->deleteUser($a, $u2);
    $zl = implode(',', array_map('intval', array_column(H2b::all($p, 'SELECT id FROM zones ORDER BY id'), 'id')));
    T::eq(["user.delete#1 user:$u2/h2b_alle z=- delete {\"username\":{\"old\":\"h2b_alle\"},\"role\":{\"old\":\"zoneadmin\"},\"active\":{\"old\":true},\"zones\":{\"removed\":[$zl]}}"], ev($p, $m), 'P47', 'Benutzerlöschung mit Zuweisungen');

    // Selbstumbenennung und Selbstlöschung: Akteursname zu Beginn des Vorgangs (E47)
    $self = new Actor($u1, 'h2b_neu2', 'admin', '192.0.2.99');
    $m = H2b::mark($p);
    $r = $us->updateUser($self, $u1, new UserInput('h2b_neu3', 'admin', []));
    T::eq([true, 'h2b_neu2'], [$r->ok, H2b::one($p, 'SELECT actor_label FROM history_change_sets WHERE id > ?', [$m])], 'P48', 'Selbstumbenennung: Label = Name vor der Änderung');
    $m = H2b::mark($p);
    $r = $us->deleteUser($a, 21);
    T::ok($r->ok && $codes($r) === ['user.delete.success'] && (int)H2b::one($p, 'SELECT COUNT(*) FROM users WHERE id = 21') === 0, 'P49', 'Selbstlöschung eines Administrators gelingt (weiterer aktiver Admin vorhanden)');
    T::eq([[21, 'h2b_admin']], array_map(fn ($c) => [(int)$c['actor_user_id'], $c['actor_label']], H2b::all($p, 'SELECT actor_user_id, actor_label FROM history_change_sets WHERE id > ?', [$m])), 'P49b', 'Akteursname vor der Löschung gesichert');
    T::eq(['user.delete#1 user:21/h2b_admin z=- delete {"username":{"old":"h2b_admin"},"role":{"old":"admin"},"active":{"old":true}}'], ev($p, $m), 'P49c', 'Ereignis der Selbstlöschung');
    T::eq([], secretFindings($p, ['H2C-Benutzer-Passwort']), 'P50', 'keine Passwörter oder Hashes (Benutzer)');
    T::eq([], H2b::invariants($p), 'P51', 'Invarianten (Benutzer)');
}

/* ============================================================================================ */
if ($run('A')) {
    T::section("A Berechtigung ($iso)");
    $p = H2b::reset();
    $counts = H2b::historyCounts($p);
    $sum = H2b::businessSum($p);
    $z = H2b::zoneadmin();
    $in = H2b::inactive();
    $rs = [
        (new UserService($p))->createUser($z, new UserInput('h2b_x', 'zoneadmin', [1]), 'H2C-Benutzer-Passwort-9'),
        (new UserService($p))->createUser($in, new UserInput('h2b_x', 'zoneadmin', [1]), 'H2C-Benutzer-Passwort-9'),
        (new UserService($p))->setActive($in, 22, false),
        (new UserService($p))->changePassword($z, 21, 'H2C-Benutzer-Passwort-9', null),
        H2b::servers($p)->createServer($in, new ServerInput('nsx.h2b.test', '192.0.2.8', '', '192.0.2.8', 'H2BTOKEN-x-0123456789abcdef0123456789ab', false, false)),
        H2b::servers($p)->deleteServer($z, 2),
        (new ServerService($p, new BindZoneBuildBackend($p), new TopologyLock($p), new H2cFakeControl('ok')))->reloadBind($in, 1),
        H2b::dyndns($p)->createAccount($in, new DyndnsAccountInput('h2b_x', 'H2C-Dyn-Passwort-99', 'x', 1)),
        H2b::dyndns($p)->changePassword($z, 1, 'H2C-Dyn-Passwort-99'),
        H2b::zones($p)->updateZone($z, 2, H2b::zoneInput($p, $a, 2, ['description' => 'fremd'])),
    ];
    T::ok(array_filter($rs, fn ($r) => $r->ok) === [], 'A01', 'alle Vorgänge abgelehnt (' . count($rs) . ')');
    T::eq($counts, H2b::historyCounts($p), 'A02', 'keine History- oder Audit-Zeilen durch abgelehnte Zugriffe');
    T::eq($sum, H2b::businessSum($p), 'A03', 'Fachtabellen unverändert');
}

/* ============================================================================================ */
if ($run('R')) {
    T::section("R Schwärzung ($iso)");
    $p = H2b::reset();
    $hash = password_hash('irgendwas', PASSWORD_BCRYPT);
    $m = H2b::mark($p);
    H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['description' => "Zugang: $hash"]));
    T::eq(['zone.update#1 zone:2/beta.h2b.test z=2 update {"description":{"old":null,"new":null,"new_redacted":true}}'], ev($p, $m), 'R01', 'Beschreibung mit Passwort-Hash geschwärzt');
    T::eq("Zugang: $hash", H2b::one($p, 'SELECT description FROM zones WHERE id = 2'), 'R01b', 'Fachdaten unverändert gespeichert (nur Audit geschwärzt)');
    $m = H2b::mark($p);
    H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['description' => str_repeat('Lang ', 200)]));
    $j = json_decode((string)H2b::one($p, 'SELECT changes FROM audit_events WHERE change_set_id > ?', [$m]), true);
    T::ok($j['description']['old'] === null && $j['description']['old_redacted'] === true && mb_strlen($j['description']['new']) === 500 && $j['description']['new_truncated'] === true,
        'R02', 'Vorher-Wert geschwärzt, Nachher-Wert auf 500 Zeichen gekürzt');
    $m = H2b::mark($p);
    H2b::zones($p)->updateZone($a, 2, H2b::zoneInput($p, $a, 2, ['description' => 'API-Key H2BTOKEN-abcdef0123456789abcdef0123456789']));
    T::eq([], secretFindings($p), 'R03', 'Token im Freitext nicht im Audit; Suchtest über alle Ereignisse und Änderungsmengen');
}

/* ============================================================================================ */
if ($run('F')) {
    T::section("F Fehlerinjektion ($iso)");
    $cases = [
        // [Fall, Tabelle, Bedingung, Vorgang, erwarteter Code]
        ['F01', 'audit_events', '1', 'zcreate', 'zone.create.failed'],
        ['F02', 'audit_events', '1', 'zdesc', 'zone.update.failed'],
        ['F03', 'audit_events', '1', 'zmix', 'zone.update.failed'],
        ['F04', 'audit_events', '1', 'zdel', 'zone.delete.failed'],
        ['F05', 'audit_events', '1', 'screate', 'server.create.failed'],
        ['F06', 'audit_events', '1', 'sapi', 'server.update.failed'],
        ['F07', 'audit_events', '1', 'srename', 'server.update.failed'],
        ['F08', 'audit_events', 'NEW.seq = 2', 'slocal', 'server.update.failed'],
        ['F09', 'audit_events', '1', 'sdel', 'server.delete.failed'],
        ['F10', 'audit_events', '1', 'dcreate', 'dyndns.create.failed'],
        ['F11', 'audit_events', '1', 'dpw', 'dyndns.password.failed'],
        ['F12', 'audit_events', '1', 'dupd', 'dyndns.update.failed'],
        ['F13', 'audit_events', '1', 'ddel', 'dyndns.delete.failed'],
        ['F14', 'audit_events', '1', 'ucreate', 'user.create.failed'],
        ['F15', 'audit_events', '1', 'uupd', 'user.update.failed'],
        ['F16', 'audit_events', '1', 'udel', 'user.delete.failed'],
        ['F17', 'audit_events', '1', 'upw', 'user.password.failed'],
        ['F18', 'audit_events', '1', 'udis', 'user.disable.failed'],
        ['F19', 'history_change_sets', "NEW.operation = 'user.create'", 'ucreate', 'user.create.failed'],
        ['F20', 'history_change_sets', "NEW.operation = 'zone.update'", 'zdesc', 'zone.update.failed'],
    ];
    foreach ($cases as [$id, $table, $cond, $opn, $code]) {
        $p = H2b::reset();
        $p->exec("INSERT INTO servers (id, name, dns_ip4, api_ip, api_token, is_local, active) VALUES (5, 'ns5.h2b.test', '192.0.2.5', '192.0.2.5', 'H2BTOKEN-fuenf-0123456789abcdef012345678', 0, 0)");
        $p->exec("CREATE TRIGGER h2c_inject BEFORE INSERT ON $table FOR EACH ROW IF $cond THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'H2C-Injektion'; END IF");
        $counts = H2b::historyCounts($p);
        $sum = H2b::businessSum($p);
        $x = H2b::pdo();
        $s2 = H2b::all($x, 'SELECT * FROM servers WHERE id = 2')[0];
        $r = match ($opn) {
            'zcreate' => H2b::zones($x)->createZone($a, H2b::newZone('inj.h2b.test')),
            'zdesc' => H2b::zones($x)->updateZone($a, 2, H2b::zoneInput($x, $a, 2, ['description' => 'inj'])),
            'zmix' => H2b::zones($x)->updateZone($a, 2, H2b::zoneInput($x, $a, 2, ['description' => 'inj', 'ttl' => '7000'])),
            'zdel' => H2b::zones($x)->deleteZone($a, 6),
            'screate' => H2b::servers($x)->createServer($a, new ServerInput('nsi.h2b.test', '192.0.2.9', '', '192.0.2.9', 'H2BTOKEN-inj-0123456789abcdef0123456789a', false, false)),
            'sapi' => H2b::servers($x)->updateServer($a, 5, new ServerInput('ns5.h2b.test', '192.0.2.5', '', '192.0.2.55', '', false, false)),
            'srename' => H2b::servers($x)->updateServer($a, 2, new ServerInput('ns2i.h2b.test', $s2['dns_ip4'], '', $s2['api_ip'], '', false, true)),
            'slocal' => H2b::servers($x)->updateServer($a, 2, new ServerInput('ns2.h2b.test', $s2['dns_ip4'], '', $s2['api_ip'], '', true, true)),
            'sdel' => H2b::servers($x)->deleteServer($a, 5),
            'dcreate' => H2b::dyndns($x)->createAccount($a, new DyndnsAccountInput('h2b_inj', 'H2C-Dyn-Passwort-99', 'inj', 1)),
            'dpw' => H2b::dyndns($x)->changePassword($a, 1, 'H2C-Dyn-Passwort-99'),
            'dupd' => H2b::dyndns($x)->updateAccount($a, 1, new DyndnsAccountInput('h2b_dynkonto', '', 'dyn', 4)),
            'ddel' => H2b::dyndns($x)->deleteAccount($a, 1),
            'ucreate' => (new UserService($x))->createUser($a, new UserInput('h2b_inj', 'zoneadmin', [1]), 'H2C-Benutzer-Passwort-9'),
            'uupd' => (new UserService($x))->updateUser($a, 22, new UserInput('h2b_zoneadmin', 'zoneadmin', [1, 2])),
            'udel' => (new UserService($x))->deleteUser($a, 22),
            'upw' => (new UserService($x))->changePassword($a, 22, 'H2C-Benutzer-Passwort-9', null),
            'udis' => (new UserService($x))->setActive($a, 22, false),
        };
        $got = $codes($r);
        T::ok(!$r->ok && in_array($code, $got, true), $id, "Injektion $table ($opn): bisheriger Fehlercode $code" . (in_array($code, $got, true) ? '' : ' – erhalten ' . implode(',', $got)));
        T::ok(H2b::historyCounts($p) === $counts && H2b::businessSum($p) === $sum && !H2b::hasOpenTrx($x) && (int)H2b::one($x, 'SELECT @@in_transaction') === 0,
            "{$id}b", 'vollständiger Rollback: Fach-, History- und Audit-Tabellen unverändert, keine offene Transaktion');
        $p->exec('DROP TRIGGER h2c_inject');
    }

    // F30 Reload: Fehler des Nachtrags ändert das Ergebnis nicht (Q2), keine Rückstände
    $p = H2b::reset();
    $p->exec("CREATE TRIGGER h2c_inject BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'H2C-Injektion'");
    $counts = H2b::historyCounts($p);
    $fake = new H2cFakeControl('ok');
    $r = (new ServerService($p, new BindZoneBuildBackend($p), new TopologyLock($p), $fake))->reloadBind($a, 1);
    $ctx = $r->messages[0]->context;
    T::ok($r->ok && $codes($r) === ['server.reload.success'] && $fake->calls === 1 && ($ctx['audit'] ?? null) === 'failed' && str_contains((string)$ctx['auditDetail'], 'H2C-Injektion'),
        'F30', 'Reload ausgeführt, Ergebnis success unverändert, Kennzeichen audit = failed für den Adapter');
    T::ok(H2b::historyCounts($p) === $counts && !H2b::hasOpenTrx($p), 'F30b', 'Nachtrag vollständig zurückgerollt, keine offene Transaktion');
    $p->exec('DROP TRIGGER h2c_inject');
}

/* ============================================================================================ */
if ($run('G')) {
    T::section("G Bindung und Reihenfolge ($iso)");
    $p = H2b::reset();
    $ctx = HistoryContext::forUser($a, 'user.update');
    T::throws(LogicException::class, fn () => AuditRecorder::open($p, $ctx), 'G01', 'open() ohne Transaktion abgelehnt');

    $counts = H2b::historyCounts($p);
    $p->beginTransaction();
    $p->query('SELECT id FROM users WHERE id = 21 LOCK IN SHARE MODE')->fetchAll();
    $au = AuditRecorder::open($p, $ctx);
    $au->record('user', 22, 'h2b_zoneadmin', null, 'update', ['role' => 'zoneadmin'], ['role' => 'admin']);
    $p->commit();                   // ohne finish()
    $p->beginTransaction();
    T::throws(LogicException::class, fn () => $au->finish(), 'G02', 'Erfassung einer beendeten Transaktion schreibt nicht in die nächste (Savepoint)');
    $p->rollBack();
    T::eq($counts, H2b::historyCounts($p), 'G02b', 'nichts geschrieben');

    $p->beginTransaction();
    $p->query('SELECT id FROM zones WHERE id = 2 FOR UPDATE')->fetchAll();
    $h = ZoneHistoryRecorder::open($p, $ctx);
    $h->beginZones([2]);
    $au = AuditRecorder::open($p, $ctx, $h);
    $au->record('zone', 2, 'beta.h2b.test', 2, 'update', ['description' => null], ['description' => 'x']);
    T::throws(LogicException::class, fn () => $au->finish(), 'G03', 'finish() vor ZoneHistoryRecorder::finish() abgelehnt (R-A6)');
    $p->rollBack();
    $h->close();
    $au->close();

    $p->beginTransaction();
    $h = ZoneHistoryRecorder::open($p, $ctx);
    T::throws(LogicException::class, fn () => AuditRecorder::open($p, HistoryContext::forUser($a, 'user.update'), $h), 'G04', 'abweichender Kontext (andere Korrelation) abgelehnt');
    $p->rollBack();
    $h->close();

    $p->beginTransaction();
    $au = AuditRecorder::open($p, $ctx);
    $au->finish();
    T::throws(LogicException::class, fn () => $au->record('user', 22, 'h2b_zoneadmin', null, 'update', ['role' => 'zoneadmin'], ['role' => 'admin']), 'G05', 'record() nach finish() abgelehnt');
    $p->rollBack();
    $p->beginTransaction();
    $au = AuditRecorder::open($p, $ctx);
    $au->close();
    T::throws(LogicException::class, fn () => $au->finish(), 'G06', 'finish() nach close() abgelehnt');
    $p->rollBack();
    $p->beginTransaction();
    T::throws(LogicException::class, fn () => AuditRecorder::recordStandalone($p, $ctx, 'server', 1, 'ns1.h2b.test', null, 'reload', ['result' => 'ok']), 'G07', 'recordStandalone() in offener Transaktion abgelehnt');
    $p->rollBack();
    $p->beginTransaction();
    $au = AuditRecorder::open($p, $ctx);
    T::throws(LogicException::class, fn () => $au->record('user', 22, 'h2b_zoneadmin', 4, 'update', ['role' => 'zoneadmin'], ['role' => 'admin']), 'G08', 'Benutzer mit Zonenbezug abgelehnt (vor der Datenbank)');
    T::throws(LogicException::class, fn () => $au->record('zone', 2, 'beta.h2b.test', 3, 'update', ['description' => null], ['description' => 'x']), 'G09', 'Zone mit fremder zone_id abgelehnt');
    $p->rollBack();
    $au->close();
    T::throws(RuntimeException::class, function () use ($p) {
        $p->beginTransaction();
        try { AuditRecorder::open($p, HistoryContext::forUser(new Actor(999, 'weg', 'admin', '192.0.2.99'), 'user.update')); } finally { $p->rollBack(); }
    }, 'G10', 'fehlender Akteur in der Fachtransaktion ⇒ Fehler (Rollback)');
    T::eq($counts, H2b::historyCounts($p), 'G11', 'keine Rückstände');
}

/* ============================================================================================ */
if ($run('N')) {
    T::section("N Nebenläufigkeit ($iso)");

    // N01 E50: Name parallel (alter Code, ohne Historie) geändert, während eine api_ip-Änderung ungesperrt vorbereitet ist
    $p = H2b::reset();
    $s = H2b::all($p, 'SELECT * FROM servers WHERE id = 2')[0];
    $w = H2b::spawn('hold-server', '2', '2', "$W/n01", 'name', 'ns2fremd.h2b.test');
    H2b::waitFile("$W/n01");
    $m = H2b::mark($p);
    $t0 = microtime(true);
    $r = H2b::servers($p)->updateServer($a, 2, new ServerInput('ns2.h2b.test', $s['dns_ip4'], '', '192.0.2.202', '', false, true));
    $waited = microtime(true) - $t0;
    H2b::join($w);
    T::ok(!$r->ok && in_array('server.update.topology_locked', $codes($r), true) && $waited > 1.5, 'N01', 'erst unter Sperre DNS-relevant ⇒ kontrollierter Abbruch topology_locked nach Lock-Wait (' . round($waited, 2) . ' s)');
    T::eq(['ns2fremd.h2b.test', '192.0.2.2'], array_map('strval', array_values(H2b::all($p, 'SELECT name, api_ip FROM servers WHERE id = 2')[0])), 'N01b', 'keine Änderung geschrieben (kein Zurücksetzen des Namens ohne Rebuild und Revision)');
    T::eq([], sets($p, $m), 'N01c', 'keine Änderungsmenge');

    // N02 E50: "active" parallel geändert, ohne Topologie-Lock ⇒ Abbruch
    $p = H2b::reset();
    $s = H2b::all($p, 'SELECT * FROM servers WHERE id = 2')[0];
    $w = H2b::spawn('hold-server', '2', '2', "$W/n02", 'active', '0');
    H2b::waitFile("$W/n02");
    $m = H2b::mark($p);
    $t0 = microtime(true);
    $r = H2b::servers($p)->updateServer($a, 2, new ServerInput('ns2.h2b.test', $s['dns_ip4'], '', '192.0.2.203', '', false, true));
    $waited = microtime(true) - $t0;
    H2b::join($w);
    T::ok(!$r->ok && in_array('server.update.topology_locked', $codes($r), true) && $waited > 1.5 && sets($p, $m) === []
        && (int)H2b::one($p, 'SELECT active FROM servers WHERE id = 2') === 0, 'N02', 'active erst unter Sperre geändert ⇒ Abbruch ohne Schreibzugriff (' . round($waited, 2) . ' s)');

    // N03 Vorher-Werte aus dem gesperrten Stand (api_ip parallel geändert, nicht DNS-relevant ⇒ Änderung zulässig)
    $p = H2b::reset();
    $s = H2b::all($p, 'SELECT * FROM servers WHERE id = 2')[0];
    $w = H2b::spawn('hold-server', '2', '2', "$W/n03", 'api_ip', '192.0.2.123');
    H2b::waitFile("$W/n03");
    $m = H2b::mark($p);
    $r = H2b::servers($p)->updateServer($a, 2, new ServerInput('ns2.h2b.test', $s['dns_ip4'], '', '192.0.2.124', '', false, true));
    H2b::join($w);
    T::eq(['server.update#1 server:2/ns2.h2b.test z=- update {"api_ip":{"old":"192.0.2.123","new":"192.0.2.124"}}'], ev($p, $m), 'N03', 'Vorher-Wert aus dem gesperrten Stand, nicht aus dem ungesperrten Vorab-Lesen (R-A5)');

    // N04 lokaler Server: bisheriger lokaler Server gesperrt ⇒ Lock-Wait, danach Ereignis aus gesperrtem Stand
    $p = H2b::reset();
    $w = H2b::spawn('hold-server', '1', '2', "$W/n04", 'none');
    H2b::waitFile("$W/n04");
    $m = H2b::mark($p);
    $t0 = microtime(true);
    $s = H2b::all($p, 'SELECT * FROM servers WHERE id = 2')[0];
    $r = H2b::servers($p)->updateServer($a, 2, new ServerInput('ns2.h2b.test', $s['dns_ip4'], '', $s['api_ip'], '', true, true));
    $waited = microtime(true) - $t0;
    H2b::join($w);
    T::ok($r->ok && $waited > 1.5, 'N04', 'Wechsel des lokalen Servers wartet auf den bisherigen (' . round($waited, 2) . ' s)');
    T::eq(['server.update#1 server:1/ns1.h2b.test z=- update {"is_local":{"old":true,"new":false}}', 'server.update#2 server:2/ns2.h2b.test z=- update {"is_local":{"old":false,"new":true}}'],
        ev($p, $m), 'N04b', 'beide Ereignisse in einer Änderungsmenge');

    // N05 konkurrierende Wechsel des lokalen Servers: nie Teilzustände, Ereigniskette lückenlos
    $p = H2b::reset();
    $p->exec("INSERT INTO servers (id, name, dns_ip4, api_ip, api_token, is_local, active) VALUES (3, 'ns3.h2b.test', '192.0.2.3', '192.0.2.3', 'H2BTOKEN-drei-0123456789abcdef0123456789', 0, 1)");
    $m = H2b::mark($p);
    $results = [];
    for ($round = 0; $round < 6; $round++) {
        $w1 = H2b::spawn('server-local', '2');
        $w2 = H2b::spawn('server-local', '3');
        $results[] = H2b::join($w1);
        $results[] = H2b::join($w2);
    }
    $okN = count(array_filter($results, fn ($x) => $x['ok'] ?? false));
    $bad = array_filter($results, fn ($x) => !($x['ok'] ?? false) && !in_array('server.update.failed', $x['codes'] ?? [], true));
    T::ok($okN >= 6 && $bad === [], 'N05', "konkurrierende Wechsel: $okN erfolgreich, übrige nur als failed (Deadlock/Timeout, vollständiger Rollback)");
    T::eq(1, (int)H2b::one($p, 'SELECT COUNT(*) FROM servers WHERE is_local = 1'), 'N05b', 'genau ein lokaler Server');
    // Kette: In jeder erfolgreichen Änderungsmenge wird der zuvor lokale Server abgelöst (aus gesperrtem Stand)
    $local = 1;
    $chainOk = true;
    foreach (H2b::all($p, 'SELECT change_set_id, entity_id, changes FROM audit_events WHERE change_set_id > ? ORDER BY change_set_id, seq', [$m]) as $e) {
        $ch = json_decode($e['changes'], true)['is_local'] ?? null;
        if ($ch === ['old' => true, 'new' => false]) {
            $chainOk = $chainOk && (int)$e['entity_id'] === $local;
        } elseif ($ch === ['old' => false, 'new' => true]) {
            $local = (int)$e['entity_id'];
        }
    }
    T::ok($chainOk && $local === (int)H2b::one($p, 'SELECT id FROM servers WHERE is_local = 1'), 'N05c', 'Ereigniskette lückenlos und konsistent mit dem Endzustand');
    T::eq([], H2b::invariants($p), 'N05d', 'Invarianten');

    // N06 Zonenlöschung wartet auf eine parallele DynDNS-Passwortänderung (zones → dyndns_accounts, E53): kein 1020
    $p = H2b::reset();
    $w = H2b::spawn('hold-account', '1', '2', "$W/n06");
    H2b::waitFile("$W/n06");
    $m = H2b::mark($p);
    $t0 = microtime(true);
    $r = H2b::zones($p)->deleteZone($a, 1);
    $waited = microtime(true) - $t0;
    H2b::join($w);
    $detail = implode(' ', array_map(fn ($x) => (string)($x->context['detail'] ?? ''), $r->messages));
    T::ok($r->ok && $waited > 1.5, 'N06', 'Zonenlöschung nach Lock-Wait (' . round($waited, 2) . ' s) erfolgreich' . ($r->ok ? '' : " – $detail"));
    T::ok(str_contains(implode('|', ev($p, $m)), '"dyndns_accounts_removed":1,"user_assignments_removed":1'), 'N06b', 'Kaskadenzähler aus gesperrtem Stand');
    T::eq([], H2b::invariants($p), 'N99', 'Invarianten');
}

/* ============================================================================================ */
if ($run('X')) {
    T::section("X Stresslauf ($iso)");
    $p = H2b::reset();
    $m = H2b::mark($p);
    $workers = [];
    for ($i = 1; $i <= 4; $i++) {
        $workers[] = H2b::spawn('audit-stress', '6', (string)$i);
    }
    $stats = array_map(fn ($w) => H2b::join($w), $workers);
    $okOps = array_sum(array_column($stats, 'ok'));
    $codesSeen = [];
    foreach ($stats as $s) { foreach ($s['codes'] ?? [] as $c => $n) { $codesSeen[$c] = ($codesSeen[$c] ?? 0) + $n; } }
    ksort($codesSeen);
    echo '       Stress: ' . $okOps . ' erfolgreich, Fehlercodes ' . json_encode($codesSeen) . "\n";
    T::ok($okOps > 20, 'X01', 'Stresslauf mit vier Prozessen ausgeführt');
    T::eq([], H2b::invariants($p), 'X02', 'Invarianten (inkl. jede Änderungsmenge mit Inhalt, seq lückenlos)');
    $multi = (int)H2b::one($p, 'SELECT COUNT(*) FROM (SELECT correlation_id FROM history_change_sets WHERE id > ? AND actor_type = ? GROUP BY correlation_id HAVING COUNT(*) > 1) g', [$m, 'user']);
    T::eq(0, $multi, 'X03', 'je Vorgang (Korrelation) höchstens eine Änderungsmenge des Akteurs (R-A6)');
    $unexpected = array_diff(array_keys($codesSeen), ['zone.update.failed', 'zone.update.conflict', 'server.update.failed', 'server.update.topology_locked', 'record.create.db_save_failed',
        'dyndns.password.failed', 'user.password.failed']);
    T::eq([], array_values($unexpected), 'X04', 'nur erwartete Fehlerarten (Lock-Konflikte)');
    T::eq([], secretFindings($p, ['Stress-Passwort', 'Stress-Benutzer']), 'X05', 'keine Geheimnisse nach dem Stresslauf');
}

T::finish("audit $iso");
