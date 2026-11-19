#!/bin/bash
# Datei: tests/agent/as2a_as1_regression.sh
# Zweck: AS1-Regression (tests/agent/as1_test.py, unverändert) gegen den AS2a-Prüfstand.
#
# Die AS1-Suite führt den Agenten in einer Datei-Sandbox mit rndc-Platzhalter aus; zu dieser Sandbox gibt es keinen
# passenden BIND. Die AS2a-Vorprüfung nach E103 (Konfiguration und ausgelieferter Zustand des echten named) wäre dort in
# observe/enforce nie erfüllbar. Deshalb ersetzt dieses Skript AUSSCHLIESSLICH in einer Kopie des Prüfstands den Rumpf von
# agentPreTransferGate() durch "zulassen" (Muster der Mutationsproben). Alle übrigen AS2a-Änderungen (Prozessfristen,
# Sperre mit Frist, Exit-Codes, effect) bleiben aktiv. Der Produktionscode enthält keinen solchen Schalter; der Modus off
# (Golden G der AS1-Suite) ist von der Ersetzung nicht betroffen.
#
# Aufruf (root, Test-VM): as2a_as1_regression.sh <Prüfstand> <Referenz-Checkout Basis> <Ergebnisverzeichnis> [Gruppen]
set -euo pipefail
SRC=$(readlink -f "$1"); REF=$(readlink -f "$2"); OUT=$3; SUITE_GROUPS=${4:-FSICLXGM}
COPY=$(mktemp -d /tmp/as2a-as1src.XXXXXX)
cp -a "$SRC/." "$COPY/"
F="$COPY/agent/inc/dns_state.php"
OLD='    return dnsPreTransferCheck($config, $mode, $zoneName, $newSerial, $newBytes, $repair);'
NEW="    return ['allow' => true, 'idempotent' => false, 'http' => 200, 'code' => null, 'message' => null, 'report' => ['mode' => \$mode, 'decision' => 'as1_regression']];"
[ "$(grep -cF "$OLD" "$F")" = 1 ] || { echo "Ersetzungsstelle nicht eindeutig" >&2; exit 2; }
python3 - "$F" "$OLD" "$NEW" <<'PY'
import sys
p, old, new = sys.argv[1:4]
s = open(p).read()
open(p, 'w').write(s.replace(old, new))
PY
diff -u "$SRC/agent/inc/dns_state.php" "$F" > "$OUT.gate.diff" || true
echo "Ersetzung: $OUT.gate.diff"
set +e
python3 "$SRC/tests/agent/as1_test.py" --src "$COPY" --ref "$REF" --out "$OUT" --groups "$SUITE_GROUPS"
rc=$?
rm -rf "$COPY"
exit $rc
