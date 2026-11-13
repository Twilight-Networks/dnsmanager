#!/bin/bash
# S42-E5: Prüft auf dem jeweiligen Host (als root) die tatsächliche VirtualHost-Auswahl und Zertifikatsauslieferung
# nach dem Aktivieren der Site dnsmanager-agent-tls. Beim ersten Fehler: "ABBRUCH" und Exit-Code 1.
# Aufruf: ns1: s42-vhost-check.sh 127.0.0.1 192.168.53.122 <UI-Fingerprint vor Phase 2>
#         ns2: s42-vhost-check.sh 192.168.53.125
set -uo pipefail
AGENT_IP=$1; UI_IP=${2:-}; UI_FP=${3:-}
SITE=${SITE:-/etc/apache2/sites-enabled/dnsmanager-agent-tls.conf}
CRT=${CRT:-/etc/ssl/dnsmanager/agent.crt}

ok()   { echo "OK      $*"; }
fail() { echo "FEHLER  $*"; echo "ABBRUCH – VirtualHost-Prüfung nicht bestanden"; exit 1; }
served_fp() { echo | timeout 10 openssl s_client -connect "$1" ${2:+-servername "$2"} 2>/dev/null \
                | openssl x509 -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2; }

apache2ctl configtest >/dev/null 2>&1 && ok "apache2ctl configtest" || fail "apache2ctl configtest fehlgeschlagen"
apache2ctl -S 2>/dev/null | awk -v a="$AGENT_IP:443" -v s="($SITE:1)" '$1 == a && $NF == s {f = 1} END {exit !f}' \
  && ok "apache2ctl -S: $AGENT_IP:443 wird von $SITE bedient" || fail "apache2ctl -S: $AGENT_IP:443 nicht dem Agent-VirtualHost zugeordnet"
exp=$(openssl x509 -in "$CRT" -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2) || true
[ -n "$exp" ] || fail "$CRT nicht lesbar"
# IP-basierte Auswahl muss unabhängig vom gesendeten SNI-Namen gelten
for sni in "" ns1 ns2 ns1.example.test dnsmanager.invalid; do
  got=$(served_fp "$AGENT_IP:443" "$sni")
  [ "$got" = "$exp" ] && ok "$AGENT_IP:443 (SNI ${sni:-ohne}): Agent-Zertifikat ausgeliefert" \
    || fail "$AGENT_IP:443 (SNI ${sni:-ohne}): ausgeliefert ${got:-nichts}, erwartet $exp"
done
if [ -n "$UI_IP" ]; then
  for sni in "" ns1 ns1.example.test; do
    got=$(served_fp "$UI_IP:443" "$sni")
    [ "$got" = "$UI_FP" ] && ok "$UI_IP:443 (SNI ${sni:-ohne}): Zertifikat der Oberfläche unverändert" \
      || fail "$UI_IP:443 (SNI ${sni:-ohne}): ausgeliefert ${got:-nichts}, erwartet unverändert $UI_FP"
  done
fi
echo "VIRTUALHOST-PRÜFUNG BESTANDEN"
