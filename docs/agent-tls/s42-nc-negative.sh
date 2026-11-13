#!/bin/bash
# S42-E7 Negativtest mit dem tatsächlichen PHP-cURL-Stack (auf ns1, als root, vor der Aktivierung).
# Testzertifikate (1 Tag) von der Agent-CA mit einem zusätzlichen, nicht erlaubten SAN müssen abgelehnt werden;
# Positivkontrolle mit dem echten Agent-Zertifikat von ns1 muss angenommen werden. Ergebnis über Exit-Code.
# Aufruf: s42-nc-negative.sh <Verzeichnis mit Testzertifikaten/-schlüsseln>
#   erwartet darin: nc-dns.pem/.key (SAN IP:127.0.0.1,DNS:ns1,DNS:nicht-erlaubt.invalid)
#                   nc-ip.pem/.key  (SAN IP:127.0.0.1,IP:192.168.53.122)
set -uo pipefail
DIR=$1
CA_FILE=${CA_FILE:-/etc/dnsmanager/agent-ca.pem}
POS_CERT=${POS_CERT:-/etc/ssl/dnsmanager/agent.crt}
POS_KEY=${POS_KEY:-/etc/ssl/dnsmanager/agent.key}
PORT=${PORT:-18443}
CLIENT=${CLIENT:-/usr/share/dnsmanager/web/inc/deploy/BindAgentClient.php}
rc=0

probe() {  # $1 Name, $2 Zertifikat, $3 Schlüssel, $4 erwartet: accept|reject
  openssl s_server -accept 127.0.0.1:$PORT -cert "$2" -key "$3" -www -quiet >/dev/null 2>&1 & local pid=$!
  for i in $(seq 1 25); do ss -ltn | grep -q "127.0.0.1:$PORT " && break; sleep 0.2; done
  local res
  res=$(sudo -u www-data php -- "$CLIENT" "$CA_FILE" "127.0.0.1:$PORT" "$4" 2>&1 <<'PHP'
<?php
[, $client, $ca, $target, $expect] = $argv;
require $client;
$r = (new BindAgentClient('', true, 2, $ca))->get($target, '/', '', 5, 10);
echo "HTTP {$r['httpCode']}, curlError=[{$r['curlError']}]";
$accepted = $r['curlError'] === '' && $r['httpCode'] === 200;
$rejected = $r['curlError'] !== '' && $r['httpCode'] === 0;
exit(($expect === 'accept' && $accepted) || ($expect === 'reject' && $rejected) ? 0 : 1);
PHP
  )
  local st=$?
  kill $pid 2>/dev/null; wait $pid 2>/dev/null
  if [ $st -eq 0 ]; then echo "OK      $1 (erwartet $4): $res"; else echo "FEHLER  $1 (erwartet $4): $res"; rc=1; fi
}

probe "Positivkontrolle echtes Agent-Zertifikat" "$POS_CERT" "$POS_KEY" accept
probe "zusätzlicher DNS-SAN außerhalb nameConstraints" "$DIR/nc-dns.pem" "$DIR/nc-dns.key" reject
probe "zusätzlicher IP-SAN außerhalb nameConstraints" "$DIR/nc-ip.pem" "$DIR/nc-ip.key" reject
[ $rc -eq 0 ] && echo "NEGATIVTEST BESTANDEN" || echo "NEGATIVTEST NICHT BESTANDEN – keine Aktivierung"
exit $rc
