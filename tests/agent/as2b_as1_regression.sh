#!/bin/bash
# Datei: tests/agent/as2b_as1_regression.sh
# Zweck: AS1-Regression (tests/agent/as1_test.py, unverändert) gegen den AS2b-Prüfstand.
#
# Wie tests/agent/as2a_as1_regression.sh: Die AS1-Suite arbeitet in einer Datei-Sandbox mit rndc-Platzhalter, zu der es
# keinen passenden BIND gibt. Deshalb werden AUSSCHLIESSLICH in einer Kopie des Prüfstands die Einstiegspunkte ersetzt,
# die den echten named voraussetzen: agentPreTransferGate() (AS2a, Vorher-Prüfung) und die AS2b-Nachverifikation
# (agentVerifyLoaded, agentCleanupNames, agentVerifyRemoved; Ergebnis jeweils unknown ohne Abfrage). Alle übrigen
# Änderungen bleiben aktiv. Der Produktionscode enthält keinen solchen Schalter; der Modus off ist nicht betroffen.
#
# Aufruf (root, Test-VM): as2b_as1_regression.sh <Prüfstand> <Referenz-Checkout Basis> <Ergebnisverzeichnis> [Gruppen]
set -euo pipefail
SRC=$(readlink -f "$1"); REF=$(readlink -f "$2"); OUT=$3; SUITE_GROUPS=${4:-FSICLXGM}
COPY=$(mktemp -d /tmp/as2b-as1src.XXXXXX)
cp -a "$SRC/." "$COPY/"
python3 - "$COPY" <<'PY'
import sys
root = sys.argv[1]
subs = [
    ('agent/inc/dns_state.php',
     '    return dnsPreTransferCheck($config, $mode, $zoneName, $newSerial, $newBytes, $repair);',
     "    return ['allow' => true, 'idempotent' => false, 'http' => 200, 'code' => null, 'message' => null, 'report' => ['mode' => $mode, 'decision' => 'as1_regression']];"),
    ('agent/inc/dns_verify.php',
     '    return verifyLoaded($config, $zoneName, $reloadEnd);',
     "    return verifyLoadedNotAttempted($zoneName, 'as1_regression');"),
    ('agent/inc/dns_verify.php',
     '    return verifyCleanupNames($config, $bases);',
     "    return ['names' => array_fill_keys(array_map('strval', $bases), ['name' => null, 'reason' => 'as1_regression']), 'proof' => ['state' => 'skipped', 'reason' => 'as1_regression', 'ms' => 0, 'remaining_ms' => 0]];"),
    ('agent/inc/dns_verify.php',
     '    return verifyRemoved($config, $names, $reloadStatus, $reloadEnd);',
     "    return verifyRemoved($config, array_map(static fn ($n) => ['name' => null, 'reason' => 'as1_regression'], $names), $reloadStatus, $reloadEnd);"),
]
for rel, old, new in subs:
    p = f'{root}/{rel}'
    s = open(p).read()
    if s.count(old) != 1:
        sys.exit(f'Ersetzungsstelle nicht eindeutig: {rel}')
    open(p, 'w').write(s.replace(old, new))
PY
diff -u "$SRC/agent/inc" "$COPY/agent/inc" > "$OUT.gate.diff" || true
echo "Ersetzungen: $OUT.gate.diff"
set +e
python3 "$SRC/tests/agent/as1_test.py" --src "$COPY" --ref "$REF" --out "$OUT" --groups "$SUITE_GROUPS"
rc=$?
rm -rf "$COPY"
exit $rc
