#!/bin/bash
# Datei: tests/history/cli_test.sh
# Zweck: Test des CLI-Adapters web/scripts/history_baseline-cli.php und von SchemaCheck mit einem Datenbankbenutzer,
#        der wie der Anwendungsbenutzer nur DML-Rechte auf Datenbankebene hat (AP1/H2a).
#
# Aufruf (als root auf dem Testsystem): bash cli_test.sh <App-Verzeichnis> <Basis-Schema.sql>
# Legt eine Kopie der Anwendung mit eigener Konfiguration, die Datenbanken dnsmgr_t21_cli/dnsmgr_t21_clio und den
# Benutzer h2acli@localhost an und entfernt alles am Ende.

set -uo pipefail
APP="${1:-}"; BASE_SCHEMA="${2:-}"
[ -d "$APP/web" ] && [ -f "$BASE_SCHEMA" ] || { echo "Aufruf: bash cli_test.sh <App-Verzeichnis> <Basis-Schema.sql>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
W="$(mktemp -d /tmp/dnsmgr-h2a-cli.XXXXXX)"; chmod 700 "$W"
PASS=0; FAIL=0
cleanup() {
    mariadb -e "DROP DATABASE IF EXISTS dnsmgr_t21_cli; DROP DATABASE IF EXISTS dnsmgr_t21_clio; DROP USER IF EXISTS 'h2acli'@'localhost'"
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
sed -i -E -e "s/define\('DB_USER', *'[^']*'\)/define('DB_USER', 'h2acli')/" -e "s/define\('DB_PASS', *'[^']*'\)/define('DB_PASS', '$PW')/" \
    -e "s/define\('DB_HOST', *'[^']*'\)/define('DB_HOST', 'localhost')/" -e "s/define\('LOG_TARGET', *'[^']*'\)/define('LOG_TARGET', 'apache')/" "$c"
chmod 600 "$c"
for d in cli clio; do
    mariadb -e "DROP DATABASE IF EXISTS dnsmgr_t21_$d; CREATE DATABASE dnsmgr_t21_$d CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
done
grep -vE '^(CREATE DATABASE|USE )' "$APP/sql/dnsmanager_schema.sql" | mariadb dnsmgr_t21_cli
mariadb dnsmgr_t21_clio < "$BASE_SCHEMA"
for d in cli clio; do mariadb dnsmgr_t21_$d < "$H/fixture.sql"; done
mariadb -e "CREATE USER 'h2acli'@'localhost' IDENTIFIED BY '$PW';
  GRANT SELECT, INSERT, UPDATE, DELETE ON dnsmgr_t21_cli.* TO 'h2acli'@'localhost';
  GRANT SELECT, INSERT, UPDATE, DELETE ON dnsmgr_t21_clio.* TO 'h2acli'@'localhost'"

run 2 "L01 ohne Argument" dnsmgr_t21_cli
run 2 "L02 unbekanntes Argument" dnsmgr_t21_cli --force
run 1 "L03 --check vor der Baseline (DML-Benutzer, SchemaCheck über information_schema)" dnsmgr_t21_cli --check
ok "$(has out 'Zonen: 4, mit History-Head: 0, ohne: 4' && echo 1)" "L03b Ausgabe nennt Zonen ohne Head"
run 0 "L04 --dry-run" dnsmgr_t21_cli --dry-run
ok "$(has out '^OK: mode=dry_run zonen=4 angelegt=0 geplant=4' && echo 1)" "L04b Prüflauf"
ok "$([ "$(mariadb -N -e 'SELECT COUNT(*) FROM dnsmgr_t21_cli.zone_revisions')" = 0 ] && echo 1)" "L04c Prüflauf schreibt nichts"
run 0 "L05 --apply" dnsmgr_t21_cli --apply
ok "$(has out '^OK: mode=apply zonen=4 angelegt=4 geplant=0 übersprungen=0' && echo 1)" "L05b Baseline angelegt"
ok "$(has err 'event=history_baseline_done mode=apply' && has err 'event=history_baseline_zone zone_id=1 zone=alpha.h2a.test revision=1' && echo 1)" "L05c Logzeilen"
ok "$(! grep -qE 'H2AFIXTURE|H2ATOKEN|\$2y\$|Warning|Deprecated|Notice' "$W/out" "$W/err" && echo 1)" "L05d keine Geheimnisse, keine PHP-Meldungen"
run 0 "L06 --check nach der Baseline" dnsmgr_t21_cli --check
run 0 "L07 --apply erneut (wiederholbar)" dnsmgr_t21_cli --apply
ok "$(has out 'angelegt=0 geplant=0 übersprungen=4' && echo 1)" "L07b alle übersprungen"
mariadb -e "SELECT GET_LOCK('dnsmanager_publish_all', 0); DO SLEEP(4)" > /dev/null & LP=$!
sleep 1
run 2 "L08 Advisory-Lock belegt" dnsmgr_t21_cli --apply
ok "$(has out 'Advisory-Lock dnsmanager_publish_all belegt' && echo 1)" "L08b Meldung"
wait $LP
run 2 "L09 --check ohne Migration 002 (fail-closed)" dnsmgr_t21_clio --check
ok "$(has out 'Schema nicht passend: history_change_sets fehlt; zone_snapshots fehlt; zone_revisions fehlt; zone_history_state fehlt' && echo 1)" "L09b Befunde"
run 2 "L10 --apply ohne Migration 002 (fail-closed)" dnsmgr_t21_clio --apply
ok "$([ "$(php "$W/app/web/scripts/schema_history-cli.php" sql | grep -c 'information_schema')" -ge 5 ] && echo 1)" "L11 schema_history-cli.php sql"
printf 'x\n' | php "$W/app/web/scripts/schema_history-cli.php" evaluate > /dev/null 2>&1; ok "$([ $? = 2 ] && echo 1)" "L12 schema_history-cli.php evaluate: ungültige Eingabe ⇒ Exit 2"

echo "ERGEBNIS cli: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
