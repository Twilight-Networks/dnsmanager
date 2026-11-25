<?php
/**
 * Datei: tests/history/journal_lib.php
 * Zweck: Gemeinsame Hilfen der Publish-Journal-Tests (AP1/H2d): isolierte Datenbank (über H2b, Eigentum nach
 *        owned.sh) und eine isolierte Zweiserver-Umgebung aus zwei Testagenten (journal_agent.py) auf 127.0.0.4 und
 *        127.0.0.5, die den ECHTEN Agent-Code des geprüften Commits mit einer Sandbox-Konfiguration ausführen.
 *
 * Schutz des Bestands (Q1): Alle Agentpfade (Zonen, Konfiguration, zones.conf, named.conf, Sperrdatei) liegen unter
 * dem Sandbox-Verzeichnis; rndc ist ein Platzhalterskript. Vor dem ersten Agentaufruf wird geprüft, dass keine
 * Sandbox-Einstellung auf /etc/bind zeigt. Die installierte Anwendung, ihre Datenbank, /etc/dnsmanager, /etc/bind und
 * die installierten Agenten werden nicht berührt; journal_test.php weist /etc/bind/zones vorher/nachher byte-gleich nach.
 */

require_once __DIR__ . '/h2b_lib.php';

final class J
{
    public const IPS = [1 => '127.0.0.4', 2 => '127.0.0.5'];
    public const API_BASE = '/api/v1';

    public static string $sbx = '';
    /** @var int[] */
    private static array $pids = [];
    /** @var string[] Logzeilen des PublishService */
    public static array $logs = [];

    /** Anwendung laden, Datenbanknamen bestimmen, Konstanten setzen, Sandbox und Testagenten starten */
    public static function init(string $app, string $iso, string $suffix): void
    {
        H2b::init($app, $iso, $suffix);
        foreach (['PublishLedger', 'PublishJournal', 'PublishJournalReader', 'PublishTargetClassifier', 'PublishHashContract', 'PublishRun'] as $c) {
            require_once "$app/web/inc/app/publish/$c.php";
        }
        $sbx = sys_get_temp_dir() . '/dnsmgr-h2d-sbx.' . bin2hex(random_bytes(4));
        if (!mkdir($sbx, 0700)) {
            throw new RuntimeException('Sandbox nicht anlegbar');
        }
        self::$sbx = $sbx;
        define('BIND_BASE_DIR', "$sbx/bindbase");
        define('REMOTE_API_BASE', self::API_BASE);
        define('CURL_SSL_VERIFYPEER', false);
        define('CURL_SSL_VERIFYHOST', 0);
        require_once "$app/web/inc/deploy/LegacyBindPublishBackend.php";
        require_once __DIR__ . '/journal_failing_backend.php';
        register_shutdown_function([self::class, 'shutdown']);
        self::startAgents($app);
    }

    public static function shutdown(): void
    {
        foreach (self::$pids as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        self::$pids = [];
        if (self::$sbx !== '' && str_starts_with(self::$sbx, sys_get_temp_dir() . '/dnsmgr-h2d-sbx.')) {
            exec('rm -rf ' . escapeshellarg(self::$sbx));
        }
    }

    private static function startAgents(string $app): void
    {
        $s = self::$sbx;
        foreach (self::IPS as $ip) {
            $ss = H2b::sh('ss -ltn');
            if (str_contains($ss, " $ip:443 ") || preg_match('/ (\*|0\.0\.0\.0|\[::\]):443 /', $ss) === 1) {
                throw new RuntimeException("ABBRUCH: $ip:443 belegt bzw. Port 443 auf allen Adressen gebunden");
            }
        }
        H2b::sh("openssl req -x509 -newkey rsa:2048 -nodes -keyout $s/k.key -out $s/k.pem -days 2 -subj /CN=h2d-journal-agent");
        file_put_contents("$s/control.json", '{}');
        touch("$s/agent.log");
        $tokens = [1 => 'H2BTOKEN-eins-0123456789abcdef0123456789', 2 => 'H2BTOKEN-zwei-0123456789abcdef0123456789'];
        foreach (self::IPS as $n => $ip) {
            $d = "$s/srv$n";
            mkdir("$d/bind/zones/conf", 0700, true);
            H2b::sh('cp -a ' . escapeshellarg("$app/agent") . ' ' . escapeshellarg("$d/agent"));
            file_put_contents("$d/bind/zones/zones.conf", '');
            file_put_contents("$d/bind/named.conf", "include \"$d/bind/zones/zones.conf\";\n");
            file_put_contents("$d/rndc", "#!/bin/sh\necho 'rndc: reload (Platzhalter $n)'\n");
            chmod("$d/rndc", 0700);
            $cfg = [
                'api_allowed_ips'      => [],
                'zone_conf_dir'        => "$d/bind/zones/conf",
                'zone_data_dir'        => "$d/bind/zones",
                'zones_conf_file'      => "$d/bind/zones/zones.conf",
                'bind_named_conf_path' => "$d/bind/named.conf",
                'token_file'           => "$d/agent/config/api_tokens.php",
                'bind_named_checkzone' => '/usr/bin/named-checkzone',
                'bind_named_checkconf' => '/usr/bin/named-checkconf',
                'bind_rndc'            => "$d/rndc",
                // E118 (AS2a): fencing_mode ist Pflichtangabe; das Journal prüft den bisherigen Ablauf (ausdrücklich off)
                'fencing_mode'         => 'off',
            ];
            foreach ($cfg as $k => $v) {
                if (is_string($v) && !in_array($k, ['bind_named_checkzone', 'bind_named_checkconf', 'fencing_mode'], true) && !str_starts_with($v, "$s/")) {
                    throw new RuntimeException("Sandbox-Konfiguration $k zeigt außerhalb der Sandbox");
                }
            }
            file_put_contents("$d/agent/config/api_config.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
            file_put_contents("$d/agent/config/api_tokens.php", "<?php\nreturn " . var_export([$tokens[$n]], true) . ";\n");
            $cmd = ['python3', __DIR__ . '/journal_agent.py', $ip, '443', "$s/k.pem", "$s/k.key", "$d/agent", "$s/control.json", "$s/agent.log"];
            $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$s/agent-$n.err", 'w']], $pipes);
            self::$pids[] = proc_get_status($p)['pid'];
        }
        $end = microtime(true) + 10;
        while (microtime(true) < $end) {
            $ss = H2b::sh('ss -ltn');
            if (str_contains($ss, ' 127.0.0.4:443 ') && str_contains($ss, ' 127.0.0.5:443 ')) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Testagenten nicht gestartet: ' . @file_get_contents("$s/agent-1.err"));
    }

    /** Datenbank neu aufbauen (Schema des Commits, fixture_h2b, Baseline) und Server auf die Testagenten richten; Sandbox leeren */
    public static function reset(): PDO
    {
        $pdo = H2b::reset(true);
        foreach (self::IPS as $n => $ip) {
            $pdo->prepare('UPDATE servers SET api_ip = ? WHERE id = ?')->execute([$ip, $n]);
            $d = self::$sbx . "/srv$n/bind/zones";
            foreach (array_merge(glob("$d/db.*") ?: [], glob("$d/conf/*.conf") ?: []) as $f) {
                unlink($f);
            }
            file_put_contents("$d/zones.conf", '');
        }
        self::control([]);
        self::$logs = [];
        file_put_contents(self::$sbx . '/agent.log', '');

        return $pdo;
    }

    /** Fehlerbilder je Server und Endpunkt: [1 => ['zone_sync.php' => 'reject'], …] */
    public static function control(array $byServer): void
    {
        $out = [];
        foreach ($byServer as $n => $eps) {
            $out[self::IPS[$n]] = $eps;
        }
        file_put_contents(self::$sbx . '/control.json', json_encode((object)$out));
    }

    /** @return string[] Agentaufrufe "ip endpunkt zone=… verhalten methode" */
    public static function agentCalls(): array
    {
        return array_map(static fn (array $c): string => $c[1], self::agentCallsTimed());
    }

    /** @return array<int, array{0: float, 1: string}> Agentaufrufe mit Zeitstempel des Testagenten (Unix-Zeit) */
    public static function agentCallsTimed(): array
    {
        $lines = array_values(array_filter(explode("\n", (string)file_get_contents(self::$sbx . '/agent.log'))));
        return array_map(static fn (string $l): array => [(float)substr($l, 0, (int)strpos($l, ' ')), substr($l, strpos($l, ' ') + 1)], $lines);
    }

    /**
     * AS4b (E81): Aufteilung der Agentaufrufe in den lesenden Restore-Abgleich und alle übrigen Aufrufe. Als
     * Restore-Abgleich zählt ausschließlich die exakte Zeile "<IP eines aktiven Servers> status.php zone=- php GET";
     * jede andere Zeile (anderer Endpunkt, andere Methode, anderes Verhalten, fremde IP) bleibt in $other – kein
     * pauschaler Filter.
     *
     * @return array{status: string[], other: string[], expected: string[]} expected = genau ein Abgleich je aktivem Server
     */
    public static function splitCalls(PDO $p): array
    {
        $expected = [];
        foreach (H2b::all($p, 'SELECT api_ip FROM servers WHERE active = 1 ORDER BY id') as $s) {
            $expected[] = $s['api_ip'] . ' status.php zone=- php GET';
        }
        $status = [];
        $other = [];
        foreach (self::agentCalls() as $l) {
            if (preg_match('/^127\.0\.0\.[45] status\.php zone=- php GET$/D', $l) === 1 && in_array($l, $expected, true)) {
                $status[] = $l;
            } else {
                $other[] = $l;
            }
        }
        sort($status);
        sort($expected);

        return ['status' => $status, 'other' => $other, 'expected' => $expected];
    }

    public static function client(): BindAgentClient
    {
        return new BindAgentClient(self::API_BASE, false, 0);
    }

    public static function service(PDO $p, ?PublishBackend $backend = null): PublishService
    {
        // AS4b: Fencing-Domäne der Testinstallation in Datenbank und Konfiguration
        $p->exec("UPDATE system_status SET fence_domain = '" . H2b::FENCE_DOMAIN . "' WHERE id = 1 AND fence_domain IS NULL");
        return new PublishService($p, $backend ?? new LegacyBindPublishBackend($p, self::client()), static function (string $level, string $msg): void {
            J::$logs[] = "$level $msg";
        }, new FenceContext(H2b::FENCE_DOMAIN));
    }

    /** Ergebnisbericht (Kontext der letzten Meldung) */
    public static function report(ServiceResult $r): array
    {
        $last = $r->messages[count($r->messages) - 1];
        return $last->context;
    }

    public static function publishAll(PDO $p): array
    {
        return self::report(self::service($p)->publishAll(H2b::admin()));
    }

    /** Installierte Datei eines Testservers (null = nicht vorhanden) */
    public static function installed(int $server, string $zone): ?string
    {
        $f = self::$sbx . "/srv$server/bind/zones/db.$zone";
        return is_file($f) ? (string)file_get_contents($f) : null;
    }

    public static function installedConf(int $server, string $zone): ?string
    {
        $f = self::$sbx . "/srv$server/bind/zones/conf/$zone.conf";
        return is_file($f) ? (string)file_get_contents($f) : null;
    }

    /* ---------------------------------------------------------------- Journal lesen */

    public static function runs(PDO $p): array
    {
        return H2b::all($p, 'SELECT * FROM publish_runs ORDER BY id');
    }

    public static function attempts(PDO $p, ?int $runId = null): array
    {
        return $runId === null
            ? H2b::all($p, 'SELECT * FROM zone_publish_attempts ORDER BY id')
            : H2b::all($p, 'SELECT * FROM zone_publish_attempts WHERE run_id = ? ORDER BY id', [$runId]);
    }

    /** @return array<int, array<string, mixed>> server_id => Zielzeile */
    public static function targets(PDO $p, int $attemptId): array
    {
        $out = [];
        foreach (H2b::all($p, 'SELECT * FROM zone_publish_targets WHERE attempt_id = ? ORDER BY server_id', [$attemptId]) as $t) {
            $out[(int)$t['server_id']] = $t;
        }
        return $out;
    }

    public static function cleanup(PDO $p, int $runId): array
    {
        $out = [];
        foreach (H2b::all($p, 'SELECT * FROM publish_cleanup_results WHERE run_id = ? ORDER BY server_id', [$runId]) as $c) {
            $out[(int)$c['server_id']] = $c;
        }
        return $out;
    }

    public static function attemptFor(PDO $p, int $runId, int $zoneId): ?array
    {
        $r = H2b::all($p, 'SELECT * FROM zone_publish_attempts WHERE run_id = ? AND zone_id = ?', [$runId, $zoneId]);
        return $r[0] ?? null;
    }

    public static function lastRunId(PDO $p): int
    {
        return (int)H2b::one($p, 'SELECT COALESCE(MAX(id), 0) FROM publish_runs');
    }

    public static function flags(PDO $p): array
    {
        return [
            'bind_dirty' => (int)H2b::one($p, 'SELECT bind_dirty FROM system_status WHERE id = 1'),
            'changed'    => array_map(fn ($r) => (int)$r['id'] . ':' . (int)$r['changed'], H2b::all($p, 'SELECT id, changed FROM zones ORDER BY id')),
            'serials'    => array_map(fn ($r) => (int)$r['id'] . ':' . (int)$r['soa_serial'], H2b::all($p, 'SELECT id, soa_serial FROM zones ORDER BY id')),
        ];
    }

    /** Publish-Lock und alle Laufsperren frei? */
    public static function locksFree(PDO $p): bool
    {
        $main = (int)H2b::one($p, "SELECT IS_FREE_LOCK('dnsmanager_publish_all')") === 1;
        $db = (string)H2b::one($p, 'SELECT DATABASE()');
        foreach (self::runs($p) as $r) {
            if (H2b::one($p, 'SELECT IS_USED_LOCK(?)', [PublishLedger::runLockName($db, (int)$r['id'])]) !== null) {
                return false;
            }
        }
        return $main;
    }

    /** Operativer Teil eines Publish-All-Berichts (ohne Journalangaben), für Vergleiche */
    public static function operational(array $report): array
    {
        unset($report['runId'], $report['journal']);
        return $report;
    }

    /** Fehlerinjektion: Trigger, der die Anweisung abweist */
    public static function inject(PDO $p, string $name, string $timing, string $table, string $cond = '1 = 1'): void
    {
        $p->exec("CREATE TRIGGER `$name` $timing ON `$table` FOR EACH ROW BEGIN IF $cond THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'H2d-Injektion $name'; END IF; END");
    }

    public static function dropInjection(PDO $p, string $name): void
    {
        $p->exec("DROP TRIGGER IF EXISTS `$name`");
    }

    /** Startet journal_worker.php in eigenem Prozess (eigene Verbindung, dieselbe Sandbox) */
    public static function spawn(string ...$args): array
    {
        $cmd = array_merge(['php', __DIR__ . '/journal_worker.php', H2b::$app, H2b::$db, H2b::$iso, self::$sbx], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return ['proc' => $p, 'pipes' => $pipes, 'start' => microtime(true), 'pid' => proc_get_status($p)['pid']];
    }

    /** Prüfsumme der Dateien unter einem Verzeichnis (Pfad und Inhalt) */
    public static function treeSum(string $dir): string
    {
        if (!is_dir($dir)) {
            return 'fehlt';
        }
        return H2b::sh('cd ' . escapeshellarg($dir) . ' && find . -type f -print0 | sort -z | xargs -0 -r sha256sum | sha256sum');
    }
}
