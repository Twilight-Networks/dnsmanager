#!/bin/bash
# Datei: tests/history/migration008_test.sh
# Zweck: Tests der Migration 008 (scripts/migrate_008_zone_serial_references.sh, AS5) auf isolierten Datenbanken
#        dnsmgr_t21_r<RUN>_t* (Eigentum nach tests/history/owned.sh): Voraussetzung 007, Plan, Sicherung, Lock, Kennwerte,
#        abweichende Struktur, Neuinstallation = Migration, frühere Skripte nach 008, Notfall-Rückbau, Code × Schema
#        (AS5 verlangt 008 strikt; der AS4b-Code bleibt auf Schema 008 ohne Befund).
#
# Aufruf (als root auf dem Testsystem): bash migration008_test.sh <App-Verzeichnis> <Basis-Schema.sql> <AS4b-App-Verzeichnis>
#   <Basis-Schema.sql> = Schema vor Migration 002 (wie run.sh); Migrationen 002–007 mit den Skripten des App-Commits.
#
# Der Advisory-Lock 'dnsmanager_publish_all' ist serverweit: T07 belegt ihn kurz.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"; AS4B="${3:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] && [ -d "$AS4B/web" ] || { echo "Aufruf: bash migration008_test.sh <App-Verzeichnis> <Basis-Schema.sql> <AS4b-App-Verzeichnis>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=owned.sh
source "$H/owned.sh"
MIG8="$APP/scripts/migrate_008_zone_serial_references.sh"
LOCK="dnsmanager_publish_all"
W="$(mktemp -d /tmp/dnsmgr-as5-mig8.XXXXXX)"; chmod 700 "$W"
owned_init "$W/owned"
PASS=0; FAIL=0
cleanup() { owned_cleanup; rm -rf "$W"; }
trap cleanup EXIT
ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }
expect_exit() { # <erwartet> <Fall> <Befehl…>
    local want="$1" id="$2"; shift 2
    "$@" > "$W/out" 2>&1; local rc=$?
    if [ "$rc" = "$want" ]; then ok 1 "$id Exit $rc"; else ok 0 "$id Exit $rc statt $want"; sed 's/^/       /' "$W/out"; fi
}
mkdb() { # <Name> <m6|m7|new>
    local d="${H2A_PREFIX}$1" m
    owned_create_db "$d" >&2 || exit 1
    if [ "$2" = new ]; then
        grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$d"
        mariadb "$d" < "$H/fixture.sql"
    else
        mariadb "$d" < "$BASE_SCHEMA"
        mariadb "$d" < "$H/fixture.sql"
        local list="migrate_002_history_foundation migrate_003_history_dependencies migrate_004_audit_events migrate_005_publish_journal migrate_006_zone_serial_verification"
        [ "$2" = m7 ] && list="$list migrate_007_journal_agent_results"
        for m in $list; do
            bash "$APP/scripts/$m.sh" --db "$d" --apply --no-backup > "$W/m.out" 2>&1 || { echo "ABBRUCH: $m auf $d" >&2; cat "$W/m.out" >&2; exit 1; }
        done
    fi
    echo "$d"
}
has() { mariadb -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = 'zone_serial_references'"; }
ctime() { mariadb -N -e "SELECT IFNULL(CREATE_TIME, '-') FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = 'zone_serial_references'"; }
problems() { php "$H/schemacheck.php" "$1" "$2" | grep '^problem' | cut -f2- | tr '\n' ';'; }
lockfree() { mariadb -N -e "SELECT IS_FREE_LOCK('$LOCK')"; }
sig() { php "$APP/web/scripts/schema_history-cli.php" sql | mariadb -N -B "$1"; }

echo "Migration 008 – Lauf-Kennung $H2A_RUN"
[ "$(lockfree)" = 1 ] || { echo "ABBRUCH: Publish-Sperre belegt"; exit 3; }

# T01 Migration 007 fehlt ⇒ Abbruch vor jeder Änderung
D=$(mkdb t1 m6)
expect_exit 2 "T01 --check ohne Migration 007" bash "$MIG8" --db "$D" --check
expect_exit 2 "T01b --apply ohne Migration 007 abgelehnt" bash "$MIG8" --db "$D" --apply --no-backup
ok "$([ "$(has "$D")" = 0 ] && echo 1)" "T01c keine DDL"

# T02 Grundablauf mit Sicherung
D=$(mkdb t2 m7)
expect_exit 1 "T02 --check nach 007: Migration 008 erforderlich" bash "$MIG8" --db "$D" --check
ok "$([ "$(problems "$APP" "$D")" = 'zone_serial_references fehlt;' ] && echo 1)" "T02b vor 008: AS5-SchemaCheck fail-closed mit genau einem Befund"
ok "$([ -z "$(problems "$AS4B" "$D")" ] && echo 1)" "T02c vor 008: AS4b-Code ohne Befund"
expect_exit 2 "T02d --no-backup für Nicht-Testdatenbank abgelehnt" bash "$MIG8" --db dnsmanager --apply --no-backup
expect_exit 0 "T02e --apply mit Sicherung" bash "$MIG8" --db "$D" --apply --backup-dir "$W/bak"
f="$(ls "$W/bak"/*-vor-migration-008-*.sql 2>/dev/null | head -1)"
ok "$([ -n "$f" ] && [ "$(stat -c %a "$f")" = 600 ] && [ "$(stat -c %a "$W/bak")" = 700 ] && echo 1)" "T02f Sicherung 600 in 700"
ok "$(grep -q '^  SESSION|[0-9]*|10|.*STRICT_ALL_TABLES' "$W/out" && grep -q '^  LOCK|1|' "$W/out" && grep -q 'Journal, Verification Store und Änderungsmengen unverändert' "$W/out" && echo 1)" "T02g Sitzungsparameter, Lock-Besitz und Kennwerte bestätigt"
ok "$([ "$(has "$D")" = 1 ] && [ -z "$(problems "$APP" "$D")" ] && [ -z "$(problems "$AS4B" "$D")" ] && [ "$(lockfree)" = 1 ] && echo 1)" "T02h nach 008: AS5 und AS4b ohne Befund, Lock frei"

# T03 wiederholbar ohne DDL
c0="$(ctime "$D")"
expect_exit 0 "T03 --check nach 008" bash "$MIG8" --db "$D" --check
expect_exit 0 "T03b erneutes --apply: keine DDL" bash "$MIG8" --db "$D" --apply --no-backup
ok "$([ "$(ctime "$D")" = "$c0" ] && grep -q 'Nichts zu tun' "$W/out" && echo 1)" "T03c Tabelle unverändert (CREATE_TIME)"

# T04 abweichende Struktur ⇒ Exit 2 ohne Änderung
D=$(mkdb t4 m7)
sed 's/^CREATE TABLE IF NOT EXISTS zone_serial_references/CREATE TABLE zone_serial_references/' "$APP/sql/migrations/008_zone_serial_references.sql" \
    | grep -v 'CONSTRAINT chk_zsr_order' | mariadb "$D" 2>"$W/err" || ok 0 "T04 Vorbereitung ($(cat "$W/err"))"
expect_exit 2 "T04 Tabelle ohne CHECK chk_zsr_order: Skript" bash "$MIG8" --db "$D" --apply --no-backup
ok "$(grep -q 'abweichend' "$W/out" && [[ "$(problems "$APP" "$D")" == *"zone_serial_references: Struktur weicht ab"* ]] && echo 1)" "T04b Skript und SchemaCheck erkennen die Abweichung"

# T05 Publish-Lock belegt ⇒ Exit 2 ohne DDL
D=$(mkdb t5 m7)
mariadb -N -e "SELECT GET_LOCK('$LOCK', 0); SELECT SLEEP(6);" > /dev/null &
LP=$!
sleep 1
expect_exit 2 "T05 Publish-Lock belegt" bash "$MIG8" --db "$D" --apply --no-backup
ok "$(grep -q 'MIG008_LOCK_BUSY' "$W/out" && [ "$(has "$D")" = 0 ] && echo 1)" "T05b keine DDL"
wait "$LP"

# T06 Neuinstallation = Migration 002–008
D=$(mkdb t6 new)
expect_exit 0 "T06 Neuinstallation: --check ohne Migration" bash "$MIG8" --db "$D" --check
ok "$([ "$(sig "$D")" = "$(sig "${H2A_PREFIX}t2")" ] && [ -z "$(problems "$APP" "$D")" ] && echo 1)" "T06b Struktursignatur byte-gleich zur Migration, SchemaCheck ohne Befund"

# T07 frühere Skripte nach 008 unverändert anwendbar
D="${H2A_PREFIX}t2"
for m in migrate_002_history_foundation migrate_003_history_dependencies migrate_004_audit_events migrate_005_publish_journal migrate_006_zone_serial_verification migrate_007_journal_agent_results; do
    expect_exit 0 "T07 $m --check nach 008" bash "$APP/scripts/$m.sh" --db "$D" --check
done

# T08 Notfall-Rückbau (nur bei leerer Tabelle, README Abschnitt 008) und erneute Anwendung
mariadb "$D" -e "DROP TABLE zone_serial_references"
ok "$([ "$(problems "$APP" "$D")" = 'zone_serial_references fehlt;' ] && [ -z "$(problems "$AS4B" "$D")" ] && echo 1)" "T08 nach Rückbau: AS5 fail-closed, AS4b ohne Befund"
expect_exit 0 "T08b erneut anwendbar" bash "$MIG8" --db "$D" --apply --no-backup

echo "ERGEBNIS migration008: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
