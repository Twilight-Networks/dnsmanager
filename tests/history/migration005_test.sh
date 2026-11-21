#!/bin/bash
# Datei: tests/history/migration005_test.sh
# Zweck: Tests der Migration 005 (scripts/migrate_005_publish_journal.sh, AP1/H2d, E55/E56) auf isolierten Datenbanken
#        dnsmgr_t21_r<RUN>_p* (Eigentum nach tests/history/owned.sh).
#
# Aufruf (als root auf dem Testsystem): bash migration005_test.sh <App-Verzeichnis> <Basis-Schema.sql>
#   <Basis-Schema.sql> = Schema vor Migration 002 (wie run.sh); Migrationen 002–004 mit den Skripten des App-Commits.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash migration005_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=owned.sh
source "$H/owned.sh"
MIG2="$APP/scripts/migrate_002_history_foundation.sh"
MIG3="$APP/scripts/migrate_003_history_dependencies.sh"
MIG4="$APP/scripts/migrate_004_audit_events.sh"
MIG5="$APP/scripts/migrate_005_publish_journal.sh"
JT="publish_runs zone_publish_attempts zone_publish_targets publish_cleanup_results"
W="$(mktemp -d /tmp/dnsmgr-h2d-mig5.XXXXXX)"; chmod 700 "$W"
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
mkdb() { # <Name> <base|m2|m4|new>
    local d="${H2A_PREFIX}$1"
    owned_create_db "$d" >&2 || exit 1
    case "$2" in
        new) grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$d" ;;
        *)   mariadb "$d" < "$BASE_SCHEMA" ;;
    esac
    mariadb "$d" < "$H/fixture.sql"
    if [ "$2" = m2 ] || [ "$2" = m4 ]; then bash "$MIG2" --db "$d" --apply --no-backup > "$W/m2.out" 2>&1 || { echo "ABBRUCH: Migration 002 auf $d" >&2; exit 1; }; fi
    if [ "$2" = m4 ]; then
        bash "$MIG3" --db "$d" --apply --no-backup > "$W/m3.out" 2>&1 || { echo "ABBRUCH: Migration 003 auf $d" >&2; exit 1; }
        bash "$MIG4" --db "$d" --apply --no-backup > "$W/m4.out" 2>&1 || { echo "ABBRUCH: Migration 004 auf $d" >&2; exit 1; }
    fi
    echo "$d"
}
njt() { local n=0 t; for t in $JT; do [ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME = '$t'")" = 1 ] && n=$((n + 1)); done; echo "$n"; }
state() { php "$H/schemacheck.php" "$APP" "$1" | grep "^$2"$'\t' | cut -f2; }
# AS4b: SchemaCheck::problems() ist strikt nach 007 – Befunde der späteren Migration 007 hier ausgefiltert
problems() { php "$H/schemacheck.php" "$APP" "$1" | grep '^problem' | grep -v 'zone_server_verification fehlt$' | grep -v 'zones.soa_serial: Typ int(11) statt int(10) unsigned$' | grep -v 'system_status.fence_domain fehlt$' | grep -vE '(zone_publish_targets: Struktur weicht ab \(erwartet col\|zone_protocol\||publish_cleanup_results: Struktur weicht ab \(erwartet col\|cleanup_protocol\|)' | cut -f2- | tr '\n' ';'; }
basecount() { mariadb -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM $1.history_change_sets), (SELECT COUNT(*) FROM $1.zone_snapshots), (SELECT COUNT(*) FROM $1.zone_revisions), (SELECT COUNT(*) FROM $1.zone_history_state), (SELECT COUNT(*) FROM $1.audit_events))"; }

echo "== Migration 005 – $(mariadb -N -e 'SELECT VERSION()')"

# P01 ohne Migration 002: Abbruch vor jeder Änderung
D=$(mkdb p1 base)
expect_exit 2 "P01 --check ohne Migration 002" bash "$MIG5" --db "$D" --check
expect_exit 2 "P01b --apply ohne Migration 002 abgelehnt" bash "$MIG5" --db "$D" --apply --no-backup
ok "$(grep -q 'Migration 002 fehlt' "$W/out" && [ "$(njt "$D")" = 0 ] && echo 1)" "P01c Meldung, keine Tabelle angelegt"

# P02–P03 Grundablauf mit Sicherung, Wiederholbarkeit
D=$(mkdb p2 m4)
mariadb "$D" -e "INSERT INTO history_change_sets (operation, actor_type, channel) VALUES ('test.marker', 'system', 'cli')"
c0=$(basecount "$D")
expect_exit 1 "P02 --check nach Migration 004: Migration 005 erforderlich" bash "$MIG5" --db "$D" --check
ok "$([ "$(state "$D" publish_runs)" = absent ] && [ "$(problems "$D")" = "publish_runs fehlt;zone_publish_attempts fehlt;zone_publish_targets fehlt;publish_cleanup_results fehlt;" ] && echo 1)" "P02b SchemaCheck: Journaltabellen fehlen (Login fail-closed)"
expect_exit 2 "P02c --no-backup für Nicht-Testdatenbank abgelehnt" bash "$MIG5" --db dnsmanager --apply --no-backup
expect_exit 0 "P02d --apply mit Sicherung" bash "$MIG5" --db "$D" --apply --backup-dir "$W/bak"
f=$(ls "$W"/bak/*vor-migration-005-*.sql 2>/dev/null | head -1)
ok "$([ -n "$f" ] && [ "$(stat -c %a "$f")" = 600 ] && [ "$(stat -c %a "$W/bak")" = 700 ] && ! grep -q 'CREATE TABLE `publish_runs`' "$f" && echo 1)" "P02e Sicherung vor der DDL (600/700, ohne neue Tabellen)"
ok "$([ "$(njt "$D")" = 4 ] && [ "$(state "$D" zone_publish_targets)" = ok ] && [ -z "$(problems "$D")" ] && echo 1)" "P02f vier Tabellen angelegt, SchemaCheck ohne Befund"
ok "$([ "$(basecount "$D")" = "$c0" ] && echo 1)" "P02g History-Tabellen unverändert ($c0)"
expect_exit 0 "P03 --apply erneut: nichts zu tun" bash "$MIG5" --db "$D" --apply --no-backup
ok "$(grep -q 'Nichts zu tun' "$W/out" && echo 1)" "P03b Meldung"
expect_exit 0 "P03c --check danach" bash "$MIG5" --db "$D" --check

# P04 abweichende vorhandene Tabelle (Spalte, NULL-Semantik eines CHECK bei gleichem Namen, FK-Regel)
mariadb "$D" -e "ALTER TABLE zone_publish_targets ADD COLUMN extra INT NULL"
expect_exit 2 "P04 zusätzliche Spalte erkannt" bash "$MIG5" --db "$D" --apply --no-backup
ok "$([ "$(state "$D" zone_publish_targets)" = mismatch ] && echo 1)" "P04b SchemaCheck: mismatch"
mariadb "$D" -e "ALTER TABLE zone_publish_targets DROP COLUMN extra"
mariadb "$D" -e "ALTER TABLE zone_publish_attempts DROP CONSTRAINT chk_zpa_revision, ADD CONSTRAINT chk_zpa_revision CHECK ((revision_basis = 'head' AND revision_no >= 1) OR (revision_basis IN ('head_mismatch', 'no_head') AND revision_no IS NULL))"
expect_exit 2 "P04c CHECK ohne IS NOT NULL (gleicher Name) erkannt" bash "$MIG5" --db "$D" --check
mariadb "$D" -e "INSERT INTO publish_runs (kind, full_rebuild, connection_id, history_watermark, actor_type, channel, correlation_id) VALUES ('all', 0, 1, 0, 'system', 'cli', '11111111-1111-4111-8111-111111111111');
  INSERT INTO zone_publish_attempts (run_id, zone_id, zone_name, revision_no, revision_basis, content_hash, snapshot_contract, render_hash, file_hash, render_contract, soa_serial, target_count)
  SELECT MAX(id), 1, 'z', NULL, 'head', REPEAT('a', 64), 'zs1', REPEAT('a', 64), REPEAT('a', 64), 'rh1', 1, 0 FROM publish_runs" 2>/dev/null
ok "$([ "$(mariadb -N -e "SELECT COUNT(*) FROM $D.zone_publish_attempts WHERE revision_basis = 'head' AND revision_no IS NULL")" = 1 ] && echo 1)" "P04d Gegenprobe: abgeschwächter CHECK nimmt head ohne Revision an (UNKNOWN gilt als erfüllt)"
mariadb "$D" -e "DELETE FROM zone_publish_attempts; DELETE FROM publish_runs; ALTER TABLE zone_publish_attempts DROP CONSTRAINT chk_zpa_revision, ADD CONSTRAINT chk_zpa_revision CHECK ((revision_basis = 'head' AND revision_no IS NOT NULL AND revision_no >= 1) OR (revision_basis IN ('head_mismatch', 'no_head') AND revision_no IS NULL))"
expect_exit 0 "P04e nach Rückbau wieder in Ordnung" bash "$MIG5" --db "$D" --check
mariadb "$D" -e "ALTER TABLE zone_publish_targets DROP FOREIGN KEY fk_zpt_attempt; ALTER TABLE zone_publish_targets ADD CONSTRAINT fk_zpt_attempt FOREIGN KEY (attempt_id) REFERENCES zone_publish_attempts (id) ON DELETE CASCADE"
expect_exit 2 "P04f FK-Regel CASCADE statt RESTRICT erkannt" bash "$MIG5" --db "$D" --check
mariadb "$D" -e "ALTER TABLE zone_publish_targets DROP FOREIGN KEY fk_zpt_attempt; ALTER TABLE zone_publish_targets ADD CONSTRAINT fk_zpt_attempt FOREIGN KEY (attempt_id) REFERENCES zone_publish_attempts (id) ON DELETE RESTRICT ON UPDATE RESTRICT"
expect_exit 0 "P04g nach Rückbau wieder in Ordnung" bash "$MIG5" --db "$D" --check

# P05 teilweise vorhandene Journaltabellen (z. B. nach technischem Abbruch): fehlende werden ergänzt
D=$(mkdb p5 m4)
bash "$MIG5" --db "$D" --apply --no-backup > "$W/out" 2>&1
mariadb "$D" -e "DROP TABLE zone_publish_targets; DROP TABLE publish_cleanup_results"
expect_exit 1 "P05 --check: zwei Tabellen fehlen" bash "$MIG5" --db "$D" --check
expect_exit 0 "P05b --apply ergänzt die fehlenden Tabellen" bash "$MIG5" --db "$D" --apply --no-backup
ok "$([ "$(njt "$D")" = 4 ] && [ -z "$(problems "$D")" ] && echo 1)" "P05c vollständig, SchemaCheck ohne Befund"

# P06 Grundtabelle aus Migration 002 abweichend: keine DDL
D=$(mkdb p6 m4)
mariadb "$D" -e "ALTER TABLE zone_revisions ADD COLUMN extra INT NULL"
expect_exit 2 "P06 abweichende Grundtabelle: Abbruch" bash "$MIG5" --db "$D" --apply --no-backup
ok "$([ "$(njt "$D")" = 0 ] && echo 1)" "P06b keine Tabelle angelegt"

# P07 technischer Abbruch während der DDL (Fehlerinjektion nach zwei Tabellen), deterministischer Wiederanlauf
D=$(mkdb p7 m4)
cp -a "$APP" "$W/appfail"
php -r '$f = $argv[1]; $s = file_get_contents($f); $m = "CREATE TABLE IF NOT EXISTS zone_publish_targets"; if (substr_count($s, $m) !== 1) exit(1);
        file_put_contents($f, str_replace($m, "SELECT * FROM gibt_es_nicht_h2d;\n" . $m, $s));' "$W/appfail/sql/migrations/005_publish_journal.sql" || ok 0 "P07 Fehlerinjektion nicht anwendbar"
expect_exit 3 "P07 Abbruch nach zwei angelegten Tabellen" bash "$W/appfail/scripts/migrate_005_publish_journal.sh" --db "$D" --apply --no-backup
ok "$([ "$(njt "$D")" = 2 ] && echo 1)" "P07b Zwischenstand: zwei Tabellen vollständig angelegt"
expect_exit 1 "P07c --check meldet fehlende Tabellen" bash "$MIG5" --db "$D" --check
expect_exit 0 "P07d Wiederanlauf mit unverändertem Skript" bash "$MIG5" --db "$D" --apply --no-backup
expect_exit 0 "P07e danach --check" bash "$MIG5" --db "$D" --check

# P08 Neuinstallation: Struktur = Migration 002 + 003 + 004 + 005
D=$(mkdb p8 new)
expect_exit 0 "P08 Neuinstallation: --check ohne Migration" bash "$MIG5" --db "$D" --check
sig() { php "$APP/web/scripts/schema_history-cli.php" sql | mariadb -N -B "$1"; }
bash "$APP/scripts/migrate_006_zone_serial_verification.sh" --db "${H2A_PREFIX}p2" --apply --no-backup > "$W/m6.out" 2>&1 || ok 0 "P08b Vorbereitung: Migration 006 auf p2"
bash "$APP/scripts/migrate_007_journal_agent_results.sh" --db "${H2A_PREFIX}p2" --apply --no-backup > "$W/m7.out" 2>&1 || ok 0 "P08b Vorbereitung: Migration 007 auf p2"
ok "$([ "$(sig "$D")" = "$(sig "${H2A_PREFIX}p2")" ] && echo 1)" "P08b Struktursignatur Neuinstallation = Migration 002–007 (byte-gleich)"

# P09 frühere Skripte bleiben nach Migration 005 konsistent
expect_exit 0 "P09 migrate_002 --check nach Migration 005" bash "$MIG2" --db "${H2A_PREFIX}p2" --check
expect_exit 0 "P09b migrate_003 --check nach Migration 005" bash "$MIG3" --db "${H2A_PREFIX}p2" --check
expect_exit 0 "P09c migrate_004 --check nach Migration 005" bash "$MIG4" --db "${H2A_PREFIX}p2" --check

echo "ERGEBNIS migration005: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
