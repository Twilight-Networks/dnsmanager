#!/bin/bash
# Datei: tests/history/mutation_test.sh
# Zweck: Mutationsproben der History-Erfassung (AP1/H2b): Jede Mutation entfernt oder verfälscht genau eine Schutzregel
#        in einer Kopie der Anwendung; die zugehörigen Tests müssen sie erkennen (rot werden). Eine nicht erkannte
#        Mutation ist ein Fehler dieser Suite.
#
# Aufruf (als root auf dem Testsystem): bash mutation_test.sh <App-Verzeichnis>
# Nutzt recorder_test.php (Abschnittsauswahl über H2B_ONLY) und static_rh8_test.php; isolierte Datenbanken wie dort.

set -uo pipefail
APP="${1:-}"
[ -d "$APP/web" ] || { echo "Aufruf: bash mutation_test.sh <App-Verzeichnis>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
W="$(mktemp -d /tmp/dnsmgr-h2a-mut.XXXXXX)"; chmod 700 "$W"
trap 'rm -rf "$W"' EXIT
PASS=0; FAIL=0
ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }

# mutate <ID> <Datei relativ> <alt> <neu> <Isolationsstufe> <Abschnitte|static> <Beschreibung>
mutate() {
    local id="$1" file="$2" old="$3" new="$4" iso="$5" sections="$6" desc="$7" m="$W/$1"
    rm -rf "$m"; cp -a "$APP" "$m"
    if ! OLD="$old" NEW="$new" php -r '$f = $argv[1]; $s = file_get_contents($f); $n = substr_count($s, getenv("OLD"));
            if ($n !== 1) { fwrite(STDERR, "Treffer: $n\n"); exit(1); } file_put_contents($f, str_replace(getenv("OLD"), getenv("NEW"), $s));' "$m/$file"; then
        ok 0 "$id Mutation nicht anwendbar ($file)"; return
    fi
    if [ "$sections" = static ]; then
        php "$m/tests/history/static_rh8_test.php" "$m" > "$W/$id.log" 2>&1
    else
        H2B_ONLY="$sections" php "$m/tests/history/recorder_test.php" "$m" "$iso" > "$W/$id.log" 2>&1
    fi
    local rc=$? hits
    hits=$(grep -c '^  FAIL' "$W/$id.log")
    if [ "$rc" != 0 ] && [ "$hits" -gt 0 ]; then
        ok 1 "$id erkannt – $desc ($(grep -m3 '^  FAIL' "$W/$id.log" | awk '{print $2}' | tr '\n' ' '))"
    else
        ok 0 "$id NICHT erkannt – $desc (Exit $rc)"; tail -5 "$W/$id.log" | sed 's/^/       /'
    fi
    rm -rf "$m"
}

R=web/inc/app/history/ZoneHistoryRecorder.php
echo "== Mutationsproben H2b"
mutate M01 "$R" 'if ($pre->contentHash() !== $headHash) {' 'if (false) {' RR D 'Drift-Prüfung (R-H3) entfernt'
mutate M02 web/inc/app/zones/ZoneService.php $'            $history->finish();\n            $this->pdo->commit();\n            $this->releaseLock(\'create\', $m);' \
    $'            $this->pdo->commit();\n            $history->finish();\n            $this->releaseLock(\'create\', $m);' RR F 'Historie nach dem Commit statt davor (R-H1)'
mutate M03 "$R" "deleted_at FROM zone_history_state WHERE zone_id = ? FOR UPDATE'" "deleted_at FROM zone_history_state WHERE zone_id = ?'" RR N 'Head ohne Sperre gelesen (R-H2: Read-View vor Fachsperren)'
mutate M04 web/inc/app/servers/ServerService.php $'            $this->pdo->beginTransaction();\n\n            if ($this->dbRole($actor->userId) !== \'admin\') {\n                $this->rollbackIfActive();\n                $m[] = new ServiceMessage(\'audit\', \'server.update.access_denied\'' \
    $'            $this->pdo->beginTransaction();\n            $this->pdo->query(\'SELECT COUNT(*) FROM zone_servers\')->fetchAll();\n\n            if ($this->dbRole($actor->userId) !== \'admin\') {\n                $this->rollbackIfActive();\n                $m[] = new ServiceMessage(\'audit\', \'server.update.access_denied\'' RR N 'einfaches Lesen vor den Zonensperren (E34)'
mutate M05 web/inc/app/records/RecordService.php '            $history->beginZones(array_keys($zones));' '' RR P 'Erfassung in deleteRecords entfernt (R-H8, Laufzeit)'
mutate M05s web/inc/app/records/RecordService.php '            $history->beginZones(array_keys($zones));' '' RR static 'Erfassung in deleteRecords entfernt (R-H8, statisch)'
mutate M06 "$R" $'                $store->store($post);\n                $headNo++;\n                HistoryWriter::insertRevision($this->pdo, $zoneId, $post->zoneName(), $headNo, $this->actorChangeSet($label), \'change\',' \
    $'                $store->store($post);\n                $headNo += 2;\n                HistoryWriter::insertRevision($this->pdo, $zoneId, $post->zoneName(), $headNo, $this->actorChangeSet($label), \'change\',' RR P 'falsche Revisionsnummer'
mutate M07 web/inc/app/records/RecordService.php '            if ($t1['"'"'changeSetId'"'"'] !== null && $t1['"'"'revisionNo'"'"'] !== null) {' '            if (false) {' RC P 'AutoPTR-Abhängigkeit (R-H7) entfernt'
mutate M08 "$R" $'        $recorder->assertSameTransaction();\n    }' $'    }' RR G 'Savepoint-Bindung an die Transaktion entfernt (E39)'
mutate M09 web/inc/app/history/ZoneSnapshotStore.php $'            if (!$this->serverInTransaction()) {' $'            if (false) {' RR S 'Transaktionsprüfung im Duplicate-Key-Pfad entfernt (E33)'
mutate M09b web/inc/app/history/ZoneSnapshotStore.php $'            if ($code !== 1062) {' $'            if (true) {' RC S 'Duplicate-Key-Pfad entfernt (E33)'
mutate M10 "$R" "'SELECT COUNT(*) FROM zone_history_state WHERE zone_id = ?'" "'SELECT COUNT(*) FROM zone_history_state WHERE zone_id = ? FOR UPDATE'" RR N 'Lazy-Baseline mit Gap-Sperre (E35)'
mutate M11 web/inc/deploy/BindZoneBuildBackend.php $'        $this->assertCallerTransaction(\'validateAndFlag\');\n        ZoneHistoryRecorder::assertOpenFor($this->pdo, $zoneId);' $'        $this->assertCallerTransaction(\'validateAndFlag\');' RR G 'Laufzeitwächter entfernt (R-H8)'
mutate M12 "$R" $'                HistoryWriter::updateState($this->pdo, $zoneId, $headNo, $pre->contentHash(), true);' $'                HistoryWriter::updateState($this->pdo, $zoneId, $headNo, $pre->contentHash(), false);' RC P 'Löschung ohne deleted_at (R-H5)'

echo "ERGEBNIS mutation: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
