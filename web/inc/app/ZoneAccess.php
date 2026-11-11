<?php
/**
 * Datei: inc/app/ZoneAccess.php
 * Zweck: Zentrale Zonenberechtigungsprüfung (Schreiben und Lesen).
 *
 * Semantik (fail-closed, S3/E34):
 * - Rechte bestehen nur, wenn die Rolle des Akteurs mit seiner AKTUELLEN Rolle in der Datenbank
 *   übereinstimmt (Schnittmenge, keine Rechteausweitung) – auch für admins.
 * - admin      -> alle Zonen, sofern users.role = 'admin'
 * - zoneadmin  -> nur Zonen mit Eintrag in user_zones, sofern users.role = 'zoneadmin'
 * - jede andere Rolle -> verweigert, ohne Datenbankabfrage
 * - deaktiviertes Konto (users.active = 0, S27) -> verweigert wie ein fehlender Benutzer; geprüft in
 *   derselben Abfrage bzw. auf derselben gesperrten users-Zeile (keine zusätzliche Sperre)
 * - Rolle und Zonenzuweisung werden in derselben SQL-Abfrage geprüft (live, kein Caching).
 * - canWriteZone()/readableZoneCondition(): nicht sperrende Lesung (Vorabprüfung, Lesepfade);
 *   innerhalb einer offenen Transaktion gilt deren Lesesicht.
 * - lockZoneWritePermissions(): sperrende, für Schreibtransaktionen maßgebliche Prüfung (R4).
 *
 * Kein Zugriff auf Session/HTTP, keine Toasts, keine Redirects, kein Logging.
 */

require_once __DIR__ . '/Actor.php';

final class ZoneAccess
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Darf der Akteur in der angegebenen Zone schreiben?
     *
     * Eine Abfrage: aktuelle DB-Rolle des Akteurs (muss der Actor-Rolle entsprechen) und – für
     * zoneadmins – die Zonenzuweisung.
     *
     * @param Actor $actor
     * @param int   $zoneId Tatsächliche Zonen-ID
     *
     * @return bool
     */
    public function canWriteZone(Actor $actor, int $zoneId): bool
    {
        if ($actor->isAdmin()) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND active = 1");
            $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
        } elseif ($actor->role === 'zoneadmin') {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM users u JOIN user_zones uz ON uz.user_id = u.id"
                . " WHERE u.id = ? AND u.role = 'zoneadmin' AND u.active = 1 AND uz.zone_id = ?"
            );
            $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $zoneId, PDO::PARAM_INT);
        } else {
            return false;
        }

        $stmt->execute();

        return (bool)$stmt->fetch();
    }

    /**
     * Sperrende Prüfung der Schreibberechtigung innerhalb einer laufenden Transaktion (R4, E40).
     *
     * Sperrt die BERECHTIGUNG des Akteurs bis zum Ende der Transaktion – nicht die Zonen selbst:
     *   1. users (S):      SELECT role, active FROM users WHERE id = ? LOCK IN SHARE MODE
     *   2. user_zones (S): nur zoneadmin – je Zonen-ID aufsteigend eine Punktabfrage über den
     *                      Primärschlüssel (user_id, zone_id) mit LOCK IN SHARE MODE
     * Die Reihenfolge users → user_zones ist durch getrennte Anweisungen festgelegt (kein Join).
     * Ein Rechteentzug (UPDATE/DELETE users, DELETE user_zones) wartet dadurch bis zum Commit bzw.
     * Rollback der aufrufenden Transaktion; ein zuvor committeter Entzug wird gesehen (Locking Reads
     * lesen unter RR und RC den jüngsten Commit).
     *
     * Muss vor dem Sperren der Zonen (dyndnsLockZones, zones X) und vor jedem einfachen SELECT der
     * Transaktion aufgerufen werden: users (S) → user_zones (S) → zones (X), wie ZoneService (E13).
     *
     * Fail-closed: Benutzer fehlt, Konto deaktiviert, DB-Rolle ≠ Actor-Rolle oder andere Rolle → leeres Ergebnis.
     * admin (DB-Rolle admin): alle angefragten Zonen-IDs, ohne Zuweisungsprüfung.
     * zoneadmin (DB-Rolle zoneadmin): nur Zonen-IDs mit vorhandener Zuweisung.
     *
     * @param Actor $actor
     * @param int[] $zoneIds Zonen-IDs, für die geschrieben werden soll (Duplikate/ungültige IDs entfallen)
     *
     * @return int[] Zonen-IDs mit gesperrter Schreibberechtigung, aufsteigend
     *
     * @throws LogicException ohne aktive Transaktion
     */
    public function lockZoneWritePermissions(Actor $actor, array $zoneIds): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('lockZoneWritePermissions() erfordert eine aktive Transaktion.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $zoneIds), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        if ($ids === [] || (!$actor->isAdmin() && $actor->role !== 'zoneadmin')) {
            return [];
        }

        // 1. DB-Rolle und Kontostatus sperrend lesen (users, S)
        $stmt = $this->pdo->prepare('SELECT role, active FROM users WHERE id = ? LOCK IN SHARE MODE');
        $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row === false || (string)$row['role'] !== $actor->role || (int)$row['active'] !== 1) {
            return [];
        }

        if ($actor->isAdmin()) {
            return $ids;
        }

        // 2. Zuweisungen sperrend lesen (user_zones, S), Punktabfrage je (user_id, zone_id), aufsteigend
        $stmt = $this->pdo->prepare('SELECT zone_id FROM user_zones WHERE user_id = ? AND zone_id = ? LOCK IN SHARE MODE');
        $allowed = [];
        foreach ($ids as $zoneId) {
            $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $zoneId, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->fetchColumn() !== false) {
                $allowed[] = $zoneId;
            }
            $stmt->closeCursor();
        }

        return $allowed;
    }

    /**
     * SQL-Bedingung "Zone ist für den Akteur lesbar" zur direkten Verwendung in der eigentlichen
     * Datenabfrage (WHERE-Teil). Dadurch werden aktuelle DB-Rolle und Zonenzuweisung in derselben
     * Anweisung geprüft, in der die Daten gelesen werden (kein Zeitfenster zwischen Prüfung und Lesen).
     *
     * Regel (identisch mit canWriteZone()): admin (DB-Rolle admin): alle Zonen; zoneadmin (DB-Rolle
     * zoneadmin): nur über user_zones zugewiesene Zonen; sonstige Rollen oder abweichende DB-Rolle:
     * keine Zone.
     *
     * @param Actor  $actor
     * @param string $zoneIdColumn Spalte mit der Zonen-ID in der aufrufenden Abfrage (z. B. "z.id");
     *                             nur einfache Bezeichner, keine Benutzereingaben
     *
     * @return array{sql: string, params: array<int, int>}|null null = keine Zone lesbar
     *
     * @throws InvalidArgumentException bei ungültigem Spaltenbezeichner
     */
    public function readableZoneCondition(Actor $actor, string $zoneIdColumn): ?array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $zoneIdColumn)) {
            throw new InvalidArgumentException('Ungültiger Spaltenbezeichner für die Zonen-ID.');
        }

        if ($actor->isAdmin()) {
            return [
                'sql'    => "EXISTS (SELECT 1 FROM users ua_read WHERE ua_read.id = ? AND ua_read.role = 'admin' AND ua_read.active = 1)",
                'params' => [$actor->userId],
            ];
        }

        if ($actor->role !== 'zoneadmin') {
            return null;
        }

        return [
            'sql'    => "EXISTS (SELECT 1 FROM user_zones uz_read JOIN users ua_read ON ua_read.id = uz_read.user_id"
                . " WHERE uz_read.zone_id = $zoneIdColumn AND uz_read.user_id = ? AND ua_read.role = 'zoneadmin' AND ua_read.active = 1)",
            'params' => [$actor->userId],
        ];
    }
}
