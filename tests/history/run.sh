#!/bin/bash
# Datei: tests/history/run.sh
# Zweck: Alle History-Tests (AP1/H2a, H2b, H2c, H2d), AS3, AS4a, AS4b und AS5 reproduzierbar auf einem festen Commit ausführen (Testsystem ns1, als root).
#
#   bash run.sh <App-SHA> <Basis-SHA> [<Generator-Basis-SHA>]
#     <App-SHA>   zu prüfender Commit (40 Zeichen)
#     <Basis-SHA> Commit, dessen sql/dnsmanager_schema.sql den Stand vor Migration 002 darstellt
#     <Generator-Basis-SHA> optional (H2d): Commit, gegen dessen Generator die Zonendateien byte-gleich sein müssen
#                 (Standard: <Basis-SHA>; für H2d der Stand vor H2d, z. B. origin/main; für AS3 der Stand vor AS3, gegen
#                 den zusätzlich alter Code auf neuem Schema und die Unverändertheit von web/ und agent/ geprüft werden)
#
# Ablauf: eigener Klon ($REPO, Standard GitHub) → git archive beider Commits in ein frisches Verzeichnis unter /tmp →
#   1. reine Tests (pure_test.php) inkl. unabhängiger Prüfung des Vertragsvektors mit sha256sum
#   2. Migrationstests (migration_test.sh)
#   3. Datenbanktests der Baseline unter REPEATABLE READ (innodb_snapshot_isolation = ON) und READ COMMITTED
#   4. CLI-Test mit DML-Benutzer (cli_test.sh)
#   5. H2b: Migration 003, statischer R-H8-Nachweis, Recorder-Tests (RR und RC), Mutationsproben
#   6. H2c: Migration 004, Audit-Tests (RR und RC); statischer R-A-Nachweis und Mutationsproben in 5
#   7. H2d: Migration 005, reine Journal-Tests (Vektor rh1 zusätzlich mit sha256sum), Journal-Tests unter RR (mit
#      Byte-Vergleich der Zonendateien gegen den Basis-Commit und Zeitüberschreitungsfällen) und RC gegen eine isolierte
#      Zweiserver-Umgebung mit dem echten Agent-Code (127.0.0.4/127.0.0.5, Sandbox unter /tmp); statischer Nachweis und
#      Mutationsproben in 5. /etc/bind und die installierten Agenten werden nicht berührt (Nachweis Z01).
#   8. AS3: Migration 006 (migration006_test.sh), Datenvertrag zone_server_verification, 32-Bit-Serials mit dem echten
#      Generator, altes/neues Schema mit altem/neuem Code und statische Nachweise (as3_test.php, RR und RC)
#   9. AS4a: Migration 007 (migration007_test.sh), Kompatibilitätsbrücke als Werkzeugsicht, CHECK-Vertrag 007, Code ×
#      Schema und Journalablauf vor/nach 007 (as4a_test.php, RR und RC)
#  10. AS4b: Fencing im Core, Antwortauswertung, zone_server_verification, Monitoring (as4b_test.php, RR und RC) und
#      Mutationsproben (as4b_mutation_test.sh)
#  11. AS5: reine Tests (RFC-1982- und Policy-Vektoren, Brute-Force im reduzierten Modell, Referenzmodell, Parser),
#      Migration 008 (migration008_test.sh), Serial-Strategie gegen den Ersatz-Agenten (as5_test.php, RR und RC) und
#      Mutationsproben (as5_mutation_test.sh); Rückfallstand für Code × Schema und Lücken ist der AS4b-Code (AS5_BASE)
# Vergleichsstände der Code-×-Schema-Gruppen sind feste Commits auf main (unabhängig von <Generator-Basis-SHA>):
#   as3_test.php  ← Stand vor AS3  (AS3_BASE),  as4a_test.php ← Stand vor AS4a (AS4A_BASE = AS3-Code),
#   as4b_test.php ← Stand vor AS4b (AS4B_BASE = AS4a-Code), as5_test.php ← Stand vor AS5 (AS5_BASE = AS4b-Code, Rückfallstand)
# Nur isolierte Datenbanken dnsmgr_t21_r<RUN>_* und der Benutzer h2a<RUN> (Lauf-Kennung H2A_RUN, 8 Hex-Zeichen; optional
# vorgebbar, sonst zufällig). Eigentum nach tests/history/owned.sh: Jede Suite legt ihre Ressourcen ohne IF NOT EXISTS an
# und trägt sie danach in das gemeinsame Register dieses Laufs ein; aufgeräumt werden ausschließlich registrierte
# Ressourcen – nie fremde Datenbanken oder Benutzer, auch nicht solche, die während des Laufs entstehen.
# Die installierte Anwendung, ihre Datenbank und /etc/dnsmanager werden nicht berührt. Der Advisory-Lock
# 'dnsmanager_publish_all' ist serverweit: Die Tests belegen ihn nur kurz; ist er beim Start belegt, bricht der Lauf ab.

set -uo pipefail
SHA="${1:-}"; BASE="${2:-}"; GBASE="${3:-${2:-}}"
[[ "$SHA" =~ ^[0-9a-f]{40}$ && "$BASE" =~ ^[0-9a-f]{40}$ && "$GBASE" =~ ^[0-9a-f]{40}$ ]] || { echo "Aufruf: bash run.sh <App-SHA> <Basis-SHA> [<Generator-Basis-SHA>] (je 40 Zeichen)"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "als root ausführen"; exit 2; }
REPO="${REPO:-https://github.com/Twilight-Networks/dnsmanager.git}"

AS3_BASE=2b5a854c4d083f903d498b789962e4dc6ac5b0e4
AS4A_BASE=abf8361546516de3e7ff875acafeef36657a2813
AS4B_BASE=17241fb90360cef2add3453603f4d9c1b8149ee2
AS5_BASE=156f9344ea3d57a9ee89ae414fc9a69031b8d983
echo "History-Tests H2a/H2b/H2c/H2d/AS3/AS4a/AS4b/AS5 – $(hostname) $(date '+%F %T')"
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
for s in "$SHA" "$BASE" "$GBASE" "$AS3_BASE" "$AS4A_BASE" "$AS4B_BASE" "$AS5_BASE"; do git -C "$T/repo" cat-file -e "$s^{commit}" || { echo "ABBRUCH: Commit $s unbekannt"; exit 1; }; done
mkdir -m 750 "$T/app"; git -C "$T/repo" archive "$SHA" | tar -x -C "$T/app"
git -C "$T/repo" show "$BASE:sql/dnsmanager_schema.sql" | grep -vE '^(CREATE DATABASE|USE )' > "$T/base_schema.sql"
mkdir -m 750 "$T/base"; git -C "$T/repo" archive "$GBASE" | tar -x -C "$T/base"
for b in "as3:$AS3_BASE" "as4a:$AS4A_BASE" "as4b:$AS4B_BASE" "as5:$AS5_BASE"; do
    mkdir -m 750 "$T/base_${b%%:*}"; git -C "$T/repo" archive "${b#*:}" | tar -x -C "$T/base_${b%%:*}"
done
echo "Anwendung: Commit $SHA Tree $(git -C "$T/repo" rev-parse "$SHA^{tree}")"
echo "Basis:     Commit $BASE (Schema vor Migration 002); Generator-Basis $GBASE"
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
# AP1/H2c: Migration 004, Audit-Ereignisse unter RR und RC
bash "$H/migration004_test.sh" "$T/app" "$T/base_schema.sql" || RC=1
php "$H/audit_test.php" "$T/app" RR || RC=1
php "$H/audit_test.php" "$T/app" RC || RC=1
# AP1/H2d: Migration 005, Publish-Journal rein und gegen die isolierte Zweiserver-Umgebung (RR mit Basis-Byte-Vergleich, RC)
bash "$H/migration005_test.sh" "$T/app" "$T/base_schema.sql" || RC=1
php "$H/journal_pure_test.php" "$T/app" || RC=1
for kind in file render; do
    want="$(grep -oE "^const VECTOR_$(echo $kind | tr a-z A-Z)_HASH = '[0-9a-f]{64}'" "$H/journal_pure_test.php" | grep -oE '[0-9a-f]{64}')"
    opt="--dump-file"; [ "$kind" = render ] && opt="--dump-masked"
    got="$(php "$H/journal_pure_test.php" "$T/app" "$opt" | sha256sum | cut -c1-64)"
    if [ "$got" = "$want" ]; then echo "  ok   V02 rh1-Vektor ($kind): sha256sum = eingefrorener Hash"; else echo "  FAIL V02 rh1-Vektor ($kind): $got ≠ $want"; RC=1; fi
done
php "$H/journal_test.php" "$T/app" RR "$T/base" || RC=1
H2D_SLOW=0 php "$H/journal_test.php" "$T/app" RC "$T/base" || RC=1
# AS3: Migration 006, Datenvertrag, 32-Bit-Serials, Code/Schema-Kombinationen, statische Nachweise (Generator-Basis = Stand vor AS3)
bash "$H/migration006_test.sh" "$T/app" "$T/base_schema.sql" || RC=1
php "$H/as3_test.php" "$T/app" RR "$T/base_as3" || RC=1
php "$H/as3_test.php" "$T/app" RC "$T/base_as3" || RC=1
# AS4a: Migration 007, Brücke, CHECK-Vertrag, Code × Schema, Journalablauf vor/nach 007 (Generator-Basis = Stand vor AS4a)
bash "$H/migration007_test.sh" "$T/app" "$T/base_schema.sql" || RC=1
php "$H/as4a_test.php" "$T/app" RR "$T/base_as4a" || RC=1
php "$H/as4a_test.php" "$T/app" RC "$T/base_as4a" || RC=1
# AS4b: Fencing, Antworten, Persistenz, Monitoring (Ersatz-Agent ohne Netzwerk und ohne named), Mutationsproben
php "$H/as4b_test.php" "$T/app" RR "$T/base_as4b" || RC=1
php "$H/as4b_test.php" "$T/app" RC "$T/base_as4b" || RC=1
bash "$H/as4b_mutation_test.sh" "$T/app" "$T/base_as4b" || RC=1
# AS5: Serial-Strategie (rein, Migration 008, Datenbank unter RR und RC gegen den Ersatz-Agenten, Mutationsproben)
php "$H/as5_pure_test.php" "$T/app" || RC=1
bash "$H/migration008_test.sh" "$T/app" "$T/base_schema.sql" "$T/base_as5" || RC=1
php "$H/as5_test.php" "$T/app" RR "$T/base_as5" || RC=1
php "$H/as5_test.php" "$T/app" RC "$T/base_as5" || RC=1
bash "$H/as5_mutation_test.sh" "$T/app" "$T/base_as5" || RC=1
[ "${H2B_SKIP_MUTATION:-0}" = 1 ] && echo "Hinweis: Mutationsproben übersprungen (H2B_SKIP_MUTATION=1)" || bash "$H/mutation_test.sh" "$T/app" || RC=1

echo "GESAMT: $([ $RC = 0 ] && echo 'alle Suiten bestanden' || echo 'FEHLER')"
exit $RC
