#!/bin/bash
# Datei: tests/history/run.sh
# Zweck: Alle History-Tests (AP1/H2a, H2b) reproduzierbar auf einem festen Commit ausführen (Testsystem ns1, als root).
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
#   5. H2b: Migration 003, statischer R-H8-Nachweis, Recorder-Tests (RR und RC), Mutationsproben
# Nur isolierte Datenbanken dnsmgr_t21_r<RUN>_* und der Benutzer h2a<RUN> (Lauf-Kennung H2A_RUN, 8 Hex-Zeichen; optional
# vorgebbar, sonst zufällig). Eigentum nach tests/history/owned.sh: Jede Suite legt ihre Ressourcen ohne IF NOT EXISTS an
# und trägt sie danach in das gemeinsame Register dieses Laufs ein; aufgeräumt werden ausschließlich registrierte
# Ressourcen – nie fremde Datenbanken oder Benutzer, auch nicht solche, die während des Laufs entstehen.
# Die installierte Anwendung, ihre Datenbank und /etc/dnsmanager werden nicht berührt. Der Advisory-Lock
# 'dnsmanager_publish_all' ist serverweit: Die Tests belegen ihn nur kurz; ist er beim Start belegt, bricht der Lauf ab.

set -uo pipefail
SHA="${1:-}"; BASE="${2:-}"
[[ "$SHA" =~ ^[0-9a-f]{40}$ && "$BASE" =~ ^[0-9a-f]{40}$ ]] || { echo "Aufruf: bash run.sh <App-SHA> <Basis-SHA> (je 40 Zeichen)"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "als root ausführen"; exit 2; }
REPO="${REPO:-https://github.com/Twilight-Networks/dnsmanager.git}"

echo "History-Tests H2a/H2b – $(hostname) $(date '+%F %T')"
export H2A_RUN="${H2A_RUN:-$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
[[ "$H2A_RUN" =~ ^[0-9a-f]{8}$ ]] || { echo "ABBRUCH: ungültige Lauf-Kennung H2A_RUN"; exit 2; }
echo "Lauf-Kennung: $H2A_RUN (Datenbanken dnsmgr_t21_r${H2A_RUN}_*, Benutzer h2a${H2A_RUN})"
[ "$(mariadb -N -e "SELECT IS_FREE_LOCK('dnsmanager_publish_all')")" = 1 ] || { echo "ABBRUCH: Publish-Sperre belegt"; exit 3; }

T="$(mktemp -d /tmp/dnsmgr-h2a-run.XXXXXX)"; chmod 700 "$T"
export H2A_REGISTRY="$T/owned"
: > "$H2A_REGISTRY"; chmod 600 "$H2A_REGISTRY"
cleanup() {
    # Sicherheitsnetz für abgebrochene Suiten: nur Ressourcen aus dem Register dieses Laufs
    # shellcheck source=owned.sh
    [ -f "$T/app/tests/history/owned.sh" ] && { source "$T/app/tests/history/owned.sh"; owned_cleanup "$H2A_REGISTRY"; }
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
# AP1/H2b: Migration 003, statischer R-H8-Nachweis, Recorder-Tests unter RR und RC, Mutationsproben
bash "$H/migration003_test.sh" "$T/app" "$T/base_schema.sql" || RC=1
php "$H/static_rh8_test.php" "$T/app" || RC=1
php "$H/recorder_test.php" "$T/app" RR || RC=1
php "$H/recorder_test.php" "$T/app" RC || RC=1
[ "${H2B_SKIP_MUTATION:-0}" = 1 ] && echo "Hinweis: Mutationsproben übersprungen (H2B_SKIP_MUTATION=1)" || bash "$H/mutation_test.sh" "$T/app" || RC=1

echo "GESAMT: $([ $RC = 0 ] && echo 'alle Suiten bestanden' || echo 'FEHLER')"
exit $RC
