<?php
/**
 * Datei: inc/dns_verify.php
 * Zweck: Nachverifikation nach dem Reload (AS2b; docs/ARCHITECTURE_HISTORY.md Abschnitt 5g).
 *
 * - conf_sync (E107, E121): verified ⇔ K = ja, gültige verwaltete Datei und autoritative SOA mit der Serial dieser Datei;
 *   serial_differs nur aus der letzten vollständigen Runde; sonst unknown. Nie not_loaded (E77).
 * - zone_cleanup (E92, E122–E124): absent ⇔ Reload im selben Aufruf mit Exit-Code 0, K = nein und not_auth;
 *   still_served ⇔ autoritative SOA; sonst unknown. Zonennamen nur aus einem eindeutigen Konfigurationsnachweis vor der
 *   Änderung (E123).
 * - Polling (E106, E120): erste Runde unmittelbar nach dem Reload, danach 50 ms, verdoppelt bis höchstens 500 ms; Fenster
 *   höchstens 10 s nach Reload-Ende und nie über TW − 1 s hinaus (Abschnittsfrist, inc/proc.php). Je Runde höchstens eine
 *   Kontrollabfrage (Rundenspeicher in dnsServedState). Keine Beobachtung wird in eine spätere Runde übernommen.
 * - Die Nachverifikation ändert nie HTTP-Status, effect, message oder rollback (E107, E125).
 *
 * Verwendet ausschließlich die AS2a-Bausteine aus inc/dns_state.php und inc/proc.php (DNS-Abfrage, strenger Parser,
 * Konfigurations- und Dateinachweis, Prozessfristen). Nur in den Modi observe/enforce verwendet (E101).
 * Die Datei definiert ausschließlich Funktionen und Konstanten (kein Seiteneffekt beim Einbinden).
 */

require_once __DIR__ . '/dns_state.php';

/** Polling-Fenster nach Reload-Ende (Sekunden, E106) */
const VERIFY_WINDOW = 10.0;
/** Antwortreserve vor TW (Sekunden, E120) */
const VERIFY_RESPONSE_RESERVE = 1.0;
/** Wartezeit nach der ersten Runde und Obergrenze der Verdopplung (Sekunden, E120) */
const VERIFY_BACKOFF_FIRST = 0.05;
const VERIFY_BACKOFF_MAX = 0.5;
/** Vorabnachweis im Cleanup: Eintrittsbedingung (Restzeit bis TW, Sekunden; E120) */
const VERIFY_PREPROOF_MIN_REMAINING = 20.0;
/**
 * Vorabnachweis im Cleanup: Zeit, die bis TW in jedem Fall für die AS2a-Schritte nach dem Nachweis frei bleibt
 * (Preflight 3 s + Reload 5 s + Antwortreserve 1 s + Nachlauf eines abgebrochenen Prozesses 1 s = 10 s). Der Nachweis
 * läuft mit der Abschnittsfrist TW − 10 s.
 */
const VERIFY_PREPROOF_RESERVE = AGENT_CAP_CHECKCONF + AGENT_CAP_RNDC_RELOAD + VERIFY_RESPONSE_RESERVE + AGENT_PROC_KILL_MAX;
/** Mindestrestzeit nach dem Vorabnachweis (Preflight + Reload + Antwortreserve); sonst wird das Ergebnis verworfen */
const VERIFY_PREPROOF_AFTER_MIN = AGENT_CAP_CHECKCONF + AGENT_CAP_RNDC_RELOAD + VERIFY_RESPONSE_RESERVE;
/** Gründe von D, die sich innerhalb des Aufrufs nicht ändern (Konfiguration der Kontrollzone bzw. Eltern-ACL) */
const VERIFY_D_STRUCTURAL = ['control_zone_missing', 'control_zone_own_acl', 'control_zone_managed', 'refused_with_parent'];

// ------------------------------------------------------------------------------------------------------------------
// Reine Funktionen (ohne Ein-/Ausgabe)
// ------------------------------------------------------------------------------------------------------------------

/** Wartezeit nach Runde $n (n ≥ 1): 50 ms, verdoppelt, höchstens 500 ms. */
function verifyBackoff(int $n): float {
    return min(VERIFY_BACKOFF_FIRST * (2 ** max(0, $n - 1)), VERIFY_BACKOFF_MAX);
}

/** Ende des Polling-Fensters: min(Reload-Ende + 10 s, TW − 1 s). */
function verifyPollDeadline(float $reloadEnd, float $tw): float {
    return min($reloadEnd + VERIFY_WINDOW, $tw - VERIFY_RESPONSE_RESERVE);
}

/** Vorübergehender Grund eines unbestimmbaren Konfigurationsnachweises (erneute Prüfung in der nächsten Runde)? */
function verifyConfigTransient(?string $reason): bool {
    return $reason !== null && ($reason === 'zone_count_mismatch' || $reason === 'rndc_status_unparsable'
        || preg_match('/_(timeout|not_started|error)$/D', $reason) === 1);
}

/** Wurde ein Schritt wegen der Frist nicht gestartet (Runde unvollständig)? */
function verifyNotStarted(?string $reason): bool {
    return $reason !== null && str_ends_with($reason, '_not_started');
}

/** Dateizustand nur vorübergehend unbestimmt (named-checkzone innerhalb der Frist nicht abgeschlossen)? */
function verifyFileTransient(?array $f): bool {
    return $f !== null && $f['state'] === 'unreadable' && $f['reason'] === 'check_timeout';
}

/**
 * Polling-Steuerung: Runde 1 sofort, danach Wartezeiten nach verifyBackoff(); keine Runde beginnt nach $deadline, und
 * keine Wartezeit reicht über $deadline hinaus.
 *
 * @param callable $round fn(int $n): bool – true, wenn nichts mehr offen ist
 *
 * @return array{rounds: int, done: bool}
 */
function verifyPoll(float $deadline, callable $round, ?callable $now = null, ?callable $sleep = null): array {
    $now ??= static fn (): float => microtime(true);
    $sleep ??= static function (float $s): void {
        usleep((int)round($s * 1e6));
    };
    $n = 0;
    while ($now() < $deadline) {
        if ($round(++$n)) {
            return ['rounds' => $n, 'done' => true];
        }
        $wait = verifyBackoff($n);
        if ($now() + $wait >= $deadline) {
            break;
        }
        $sleep($wait);
    }

    return ['rounds' => $n, 'done' => false];
}

/**
 * Bewertung einer Runde in conf_sync (E121, R1–R3).
 *
 * @param array      $f          dnsZoneFileState() dieser Prüfsequenz
 * @param array|null $k          dnsZoneConfigState() aus einem nach dem Reload erzeugten Nachweis (null: nicht ermittelt)
 * @param bool       $kTransient K vorübergehend unbestimmbar
 * @param array|null $d          dnsServedState(), abgefragt nach K in dieser Runde (null: nicht abgefragt)
 *
 * @return array{final: bool, complete: bool, state: string, reason: ?string}
 *         final: Ergebnis steht fest (verified bzw. dauerhaft unknown); complete: Runde vollständig ausgeführt
 */
function verifyLoadedDecide(array $f, ?array $k, bool $kTransient, ?array $d): array {
    $r = static fn (bool $final, bool $complete, string $state, ?string $reason): array
        => ['final' => $final, 'complete' => $complete, 'state' => $state, 'reason' => $reason];
    if (verifyFileTransient($f)) {
        return $r(false, false, 'unknown', 'file_check_timeout');
    }
    if ($f['state'] !== 'valid') {
        return $r(true, true, 'unknown', 'file_' . $f['state']);
    }
    if ($k === null) {
        return $r(false, false, 'unknown', 'config_not_evaluated');
    }
    if ($k['state'] === 'undeterminable') {
        return $kTransient ? $r(false, !verifyNotStarted($k['reason']), 'unknown', 'config_' . $k['reason'])
            : $r(true, true, 'unknown', 'config_' . $k['reason']);
    }
    if ($k['state'] !== 'yes') {
        return $r(true, true, 'unknown', 'config_not_configured');
    }
    if ($d === null || verifyNotStarted($d['reason'])) {
        return $r(false, false, 'unknown', $d['reason'] ?? 'served_not_queried');
    }
    if ($d['state'] === 'served' && is_int($d['serial']) && is_int($f['serial'])) {
        return $d['serial'] === $f['serial'] ? $r(true, true, 'verified', null) : $r(false, true, 'serial_differs', null);
    }

    return $r(false, true, 'unknown', $d['reason'] ?? 'served_unknown');
}

/**
 * Bewertung einer Zone in einer Runde des Cleanups (E92, E124).
 *
 * @param bool  $reloadOk   Reload im selben Aufruf mit Exit-Code 0
 * @param array $k          dnsZoneConfigState() aus dem Nachweis dieser Runde (nach dem Reload)
 * @param bool  $kTransient Konfigurationsnachweis vorübergehend unbestimmbar
 * @param array $d          dnsServedState(), abgefragt nach K in dieser Runde
 *
 * @return array{final: bool, complete: bool, state: string, reason: ?string}
 */
function verifyRemovedDecide(bool $reloadOk, array $k, bool $kTransient, array $d): array {
    $r = static fn (bool $final, bool $complete, string $state, ?string $reason): array
        => ['final' => $final, 'complete' => $complete, 'state' => $state, 'reason' => $reason];
    if (verifyNotStarted($d['reason'])) {
        return $r(false, false, 'unknown', $d['reason']);
    }
    // Nachweis der Entfernung nur mit erfolgreichem Reload, K = nein und not_auth (E92)
    if ($reloadOk && $k['state'] === 'no' && $d['state'] === 'not_auth') {
        return $r(true, true, 'absent', $d['reason']);
    }
    // Zone nach dem Reload weiter konfiguriert bzw. dauerhaft unbestimmbar: absent ist in diesem Aufruf ausgeschlossen
    $settled = !$reloadOk || $k['state'] === 'yes' || ($k['state'] === 'undeterminable' && !$kTransient);
    $why = $k['state'] === 'yes' ? 'still_configured' : ($k['state'] === 'undeterminable' ? 'config_' . $k['reason'] : null);
    if ($d['state'] === 'served') {
        return $r($settled, true, 'still_served', $why);
    }
    if ($settled) {
        return $r(true, true, 'unknown', $why ?? ($d['reason'] ?? 'served_unknown'));
    }
    if ($k['state'] === 'no' && in_array($d['reason'], VERIFY_D_STRUCTURAL, true)) {
        return $r(true, true, 'unknown', $d['reason']);
    }

    return $r(false, !($kTransient && verifyNotStarted($k['reason'])), 'unknown', $why ?? ($d['reason'] ?? 'served_unknown'));
}

/**
 * Endergebnis nach R3/R4: ein festes Ergebnis gilt; sonst entscheidet die letzte vollständige Runde – serial_differs bzw.
 * still_served nur, wenn die letzte vollständige Runde sie zeigt; eine unbekannte letzte Runde ergibt unknown
 * (last_round_unknown); ohne vollständige Runde unknown (budget_exhausted).
 *
 * @return array{state: string, reason: ?string, last_reason?: ?string}
 */
function verifyFinalize(?array $final, ?array $lastComplete): array {
    if ($final !== null) {
        return ['state' => $final['state'], 'reason' => $final['reason']];
    }
    if ($lastComplete === null) {
        return ['state' => 'unknown', 'reason' => 'budget_exhausted'];
    }
    if (in_array($lastComplete['state'], ['serial_differs', 'still_served'], true)) {
        return ['state' => $lastComplete['state'], 'reason' => $lastComplete['reason']];
    }

    return ['state' => 'unknown', 'reason' => 'last_round_unknown', 'last_reason' => $lastComplete['reason']];
}

/**
 * Zonennamen verwaister .conf-Dateien aus einem Konfigurationsnachweis vor der Änderung (E123): Kandidat ist jede Zone mit
 * K = ja, deren verwaltete Datei genau zone_data_dir/db.<base> ist. Nur bei genau einem Kandidaten wird der Name
 * verwendet; keine Ableitung aus dem Dateinamen.
 *
 * @param array<int, string> $bases Basisnamen der verwaisten .conf-Dateien
 *
 * @return array<string, array{name: ?string, reason: ?string}>
 */
function verifyOrphanNames(array $config, array $proof, array $bases): array {
    $out = [];
    foreach ($bases as $base) {
        $base = (string)$base;
        if ($proof['state'] !== 'ok') {
            $out[$base] = ['name' => null, 'reason' => verifyNotStarted($proof['reason']) ? 'budget_exhausted' : 'config_undeterminable'];
            continue;
        }
        $target = rtrim((string)$config['zone_data_dir'], '/') . '/db.' . $base;
        $cands = [];
        foreach ($proof['list'] as [$n]) {
            if ($n === '' || in_array($n, $cands, true) || dnsManagedZonePath($config, $n) !== $target) {
                continue;
            }
            if (dnsZoneConfigState($config, $n, $proof)['state'] === 'yes') {
                $cands[] = $n;
            }
        }
        $out[$base] = count($cands) === 1 ? ['name' => $cands[0], 'reason' => null]
            : ['name' => null, 'reason' => $cands === [] ? 'zone_name_unresolved' : 'zone_name_ambiguous'];
    }

    return $out;
}

// ------------------------------------------------------------------------------------------------------------------
// Abläufe (unter der Agent-Sperre, E119)
// ------------------------------------------------------------------------------------------------------------------

/** Verifikationsobjekt von conf_sync ohne DNS-Abfrage (z. B. gescheiterter Reload, E122). */
function verifyLoadedNotAttempted(string $zoneName, string $reason): array {
    return ['state' => 'unknown', 'reason' => $reason, 'zone' => dnsNormName($zoneName), 'file_serial' => null,
        'file_sha256' => null, 'served_serial' => null, 'served_rcode' => null, 'served_reason' => null,
        'config_state' => null, 'config_reason' => null, 'rounds' => 0, 'elapsed_ms' => 0, 'window_ms' => 0,
        'observations' => ['complete_rounds' => 0, 'serial_differs' => 0, 'unknown' => 0, 'last_served_serial' => null]];
}

/**
 * Nachverifikation in conf_sync nach erfolgreichem Reload (E107, E121).
 *
 * @param float $reloadEnd Ende von rndc reload (Unix-Zeit)
 */
function verifyLoaded(array $config, string $zoneName, float $reloadEnd): array {
    $t0 = microtime(true);
    $deadline = verifyPollDeadline($reloadEnd, agentWorkDeadline());
    $v = verifyLoadedNotAttempted($zoneName, 'budget_exhausted');
    $v['window_ms'] = (int)round(max(0.0, $deadline - $reloadEnd) * 1000);
    $zone = $v['zone'];
    if ($zone === null) {
        $v['reason'] = 'zone_name_invalid';
        return $v;
    }
    // Verwaltete Datei wie in zone_sync aus dem Namen der Anfrage (Dateiname), K aus dem normalisierten Namen
    $path = dnsManagedZonePath($config, $zoneName);
    $f = null;
    $proof = null;
    $k = null;
    $kTransient = false;
    $final = null;
    $last = null;
    $obs = &$v['observations'];

    $round = static function (int $n) use ($config, $zone, $path, &$f, &$proof, &$k, &$kTransient, &$final, &$last, &$obs): bool {
        // R1: Datei einmal (erneut nur bei abgebrochener Prüfung), K aus einem Nachweis nach dem Reload (erneut nur bei
        // vorübergehendem Grund), D in dieser Runde nach K
        if ($f === null || verifyFileTransient($f)) {
            $f = dnsZoneFileState($config, $zone, $path);
            unset($f['bytes']);
        }
        $d = null;
        if (!verifyFileTransient($f) && $f['state'] === 'valid') {
            if ($k === null || $kTransient) {
                $proof = dnsConfigProof($config, true);
                $k = dnsZoneConfigState($config, $zone, $proof);
                $kTransient = $k['state'] === 'undeterminable' && verifyConfigTransient($k['reason']);
            }
            if ($k['state'] === 'yes') {
                $d = dnsServedState($config, $zone, $k, $proof);
            }
        }
        $o = verifyLoadedDecide($f, $k, $kTransient, $d) + ['f' => $f, 'k' => $k, 'd' => $d];
        if ($o['complete']) {
            $obs['complete_rounds']++;
            if ($o['state'] === 'serial_differs') {
                $obs['serial_differs']++;
            } elseif ($o['state'] === 'unknown') {
                $obs['unknown']++;
            }
            if (($d['state'] ?? null) === 'served') {
                $obs['last_served_serial'] = $d['serial'];
            }
        }
        if ($o['final']) {
            $final = $o;
            return true;
        }
        if ($o['complete']) {
            $last = $o;
        }
        return false;
    };
    $p = agentWithSectionDeadline($deadline, static fn (): array => verifyPoll($deadline, $round));

    $decisive = $final ?? $last;
    $v = verifyFinalize($final, $last) + $v;
    $v['rounds'] = $p['rounds'];
    $v['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
    if ($decisive !== null) {
        $v['file_serial'] = $decisive['f']['serial'] ?? null;
        $v['file_sha256'] = $decisive['f']['sha256'] ?? null;
        $v['config_state'] = $decisive['k']['state'] ?? null;
        $v['config_reason'] = $decisive['k']['reason'] ?? null;
        $v['served_serial'] = $decisive['d']['serial'] ?? null;
        $v['served_rcode'] = $decisive['d']['rcode'] ?? null;
        $v['served_reason'] = $decisive['d']['reason'] ?? null;
    }
    if ($v['state'] !== 'verified') {
        error_log(sprintf('[verify] conf_sync %s: %s (%s), Runden %d, %d ms', $zone, $v['state'], (string)$v['reason'], $v['rounds'], $v['elapsed_ms']));
    }

    return $v;
}

/**
 * Vorabnachweis im Cleanup (E120, E123): Zonennamen der verwaisten Dateien aus dem Konfigurationsnachweis vor der
 * Änderung. Läuft nur mit mindestens 20 s Restzeit und mit der Abschnittsfrist TW − 10 s, sodass Preflight, Reload und
 * Antwort des Cleanups ihr AS2a-Budget behalten. Reicht die Zeit nicht, bleiben die Namen offen (budget_exhausted).
 *
 * @param array<int, string> $bases
 *
 * @return array{names: array<string, array{name: ?string, reason: ?string}>, proof: array}
 */
function verifyCleanupNames(array $config, array $bases): array {
    $t0 = microtime(true);
    $open = static function (string $reason) use ($bases): array {
        $o = [];
        foreach ($bases as $b) {
            $o[(string)$b] = ['name' => null, 'reason' => $reason];
        }
        return $o;
    };
    $meta = static fn (string $state, ?string $reason): array => ['state' => $state, 'reason' => $reason,
        'ms' => (int)round((microtime(true) - $t0) * 1000), 'remaining_ms' => (int)round(agentRemaining() * 1000)];

    if (agentRemaining() < VERIFY_PREPROOF_MIN_REMAINING) {
        return ['names' => $open('budget_exhausted'), 'proof' => $meta('skipped', 'budget_exhausted')];
    }
    $proof = agentWithSectionDeadline(agentWorkDeadline() - VERIFY_PREPROOF_RESERVE,
        static fn (): array => dnsConfigProof($config, true));
    // Dynamische Prüfung danach: Ist die Reserve für Preflight, Reload und Antwort nicht mehr sicher vorhanden, wird das
    // Ergebnis verworfen (kein Ersatznachweis)
    if (agentRemaining() < VERIFY_PREPROOF_AFTER_MIN) {
        return ['names' => $open('budget_exhausted'), 'proof' => $meta('discarded', 'budget_exhausted')];
    }
    if ($proof['state'] !== 'ok') {
        $reason = verifyNotStarted($proof['reason']) ? 'budget_exhausted' : 'config_undeterminable';
        return ['names' => $open($reason), 'proof' => $meta('undeterminable', $proof['reason'])];
    }

    return ['names' => verifyOrphanNames($config, $proof, $bases), 'proof' => $meta('ok', null)];
}

/**
 * Nachweis der Entfernung im Cleanup (E92, E122, E124).
 *
 * @param array<string, array{name: ?string, reason: ?string}> $names Ergebnis von verifyCleanupNames()
 * @param string                                               $reloadStatus agentReloadTimed()['status']
 *
 * @return array{zones: array<string, array{name: ?string, served: string, served_reason: ?string}>, meta: array}
 */
function verifyRemoved(array $config, array $names, string $reloadStatus, float $reloadEnd): array {
    $t0 = microtime(true);
    $deadline = verifyPollDeadline($reloadEnd, agentWorkDeadline());
    $reloadOk = $reloadStatus === 'ok';
    $zones = [];
    $pending = [];
    foreach ($names as $base => $n) {
        $zones[(string)$base] = ['name' => $n['name'], 'served' => 'unknown', 'served_reason' => $n['reason']];
        if ($n['name'] !== null) {
            $pending[(string)$base] = $n['name'];
        }
    }
    $final = [];
    $last = [];
    $controlQueries = 0;
    $rounds = 0;

    if ($pending !== [] && !$reloadOk) {
        // E122: eine Runde nur mit SOA-Abfrage – still_served nachweisbar, absent nie; kein K, keine Kontrollabfrage
        $rounds = 1;
        agentWithSectionDeadline($deadline, static function () use ($config, $pending, $reloadStatus, &$final): void {
            foreach ($pending as $base => $name) {
                if (agentRemaining() < AGENT_PROC_MIN_RUNTIME) {
                    $final[$base] = ['state' => 'unknown', 'reason' => 'budget_exhausted'];
                    continue;
                }
                $r = dnsQuery($config, $name);
                $final[$base] = dnsAuthoritativeSoa($r, $name) !== null
                    ? ['state' => 'still_served', 'reason' => 'reload_' . $reloadStatus]
                    : ['state' => 'unknown', 'reason' => verifyNotStarted($r['reason']) ? 'budget_exhausted' : 'reload_' . $reloadStatus];
            }
        });
    } elseif ($pending !== []) {
        $proof = null;
        $kTransient = false;
        $round = static function (int $n) use ($config, &$pending, &$proof, &$kTransient, &$final, &$last, &$controlQueries): bool {
            if ($proof === null || $kTransient) {
                $proof = dnsConfigProof($config, true);
                $kTransient = $proof['state'] !== 'ok' && verifyConfigTransient($proof['reason']);
            }
            // Rundenspeicher der Kontrollzone: neu je Runde, gebunden an den Nachweis dieser Runde
            $cache = [];
            foreach ($pending as $base => $name) {
                $k = dnsZoneConfigState($config, $name, $proof);
                $d = dnsServedState($config, $name, $k, $proof, $cache);
                $o = verifyRemovedDecide(true, $k, $kTransient, $d);
                if ($o['final']) {
                    $final[$base] = $o;
                    unset($pending[$base]);
                } elseif ($o['complete']) {
                    $last[$base] = $o;
                }
                // R4: eine unvollständige Runde (Schritt wegen der Frist nicht gestartet) ersetzt die letzte vollständige nicht
            }
            $controlQueries += $cache['control_queries'] ?? 0;
            return $pending === [];
        };
        $p = agentWithSectionDeadline($deadline, static fn (): array => verifyPoll($deadline, $round));
        $rounds = $p['rounds'];
    }

    $notAbsent = 0;
    foreach ($zones as $base => &$z) {
        if ($z['name'] === null) {
            $notAbsent++;
            continue;
        }
        $res = verifyFinalize($final[$base] ?? null, $last[$base] ?? null);
        $z['served'] = $res['state'];
        $z['served_reason'] = $res['reason'] === 'last_round_unknown' ? 'last_round_unknown:' . ($res['last_reason'] ?? '') : $res['reason'];
        if ($res['state'] !== 'absent') {
            $notAbsent++;
        }
    }
    unset($z);
    $meta = ['rounds' => $rounds, 'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'window_ms' => (int)round(max(0.0, $deadline - $reloadEnd) * 1000), 'control_queries' => $controlQueries];
    if ($notAbsent > 0) {
        error_log(sprintf('[verify] zone_cleanup: %d von %d Zonen ohne Nachweis der Entfernung, Runden %d, %d ms', $notAbsent, count($zones), $rounds, $meta['elapsed_ms']));
    }

    return ['zones' => $zones, 'meta' => $meta];
}

// ------------------------------------------------------------------------------------------------------------------
// Einzige Aufrufpunkte in den Endpunkten. Die AS1-Regression ersetzt ausschließlich diese Funktionen in ihrer
// Sandbox-Kopie des Agenten (dort gibt es keinen passenden BIND); der Produktionscode hat keinen Schalter.
// ------------------------------------------------------------------------------------------------------------------

function agentVerifyLoaded(array $config, string $zoneName, float $reloadEnd): array {
    return verifyLoaded($config, $zoneName, $reloadEnd);
}

function agentCleanupNames(array $config, array $bases): array {
    return verifyCleanupNames($config, $bases);
}

function agentVerifyRemoved(array $config, array $names, string $reloadStatus, float $reloadEnd): array {
    return verifyRemoved($config, $names, $reloadStatus, $reloadEnd);
}
