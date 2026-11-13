#!/bin/bash
# S42 D6 – Extensions der produktiven Agent-CA prüfen (ns1, als root). Exit 0 = BESTANDEN, 1 = ABBRUCH.
# Aufruf: s42-ca-ext-check.sh [CA-Datei]  (Standard: /etc/dnsmanager/agent-ca.pem)
CA=${1:-/etc/dnsmanager/agent-ca.pem}
fail() { echo "ABBRUCH D6: $*"; exit 1; }
[ "$(grep -c 'BEGIN CERTIFICATE' "$CA")" = 1 ] || fail "CA-Datei enthält nicht genau ein Zertifikat"
ext() { openssl x509 -in "$CA" -noout -ext "$1" 2>/dev/null; }
[ "$(ext basicConstraints)" = "$(printf 'X509v3 Basic Constraints: critical\n    CA:TRUE, pathlen:0')" ] || fail "basicConstraints: $(ext basicConstraints | tr '\n' ' ')"
[ "$(ext keyUsage)" = "$(printf 'X509v3 Key Usage: critical\n    Certificate Sign, CRL Sign')" ] || fail "keyUsage: $(ext keyUsage | tr '\n' ' ')"
expected=$(printf '%s\n' 'X509v3 Name Constraints: critical' '    Permitted:' \
  '      IP:127.0.0.1/255.255.255.255' '      IP:192.168.53.125/255.255.255.255' \
  '      DNS:ns1' '      DNS:ns2' '      DNS:ns1.example.test' '      DNS:ns2.example.test')
[ "$(ext nameConstraints)" = "$expected" ] || fail "nameConstraints weichen ab: $(ext nameConstraints | tr '\n' ' ')"
openssl verify -CAfile "$CA" -no-CApath -no-CAstore -x509_strict "$CA" >/dev/null 2>&1 || fail "CA-Zertifikat nicht selbstsigniert gültig"
echo "D6 BESTANDEN: basicConstraints, keyUsage und nameConstraints der Agent-CA wie festgelegt"
