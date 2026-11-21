#!/bin/bash
# Datei: tests/history/migration003_test.sh
# Zweck: Tests der Migration 003 (scripts/migrate_003_history_dependencies.sh, AP1/H2b, E37) auf isolierten
#        Datenbanken dnsmgr_t21_r<RUN>_n* (Eigentum nach tests/history/owned.sh).
#
# Aufruf (als root auf dem Testsystem): bash migration003_test.sh <App-Verzeichnis> <Basis-Schema.sql>
#   <Basis-Schema.sql> = Schema vor Migration 002 (wie run.sh); Migration 002 wird mit dem Skript des App-Commits
#   angewendet.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash migration003_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=owned.sh
source "$H/owned.sh"
MIG2="$APP/scripts/migrate_002_history_foundation.sh"
MIG3="$APP/scripts/migrate_003_history_dependencies.sh"
W="$(mktemp -d /tmp/dnsmgr-h2a-mig3.XXXXXX)"; chmod 700 "$W"
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
mkdb() { # <Name> <base|m2|new>
    local d="${H2A_PREFIX}$1"
    owned_create_db "$d" >&2 || exit 1
    case "$2" in
        new) grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$d" ;;
        *)   mariadb "$d" < "$BASE_SCHEMA" ;;
    esac
    mariadb "$d" < "$H/fixture.sql"
    if [ "$2" = m2 ]; then bash "$MIG2" --db "$d" --apply --no-backup > "$W/m2.out" 2>&1 || { echo "ABBRUCH: Migration 002 auf $d" >&2; exit 1; }; fi
    echo "$d"
}
has_dep() { [ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = 'history_dependencies'")" = 1 ]; }
state() { php "$H/schemacheck.php" "$APP" "$1" | grep "^$2"$'\t' | cut -f2; }
# audit_events (Migration 004, AP1/H2c) prüft migration004_test.sh
problems() { php "$H/schemacheck.php" "$APP" "$1" | grep '^problem' | grep -vE '^problem.(publish_runs|zone_publish_attempts|zone_publish_targets|publish_cleanup_results) fehlt$' | grep -v 'audit_events fehlt$' | grep -v 'zone_server_verification fehlt$' | grep -v 'zones.soa_serial: Typ int(11) statt int(10) unsigned$' | cut -f2- | tr '\n' ';'; }
basecount() { mariadb -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM $1.history_change_sets), (SELECT COUNT(*) FROM $1.zone_snapshots), (SELECT COUNT(*) FROM $1.zone_revisions), (SELECT COUNT(*) FROM $1.zone_history_state))"; }

echo "== Migration 003 – $(mariadb -N -e 'SELECT VERSION()')"

# N01 ohne Migration 002: Abbruch vor jeder Änderung
D=$(mkdb n1 base)
expect_exit 2 "N01 --check ohne Migration 002" bash "$MIG3" --db "$D" --check
expect_exit 2 "N01b --apply ohne Migration 002 abgelehnt" bash "$MIG3" --db "$D" --apply --no-backup
ok "$(grep -q 'Migration 002 fehlt' "$W/out" && ! has_dep "$D" && echo 1)" "N01c Meldung, keine Tabelle angelegt"

# N02–N03 Grundablauf mit Sicherung, Wiederholbarkeit
D=$(mkdb n2 m2)
mariadb "$D" -e "INSERT INTO history_change_sets (operation, actor_type, channel) VALUES ('test.marker', 'system', 'cli')"
c0=$(basecount "$D")
expect_exit 1 "N02 --check nach Migration 002: Migration 003 erforderlich" bash "$MIG3" --db "$D" --check
ok "$([ "$(state "$D" history_dependencies)" = absent ] && [ "$(problems "$D")" = "history_dependencies fehlt;" ] && echo 1)" "N02b SchemaCheck: history_dependencies fehlt (Login fail-closed)"
expect_exit 2 "N02c --no-backup für Nicht-Testdatenbank abgelehnt" bash "$MIG3" --db dnsmanager --apply --no-backup
expect_exit 0 "N02d --apply mit Sicherung" bash "$MIG3" --db "$D" --apply --backup-dir "$W/bak"
f=$(ls "$W"/bak/*vor-migration-003-*.sql 2>/dev/null | head -1)
ok "$([ -n "$f" ] && [ "$(stat -c %a "$f")" = 600 ] && [ "$(stat -c %a "$W/bak")" = 700 ] && ! grep -q 'CREATE TABLE `history_dependencies`' "$f" && echo 1)" "N02e Sicherung vor der DDL (600/700, ohne neue Tabelle)"
ok "$(has_dep "$D" && [ "$(state "$D" history_dependencies)" = ok ] && [ -z "$(problems "$D")" ] && echo 1)" "N02f Tabelle angelegt, SchemaCheck ohne Befund"
ok "$([ "$(basecount "$D")" = "$c0" ] && echo 1)" "N02g Grundtabellen unverändert ($c0)"
expect_exit 0 "N03 --apply erneut: nichts zu tun" bash "$MIG3" --db "$D" --apply --no-backup
ok "$(grep -q 'Nichts zu tun' "$W/out" && echo 1)" "N03b Meldung"
expect_exit 0 "N03c --check danach" bash "$MIG3" --db "$D" --check

# N04 abweichende vorhandene Tabelle: Abbruch ohne Änderung
mariadb "$D" -e "ALTER TABLE history_dependencies ADD COLUMN extra INT NULL"
expect_exit 2 "N04 abweichende history_dependencies erkannt" bash "$MIG3" --db "$D" --apply --no-backup
ok "$([ "$(state "$D" history_dependencies)" = mismatch ] && echo 1)" "N04b SchemaCheck: mismatch"
mariadb "$D" -e "ALTER TABLE history_dependencies DROP COLUMN extra"
mariadb "$D" -e "ALTER TABLE history_dependencies DROP CONSTRAINT chk_hd_distinct"
expect_exit 2 "N04c fehlende CHECK-Bedingung erkannt" bash "$MIG3" --db "$D" --check
mariadb "$D" -e "ALTER TABLE history_dependencies ADD CONSTRAINT chk_hd_distinct CHECK (change_set_id <> dep_change_set_id)"
expect_exit 0 "N04d nach Rückbau wieder in Ordnung" bash "$MIG3" --db "$D" --check

# N05 Grundtabelle aus Migration 002 abweichend: keine DDL
D=$(mkdb n5 m2)
mariadb "$D" -e "ALTER TABLE zone_revisions ADD COLUMN extra INT NULL"
expect_exit 2 "N05 abweichende Grundtabelle: Abbruch" bash "$MIG3" --db "$D" --apply --no-backup
ok "$(! has_dep "$D" && echo 1)" "N05b keine Tabelle angelegt"

# N06 technischer Abbruch während der DDL (Fehlerinjektion), deterministischer Wiederanlauf
D=$(mkdb n6 m2)
cp -a "$APP" "$W/appfail"
printf 'SELECT * FROM gibt_es_nicht_h2b;\n' >> "$W/appfail/sql/migrations/003_history_dependencies.sql"
expect_exit 3 "N06 Fehler nach der CREATE-Anweisung" bash "$W/appfail/scripts/migrate_003_history_dependencies.sh" --db "$D" --apply --no-backup
expect_exit 0 "N06b Wiederanlauf mit unverändertem Skript" bash "$MIG3" --db "$D" --apply --no-backup
expect_exit 0 "N06c danach --check" bash "$MIG3" --db "$D" --check

# N07 Neuinstallation: Struktur = Migration 002 + 003
D=$(mkdb n7 new)
expect_exit 0 "N07 Neuinstallation: --check ohne Migration" bash "$MIG3" --db "$D" --check
sig() { php "$APP/web/scripts/schema_history-cli.php" sql | mariadb -N -B "$1"; }
bash "$APP/scripts/migrate_004_audit_events.sh" --db "${H2A_PREFIX}n2" --apply --no-backup > "$W/m4.out" 2>&1 || ok 0 "N07b Vorbereitung: Migration 004 auf n2"
bash "$APP/scripts/migrate_005_publish_journal.sh" --db "${H2A_PREFIX}n2" --apply --no-backup > "$W/m5.out" 2>&1 || ok 0 "N07b Vorbereitung: Migration 005 auf n2"
bash "$APP/scripts/migrate_006_zone_serial_verification.sh" --db "${H2A_PREFIX}n2" --apply --no-backup > "$W/m6.out" 2>&1 || ok 0 "N07b Vorbereitung: Migration 006 auf n2"
bash "$APP/scripts/migrate_007_journal_agent_results.sh" --db "${H2A_PREFIX}n2" --apply --no-backup > "$W/m7.out" 2>&1 || ok 0 "N07b Vorbereitung: Migration 007 auf n2"
ok "$([ "$(sig "$D")" = "$(sig "${H2A_PREFIX}n2")" ] && echo 1)" "N07b Struktursignatur Neuinstallation = Migration 002 + 003 + 004 + 005 + 006 + 007 (byte-gleich)"

# N08 Migration 002-Skript des neuen Codes bleibt nach Migration 003 konsistent
expect_exit 0 "N08 migrate_002 --check nach Migration 003" bash "$MIG2" --db "${H2A_PREFIX}n2" --check

echo "ERGEBNIS migration003: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
