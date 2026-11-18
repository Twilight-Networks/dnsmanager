#!/bin/bash
# Datei: tests/history/mutation_test.sh
# Zweck: Mutationsproben der History-Erfassung (AP1/H2b), der Audit-Ereignisse (AP1/H2c) und des Publish-Journals (AP1/H2d):
#        Jede Mutation entfernt oder verfälscht genau eine Schutzregel
#        in einer Kopie der Anwendung; die zugehörigen Tests müssen sie erkennen (rot werden). Eine nicht erkannte
#        Mutation ist ein Fehler dieser Suite.
#
# Aufruf (als root auf dem Testsystem): bash mutation_test.sh <App-Verzeichnis>
# Nutzt recorder_test.php (Abschnittsauswahl über H2B_ONLY), audit_test.php (Abschnitte "A:<Liste>" über H2C_ONLY),
# journal_test.php (Abschnitte "J:<Liste>" über H2D_ONLY, ohne Zeitüberschreitungsfälle), journal_pure_test.php ("JP") und
# static_rh8_test.php; isolierte Datenbanken wie dort.

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
    elif [ "$sections" = JP ]; then
        php "$m/tests/history/journal_pure_test.php" "$m" > "$W/$id.log" 2>&1
    elif [[ "$sections" == J:* ]]; then
        H2D_SLOW=0 H2D_ONLY="${sections#J:}" php "$m/tests/history/journal_test.php" "$m" "$iso" > "$W/$id.log" 2>&1
    elif [[ "$sections" == A:* ]]; then
        H2C_ONLY="${sections#A:}" php "$m/tests/history/audit_test.php" "$m" "$iso" > "$W/$id.log" 2>&1
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
mutate M02 web/inc/app/zones/ZoneService.php $'            $history->finish();\n            $audit->finish();\n            $this->pdo->commit();\n            $this->releaseLock(\'create\', $m);' \
    $'            $this->pdo->commit();\n            $history->finish();\n            $audit->finish();\n            $this->releaseLock(\'create\', $m);' RR F 'Historie nach dem Commit statt davor (R-H1)'
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


# ---- AP1/H2c: Audit-Ereignisse
AR=web/inc/app/history/AuditRecorder.php
AP=web/inc/app/history/AuditPolicy.php
SV=web/inc/app/servers/ServerService.php
echo "== Mutationsproben H2c"
mutate MA01 web/inc/app/users/UserService.php $'            $audit->record(\'user\', $userId, $input->username, null, \'create\', [],' $'            if (false) $audit->record(\'user\', $userId, $input->username, null, \'create\', [],' RR A:P 'Ereignis der Benutzeranlage entfernt'
mutate MA01s web/inc/app/users/UserService.php $'            $audit->finish();\n            $this->pdo->commit();\n\n            return self::result(true, \'success\', \'user.create.success\'' $'            $this->pdo->commit();\n\n            return self::result(true, \'success\', \'user.create.success\'' RR static 'finish() der Benutzeranlage entfernt (statisch)'
mutate MA02 "$AP" '        if (self::looksSecret($value)) {' '        if (false) {' RR A:R 'Schwärzung von Freitext entfernt'
mutate MA03 "$SV" '                self::auditValues($lockedRow), self::auditValues($update_data),' '                self::auditValues($existing), self::auditValues($update_data),' RC A:N 'Vorher-Werte aus ungesperrtem Stand (R-A5)'
mutate MA04 "$SV" '            if (($lockedDnsChanged && !$server_data_changed) || ($lockedActiveChanged && !$this->lock->isHeld())) {' '            if (false) {' RR A:N 'E50-Prüfung unter Sperre entfernt'
mutate MA05 "$AR" $'        $changeSetId = $this->zones?->changeSetId()\n            ?? HistoryWriter::insertChangeSet(' $'        $changeSetId = HistoryWriter::insertChangeSet(' RR A:P 'getrennte Änderungsmenge bei Mischoperation (R-A6)'
mutate MA06 web/inc/app/users/UserService.php $'            $target = $locked[$userId];\n            $audit->record(\'user\', $userId, $target[\'username\'], null, \'delete\',' $'            $target = $locked[$userId];\n            $audit = AuditRecorder::open($this->pdo, HistoryContext::forUser($actor, \'user.delete\'));\n            $audit->record(\'user\', $userId, $target[\'username\'], null, \'delete\',' RR A:P 'Akteursname erst nach der Selbstlöschung gelesen (E47)'
mutate MA07 "$AR" $'        try {\n            $this->pdo->exec(\'RELEASE SAVEPOINT \' . $this->savepoint);' $'        return;\n        try {\n            $this->pdo->exec(\'RELEASE SAVEPOINT \' . $this->savepoint);' RR A:G 'Savepoint-Bindung der Erfassung entfernt'
mutate MA08 "$SV" $'                $audit->record(\'server\', (int)$pl[\'id\'], (string)$pl[\'name\'], null, \'update\', [\'is_local\' => 1], [\'is_local\' => 0]);' '' RR A:P 'impliziter Wechsel des bisherigen lokalen Servers nicht erfasst'
mutate MA09 sql/dnsmanager_schema.sql "(entity_type = 'zone' AND zone_id IS NOT NULL AND zone_id = entity_id)" "(entity_type = 'zone' AND zone_id = entity_id)" RR A:K 'CHECK ohne IS NOT NULL (NULL-Semantik)'
mutate MA10 sql/dnsmanager_schema.sql '    UNIQUE KEY uq_ae_change_set_seq (change_set_id, seq),' '    KEY uq_ae_change_set_seq (change_set_id, seq),' RR A:K 'seq nicht eindeutig je Änderungsmenge'
mutate MA11 web/inc/app/zones/ZoneService.php $'                    $dyndnsAccounts = (int)$stmt->fetchColumn();' $'                    $dyndnsAccounts = 0;' RC A:P 'Kaskadenzähler der Zonenlöschung falsch (E53)'
mutate MA12 "$SV" $'            $ctx[\'audit\'] = \'failed\';' '' RR A:F 'Kennzeichen für gescheiterten Reload-Nachtrag entfernt (Q2)'
mutate MA13 "$AP" '            if (!is_string($marker) || !in_array($marker, self::SECRET_MARKERS, true)) {' '            if (false) {' RR A:Q 'Kennzeichenprüfung entfernt (Wert statt Kennzeichen)'

# ---- AP1/H2d: Publish-Journal
PS=web/inc/app/publish/PublishService.php
PL=web/inc/app/publish/PublishLedger.php
PJ=web/inc/app/publish/PublishJournal.php
PR=web/inc/app/publish/PublishJournalReader.php
PC=web/inc/app/publish/PublishTargetClassifier.php
LB=web/inc/deploy/LegacyBindPublishBackend.php
DI=web/inc/deploy/bind_file_distributor.php
echo "== Mutationsproben H2d"
mutate MJ01 "$PS" $'            $ledger->abandonStale();\n' '' RR J:F 'Wiederanlauf J6 entfernt (offene Vorgänge bleiben offen)'
mutate MJ02 "$LB" "generate_zone_file(\$zoneId, \$work->path(), 'publish', \$this->pdo, \$hook)" "generate_zone_file(\$zoneId, \$work->path(), 'publish', \$this->pdo, null)" RR J:P 'Versuch nicht registriert (J2)'
mutate MJ04 "$PL" $'        $contentHash = $snapshot->contentHash();' $'        $contentHash = $head !== false ? (string)$head[\'head_hash\'] : $snapshot->contentHash();' RR J:P 'content_hash aus dem Head statt aus dem erzeugten Stand (J3)'
mutate MJ05 "$DI" $'        [$servers, $missing] = distribute_registered_targets($servers, $registered, \'id\');' '        $missing = [];' RC J:N 'Konfiguration an die aktuelle statt die registrierte Zielmenge (E62)'
mutate MJ06 "$PC" '    private const CURL_NOT_SENT = [1, 2, 3, 5, 6, 7, 35,' '    private const CURL_NOT_SENT = [1, 2, 3, 5, 6, 7, 28, 35,' RR JP 'Zeitüberschreitung als failed statt unknown (Q3)'
mutate MJ07 "$PJ" $'        } catch (Throwable $e) {\n            $this->failure($what, $e->getMessage());\n        }' $'        } catch (LogicException $e) {\n            $this->failure($what, $e->getMessage());\n        }' RR J:F 'J4-Schreibfehler beeinflusst das operative Ergebnis'
mutate MJ08 "$PL" " WHERE t.attempt_id = ? AND t.server_id = ? AND a.run_id = ? AND t.{\$ch}_sync = 'sending'\"" " WHERE t.attempt_id = ? AND t.server_id = ? AND a.run_id = ?\"" RR J:J 'Endzustand eines Ziels überschreibbar (J4a, dynamisch)'
mutate MJ08s "$PL" " WHERE t.attempt_id = ? AND t.server_id = ? AND a.run_id = ? AND t.{\$ch}_sync = 'sending'\"" " WHERE t.attempt_id = ? AND t.server_id = ? AND a.run_id = ?\"" RR static 'Endzustand eines Ziels überschreibbar (J4a, statisch)'
mutate MJ09 "$DI" $'    if ($journal !== null && !($journal[\'register\'])(array_column($servers, \'name\', \'id\'))) {' $'    if (false) {' RR J:P 'Cleanup ohne Vorregistrierung (E66)'
mutate MJ10 "$PR" $'            && $runLockHolder !== null && $runLockHolder !== false && (int)$runLockHolder === $connectionId\n' '' RR J:L 'Lebendigkeit nur über die Verbindungs-ID (Wiederverwendung nach Neustart, E73)'
mutate MJ11 "$PL" $'            if ($holder !== null && $holder !== false) {' $'            if (false) {' RR J:L 'J6 markiert auch einen lebendigen Lauf'
mutate MJ12 "$DI" $'    if ($journal !== null && (!is_string($zone_bytes) || !hash_equals((string)$journal[\'fileHash\'], PublishHashContract::sha256($zone_bytes)))) {' $'    if (false) {' RR J:P 'Datei ohne Prüfung des registrierten file_hash gesendet'
mutate MJ13 "$PL" "            \$kind, \$requestedZoneId, \$fullRebuild ? 1 : 0, \$connectionId, (int)\$row['w'], \$ctx->actorType," "            \$kind, \$requestedZoneId, \$fullRebuild ? 1 : 0, \$connectionId, 0, \$ctx->actorType," RR J:P,R 'Wasserstand der Reaktivierung falsch (E70)'
mutate MJ14 "$PL" $'        $stmt = $this->pdo->prepare(\'SELECT GET_LOCK(?, 0)\');\n        $stmt->execute([$lock]);' $'        $stmt = $this->pdo->prepare(\'SELECT 1\');\n        $stmt->execute([]);' RR J:L 'Lauf ohne laufeigene Sperre (Identität nicht nachweisbar)'

echo "ERGEBNIS mutation: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
