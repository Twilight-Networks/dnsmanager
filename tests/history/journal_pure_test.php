<?php
/**
 * Datei: tests/history/journal_pure_test.php
 * Zweck: Reine Tests des Publish-Journals (AP1/H2d) ohne Datenbank und Netzwerk: Hash-Vertrag rh1 mit eingefrorenem
 *        Vektor, Einordnung der Agentenantworten (E63, Q3), Bereinigung der Fehlertexte, Lese-Regeln J5/E70, Name der
 *        Laufsperre (E73).
 *
 * Aufruf: php journal_pure_test.php <App-Verzeichnis> [--dump-file|--dump-masked]
 *   --dump-file / --dump-masked geben die Bytes des eingefrorenen Vektors aus (unabhängige Prüfung mit sha256sum in run.sh).
 */

require_once __DIR__ . '/lib.php';

$app = rtrim($argv[1] ?? '', '/');
if (!is_file("$app/web/inc/app/publish/PublishHashContract.php")) {
    fwrite(STDERR, "Aufruf: php journal_pure_test.php <App-Verzeichnis>\n");
    exit(2);
}
require_once "$app/web/inc/deploy/bind_file_generator.php";
foreach (['PublishHashContract', 'PublishTargetClassifier', 'PublishLedger', 'PublishJournalReader'] as $c) {
    require_once "$app/web/inc/app/publish/$c.php";
}

/** Eingefrorener Vektor: Zone mit RRset unterschiedlicher TTL, Record ohne TTL, Systemrecord */
const VECTOR_SERIAL = 2026111801;
const VECTOR_FILE_HASH = '92a7688298c04ef8a29add288da951f416264e364418a73d0c68a6fe57e10f7a';
const VECTOR_RENDER_HASH = '845b71437ecc3833a2328a1fa926efd8c7f50231d53916d49cdc849c62dd58e7';
$zone = ['name' => 'vektor.h2d.test', 'ttl' => '3600', 'soa_ns' => 'ns1.h2d.test.', 'soa_mail' => 'hostmaster.h2d.test.',
    'soa_refresh' => '3600', 'soa_retry' => '1800', 'soa_expire' => '1209600', 'soa_minimum' => '86400'];
$records = [
    ['name' => '@', 'type' => 'NS', 'content' => 'ns1.h2d.test.', 'ttl' => '3600', 'server_id' => '1'],
    ['name' => 'www', 'type' => 'A', 'content' => '192.0.2.10', 'ttl' => '600', 'server_id' => null],
    ['name' => 'www', 'type' => 'A', 'content' => '192.0.2.11', 'ttl' => '300', 'server_id' => null],
    ['name' => 'txt', 'type' => 'txt', 'content' => '"ä – ✓"', 'ttl' => null, 'server_id' => null],
];
$file = zone_file_render($zone, $records, (string)VECTOR_SERIAL);
$masked = zone_file_render($zone, $records, PublishHashContract::SERIAL_PLACEHOLDER);
if (in_array('--dump-file', $argv, true)) {
    echo $file;
    exit(0);
}
if (in_array('--dump-masked', $argv, true)) {
    echo $masked;
    exit(0);
}

T::section('H Hash-Vertrag rh1 und file_hash (E60)');
T::eq(VECTOR_FILE_HASH, hash('sha256', $file), 'H01', 'file_hash des eingefrorenen Vektors');
T::eq(VECTOR_RENDER_HASH, PublishHashContract::renderHashFromFile($file, VECTOR_SERIAL), 'H02', 'render_hash des eingefrorenen Vektors (maskierte Datei)');
T::eq($masked, PublishHashContract::maskSerial($file, VECTOR_SERIAL), 'H03', 'Maskierung der Datei = Rendering mit Platzhalter');
foreach ([0, 1, 2147483647, 2147483648, 4294967295] as $s) {
    $ok = PublishHashContract::maskSerial(zone_file_render($zone, $records, (string)$s), $s) === $masked;
    T::ok($ok, 'H04', "Maskierung unabhängig von der Serial ($s, 32 Bit ohne Vorzeichen)");
}
T::ok(hash('sha256', zone_file_render($zone, $records, '2026111802')) !== hash('sha256', $file), 'H05', 'andere Serial ⇒ anderer file_hash, gleicher render_hash');
T::throws(InvalidArgumentException::class, fn () => PublishHashContract::maskSerial($file, VECTOR_SERIAL + 1), 'H06', 'falsche Serial ⇒ nicht maskierbar');
T::throws(InvalidArgumentException::class, fn () => PublishHashContract::maskSerial($file . $file, VECTOR_SERIAL), 'H07', 'Serial-Zeile doppelt ⇒ nicht maskierbar');
T::throws(InvalidArgumentException::class, fn () => PublishHashContract::maskSerial($file, -1), 'H08', 'negative Serial abgewiesen');
T::throws(InvalidArgumentException::class, fn () => PublishHashContract::maskSerial($file, 4294967296), 'H09', 'Serial ≥ 2^32 abgewiesen');
$other = $records;
$other[1]['content'] = '192.0.2.12';
T::ok(PublishHashContract::renderHashFromFile(zone_file_render($zone, $other, (string)VECTOR_SERIAL), VECTOR_SERIAL) !== VECTOR_RENDER_HASH, 'H10', 'anderer Inhalt ⇒ anderer render_hash');
T::ok(str_contains($file, "www\t300\tIN\tA\t192.0.2.10\n") && str_contains($file, "txt\t3600\tIN\tTXT\t\"ä – ✓\"\n"), 'H11', 'Rendering wie Generator (RRset-TTL vereinheitlicht, Zonen-TTL als Rückfall, Typ in Großbuchstaben)');
T::ok(PublishHashContract::isHash(VECTOR_RENDER_HASH) && !PublishHashContract::isHash(strtoupper(VECTOR_FILE_HASH)), 'H12', 'Hashformat: 64 Hex-Zeichen klein');

T::section('C Einordnung der Agentenantworten (E63, Q3)');
$c = static fn (string $k, array $r): string => implode('|', array_map(fn ($v) => $v === null ? '-' : (string)$v,
    array_slice(PublishTargetClassifier::classify($k, $r), 0, 3)));
$ok200 = ['httpCode' => 200, 'body' => '{"status":"success","message":"x","rndc":"y"}', 'curlError' => '', 'curlErrno' => 0];
$cases = [
    'C01' => ['zone_sync', $ok200, 'ok|200|-', 'HTTP 200 + success ⇒ ok'],
    'C02' => ['zone_sync', ['httpCode' => 422, 'body' => '{"status":"error","message":"Zonenprüfung fehlgeschlagen"}'], 'rejected|422|agent_error', 'Agent-Ablehnung (422) ⇒ rejected'],
    'C03' => ['zone_sync', ['httpCode' => 500, 'body' => '{"status":"error","message":"Fehler beim Verschieben"}'], 'rejected|500|agent_error', 'Agent-Fehler vor der Übernahme ⇒ rejected'],
    'C04' => ['zone_sync', ['httpCode' => 403, 'body' => '{"status":"error","message":"Zugriff verweigert"}'], 'rejected|403|agent_error', 'Agent-Zugriffsverweigerung ⇒ rejected'],
    'C05' => ['zone_sync', ['httpCode' => 200, 'body' => '<html>ok</html>'], 'unknown|200|invalid_response', 'HTTP 200 ohne Agent-JSON ⇒ unknown (operativ weiterhin Erfolg)'],
    'C06' => ['zone_sync', ['httpCode' => 200, 'body' => '{"status":"error"}'], 'unknown|200|invalid_response', 'HTTP 200 mit Fehlerstatus ⇒ unknown'],
    'C07' => ['zone_sync', ['httpCode' => 502, 'body' => '<html>Bad Gateway</html>'], 'unknown|502|invalid_response', 'Fremdantwort ⇒ unknown'],
    'C08' => ['zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => 'Operation timed out', 'curlErrno' => 28], 'unknown|-|timeout', 'Zeitüberschreitung ⇒ unknown (auch beim Verbindungsaufbau, Q3)'],
    'C09' => ['zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => 'Failed to connect', 'curlErrno' => 7], 'failed|-|not_delivered', 'Verbindung abgelehnt ⇒ failed'],
    'C10' => ['zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => 'Could not resolve', 'curlErrno' => 6], 'failed|-|not_delivered', 'Namensauflösung ⇒ failed'],
    'C11' => ['zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => 'SSL certificate problem', 'curlErrno' => 60], 'failed|-|not_delivered', 'Zertifikatsprüfung ⇒ failed (vor dem Request)'],
    'C12' => ['zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => 'Empty reply from server', 'curlErrno' => 52], 'unknown|-|transport_unknown', 'leere Antwort ⇒ unknown'],
    'C13' => ['zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => 'Recv failure', 'curlErrno' => 56], 'unknown|-|transport_unknown', 'Empfangsfehler ⇒ unknown'],
    'C14' => ['zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => 'Agent-TLS-Konfiguration ungültig', 'curlErrno' => 0, 'tlsConfigError' => true], 'failed|-|tls_config', 'TLS-Konfigurationsfehler ⇒ failed'],
    'C15' => ['conf_sync', ['httpCode' => 500, 'body' => '{"status":"error","message":"x – Rollback fehlgeschlagen","rollback":"failed"}'], 'unknown|500|rollback_failed', 'conf_sync rollback=failed ⇒ unknown'],
    'C16' => ['conf_sync', ['httpCode' => 422, 'body' => '{"status":"error","message":"named-checkconf","rollback":"ok"}'], 'rejected|422|agent_error', 'conf_sync zurückgerollt ⇒ rejected'],
    'C17' => ['zone_cleanup', ['httpCode' => 500, 'body' => '{"status":"error","errors":["a"],"removed_conf":["x.conf"],"removed_zone_files":["db.x"]}'], 'partial|500|cleanup_incomplete', 'Cleanup mit entfernten Dateien ⇒ partial'],
    'C18' => ['zone_cleanup', ['httpCode' => 422, 'body' => '{"status":"error","removed_conf":[],"removed_zone_files":[],"rollback":"ok"}'], 'rejected|422|agent_error', 'Cleanup-Preflight zurückgerollt ⇒ rejected'],
    'C19' => ['zone_cleanup', ['httpCode' => 500, 'body' => '{"status":"error","rollback":"failed"}'], 'unknown|500|rollback_failed', 'Cleanup rollback=failed ⇒ unknown'],
    'C20' => ['zone_cleanup', ['httpCode' => 200, 'body' => '{"status":"success"}'], 'unknown|200|invalid_response', 'Cleanup-Erfolg ohne Dateilisten ⇒ unknown (keine erfundenen Zähler)'],
];
foreach ($cases as $id => [$k, $r, $want, $msg]) {
    T::eq($want, $c($k, $r), $id, $msg);
}
$cl = PublishTargetClassifier::classify('zone_cleanup', ['httpCode' => 200, 'body' => '{"status":"success","removed_conf":["a.conf","b.conf"],"removed_zone_files":["db.a"]}']);
T::eq([2, 1], [$cl['removedConf'], $cl['removedZoneFiles']], 'C21', 'Cleanup-Zähler aus der Antwort (nur Zähler, Q5)');
T::throws(InvalidArgumentException::class, fn () => PublishTargetClassifier::classify('system_control', $ok200), 'C22', 'unbekannter Endpunkt abgewiesen');
T::eq('failed|-|no_file', implode('|', array_map(fn ($v) => $v ?? '-', array_slice(PublishTargetClassifier::localFailure('no_file', 'x'), 0, 3))), 'C23', 'lokaler Fehler vor dem Senden ⇒ failed');
T::throws(InvalidArgumentException::class, fn () => PublishTargetClassifier::localFailure('Ungültig!', 'x'), 'C24', 'ungültiger Fehlercode abgewiesen');

T::section('S Bereinigung der Fehlertexte (Abschnitt 16)');
T::eq('a b c', PublishTargetClassifier::sanitize("a\r\nb\x00\tc"), 'S01', 'Steuerzeichen entfernt, Leerraum zusammengefasst');
T::eq('Token […] Ende', PublishTargetClassifier::sanitize('Token H2BTOKEN-eins-0123456789abcdef0123456789 Ende'), 'S02', 'tokenähnliche Zeichenfolge geschwärzt');
T::eq('Hash […]', PublishTargetClassifier::sanitize('Hash ' . str_repeat('ab', 32)), 'S03', 'Hash-Werte geschwärzt');
$long = PublishTargetClassifier::sanitize(str_repeat('ä', 800));
T::ok(mb_strlen((string)$long) === 500 && str_ends_with((string)$long, '…'), 'S04', 'höchstens 500 Zeichen (UTF-8-sicher gekürzt)');
T::ok(mb_check_encoding((string)PublishTargetClassifier::sanitize("ok \xff\xfe kaputt"), 'UTF-8'), 'S05', 'ungültiges UTF-8 ersetzt');
T::eq(null, PublishTargetClassifier::sanitize(" \n\t "), 'S06', 'leerer Text ⇒ null');
$cls = PublishTargetClassifier::classify('zone_sync', ['httpCode' => 0, 'body' => false, 'curlError' => "Fehler\nmit Token " . str_repeat('x', 40), 'curlErrno' => 56]);
T::ok(!str_contains((string)$cls['errorDetail'], str_repeat('x', 40)) && !str_contains((string)$cls['errorDetail'], "\n"), 'S07', 'Einordnung verwendet die Bereinigung');

T::section('R Lese-Regeln (J5/E68/E73, E70)');
T::ok(PublishJournalReader::isAlive('running', 42, '42', 42), 'R01', 'lebendig: running, beide Sperren auf der registrierten Verbindung');
T::ok(!PublishJournalReader::isAlive('running', 42, null, 42), 'R02', 'Laufsperre frei (Neustart, Verbindungsende, wiederverwendete ID) ⇒ nicht lebendig');
T::ok(!PublishJournalReader::isAlive('running', 42, 43, 43), 'R03', 'Sperren auf anderer Verbindung ⇒ nicht lebendig');
T::ok(!PublishJournalReader::isAlive('running', 42, 42, null), 'R04', 'Publish-Lock frei ⇒ nicht lebendig');
T::ok(!PublishJournalReader::isAlive('finished', 42, 42, 42), 'R05', 'abgeschlossener Lauf ⇒ nicht lebendig');
T::eq(['sending', 'unknown', 'unknown', 'ok', 'abandoned', 'rejected'], [
    PublishJournalReader::effectiveState('sending', true), PublishJournalReader::effectiveState('sending', false),
    PublishJournalReader::effectiveState(null, false), PublishJournalReader::effectiveState('ok', false),
    PublishJournalReader::effectiveState('abandoned', true), PublishJournalReader::effectiveState('rejected', false),
], 'R06', 'wirksamer Zustand: offen ⇒ sending nur bei lebendigem Lauf, sonst unknown; Endzustände unverändert');
T::ok(PublishJournalReader::invalidatedByReactivation(10, [5, 11]) && !PublishJournalReader::invalidatedByReactivation(10, [5, 10]) && !PublishJournalReader::invalidatedByReactivation(10, []), 'R07', 'Reaktivierung nach dem Wasserstand ⇒ Bestätigung veraltet');
T::ok(strlen(PublishLedger::runLockName(str_repeat('d', 64), PHP_INT_MAX)) <= 64, 'R08', 'Laufsperrenname ≤ 64 Zeichen');
T::finish('journal_pure');
