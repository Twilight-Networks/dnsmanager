<?php
/**
 * Datei: inc/app/publish/SerialReferenceAdminService.php
 * Zweck: Administrative Serial-Referenzen und Status (AS5, D17/D25, D22, D24; docs/ARCHITECTURE_HISTORY.md Abschnitt 5k).
 *        Adapter: web/scripts/serial_admin-cli.php (nur CLI).
 *
 * - status(): je Zone DB-Serial, Zielserver, verbindliche Referenzen je Bestandteil, Sperren und die Entscheidung der
 *   SerialPolicy (dieselbe Implementierung wie im Generator: SerialReferenceReader), ruhende Paare, Publish-Sperre.
 *   Nur lesend, ohne Lock.
 * - record(): erfasst eine tatsächlich erhobene Datei- (F) bzw. ausgelieferte Serial (D) eines Servers für eine Zone
 *   ausschließlich hinzufügend, unter dem Publish-Lock (Try-Lock), mit eindeutiger Zonen- und Serveridentität (ID und
 *   Name müssen zusammenpassen), strenger Eingabeprüfung (RFC-1982-Wertebereich, 0 gültig), Herkunftsangabe und
 *   Audit-Ereignis in derselben Transaktion. Mehrere Erfassungen beim gleichen Wasserstand sind additiv (eigene Zeilen,
 *   Reihenfolge über die ID).
 * Ausgeschlossen (D17): keine Änderung von zones.soa_serial, kein Löschen oder Absenken von Referenzen, kein Schreiben in
 * den Verification Store (kein L3-Nachweis), keine Umgehung der SerialPolicy, kein Publish. Eine Erfassung hebt
 * serial_unresolvable nie auf; sie löst nur einen Referenzverlust bzw. eine Lücke, wenn BEIDE Bestandteile nach dem
 * sperrenden Ereignis erfasst sind (ausdrückliche administrative Feststellung des aktuellen Zustands; der verlorene
 * historische Wert gilt dadurch nicht als wiederhergestellt).
 * Die Fencing-Domäne wird bewusst nicht vorausgesetzt: Bei aktiver Publish-Sperre (fehlende FENCE_DOMAIN, D24) bleibt die
 * Erfassung möglich (Restore- und Rückfallverfahren).
 */

require_once __DIR__ . '/SerialNumber.php';
require_once __DIR__ . '/SerialPolicy.php';
require_once __DIR__ . '/SerialReferenceStore.php';
require_once __DIR__ . '/SerialReferenceReader.php';
require_once __DIR__ . '/FenceContext.php';
require_once __DIR__ . '/PublishLedger.php';
require_once __DIR__ . '/../history/AuditRecorder.php';
require_once __DIR__ . '/../history/AuditPolicy.php';
require_once __DIR__ . '/../history/HistoryContext.php';

final class SerialReferenceAdminService
{
    public const MAX_EVIDENCE = 500;

    private SerialReferenceStore $store;

    public function __construct(private readonly PDO $pdo, private readonly ?FenceContext $fence = null)
    {
        if ((int)$pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('SerialReferenceAdminService benötigt PDO::ERRMODE_EXCEPTION');
        }
        $this->store = new SerialReferenceStore($pdo);
    }

    /**
     * Status (nur lesend).
     *
     * @return array{publishBlocked: bool, publishBlockReason: ?string, epoch: ?array, zones: list<array>, dormant: list<array>}
     */
    public function status(?string $zoneName = null, ?string $ymd = null): array
    {
        $blocked = false;
        $why = null;
        if ($this->fence !== null) {
            try {
                $this->fence->resolve($this->pdo);
            } catch (RuntimeException $e) {
                [$blocked, $why] = [true, $e->getMessage()];
            }
        }
        $ymd ??= date('Ymd');
        $sql = 'SELECT id, name, soa_serial, changed FROM zones';
        $params = [];
        if ($zoneName !== null) {
            $n = SerialReferenceStore::normName($zoneName);
            if ($n === null) {
                throw new RuntimeException('Zonenname ungültig');
            }
            $sql .= ' WHERE ' . SerialReferenceStore::normSql('name') . ' = ?';
            $params[] = $n;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY name');
        $stmt->execute($params);
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($zoneName !== null && $zones === []) {
            throw new RuntimeException('Zone nicht gefunden');
        }
        $reader = new SerialReferenceReader($this->pdo, $this->store);
        $out = [];
        $targetPairs = [];
        foreach ($zones as $z) {
            $targets = $this->targets((int)$z['id']);
            $set = $reader->forZone($z, $targets);
            $decision = SerialPolicy::decide($set['refs'], SerialPolicy::calendarCandidate($ymd), $set['blocks']);
            $pairs = [];
            foreach ($set['pairs'] as $sid => $p) {
                $pairs[$sid] = ['name' => $targets[$sid], 'F' => $p['F']['values'], 'D' => $p['D']['values'], 'blocks' => $p['blocks']];
                $targetPairs[$set['zoneName'] . '|' . $sid] = true;
            }
            $out[] = ['id' => (int)$z['id'], 'name' => (string)$z['name'], 'db' => $set['db'], 'changed' => (int)$z['changed'],
                'latestAttempt' => $set['latestAttempt'], 'pairs' => $pairs, 'refs' => $set['refs'], 'decision' => $decision,
                'contributors' => $set['contributors']];
        }
        $dormant = [];
        foreach ($this->store->knownPairs() as $kp) {
            if (!isset($targetPairs[$kp['zone_name'] . '|' . $kp['server_id']]) && ($zoneName === null
                || $kp['zone_name'] === SerialReferenceStore::normName($zoneName))) {
                $dormant[] = ['zone' => (string)$kp['zone_name'], 'serverId' => (int)$kp['server_id'], 'serverName' => (string)$kp['server_name'],
                    'rows' => (int)$kp['n']];
            }
        }

        return ['publishBlocked' => $blocked, 'publishBlockReason' => $why, 'epoch' => $this->store->epoch(), 'zones' => $out, 'dormant' => $dormant];
    }

    /**
     * Administrative Erfassung einer tatsächlich erhobenen Serial (D17).
     *
     * @param string|null $fileSerial   Serial der verwalteten Zonendatei (F) – genau eine der beiden Angaben
     * @param string|null $servedSerial autoritativ ausgelieferte Serial (D)
     * @return int ID der Referenzzeile
     * @throws RuntimeException bei Ablehnung (keine Änderung)
     */
    public function record(int $zoneId, string $zoneName, int $serverId, string $serverName, ?string $fileSerial, ?string $servedSerial,
        string $evidence): int
    {
        if (($fileSerial === null) === ($servedSerial === null)) {
            throw new RuntimeException('Genau eine Angabe erforderlich: Datei-Serial (F) oder ausgelieferte Serial (D)');
        }
        $raw = $fileSerial ?? $servedSerial;
        $serial = SerialNumber::parse($raw);
        if ($serial === null) {
            throw new RuntimeException('Serial ungültig (Dezimalzahl 0 … 4294967295 ohne Vorzeichen und ohne führende Null)');
        }
        $evidence = trim($evidence);
        if ($evidence === '' || mb_strlen($evidence) > self::MAX_EVIDENCE || !mb_check_encoding($evidence, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/u', $evidence) === 1) {
            throw new RuntimeException('Herkunftsangabe erforderlich (1 … ' . self::MAX_EVIDENCE . ' Zeichen, ohne Steuerzeichen)');
        }
        if (AuditPolicy::looksSecret($evidence)) {
            throw new RuntimeException('Herkunftsangabe enthält ein Geheimnismuster – nicht gespeichert');
        }
        if ($zoneId < 1 || $serverId < 1) {
            throw new RuntimeException('Zonen- bzw. Server-ID ungültig');
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('record() außerhalb einer Transaktion aufrufen');
        }

        $got = $this->pdo->query("SELECT GET_LOCK('" . PublishLedger::MAIN_LOCK . "', 0)")->fetchColumn();
        if ($got === null || $got === false || (int)$got !== 1) {
            throw new RuntimeException('Publish-Lock belegt – keine Erfassung (später erneut ausführen)');
        }
        try {
            // Identität unter dem Publish-Lock (Zonen- und Serveränderungen halten denselben Lock)
            $stmt = $this->pdo->prepare('SELECT name FROM zones WHERE id = ?');
            $stmt->execute([$zoneId]);
            $zn = $stmt->fetchColumn();
            $stmt->closeCursor();
            $norm = SerialReferenceStore::normName($zoneName);
            if ($zn === false || $norm === null || SerialReferenceStore::normName((string)$zn) !== $norm) {
                throw new RuntimeException('Zonen-ID und Zonenname passen nicht zu einer bestehenden Zone');
            }
            $stmt = $this->pdo->prepare('SELECT name FROM servers WHERE id = ?');
            $stmt->execute([$serverId]);
            $sn = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($sn === false || (string)$sn !== $serverName) {
                throw new RuntimeException('Server-ID und Servername passen nicht zu einem bestehenden Server');
            }
            $w = (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM publish_runs')->fetchColumn();
            $this->store->ensureEpoch($w);

            $ctx = HistoryContext::system('serial.reference_record', 'cli');
            $this->pdo->beginTransaction();
            $audit = null;
            try {
                $audit = AuditRecorder::open($this->pdo, $ctx);
                $facts = ['serial_reference_server' => $serverId];
                $facts[$fileSerial !== null ? 'serial_reference_file' : 'serial_reference_served'] = $serial;
                $audit->record('zone', $zoneId, (string)$zn, $zoneId, 'update', [], [], [], $facts);
                $audit->finish();
                $cs = $audit->changeSetId();
                if ($cs === null) {
                    throw new LogicException('Audit-Ereignis ohne Änderungsmenge');
                }
                $id = $this->store->insertAdmin($w, $zoneId, (string)$zn, $serverId, (string)$sn,
                    $fileSerial !== null ? $serial : null, $servedSerial !== null ? $serial : null, $evidence, $cs);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            } finally {
                $audit?->close();
            }

            return $id;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . PublishLedger::MAIN_LOCK . "')")->fetchColumn();
        }
    }

    /** @return array<int, string> aktive, zugewiesene Server (Zielmenge wie J2) */
    private function targets(int $zoneId): array
    {
        $stmt = $this->pdo->prepare('SELECT s.id, s.name FROM zone_servers zs JOIN servers s ON s.id = zs.server_id WHERE zs.zone_id = ? AND s.active = 1 ORDER BY s.id');
        $stmt->execute([$zoneId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $out[(int)$t['id']] = (string)$t['name'];
        }

        return $out;
    }
}
