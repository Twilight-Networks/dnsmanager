<?php
/**
 * Datei: inc/app/publish/SerialReferenceReader.php
 * Zweck: Ermittlung der verbindlichen Serial-Referenzmenge R einer Zone (AS5, E78, D1/D5/D16/D18/D19/D21–D23;
 *        docs/ARCHITECTURE_HISTORY.md Abschnitt 5k). Dieselbe fachliche Implementierung dient der Serial-Auswahl
 *        (Generator), der administrativen Statusanzeige und dem späteren Retentionsschutz (Meilenstein R, RET-S1).
 *
 * Quellen (nur lesend, nicht sperrend; alle Schreiber halten den Publish-Lock, in der Generator-Transaktion also stabil):
 * - zonenweit (D19): DB-Serial der Zone und die Serial des zuletzt registrierten Versuchs unter demselben normalisierten
 *   Zonennamen (über alle Zonen-IDs; keine weitere Historie);
 * - je Paar (normalisierter Name × Zielserver, D1): Journal (zone_publish_targets), zone_serial_references und – nur
 *   soweit nicht gespiegelt – der Verification Store (gelesen ausschließlich über ServerVerificationStore::rowsForName(),
 *   E87).
 *
 * Ereignismodell je Bestandteil F (Datei) und D (ausgeliefert), Ordnung über (Lauf, Phase, Folge) – nie über die Serial:
 *   Phase 1 Vorher-Bericht (serial_check), 2 zone_sync-Ergebnis, 3 conf_sync, 4 cleanup, 5 Monitoring, 6 Admin.
 *   anchor     gemessener Zustand (Wertemenge, auch leer = nachweislich keine Datei bzw. nicht ausgeliefert)
 *   possible   möglicherweise eingetretener Zustand ohne Nachweis (sending/unknown/abandoned, D nach zone_sync ok)
 *   assumed    angenommener D-Zustand nach erfolgreicher Legacy-Veröffentlichung (D18, nie gemessen)
 *   additive   gültige Rohwerte einer unknown-Beobachtung (D21)
 *   conflict   Konfliktreferenz aus serial_check (reject/would_reject mit Serial-Code), je Bestandteil getrennt
 *   admin      administrativ erfasste Serial (D17)
 *   uncertain  Wert ohne sicher bestimmbaren Messzeitpunkt (D16/D23)
 * Ablöseregeln:
 *   S1 Ref_c = Werte des letzten Ankers ∪ spätere possible/additive ∪ letzter späterer assumed ∪ nicht abgelöste
 *      conflict/admin/uncertain.
 *   S2 Ein Anker löst frühere Anker, possible und additive ab (nur nach der Ordnung).
 *   S3 conflict/admin werden nur durch einen späteren Anker desselben Bestandteils mit einem Wert abgelöst, der newer
 *      ist; uncertain ebenso, „später“ dort nach dem Aufzeichnungszeitpunkt (Ordnung nicht bestimmbar, D16).
 *   S4 Nicht sicher geordnete Ereignisse lösen nie ab.
 *   D18 assumed löst nur frühere assumed ab; gemessene D-Anker lösen frühere assumed ab.
 * Sperren (D22): Verlustmarke, intent ohne Abschluss, AS5-fremder Lauf mit as2-zone_sync nach der Epoche (Rückfall),
 *   nicht gespiegelte Beobachtung nach der Epoche. Aufgelöst nur durch administrative Erfassung BEIDER Bestandteile nach
 *   dem sperrenden Ereignis – nie durch eine automatische Messung (Ergänzung 3.1: verlorene Werte gelten nie als
 *   wiederhergestellt).
 * AS4-Historie vor der Epoche sperrt nie (Ergänzung 3.3).
 */

require_once __DIR__ . '/SerialNumber.php';
require_once __DIR__ . '/SerialReferenceStore.php';
require_once __DIR__ . '/ServerVerificationStore.php';

final class SerialReferenceReader
{
    public const PHASE = ['agent_check' => 1, 'zone_sync' => 2, 'conf_sync' => 3, 'cleanup' => 4, 'monitoring' => 5, 'zone_verify' => 5, 'admin' => 6];
    /** Serial-Codes des Agenten (E103), die eine Konfliktreferenz begründen */
    public const SERIAL_CODES = ['stale_serial', 'serial_conflict', 'serial_undefined'];

    public function __construct(private readonly PDO $pdo, private readonly SerialReferenceStore $store)
    {
    }

    /**
     * Zentrale Serial-Auswahl einer Zone (Generator, in dessen Transaktion nach zones … FOR UPDATE): aktuelle Zielmenge
     * (aktive, zugewiesene Server – wie die Registrierung J2), Referenzmenge, SerialPolicy mit Kalenderkandidat.
     *
     * @param array<string, mixed> $zoneRow gesperrte Zonenzeile
     * @return array{ok: bool, serial?: int, basis?: string, reason?: string, detail?: string, refs: list<int>, blocks: list<array>}
     */
    public static function selectSerial(PDO $pdo, array $zoneRow, string $ymd): array
    {
        require_once __DIR__ . '/SerialPolicy.php';
        $stmt = $pdo->prepare('SELECT s.id, s.name FROM zone_servers zs JOIN servers s ON s.id = zs.server_id WHERE zs.zone_id = ? AND s.active = 1 ORDER BY s.id');
        $stmt->execute([(int)$zoneRow['id']]);
        $targets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $targets[(int)$t['id']] = (string)$t['name'];
        }
        $stmt->closeCursor();
        $set = (new self($pdo, new SerialReferenceStore($pdo)))->forZone($zoneRow, $targets);
        $d = SerialPolicy::decide($set['refs'], SerialPolicy::calendarCandidate($ymd), $set['blocks']);

        return $d + ['blocks' => $set['blocks']];
    }

    /**
     * Referenzmenge einer Zone für die gegebenen Zielserver.
     *
     * @param array<string, mixed> $zoneRow Zonenzeile (id, name, soa_serial)
     * @param array<int, string>   $targets server_id => Name (aktuelle Zielmenge; ruhende Paare binden nicht, D5)
     * @return array{zoneName: string, db: int, latestAttempt: ?array{id: int, zoneId: int, serial: int},
     *               pairs: array<int, array{F: array, D: array, blocks: list<array>}>, refs: list<int>, blocks: list<array>,
     *               contributors: array{references: list<int>, attempts: list<int>}}
     */
    public function forZone(array $zoneRow, array $targets): array
    {
        $name = SerialReferenceStore::normName((string)$zoneRow['name']);
        if ($name === null) {
            throw new RuntimeException('Zonenname für die Serial-Referenz ungültig');
        }
        $db = SerialNumber::parse((int)$zoneRow['soa_serial']);
        if ($db === null) {
            throw new RuntimeException('DB-Serial ungültig');
        }
        $stmt = $this->pdo->prepare('SELECT id, zone_id, soa_serial FROM zone_publish_attempts WHERE '
            . SerialReferenceStore::normSql('zone_name') . ' = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$name]);
        $la = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        $latest = $la === null ? null : ['id' => (int)$la['id'], 'zoneId' => (int)$la['zone_id'], 'serial' => (int)$la['soa_serial']];

        $serverIds = array_map('intval', array_keys($targets));
        $pairs = $this->pairs($name, $serverIds);

        $refs = [$db];
        if ($latest !== null) {
            $refs[] = $latest['serial'];
        }
        $blocks = [];
        $contribRefs = [];
        $contribAttempts = $latest !== null ? [$latest['id']] : [];
        foreach ($pairs as $sid => $p) {
            foreach (['F', 'D'] as $c) {
                foreach ($p[$c]['values'] as $v) {
                    $refs[] = $v;
                }
                foreach ($p[$c]['contrib'] as $ref) {
                    if ($ref[0] === 'zsr') {
                        $contribRefs[$ref[1]] = true;
                    } elseif ($ref[0] === 'attempt') {
                        $contribAttempts[] = $ref[1];
                    }
                }
            }
            foreach ($p['blocks'] as $b) {
                $blocks[] = $b + ['serverId' => $sid, 'serverName' => $targets[$sid] ?? ''];
            }
        }

        return ['zoneName' => $name, 'db' => $db, 'latestAttempt' => $latest, 'pairs' => $pairs,
            'refs' => array_values(array_unique($refs)), 'blocks' => $blocks,
            'contributors' => ['references' => array_keys($contribRefs), 'attempts' => array_values(array_unique($contribAttempts))]];
    }

    /**
     * Ereignisse und Ergebnis je Paar (auch für ruhende Paare im Status verwendbar).
     *
     * @param list<int> $serverIds
     * @return array<int, array{F: array, D: array, blocks: list<array>, events: list<array>}>
     */
    public function pairs(string $name, array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }
        $epoch = $this->store->epoch();
        $markers = $epoch === null ? [] : $this->store->runMarkers($epoch['run_key']);
        $in = implode(',', array_fill(0, count($serverIds), '?'));

        $stmt = $this->pdo->prepare('SELECT a.id AS attempt_id, a.run_id, a.zone_id, a.soa_serial, t.server_id, t.zone_sync, t.zone_sync_at,'
            . ' t.zone_protocol, t.zone_installed_serial, t.conf_sync_at, t.conf_verify_state, t.conf_verify_file_serial,'
            . ' t.conf_verify_served_serial FROM zone_publish_attempts a JOIN zone_publish_targets t ON t.attempt_id = a.id'
            . ' WHERE ' . SerialReferenceStore::normSql('a.zone_name') . " = ? AND t.server_id IN ($in) ORDER BY a.id");
        $stmt->execute(array_merge([$name], $serverIds));
        $journal = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $refRows = $this->store->pairRows($name, $serverIds);

        $zsv = (new ServerVerificationStore($this->pdo))->rowsForName($name, $serverIds);   // E87: einziger Leser

        $out = [];
        foreach ($serverIds as $sid) {
            $events = [];
            $blocks = [];
            $admins = [];
            foreach ($refRows as $r) {
                if ((int)$r['server_id'] === $sid && $r['source'] === 'admin') {
                    $admins[] = $r;
                }
            }
            foreach ($journal as $j) {
                if ((int)$j['server_id'] !== $sid) {
                    continue;
                }
                array_push($events, ...self::journalEvents($j));
                if ($epoch !== null && $j['zone_protocol'] === 'as2' && (int)$j['run_id'] > $epoch['run_key'] && !isset($markers[(int)$j['run_id']])) {
                    $blocks[] = ['reason' => 'serial_reference_gap', 'detail' => 'Lauf ' . $j['run_id'] . ' ohne AS5-Laufmarke (Rückfall): serial_check nicht gespeichert',
                        'after' => ['run_key' => (int)$j['run_id']]];
                }
            }
            $completed = [];
            foreach ($refRows as $r) {
                if ((int)$r['server_id'] === $sid && in_array($r['source'], ['agent_check', 'no_report', 'loss'], true) && $r['attempt_id'] !== null) {
                    $completed[(int)$r['attempt_id']] = true;
                }
            }
            foreach ($refRows as $r) {
                if ((int)$r['server_id'] !== $sid) {
                    continue;
                }
                if ($r['source'] === 'intent' && !isset($completed[(int)$r['attempt_id']])) {
                    $blocks[] = ['reason' => 'serial_reference_lost', 'detail' => 'zone_sync Versuch ' . $r['attempt_id'] . ' ohne gespeicherten Abschluss',
                        'after' => ['id' => (int)$r['id']]];
                } elseif ($r['source'] === 'loss') {
                    $blocks[] = ['reason' => 'serial_reference_lost', 'detail' => 'Verlustmarke ' . $r['id'] . ' (' . $r['reason'] . ')',
                        'after' => ['id' => (int)$r['id']]];
                }
                array_push($events, ...self::referenceEvents($r));
            }
            foreach ($zsv as $v) {
                if ((int)$v['server_id'] !== $sid) {
                    continue;
                }
                $mirrored = $this->store->isMirrored($v);
                if (!$mirrored) {
                    array_push($events, ...self::zsvLastEvents($v));
                    if ($epoch !== null && (string)$v['last_observed_at'] > $epoch['recorded_at']) {
                        $blocks[] = ['reason' => 'serial_reference_gap', 'detail' => 'Beobachtung ' . $v['last_method'] . ' vom ' . $v['last_observed_at']
                            . ' nicht gespiegelt', 'after' => ['at' => (string)$v['last_observed_at']]];
                    }
                }
                if ($v['verified_serial'] !== null && (string)$v['last_state'] !== 'verified' && !$this->verifiedMirrored($v, $refRows)) {
                    $vs = (int)$v['verified_serial'];
                    $ev = $v['verified_run_id'] !== null
                        ? ['kind' => 'anchor', 'key' => [(int)$v['verified_run_id'], 3, PHP_INT_MAX]]
                        : ['kind' => 'uncertain', 'key' => [0, 0, 0]];
                    foreach (['F', 'D'] as $c) {
                        $events[] = $ev + ['comp' => $c, 'values' => [$vs], 'at' => (string)$v['verified_at'], 'ref' => ['zsv', (int)$v['zone_id']]];
                    }
                }
            }
            $blocks = array_values(array_filter($blocks, static fn (array $b): bool => !self::resolvedByAdmin($b['after'], $admins)));
            $res = self::compute($events);
            $out[$sid] = ['F' => $res['F'], 'D' => $res['D'], 'blocks' => array_map(static fn (array $b): array => ['reason' => $b['reason'], 'detail' => $b['detail']], $blocks),
                'events' => $events];
        }

        return $out;
    }

    /* ------------------------------------------------------------------------------------------------ */
    /* Reines Modell                                                                                     */
    /* ------------------------------------------------------------------------------------------------ */

    /**
     * Wendet S1–S5 und D18 an (rein).
     *
     * @param list<array{comp: string, kind: string, values: list<int>, key: array{0: int, 1: int, 2: int}, at?: string, ref?: array}> $events
     * @return array{F: array{values: list<int>, contrib: list<array>}, D: array{values: list<int>, contrib: list<array>}}
     */
    public static function compute(array $events): array
    {
        $out = [];
        foreach (['F', 'D'] as $c) {
            $ev = array_values(array_filter($events, static fn (array $e): bool => $e['comp'] === $c));
            $anchors = array_values(array_filter($ev, static fn (array $e): bool => $e['kind'] === 'anchor'));
            $last = null;
            foreach ($anchors as $a) {
                if ($last === null || self::cmpKey($a['key'], $last['key']) > 0) {
                    $last = $a;
                }
            }
            $vals = [];
            $contrib = [];
            $take = static function (array $e, ?array $only = null) use (&$vals, &$contrib): void {
                foreach ($only ?? $e['values'] as $v) {
                    $vals[$v] = true;
                }
                if (isset($e['ref'])) {
                    $contrib[] = $e['ref'];
                }
            };
            if ($last !== null) {
                $take($last);
            }
            $after = static fn (array $e): bool => $last === null || self::cmpKey($e['key'], $last['key']) > 0;
            $assumed = null;
            foreach ($ev as $e) {
                switch ($e['kind']) {
                    case 'possible':
                    case 'additive':
                        if ($after($e)) {
                            $take($e);
                        }
                        break;
                    case 'assumed':
                        if ($after($e) && ($assumed === null || self::cmpKey($e['key'], $assumed['key']) > 0)) {
                            $assumed = $e;
                        }
                        break;
                    case 'conflict':
                    case 'admin':
                    case 'uncertain':
                        $keep = [];
                        foreach ($e['values'] as $v) {
                            if (!self::supersededByNewer($e, $v, $anchors)) {
                                $keep[] = $v;
                            }
                        }
                        if ($keep !== []) {
                            $take($e, $keep);
                        }
                        break;
                }
            }
            if ($assumed !== null) {
                $take($assumed);
            }
            $out[$c] = ['values' => array_keys($vals), 'contrib' => $contrib];
        }

        return $out;
    }

    /** S3: späterer Anker desselben Bestandteils mit einem Wert, der newer als $v ist */
    private static function supersededByNewer(array $e, int $v, array $anchors): bool
    {
        foreach ($anchors as $a) {
            $later = $e['kind'] === 'uncertain'
                ? (isset($a['at'], $e['at']) && $a['at'] !== '' && $e['at'] !== '' && $a['at'] > $e['at'])
                : self::cmpKey($a['key'], $e['key']) > 0;
            if (!$later) {
                continue;
            }
            foreach ($a['values'] as $w) {
                if (SerialNumber::compare($w, $v) === SerialNumber::NEWER) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array{0: int, 1: int, 2: int} $a @param array{0: int, 1: int, 2: int} $b */
    public static function cmpKey(array $a, array $b): int
    {
        return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
    }

    /**
     * Ereignisse einer Journal-Zielzeile (zone_sync und conf_sync-Verifikation).
     *
     * @param array<string, mixed> $j
     * @return list<array>
     */
    public static function journalEvents(array $j): array
    {
        $x = (int)$j['soa_serial'];
        $run = (int)$j['run_id'];
        $aid = (int)$j['attempt_id'];
        $ref = ['attempt', $aid];
        $at = (string)($j['zone_sync_at'] ?? '');
        $k2 = [$run, 2, $aid];
        $inst = $j['zone_installed_serial'] !== null ? (int)$j['zone_installed_serial'] : null;
        $e = static fn (string $comp, string $kind, array $values, array $key, string $at) => ['comp' => $comp, 'kind' => $kind,
            'values' => $values, 'key' => $key, 'at' => $at, 'ref' => $ref];
        $out = [];
        switch ((string)$j['zone_sync']) {
            case 'ok':
                if ($j['zone_protocol'] === 'as2' && $inst !== null) {
                    $out[] = $e('F', 'anchor', [$inst], $k2, $at);
                    $out[] = $e('D', 'possible', [$inst], $k2, $at);
                } elseif ($j['zone_protocol'] === 'as2') {
                    $out[] = $e('F', 'possible', [$x], $k2, $at);
                    $out[] = $e('D', 'possible', [$x], $k2, $at);
                } else {
                    // Legacy bzw. ohne Protokoll: Datei übernommen (F-Anker), Auslieferung nur angenommen (D18)
                    $out[] = $e('F', 'anchor', [$x], $k2, $at);
                    $out[] = $e('D', 'assumed', [$x], $k2, $at);
                }
                break;
            case 'unknown':
                if ($inst !== null) {
                    // R1: installierter Stand bei gescheitertem Reload – F-Anker, D nur möglich (D21)
                    $out[] = $e('F', 'anchor', [$inst], $k2, $at);
                    $out[] = $e('D', 'possible', [$inst], $k2, $at);
                } else {
                    $out[] = $e('F', 'possible', [$x], $k2, $at);
                    $out[] = $e('D', 'possible', [$x], $k2, $at);
                }
                break;
            case 'sending':
            case 'abandoned':
                $out[] = $e('F', 'possible', [$x], $k2, $at);
                $out[] = $e('D', 'possible', [$x], $k2, $at);
                break;
            default:   // failed, rejected: Bestand unverändert
                break;
        }
        if ($j['conf_verify_state'] !== null) {
            $k3 = [$run, 3, $aid];
            $cat = (string)($j['conf_sync_at'] ?? '');
            array_push($out, ...self::obsEvents((string)$j['conf_verify_state'], $j['conf_verify_file_serial'], $j['conf_verify_served_serial'],
                $k3, $cat, true, $ref));
        }

        return $out;
    }

    /**
     * Ereignisse einer Zeile von zone_serial_references.
     *
     * @param array<string, mixed> $r
     * @return list<array>
     */
    public static function referenceEvents(array $r): array
    {
        $src = (string)$r['source'];
        $certain = (int)$r['order_certain'] === 1;
        $ref = ['zsr', (int)$r['id']];
        $at = (string)$r['recorded_at'];
        $fs = $r['file_serial'] !== null ? (int)$r['file_serial'] : null;
        $ss = $r['served_serial'] !== null ? (int)$r['served_serial'] : null;
        $out = [];
        if ($src === 'agent_check') {
            $key = [(int)$r['run_key'], 1, (int)$r['id']];
            if (!$certain) {
                foreach (['F' => $fs, 'D' => $ss] as $c => $v) {
                    if ($v !== null) {
                        $out[] = ['comp' => $c, 'kind' => 'uncertain', 'values' => [$v], 'key' => $key, 'at' => $at, 'ref' => $ref];
                    }
                }
                return $out;
            }
            if ($r['file_state'] === 'valid' && $fs !== null) {
                $out[] = ['comp' => 'F', 'kind' => 'anchor', 'values' => [$fs], 'key' => $key, 'at' => $at, 'ref' => $ref];
            } elseif ($r['file_state'] === 'missing') {
                $out[] = ['comp' => 'F', 'kind' => 'anchor', 'values' => [], 'key' => $key, 'at' => $at, 'ref' => $ref];
            }
            if ($r['served_state'] === 'served' && $ss !== null) {
                $out[] = ['comp' => 'D', 'kind' => 'anchor', 'values' => [$ss], 'key' => $key, 'at' => $at, 'ref' => $ref];
            } elseif ($r['served_state'] === 'not_auth' && $r['config_state'] === 'no') {
                // nachweislich nicht ausgeliefert nur bei K = nein (nie bei widersprüchlichem Konfigurationszustand)
                $out[] = ['comp' => 'D', 'kind' => 'anchor', 'values' => [], 'key' => $key, 'at' => $at, 'ref' => $ref];
            }
            if (in_array($r['decision'], ['reject', 'would_reject'], true) && in_array($r['code'], self::SERIAL_CODES, true)) {
                foreach (['F' => $fs, 'D' => $ss] as $c => $v) {
                    if ($v !== null) {
                        $out[] = ['comp' => $c, 'kind' => 'conflict', 'values' => [$v], 'key' => $key, 'at' => $at, 'ref' => $ref];
                    }
                }
            }
            return $out;
        }
        if (in_array($src, SerialReferenceStore::OBS_SOURCES, true)) {
            return self::obsEvents((string)$r['obs_state'], $fs, $ss, [(int)$r['run_key'], self::PHASE[$src], (int)$r['id']], $at, $certain, $ref);
        }
        if ($src === 'admin') {
            $key = [(int)$r['run_key'], 6, (int)$r['id']];
            if ($fs !== null) {
                $out[] = ['comp' => 'F', 'kind' => 'admin', 'values' => [$fs], 'key' => $key, 'at' => $at, 'ref' => $ref];
            }
            if ($ss !== null) {
                $out[] = ['comp' => 'D', 'kind' => 'admin', 'values' => [$ss], 'key' => $key, 'at' => $at, 'ref' => $ref];
            }
        }

        return $out;
    }

    /** Ereignisse einer Beobachtung (Zustand mit Rohwerten) */
    private static function obsEvents(string $state, mixed $file, mixed $served, array $key, string $at, bool $certain, array $ref): array
    {
        $fs = $file !== null ? (int)$file : null;
        $ss = $served !== null ? (int)$served : null;
        $e = static fn (string $c, string $kind, array $v): array => ['comp' => $c, 'kind' => $kind, 'values' => $v, 'key' => $key, 'at' => $at, 'ref' => $ref];
        if (!$certain) {
            $out = [];
            foreach (['F' => $fs, 'D' => $ss] as $c => $v) {
                if ($v !== null) {
                    $out[] = $e($c, 'uncertain', [$v]);
                }
            }
            return $out;
        }

        return match ($state) {
            'verified', 'serial_differs' => array_values(array_filter([$fs !== null ? $e('F', 'anchor', [$fs]) : null,
                $ss !== null ? $e('D', 'anchor', [$ss]) : null])),
            'still_served' => $ss !== null ? [$e('D', 'anchor', [$ss])] : [],
            'absent' => [$e('D', 'anchor', [])],
            default => array_values(array_filter([$fs !== null ? $e('F', 'additive', [$fs]) : null,
                $ss !== null ? $e('D', 'additive', [$ss]) : null])),
        };
    }

    /** Ereignisse der letzten Beobachtung einer nicht gespiegelten Zeile des Verification Store */
    private static function zsvLastEvents(array $v): array
    {
        $phase = self::PHASE[(string)$v['last_method']] ?? 5;

        return self::obsEvents((string)$v['last_state'], $v['last_file_serial'], $v['last_served_serial'],
            [(int)($v['last_run_id'] ?? 0), $phase, PHP_INT_MAX], (string)$v['last_observed_at'], true, ['zsv', (int)$v['zone_id']]);
    }

    /** positiver Nachweis bereits als geordnete verified-Zeile vorhanden */
    private function verifiedMirrored(array $v, array $refRows): bool
    {
        foreach ($refRows as $r) {
            if ((int)$r['zone_id'] === (int)$v['zone_id'] && (int)$r['server_id'] === (int)$v['server_id'] && $r['obs_state'] === 'verified'
                && (int)$r['order_certain'] === 1 && (string)$r['served_serial'] === (string)$v['verified_serial']
                && (string)$r['file_hash'] === (string)$v['verified_file_hash']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sperre aufgelöst: administrative Erfassung BEIDER Bestandteile nach dem sperrenden Ereignis.
     *
     * @param array{id?: int, run_key?: int, at?: string} $after
     * @param list<array<string, mixed>> $admins
     */
    public static function resolvedByAdmin(array $after, array $admins): bool
    {
        $f = false;
        $d = false;
        foreach ($admins as $r) {
            $isAfter = (isset($after['id']) && (int)$r['id'] > $after['id'])
                || (isset($after['run_key']) && (int)$r['run_key'] >= $after['run_key'] && !isset($after['id']))
                || (isset($after['at']) && (string)$r['recorded_at'] > $after['at']);
            if (!$isAfter) {
                continue;
            }
            $f = $f || $r['file_serial'] !== null;
            $d = $d || $r['served_serial'] !== null;
        }

        return $f && $d;
    }
}
