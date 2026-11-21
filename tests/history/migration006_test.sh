#!/bin/bash
# Datei: tests/history/migration006_test.sh
# Zweck: Tests der Migration 006 (scripts/migrate_006_zone_serial_verification.sh, AS3, E79/E87) auf isolierten
#        Datenbanken dnsmgr_t21_r<RUN>_q* (Eigentum nach tests/history/owned.sh).
#
# Aufruf (als root auf dem Testsystem): bash migration006_test.sh <App-Verzeichnis> <Basis-Schema.sql>
#   <Basis-Schema.sql> = Schema vor Migration 002 (wie run.sh); Migrationen 002–005 mit den Skripten des App-Commits.
#
# Der Advisory-Lock 'dnsmanager_publish_all' ist serverweit: Q09–Q11 belegen ihn bzw. halten ihn über die Migration nur
# wenige Sekunden. Metadatensperren betreffen ausschließlich die isolierten Testdatenbanken.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash migration006_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=owned.sh
source "$H/owned.sh"
MIG6="$APP/scripts/migrate_006_zone_serial_verification.sh"
LOCK="dnsmanager_publish_all"
W="$(mktemp -d /tmp/dnsmgr-as3-mig6.XXXXXX)"; chmod 700 "$W"
owned_init "$W/owned"
PASS=0; FAIL=0
BG=()
cleanup() { for p in "${BG[@]}"; do kill "$p" 2>/dev/null; done; wait 2>/dev/null; owned_cleanup; rm -rf "$W"; }
trap cleanup EXIT
ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }
expect_exit() { # <erwartet> <Fall> <Befehl…>
    local want="$1" id="$2"; shift 2
    "$@" > "$W/out" 2>&1; local rc=$?
    if [ "$rc" = "$want" ]; then ok 1 "$id Exit $rc"; else ok 0 "$id Exit $rc statt $want"; sed 's/^/       /' "$W/out"; fi
}
mkdb() { # <Name> <base|m2|m5|new>
    local d="${H2A_PREFIX}$1" m
    owned_create_db "$d" >&2 || exit 1
    case "$2" in
        new) grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$d" ;;
        *)   mariadb "$d" < "$BASE_SCHEMA" ;;
    esac
    mariadb "$d" < "$H/fixture.sql"
    if [ "$2" = m2 ] || [ "$2" = m5 ]; then bash "$APP/scripts/migrate_002_history_foundation.sh" --db "$d" --apply --no-backup > "$W/m.out" 2>&1 || { echo "ABBRUCH: Migration 002 auf $d" >&2; exit 1; }; fi
    if [ "$2" = m5 ]; then
        for m in migrate_003_history_dependencies migrate_004_audit_events migrate_005_publish_journal; do
            bash "$APP/scripts/$m.sh" --db "$d" --apply --no-backup > "$W/m.out" 2>&1 || { echo "ABBRUCH: $m auf $d" >&2; exit 1; }
        done
    fi
    echo "$d"
}
col() { mariadb -N -e "SELECT CONCAT(LOWER(COLUMN_TYPE), '|', IS_NULLABLE, '|', IFNULL(COLUMN_DEFAULT, '~')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = 'zones' AND COLUMN_NAME = 'soa_serial'"; }
hastab() { mariadb -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = 'zone_server_verification'"; }
ctime() { mariadb -N -e "SELECT IFNULL(CREATE_TIME, '-') FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = '$2'"; }
serials() { mariadb -N -B "$1" -e "SELECT id, soa_serial FROM zones ORDER BY id" | sha256sum | cut -c1-64; }
state() { php "$H/schemacheck.php" "$APP" "$1" | grep "^$2"$'\t' | cut -f2; }
# AS4b: SchemaCheck::problems() ist strikt nach 007 – Befunde der späteren Migration 007 hier ausgefiltert
problems() { php "$H/schemacheck.php" "$APP" "$1" | grep '^problem' | grep -v 'system_status.fence_domain fehlt$' | grep -vE '(zone_publish_targets: Struktur weicht ab \(erwartet col\|zone_protocol\||publish_cleanup_results: Struktur weicht ab \(erwartet col\|cleanup_protocol\|)' | cut -f2- | tr '\n' ';'; }
lockfree() { mariadb -N -e "SELECT IS_FREE_LOCK('$LOCK')"; }
OLD="int(11)|NO|2025010101"; NEW="int(10) unsigned|NO|2025010101"

echo "Migration 006 – Lauf-Kennung $H2A_RUN"
[ "$(lockfree)" = 1 ] || { echo "ABBRUCH: Publish-Sperre belegt"; exit 3; }

# Q01 Migrationen 002–005 fehlen bzw. unvollständig: Abbruch vor jeder Änderung
D=$(mkdb q1 m2)
expect_exit 2 "Q01 --check ohne Migrationen 003–005" bash "$MIG6" --db "$D" --check
expect_exit 2 "Q01b --apply ohne Migrationen 003–005 abgelehnt" bash "$MIG6" --db "$D" --apply --no-backup
ok "$([ "$(col "$D")" = "$OLD" ] && [ "$(hastab "$D")" = 0 ] && echo 1)" "Q01c keine DDL"

# Q02 Grundablauf mit Sicherung; Sitzungsparameter und Lock auf derselben Verbindung
D=$(mkdb q2 m5)
expect_exit 1 "Q02 --check nach Migration 005: Migration 006 erforderlich" bash "$MIG6" --db "$D" --check
ok "$([ "$(problems "$D")" = "zones.soa_serial: Typ int(11) statt int(10) unsigned;zone_server_verification fehlt;" ] && echo 1)" "Q02b SchemaCheck (neuer Code, altes Schema): beide Befunde, Login fail-closed"
expect_exit 2 "Q02c --no-backup für Nicht-Testdatenbank abgelehnt" bash "$MIG6" --db dnsmanager --apply --no-backup
before="$(serials "$D")"
expect_exit 0 "Q02d --apply mit Sicherung" bash "$MIG6" --db "$D" --apply --backup-dir "$W/bak"
cp "$W/out" "$W/q2.out"
bf="$(ls "$W/bak"/*-vor-migration-006-*.sql 2>/dev/null | head -1)"
ok "$([ -n "$bf" ] && [ "$(stat -c %a "$bf")" = 600 ] && [ "$(stat -c %a "$W/bak")" = 700 ] && grep -q 'CREATE TABLE `zones`' "$bf" && echo 1)" "Q02e Sicherung 600 in 700, enthält zones"
ok "$([ "$(col "$D")" = "$NEW" ] && [ "$(state "$D" zone_server_verification)" = ok ] && [ -z "$(problems "$D")" ] && echo 1)" "Q02f Spalte ohne Vorzeichen, Tabelle wie erwartet, SchemaCheck ohne Befund"
ok "$([ "$(serials "$D")" = "$before" ] && grep -q '^Zonen und Serials unverändert' "$W/q2.out" && echo 1)" "Q02g Zonen und Serial-Werte unverändert (unabhängig und im Skript geprüft)"
sess="$(grep -oE 'SESSION\|[0-9]+\|[0-9]+\|[A-Z_,]+' "$W/q2.out")"; lockl="$(grep -oE 'LOCK\|[^ ]+' "$W/q2.out")"
IFS='|' read -r _ s_conn s_lwt s_mode <<< "$sess"; IFS='|' read -r _ l_got l_holder l_conn <<< "$lockl"
ok "$([ "$s_lwt" = 10 ] && [[ "$s_mode" == *STRICT_ALL_TABLES* ]] && [ "$l_got" = 1 ] && [ "$l_holder" = "$s_conn" ] && [ "$l_conn" = "$s_conn" ] && echo 1)" "Q02h lock_wait_timeout 10, strikter sql_mode und Lock auf derselben Verbindung ($sess / $lockl)"
ok "$(grep -q '^  RELEASE|1$' "$W/q2.out" && [ "$(lockfree)" = 1 ] && echo 1)" "Q02i Lock freigegeben"
expect_exit 0 "Q02j --check danach" bash "$MIG6" --db "$D" --check

# Q03 vollständig migriert: keine weitere DDL
sleep 1.2
c1="$(ctime "$D" zones)"; c2="$(ctime "$D" zone_server_verification)"
expect_exit 0 "Q03 --apply erneut" bash "$MIG6" --db "$D" --apply --no-backup
ok "$(grep -q 'Nichts zu tun' "$W/out" && ! grep -q 'Geschützter Teil' "$W/out" && [ "$(ctime "$D" zones)" = "$c1" ] && [ "$(ctime "$D" zone_server_verification)" = "$c2" ] && echo 1)" "Q03b keine DDL (CREATE_TIME beider Tabellen unverändert)"

# Q04 Teilzustand: Tabelle vorhanden, Spalte alt ⇒ nur Schritt b
D=$(mkdb q4 m5)
mariadb "$D" < "$APP/sql/migrations/006a_zone_server_verification.sql"
sleep 1.2; c2="$(ctime "$D" zone_server_verification)"
expect_exit 1 "Q04 --check: Schritt b offen" bash "$MIG6" --db "$D" --check
expect_exit 0 "Q04b --apply führt nur Schritt b aus" bash "$MIG6" --db "$D" --apply --no-backup
ok "$(grep -q 'Schritt a (CREATE TABLE zone_server_verification): bereits vorhanden' "$W/out" && ! grep -q 'STEP|a' "$W/out" && grep -q 'STEP|b' "$W/out" && [ "$(ctime "$D" zone_server_verification)" = "$c2" ] && [ "$(col "$D")" = "$NEW" ] && echo 1)" "Q04c kein CREATE, Tabelle unverändert, Spalte umgestellt"

# Q05 Teilzustand: Spalte neu, Tabelle fehlt ⇒ nur Schritt a, kein erneutes ALTER
D=$(mkdb q5 m5)
mariadb "$D" < "$APP/sql/migrations/006b_zones_soa_serial_unsigned.sql"
sleep 1.2; c1="$(ctime "$D" zones)"
expect_exit 0 "Q05 --apply führt nur Schritt a aus" bash "$MIG6" --db "$D" --apply --no-backup
ok "$(grep -q 'STEP|a' "$W/out" && ! grep -q 'STEP|b' "$W/out" && [ "$(ctime "$D" zones)" = "$c1" ] && [ "$(state "$D" zone_server_verification)" = ok ] && echo 1)" "Q05b kein ALTER (CREATE_TIME zones unverändert), Tabelle angelegt"

# Q06 abweichende Spalte ⇒ Exit 2 ohne DDL
for v in "BIGINT NOT NULL DEFAULT 2025010101|bigint" "INT NULL DEFAULT 2025010101|nullable" "INT NOT NULL DEFAULT 1|Standardwert" "INT UNSIGNED NOT NULL DEFAULT 1|unsigned mit anderem Standardwert"; do
    def="${v%%|*}"; label="${v##*|}"
    D=$(mkdb "q6$(echo "$label" | tr -cd 'a-z' | cut -c1-8)" m5)
    mariadb "$D" -e "ALTER TABLE zones MODIFY soa_serial $def"
    sleep 1.2; c1="$(ctime "$D" zones)"
    expect_exit 2 "Q06 Spalte abweichend ($label): --apply" bash "$MIG6" --db "$D" --apply --no-backup
    ok "$([ "$(ctime "$D" zones)" = "$c1" ] && [ "$(hastab "$D")" = 0 ] && echo 1)" "Q06b ($label) keine DDL"
done

# Q07 abweichende Tabelle ⇒ Skript und SchemaCheck erkennen jede Abweichung
D=$(mkdb q7 m5)
mutate() { # <Fall> <SQL nach Anlage der Tabelle>
    mariadb "$D" -e "DROP TABLE IF EXISTS zone_server_verification"
    mariadb "$D" < "$APP/sql/migrations/006a_zone_server_verification.sql"
    mariadb "$D" -e "$2"
    expect_exit 2 "Q07 $1: Skript" bash "$MIG6" --db "$D" --apply --no-backup
    ok "$([ "$(state "$D" zone_server_verification)" = mismatch ] && [ "$(col "$D")" = "$OLD" ] && echo 1)" "Q07b $1: SchemaCheck mismatch, keine DDL"
}
mutate "CHECK entfernt" "ALTER TABLE zone_server_verification DROP CONSTRAINT chk_zsv_verified_order"
mutate "CHECK-Ausdruck bei gleichem Namen geändert" "ALTER TABLE zone_server_verification DROP CONSTRAINT chk_zsv_verified_bundle, ADD CONSTRAINT chk_zsv_verified_bundle CHECK ((verified_serial IS NULL AND verified_file_hash IS NULL AND verified_at IS NULL) OR (verified_serial IS NOT NULL AND verified_file_hash IS NOT NULL AND verified_at IS NOT NULL))"
mutate "Primärschlüssel umgekehrt" "ALTER TABLE zone_server_verification DROP PRIMARY KEY, ADD PRIMARY KEY (server_id, zone_id)"
mutate "Index fehlt" "ALTER TABLE zone_server_verification DROP INDEX idx_zsv_zone_name"
mutate "Serial mit Vorzeichen" "ALTER TABLE zone_server_verification MODIFY verified_serial INT NULL DEFAULT NULL"
mutate "zusätzliche Spalte" "ALTER TABLE zone_server_verification ADD COLUMN x INT NULL"
mutate "Fremdschlüssel ergänzt" "ALTER TABLE zone_server_verification ADD CONSTRAINT fk_x FOREIGN KEY (zone_id) REFERENCES zones (id)"

# Q08 negative Serial ⇒ Exit 2 ohne Änderung
D=$(mkdb q8 m5)
mariadb "$D" -e "UPDATE zones SET soa_serial = -5 WHERE id = (SELECT MIN(id) FROM (SELECT id FROM zones) z)"
expect_exit 2 "Q08 negative Serial: --check" bash "$MIG6" --db "$D" --check
expect_exit 2 "Q08b negative Serial: --apply" bash "$MIG6" --db "$D" --apply --no-backup
ok "$([ "$(col "$D")" = "$OLD" ] && [ "$(hastab "$D")" = 0 ] && echo 1)" "Q08c keine DDL"

# Q09 Publish-Lock belegt ⇒ Exit 2 ohne DDL
D=$(mkdb q9 m5)
mariadb -N -e "SELECT GET_LOCK('$LOCK', 0), SLEEP(6)" > /dev/null & BG+=($!)
sleep 1
expect_exit 2 "Q09 Publish-Lock belegt" bash "$MIG6" --db "$D" --apply --no-backup
ok "$(grep -q 'MIG006_LOCK_BUSY' "$W/out" && [ "$(col "$D")" = "$OLD" ] && [ "$(hastab "$D")" = 0 ] && echo 1)" "Q09b keine DDL"
wait; BG=()

# Q10 Lock-Besitz während der DDL: offene Transaktion auf zones hält die Metadatensperre 5 s
D=$(mkdb q10 m5)
mariadb -N "$D" -e "START TRANSACTION; SELECT COUNT(*) FROM zones; SELECT SLEEP(5); COMMIT;" > /dev/null & BG+=($!)
sleep 0.5
t0=$(date +%s.%N)
bash "$MIG6" --db "$D" --apply --no-backup > "$W/q10.out" 2>&1 & mpid=$!
sleep 2.5
obs="$(mariadb -N -e "SELECT IS_USED_LOCK('$LOCK'), (SELECT CONCAT(STATE, '|', LEFT(INFO, 30)) FROM information_schema.PROCESSLIST WHERE ID = IS_USED_LOCK('$LOCK'))")"
wait "$mpid"; mrc=$?; t1=$(date +%s.%N)
wait; BG=()
holder="${obs%%$'\t'*}"; pstate="${obs#*$'\t'}"
sconn="$(grep -oE 'SESSION\|[0-9]+' "$W/q10.out" | cut -d'|' -f2)"
ok "$([ "$mrc" = 0 ] && [ -n "$holder" ] && [ "$holder" = "$sconn" ] && [[ "$pstate" == *"metadata lock"*"ALTER TABLE zones"* ]] && echo 1)" "Q10 während des Wartens auf die Metadatensperre hält die ALTER-Verbindung den Publish-Lock (Halter $holder = Sitzung $sconn; $pstate)"
ok "$([ "$(lockfree)" = 1 ] && [ "$(col "$D")" = "$NEW" ] && echo 1)" "Q10b danach migriert und Lock frei (Dauer $(awk -v a="$t0" -v b="$t1" 'BEGIN { printf "%.1f", b - a }') s)"

# Q11 Metadatensperre länger als 10 s ⇒ Exit 3, dokumentierter Teilzustand, Wiederanlauf
D=$(mkdb q11 m5)
mariadb -N "$D" -e "START TRANSACTION; SELECT COUNT(*) FROM zones; SELECT SLEEP(18); COMMIT;" > /dev/null & BG+=($!)
sleep 0.5
t0=$(date +%s)
expect_exit 3 "Q11 Metadatensperre: Abbruch" bash "$MIG6" --db "$D" --apply --no-backup
dt=$(( $(date +%s) - t0 ))
ok "$([ "$dt" -ge 9 ] && [ "$dt" -le 16 ] && grep -q 'Lock wait timeout' "$W/out" && grep -q 'Teilzustand: Schritt a ausgeführt' "$W/out" && echo 1)" "Q11b nach ≈ 10 s ($dt s), Teilzustand gemeldet"
ok "$([ "$(col "$D")" = "$OLD" ] && [ "$(state "$D" zone_server_verification)" = ok ] && [ "$(lockfree)" = 1 ] && echo 1)" "Q11c Spalte unverändert (ALTER atomar), Tabelle angelegt, Lock frei"
wait; BG=()
expect_exit 0 "Q11d Wiederanlauf" bash "$MIG6" --db "$D" --apply --no-backup
ok "$(! grep -q 'STEP|a' "$W/out" && grep -q 'STEP|b' "$W/out" && [ "$(col "$D")" = "$NEW" ] && echo 1)" "Q11e nur Schritt b nachgeholt"

# Q12 Zustand ändert sich zwischen Preflight und Prüfung unter dem Lock (Kopie mit falschem Plan) ⇒ keine DDL
D=$(mkdb q12 new)
mkdir -p "$W/app12"; cp -a "$APP/." "$W/app12/"
sed -i 's/    "$COL_NEW") NEED_ALTER=0 ;;/    "$COL_NEW") NEED_ALTER=1 ;;/' "$W/app12/scripts/migrate_006_zone_serial_verification.sh"
mariadb "$D" -e "DROP TABLE zone_server_verification"
sleep 1.2; c1="$(ctime "$D" zones)"
expect_exit 2 "Q12 Prüfung unter dem Lock erkennt abweichenden Zustand" bash "$W/app12/scripts/migrate_006_zone_serial_verification.sh" --db "$D" --apply --no-backup
ok "$(grep -q 'MIG006_STATE_CHANGED' "$W/out" && [ "$(ctime "$D" zones)" = "$c1" ] && [ "$(hastab "$D")" = 0 ] && [ "$(lockfree)" = 1 ] && echo 1)" "Q12b keine DDL, Lock frei"

# Q13 Lock-Verlust vor dem ALTER (Kopie gibt den Lock nach Schritt a frei) ⇒ Abbruch, kein ALTER
D=$(mkdb q13 m5)
mkdir -p "$W/app13"; cp -a "$APP/." "$W/app13/"
php -r '$f = $argv[1]; $s = file_get_contents($f); $m = "        echo \"SELECT '\''STEP|b'\'';\""; $n = substr_count($s, $m);
        if ($n !== 1) { exit(1); }
        $s = str_replace("    if [ \"\$NEED_ALTER\" -eq 1 ]; then\n        echo \"DELIMITER //\"", "    if [ \"\$NEED_ALTER\" -eq 1 ]; then\n        echo \"SELECT RELEASE_LOCK('\''\$LOCK_NAME'\'');\"\n        echo \"DELIMITER //\"", $s, $c);
        if ($c !== 1) { exit(1); } file_put_contents($f, $s);' "$W/app13/scripts/migrate_006_zone_serial_verification.sh" || ok 0 "Q13 Vorbereitung der Kopie"
expect_exit 3 "Q13 Lock-Verlust vor dem ALTER" bash "$W/app13/scripts/migrate_006_zone_serial_verification.sh" --db "$D" --apply --no-backup
ok "$(grep -q 'MIG006_LOCK_LOST' "$W/out" && [ "$(col "$D")" = "$OLD" ] && [ "$(lockfree)" = 1 ] && echo 1)" "Q13b kein ALTER, Lock frei"
expect_exit 0 "Q13c Wiederanlauf mit unverändertem Skript" bash "$MIG6" --db "$D" --apply --no-backup

# Q14 Neuinstallation = Migration 002–007 (das Neuinstallationsschema enthält seit AS4a auch Migration 007)
D=$(mkdb q14 new)
expect_exit 0 "Q14 Neuinstallation: --check ohne Migration" bash "$MIG6" --db "$D" --check
sig() { php "$APP/web/scripts/schema_history-cli.php" sql | mariadb -N -B "$1"; }
bash "$APP/scripts/migrate_007_journal_agent_results.sh" --db "${H2A_PREFIX}q2" --apply --no-backup > "$W/m7.out" 2>&1 || ok 0 "Q14b Vorbereitung: Migration 007 auf q2"
ok "$([ "$(sig "$D")" = "$(sig "${H2A_PREFIX}q2")" ] && [ "$(col "$D")" = "$(col "${H2A_PREFIX}q2")" ] && [ -z "$(problems "$D")" ] && echo 1)" "Q14b Struktursignatur und Spalte Neuinstallation = Migration 002–007 (byte-gleich), SchemaCheck ohne Befund"

# Q15 frühere Skripte bleiben nach Migration 006 konsistent
for m in migrate_002_history_foundation migrate_003_history_dependencies migrate_004_audit_events migrate_005_publish_journal migrate_006_zone_serial_verification; do
    expect_exit 0 "Q15 $m --check nach Migration 006 und 007" bash "$APP/scripts/$m.sh" --db "${H2A_PREFIX}q2" --check
done

# Q16 Schema-Rollback nach README: nur bei MAX(soa_serial) ≤ 2147483647 und leerer Tabelle
D=$(mkdb q16 m5)
bash "$MIG6" --db "$D" --apply --no-backup > /dev/null 2>&1
mariadb "$D" -e "UPDATE zones SET soa_serial = 3000000000 WHERE id = (SELECT MIN(id) FROM (SELECT id FROM zones) z)"
mariadb "$D" -e "SET SESSION sql_mode = 'STRICT_ALL_TABLES'; ALTER TABLE zones MODIFY soa_serial INT NOT NULL DEFAULT 2025010101" > "$W/out" 2>&1
ok "$(grep -q 'Out of range' "$W/out" && [ "$(col "$D")" = "$NEW" ] && echo 1)" "Q16 Rückweg mit Serial > 2³¹−1 scheitert ohne Änderung (strikt)"
mariadb "$D" -e "UPDATE zones SET soa_serial = 2026112001"
mariadb "$D" -e "SET SESSION sql_mode = 'STRICT_ALL_TABLES'; SELECT COUNT(*) FROM zone_server_verification INTO @n; DROP TABLE zone_server_verification; ALTER TABLE zones MODIFY soa_serial INT NOT NULL DEFAULT 2025010101" > "$W/out" 2>&1
expect_exit 1 "Q16b nach Rückweg: --check meldet Migration 006 erforderlich" bash "$MIG6" --db "$D" --check
expect_exit 0 "Q16c erneut anwendbar" bash "$MIG6" --db "$D" --apply --no-backup

echo "ERGEBNIS migration006: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
