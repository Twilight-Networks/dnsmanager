<?php
/**
 * Datei: inc/app/status/SystemStatusReadService.php
 * Zweck: Lesender Zugriff auf den Publish-Status und die Dashboard-Kennzahlen für beliebige Aufrufkanäle
 *        (bisher direkte Abfragen in templates/layout.php und pages/dashboard.php).
 *
 * - Ausschließlich lesend, keine Transaktionen, keine Sperren.
 * - Die Berechtigung (Akteur laut Datenbank aktiv, Rolle wie im Actor; ActorAccess) ist Bedingung derselben
 *   Abfrage, die die Daten liest. Ohne Berechtigung: null.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ActorAccess.php';

final class SystemStatusReadService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Publish-Status (für jeden aktiven Akteur): bind_dirty und Zahl der Zonen mit changed <> 0.
     * changed/bind_dirty: 0 = sauber, 1 = wartet auf Publish, 2 = von Publish übernommen bzw. nicht sicher
     * abgeschlossen; 1 und 2 gelten als ausstehend.
     *
     * @return array{bindDirty: int, changedZones: int, pending: bool}|null
     */
    public function getPublishStatus(Actor $actor): ?array
    {
        $access = (new ActorAccess($this->pdo))->activeCondition($actor);
        if ($access === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT (SELECT bind_dirty FROM system_status WHERE id = 1) AS bind_dirty,'
            . ' (SELECT COUNT(*) FROM zones WHERE changed <> 0) AS changed_zones'
            . ' FROM DUAL WHERE ' . $access['sql']
        );
        self::bind($stmt, $access['params']);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row === false) {
            return null;
        }

        $bindDirty = (int)$row['bind_dirty'];
        $changedZones = (int)$row['changed_zones'];

        return ['bindDirty' => $bindDirty, 'changedZones' => $changedZones, 'pending' => $bindDirty !== 0 || $changedZones > 0];
    }

    /**
     * Kennzahlen des Admin-Dashboards (nur aktiver admin): Anzahl Benutzer, Zonen, Records.
     *
     * @return array{users: int, zones: int, records: int}|null
     */
    public function getDashboardCounts(Actor $actor): ?array
    {
        if (!$actor->isAdmin()) {
            return null;
        }
        $access = (new ActorAccess($this->pdo))->activeCondition($actor);
        if ($access === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM zones) AS zones,'
            . ' (SELECT COUNT(*) FROM records) AS records'
            . ' FROM DUAL WHERE ' . $access['sql']
        );
        self::bind($stmt, $access['params']);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row === false) {
            return null;
        }

        return ['users' => (int)$row['users'], 'zones' => (int)$row['zones'], 'records' => (int)$row['records']];
    }

    /**
     * @param array<int, int> $params
     */
    private static function bind(PDOStatement $stmt, array $params): void
    {
        $i = 1;
        foreach ($params as $param) {
            $stmt->bindValue($i++, $param, PDO::PARAM_INT);
        }
    }
}
