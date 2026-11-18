<?php
/**
 * Datei: inc/app/history/ZoneSnapshotReader.php
 * Zweck: Liest den aktuellen DNS-Inhalt einer Zone unter Zonensperre als ZoneStateSnapshot (AP1/H2a, E23).
 *
 * Konsistenzregeln (MariaDB 11.8, REPEATABLE READ mit innodb_snapshot_isolation = 1 sowie READ COMMITTED):
 * - Aufruf nur in einer offenen Transaktion (sonst LogicException).
 * - Zuerst die Zonenzeile mit SELECT … FOR UPDATE (zones X, Lock-Reihenfolge ARCHITECTURE_CORE.md Abschnitt 1;
 *   bereits gehaltene Sperre wird nur bestätigt).
 * - Danach Serverzuweisung und Records ausschließlich als sperrende Lesevorgänge (LOCK IN SHARE MODE). Sperrende
 *   Lesevorgänge sehen immer den neuesten bestätigten Stand und erzeugen keinen Read-View; das Ergebnis hängt damit
 *   weder vom Zeitpunkt des Read-Views noch von der Isolationsstufe ab, und ein späterer Konflikt (Fehler 1020) kann
 *   aus diesen Lesevorgängen nicht entstehen. Der Leser führt keine nicht sperrenden Lesevorgänge aus.
 * - Explizite Spalten (Whitelist); keine Hashes, Tokens oder Passwortdaten.
 * - Schreibt nichts.
 */

require_once __DIR__ . '/ZoneStateSnapshot.php';

final class ZoneSnapshotReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Sperrt die Zone (X) und liest ihren Snapshot.
     *
     * @return ZoneStateSnapshot|null null = Zone existiert nicht (mehr)
     *
     * @throws LogicException ohne offene Transaktion
     * @throws PDOException bei Datenbankfehlern (Aufrufer: Rollback)
     */
    public function lockAndRead(int $zoneId): ?ZoneStateSnapshot
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('ZoneSnapshotReader::lockAndRead() erfordert eine offene Transaktion');
        }

        $stmt = $this->pdo->prepare(
            'SELECT name, type, ttl, prefix_length, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum'
            . ' FROM zones WHERE id = ? FOR UPDATE'
        );
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($zone === false) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT server_id, is_master FROM zone_servers WHERE zone_id = ? ORDER BY server_id LOCK IN SHARE MODE'
        );
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $stmt = $this->pdo->prepare(
            'SELECT name, type, content, ttl, server_id FROM records WHERE zone_id = ? ORDER BY id LOCK IN SHARE MODE'
        );
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return ZoneStateSnapshot::fromRows($zone, $assignments, $records);
    }
}
