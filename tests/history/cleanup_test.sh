#!/bin/bash
# Datei: tests/history/cleanup_test.sh
# Zweck: Nachweis, dass das Aufräumen der History-Tests ausschließlich eigene Ressourcen entfernt (Review S2), auch wenn
#        während eines laufenden Testlaufs fremde Datenbanken und Benutzer entstehen.
#
# Aufruf (als root auf dem Testsystem): bash cleanup_test.sh <App-SHA> <Basis-SHA>
#
# Ablauf: run.sh läuft im Hintergrund mit vorgegebener Lauf-Kennung. Sobald dessen erste eigene Datenbank existiert,
# legt dieser Test – nebenläufig zum Lauf – fremde Ressourcen an:
#   F1  dnsmgr_t21_fremd_<x>          beliebige fremde Testdatenbank
#   F2  dnsmgr_t21_r<anderer Lauf>_m1 gleiches Namensschema, anderer Lauf
#   F3  dnsmgr_t21_r<dieser Lauf>_fremd  gleiches Präfix wie der laufende Lauf, aber nicht von ihm angelegt
#   U1  h2a<anderer Lauf>@localhost   fremder Benutzer im Namensschema der Tests
# Danach: run.sh erfolgreich, alle fremden Ressourcen vorhanden, alle Ressourcen des Laufs entfernt. Zum Schluss
# entfernt dieser Test nur die Ressourcen, die er selbst angelegt hat.

set -uo pipefail
SHA="${1:-}"; BASE="${2:-}"
[[ "$SHA" =~ ^[0-9a-f]{40}$ && "$BASE" =~ ^[0-9a-f]{40}$ ]] || { echo "Aufruf: bash cleanup_test.sh <App-SHA> <Basis-SHA>"; exit 2; }
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
hex() { head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n'; }
RUN=$(hex); OTHER=$(hex); X=$(hex)
P="dnsmgr_t21_r${RUN}_"
F1="dnsmgr_t21_fremd_${X}"; F2="dnsmgr_t21_r${OTHER}_m1"; F3="${P}fremd"; U1="h2a${OTHER}"
W="$(mktemp -d /tmp/dnsmgr-h2a-cleanup.XXXXXX)"; chmod 700 "$W"
MINE=""   # von diesem Test angelegte Ressourcen
PASS=0; FAIL=0
cleanup() {
    local r
    for r in $MINE; do
        case "$r" in
            db:*)   mariadb -e "DROP DATABASE IF EXISTS \`${r#db:}\`" ;;
            user:*) mariadb -e "DROP USER IF EXISTS '${r#user:}'@'localhost'" ;;
        esac
    done
    rm -rf "$W"
}
trap cleanup EXIT
ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }
dbs() { mariadb -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '$1' ORDER BY 1"; }
exists_db() { [ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$1'")" = 1 ]; }
exists_user() { [ "$(mariadb -N -e "SELECT COUNT(*) FROM mysql.user WHERE User = '$1' AND Host = 'localhost'")" = 1 ]; }

echo "== Cleanup-Eigentum (S2): Lauf $RUN, fremd $F1 $F2 $F3 $U1"
for d in "$F1" "$F2" "$F3"; do exists_db "$d" && { echo "ABBRUCH: $d existiert bereits"; exit 3; }; done
exists_user "$U1" && { echo "ABBRUCH: $U1 existiert bereits"; exit 3; }

H2A_RUN="$RUN" bash "$H/run.sh" "$SHA" "$BASE" > "$W/run.log" 2>&1 &
PID=$!
seen=0
for i in $(seq 1 600); do
    exists_db "${P}m1" && { seen=1; break; }
    kill -0 $PID 2>/dev/null || break
    sleep 0.2
done
ok "$seen" "C01 erste Datenbank des laufenden Laufs beobachtet (${P}m1)"
# Fremde Ressourcen nebenläufig zum Lauf anlegen
mariadb -e "CREATE DATABASE \`$F1\`" && MINE="$MINE db:$F1"
mariadb -e "CREATE DATABASE \`$F2\`" && MINE="$MINE db:$F2"
mariadb -e "CREATE DATABASE \`$F3\`" && MINE="$MINE db:$F3"
mariadb -e "CREATE USER '$U1'@'localhost' IDENTIFIED BY '$(hex)$(hex)'" && MINE="$MINE user:$U1"
mariadb -e "CREATE TABLE \`$F3\`.marker (id INT PRIMARY KEY); INSERT INTO \`$F3\`.marker VALUES (42)"
ok "$(kill -0 $PID 2>/dev/null && echo 1)" "C02 fremde Ressourcen während des laufenden Laufs angelegt"
wait $PID; rc=$?
grep -E "^Lauf-Kennung|ERGEBNIS|GESAMT|FAIL" "$W/run.log" | sed 's/^/       /'
ok "$([ $rc = 0 ] && echo 1)" "C03 run.sh erfolgreich (Exit $rc)"
ok "$(grep -q "^Lauf-Kennung: $RUN " "$W/run.log" && echo 1)" "C04 run.sh verwendete die vorgegebene Lauf-Kennung"
ok "$(exists_db "$F1" && echo 1)" "C05 fremde Datenbank $F1 erhalten"
ok "$(exists_db "$F2" && echo 1)" "C06 Datenbank eines anderen Laufs $F2 erhalten"
ok "$(exists_db "$F3" && [ "$(mariadb -N -e "SELECT id FROM \`$F3\`.marker")" = 42 ] && echo 1)" "C07 fremde Datenbank mit gleichem Laufpräfix $F3 samt Inhalt erhalten"
ok "$(exists_user "$U1" && echo 1)" "C08 fremder Benutzer $U1 erhalten"
ok "$([ "$(dbs "${P//_/\\_}%" | tr '\n' ' ')" = "$F3 " ] && echo 1)" "C09 alle eigenen Datenbanken des Laufs entfernt (nur $F3 übrig)"
ok "$(! exists_user "h2a$RUN" && echo 1)" "C10 eigener Benutzer h2a$RUN entfernt"
ok "$([ -z "$(ls -d /tmp/dnsmgr-h2a-run.* /tmp/dnsmgr-h2a-mig.* /tmp/dnsmgr-h2a-cli.* /tmp/dnsmgr-h2a-db-* 2>/dev/null)" ] && echo 1)" "C11 keine Arbeitsverzeichnisse des Laufs übrig"

echo "ERGEBNIS cleanup: $PASS bestanden, $FAIL fehlgeschlagen"
[ "$FAIL" = 0 ]
