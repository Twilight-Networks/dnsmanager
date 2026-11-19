<?php
/**
 * Datei: inc/proc.php
 * Zweck: Externe Prozesse mit absoluter Frist (AS2a, docs/ARCHITECTURE_HISTORY.md Abschnitt 5f, E117).
 *
 * - Gesamtfrist je Agent-Aufruf: Arbeitsende TW = Anfragebeginn + 24 s (1 s Reserve bis zur harten Grenze von 25 s;
 *   Core-Timeout 30 s).
 * - Jeder externe Prozess (named-checkzone, named-checkconf, rndc, dig) läuft mit min(eigene Obergrenze, TW − jetzt).
 *   Reicht die Restzeit nicht für die Mindestlaufzeit, wird er nicht gestartet (status not_started).
 * - Jeder Prozess läuft in einer eigenen Prozessgruppe (setsid). Bei Fristablauf erhält die Gruppe SIGTERM (sudo leitet
 *   es an den Befehl weiter, auch mit use_pty), nach kurzer Wartezeit SIGKILL. So bleiben keine verwaisten Kindprozesse.
 * - Ein abgebrochener Prozess gilt als "timeout" – der Aufrufer entscheidet, ob die Wirkung unbestimmt ist (z. B. rndc).
 *
 * Nur in den Modi observe/enforce verwendet; im Modus off laufen die bisherigen Funktionen aus bind_utils.php
 * unverändert (E101, E95). Voraussetzung: PHP-Erweiterung posix (E100).
 *
 * Die Datei definiert ausschließlich Funktionen und Konstanten (kein Seiteneffekt beim Einbinden).
 */

/** Arbeitsende relativ zum Anfragebeginn (Sekunden) */
const AGENT_WORK_DEADLINE = 24.0;
/** Wartezeit zwischen SIGTERM und SIGKILL (Sekunden) */
const AGENT_PROC_TERM_GRACE = 0.5;
/** Kleinste sinnvolle Laufzeit eines externen Prozesses (Sekunden) */
const AGENT_PROC_MIN_RUNTIME = 0.3;

/** util-linux setsid (absoluter Pfad, unabhängig von PATH des Webservers) */
const AGENT_SETSID = '/usr/bin/setsid';

/** Obergrenzen je Prozessart (Sekunden, E117) */
const AGENT_CAP_CHECKZONE = 8.0;
const AGENT_CAP_CHECKCONF = 3.0;
const AGENT_CAP_RNDC_STATUS = 3.0;
const AGENT_CAP_RNDC_RELOAD = 5.0;
const AGENT_CAP_DIG = 1.5;
const AGENT_CAP_PGREP = 2.0;

/**
 * Absolutes Arbeitsende dieser Anfrage (Unix-Zeit, Sekunden).
 */
function agentWorkDeadline(): float {
    static $deadline = null;
    if ($deadline === null) {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $start = is_float($start) || is_int($start) ? (float)$start : microtime(true);
        $deadline = $start + AGENT_WORK_DEADLINE;
    }

    return $deadline;
}

/** Restzeit bis zum Arbeitsende (Sekunden, ggf. negativ). */
function agentRemaining(): float {
    return agentWorkDeadline() - microtime(true);
}

/**
 * Führt einen externen Prozess mit Frist aus (ohne Shell; stdout und stderr zusammengeführt).
 *
 * @param string[] $argv Programm und Argumente
 * @param float    $cap  Obergrenze dieses Prozesses (Sekunden)
 *
 * @return array{status: string, exit_code: ?int, output: string, duration: float}
 *         status: ok (beendet, exit_code gesetzt) | timeout (Frist abgelaufen, Gruppe beendet) | not_started | error
 */
function agentRunProcess(array $argv, float $cap): array {
    $t0 = microtime(true);
    $limit = min($cap, agentRemaining());
    if ($limit < AGENT_PROC_MIN_RUNTIME) {
        return ['status' => 'not_started', 'exit_code' => null, 'output' => '', 'duration' => 0.0];
    }
    if (!function_exists('posix_kill')) {
        return ['status' => 'not_started', 'exit_code' => null, 'output' => 'posix fehlt', 'duration' => 0.0];
    }

    $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    // setsid: eigene Prozessgruppe (proc_open-Kind ist kein Gruppenführer ⇒ setsid ruft setsid() ohne fork auf)
    $proc = @proc_open(array_merge([AGENT_SETSID], $argv), $spec, $pipes);
    if (!is_resource($proc)) {
        return ['status' => 'error', 'exit_code' => null, 'output' => 'Prozess nicht startbar', 'duration' => microtime(true) - $t0];
    }
    stream_set_blocking($pipes[1], false);
    $pid = (int)(proc_get_status($proc)['pid'] ?? 0);
    $out = '';
    $exit = null;
    $timedOut = false;
    $end = $t0 + $limit;

    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $exit = (int)$st['exitcode'];
            $out .= (string)stream_get_contents($pipes[1]);
            break;
        }
        $wait = $end - microtime(true);
        if ($wait <= 0) {
            $timedOut = true;
            break;
        }
        $r = [$pipes[1]];
        $w = $x = null;
        $sec = (int)floor(min($wait, 0.05));
        $usec = (int)((min($wait, 0.05) - $sec) * 1e6);
        if (@stream_select($r, $w, $x, $sec, $usec) > 0) {
            $chunk = fread($pipes[1], 65536);
            if (is_string($chunk)) {
                $out .= $chunk;
            }
        }
    }

    if ($timedOut) {
        agentKillGroup($pid, $proc);
        $out .= (string)@stream_get_contents($pipes[1]);
    }
    @fclose($pipes[1]);
    $rc = proc_close($proc);
    if ($exit === null && !$timedOut) {
        $exit = $rc;
    }

    return [
        'status'    => $timedOut ? 'timeout' : 'ok',
        'exit_code' => $timedOut ? null : $exit,
        'output'    => $out,
        'duration'  => microtime(true) - $t0,
    ];
}

/**
 * Beendet eine Prozessgruppe: SIGTERM, Wartezeit, danach SIGKILL. Wartet, bis der Gruppenführer beendet (und über
 * proc_get_status abgeholt) ist und die Gruppe keine Mitglieder mehr hat, damit keine verwaisten Kindprozesse
 * zurückbleiben. sudo mit use_pty startet den Befehl in einer eigenen Sitzung und leitet SIGTERM an ihn weiter.
 *
 * @param resource $proc proc_open-Handle des Gruppenführers
 */
function agentKillGroup(int $pgid, $proc): void {
    if ($pgid <= 1) {
        return;
    }
    $gone = static function () use ($pgid, $proc): bool {
        $leaderRunning = is_resource($proc) && (proc_get_status($proc)['running'] ?? false);

        return !$leaderRunning && !@posix_kill(-$pgid, 0);
    };
    foreach ([15, 9] as $sig) {   // SIGTERM, danach SIGKILL
        @posix_kill(-$pgid, $sig);
        $until = microtime(true) + AGENT_PROC_TERM_GRACE;
        while (microtime(true) < $until) {
            if ($gone()) {
                return;
            }
            usleep(20000);
        }
    }
}

/**
 * Exklusiver Agent-flock mit Frist (AS2a): nicht blockierend mit Wiederholung, bis höchstens $reserve Sekunden vor
 * dem Arbeitsende. Gibt den Handle oder null zurück (Frist abgelaufen bzw. Fehler).
 *
 * @param float $reserve Restzeit, die für die eigentliche Arbeit mindestens verbleiben muss (Sekunden)
 *
 * @return array{handle: mixed, reason: ?string}
 */
function agentLockWithDeadline(string $lockPath, float $reserve): array {
    $h = @fopen($lockPath, 'c');
    if ($h === false) {
        return ['handle' => null, 'reason' => 'open'];
    }
    while (true) {
        // Review AS2a: Reserve VOR jedem Sperrversuch prüfen – auch eine freie Sperre wird nicht mehr genommen, wenn die
        // verbleibende Arbeitszeit die Reserve nicht mehr deckt
        if (agentRemaining() <= $reserve) {
            fclose($h);
            return ['handle' => null, 'reason' => 'timeout'];
        }
        $wouldBlock = 0;
        if (@flock($h, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return ['handle' => $h, 'reason' => null];
        }
        if (!$wouldBlock) {
            fclose($h);
            return ['handle' => null, 'reason' => 'flock'];
        }
        usleep(50000);
    }
}
