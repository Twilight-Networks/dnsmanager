#!/bin/bash
# Datei: tests/history/run.sh
# Zweck: Alle History-Tests (AP1/H2a) reproduzierbar auf einem festen Commit ausführen (Testsystem ns1, als root).
#
#   bash run.sh <App-SHA> <Basis-SHA>
#     <App-SHA>   zu prüfender Commit (40 Zeichen)
#     <Basis-SHA> Commit, dessen sql/dnsmanager_schema.sql den Stand vor Migration 002 darstellt (z. B. origin/main)
#
# Ablauf: eigener Klon ($REPO, Standard GitHub) → git archive beider Commits in ein frisches Verzeichnis unter /tmp →
#   1. reine Tests (pure_test.php) inkl. unabhängiger Prüfung des Vertragsvektors mit sha256sum
#   2. Migrationstests (migration_test.sh)
#   3. Datenbanktests der Baseline unter REPEATABLE READ (innodb_snapshot_isolation = ON) und READ COMMITTED
#   4. CLI-Test mit DML-Benutzer (cli_test.sh)
# Nur isolierte Datenbanken dnsmgr_t21_* und der Benutzer h2acli; die installierte Anwendung, ihre Datenbank und
# /etc/dnsmanager werden nicht berührt. Der Advisory-Lock 'dnsmanager_publish_all' ist serverweit: Die Tests belegen
# ihn nur kurz; ist er beim Start belegt, bricht der Lauf ab. Aufräumen am Ende (auch bei Abbruch).

set -uo pipefail
SHA="${1:-}"; BASE="${2:-}"
[[ "$SHA" =~ ^[0-9a-f]{40}$ && "$BASE" =~ ^[0-9a-f]{40}$ ]] || { echo "Aufruf: bash run.sh <App-SHA> <Basis-SHA> (je 40 Zeichen)"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "als root ausführen"; exit 2; }
REPO="${REPO:-https://github.com/Twilight-Networks/dnsmanager.git}"

echo "History-Tests H2a – $(hostname) $(date '+%F %T')"
# Vorprüfungen VOR dem Einrichten des Aufräumens: Ein Abbruch hier entfernt nichts (auch keine fremden dnsmgr_t21_*)
[ "$(mariadb -N -e "SELECT IS_FREE_LOCK('dnsmanager_publish_all')")" = 1 ] || { echo "ABBRUCH: Publish-Sperre belegt"; exit 3; }
[ -z "$(mariadb -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'dnsmgr\\_t21\\_%'")" ] || { echo "ABBRUCH: Datenbanken dnsmgr_t21_* vorhanden (nicht von diesem Lauf) – keine Änderung"; exit 3; }
[ "$(mariadb -N -e "SELECT COUNT(*) FROM mysql.user WHERE User = 'h2acli'")" = 0 ] || { echo "ABBRUCH: Benutzer h2acli vorhanden – keine Änderung"; exit 3; }

T="$(mktemp -d /tmp/dnsmgr-h2a-run.XXXXXX)"; chmod 700 "$T"
cleanup() {
    local d
    for d in $(mariadb -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'dnsmgr\\_t21\\_%'"); do
        mariadb -e "DROP DATABASE IF EXISTS \`$d\`"
    done
    mariadb -e "DROP USER IF EXISTS 'h2acli'@'localhost'"
    rm -rf "$T"
}
trap cleanup EXIT

git clone -q "$REPO" "$T/repo" || { echo "ABBRUCH: Klon"; exit 1; }
for s in "$SHA" "$BASE"; do git -C "$T/repo" cat-file -e "$s^{commit}" || { echo "ABBRUCH: Commit $s unbekannt"; exit 1; }; done
mkdir -m 750 "$T/app"; git -C "$T/repo" archive "$SHA" | tar -x -C "$T/app"
git -C "$T/repo" show "$BASE:sql/dnsmanager_schema.sql" | grep -vE '^(CREATE DATABASE|USE )' > "$T/base_schema.sql"
echo "Anwendung: Commit $SHA Tree $(git -C "$T/repo" rev-parse "$SHA^{tree}")"
echo "Basis:     Commit $BASE (Schema vor Migration 002)"
echo "Umgebung:  $(mariadb -N -e 'SELECT VERSION()'), global $(mariadb -N -e 'SELECT @@global.transaction_isolation, @@global.innodb_snapshot_isolation' | tr '\t' ' '), PHP $(php -r 'echo PHP_VERSION;')"

RC=0
H="$T/app/tests/history"
php "$H/pure_test.php" "$T/app" || RC=1
v1="$(php "$H/pure_test.php" "$T/app" --dump-vector | sha256sum | cut -c1-64)"
v2="$(grep -oE "^const VECTOR_HASH = '[0-9a-f]{64}'" "$H/pure_test.php" | grep -oE '[0-9a-f]{64}')"
if [ "$v1" = "$v2" ]; then echo "  ok   V01 Vertragsvektor: sha256sum = eingefrorener Hash ($v1)"; else echo "  FAIL V01 Vertragsvektor: sha256sum $v1 ≠ $v2"; RC=1; fi
bash "$H/migration_test.sh" "$T/app" "$T/base_schema.sql" || RC=1
php "$H/db_test.php" "$T/app" RR || RC=1
php "$H/db_test.php" "$T/app" RC || RC=1
bash "$H/cli_test.sh" "$T/app" "$T/base_schema.sql" || RC=1

echo "GESAMT: $([ $RC = 0 ] && echo 'alle Suiten bestanden' || echo 'FEHLER')"
exit $RC
