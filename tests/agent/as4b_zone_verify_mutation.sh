#!/bin/bash
# Datei: tests/agent/as4b_zone_verify_mutation.sh
# Zweck: Mutationsprobe zu as4b_zone_verify_test.php: In einer Kopie des Checkouts wird die Einbindung von
#        inc/bind_utils.php aus agent/api/v1/zones/zone_verify.php entfernt (Stand vor dem Fix). Der Endpunkt-Test muss
#        das erkennen (Exit ≠ 0 und mindestens ein FAIL); der unveränderte Checkout muss bestehen.
# Aufruf (root, Testsystem wie as4b_zone_verify_test.php): bash tests/agent/as4b_zone_verify_mutation.sh <Checkout>
set -uo pipefail
APP="${1:-}"
[ -f "$APP/agent/api/v1/zones/zone_verify.php" ] || { echo "Aufruf: bash as4b_zone_verify_mutation.sh <Checkout>"; exit 2; }
T="$APP/tests/agent/as4b_zone_verify_test.php"
W="$(mktemp -d /tmp/dnsmgr-as4b-zvmut.XXXXXX)"; chmod 755 "$W"
trap 'rm -rf "$W"' EXIT
PASS=0; FAIL=0
res() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }

php "$T" "$APP" > "$W/base.log" 2>&1; rc=$?
res "$([ "$rc" = 0 ] && echo 1)" "ZM00 unveränderter Checkout besteht ($(grep -c '^  ok' "$W/base.log") ok, Exit $rc)"

cp -a "$APP" "$W/m"
f="$W/m/agent/api/v1/zones/zone_verify.php"
n="$(grep -c "inc/bind_utils.php" "$f")"
sed -i "\#require_once __DIR__ . '/../../../inc/bind_utils.php';#d" "$f"
res "$([ "$n" = 1 ] && [ "$(grep -c 'inc/bind_utils.php' "$f")" = 0 ] && echo 1)" "ZM01a Mutation angewendet (Einbindung entfernt)"
php "$W/m/tests/agent/as4b_zone_verify_test.php" "$W/m" > "$W/m.log" 2>&1; rc=$?
res "$([ "$rc" != 0 ] && grep -q '^  FAIL Z0[1-6]' "$W/m.log" && echo 1)" "ZM01 Mutation erkannt ($(grep -oE '^  FAIL Z0[0-9]' "$W/m.log" | awk '{print $2}' | tr '\n' ' ')Exit $rc)"

echo "ERGEBNIS as4b_zone_verify_mutation: $PASS bestanden, $FAIL fehlgeschlagen"
exit $([ "$FAIL" = 0 ] && echo 0 || echo 1)
