#!/bin/bash
# Datei: tests/history/migration004_test.sh
# Zweck: Tests der Migration 004 (scripts/migrate_004_audit_events.sh, AP1/H2c, E45/E46) auf isolierten Datenbanken
#        dnsmgr_t21_r<RUN>_o* (Eigentum nach tests/history/owned.sh).
#
# Aufruf (als root auf dem Testsystem): bash migration004_test.sh <App-Verzeichnis> <Basis-Schema.sql>
#   <Basis-Schema.sql> = Schema vor Migration 002 (wie run.sh); Migrationen 002/003 werden mit den Skripten des
#   App-Commits angewendet.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash migration004_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=owned.sh
source "$H/owned.sh"
MIG2="$APP/scripts/migrate_002_history_foundation.sh"
MIG3="$APP/scripts/migrate_003_history_dependencies.sh"
MIG4="$APP/scripts/migrate_004_audit_events.sh"
W="$(mktemp -d /tmp/dnsmgr-h2c-mig4.XXXXXX)"; chmod 700 "$W"
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
mkdb() { # <Name> <base|m2|m3|new>
    local d="${H2A_PREFIX}$1"
    owned_create_db "$d" >&2 || exit 1
    case "$2" in
        new) grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$d" ;;
        *)   mariadb "$d" < "$BASE_SCHEMA" ;;
    esac
    mariadb "$d" < "$H/fixture.sql"
    if [ "$2" = m2 ] || [ "$2" = m3 ]; then bash "$MIG2" --db "$d" --apply --no-backup > "$W/m2.out" 2>&1 || { echo "ABBRUCH: Migration 002 auf $d" >&2; exit 1; }; fi
    if [ "$2" = m3 ]; then bash "$MIG3" --db "$d" --apply --no-backup > "$W/m3.out" 2>&1 || { echo "ABBRUCH: Migration 003 auf $d" >&2; exit 1; }; fi
    echo "$d"
}
has_ae() { [ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = 'audit_events'")" = 1 ]; }
state() { php "$H/schemacheck.php" "$APP" "$1" | grep "^$2"$'\t' | cut -f2; }
problems() { php "$H/schemacheck.php" "$APP" "$1" | grep '^problem' | grep -vE '^problem.(publish_runs|zone_publish_attempts|zone_publish_targets|publish_cleanup_results) fehlt$' | grep -v 'zone_server_verification fehlt$' | grep -v 'zones.soa_serial: Typ int(11) statt int(10) unsigned$' | cut -f2- | tr '\n' ';'; }
basecount() { mariadb -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM $1.history_change_sets), (SELECT COUNT(*) FROM $1.zone_snapshots), (SELECT COUNT(*) FROM $1.zone_revisions), (SELECT COUNT(*) FROM $1.zone_history_state))"; }

echo "== Migration 004 – $(mariadb -N -e 'SELECT VERSION()')"

# O01 ohne Migration 002: Abbruch vor jeder Änderung
D=$(mkdb o1 base)
expect_exit 2 "O01 --check ohne Migration 002" bash "$MIG4" --db "$D" --check
expect_exit 2 "O01b --apply ohne Migration 002 abgelehnt" bash "$MIG4" --db "$D" --apply --no-backup
ok "$(grep -q 'Migration 002 fehlt' "$W/out" && ! has_ae "$D" && echo 1)" "O01c Meldung, keine Tabelle angelegt"

# O02–O03 Grundablauf mit Sicherung, Wiederholbarkeit
D=$(mkdb o2 m3)
mariadb "$D" -e "INSERT INTO history_change_sets (operation, actor_type, channel) VALUES ('test.marker', 'system', 'cli')"
c0=$(basecount "$D")
expect_exit 1 "O02 --check nach Migration 003: Migration 004 erforderlich" bash "$MIG4" --db "$D" --check
ok "$([ "$(state "$D" audit_events)" = absent ] && [ "$(problems "$D")" = "audit_events fehlt;" ] && echo 1)" "O02b SchemaCheck: audit_events fehlt (Login fail-closed)"
expect_exit 2 "O02c --no-backup für Nicht-Testdatenbank abgelehnt" bash "$MIG4" --db dnsmanager --apply --no-backup
expect_exit 0 "O02d --apply mit Sicherung" bash "$MIG4" --db "$D" --apply --backup-dir "$W/bak"
f=$(ls "$W"/bak/*vor-migration-004-*.sql 2>/dev/null | head -1)
ok "$([ -n "$f" ] && [ "$(stat -c %a "$f")" = 600 ] && [ "$(stat -c %a "$W/bak")" = 700 ] && ! grep -q 'CREATE TABLE `audit_events`' "$f" && echo 1)" "O02e Sicherung vor der DDL (600/700, ohne neue Tabelle)"
ok "$(has_ae "$D" && [ "$(state "$D" audit_events)" = ok ] && [ -z "$(problems "$D")" ] && echo 1)" "O02f Tabelle angelegt, SchemaCheck ohne Befund"
ok "$([ "$(basecount "$D")" = "$c0" ] && echo 1)" "O02g Grundtabellen unverändert ($c0)"
expect_exit 0 "O03 --apply erneut: nichts zu tun" bash "$MIG4" --db "$D" --apply --no-backup
ok "$(grep -q 'Nichts zu tun' "$W/out" && echo 1)" "O03b Meldung"
expect_exit 0 "O03c --check danach" bash "$MIG4" --db "$D" --check

# O04 abweichende vorhandene Tabelle (Spalte, NULL-Semantik eines CHECK bei gleichem Namen, Eindeutigkeit von seq)
mariadb "$D" -e "ALTER TABLE audit_events ADD COLUMN extra INT NULL"
expect_exit 2 "O04 zusätzliche Spalte erkannt" bash "$MIG4" --db "$D" --apply --no-backup
ok "$([ "$(state "$D" audit_events)" = mismatch ] && echo 1)" "O04b SchemaCheck: mismatch"
mariadb "$D" -e "ALTER TABLE audit_events DROP COLUMN extra"
mariadb "$D" -e "ALTER TABLE audit_events DROP CONSTRAINT chk_ae_zone, ADD CONSTRAINT chk_ae_zone CHECK ((entity_type = 'zone' AND zone_id = entity_id) OR (entity_type = 'dyndns_account' AND zone_id >= 1) OR (entity_type IN ('server', 'user') AND zone_id IS NULL))"
expect_exit 2 "O04c CHECK ohne IS NOT NULL (gleicher Name, NULL wäre zulässig) erkannt" bash "$MIG4" --db "$D" --check
mariadb "$D" -e "INSERT INTO audit_events (change_set_id, seq, entity_type, entity_id, entity_label, zone_id, action, changes) SELECT MIN(id), 1, 'zone', 7, 'z', NULL, 'update', '{\"a\":1}' FROM history_change_sets" 2>/dev/null
ok "$([ "$(mariadb -N -e "SELECT COUNT(*) FROM $D.audit_events WHERE zone_id IS NULL")" = 1 ] && echo 1)" "O04d Gegenprobe: abgeschwächter CHECK nimmt Zone ohne zone_id an (MariaDB: UNKNOWN gilt als erfüllt)"
mariadb "$D" -e "DELETE FROM audit_events; ALTER TABLE audit_events DROP CONSTRAINT chk_ae_zone, ADD CONSTRAINT chk_ae_zone CHECK ((entity_type = 'zone' AND zone_id IS NOT NULL AND zone_id = entity_id) OR (entity_type = 'dyndns_account' AND zone_id IS NOT NULL AND zone_id >= 1) OR (entity_type IN ('server', 'user') AND zone_id IS NULL))"
expect_exit 0 "O04e nach Rückbau wieder in Ordnung" bash "$MIG4" --db "$D" --check
mariadb "$D" -e "ALTER TABLE audit_events DROP INDEX uq_ae_change_set_seq, ADD INDEX uq_ae_change_set_seq (change_set_id, seq)"
expect_exit 2 "O04f seq-Index nicht eindeutig erkannt" bash "$MIG4" --db "$D" --check
mariadb "$D" -e "ALTER TABLE audit_events DROP INDEX uq_ae_change_set_seq, ADD UNIQUE KEY uq_ae_change_set_seq (change_set_id, seq)"
mariadb "$D" -e "ALTER TABLE audit_events MODIFY seq SMALLINT UNSIGNED NULL"
expect_exit 2 "O04g seq NULL-fähig erkannt" bash "$MIG4" --db "$D" --check
mariadb "$D" -e "ALTER TABLE audit_events MODIFY seq SMALLINT UNSIGNED NOT NULL"
expect_exit 0 "O04h nach Rückbau wieder in Ordnung" bash "$MIG4" --db "$D" --check

# O05 Grundtabelle aus Migration 002 abweichend: keine DDL
D=$(mkdb o5 m3)
mariadb "$D" -e "ALTER TABLE history_change_sets ADD COLUMN extra INT NULL"
expect_exit 2 "O05 abweichende Grundtabelle: Abbruch" bash "$MIG4" --db "$D" --apply --no-backup
ok "$(! has_ae "$D" && echo 1)" "O05b keine Tabelle angelegt"

# O06 technischer Abbruch während der DDL (Fehlerinjektion), deterministischer Wiederanlauf
D=$(mkdb o6 m3)
cp -a "$APP" "$W/appfail"
printf 'SELECT * FROM gibt_es_nicht_h2c;\n' >> "$W/appfail/sql/migrations/004_audit_events.sql"
expect_exit 3 "O06 Fehler nach der CREATE-Anweisung" bash "$W/appfail/scripts/migrate_004_audit_events.sh" --db "$D" --apply --no-backup
expect_exit 0 "O06b Wiederanlauf mit unverändertem Skript" bash "$MIG4" --db "$D" --apply --no-backup
expect_exit 0 "O06c danach --check" bash "$MIG4" --db "$D" --check

# O07 Neuinstallation: Struktur = Migration 002 + 003 + 004
D=$(mkdb o7 new)
expect_exit 0 "O07 Neuinstallation: --check ohne Migration" bash "$MIG4" --db "$D" --check
sig() { php "$APP/web/scripts/schema_history-cli.php" sql | mariadb -N -B "$1"; }
bash "$APP/scripts/migrate_005_publish_journal.sh" --db "${H2A_PREFIX}o2" --apply --no-backup > "$W/m5.out" 2>&1 || ok 0 "O07b Vorbereitung: Migration 005 auf o2"
bash "$APP/scripts/migrate_006_zone_serial_verification.sh" --db "${H2A_PREFIX}o2" --apply --no-backup > "$W/m6.out" 2>&1 || ok 0 "O07b Vorbereitung: Migration 006 auf o2"
ok "$([ "$(sig "$D")" = "$(sig "${H2A_PREFIX}o2")" ] && echo 1)" "O07b Struktursignatur Neuinstallation = Migration 002 + 003 + 004 + 005 + 006 (byte-gleich)"

# O08 Skripte 002/003 bleiben nach Migration 004 konsistent; Migration 004 ohne 003 möglich (keine Abhängigkeit)
expect_exit 0 "O08 migrate_002 --check nach Migration 004" bash "$MIG2" --db "${H2A_PREFIX}o2" --check
expect_exit 0 "O08b migrate_003 --check nach Migration 004" bash "$MIG3" --db "${H2A_PREFIX}o2" --check
D=$(mkdb o8 m2)
expect_exit 0 "O08c Migration 004 direkt nach 002" bash "$MIG4" --db "$D" --apply --no-backup

echo "ERGEBNIS migration004: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
