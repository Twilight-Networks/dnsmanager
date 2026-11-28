#!/bin/bash
# Datei: tests/history/as4b_mutation_test.sh
# Zweck: Mutationsproben für AS4b. Jede Mutation entfernt oder verfälscht genau eine Schutzregel in einer Kopie der
#        Anwendung; die zugehörigen Gruppen von as4b_test.php (Auswahl über AS4B_ONLY) müssen sie erkennen (rot werden).
#        Eine nicht erkannte Mutation ist ein Fehler dieser Suite. Isolierte Datenbanken wie in as4b_test.php.
#
# Aufruf (als root auf dem Testsystem): bash as4b_mutation_test.sh <App-Verzeichnis> <AS4a-App-Verzeichnis>

set -uo pipefail
APP="${1:-}"; BASEAPP="${2:-}"
[ -d "$APP/web" ] && [ -d "$BASEAPP/web" ] || { echo "Aufruf: bash as4b_mutation_test.sh <App-Verzeichnis> <AS4a-App-Verzeichnis>"; exit 2; }
W="$(mktemp -d /tmp/dnsmgr-as4b-mut.XXXXXX)"; chmod 700 "$W"
trap 'rm -rf "$W"' EXIT
PASS=0; FAIL=0
ok() { if [ "$1" = 1 ]; then PASS=$((PASS + 1)); echo "  ok   $2"; else FAIL=$((FAIL + 1)); echo "  FAIL $2"; fi; }

# mutate <ID> <Datei relativ> <alt> <neu> <Gruppen> <Beschreibung> [<alt2> <neu2>]
mutate() {
    local id="$1" file="$2" old="$3" new="$4" groups="$5" desc="$6" m="$W/$1"
    rm -rf "$m"; cp -a "$APP" "$m"
    if ! OLD="$old" NEW="$new" php -r '$f = $argv[1]; $s = file_get_contents($f); $n = substr_count($s, getenv("OLD"));
            if ($n !== 1) { fwrite(STDERR, "Treffer: $n\n"); exit(1); } file_put_contents($f, str_replace(getenv("OLD"), getenv("NEW"), $s));' "$m/$file"; then
        ok 0 "$id Mutation nicht anwendbar ($file)"; return
    fi
    # optionales zweites Paar in derselben Datei (eine Schutzregel an zwei Stellen, AS5: B01)
    if [ -n "${7:-}" ] && ! OLD="$7" NEW="${8:-}" php -r '$f = $argv[1]; $s = file_get_contents($f); $n = substr_count($s, getenv("OLD"));
            if ($n !== 1) { fwrite(STDERR, "Treffer: $n\n"); exit(1); } file_put_contents($f, str_replace(getenv("OLD"), getenv("NEW"), $s));' "$m/$file"; then
        ok 0 "$id Mutation (zweites Paar) nicht anwendbar ($file)"; return
    fi
    AS4B_ONLY="$groups" php "$m/tests/history/as4b_test.php" "$m" RR "$BASEAPP" > "$W/$id.log" 2>&1
    local rc=$? hits
    hits=$(grep -c '^  FAIL' "$W/$id.log")
    if [ "$rc" != 0 ] && [ "$hits" -gt 0 ]; then
        ok 1 "$id erkannt – $desc ($(grep -m3 '^  FAIL' "$W/$id.log" | awk '{print $3}' | tr '\n' ' '))"
    else
        ok 0 "$id NICHT erkannt – $desc (Exit $rc)"; tail -5 "$W/$id.log" | sed 's/^/       /'
    fi
    rm -rf "$m"
}

J=web/inc/app/publish/PublishJournal.php
D=web/inc/deploy/bind_file_distributor.php
FC=web/inc/app/publish/FenceContext.php
ST=web/inc/app/publish/ServerVerificationStore.php
P=web/inc/app/publish/AgentResponseParser.php
MS=web/inc/app/monitoring/MonitoringService.php
echo "== Mutationsproben AS4b"
# AS5: Anker von B01/B02/B10 an den geänderten Code angepasst (Mutation inhaltlich unverändert). B01 entfernt seit dem
# Review AS5 beide Laufprüfungen vor zone_sync (vor und nach dem Write-ahead); die zweite allein prüft as5 C32.
mutate B01 "$J" '    public function beforeAgentCall(): array
    {
        $this->ledger->assertRunAlive($this->run);
' '    public function beforeAgentCall(): array
    {
' L 'keine Laufprüfung vor dem Agent-Aufruf (E83)' '            $this->ledger->assertRunAlive($this->run);
        }

        return $fields;' '        }

        return $fields;'
mutate B02 "$D" '        $fail($sid, PublishTargetClassifier::localFailure($e instanceof SerialReferenceLost ? '"'"'serial_intent_failed'"'"' : '"'"'run_lock_lost'"'"', $e->getMessage()));
        throw $e;' '        return [];' L 'Lock-Verlust verschluckt, Verteilung läuft weiter'
mutate B03 "$FC" 'if (!hash_equals((string)$db, $this->configuredDomain)) {' 'if (false) {' F 'Domänenvergleich DB/Konfiguration entfernt (E74)'
mutate B04 "$J" "'fence_token' => \$this->run->id," "'fence_token' => \$this->run->id + 1," F 'Token ≠ registrierte Lauf-ID (F5)'
mutate B05 "$FC" '} elseif ($a['"'"'high'"'"'] >= $next) {' '} elseif ($a['"'"'high'"'"'] > $next + 1000000) {' R 'Restore-Abgleich wirkungslos (E81)'
mutate B06 "$ST" "if (\$obs['state'] === 'verified') {" "if (\$obs['state'] !== 'unknown') {" O 'positiver Nachweis auch bei negativer Beobachtung überschrieben (E77)'
mutate B07 "$ST" '            return $newKey > $rowKey;' '            return true;' O 'veraltete Beobachtung angenommen (Ordnungsschlüssel)'
mutate B08 "$ST" "        return isset(self::ACCEPT_EQUAL[(string)\$row['last_method'] . '>' . \$newMethod]);" '        return true;' O 'Rangfolge bei gleichem Schlüssel ignoriert (K3)'
mutate B09 "$MS" '->fetchColumn() !== $watermark) {' '->fetchColumn() < 0) {' M 'Wasserstand bei der Speicherung nicht erneut geprüft'
mutate B10 "$MS" "        \$locked = (int)\$this->pdo->query(\"SELECT GET_LOCK('\" . PublishLedger::MAIN_LOCK . \"', 0)\")->fetchColumn() === 1;" "        \$locked = true;" M 'Speicherung ohne Publish-Lock'
mutate B11 "$P" '$serial !== null && $hash !== null && $consistent' '$serial !== null && $hash !== null' P 'installierter Stand ohne R1-Konsistenz übernommen'
mutate B12 "$P" "if (\$classification !== 'ok' || \$fs === null" "if (\$fs === null" P 'verified trotz Einordnung unknown'
mutate B13 "$ST" '        if (count($ids) !== 1) {' '        if ($ids === []) {' C 'Cleanup-Paar trotz mehrerer Kandidaten zugeordnet'
mutate B14 "$ST" '        return $assigned ? null : ' '        return false ? null : ' C 'Cleanup-Beobachtung für noch zugewiesene Zone'
mutate B15 web/inc/app/SchemaCheck.php 'bool $acceptPre007 = false' 'bool $acceptPre007 = true' X 'AS4b akzeptiert das Schema vor 007 (nicht strikt)'
mutate B16 "$ST" "                \$this->pdo->beginTransaction();
                \$stmt = \$this->pdo->prepare('SELECT * FROM zone_server_verification" "                \$this->pdo->beginTransaction();
                if (str_contains(\$zoneName, 'beta')) { throw new RuntimeException('as4b_mut'); }
                \$stmt = \$this->pdo->prepare('SELECT * FROM zone_server_verification" J 'Beobachtung fehlt bei verified (Schreibpfad unterbrochen)'
# Review R2/R3
mutate B17 "$P" "if (\$expected === null || self::zoneName(\$v['zone'] ?? null) !== \$expected) {" 'if (false) {' P,J,M 'Zonenidentität der Verifikation nicht geprüft (R2)'
mutate B18 "$P" "self::zoneName(\$v['zone'] ?? null) !== \$expected" "(\$v['zone'] ?? null) !== \$zone" P 'Zonenvergleich ohne DNS-Namensnormalisierung (R2)'
mutate B19 "$P" '        if ($invalid !== []) {' '        if (false) {' P,M 'ungültige zone_verify-Antwort herabgestuft und gespeichert statt verworfen (R3)'
mutate B20 "$P" "            if (\$v[\$k] !== null && \$parsed === null) {" '            if (false) {' P,M 'fehlerhaft geformte Rohwerte stillschweigend verworfen (R3)'
mutate B22 "$P" '            if (!array_key_exists($k, $v)) {' '            if (false) {' P 'fehlendes Grundfeld wie null behandelt (R3-Nachtrag)'
mutate B23 "$P" "        if (\$v['state'] === 'unknown' && \$reason === null) {" '        if (false) {' P 'unknown ohne gültigen Grund akzeptiert (R3-Nachtrag)'
mutate B21 "$J" "\$raw, \$c, (string)\$attempt['zoneName']);" "\$raw, \$c, 'beta.h2b.test');" J 'Publish prüft gegen eine feste statt die Zone des Versuchs (R2)'

echo "ERGEBNIS as4b_mutation: $PASS erkannt, $FAIL nicht erkannt"
exit $([ "$FAIL" = 0 ] && echo 0 || echo 1)
