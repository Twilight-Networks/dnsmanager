#!/bin/bash
# Datei: tests/history/migration007_test.sh
# Zweck: Tests der Migration 007 (scripts/migrate_007_journal_agent_results.sh, AS4a) auf isolierten Datenbanken
#        dnsmgr_t21_r<RUN>_r* (Eigentum nach tests/history/owned.sh): Plan nur der fehlenden Schritte a/b/c für alle
#        Teilzustände, Erkennung abweichender Strukturen durch Skript und SchemaCheck (Brücke), Lock- und Abbruchfälle,
#        Kennwerte, Neuinstallation = Migration, frühere Skripte vor und nach 007, Notfall-Rückbau.
#        AS4b: Die Anwendung verlangt 007 strikt. Vor und während 007 (Teilzustände, Rückbau) meldet SchemaCheck::problems()
#        genau die Befunde der offenen Schritte (Login und Publish fail-closed); die Brücke (Stand vor oder nach 007 je
#        Tabelle ok) bleibt die Sicht des Migrationswerkzeugs und des AS4a-Codes (Rückfallstand, as4b_test.php X02/X04).
#
# Aufruf (als root auf dem Testsystem): bash migration007_test.sh <App-Verzeichnis> <Basis-Schema.sql>
#   <Basis-Schema.sql> = Schema vor Migration 002 (wie run.sh); Migrationen 002–006 mit den Skripten des App-Commits.
#
# Der Advisory-Lock 'dnsmanager_publish_all' ist serverweit: R08–R10 belegen ihn bzw. halten ihn über die Migration nur
# wenige Sekunden. Metadatensperren betreffen ausschließlich die isolierten Testdatenbanken.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash migration007_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=owned.sh
source "$H/owned.sh"
MIG7="$APP/scripts/migrate_007_journal_agent_results.sh"
MIGDIR="$APP/sql/migrations"
LOCK="dnsmanager_publish_all"
W="$(mktemp -d /tmp/dnsmgr-as4a-mig7.XXXXXX)"; chmod 700 "$W"
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

# Journal-Testdaten (Lauf, Versuch, zwei Zielzeilen, Cleanup), damit die Kennwerte nicht leer sind
SEED="INSERT INTO publish_runs (kind, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id, status, result, finished_at)
  VALUES ('all', 0, 1, 0, 'system', 'cli', '11111111-1111-4111-8111-111111111111', 'finished', 'success', NOW(6));
INSERT INTO zone_publish_attempts (run_id, zone_id, zone_name, revision_basis, content_hash, snapshot_contract, render_hash, file_hash, render_contract, soa_serial, target_count, outcome, finished_at)
  SELECT 1, id, name, 'no_head', REPEAT('a', 64), 'zs1', REPEAT('b', 64), REPEAT('c', 64), 'rh1', 2026112001, 2, 'published', NOW(6) FROM zones ORDER BY id LIMIT 1;
INSERT INTO zone_publish_targets (attempt_id, server_id, server_name, zone_sync, zone_sync_at, conf_sync, conf_sync_at, conf_hash)
  VALUES (1, 1, 'ns1.test', 'ok', NOW(6), 'ok', NOW(6), REPEAT('d', 64));
INSERT INTO zone_publish_targets (attempt_id, server_id, server_name, zone_sync, zone_sync_at, zone_http_status, zone_error_code, zone_error_detail)
  VALUES (1, 2, 'ns2.test', 'unknown', NOW(6), NULL, 'timeout', 'curl 28');
INSERT INTO publish_cleanup_results (run_id, server_id, server_name, status, finished_at, valid_zone_count, removed_conf_count, removed_zone_file_count, http_status)
  VALUES (1, 1, 'ns1.test', 'ok', NOW(6), 3, 0, 0, 200);"

mkdb() { # <Name> <base|m2|m5|m6|new>
    local d="${H2A_PREFIX}$1" m
    owned_create_db "$d" >&2 || exit 1
    case "$2" in
        new) grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$d" ;;
        *)   mariadb "$d" < "$BASE_SCHEMA" ;;
    esac
    mariadb "$d" < "$H/fixture.sql"
    if [ "$2" = m2 ] || [ "$2" = m5 ] || [ "$2" = m6 ]; then
        bash "$APP/scripts/migrate_002_history_foundation.sh" --db "$d" --apply --no-backup > "$W/m.out" 2>&1 || { echo "ABBRUCH: Migration 002 auf $d" >&2; exit 1; }
    fi
    if [ "$2" = m5 ] || [ "$2" = m6 ]; then
        for m in migrate_003_history_dependencies migrate_004_audit_events migrate_005_publish_journal; do
            bash "$APP/scripts/$m.sh" --db "$d" --apply --no-backup > "$W/m.out" 2>&1 || { echo "ABBRUCH: $m auf $d" >&2; exit 1; }
        done
        mariadb "$d" -e "$SEED" || { echo "ABBRUCH: Journal-Testdaten auf $d" >&2; exit 1; }
    fi
    if [ "$2" = m6 ]; then
        bash "$APP/scripts/migrate_006_zone_serial_verification.sh" --db "$d" --apply --no-backup > "$W/m.out" 2>&1 || { echo "ABBRUCH: Migration 006 auf $d" >&2; exit 1; }
    fi
    echo "$d"
}
hascol() { mariadb -N -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = '$2' AND COLUMN_NAME = '$3'"; }
gen() { # <db> ⇒ "a b c" mit 1 = Schritt ausgeführt
    echo "$(hascol "$1" zone_publish_targets zone_protocol) $(hascol "$1" publish_cleanup_results cleanup_protocol) $(hascol "$1" system_status fence_domain)"
}
ctime() { mariadb -N -e "SELECT IFNULL(CREATE_TIME, '-') FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = '$2'"; }
state() { php "$H/schemacheck.php" "$APP" "$1" | grep "^$2"$'\t' | cut -f2; }
problems() { php "$H/schemacheck.php" "$APP" "$1" | grep '^problem' | grep -v 'zone_serial_references fehlt$' | cut -f2- | tr '\n' ';'; }   # AS5: 008 ausgefiltert
# AS4b: Befunde in Kurzform (Journaltabellen vor 007 ⇒ "<Tabelle>:vor007") und die nach gen() erwarteten Befunde
problems7() { php "$H/schemacheck.php" "$APP" "$1" | grep '^problem' | grep -v 'zone_serial_references fehlt$' | cut -f2- \
    | sed -E 's/^(zone_publish_targets): Struktur weicht ab \(erwartet col\|zone_protocol\|.*$/\1:vor007/; s/^(publish_cleanup_results): Struktur weicht ab \(erwartet col\|cleanup_protocol\|.*$/\1:vor007/' | tr '\n' ';'; }
expect7() { # <db> ⇒ erwartete Befunde der offenen Schritte (Reihenfolge wie SchemaCheck: system_status, dann Journaltabellen)
    local a b c; read -r a b c <<< "$(gen "$1")"
    [ "$c" = 1 ] || printf '%s;' 'system_status.fence_domain fehlt'
    [ "$a" = 1 ] || printf '%s;' 'zone_publish_targets:vor007'
    [ "$b" = 1 ] || printf '%s;' 'publish_cleanup_results:vor007'
}
tool_ok() { [ "$(state "$1" zone_publish_targets)" = ok ] && [ "$(state "$1" publish_cleanup_results)" = ok ]; }   # Werkzeugsicht (Brücke)
journal() { # unabhängiger Vergleichswert aller bisherigen Journal- und Statusspalten
    mariadb -N -B "$1" -e "SELECT attempt_id, server_id, server_name, agent_protocol, zone_sync, zone_sync_at, zone_http_status, zone_error_code, zone_error_detail, conf_sync, conf_sync_at, conf_http_status, conf_error_code, conf_error_detail, conf_hash FROM zone_publish_targets ORDER BY attempt_id, server_id;
      SELECT run_id, server_id, server_name, agent_protocol, registered_at, status, finished_at, valid_zone_count, removed_conf_count, removed_zone_file_count, http_status, error_code, error_detail FROM publish_cleanup_results ORDER BY run_id, server_id;
      SELECT id, bind_dirty FROM system_status" | sha256sum | cut -c1-64
}
lockfree() { mariadb -N -e "SELECT IS_FREE_LOCK('$LOCK')"; }
apply_step() { # <db> <a|b|c>
    case "$2" in a) mariadb "$1" < "$MIGDIR/007a_zone_publish_targets_agent.sql" ;; b) mariadb "$1" < "$MIGDIR/007b_publish_cleanup_results_agent.sql" ;; c) mariadb "$1" < "$MIGDIR/007c_system_status_fence_domain.sql" ;; esac
}
# Notfall-Rückbau (sql/migrations/README.md, Abschnitt 007)
RB_A="ALTER TABLE zone_publish_targets DROP CONSTRAINT chk_zpt_zone_agent, DROP CONSTRAINT chk_zpt_zone_installed, DROP CONSTRAINT chk_zpt_zone_fence, DROP CONSTRAINT chk_zpt_conf_agent, DROP CONSTRAINT chk_zpt_conf_fence, DROP CONSTRAINT chk_zpt_conf_verify, DROP CONSTRAINT chk_zpt_conf_verify_fmt, DROP COLUMN zone_protocol, DROP COLUMN zone_effect, DROP COLUMN zone_installed_serial, DROP COLUMN zone_installed_hash, DROP COLUMN zone_reload, DROP COLUMN zone_fence_decision, DROP COLUMN zone_fence_code, DROP COLUMN conf_protocol, DROP COLUMN conf_effect, DROP COLUMN conf_reload, DROP COLUMN conf_fence_decision, DROP COLUMN conf_fence_code, DROP COLUMN conf_verify_state, DROP COLUMN conf_verify_reason, DROP COLUMN conf_verify_file_serial, DROP COLUMN conf_verify_file_hash, DROP COLUMN conf_verify_served_serial, ALGORITHM=COPY, LOCK=SHARED"
RB_B="ALTER TABLE publish_cleanup_results DROP CONSTRAINT chk_pcr_agent, DROP CONSTRAINT chk_pcr_fence, DROP CONSTRAINT chk_pcr_served, DROP COLUMN cleanup_protocol, DROP COLUMN cleanup_effect, DROP COLUMN fence_decision, DROP COLUMN fence_code, DROP COLUMN served_absent_count, DROP COLUMN served_still_count, DROP COLUMN served_unknown_count, DROP COLUMN unassigned_count, ALGORITHM=COPY, LOCK=SHARED"
RB_C="ALTER TABLE system_status DROP CONSTRAINT chk_ss_fence_domain, DROP COLUMN fence_domain, ALGORITHM=COPY, LOCK=SHARED"

echo "Migration 007 – Lauf-Kennung $H2A_RUN"
[ "$(lockfree)" = 1 ] || { echo "ABBRUCH: Publish-Sperre belegt"; exit 3; }

# R01 Migration 006 fehlt: Abbruch vor jeder Änderung
D=$(mkdb r1 m5)
expect_exit 2 "R01 --check ohne Migration 006" bash "$MIG7" --db "$D" --check
expect_exit 2 "R01b --apply ohne Migration 006 abgelehnt" bash "$MIG7" --db "$D" --apply --no-backup
ok "$([ "$(gen "$D")" = "0 0 0" ] && echo 1)" "R01c keine DDL"

# R02 Grundablauf mit Sicherung; Sitzungsparameter und Lock auf derselben Verbindung; Kennwerte
D=$(mkdb r2 m6)
expect_exit 1 "R02 --check nach Migration 006: Migration 007 erforderlich (a, b, c offen)" bash "$MIG7" --db "$D" --check
ok "$(grep -q 'Schritt a: offen, Schritt b: offen, Schritt c: offen' "$W/out" && echo 1)" "R02b Plan a, b, c"
ok "$([ "$(problems7 "$D")" = 'system_status.fence_domain fehlt;zone_publish_targets:vor007;publish_cleanup_results:vor007;' ] && tool_ok "$D" && echo 1)" "R02c Schema vor 007: SchemaCheck (AS4b) fail-closed mit genau den drei Befunden, Werkzeugsicht ok"
expect_exit 2 "R02d --no-backup für Nicht-Testdatenbank abgelehnt" bash "$MIG7" --db dnsmanager --apply --no-backup
before="$(journal "$D")"
expect_exit 0 "R02e --apply mit Sicherung" bash "$MIG7" --db "$D" --apply --backup-dir "$W/bak"
cp "$W/out" "$W/r2.out"
bf="$(ls "$W/bak"/*-vor-migration-007-*.sql 2>/dev/null | head -1)"
ok "$([ -n "$bf" ] && [ "$(stat -c %a "$bf")" = 600 ] && [ "$(stat -c %a "$W/bak")" = 700 ] && grep -q 'CREATE TABLE `zone_publish_targets`' "$bf" && tail -1 "$bf" | grep -q '^-- Dump completed' && echo 1)" "R02f Sicherung 600 in 700, vollständig"
ok "$([ "$(gen "$D")" = "1 1 1" ] && [ "$(state "$D" zone_publish_targets)" = ok ] && [ "$(state "$D" publish_cleanup_results)" = ok ] && [ -z "$(problems "$D")" ] && echo 1)" "R02g alle Schritte ausgeführt, SchemaCheck ohne Befund (nach 007)"
ok "$([ "$(journal "$D")" = "$before" ] && grep -q '^Journal und Systemstatus unverändert' "$W/r2.out" && grep -q '^Neue Spalten mit Wert: 0' "$W/r2.out" && echo 1)" "R02h Journal- und Statuswerte unverändert (unabhängig und im Skript), neue Spalten leer"
pre="$(grep -oE 'PRE\|[0-9|]+' "$W/r2.out")"
ok "$([ "$(echo "$pre" | cut -d'|' -f2)" = 2 ] && [ "$(echo "$pre" | cut -d'|' -f4)" = 1 ] && echo 1)" "R02i Kennwerte über nichtleere Tabellen ($pre)"
sess="$(grep -oE 'SESSION\|[0-9]+\|[0-9]+\|[A-Z_,]+' "$W/r2.out")"; lockl="$(grep -oE 'LOCK\|[^ ]+' "$W/r2.out")"
IFS='|' read -r _ s_conn s_lwt s_mode <<< "$sess"; IFS='|' read -r _ l_got l_holder l_conn <<< "$lockl"
ok "$([ "$s_lwt" = 10 ] && [[ "$s_mode" == *STRICT_ALL_TABLES* ]] && [ "$l_got" = 1 ] && [ "$l_holder" = "$s_conn" ] && [ "$l_conn" = "$s_conn" ] && echo 1)" "R02j lock_wait_timeout 10, strikter sql_mode und Lock auf derselben Verbindung ($sess / $lockl)"
ok "$(grep -q '^  RELEASE|1$' "$W/r2.out" && [ "$(lockfree)" = 1 ] && echo 1)" "R02k Lock freigegeben"
expect_exit 0 "R02l --check danach" bash "$MIG7" --db "$D" --check

# R03 vollständig migriert: keine weitere DDL
sleep 1.2
c1="$(ctime "$D" zone_publish_targets)"; c2="$(ctime "$D" publish_cleanup_results)"; c3="$(ctime "$D" system_status)"
expect_exit 0 "R03 --apply erneut" bash "$MIG7" --db "$D" --apply --no-backup
ok "$(grep -q 'Nichts zu tun' "$W/out" && ! grep -q 'Geschützter Teil' "$W/out" && [ "$(ctime "$D" zone_publish_targets)" = "$c1" ] && [ "$(ctime "$D" publish_cleanup_results)" = "$c2" ] && [ "$(ctime "$D" system_status)" = "$c3" ] && echo 1)" "R03b keine DDL (CREATE_TIME aller drei Tabellen unverändert)"

# R04 alle Teilzustände: nur die fehlenden Schritte werden ausgeführt; die Werkzeugsicht akzeptiert jeden Teilzustand,
#     die Anwendung (AS4b) meldet genau die offenen Schritte
for subset in a b c ab ac bc; do
    D=$(mkdb "r4$subset" m6)
    for s in $(echo "$subset" | grep -o .); do apply_step "$D" "$s"; done
    ok "$([ "$(problems7 "$D")" = "$(expect7 "$D")" ] && [ -n "$(expect7 "$D")" ] && tool_ok "$D" && echo 1)" "R04 Teilzustand {$subset}: SchemaCheck (AS4b) meldet genau die offenen Schritte ($(expect7 "$D")), Werkzeugsicht ok"
    sleep 1.2
    declare -A CT=( [a]="$(ctime "$D" zone_publish_targets)" [b]="$(ctime "$D" publish_cleanup_results)" [c]="$(ctime "$D" system_status)" )
    expect_exit 1 "R04b Teilzustand {$subset}: --check meldet offene Schritte" bash "$MIG7" --db "$D" --check
    expect_exit 0 "R04c Teilzustand {$subset}: --apply" bash "$MIG7" --db "$D" --apply --no-backup
    good=1
    for s in a b c; do
        if [[ "$subset" == *"$s"* ]]; then
            grep -q "STEP|$s" "$W/out" && good=0
            t=$(case $s in a) echo zone_publish_targets ;; b) echo publish_cleanup_results ;; c) echo system_status ;; esac)
            [ "$(ctime "$D" "$t")" = "${CT[$s]}" ] || good=0
        else
            grep -q "STEP|$s" "$W/out" || good=0
        fi
    done
    ok "$([ "$good" = 1 ] && [ "$(gen "$D")" = "1 1 1" ] && [ -z "$(problems "$D")" ] && echo 1)" "R04d Teilzustand {$subset}: genau die fehlenden Schritte ausgeführt, vorhandene unverändert"
done

# R05 abweichende Struktur ⇒ Skript Exit 2 ohne DDL, SchemaCheck mismatch
MUT=0
mutate() { # <Fall> <Tabelle> <SQL nach Schritt a und b> – je Fall eine eigene Datenbank
    MUT=$((MUT + 1)); D=$(mkdb "r5m$MUT" m6)
    apply_step "$D" a; apply_step "$D" b
    mariadb "$D" -e "$3" || { ok 0 "R05 $1: Mutation nicht anwendbar"; return; }
    local c="$(ctime "$D" system_status)"
    expect_exit 2 "R05 $1: Skript" bash "$MIG7" --db "$D" --apply --no-backup
    ok "$([ "$(state "$D" "$2")" = mismatch ] && [ "$(hascol "$D" system_status fence_domain)" = 0 ] && [ "$(ctime "$D" system_status)" = "$c" ] && echo 1)" "R05b $1: SchemaCheck mismatch, keine DDL"
}
mutate "CHECK entfernt" zone_publish_targets "ALTER TABLE zone_publish_targets DROP CONSTRAINT chk_zpt_conf_verify"
mutate "CHECK in NULL-unsicherer Form (K1) bei gleichem Namen" zone_publish_targets "ALTER TABLE zone_publish_targets DROP CONSTRAINT chk_zpt_zone_agent, ADD CONSTRAINT chk_zpt_zone_agent CHECK ((zone_protocol IS NULL AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL) OR (zone_protocol = 'legacy' AND zone_sync IN ('ok','rejected','unknown') AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL) OR (zone_protocol = 'as2' AND zone_sync IN ('ok','rejected','unknown') AND zone_effect IS NOT NULL))"
mutate "ENUM-Wert ergänzt" zone_publish_targets "ALTER TABLE zone_publish_targets MODIFY zone_protocol ENUM('legacy','as2','as3') CHARACTER SET ascii COLLATE ascii_bin NULL"
mutate "Spalte NOT NULL" publish_cleanup_results "ALTER TABLE publish_cleanup_results DROP CONSTRAINT chk_pcr_served; UPDATE publish_cleanup_results SET unassigned_count = 0; ALTER TABLE publish_cleanup_results MODIFY unassigned_count INT UNSIGNED NOT NULL DEFAULT 0"
mutate "zusätzliche Spalte" publish_cleanup_results "ALTER TABLE publish_cleanup_results ADD COLUMN x INT NULL"
mutate "Mischform (nur ein Teil der Spalten aus 007a)" zone_publish_targets "ALTER TABLE zone_publish_targets DROP CONSTRAINT chk_zpt_conf_verify, DROP CONSTRAINT chk_zpt_conf_verify_fmt, DROP COLUMN conf_verify_served_serial"
mutate "agent_protocol verändert" zone_publish_targets "ALTER TABLE zone_publish_targets MODIFY agent_protocol ENUM('legacy','as2') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy'"
# system_status abweichend
D=$(mkdb r5s m6)
mariadb "$D" -e "ALTER TABLE system_status ADD COLUMN fence_domain CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL"
expect_exit 2 "R05c system_status.fence_domain mit anderem Typ: Skript" bash "$MIG7" --db "$D" --apply --no-backup
ok "$([ "$(gen "$D")" = "0 0 1" ] && grep -q 'fence_domain vorhanden, aber abweichend' "$W/out" && echo 1)" "R05d keine DDL, Abweichung benannt"
mariadb "$D" -e "ALTER TABLE system_status DROP COLUMN fence_domain"; apply_step "$D" c
mariadb "$D" -e "ALTER TABLE system_status DROP CONSTRAINT chk_ss_fence_domain, ADD CONSTRAINT chk_ss_fence_domain CHECK (fence_domain IS NULL OR fence_domain REGEXP '^[0-9a-f-]{36}\$')"
expect_exit 2 "R05e chk_ss_fence_domain mit anderem Ausdruck: Skript" bash "$MIG7" --db "$D" --apply --no-backup
ok "$([ "$(gen "$D")" = "0 0 1" ] && echo 1)" "R05f keine DDL"

# R06 abweichende Vorgängertabelle (zone_server_verification) ⇒ Exit 2
D=$(mkdb r6 m6)
mariadb "$D" -e "ALTER TABLE zone_server_verification ADD COLUMN x INT NULL"
expect_exit 2 "R06 zone_server_verification abweichend: Skript" bash "$MIG7" --db "$D" --apply --no-backup
ok "$([ "$(gen "$D")" = "0 0 0" ] && echo 1)" "R06b keine DDL"

# R07 Publish-Lock belegt ⇒ Exit 2 ohne DDL
D=$(mkdb r7 m6)
mariadb -N -e "SELECT GET_LOCK('$LOCK', 0), SLEEP(6)" > /dev/null & BG+=($!)
sleep 1
expect_exit 2 "R07 Publish-Lock belegt" bash "$MIG7" --db "$D" --apply --no-backup
ok "$(grep -q 'MIG007_LOCK_BUSY' "$W/out" && [ "$(gen "$D")" = "0 0 0" ] && echo 1)" "R07b keine DDL"
wait; BG=()

# R08 Lock-Besitz während des Wartens auf die Metadatensperre (offene Transaktion auf zone_publish_targets, 5 s)
D=$(mkdb r8 m6)
mariadb -N "$D" -e "START TRANSACTION; SELECT COUNT(*) FROM zone_publish_targets; SELECT SLEEP(5); COMMIT;" > /dev/null & BG+=($!)
sleep 0.5
bash "$MIG7" --db "$D" --apply --no-backup > "$W/r8.out" 2>&1 & mpid=$!
sleep 2.5
obs="$(mariadb -N -e "SELECT IS_USED_LOCK('$LOCK'), (SELECT CONCAT(STATE, '|', LEFT(INFO, 40)) FROM information_schema.PROCESSLIST WHERE ID = IS_USED_LOCK('$LOCK'))")"
wait "$mpid"; mrc=$?
wait; BG=()
holder="${obs%%$'\t'*}"; pstate="${obs#*$'\t'}"
sconn="$(grep -oE 'SESSION\|[0-9]+' "$W/r8.out" | cut -d'|' -f2)"
ok "$([ "$mrc" = 0 ] && [ -n "$holder" ] && [ "$holder" = "$sconn" ] && [[ "$pstate" == *"metadata lock"*"ALTER TABLE zone_publish_targets"* ]] && echo 1)" "R08 während des Wartens hält die ALTER-Verbindung den Publish-Lock (Halter $holder = Sitzung $sconn; $pstate)"
ok "$([ "$(lockfree)" = 1 ] && [ "$(gen "$D")" = "1 1 1" ] && echo 1)" "R08b danach vollständig migriert, Lock frei"

# R09 Metadatensperre auf publish_cleanup_results länger als 10 s ⇒ Schritt a erledigt, b abgebrochen, Exit 3, Wiederanlauf
D=$(mkdb r9 m6)
mariadb -N "$D" -e "START TRANSACTION; SELECT COUNT(*) FROM publish_cleanup_results; SELECT SLEEP(18); COMMIT;" > /dev/null & BG+=($!)
sleep 0.5
t0=$(date +%s)
expect_exit 3 "R09 Metadatensperre: Abbruch" bash "$MIG7" --db "$D" --apply --no-backup
dt=$(( $(date +%s) - t0 ))
ok "$([ "$dt" -ge 9 ] && [ "$dt" -le 16 ] && grep -q 'Lock wait timeout' "$W/out" && grep -q 'Teilzustand: begonnene Schritte: a b' "$W/out" && echo 1)" "R09b nach ≈ 10 s ($dt s), Teilzustand gemeldet"
ok "$([ "$(gen "$D")" = "1 0 0" ] && [ "$(problems7 "$D")" = "$(expect7 "$D")" ] && tool_ok "$D" && [ "$(lockfree)" = 1 ] && echo 1)" "R09c Schritt a vollständig, b atomar nicht ausgeführt, AS4b meldet genau b und c, Werkzeugsicht ok, Lock frei"
wait; BG=()
expect_exit 0 "R09d Wiederanlauf" bash "$MIG7" --db "$D" --apply --no-backup
ok "$(! grep -q 'STEP|a' "$W/out" && grep -q 'STEP|b' "$W/out" && grep -q 'STEP|c' "$W/out" && [ "$(gen "$D")" = "1 1 1" ] && echo 1)" "R09e nur Schritte b und c nachgeholt"

# R10 Zustand ändert sich zwischen Preflight und Prüfung unter dem Lock (Kopie mit falschem Plan) ⇒ keine DDL
D=$(mkdb r10 m6)
mkdir -p "$W/app10"; cp -a "$APP/." "$W/app10/"
sed -i 's/^NEED_A=0; \[ "\$GEN_A" = "pre" \] && NEED_A=1$/NEED_A=1/' "$W/app10/scripts/migrate_007_journal_agent_results.sh"
apply_step "$D" a
grep -q '^NEED_A=1$' "$W/app10/scripts/migrate_007_journal_agent_results.sh" || ok 0 "R10 Vorbereitung der Kopie"
sleep 1.2; c1="$(ctime "$D" publish_cleanup_results)"
expect_exit 2 "R10 Prüfung unter dem Lock erkennt abweichenden Zustand" bash "$W/app10/scripts/migrate_007_journal_agent_results.sh" --db "$D" --apply --no-backup
ok "$(grep -q 'MIG007_STATE_CHANGED' "$W/out" && [ "$(ctime "$D" publish_cleanup_results)" = "$c1" ] && [ "$(gen "$D")" = "1 0 0" ] && [ "$(lockfree)" = 1 ] && echo 1)" "R10b keine DDL, Lock frei"

# R11 Lock-Verlust zwischen den Schritten (Kopie gibt den Lock vor Schritt b frei) ⇒ Abbruch, b nicht ausgeführt
D=$(mkdb r11 m6)
mkdir -p "$W/app11"; cp -a "$APP/." "$W/app11/"
php -r '$f = $argv[1]; $s = file_get_contents($f); $m = "    if [ \"\$NEED_B\" -eq 1 ]; then\n        lockcheck\n";
        if (substr_count($s, $m) !== 1) { exit(1); }
        $s = str_replace($m, "    if [ \"\$NEED_B\" -eq 1 ]; then\n        echo \"SELECT RELEASE_LOCK('\''\$LOCK_NAME'\'');\"\n        lockcheck\n", $s);
        file_put_contents($f, $s);' "$W/app11/scripts/migrate_007_journal_agent_results.sh" || ok 0 "R11 Vorbereitung der Kopie"
expect_exit 3 "R11 Lock-Verlust vor Schritt b" bash "$W/app11/scripts/migrate_007_journal_agent_results.sh" --db "$D" --apply --no-backup
ok "$(grep -q 'MIG007_LOCK_LOST' "$W/out" && [ "$(gen "$D")" = "1 0 0" ] && [ "$(lockfree)" = 1 ] && echo 1)" "R11b nur Schritt a ausgeführt, Lock frei"
expect_exit 0 "R11c Wiederanlauf mit unverändertem Skript" bash "$MIG7" --db "$D" --apply --no-backup

# R12 Neuinstallation = Migration 002–007 (Struktursignatur und system_status)
D=$(mkdb r12 new)
expect_exit 0 "R12 Neuinstallation: --check ohne Migration" bash "$MIG7" --db "$D" --check
sig() { php "$APP/web/scripts/schema_history-cli.php" sql | mariadb -N -B "$1"; }
bash "$APP/scripts/migrate_008_zone_serial_references.sh" --db "${H2A_PREFIX}r2" --apply --no-backup > "$W/m8.out" 2>&1 || ok 0 "R12b Vorbereitung: Migration 008 auf r2"   # AS5
ss() { mariadb -N -B -e "SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), IFNULL(COLLATION_NAME, '~') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = 'system_status' ORDER BY ORDINAL_POSITION; SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$1' AND TABLE_NAME = 'system_status' ORDER BY CONSTRAINT_NAME"; }
ok "$([ "$(sig "$D")" = "$(sig "${H2A_PREFIX}r2")" ] && [ "$(ss "$D")" = "$(ss "${H2A_PREFIX}r2")" ] && [ -z "$(problems "$D")" ] && echo 1)" "R12b Neuinstallation = Migration 002–007 (Signatur und system_status byte-gleich), SchemaCheck ohne Befund"

# R13 frühere Skripte (Brückencode) vor und nach 007 konsistent
D=$(mkdb r13 m6)
for m in migrate_002_history_foundation migrate_003_history_dependencies migrate_004_audit_events migrate_005_publish_journal migrate_006_zone_serial_verification; do
    expect_exit 0 "R13 $m --check vor 007" bash "$APP/scripts/$m.sh" --db "$D" --check
done
bash "$MIG7" --db "$D" --apply --no-backup > /dev/null 2>&1
for m in migrate_002_history_foundation migrate_003_history_dependencies migrate_004_audit_events migrate_005_publish_journal migrate_006_zone_serial_verification; do
    expect_exit 0 "R13b $m --check nach 007" bash "$APP/scripts/$m.sh" --db "$D" --check
done

# R14 Notfall-Rückbau nach README (nur solange alle neuen Spalten leer sind) und erneute Anwendung
D=$(mkdb r14 m6)
before="$(journal "$D")"
bash "$MIG7" --db "$D" --apply --no-backup > /dev/null 2>&1
nz="$(mariadb -N "$D" -e "SELECT (SELECT COUNT(*) FROM zone_publish_targets WHERE COALESCE(zone_protocol, zone_effect, zone_installed_serial, zone_installed_hash, zone_reload, zone_fence_decision, zone_fence_code, conf_protocol, conf_effect, conf_reload, conf_fence_decision, conf_fence_code, conf_verify_state, conf_verify_reason, conf_verify_file_serial, conf_verify_file_hash, conf_verify_served_serial) IS NOT NULL) + (SELECT COUNT(*) FROM publish_cleanup_results WHERE COALESCE(cleanup_protocol, cleanup_effect, fence_decision, fence_code, served_absent_count, served_still_count, served_unknown_count, unassigned_count) IS NOT NULL) + (SELECT COUNT(*) FROM system_status WHERE fence_domain IS NOT NULL)")"
ok "$([ "$nz" = 0 ] && echo 1)" "R14 Vorbedingung des Rückbaus: alle neuen Spalten leer"
mariadb "$D" -e "SET SESSION sql_mode = 'STRICT_ALL_TABLES'; $RB_A; $RB_B; $RB_C" > "$W/out" 2>&1
ok "$([ "$(gen "$D")" = "0 0 0" ] && [ "$(problems7 "$D")" = "$(expect7 "$D")" ] && tool_ok "$D" && [ "$(journal "$D")" = "$before" ] && echo 1)" "R14b Rückbau: Struktur vor 007, AS4b fail-closed (drei Befunde), Werkzeugsicht ok, Journalwerte unverändert"
expect_exit 1 "R14c nach Rückbau: --check meldet Migration 007 erforderlich" bash "$MIG7" --db "$D" --check
expect_exit 0 "R14d erneut anwendbar" bash "$MIG7" --db "$D" --apply --no-backup

echo "ERGEBNIS migration007: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
