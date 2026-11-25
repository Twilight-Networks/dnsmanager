#!/bin/bash
# Datei: tests/agent/as4b_zone_verify_mutation.sh
# Zweck: Mutations- und Sicherheitsproben zu as4b_zone_verify_test.php.
#   ZM00  unveränderter Checkout besteht
#   ZM01  Einbindung von inc/bind_utils.php in zone_verify.php entfernt (Stand vor dem Fix) ⇒ Endpunkt-Test rot
#   ZM02  agent/config/api_config.php und api_tokens.php als Symlinks auf isolierte Dummy-Dateien (wie installierte
#         Checkouts auf /etc/dnsmanager/) ⇒ Test läuft, Link-Ziele byte-genau, mit Rechten und mtime unverändert
#   ZM03  weiterer Symlink im Checkout (agent/inc/) ⇒ Preflight-Abbruch (Exit 2) vor jeder Schreiboperation, Ziel
#         unverändert
# Sicherheit (Review PR #109): kein cp -a des Checkouts; übernommen werden nur agent/ (ohne agent/config/), der
# Endpunkt-Test und der Core-Parser, nachdem der Preflight Symlinks und Sonderdateien ausgeschlossen hat. Arbeits-
# verzeichnis 0700 root; die Dummy-Dateien liegen ausschließlich darin.
# Aufruf (root, Testsystem wie as4b_zone_verify_test.php): bash tests/agent/as4b_zone_verify_mutation.sh <Checkout>
set -uo pipefail
APP="${1:-}"
[ -f "$APP/agent/api/v1/zones/zone_verify.php" ] || { echo "Aufruf: bash as4b_zone_verify_mutation.sh <Checkout>"; exit 2; }
APP="$(cd "$APP" && pwd)"
PARTS="agent tests/agent/as4b_zone_verify_test.php web/inc/app/publish/AgentResponseParser.php"
bad="$(cd "$APP" && find $PARTS -path agent/config -prune -o \( -type l -o \( ! -type f ! -type d \) \) -print)"
[ -z "$bad" ] || { echo "ABBRUCH (Preflight): Symlinks bzw. Sonderdateien im Checkout: $bad"; exit 2; }
W="$(mktemp -d /tmp/dnsmgr-as4b-zvmut.XXXXXX)"; chmod 700 "$W"
trap 'rm -rf "$W"' EXIT
PASS=0; FAIL=0
res() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }
copy() {   # $1 Ziel – nur die benötigten Teile, ohne agent/config/
    mkdir -m 700 "$1"
    (cd "$APP" && tar --exclude=./agent/config --exclude=agent/config -cf - $PARTS) | tar -C "$1" -xf -
    [ -z "$(find "$1" -type l)" ]
}
T=tests/agent/as4b_zone_verify_test.php

php "$APP/$T" "$APP" > "$W/base.log" 2>&1; rc=$?
res "$([ "$rc" = 0 ] && echo 1)" "ZM00 unveränderter Checkout besteht ($(grep -c '^  ok' "$W/base.log") ok, Exit $rc)"

# ZM01 Mutation: Einbindung entfernt
copy "$W/m"
f="$W/m/agent/api/v1/zones/zone_verify.php"; n="$(grep -c "inc/bind_utils.php" "$f")"
sed -i "\#require_once __DIR__ . '/../../../inc/bind_utils.php';#d" "$f"
res "$([ "$n" = 1 ] && [ "$(grep -c 'inc/bind_utils.php' "$f")" = 0 ] && echo 1)" "ZM01a Mutation angewendet (Einbindung entfernt)"
php "$W/m/$T" "$W/m" > "$W/m.log" 2>&1; rc=$?
res "$([ "$rc" != 0 ] && grep -q '^  FAIL Z0[1-6]' "$W/m.log" && echo 1)" "ZM01 Mutation erkannt ($(grep -oE '^  FAIL Z0[0-9]' "$W/m.log" | awk '{print $2}' | tr '\n' ' ')Exit $rc)"

# Isolierte Dummy-Ziele (nie /etc/dnsmanager)
D="$W/dummy"; mkdir -m 700 "$D"
printf '<?php\nreturn [\x27fencing_mode\x27 => \x27off\x27, \x27dummy\x27 => \x27ZM-Konfiguration\x27];\n' > "$D/api_config.php"
printf '<?php\nreturn [\x27ZM-DUMMY-TOKEN\x27];\n' > "$D/api_tokens.php"
chmod 600 "$D/api_config.php"; chmod 604 "$D/api_tokens.php"; touch -d '2020-01-01 00:00:00' "$D"/*.php
snap() { (cd "$D" && sha256sum api_config.php api_tokens.php && stat -c '%a %U:%G %Y %s %i %n' api_config.php api_tokens.php); }
S0="$(snap)"

# ZM02 Symlinks auf die Dummy-Dateien an der Stelle, an der installierte Checkouts auf /etc/dnsmanager zeigen
copy "$W/s"; mkdir -m 700 "$W/s/agent/config"
ln -s "$D/api_config.php" "$W/s/agent/config/api_config.php"; ln -s "$D/api_tokens.php" "$W/s/agent/config/api_tokens.php"
php "$W/s/$T" "$W/s" > "$W/s.log" 2>&1; rc=$?
res "$([ "$rc" = 0 ] && grep -q '^  ok   Z00a' "$W/s.log" && echo 1)" "ZM02a Checkout mit Konfigurations-Symlinks: Test läuft ohne sie ($(grep -c '^  ok' "$W/s.log") ok, Exit $rc)"
res "$([ "$(snap)" = "$S0" ] && echo 1)" "ZM02 Link-Ziele byte-genau, Rechte, Eigentümer, mtime, Größe und Inode unverändert"

# ZM03 weiterer Symlink im Checkout ⇒ Preflight-Abbruch vor jeder Schreiboperation
copy "$W/x"; ln -s "$D/api_config.php" "$W/x/agent/inc/zm_link.php"
php "$W/x/$T" "$W/x" > "$W/x.log" 2>&1; rc=$?
res "$([ "$rc" = 2 ] && grep -q 'ABBRUCH (Preflight)' "$W/x.log" && [ "$(snap)" = "$S0" ] && [ -z "$(ls -d /tmp/dnsmgr-as4b-zv.* 2>/dev/null)" ] && echo 1)" "ZM03 Symlink in agent/inc ⇒ Preflight-Abbruch (Exit $rc), keine Sandbox, Ziel unverändert"

echo "ERGEBNIS as4b_zone_verify_mutation: $PASS bestanden, $FAIL fehlgeschlagen"
exit $([ "$FAIL" = 0 ] && echo 0 || echo 1)
