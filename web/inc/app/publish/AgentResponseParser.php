<?php
/**
 * Datei: inc/app/publish/AgentResponseParser.php
 * Zweck: Streng validierende Auswertung der zusätzlichen Agent-Antwortfelder (AS4b; docs/ARCHITECTURE_HISTORY.md
 *        Abschnitte 5f, 5g, 5i, 5j) für das Journal (Migration 007) und die L3-Beobachtung.
 *
 * Rein: keine Datenbank, kein Netzwerk. Eingabe ist das Ergebnis von BindAgentClient::postJson() und die bereits
 * gebildete Einordnung von PublishTargetClassifier (E63/E113, unverändert; operative Auswertung J8 unverändert).
 *
 * Regeln:
 * - Ohne gültiges Agent-JSON (Transportfehler, Zeitüberschreitung, kein JSON) gibt es keine Agent-Felder (Protokoll NULL).
 * - Protokoll: "as2", wenn die Antwort ein gültiges effect enthält; "legacy" ohne effect; ein ungültiges effect ⇒
 *   Protokoll NULL (nicht bestimmbar). Protokoll nur bei den Einordnungen ok, rejected, unknown (CHECK chk_*_agent).
 * - Installierter Stand (nur zone_sync, R1): Serial und sha256 gemeinsam, gültig, nur bei effect applied/unchanged und
 *   Reload-Bericht, und nur in den beiden Formen, die zone_sync tatsächlich liefert: Einordnung ok mit Reload ok bzw.
 *   unknown mit Reload failed/timeout/not_started. Ein technischer Rohwert – nie eine L2-/L3-Bestätigung.
 * - Verifikation (nur conf_sync, AS2b): verified nur bei Einordnung ok, beiden Serials gleich und gültigem Datei-Hash;
 *   serial_differs nur mit beiden, verschiedenen Serials. Jede ungültige oder unvollständige Angabe wird zu unknown mit
 *   Grund verification_invalid herabgestuft – nie zu einem positiven Nachweis (E77, E107).
 * - Zonenidentität (Review R2): Das Verifikationsobjekt muss die angefragte Zone nennen (verification.zone). Verglichen
 *   wird nach der verbindlichen DNS-Namensnormalisierung des Agenten (dnsNormName: Kleinbuchstaben, ohne abschließenden
 *   Punkt, gültige Namensform). Fehlende, ungültige oder abweichende Zone ⇒ ungültig (conf_sync: unknown mit
 *   verification_invalid; zone_verify: verworfen) – für jeden Zustand, damit nie eine Beobachtung der falschen Zone
 *   zugeordnet wird.
 * - zone_verify (Review R3): Nur ein vollständig gültiges Verifikationsobjekt ist eine Beobachtung (auch state unknown);
 *   ein fehlendes, ungültiges, unvollständiges oder widersprüchliches Objekt wird verworfen, nicht herabgestuft.
 *   Vollständig heißt: alle Felder des Grundschemas (VERIFY_FIELDS) vorhanden, nicht verfügbare Messwerte ausdrücklich
 *   null, Nicht-null-Werte streng gültig, bei unknown ein gültiger Grund. Für conf_sync gilt dieselbe Prüfung mit der
 *   bisherigen E77-Folge (Herabstufung zu unknown/verification_invalid).
 * - Cleanup (AS2b): Ergebnis je Zone (Name aus dem Konfigurationsnachweis des Agenten, served, served_reason,
 *   served_serial nur bei still_served aus einer konkreten DNS-Beobachtung) und Zähler.
 * Ungültige Einzelangaben werden verworfen (NULL) und unter "invalid" benannt.
 *
 * AS5 (D20/D21, docs/ARCHITECTURE_HISTORY.md Abschnitt 5k): serialCheck() wertet den Vorher-Bericht serial_check einer
 * zone_sync-Antwort (agent/inc/dns_state.php, E103) streng aus – nur vollständig gültige Berichte (alle Pflichtfelder
 * vorhanden, Zustände aus den Wertemengen, Serials nur bei valid bzw. served, new_serial = Serial des Versuchs) sind
 * Referenzwerte; sie sind nie ein positiver Nachweis. Ein vorhandener, aber ungültiger Bericht ist ein Referenzverlust.
 */

final class AgentResponseParser
{
    public const EFFECTS = ['none', 'unchanged', 'applied', 'partial', 'unknown'];
    public const RELOADS = ['ok', 'failed', 'timeout', 'not_started'];
    public const DECISIONS = ['accept', 'would_reject', 'reject'];
    public const VERIFY_STATES = ['verified', 'serial_differs', 'unknown'];
    /** Grundschema des Verifikationsobjekts (agent/inc/dns_verify.php, auch bei unknown vollständig; R3) */
    public const VERIFY_FIELDS = ['state', 'reason', 'zone', 'file_serial', 'file_sha256', 'served_serial'];
    public const SERVED = ['absent', 'still_served', 'unknown'];
    public const SERIAL_MAX = 4294967295;

    /**
     * @param string                    $kind PublishTargetClassifier::KIND_*
     * @param array<string, mixed>|null $r    Ergebnis von postJson() (null = kein Request, lokaler Fehler)
     * @param array{status: string}     $c    Einordnung (classify bzw. localFailure)
     * @param string|null               $zone conf_sync: Zone des registrierten Versuchs (Prüfung der Zonenidentität, R2)
     * @return array{protocol: ?string, effect: ?string, reload: ?string, fenceDecision: ?string, fenceCode: ?string,
     *               installedSerial: ?int, installedHash: ?string, verify: ?array, zones: ?array, invalid: string[]}
     */
    public static function parse(string $kind, ?array $r, array $c, ?string $zone = null): array
    {
        $out = ['protocol' => null, 'effect' => null, 'reload' => null, 'fenceDecision' => null, 'fenceCode' => null,
            'installedSerial' => null, 'installedHash' => null, 'verify' => null, 'zones' => null, 'invalid' => []];
        if ($r === null || !in_array($c['status'] ?? null, ['ok', 'rejected', 'unknown', 'partial'], true)) {
            return $out;
        }
        $body = $r['body'] ?? false;
        $json = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($json) || !isset($json['status']) || !is_string($json['status'])) {
            return $out;
        }
        if (!array_key_exists('effect', $json)) {
            $out['protocol'] = 'legacy';
            return $out;
        }
        if (!is_string($json['effect']) || !in_array($json['effect'], self::EFFECTS, true)) {
            $out['invalid'][] = 'effect';
            return $out;
        }
        $out['protocol'] = 'as2';
        $out['effect'] = $json['effect'];

        if (array_key_exists('reload', $json)) {
            $st = is_array($json['reload']) ? ($json['reload']['status'] ?? null) : null;
            if (is_string($st) && in_array($st, self::RELOADS, true)) {
                $out['reload'] = $st;
            } else {
                $out['invalid'][] = 'reload';
            }
        }
        if (array_key_exists('fence', $json)) {
            $f = is_array($json['fence']) ? $json['fence'] : [];
            $d = $f['decision'] ?? null;
            if (is_string($d) && in_array($d, self::DECISIONS, true)) {
                $out['fenceDecision'] = $d;
                $code = $f['code'] ?? null;
                $out['fenceCode'] = is_string($code) && preg_match('/^[a-z_]{1,32}$/D', $code) === 1 ? $code : null;
            } else {
                $out['invalid'][] = 'fence';
            }
        }

        if ($kind === PublishTargetClassifier::KIND_ZONE && array_key_exists('installed', $json)) {
            $inst = is_array($json['installed']) ? $json['installed'] : [];
            $serial = self::serial($inst['serial'] ?? null);
            $hash = self::hash($inst['sha256'] ?? null);
            $consistent = in_array($out['effect'], ['applied', 'unchanged'], true) && $out['reload'] !== null
                && (($c['status'] === 'ok' && $out['reload'] === 'ok')
                    || ($c['status'] === 'unknown' && in_array($out['reload'], ['failed', 'timeout', 'not_started'], true)));
            if ($serial !== null && $hash !== null && $consistent) {
                $out['installedSerial'] = $serial;
                $out['installedHash'] = $hash;
            } else {
                $out['invalid'][] = 'installed';
            }
        }

        if ($kind === PublishTargetClassifier::KIND_CONF && array_key_exists('verification', $json)) {
            $out['verify'] = self::verification($json['verification'], $c['status'], $zone, $out['invalid']);
        }

        if ($kind === PublishTargetClassifier::KIND_CLEANUP && array_key_exists('zones', $json)) {
            if (!is_array($json['zones']) || !array_is_list($json['zones'])) {
                $out['invalid'][] = 'zones';
            } else {
                $zones = [];
                foreach ($json['zones'] as $z) {
                    if (!is_array($z) || !is_string($z['served'] ?? null) || !in_array($z['served'], self::SERVED, true)) {
                        $out['invalid'][] = 'zones';
                        continue;
                    }
                    $name = $z['name'] ?? null;
                    $zones[] = [
                        'name'   => is_string($name) && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/D', $name) === 1 && strlen($name) <= 253 ? $name : null,
                        'served' => $z['served'],
                        'reason' => self::reason($z['served_reason'] ?? null),
                        'servedSerial' => $z['served'] === 'still_served' ? self::serial($z['served_serial'] ?? null) : null,
                    ];
                }
                $out['zones'] = $zones;
            }
        }

        return $out;
    }

    /**
     * Journalfelder (Migration 007) eines Kanals aus dem Parse-Ergebnis.
     *
     * @return array<string, mixed>
     */
    public static function journalFields(string $kind, array $p, ?int $unassigned = null): array
    {
        if ($kind === PublishTargetClassifier::KIND_ZONE) {
            return ['zone_protocol' => $p['protocol'], 'zone_effect' => $p['effect'], 'zone_installed_serial' => $p['installedSerial'],
                'zone_installed_hash' => $p['installedHash'], 'zone_reload' => $p['reload'],
                'zone_fence_decision' => $p['fenceDecision'], 'zone_fence_code' => $p['fenceCode']];
        }
        if ($kind === PublishTargetClassifier::KIND_CONF) {
            $v = $p['verify'];
            return ['conf_protocol' => $p['protocol'], 'conf_effect' => $p['effect'], 'conf_reload' => $p['reload'],
                'conf_fence_decision' => $p['fenceDecision'], 'conf_fence_code' => $p['fenceCode'],
                'conf_verify_state' => $v['state'] ?? null, 'conf_verify_reason' => $v['reason'] ?? null,
                'conf_verify_file_serial' => $v['fileSerial'] ?? null, 'conf_verify_file_hash' => $v['fileHash'] ?? null,
                'conf_verify_served_serial' => $v['servedSerial'] ?? null];
        }
        $counts = ['served_absent_count' => null, 'served_still_count' => null, 'served_unknown_count' => null, 'unassigned_count' => null];
        if ($p['protocol'] === 'as2' && is_array($p['zones'])) {
            $n = ['absent' => 0, 'still_served' => 0, 'unknown' => 0];
            foreach ($p['zones'] as $z) {
                $n[$z['served']]++;
            }
            $counts = ['served_absent_count' => $n['absent'], 'served_still_count' => $n['still_served'],
                'served_unknown_count' => $n['unknown'], 'unassigned_count' => $unassigned ?? 0];
        }

        return ['cleanup_protocol' => $p['protocol'], 'cleanup_effect' => $p['effect'],
            'fence_decision' => $p['fenceDecision'], 'fence_code' => $p['fenceCode']] + $counts;
    }

    /**
     * AS4b: Antwort von zones/zone_verify (Monitoring) für die angefragte Zone. Nur HTTP 200 mit status success und
     * einem vollständig gültigen Verifikationsobjekt dieser Zone ist eine Beobachtung. Sonst null mit Grund:
     * no_result (404 älterer Agent, 409 verification_disabled, Transportfehler, kein JSON/kein Objekt) bzw. invalid
     * (Objekt vorhanden, aber ungültig, unvollständig, widersprüchlich oder für eine andere Zone; R2, R3 – verworfen,
     * nicht als unknown gespeichert).
     *
     * @param-out string|null $why
     * @return array{state: string, reason: ?string, fileSerial: ?int, fileHash: ?string, servedSerial: ?int}|null
     */
    public static function parseZoneVerify(array $r, string $zone, ?string &$why = null): ?array
    {
        $why = 'no_result';
        if (($r['curlError'] ?? '') !== '' || (int)($r['curlErrno'] ?? 0) !== 0 || (int)($r['httpCode'] ?? 0) !== 200) {
            return null;
        }
        $json = is_string($r['body'] ?? null) ? json_decode((string)$r['body'], true) : null;
        if (!is_array($json) || ($json['status'] ?? null) !== 'success' || !array_key_exists('verification', $json)) {
            return null;
        }
        $invalid = [];
        $v = self::verification($json['verification'], 'ok', $zone, $invalid);
        if ($invalid !== []) {
            $why = 'invalid';
            return null;
        }
        $why = null;

        return $v;
    }

    /**
     * Verbindliche DNS-Namensnormalisierung (wie dnsNormName() des Agenten, agent/inc/dns_state.php).
     */
    /** Pflichtfelder des serial_check-Berichts (agent/inc/dns_state.php dnsPreTransferCheck) */
    public const SERIAL_CHECK_FIELDS = ['decision', 'code', 'new_serial', 'file_state', 'file_serial', 'file_sha256', 'config_state',
        'served_state', 'served_serial'];

    /**
     * AS5: serial_check einer zone_sync-Antwort.
     *
     * @param array<string, mixed>|null $r              Ergebnis von postJson() (null = kein Request)
     * @param int                       $expectedSerial Serial des registrierten Versuchs (Identität des Berichts)
     * @return array{state: string, report: ?array{decision: string, code: ?string, fileState: string, fileSerial: ?int,
     *               fileHash: ?string, configState: string, servedState: string, servedSerial: ?int}, why: ?string}
     *         state: absent (kein Bericht, keine JSON-Antwort) | valid | invalid
     */
    public static function serialCheck(?array $r, int $expectedSerial): array
    {
        $none = ['state' => 'absent', 'report' => null, 'why' => null];
        if ($r === null) {
            return $none;
        }
        $body = $r['body'] ?? false;
        $json = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($json) || !array_key_exists('serial_check', $json)) {
            return $none;
        }
        $sc = $json['serial_check'];
        $bad = static fn (string $why): array => ['state' => 'invalid', 'report' => null, 'why' => $why];
        if (!is_array($sc)) {
            return $bad('kein Objekt');
        }
        foreach (self::SERIAL_CHECK_FIELDS as $k) {
            if (!array_key_exists($k, $sc)) {
                return $bad("Feld $k fehlt");
            }
        }
        $decision = $sc['decision'];
        if (!is_string($decision) || !in_array($decision, self::DECISIONS, true)) {
            return $bad('decision');
        }
        $code = $sc['code'];
        if ($code !== null && (!is_string($code) || preg_match('/^[a-z_]{1,32}$/D', $code) !== 1)) {
            return $bad('code');
        }
        if (($decision === 'accept') !== ($code === null)) {
            return $bad('decision/code');
        }
        if (self::serial($sc['new_serial']) !== $expectedSerial) {
            return $bad('new_serial');
        }
        $fileState = $sc['file_state'];
        if (!is_string($fileState) || !in_array($fileState, ['missing', 'valid', 'invalid', 'unreadable'], true)) {
            return $bad('file_state');
        }
        $fileSerial = $sc['file_serial'] === null ? null : self::serial($sc['file_serial']);
        if (($fileState === 'valid') !== ($fileSerial !== null) || ($sc['file_serial'] !== null && $fileSerial === null)) {
            return $bad('file_serial');
        }
        $fileHash = $sc['file_sha256'] === null ? null : self::hash($sc['file_sha256']);
        if ($sc['file_sha256'] !== null && $fileHash === null) {
            return $bad('file_sha256');
        }
        $configState = $sc['config_state'];
        if (!is_string($configState) || !in_array($configState, ['yes', 'no', 'undeterminable'], true)) {
            return $bad('config_state');
        }
        $servedState = $sc['served_state'];
        if (!is_string($servedState) || !in_array($servedState, ['served', 'not_auth', 'unknown'], true)) {
            return $bad('served_state');
        }
        $servedSerial = $sc['served_serial'] === null ? null : self::serial($sc['served_serial']);
        if (($servedState === 'served') !== ($servedSerial !== null) || ($sc['served_serial'] !== null && $servedSerial === null)) {
            return $bad('served_serial');
        }
        if ($servedState === 'not_auth' && $configState !== 'no') {
            // Der Agent bildet not_auth nur bei K = nein; ein Widerspruch ist kein Messergebnis
            return $bad('not_auth ohne K = nein');
        }

        return ['state' => 'valid', 'why' => null, 'report' => ['decision' => $decision, 'code' => $code, 'fileState' => $fileState,
            'fileSerial' => $fileSerial, 'fileHash' => $fileHash, 'configState' => $configState, 'servedState' => $servedState,
            'servedSerial' => $servedSerial]];
    }

    public static function zoneName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $n = strtolower(rtrim($name, '.'));
        if ($n === '' || strlen($n) > 253 || preg_match('/^[a-z0-9_](?:[a-z0-9_.-]*[a-z0-9_])?$/D', $n) !== 1 || str_contains($n, '..')) {
            return null;
        }

        return $n;
    }

    /** @param string[] $invalid */
    private static function verification(mixed $v, string $classification, ?string $zone, array &$invalid): array
    {
        $down = static function () use (&$invalid): array {
            $invalid[] = 'verification';
            return ['state' => 'unknown', 'reason' => 'verification_invalid', 'fileSerial' => null, 'fileHash' => null, 'servedSerial' => null];
        };
        if (!is_array($v) || !is_string($v['state'] ?? null) || !in_array($v['state'], self::VERIFY_STATES, true)) {
            return $down();
        }
        // R2: Zonenidentität – angefragte und verifizierte Zone müssen nach Normalisierung gleich sein (jeder Zustand)
        $expected = self::zoneName($zone);
        if ($expected === null || self::zoneName($v['zone'] ?? null) !== $expected) {
            return $down();
        }
        $fs = self::serial($v['file_serial'] ?? null);
        $ss = self::serial($v['served_serial'] ?? null);
        $fh = self::hash($v['file_sha256'] ?? null);
        $reason = self::reason($v['reason'] ?? null);
        // R3: festes Grundschema des Agenten – alle Felder vorhanden (nicht verfügbare Werte ausdrücklich null); ein
        // fehlendes Feld ist nicht dasselbe wie null
        foreach (self::VERIFY_FIELDS as $k) {
            if (!array_key_exists($k, $v)) {
                return $down();
            }
        }
        // R3: vorhandene, aber fehlerhaft geformte Rohwerte ⇒ Objekt ungültig (nicht stillschweigend NULL)
        foreach (['file_serial' => $fs, 'served_serial' => $ss, 'file_sha256' => $fh, 'reason' => $reason] as $k => $parsed) {
            if ($v[$k] !== null && $parsed === null) {
                return $down();
            }
        }
        // R3: unknown nur mit gültigem Grund
        if ($v['state'] === 'unknown' && $reason === null) {
            return $down();
        }
        switch ($v['state']) {
            case 'verified':
                if ($classification !== 'ok' || $fs === null || $ss === null || $fs !== $ss || $fh === null) {
                    return $down();
                }
                return ['state' => 'verified', 'reason' => $reason, 'fileSerial' => $fs, 'fileHash' => $fh, 'servedSerial' => $ss];
            case 'serial_differs':
                if ($fs === null || $ss === null || $fs === $ss) {
                    return $down();
                }
                return ['state' => 'serial_differs', 'reason' => $reason, 'fileSerial' => $fs, 'fileHash' => $fh, 'servedSerial' => $ss];
            default:
                return ['state' => 'unknown', 'reason' => $reason, 'fileSerial' => $fs, 'fileHash' => $fh, 'servedSerial' => $ss];
        }
    }

    /** 32-Bit-Serial ohne Vorzeichen (JSON-Zahl oder Dezimalzeichenkette ohne führende Null) */
    public static function serial(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 && $v <= self::SERIAL_MAX ? $v : null;
        }
        if (is_string($v) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) === 1 && (int)$v <= self::SERIAL_MAX) {
            return (int)$v;
        }

        return null;
    }

    public static function hash(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[0-9a-f]{64}$/D', $v) === 1 ? $v : null;
    }

    public static function reason(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[a-z0-9_:]{1,64}$/D', $v) === 1 ? $v : null;
    }
}
