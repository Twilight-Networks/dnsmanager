#!/bin/bash
# Datei: tests/history/migration_test.sh
# Zweck: Tests der Migration 002 (scripts/migrate_002_history_foundation.sh) auf isolierten Datenbanken dnsmgr_t21_*
#        (AP1/H2a, E26/E27). Gegenprobe jedes Strukturbefunds mit SchemaCheck (PHP).
#
# Aufruf (als root auf dem Testsystem, MariaDB-Socket): bash migration_test.sh <App-Verzeichnis> <Basis-Schema.sql>
#   <App-Verzeichnis>   Stand des zu prüfenden Commits (git archive)
#   <Basis-Schema.sql>  sql/dnsmanager_schema.sql des Basis-Commits (ohne CREATE DATABASE/USE)
# Legt ausschließlich Datenbanken dnsmgr_t21_r<RUN>_m* (tests/history/owned.sh) und ein Arbeitsverzeichnis unter /tmp
# an und entfernt am Ende nur die selbst angelegten Datenbanken.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/scripts" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash migration_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIG="$APP/scripts/migrate_002_history_foundation.sh"
# shellcheck source=owned.sh
source "$H/owned.sh"
W="$(mktemp -d /tmp/dnsmgr-h2a-mig.XXXXXX)"; chmod 700 "$W"
owned_init "$W/owned"
PASS=0; FAIL=0

cleanup() {
    owned_cleanup
    rm -rf "$W"
}
trap cleanup EXIT

ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }
expect_exit() { # <erwartet> <Fall> <Befehl…>
    local want="$1" id="$2"; shift 2
    "$@" > "$W/out" 2>&1; local rc=$?
    if [ "$rc" = "$want" ]; then ok 1 "$id Exit $rc"; else ok 0 "$id Exit $rc statt $want"; sed 's/^/       /' "$W/out"; fi
}
mkdb() { # <Name> [new]: Basis-Schema (bzw. neues Schema) und Fixture
    local d="${H2A_PREFIX}$1"
    owned_create_db "$d" >&2 || exit 1
    if [ "${2:-}" = new ]; then
        grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$d"
    else
        mariadb "$d" < "$BASE_SCHEMA"
    fi
    mariadb "$d" < "$H/fixture.sql"
    echo "$d"
}
# Vollständiger Inhalt der Fachtabellen (sortiert, ohne Zeitstempel der Sicherung) als Prüfsumme
bizsum() {
    mysqldump --skip-dump-date --skip-comments --order-by-primary --no-create-info --skip-extended-insert "$1" \
        users user_zones zones zone_servers servers records dyndns_accounts system_status deployments diagnostics diagnostic_log \
        | sha256sum | cut -c1-64
}
tables() { mariadb -N -B -e "SELECT IFNULL(GROUP_CONCAT(TABLE_NAME ORDER BY TABLE_NAME), '') FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$1' AND TABLE_NAME IN ('history_change_sets','zone_snapshots','zone_revisions','zone_history_state')"; }
# Bewertung der vier Tabellen aus Migration 002; history_dependencies (Migration 003, AP1/H2b) prüft migration003_test.sh,
# audit_events (Migration 004, AP1/H2c) migration004_test.sh
php_states() { php "$H/schemacheck.php" "$APP" "$1" | grep -v '^problem' | grep -v '^history_dependencies' | grep -v '^audit_events' | grep -vE '^(publish_runs|zone_publish_attempts|zone_publish_targets|publish_cleanup_results|zone_server_verification|zone_serial_references)' | cut -f2 | tr '\n' ' ' | sed 's/ $//'; }
# AS4b: SchemaCheck::problems() ist strikt nach 007 – Befunde der späteren Migration 007 hier ausgefiltert (AS5: ebenso 008)
php_problems() { php "$H/schemacheck.php" "$APP" "$1" | grep '^problem' | grep -v 'zone_serial_references fehlt$' | grep -vE '^problem.(publish_runs|zone_publish_attempts|zone_publish_targets|publish_cleanup_results) fehlt$' | grep -v 'history_dependencies fehlt$' | grep -v 'audit_events fehlt$' | grep -v 'zone_server_verification fehlt$' | grep -v 'zones.soa_serial: Typ int(11) statt int(10) unsigned$' | grep -v 'system_status.fence_domain fehlt$' | grep -vE '(zone_publish_targets: Struktur weicht ab \(erwartet col\|zone_protocol\||publish_cleanup_results: Struktur weicht ab \(erwartet col\|cleanup_protocol\|)' | cut -f2-; }

echo "== Migration 002 – $(mariadb -N -e 'SELECT VERSION()'), PHP $(php -r 'echo PHP_VERSION;')"

# ---- M01–M03: Grundablauf, Wiederholbarkeit, Fachdaten unverändert ----
D=$(mkdb m1)
sum0=$(bizsum "$D")
expect_exit 1 "M01 --check auf Basis-Schema: Migration erforderlich" bash "$MIG" --db "$D" --check
ok "$([ -z "$(tables "$D")" ] && echo 1)" "M01b --check legt nichts an"
ok "$([ "$(php_states "$D")" = "absent absent absent absent" ] && echo 1)" "M01c SchemaCheck: alle vier Tabellen absent"
ok "$([ "$(php_problems "$D" | tr '\n' ';')" = "history_change_sets fehlt;zone_snapshots fehlt;zone_revisions fehlt;zone_history_state fehlt;" ] && echo 1)" "M01d SchemaCheck-Befunde (Login fail-closed) nennen genau die fehlenden Tabellen"
expect_exit 2 "M02 --no-backup für dnsmanager abgelehnt (vor jedem DB-Zugriff)" bash "$MIG" --db dnsmanager --apply --no-backup
grep -q 'keine Verbindung, keine Änderung' "$W/out"; ok "$([ $? = 0 ] && echo 1)" "M02b Meldung ohne Verbindung"
expect_exit 2 "M02c --no-backup für dnsmgr_t1_x (falsches Muster) abgelehnt" bash "$MIG" --db dnsmgr_t1_x --apply --no-backup
expect_exit 2 "M02d --apply ohne --backup-dir abgelehnt" bash "$MIG" --db "$D" --apply
ok "$([ -z "$(tables "$D")" ] && echo 1)" "M02e ohne Sicherungsangabe nichts angelegt"
expect_exit 0 "M03 --apply --no-backup" bash "$MIG" --db "$D" --apply --no-backup
grep -q 'Prüfsummen unverändert' "$W/out"; ok "$([ $? = 0 ] && echo 1)" "M03b Skript meldet Fachtabellen unverändert"
expect_exit 0 "M03c --check nach Migration" bash "$MIG" --db "$D" --check
ok "$([ "$(php_states "$D")" = "ok ok ok ok" ] && echo 1)" "M03d SchemaCheck: alle ok"
ok "$([ -z "$(php_problems "$D")" ] && echo 1)" "M03e SchemaCheck::problems() leer"
expect_exit 0 "M03f zweites --apply" bash "$MIG" --db "$D" --apply --no-backup
grep -q 'Nichts zu tun' "$W/out"; ok "$([ $? = 0 ] && echo 1)" "M03g zweites --apply: Nichts zu tun"
ok "$([ "$(bizsum "$D")" = "$sum0" ] && echo 1)" "M03h Fachtabellen vollständig unverändert (Inhalts-Prüfsumme)"
ok "$([ "$(mariadb -N -e "SELECT CONCAT((SELECT COUNT(*) FROM $D.history_change_sets),(SELECT COUNT(*) FROM $D.zone_snapshots),(SELECT COUNT(*) FROM $D.zone_revisions),(SELECT COUNT(*) FROM $D.zone_history_state))")" = 0000 ] && echo 1)" "M03i History-Tabellen leer (keine Baseline durch die Migration)"

# ---- M04: Sicherung ----
D=$(mkdb m4)
expect_exit 0 "M04 --apply mit Sicherung" bash "$MIG" --db "$D" --apply --backup-dir "$W/bk"
f=$(ls "$W/bk"/"$D"-vor-migration-002-*.sql 2>/dev/null | head -1)
ok "$([ -n "$f" ] && [ "$(stat -c %a "$f")" = 600 ] && [ "$(stat -c %a "$W/bk")" = 700 ] && echo 1)" "M04b Sicherung 600 in 700-Verzeichnis"
ok "$(grep -q 'CREATE TABLE `zones`' "$f" && ! grep -q 'CREATE TABLE `zone_revisions`' "$f" && echo 1)" "M04c Sicherung vor der DDL (zones enthalten, History-Tabellen nicht)"

# ---- M05: technischer Abbruch während der DDL, deterministischer Wiederanlauf ----
D=$(mkdb m5)
sum0=$(bizsum "$D")
cp -a "$APP" "$W/appfail"
awk '{print} /^\) ENGINE=InnoDB/ {n++; if (n==2) print "SELECT * FROM h2a_fehlerinjektion_tabelle_fehlt;"}' \
    "$APP/sql/migrations/002_history_foundation.sql" > "$W/appfail/sql/migrations/002_history_foundation.sql"
expect_exit 3 "M05 Abbruch nach der zweiten CREATE-Anweisung (Fehlerinjektion)" bash "$W/appfail/scripts/migrate_002_history_foundation.sh" --db "$D" --apply --no-backup
ok "$([ "$(tables "$D")" = "history_change_sets,zone_snapshots" ] && echo 1)" "M05b angelegte Tabellen bleiben bestehen (2 von 4)"
ok "$([ "$(php_states "$D")" = "ok ok absent absent" ] && echo 1)" "M05c Teilzustand: vorhandene Tabellen korrekt, fehlende absent"
mariadb "$D" -e "INSERT INTO history_change_sets (operation, actor_type, channel) VALUES ('test.marker', 'system', 'cli');
  INSERT INTO zone_snapshots (content_hash, contract, payload, record_count) VALUES (REPEAT('a', 64), 'zs1', '{}', 0)"
expect_exit 1 "M05d --check nach Abbruch: Migration erforderlich" bash "$MIG" --db "$D" --check
expect_exit 0 "M05e Wiederanlauf mit unverändertem Skript" bash "$MIG" --db "$D" --apply --no-backup
grep -q 'Wiederanlauf: 2 Tabelle(n) bereits korrekt vorhanden, 2 werden angelegt' "$W/out"; ok "$([ $? = 0 ] && echo 1)" "M05f Wiederanlauf erkennt vorhandene Tabellen"
ok "$([ "$(php_states "$D")" = "ok ok ok ok" ] && echo 1)" "M05g nach Wiederanlauf alle ok"
ok "$([ "$(mariadb -N -e "SELECT CONCAT((SELECT COUNT(*) FROM $D.history_change_sets),(SELECT COUNT(*) FROM $D.zone_snapshots))")" = 11 ] && echo 1)" "M05h Daten vorhandener Tabellen erhalten"
ok "$([ "$(bizsum "$D")" = "$sum0" ] && echo 1)" "M05i Fachtabellen unverändert"

# ---- M06: Abweichung einer vorhandenen Tabelle ⇒ Exit 2 vor der ersten DDL ----
D=$(mkdb m6)
sum0=$(bizsum "$D")
sed -n '/^CREATE TABLE IF NOT EXISTS zone_snapshots/,/^) ENGINE/p' "$APP/sql/migrations/002_history_foundation.sql" \
    | sed 's/record_count INT UNSIGNED NOT NULL/record_count INT NOT NULL/' | mariadb "$D"
expect_exit 2 "M06 --apply mit abweichender vorhandener Tabelle" bash "$MIG" --db "$D" --apply --no-backup
grep -q 'zone_snapshots: mismatch – erwartet col|record_count|int(10) unsigned' "$W/out"; ok "$([ $? = 0 ] && echo 1)" "M06b Detail nennt die Abweichung"
ok "$([ "$(tables "$D")" = "zone_snapshots" ] && echo 1)" "M06c keine weitere Tabelle angelegt (Preflight vor DDL)"
ok "$([ "$(bizsum "$D")" = "$sum0" ] && echo 1)" "M06d Fachtabellen unverändert"
expect_exit 2 "M06e --check meldet Abweichung" bash "$MIG" --db "$D" --check
ok "$([ "$(php_states "$D")" = "absent mismatch absent absent" ] && echo 1)" "M06f SchemaCheck: gleiche Bewertung wie das Skript"
ok "$(php_problems "$D" | grep -q '^zone_snapshots: Struktur weicht ab (erwartet col|record_count|int(10) unsigned' && echo 1)" "M06g SchemaCheck-Befund für den Login"

# ---- M07: Mutationen einer vollständig migrierten Datenbank (Skript und SchemaCheck erkennen jede) ----
D=$(mkdb m7)
bash "$MIG" --db "$D" --apply --no-backup > /dev/null 2>&1
mutate() { # <Fall> <Tabelle> <Index der Tabelle 1–4> <Mutation-SQL> <Rückbau-SQL>
    local id="$1" table="$2" pos="$3" sql="$4" undo="$5" want
    mariadb "$D" -e "$sql" || { ok 0 "$id Mutation nicht anwendbar"; return; }
    want=$(echo "ok ok ok ok" | awk -v p="$pos" '{ $p = "mismatch"; print }')
    expect_exit 2 "$id Skript erkennt: $table" bash "$MIG" --db "$D" --check
    ok "$([ "$(php_states "$D")" = "$want" ] && echo 1)" "$id SchemaCheck erkennt: $table ($want)"
    mariadb "$D" -e "$undo" || ok 0 "$id Rückbau"
    ok "$([ "$(php_states "$D")" = "ok ok ok ok" ] && echo 1)" "$id nach Rückbau wieder ok"
}
mutate M07a zone_history_state 4 "ALTER TABLE zone_history_state ADD COLUMN extra INT NULL" "ALTER TABLE zone_history_state DROP COLUMN extra"
mutate M07b history_change_sets 1 "CREATE INDEX idx_extra ON history_change_sets (channel)" "DROP INDEX idx_extra ON history_change_sets"
mutate M07c zone_revisions 3 "ALTER TABLE zone_revisions DROP FOREIGN KEY fk_zr_snapshot; ALTER TABLE zone_revisions ADD CONSTRAINT fk_zr_snapshot FOREIGN KEY (content_hash) REFERENCES zone_snapshots (content_hash) ON DELETE CASCADE" \
    "ALTER TABLE zone_revisions DROP FOREIGN KEY fk_zr_snapshot; ALTER TABLE zone_revisions ADD CONSTRAINT fk_zr_snapshot FOREIGN KEY (content_hash) REFERENCES zone_snapshots (content_hash) ON DELETE RESTRICT ON UPDATE RESTRICT"
mutate M07d zone_snapshots 2 "ALTER TABLE zone_snapshots DROP CONSTRAINT chk_zs_payload" "ALTER TABLE zone_snapshots ADD CONSTRAINT chk_zs_payload CHECK (JSON_VALID(payload))"
mutate M07e zone_snapshots 2 "ALTER TABLE zone_snapshots MODIFY payload LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" "ALTER TABLE zone_snapshots MODIFY payload LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL"
mutate M07f history_change_sets 1 "ALTER TABLE history_change_sets MODIFY actor_type ENUM('user','dyndns_account','system','x') CHARACTER SET ascii COLLATE ascii_bin NOT NULL" "ALTER TABLE history_change_sets MODIFY actor_type ENUM('user','dyndns_account','system') CHARACTER SET ascii COLLATE ascii_bin NOT NULL"
mutate M07g zone_revisions 3 "ALTER TABLE zone_revisions MODIFY revision_no INT UNSIGNED NULL" "ALTER TABLE zone_revisions MODIFY revision_no INT UNSIGNED NOT NULL"
mutate M07h history_change_sets 1 "ALTER TABLE history_change_sets ALTER created_at DROP DEFAULT" "ALTER TABLE history_change_sets ALTER created_at SET DEFAULT CURRENT_TIMESTAMP(6)"
# S1: geänderter CHECK-Ausdruck bei unverändertem Constraint-Namen
mutate M07k zone_snapshots 2 "ALTER TABLE zone_snapshots DROP CONSTRAINT chk_zs_hash; ALTER TABLE zone_snapshots ADD CONSTRAINT chk_zs_hash CHECK (content_hash REGEXP '^[0-9a-f]{40,64}\$')"     "ALTER TABLE zone_snapshots DROP CONSTRAINT chk_zs_hash; ALTER TABLE zone_snapshots ADD CONSTRAINT chk_zs_hash CHECK (content_hash REGEXP '^[0-9a-f]{64}\$')"
grep -q "zone_snapshots: mismatch – erwartet chk|chk_zs_hash|Table|" "$W/out"; ok "$([ $? = 0 ] && echo 1)" "M07k Skript-Detail nennt den CHECK-Ausdruck"
mutate M07l history_change_sets 1 "ALTER TABLE history_change_sets DROP CONSTRAINT chk_hcs_actor; ALTER TABLE history_change_sets ADD CONSTRAINT chk_hcs_actor CHECK (actor_type IN ('user', 'dyndns_account', 'system'))"     "ALTER TABLE history_change_sets DROP CONSTRAINT chk_hcs_actor; ALTER TABLE history_change_sets ADD CONSTRAINT chk_hcs_actor CHECK ((actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_dyndns_account_id IS NULL) OR (actor_type = 'dyndns_account' AND actor_dyndns_account_id IS NOT NULL AND actor_user_id IS NULL AND actor_label IS NULL) OR (actor_type = 'system' AND actor_user_id IS NULL AND actor_dyndns_account_id IS NULL))"
mariadb "$D" -e "ALTER TABLE zone_revisions DROP CONSTRAINT chk_zr_revision_no; ALTER TABLE zone_revisions ADD CONSTRAINT chk_zr_revision_no CHECK (revision_no >= 0)"
expect_exit 2 "M07m --apply mit geändertem CHECK-Ausdruck ohne Änderung abgelehnt" bash "$MIG" --db "$D" --apply --no-backup
mariadb "$D" -e "ALTER TABLE zone_revisions DROP CONSTRAINT chk_zr_revision_no; ALTER TABLE zone_revisions ADD CONSTRAINT chk_zr_revision_no CHECK (revision_no >= 1)"
ok "$([ "$(php_states "$D")" = "ok ok ok ok" ] && echo 1)" "M07n nach Rückbau wieder ok"
# Sicht statt Tabelle (nur Zustandsbewertung, Rückbau: Tabelle neu anlegen)
mariadb "$D" -e "DROP TABLE zone_history_state; CREATE VIEW zone_history_state AS SELECT 1 AS zone_id"
expect_exit 2 "M07i Skript erkennt Sicht statt Tabelle" bash "$MIG" --db "$D" --check
expect_exit 2 "M07j --apply mit Sicht statt Tabelle ohne Änderung abgelehnt" bash "$MIG" --db "$D" --apply --no-backup

# ---- M08: Neuinstallation mit sql/dnsmanager_schema.sql = migrierte Struktur ----
D=$(mkdb m8 new)
expect_exit 0 "M08 Neuinstallation: --check ohne Migration" bash "$MIG" --db "$D" --check
ok "$([ -z "$(php_problems "$D")" ] && echo 1)" "M08b Neuinstallation: SchemaCheck::problems() leer"
sig() { php "$APP/web/scripts/schema_history-cli.php" sql | mariadb -N -B "$1"; }
bash "$APP/scripts/migrate_003_history_dependencies.sh" --db "${H2A_PREFIX}m1" --apply --no-backup > "$W/out" 2>&1 || ok 0 "M08c Vorbereitung: Migration 003 auf m1"
bash "$APP/scripts/migrate_004_audit_events.sh" --db "${H2A_PREFIX}m1" --apply --no-backup > "$W/out" 2>&1 || ok 0 "M08c Vorbereitung: Migration 004 auf m1"
bash "$APP/scripts/migrate_005_publish_journal.sh" --db "${H2A_PREFIX}m1" --apply --no-backup > "$W/out" 2>&1 || ok 0 "M08c Vorbereitung: Migration 005 auf m1"
bash "$APP/scripts/migrate_006_zone_serial_verification.sh" --db "${H2A_PREFIX}m1" --apply --no-backup > "$W/out" 2>&1 || ok 0 "M08c Vorbereitung: Migration 006 auf m1"
bash "$APP/scripts/migrate_007_journal_agent_results.sh" --db "${H2A_PREFIX}m1" --apply --no-backup > "$W/m7.out" 2>&1 || ok 0 "M08c Vorbereitung: Migration 007 auf m1"
bash "$APP/scripts/migrate_008_zone_serial_references.sh" --db "${H2A_PREFIX}m1" --apply --no-backup > "$W/m8.out" 2>&1 || ok 0 "M08c Vorbereitung: Migration 008 auf m1"   # AS5
ok "$([ "$(sig "$D")" = "$(sig "${H2A_PREFIX}m1")" ] && echo 1)" "M08c Struktursignatur Neuinstallation = Migration 002 + 003 + 004 + 005 + 006 + 007 (byte-gleich)"

echo "ERGEBNIS migration: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
