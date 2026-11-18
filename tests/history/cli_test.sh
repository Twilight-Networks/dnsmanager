#!/bin/bash
# Datei: tests/history/cli_test.sh
# Zweck: Test des CLI-Adapters web/scripts/history_baseline-cli.php und von SchemaCheck mit einem Datenbankbenutzer,
#        der wie der Anwendungsbenutzer nur DML-Rechte auf Datenbankebene hat (AP1/H2a).
#
# Aufruf (als root auf dem Testsystem): bash cli_test.sh <App-Verzeichnis> <Basis-Schema.sql>
# Legt eine Kopie der Anwendung mit eigener Konfiguration, die Datenbanken dnsmgr_t21_r<RUN>_cli/_clio und den
# Benutzer h2a<RUN>@localhost an (tests/history/owned.sh) und entfernt am Ende nur diese selbst angelegten Ressourcen.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash cli_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=owned.sh
source "$H/owned.sh"
W="$(mktemp -d /tmp/dnsmgr-h2a-cli.XXXXXX)"; chmod 700 "$W"
owned_init "$W/owned"
PASS=0; FAIL=0
DB="${H2A_PREFIX}cli"; DBO="${H2A_PREFIX}clio"; U="$H2A_USER"
cleanup() {
    owned_cleanup
    rm -rf "$W"
}
trap cleanup EXIT
ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }
run() { # <erwartet> <Fall> <Datenbank> <Argumente…>
    local want="$1" id="$2" db="$3"; shift 3
    sed -i -E "s/define\('DB_NAME', *'[^']*'\)/define('DB_NAME', '$db')/" "$W/app/web/config/ui_config.php"
    php "$W/app/web/scripts/history_baseline-cli.php" "$@" > "$W/out" 2> "$W/err"; local rc=$?
    if [ "$rc" = "$want" ]; then ok 1 "$id Exit $rc"; else ok 0 "$id Exit $rc statt $want"; sed 's/^/       /' "$W/out" "$W/err"; fi
}
has() { grep -qE "$2" "$W/$1"; }

echo "== CLI history_baseline-cli.php"
PW=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
cp -a "$APP" "$W/app"
c="$W/app/web/config/ui_config.php"
cp "$W/app/web/config/ui_config-sample.php" "$c"
sed -i -E -e "s/define\('DB_USER', *'[^']*'\)/define('DB_USER', '$U')/" -e "s/define\('DB_PASS', *'[^']*'\)/define('DB_PASS', '$PW')/" \
    -e "s/define\('DB_HOST', *'[^']*'\)/define('DB_HOST', 'localhost')/" -e "s/define\('LOG_TARGET', *'[^']*'\)/define('LOG_TARGET', 'apache')/" "$c"
chmod 600 "$c"
owned_create_db "$DB" || exit 1
owned_create_db "$DBO" || exit 1
grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb "$DB"
mariadb "$DBO" < "$BASE_SCHEMA"
for d in "$DB" "$DBO"; do mariadb "$d" < "$H/fixture.sql"; done
owned_create_user "$U" "$PW" || exit 1
mariadb -e "GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB\`.* TO '$U'@'localhost';
  GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DBO\`.* TO '$U'@'localhost'"

run 2 "L01 ohne Argument" "$DB"
run 2 "L02 unbekanntes Argument" "$DB" --force
run 1 "L03 --check vor der Baseline (DML-Benutzer, SchemaCheck über information_schema)" "$DB" --check
ok "$(has out 'Zonen: 4, mit History-Head: 0, ohne: 4' && echo 1)" "L03b Ausgabe nennt Zonen ohne Head"
run 0 "L04 --dry-run" "$DB" --dry-run
ok "$(has out '^OK: mode=dry_run zonen=4 angelegt=0 geplant=4' && echo 1)" "L04b Prüflauf"
ok "$([ "$(mariadb -N -e "SELECT COUNT(*) FROM \`$DB\`.zone_revisions")" = 0 ] && echo 1)" "L04c Prüflauf schreibt nichts"
run 0 "L05 --apply" "$DB" --apply
ok "$(has out '^OK: mode=apply zonen=4 angelegt=4 geplant=0 übersprungen=0' && echo 1)" "L05b Baseline angelegt"
ok "$(has err 'event=history_baseline_done mode=apply' && has err 'event=history_baseline_zone zone_id=1 zone=alpha.h2a.test revision=1' && echo 1)" "L05c Logzeilen"
ok "$(! grep -qE 'H2AFIXTURE|H2ATOKEN|\$2y\$|Warning|Deprecated|Notice' "$W/out" "$W/err" && echo 1)" "L05d keine Geheimnisse, keine PHP-Meldungen"
run 0 "L06 --check nach der Baseline" "$DB" --check
run 0 "L07 --apply erneut (wiederholbar)" "$DB" --apply
ok "$(has out 'angelegt=0 geplant=0 übersprungen=4' && echo 1)" "L07b alle übersprungen"
mariadb -e "SELECT GET_LOCK('dnsmanager_publish_all', 0); DO SLEEP(4)" > /dev/null & LP=$!
sleep 1
run 2 "L08 Advisory-Lock belegt" "$DB" --apply
ok "$(has out 'Advisory-Lock dnsmanager_publish_all belegt' && echo 1)" "L08b Meldung"
wait $LP
run 2 "L09 --check ohne Migration 002 (fail-closed)" "$DBO" --check
ok "$(has out 'Schema nicht passend: history_change_sets fehlt; zone_snapshots fehlt; zone_revisions fehlt; zone_history_state fehlt; history_dependencies fehlt' && echo 1)" "L09b Befunde (inkl. Migration 003)"
run 2 "L10 --apply ohne Migration 002 (fail-closed)" "$DBO" --apply
ok "$([ "$(php "$W/app/web/scripts/schema_history-cli.php" sql | grep -c 'information_schema')" -ge 5 ] && echo 1)" "L11 schema_history-cli.php sql"
printf 'x\n' | php "$W/app/web/scripts/schema_history-cli.php" evaluate > /dev/null 2>&1; ok "$([ $? = 2 ] && echo 1)" "L12 schema_history-cli.php evaluate: ungültige Eingabe ⇒ Exit 2"

# AP1/H2b, E36: Abgleich gelöschter Zonen (DML-Benutzer)
mariadb "$DB" -e "DELETE FROM zones WHERE id = 5"
run 0 "L13 --reconcile-dry-run" "$DB" --reconcile-dry-run
ok "$(has out '^geplant: +5 empty.h2a.test revision=2' && has out '^OK: mode=dry_run heads=1 gekennzeichnet=0 geplant=1' && echo 1)" "L13b Prüflauf nennt die gelöschte Zone"
ok "$([ "$(mariadb -N -e "SELECT deleted_at IS NULL FROM \`$DB\`.zone_history_state WHERE zone_id = 5")" = 1 ] && echo 1)" "L13c Prüflauf schreibt nichts"
run 0 "L14 --reconcile" "$DB" --reconcile
ok "$(has out '^gekennzeichnet: 5 empty.h2a.test revision=2' && has err 'event=history_reconcile_zone zone_id=5 zone=empty.h2a.test revision=2' && has err 'event=history_reconcile_done mode=apply' && echo 1)" "L14b Ausgabe und Logzeilen"
ok "$([ "$(mariadb -N -e "SELECT CONCAT(s.head_revision_no, s.deleted_at IS NOT NULL, r.kind, c.operation, c.actor_type, c.channel) FROM \`$DB\`.zone_history_state s JOIN \`$DB\`.zone_revisions r ON r.zone_id = s.zone_id AND r.revision_no = s.head_revision_no JOIN \`$DB\`.history_change_sets c ON c.id = r.change_set_id WHERE s.zone_id = 5")" = '21deletehistory.reconcile_deletedsystemcli' ] && echo 1)" "L14c Revision delete, Head gelöscht, Änderungsmenge system/cli"
run 0 "L15 --reconcile erneut (wiederholbar)" "$DB" --reconcile
ok "$(has out 'heads=0 gekennzeichnet=0' && echo 1)" "L15b nichts mehr zu tun"
run 2 "L16 --reconcile ohne Migration (fail-closed)" "$DBO" --reconcile

echo "ERGEBNIS cli: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
