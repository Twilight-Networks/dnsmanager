#!/bin/bash
# Datei: tests/ui/run.sh
# Zweck: UI-Golden-Basis (U0) auf einem dedizierten Testsystem (ns1) reproduzierbar ausführen.
#
#   run.sh record  <Commit-SHA>                 Ergebnis erzeugen (z. B. neue Basis)
#   run.sh compare <Commit-SHA> <basis.json> [ausnahmen.json]
#                                                Ergebnis erzeugen und mit der Basis vergleichen (Exit 1 bei Vertragsabweichung)
#   run.sh serve   <Commit-SHA>                 Testkopie je Rolle lokal ausliefern (Screenshots, siehe README)
#
# Ablauf: eigener GitHub-Klon → git archive des Commits nach /tmp/dnsmgr-uig/app; Konfiguration aus
# web/config/ui_config-sample.php (isolierte DB dnsmgr_uig_*, eigener DB-Benutzer, Log in Datei, Sprache de);
# Update-Quelle der Testkopie auf eine lokale Datei umgestellt; Agent-Platzhalter auf 127.0.0.2/127.0.0.3:443.
# Keine Änderung an der installierten Anwendung, ihrer Datenbank oder Konfiguration. Aufräumen am Ende.
# Ergebnisse bleiben unter $OUT_DIR (Standard /root/dnsmgr-uig-results).
set -uo pipefail
MODE=${1:-}; SHA=${2:-}; BASELINE=${3:-}; ALLOW=${4:-}
H=$(cd "$(dirname "$0")" && pwd)
T=/tmp/dnsmgr-uig
OUT_DIR=${OUT_DIR:-/root/dnsmgr-uig-results}
REPO=${REPO:-https://github.com/Twilight-Networks/dnsmanager.git}
[[ "$MODE" =~ ^(record|compare|serve)$ ]] && [[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "Aufruf: run.sh record|compare|serve <40-stelliger Commit-SHA> [basis.json] [ausnahmen.json]"; exit 2; }
[ "$MODE" = compare ] && [ ! -f "$BASELINE" ] && { echo "Basisdatei fehlt: $BASELINE"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "als root ausführen (php-cgi läuft per sudo als www-data)"; exit 2; }

cleanup() {
  [ -f $T/pids ] && kill $(cat $T/pids) 2>/dev/null
  mariadb -e "DROP DATABASE IF EXISTS dnsmgr_uig_tpl; DROP DATABASE IF EXISTS dnsmgr_uig_app; DROP USER IF EXISTS 'uigweb'@'localhost'" 2>/dev/null
  cd /; rm -rf $T
}

echo "UI-Golden U0 – $MODE $SHA – $(hostname) $(date '+%F %T')"
for ip in 127.0.0.2 127.0.0.3; do ss -ltn | grep -q " $ip:443 " && { echo "ABBRUCH: $ip:443 belegt"; exit 3; }; done
ss -ltn | grep -qE " (\*|0\.0\.0\.0|\[::\]):443 " && { echo "ABBRUCH: Port 443 auf allen Adressen gebunden – Platzhalter würden kollidieren"; exit 3; }
[ "$(mariadb -N -e "SELECT IS_FREE_LOCK('dnsmanager_publish_all')")" = 1 ] || { echo "ABBRUCH: Publish-Sperre belegt (serverweit, F7)"; exit 3; }
rm -rf $T; mkdir -m 711 $T; mkdir -m 700 $T/sess $T/k; chown www-data:www-data $T/sess; mkdir -m 750 $T/feed; chown root:www-data $T/feed
trap cleanup EXIT

git clone -q "$REPO" $T/repo || { echo "ABBRUCH: Klon"; exit 1; }
git -C $T/repo cat-file -e "$SHA^{commit}" || { echo "ABBRUCH: Commit unbekannt"; exit 1; }
mkdir -m 750 $T/app; git -C $T/repo archive "$SHA" | tar -x -C $T/app
echo "Anwendung: Commit $SHA Tree $(git -C $T/repo rev-parse "$SHA^{tree}")"
echo "Harness:   $H ($(git -C "$H" rev-parse HEAD 2>/dev/null || echo 'kein Git'))"

PW=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
c=$T/app/web/config/ui_config.php
cp $T/app/web/config/ui_config-sample.php $c
sed -i -E -e "s/define\('DB_NAME', *'[^']*'\)/define('DB_NAME', 'dnsmgr_uig_app')/" -e "s/define\('DB_USER', *'[^']*'\)/define('DB_USER', 'uigweb')/" \
  -e "s/define\('DB_PASS', *'[^']*'\)/define('DB_PASS', '$PW')/" -e "s/define\('LOG_TARGET', *'[^']*'\)/define('LOG_TARGET', 'apache')/" \
  -e "s/define\('DEFAULT_LANGUAGE', *'[^']*'\)/define('DEFAULT_LANGUAGE', 'de')/" $c
for k in "DB_NAME', 'dnsmgr_uig_app" "DB_USER', 'uigweb" "LOG_TARGET', 'apache" "DEFAULT_LANGUAGE', 'de"; do grep -q "define('$k')" $c || { echo "ABBRUCH: Konfiguration ($k)"; exit 1; }; done
f=$T/app/web/inc/deploy/CurlUpdateFeed.php
sed -i "s#https://www.twilight-networks.com/dnsmanager-update/latest.json#file://$T/feed/latest.json#" $f
grep -q "file://$T/feed/latest.json" $f || { echo "ABBRUCH: Update-Quelle der Testkopie"; exit 1; }
echo '{"version":"1.2.5","release_date":"2026-11-01","download_url":"https://example.test/download","changelog_url":"https://example.test/changelog"}' > $T/feed/latest.json
chmod 640 $T/feed/latest.json; chown root:www-data $T/feed/latest.json
chown -R root:www-data $T/app; chmod -R o-rwx,g-w $T/app; find $T/app -type d -exec chmod g+x {} +
: > $T/app.log; chown www-data:www-data $T/app.log; chmod 640 $T/app.log

grep -vE '^(CREATE DATABASE|USE )' $T/app/sql/dnsmanager_schema.sql > $T/schema.sql
for d in tpl app; do
  mariadb -e "DROP DATABASE IF EXISTS dnsmgr_uig_$d; CREATE DATABASE dnsmgr_uig_$d CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mariadb dnsmgr_uig_$d < $T/schema.sql
done
mariadb -e "DROP USER IF EXISTS 'uigweb'@'localhost'; CREATE USER 'uigweb'@'localhost' IDENTIFIED BY '$PW'; GRANT SELECT, INSERT, UPDATE, DELETE ON dnsmgr_uig_app.* TO 'uigweb'@'localhost'"
php $H/fixture.php | mariadb dnsmgr_uig_tpl || { echo "ABBRUCH: Fixture"; exit 1; }

openssl req -x509 -newkey rsa:2048 -nodes -keyout $T/k/s.key -out $T/k/s.pem -days 2 -subj "/CN=ui-golden-stub" 2>/dev/null
: > $T/stub.log; : > $T/pids
for ip in 127.0.0.2 127.0.0.3; do python3 $H/agent_stub.py $ip 443 $T/k/s.pem $T/k/s.key $T/stub.log >/dev/null 2>&1 & echo $! >> $T/pids; done
for i in $(seq 1 25); do [ "$(ss -ltn | grep -cE ' 127\.0\.0\.[23]:443 ')" = 2 ] && break; sleep 0.2; done
echo "Agent-Platzhalter: $(ss -ltn | grep -cE ' 127\.0\.0\.[23]:443 ')/2; PHP $(php -r 'echo PHP_VERSION;'); $(mariadb -N -e 'SELECT VERSION()')"

if [ "$MODE" = serve ]; then
  # Screenshot-Modus: je Rolle ein PHP-Entwicklungsserver nur auf 127.0.0.1 (Zugriff per SSH-Tunnel), bis $T/stop
  # existiert oder MAX_MINUTES (Standard 30) abgelaufen sind; danach Aufräumen wie üblich.
  php $H/serve_sessions.php || { echo "ABBRUCH: Sessions"; exit 1; }
  install -m 644 -o root -g root $H/serve_router.php $T/serve_router.php   # für www-data lesbar (Harness liegt ggf. unter /root)
  declare -A PORT=([admin]=18601 [zoneadmin]=18602 [anon]=18603)
  for role in admin zoneadmin anon; do
    ss -ltn | grep -q " 127.0.0.1:${PORT[$role]} " && { echo "ABBRUCH: Port ${PORT[$role]} belegt"; exit 3; }
    sid=""; [ -f $T/sid_$role ] && sid=$(cat $T/sid_$role)
    sudo -u www-data env -i PATH=/usr/bin:/bin UIG_SID="$sid" php -d session.save_path=$T/sess -d error_log=$T/app.log -d log_errors=1 -d display_errors=0 \
      -S 127.0.0.1:${PORT[$role]} $T/serve_router.php > /dev/null 2>&1 & echo $! >> $T/pids
  done
  sleep 1; echo "Screenshot-Server: $(ss -ltn | grep -cE ' 127\.0\.0\.1:1860[123] ')/3 (admin 18601, zoneadmin 18602, anonym 18603) – Stopp: touch $T/stop"
  for i in $(seq 1 $(( ${MAX_MINUTES:-30} * 12 ))); do [ -e $T/stop ] && break; sleep 5; done
  PAT='PHP (Fatal error|Warning|Notice|Deprecated|Parse error)|event=php_error'
  echo "PHP-Meldungen im Log: $(grep -cE "$PAT" $T/app.log)"; grep -E "$PAT" $T/app.log | sed -E 's/^\[[^]]+\] //; s/ time="[^"]+"//' | sort | uniq -c | head -10
  echo "Screenshot-Modus beendet"; exit 0
fi

php $H/cases.php $T/result.json "$SHA"; rc=$?
[ $rc = 0 ] || { echo "ABBRUCH: Fälle (Exit $rc)"; exit 1; }
echo "--- Agent-Platzhalter-Aufrufe:"; sort $T/stub.log | uniq -c
mkdir -p -m 700 "$OUT_DIR"
OUT="$OUT_DIR/ui-golden-${SHA:0:7}-$(date +%Y%m%d-%H%M%S).json"
cp $T/result.json "$OUT"; chmod 600 "$OUT"; echo "Ergebnis: $OUT"
rc=0
if [ "$MODE" = compare ]; then php $H/compare.php "$BASELINE" "$OUT" ${ALLOW:+"$ALLOW"}; rc=$?; fi
echo "Publish-Sperre danach frei: $(mariadb -N -e "SELECT IS_FREE_LOCK('dnsmanager_publish_all')"); Arbeitsverzeichnisse www-data übrig: $(find /tmp -maxdepth 1 -name 'dnsmanager-work-*' -user www-data | wc -l)"
exit $rc
