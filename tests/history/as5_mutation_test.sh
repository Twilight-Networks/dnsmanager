#!/bin/bash
# Datei: tests/history/as5_mutation_test.sh
# Zweck: Mutationsproben für AS5 (docs/ARCHITECTURE_HISTORY.md Abschnitt 5k). Jede Mutation entfernt oder verfälscht
#        genau eine Schutzregel in einer Kopie der Anwendung; die zugehörigen Tests müssen sie erkennen (rot werden):
#        Gruppe "pure" ⇒ as5_pure_test.php, sonst die Gruppen von as5_test.php (AS5_ONLY, REPEATABLE READ).
#        Eine nicht erkannte Mutation ist ein Fehler dieser Suite. Nur auf zuvor grüner Basis ausführen (run.sh).
#        Review AS5 (Punkt 5): Erkannt ist eine Mutation nur, wenn ein ERWARTETER Testfall mit der erwarteten Ursache
#        fehlschlägt (EXPECT: regulärer Ausdruck auf die FAIL-Zeile). Ein PHP-Abbruch ohne diese FAIL-Zeile zählt nicht.
#        Vor den Mutationen muss die unveränderte Basis in beiden Suiten grün sein.
#
# Aufruf (als root auf dem Testsystem): bash as5_mutation_test.sh <App-Verzeichnis> <AS4b-App-Verzeichnis>

set -uo pipefail
APP="${1:-}"; BASEAPP="${2:-}"
[ -d "$APP/web" ] && [ -d "$BASEAPP/web" ] || { echo "Aufruf: bash as5_mutation_test.sh <App-Verzeichnis> <AS4b-App-Verzeichnis>"; exit 2; }
W="$(mktemp -d /tmp/dnsmgr-as5-mut.XXXXXX)"; chmod 700 "$W"
trap 'rm -rf "$W"' EXIT
PASS=0; FAIL=0
ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }

# Basis muss grün sein (keine Mutationsprobe auf roter Basis): reine Tests und alle Datenbankgruppen (RR)
php "$APP/tests/history/as5_pure_test.php" "$APP" > "$W/base.log" 2>&1 || { echo "ABBRUCH: reine Tests auf der Basis nicht grün"; tail -5 "$W/base.log"; exit 1; }
php "$APP/tests/history/as5_test.php" "$APP" RR "$BASEAPP" > "$W/base_db.log" 2>&1 || { echo "ABBRUCH: as5_test.php auf der Basis nicht grün"; grep '^  FAIL' "$W/base_db.log" | head -5; exit 1; }
echo "  ok   Basis grün: $(tail -1 "$W/base.log"); $(tail -1 "$W/base_db.log")"

# Erwartete Fehlschläge je Mutation (regulärer Ausdruck auf "<Fall-ID> <Text>" der FAIL-Zeile)
declare -A EXPECT=(
    [C01]='^R01 ' [C02]='^R0[12] ' [C03]='^(V01|B01|B02) ' [C04]='^(V01|V02) .*' [C05]='^V01 .*Invariante'
    [C06]='^G09 ' [C07]='^(P01|P03|P04) ' [C08]='^(M05|M18|M21|M22|M25) ' [C09]='^L0[12] ' [C10]='^E05 '
    [C11]='^(W06|W08) ' [C12]='^(W03|W07) ' [C13]='^E08 ' [C14]='^P03b ' [C15]='^(E07|P04) ' [C16]='^E01 '
    [C17]='^G05 ' [C18]='^G06 ' [C19]='^G07 ' [C20]='^(W02|W03|W07) ' [C21]='^W06 ' [C22]='^(W03|W06) '
    [C24]='^X07 ' [C25]='^X03 ' [C26]='^N04 ' [C27]='^(A03|W05) ' [C28]='^A02 .*Audit-Ereignis ohne Änderungsmenge'
    [C29]='^N01 ' [C30]='^C03 .*\[\[\],"exception"\]' [C31]='^(G03|G04|G08) '
    [C32]='^N06 ' [C33]='^M23 ' [C35]='^G08 ' [C36]='^MM02 ' [C37]='^MM01 ' [C38]='^MM03 '
)

# mutate <ID> <Datei relativ> <alt> <neu> <Gruppen> <Beschreibung>
mutate() {
    local id="$1" file="$2" old="$3" new="$4" groups="$5" desc="$6" m="$W/$1"
    rm -rf "$m"; cp -a "$APP" "$m"
    if ! OLD="$old" NEW="$new" php -r '$f = $argv[1]; $s = file_get_contents($f); $n = substr_count($s, getenv("OLD"));
            if ($n !== 1) { fwrite(STDERR, "Treffer: $n\n"); exit(1); } file_put_contents($f, str_replace(getenv("OLD"), getenv("NEW"), $s));' "$m/$file"; then
        ok 0 "$id Mutation nicht anwendbar ($file)"; return
    fi
    local rc=0 hits db
    : > "$W/$id.log"
    if [[ ",$groups," == *",pure,"* ]]; then
        php "$m/tests/history/as5_pure_test.php" "$m" >> "$W/$id.log" 2>&1 || rc=1
    fi
    db="$(echo ",$groups," | sed 's/,pure,/,/g; s/^,//; s/,$//')"
    if [ -n "$db" ]; then
        AS5_ONLY="$db" php "$m/tests/history/as5_test.php" "$m" RR "$BASEAPP" >> "$W/$id.log" 2>&1 || rc=1
    fi
    local want="${EXPECT[$id]:-}"
    [ -n "$want" ] || { ok 0 "$id ohne Erwartung (EXPECT fehlt)"; rm -rf "$m"; return; }
    hits="$(grep '^  FAIL' "$W/$id.log" | sed -E 's/^  FAIL (\[R.\] )?//' | grep -E "$want" | head -3)"
    if [ "$rc" != 0 ] && [ -n "$hits" ]; then
        ok 1 "$id erkannt – $desc ($(echo "$hits" | awk '{print $1}' | tr '\n' ' '))"
        echo "       Ursache: $(echo "$hits" | head -1 | cut -c1-240)"
    else
        ok 0 "$id NICHT erkannt – $desc (Exit $rc, erwartet: $want)"
        { grep -E '^  FAIL|PHP Fatal' "$W/$id.log" | head -4; tail -2 "$W/$id.log"; } | sed 's/^/       /'
    fi
    rm -rf "$m"
}

N=web/inc/app/publish/SerialNumber.php
PO=web/inc/app/publish/SerialPolicy.php
R=web/inc/app/publish/SerialReferenceReader.php
S=web/inc/app/publish/SerialReferenceStore.php
J=web/inc/app/publish/PublishJournal.php
A=web/inc/app/publish/SerialReferenceAdminService.php
G=web/inc/deploy/bind_file_generator.php
B=web/inc/deploy/LegacyBindPublishBackend.php
L=web/inc/app/publish/PublishLedger.php
echo "== Mutationsproben AS5"
mutate C01 "$N" '        return $d < $half ? self::NEWER : self::OLDER;' '        return $a > $b ? self::NEWER : self::OLDER;' pure 'numerischer Vergleich statt RFC 1982'
mutate C02 "$N" '        if ($d === $half) {
            return self::UNDEFINED;
        }' '' pure 'Abstand 2^31 als neuer/älter statt undefiniert'
mutate C03 "$PO" '                $c = SerialNumber::next($r, $bits);' '                $c = SerialNumber::next(max($values), $bits);' pure 'numerisches Maximum als Auswahlgrundlage'
mutate C04 "$PO" '                $c = SerialNumber::next($r, $bits);' '                $c = SerialNumber::next($r, $bits);
                if ($c === 0) { continue; }' pure 'Serial 0 übersprungen'
mutate C05 "$PO" 'if ($calendar !== null && SerialNumber::newerThanAll($calendar, $values, $bits)) {' 'if ($calendar !== null && $calendar > max($values)) {' pure 'Kalenderkandidat numerisch statt gegen jede Referenz geprüft'
mutate C06 "$R" '            $refs[] = $latest['"'"'serial'"'"'];' '' G 'zonenweite Referenz (letzter Versuch unter dem Namen) fehlt (D19)'
mutate C07 "$R" '                array_push($events, ...self::referenceEvents($r));' '' P,W 'Referenzzeilen nicht berücksichtigt'
mutate C08 "$R" '                if (SerialNumber::compare($w, $v) === SerialNumber::NEWER) {' '                if (true) {' pure,P 'Konflikt durch jeden späteren Anker abgelöst (S3 wie S2)'
mutate C09 "$R" "                    \$out[] = \$e('D', 'assumed', [\$x], \$k2, \$at);" "                    \$out[] = \$e('D', 'anchor', [\$x], \$k2, \$at);" L 'Legacy-Annahme als gemessener D-Anker (D18)'
mutate C10 "$R" "        \$certain = (int)\$r['order_certain'] === 1;" '        $certain = true;' pure 'Ordnungsgewissheit ignoriert (D16)'
mutate C11 "$R" "                } elseif (\$r['source'] === 'loss') {" '                } elseif (false) {' W 'Verlustmarke ignoriert'
mutate C12 "$R" "                if (\$r['source'] === 'intent' && !isset(\$completed[(int)\$r['attempt_id']])) {" "                if (false && \$r['source'] === 'intent') {" W,N 'offenes Write-ahead ignoriert'
mutate C13 "$R" "                \$out[] = ['comp' => 'D', 'kind' => 'admin', 'values' => [\$ss], 'key' => \$key, 'at' => \$at, 'ref' => \$ref];" "                \$out[] = ['comp' => 'D', 'kind' => 'anchor', 'values' => [\$ss], 'key' => \$key, 'at' => \$at, 'ref' => \$ref];" pure 'Admin-Referenz als Anker (Override)'
mutate C14 "$S" "&& (int)\$last['run_key'] === \$runKey" '' P 'Deduplizierung ohne Wasserstandsprüfung (Ergänzung 3.2)'
mutate C15 "$R" "            default => array_values(array_filter([\$fs !== null ? \$e('F', 'additive', [\$fs]) : null,
                \$ss !== null ? \$e('D', 'additive', [\$ss]) : null])),
        };" '            default => [],
        };' pure,P 'Rohwerte aus unknown verworfen (D21)'
mutate C16 "$R" "} elseif (\$r['served_state'] === 'not_auth' && \$r['config_state'] === 'no') {" "} elseif (\$r['served_state'] === 'not_auth') {" pure 'not_auth ohne K = nein als Entfernungsnachweis'
mutate C17 "$G" "    if (\$journal === null && \$mode === 'publish') {" '    if (false) {' G 'Publish ohne Journal zulässig (G1)'
mutate C18 "$B" '        if ($journal === null) {' '        if (false) {' G 'Backend ohne Journal zulässig (G2)'
mutate C19 "$L" '        if ($dbSerial === null || SerialNumber::compare($serial, $dbSerial) !== SerialNumber::NEWER) {' '        if (false) {' G 'D9-Prüfung entfernt'
mutate C20 "$J" '                $this->refs->recordIntent($this->run->id,' '                (fn (...$x) => 0)($this->run->id,' W 'kein Write-ahead vor zone_sync'
mutate C21 "$J" "                \$this->refs->recordLoss(\$this->run->id, \$aid, \$zoneId, \$zoneName, \$serverId, \$serverName, 'report_not_stored', \$certain);" '' W 'keine Verlustmarke bei nicht gespeichertem Bericht'
mutate C22 "$J" '            throw new SerialReferenceLost($lost);' '' W 'Lauf läuft nach Referenzverlust weiter'
mutate C24 "$S" '        if ($prev !== null && !$this->isMirrored($prev)) {' '        if (false) {' X 'nicht gespiegelte Beobachtung ohne Sicherung überschrieben'
mutate C25 "$R" "if (\$epoch !== null && \$j['zone_protocol'] === 'as2'" "if (false && \$j['zone_protocol'] === 'as2'" X 'Lücke nach AS4b-Rückfall nicht erkannt (Ergänzung 3.3)'
mutate C26 "$A" '        if ($got === null || $got === false || (int)$got !== 1) {' '        if (false) {' N 'Admin-Erfassung ohne Publish-Lock'
mutate C27 "$R" '        return $f && $d;' '        return $f || $d;' pure,W 'Sperre schon mit einem Bestandteil aufgelöst'
mutate C28 "$A" '                $audit->finish();' '' A 'Erfassung ohne Audit-Ereignis'
mutate C29 "$J" '            $certain = false;   // verspätete Antwort nach Lock-Verlust (D23)' '            $certain = true;' N 'verspätete Antwort als geordnet gespeichert (D23)'
mutate C30 web/inc/app/SchemaCheck.php '        foreach (self::HISTORY_TABLES as $table => $expected) {' '        foreach (self::HISTORY_TABLES as $table => $expected) {
            if ($table === '"'"'zone_serial_references'"'"') {
                continue;
            }' C 'SchemaCheck prüft die Tabelle aus 008 nicht (fehlende Tabelle ohne Befund)'
mutate C31 "$G" "            \$choice = SerialReferenceReader::selectSerial(\$pdo, \$zone, date('Ymd'));" "            \$choice = SerialPolicy::decide([(int)\$zone['soa_serial']], SerialPolicy::calendarCandidate(date('Ymd'))) + ['blocks' => []];" G,P,W 'nur DB-Serial als Referenz (Referenzmenge umgangen)'

MS=web/inc/app/monitoring/MonitoringService.php
# Review AS5: E83, leere Anker, D5, Monitoring-Persistenz
mutate C32 "$J" '            $this->ledger->assertRunAlive($this->run);
        }

        return $fields;' '        }

        return $fields;' N 'keine Laufprüfung nach dem Write-ahead (E83)'
mutate C33 "$R" '            if (!$later) {
                continue;
            }' '            if (!$later) {
                continue;
            }
            if ($a['"'"'values'"'"'] === []) {
                return true;
            }' pure 'leerer Anker löscht frühere numerische Referenzen'
mutate C35 "$R" "        \$serverIds = array_values(array_unique(array_merge(array_map('intval', array_keys(\$targets)), \$this->referencedServers(\$name))));" "        \$serverIds = array_map('intval', array_keys(\$targets));" G 'ehemalige Zielserver nicht bindend (D5)'
mutate C36 "$MS" '                    $refs->recordLoss($watermark, null, (int)$it['"'"'zoneId'"'"'],' '                    (fn (...$x) => 0)($watermark, null, (int)$it['"'"'zoneId'"'"'],' M 'gescheiterte ungeordnete Monitoring-Speicherung ohne Verlustmarke'
mutate C37 "$MS" '            if ($locked) {
                $refs->ensureEpoch($watermark);   // AS5: Epoche vor beiden Zweigen
            }
' '' M 'keine Epoche bei verändertem Wasserstand'
mutate C38 "$MS" "                \$m[] = new ServiceMessage('error', 'monitoring.serial_reference_lost', [" "                \$ignored = new ServiceMessage('error', 'monitoring.serial_reference_lost', [" M 'Verlust ohne Meldung'

echo "ERGEBNIS as5_mutation: $PASS erkannt, $FAIL nicht erkannt"
exit $([ "$FAIL" = 0 ] && echo 0 || echo 1)
