<?php
/**
 * Datei: tests/history/h2b_lib.php
 * Zweck: Gemeinsame Hilfen der Recorder-Tests (AP1/H2b): isolierte Datenbank dnsmgr_t21_r<RUN>_<suffix> mit Eigentum
 *        nach tests/history/owned.sh, Verbindungen je Isolationsstufe, Services, Akteure, Abfragen der Historie und
 *        Invariantenprüfung. Kein Framework; Prüfungen über T (lib.php).
 *
 * Die installierte Anwendung, ihre Datenbank und /etc/dnsmanager werden nicht berührt.
 */

require_once __DIR__ . '/lib.php';

final class H2b
{
    public const SOCK = '/run/mysqld/mysqld.sock';
    public const DYN_PASSWORD = 'H2B-Dyn-Geheim-4711';
    /** AS4b: Fencing-Domäne der Testinstallation (Datenbank und FenceContext identisch) */
    public const FENCE_DOMAIN = '5a4b3c2d-1e0f-4a9b-8c7d-6e5f4a3b2c1d';

    public const BUSINESS_TABLES = ['users', 'user_zones', 'zones', 'zone_servers', 'servers', 'records', 'dyndns_accounts', 'system_status'];
    public const HISTORY_TABLES = ['history_change_sets', 'zone_snapshots', 'zone_revisions', 'zone_history_state', 'history_dependencies', 'audit_events'];

    public static string $app = '';
    public static string $iso = '';
    public static string $db = '';
    private static bool $owned = false;

    /** Anwendung laden und Datenbanknamen bestimmen (Eigentum erst mit dem ersten reset()) */
    public static function init(string $app, string $iso, string $suffix): void
    {
        if (!is_dir("$app/web/inc/app/history") || !in_array($iso, ['RR', 'RC'], true) || preg_match('/^[a-z0-9]+$/D', $suffix) !== 1) {
            fwrite(STDERR, "Aufruf: <App-Verzeichnis> <RR|RC>\n");
            exit(2);
        }
        $run = getenv('H2A_RUN') ?: bin2hex(random_bytes(4));
        if (preg_match('/^[0-9a-f]{8}$/D', $run) !== 1) {
            fwrite(STDERR, "ABBRUCH: ungültige Lauf-Kennung H2A_RUN\n");
            exit(2);
        }
        self::$app = rtrim($app, '/');
        self::$iso = $iso;
        self::$db = "dnsmgr_t21_r{$run}_{$suffix}" . strtolower($iso);
        self::requireApp();
        register_shutdown_function(static function (): void {
            if (self::$owned) {
                exec("mariadb -e 'DROP DATABASE IF EXISTS `" . self::$db . "`' 2>&1");
            }
        });
    }

    public static function requireApp(): void
    {
        $a = self::$app;
        foreach ([
            'web/inc/app/Actor.php', 'web/inc/app/ZoneAccess.php', 'web/inc/app/TopologyLock.php',
            'web/inc/app/zones/ZoneService.php', 'web/inc/app/zones/ZoneInput.php', 'web/inc/app/zones/ZoneUpdateInput.php',
            'web/inc/app/zones/ZoneReadService.php', 'web/inc/app/records/RecordService.php', 'web/inc/app/records/RecordInput.php',
            'web/inc/app/servers/ServerService.php', 'web/inc/app/servers/ServerInput.php',
            'web/inc/app/dyndns/DyndnsAccountService.php', 'web/inc/app/dyndns/DyndnsAccountInput.php',
            'web/inc/app/dyndns/DyndnsUpdateService.php', 'web/inc/app/publish/PublishService.php',
            'web/inc/app/publish/PublishBackend.php', 'web/inc/deploy/BindZoneBuildBackend.php',
            'web/inc/app/history/HistoryBaselineService.php', 'web/inc/app/history/ZoneHistoryRecorder.php',
            'web/inc/app/history/ZoneSnapshotStore.php', 'web/inc/app/history/ZoneSnapshotReader.php',
            'web/inc/app/history/AuditRecorder.php', 'web/inc/app/history/AuditPolicy.php',
            'web/inc/app/users/UserService.php', 'web/inc/app/users/UserInput.php',
        ] as $f) {
            require_once "$a/$f";
        }
        require_once __DIR__ . '/h2b_fake_publish.php';
    }

    public static function sh(string $cmd): string
    {
        $out = [];
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException("Befehl fehlgeschlagen ($rc): $cmd\n" . implode("\n", $out));
        }
        return implode("\n", $out);
    }

    /**
     * Datenbank neu aus sql/dnsmanager_schema.sql des Commits und fixture_h2b.sql aufbauen; optional Baseline (wie nach
     * H2a auf ns1). Liefert eine Verbindung der gewählten Isolationsstufe.
     */
    public static function reset(bool $baseline = true): PDO
    {
        $db = self::$db;
        if (self::$owned) {
            self::sh("mariadb -e 'DROP DATABASE `$db`'");
        }
        self::sh("mariadb -e 'CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'");   // scheitert, falls fremd vorhanden
        if (!self::$owned) {
            self::$owned = true;
            $reg = getenv('H2A_REGISTRY');
            if (is_string($reg) && $reg !== '') {
                file_put_contents($reg, "db $db" . PHP_EOL, FILE_APPEND);
            }
        }
        self::sh("grep -vE '^(CREATE DATABASE|USE )' " . escapeshellarg(self::$app . '/sql/dnsmanager_schema.sql') . " | mariadb $db");
        self::sh("mariadb $db -e 'DELETE FROM users; DELETE FROM system_status WHERE id <> 1'");
        self::sh("mariadb $db -e \"UPDATE system_status SET fence_domain = '" . self::FENCE_DOMAIN . "' WHERE id = 1\"");   // AS4b
        self::sh('mariadb ' . $db . ' < ' . escapeshellarg(__DIR__ . '/fixture_h2b.sql'));
        $pdo = self::pdo();
        $stmt = $pdo->prepare('UPDATE dyndns_accounts SET password_hash = ? WHERE id = 1');
        $stmt->execute([password_hash(self::DYN_PASSWORD, PASSWORD_BCRYPT)]);
        if ($baseline) {
            $r = (new HistoryBaselineService($pdo))->run(true);
            if (!$r->ok) {
                throw new RuntimeException('Baseline fehlgeschlagen: ' . json_encode($r->messages));
            }
        }

        return $pdo;
    }

    /** Neue Verbindung (root über Socket) mit der Isolationsstufe des Laufs (RR: innodb_snapshot_isolation = ON) */
    public static function pdo(?string $iso = null): PDO
    {
        $iso ??= self::$iso;
        $pdo = new PDO('mysql:unix_socket=' . self::SOCK . ';dbname=' . self::$db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($iso === 'RR') {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');
        } else {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');

        return $pdo;
    }

    /* ---------------------------------------------------------------- Akteure und Services */

    public static function admin(): Actor { return new Actor(21, 'h2b_admin', 'admin', '192.0.2.99'); }
    public static function zoneadmin(): Actor { return new Actor(22, 'h2b_zoneadmin', 'zoneadmin', '192.0.2.99'); }
    public static function inactive(): Actor { return new Actor(23, 'h2b_inaktiv', 'admin', '192.0.2.99'); }

    public static function zones(PDO $p): ZoneService { return new ZoneService($p, new BindZoneBuildBackend($p), new TopologyLock($p)); }
    public static function records(PDO $p): RecordService { return new RecordService($p, new ZoneAccess($p), [new BindZoneBuildBackend($p), 'validateAndFlag']); }
    public static function servers(PDO $p): ServerService { return new ServerService($p, new BindZoneBuildBackend($p), new TopologyLock($p)); }
    public static function dyndns(PDO $p): DyndnsAccountService { return new DyndnsAccountService($p, new BindZoneBuildBackend($p)); }
    public static function dyndnsApi(PDO $p): DyndnsUpdateService { return new DyndnsUpdateService($p, new BindZoneBuildBackend($p), new PublishService($p, new H2bFakePublishBackend(), null, new FenceContext(self::FENCE_DOMAIN))); }

    /** Bearbeitungseingabe einer Zone auf dem aktuellen Stand (wie das Formular), mit Überschreibungen */
    public static function zoneInput(PDO $p, Actor $a, int $zoneId, array $over = []): ZoneUpdateInput
    {
        $z = (new ZoneReadService($p, new ZoneAccess($p)))->getZone($a, $zoneId);
        $servers = array_map(fn ($s) => (int)$s['id'], $z['servers']);
        $master = array_values(array_filter($z['servers'], fn ($s) => $s['role'] === 'master'))[0]['id'] ?? null;
        return new ZoneUpdateInput(
            (string)($over['ttl'] ?? $z['ttl']), $z['prefixLength'] === null ? null : (string)$z['prefixLength'],
            (string)($over['description'] ?? ($z['description'] ?? '')), (bool)($over['allowDyndns'] ?? $z['allowDyndns']),
            $z['soa']['mail'], (string)$z['soa']['refresh'], (string)$z['soa']['retry'], (string)$z['soa']['expire'], (string)$z['soa']['minimum'],
            $over['serverIds'] ?? $servers, $over['masterId'] ?? (int)$master, null, $z['editVersion'], false
        );
    }

    /** Eingabe zur Zonenanlage */
    public static function newZone(string $name, array $serverIds = [1], int $master = 1): ZoneInput
    {
        return new ZoneInput('forward', $name, null, '3600', 'H2B neu', 'hostmaster.h2b.test', '3600', '1800', '1209600', '86400', false, $serverIds, $master);
    }

    public static function record(string $type, string $name, string $content, int $ttl = 3600): RecordInput
    {
        return new RecordInput($type, $name, $content, $ttl);
    }

    /* ---------------------------------------------------------------- Abfragen */

    public static function one(PDO $p, string $sql, array $params = []): mixed
    {
        $s = $p->prepare($sql);
        $s->execute($params);
        $v = $s->fetchColumn();
        $s->closeCursor();
        return $v;
    }

    /** @return list<array<string, mixed>> */
    public static function all(PDO $p, string $sql, array $params = []): array
    {
        $s = $p->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Höchste ID der Änderungsmengen (Marke für since()) */
    public static function mark(PDO $p): int
    {
        return (int)self::one($p, 'SELECT COALESCE(MAX(id), 0) FROM history_change_sets');
    }

    /**
     * Änderungsmengen nach der Marke mit ihren Revisionen in kompakter Form:
     * [{op, actor, user, label, dyn, channel, corr, revs: [[zone, no, kind], …]}, …]
     */
    public static function since(PDO $p, int $mark): array
    {
        $out = [];
        foreach (self::all($p, 'SELECT * FROM history_change_sets WHERE id > ? ORDER BY id', [$mark]) as $cs) {
            $revs = array_map(fn ($r) => [(int)$r['zone_id'], (int)$r['revision_no'], $r['kind']],
                self::all($p, 'SELECT zone_id, revision_no, kind FROM zone_revisions WHERE change_set_id = ? ORDER BY zone_id, revision_no', [$cs['id']]));
            $out[] = [
                'id' => (int)$cs['id'], 'op' => $cs['operation'], 'actor' => $cs['actor_type'],
                'user' => $cs['actor_user_id'] === null ? null : (int)$cs['actor_user_id'], 'label' => $cs['actor_label'],
                'dyn' => $cs['actor_dyndns_account_id'] === null ? null : (int)$cs['actor_dyndns_account_id'],
                'channel' => $cs['channel'], 'corr' => $cs['correlation_id'], 'revs' => $revs,
            ];
        }
        return $out;
    }

    /** Vergleichbare Kurzform von since(): "op actor[user/label|dyn] channel: zone#no:kind …" */
    public static function brief(PDO $p, int $mark): array
    {
        return array_map(function (array $c): string {
            $who = $c['actor'] === 'user' ? "user:{$c['user']}/{$c['label']}" : ($c['actor'] === 'dyndns_account' ? "dyn:{$c['dyn']}" : 'system');
            return "{$c['op']} $who {$c['channel']}: " . implode(' ', array_map(fn ($r) => "{$r[0]}#{$r[1]}:{$r[2]}", $c['revs']));
        }, self::since($p, $mark));
    }

    /** Zeilenzahlen aller History-Tabellen */
    public static function historyCounts(PDO $p): array
    {
        $out = [];
        foreach (self::HISTORY_TABLES as $t) {
            $out[$t] = (int)self::one($p, "SELECT COUNT(*) FROM `$t`");
        }
        return $out;
    }

    /** Prüfsumme der Fachtabellen (vollständig, inkl. Flags und Serials) */
    public static function businessSum(PDO $p): string
    {
        return json_encode(self::all($p, 'CHECKSUM TABLE ' . implode(', ', self::BUSINESS_TABLES) . ' EXTENDED'));
    }

    /** Head einer Zone [no, hash, tracking, deleted] oder null */
    public static function head(PDO $p, int $zoneId): ?array
    {
        $r = self::all($p, 'SELECT head_revision_no, head_hash, tracking, deleted_at FROM zone_history_state WHERE zone_id = ?', [$zoneId]);
        return $r === [] ? null : [(int)$r[0]['head_revision_no'], $r[0]['head_hash'], $r[0]['tracking'], $r[0]['deleted_at'] !== null];
    }

    /** Aktueller DNS-Inhalt einer Zone als Snapshot-Hash (eigene Transaktion, sperrend gelesen) */
    public static function currentHash(PDO $p, int $zoneId): ?string
    {
        $p->beginTransaction();
        try {
            $s = (new ZoneSnapshotReader($p))->lockAndRead($zoneId);
            return $s?->contentHash();
        } finally {
            $p->rollBack();
        }
    }

    /** Offene Transaktion dieser Verbindung laut InnoDB? */
    public static function hasOpenTrx(PDO $p): bool
    {
        return (int)self::one($p, 'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()') > 0;
    }

    /**
     * Invarianten der Historie: je Zone mit Head Revisionen lückenlos 1..Head, Head = letzte Revision (Nummer und Hash),
     * für vorhandene Zonen Head-Hash = aktueller DB-Inhalt, gelöschte Zonen nur mit deleted_at; jede Zone hat einen
     * Head; jede Änderungsmenge hat mindestens eine Revision oder (AP1/H2c) ein Audit-Ereignis; Audit-Ereignisse je
     * Änderungsmenge lückenlos mit seq 1..n; jeder Revisions-Hash hat einen Snapshot.
     *
     * @return string[] Befunde (leer = in Ordnung)
     */
    public static function invariants(PDO $p): array
    {
        $bad = [];
        $zones = array_map('intval', self::all($p, 'SELECT id FROM zones ORDER BY id') === [] ? [] : array_column(self::all($p, 'SELECT id FROM zones ORDER BY id'), 'id'));
        foreach (self::all($p, 'SELECT * FROM zone_history_state ORDER BY zone_id') as $s) {
            $z = (int)$s['zone_id'];
            $nos = array_map('intval', array_column(self::all($p, 'SELECT revision_no FROM zone_revisions WHERE zone_id = ? ORDER BY revision_no', [$z]), 'revision_no'));
            if ($nos !== range(1, count($nos)) || end($nos) !== (int)$s['head_revision_no']) {
                $bad[] = "Zone $z: Revisionen " . implode(',', $nos) . " / Head {$s['head_revision_no']}";
            }
            $last = self::one($p, 'SELECT content_hash FROM zone_revisions WHERE zone_id = ? AND revision_no = ?', [$z, (int)$s['head_revision_no']]);
            if ($last !== $s['head_hash']) {
                $bad[] = "Zone $z: Head-Hash ≠ letzte Revision";
            }
            $exists = in_array($z, $zones, true);
            if ($exists && $s['deleted_at'] !== null) {
                $bad[] = "Zone $z: vorhanden, aber als gelöscht markiert";
            }
            if (!$exists && $s['deleted_at'] === null) {
                $bad[] = "Zone $z: gelöscht, Head nicht markiert";
            }
            if ($exists && self::currentHash($p, $z) !== $s['head_hash']) {
                $bad[] = "Zone $z: Head-Hash ≠ aktueller DB-Inhalt";
            }
        }
        foreach ($zones as $z) {
            if (self::head($p, $z) === null) {
                $bad[] = "Zone $z ohne Head";
            }
        }
        $n = (int)self::one($p, 'SELECT COUNT(*) FROM history_change_sets c WHERE NOT EXISTS (SELECT 1 FROM zone_revisions r WHERE r.change_set_id = c.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM audit_events e WHERE e.change_set_id = c.id)');
        if ($n > 0) {
            $bad[] = "$n Änderungsmenge(n) ohne Revision und ohne Audit-Ereignis";
        }
        $n = (int)self::one($p, 'SELECT COUNT(*) FROM (SELECT change_set_id FROM audit_events GROUP BY change_set_id'
            . ' HAVING MIN(seq) <> 1 OR MAX(seq) <> COUNT(*)) g');
        if ($n > 0) {
            $bad[] = "$n Änderungsmenge(n) mit lückenhafter Ereignisfolge";
        }
        $n = (int)self::one($p, 'SELECT COUNT(*) FROM zone_revisions r LEFT JOIN zone_snapshots s ON s.content_hash = r.content_hash WHERE s.content_hash IS NULL');
        if ($n > 0) {
            $bad[] = "$n Revision(en) ohne Snapshot";
        }
        return $bad;
    }

    /* ---------------------------------------------------------------- Nebenläufigkeit */

    /**
     * Startet tests/history/h2b_worker.php in einem eigenen Prozess (eigene Verbindung).
     * @return array{proc: resource, pipes: array, start: float}
     */
    public static function spawn(string ...$args): array
    {
        $cmd = array_merge(['php', __DIR__ . '/h2b_worker.php', self::$app, self::$db, self::$iso], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return ['proc' => $p, 'pipes' => $pipes, 'start' => microtime(true)];
    }

    /** Wartet auf den Worker; liefert die dekodierte JSON-Ausgabe (letzte Zeile) und die Laufzeit */
    public static function join(array $w): array
    {
        $out = stream_get_contents($w['pipes'][1]);
        $err = stream_get_contents($w['pipes'][2]);
        proc_close($w['proc']);
        $lines = array_values(array_filter(explode("\n", (string)$out)));
        $res = json_decode((string)end($lines), true);
        if (!is_array($res)) {
            $res = ['error' => trim($out . ' ' . $err)];
        }
        $res['_seconds'] = microtime(true) - $w['start'];
        return $res;
    }

    /** Wartet, bis der Worker eine Marke in seine Ausgabe-Datei geschrieben hat (Synchronisation über Datei) */
    public static function waitFile(string $file, float $timeout = 20.0): bool
    {
        $end = microtime(true) + $timeout;
        while (microtime(true) < $end) {
            if (is_file($file)) {
                return true;
            }
            usleep(20000);
        }
        return false;
    }

    /** Anzahl wartender Sperranforderungen in dieser Datenbank */
    public static function lockWaits(PDO $p): int
    {
        return (int)self::one($p, 'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS');
    }
}
