#!/bin/bash
# S42 Vorprüfung vor der Aktivierung der Agent-TLS-Prüfung (auf ns1, als root).
# Jede Prüfung wertet Exit-Codes bzw. exakte Werte aus. Beim ersten Fehler: Meldung "ABBRUCH" und Exit-Code 1.
# Keine Geheimnisse: der PHP-Test sendet absichtlich keinen Token (erwartet HTTP 401 vom Agenten).
set -uo pipefail
CA_FILE=${CA_FILE:-/etc/dnsmanager/agent-ca.pem}
# Agent-Ziele: "Adresse:Port|IP im SAN|erwarteter SHA-256-Fingerprint" (Fingerprints aus Schritt C)
AGENTS=${AGENTS:-"127.0.0.1:443|127.0.0.1|FP_NS1 192.168.53.125:443|192.168.53.125|FP_NS2"}
UI_TARGET=${UI_TARGET:-192.168.53.122:443}
UI_FP=${UI_FP:-FP_UI_VORHER}            # Fingerprint der Oberfläche vor Phase 2 (Schritt D0)
UI_URL=${UI_URL:-https://192.168.53.122/dnsmanager-ui/login.php}
API_BASE=${API_BASE:-/dnsmanager-agent/api/v1}
API_PATH=${API_PATH:-/system/status.php}
EXPECT_HTTP=${EXPECT_HTTP:-401}
MIN_DAYS=${MIN_DAYS:-30}
CLIENT=${CLIENT:-/usr/share/dnsmanager/web/inc/deploy/BindAgentClient.php}

ok()   { echo "OK      $*"; }
fail() { echo "FEHLER  $*"; echo "ABBRUCH – Vorprüfung nicht bestanden, keine Aktivierung"; exit 1; }
served_cert() { echo | timeout 10 openssl s_client -connect "$1" 2>/dev/null | openssl x509 2>/dev/null; }
fp() { openssl x509 -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2; }

# 1 Uhrzeit
[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ] && ok "Uhrzeit synchronisiert" || fail "Uhrzeit nicht synchronisiert"

# 2 CA-Datei: Rechte, Lesbarkeit für www-data, jedes Zertifikat CA:TRUE und ausreichend gültig
perm=$(stat -c '%a %U:%G' "$CA_FILE" 2>/dev/null) || fail "CA-Datei $CA_FILE fehlt"
[ "$perm" = "640 root:www-data" ] && ok "CA-Datei $CA_FILE: $perm" || fail "CA-Datei $CA_FILE: Rechte $perm statt 640 root:www-data"
sudo -u www-data openssl x509 -in "$CA_FILE" -noout 2>/dev/null && ok "CA-Datei für www-data lesbar, PEM gültig" || fail "CA-Datei für www-data nicht lesbar oder kein PEM"
d=$(mktemp -d); trap 'rm -rf "$d"' EXIT
awk -v d="$d" '/BEGIN CERTIFICATE/{n++} n{print > (d "/ca" n ".pem")}' "$CA_FILE"
for f in "$d"/ca*.pem; do
  [ -e "$f" ] || fail "CA-Datei enthält kein Zertifikat"
  s=$(openssl x509 -in "$f" -noout -subject 2>/dev/null) || fail "CA-Datei enthält ein ungültiges Zertifikat"
  openssl x509 -in "$f" -noout -ext basicConstraints 2>/dev/null | grep -qx '\s*CA:TRUE, pathlen:0' || fail "$s: kein CA-Zertifikat mit CA:TRUE, pathlen:0"
  openssl verify -CAfile "$f" -no-CApath -no-CAstore -x509_strict "$f" >/dev/null 2>&1 || fail "$s: CA-Zertifikat nicht selbstsigniert gültig"
  openssl x509 -in "$f" -noout -checkend $((MIN_DAYS*86400)) >/dev/null || fail "$s: läuft in weniger als $MIN_DAYS Tagen ab"
  ok "CA $s: gültig, mehr als $MIN_DAYS Tage"
done

# 3 je Agent-Ziel: ausgeliefertes Zertifikat, Kette/Zweck/SAN, Laufzeit, Handshake (OpenSSL), PHP/libcurl als www-data
for a in $AGENTS; do
  IFS='|' read -r t ip efp <<<"$a"
  cert=$(served_cert "$t"); [ -n "$cert" ] || fail "$t: kein Zertifikat abrufbar"
  sfp=$(echo "$cert" | fp)
  [ "$sfp" = "$efp" ] && ok "$t: ausgeliefertes Zertifikat ist das erwartete" || fail "$t: ausgeliefert $sfp, erwartet $efp (falscher VirtualHost oder falsches Zertifikat)"
  openssl verify -CAfile "$CA_FILE" -no-CApath -no-CAstore -purpose sslserver -verify_ip "$ip" <(echo "$cert") >/dev/null 2>&1 \
    && ok "$t: Kette, Verwendungszweck und IP-SAN $ip gegen Agent-CA gültig" || fail "$t: openssl verify gegen Agent-CA fehlgeschlagen"
  echo "$cert" | openssl x509 -noout -checkend $((MIN_DAYS*86400)) >/dev/null \
    && ok "$t: Restlaufzeit mehr als $MIN_DAYS Tage" || fail "$t: Zertifikat läuft in weniger als $MIN_DAYS Tagen ab"
  echo | timeout 10 openssl s_client -connect "$t" -CAfile "$CA_FILE" -no-CApath -no-CAstore -verify_ip "$ip" -verify_return_error >/dev/null 2>&1 \
    && ok "$t: TLS-Handshake mit Prüfung (OpenSSL) erfolgreich" || fail "$t: TLS-Handshake mit Prüfung (OpenSSL) fehlgeschlagen"
  res=$(sudo -u www-data php -- "$CLIENT" "$CA_FILE" "$t" "$API_BASE" "$API_PATH" "$EXPECT_HTTP" 2>&1 <<'PHP'
<?php
[, $client, $ca, $target, $base, $path, $expect] = $argv;
require $client;
$r = (new BindAgentClient($base, true, 2, $ca))->get($target, $path, '', 5, 10);
echo "HTTP {$r['httpCode']}, curlError=[{$r['curlError']}]";
exit($r['curlError'] === '' && $r['httpCode'] === (int)$expect ? 0 : 1);
PHP
  ) && ok "$t: PHP/libcurl als www-data: $res" || fail "$t: PHP/libcurl als www-data: $res (erwartet leerer curlError und HTTP $EXPECT_HTTP)"
done

# 4 Oberfläche unverändert (E5)
ucert=$(served_cert "$UI_TARGET"); [ -n "$ucert" ] || fail "$UI_TARGET: kein Zertifikat abrufbar"
[ "$(echo "$ucert" | fp)" = "$UI_FP" ] && ok "$UI_TARGET: Zertifikat der Oberfläche unverändert" || fail "$UI_TARGET: Zertifikat der Oberfläche geändert ($(echo "$ucert" | fp))"
code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "$UI_URL")
[ "$code" = 200 ] && ok "$UI_URL: HTTP 200" || fail "$UI_URL: HTTP $code"

echo "VORPRÜFUNG BESTANDEN"
