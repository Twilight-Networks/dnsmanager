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
 * - Rolle und Zonenzuweisung werden in derselben SQL-Abfrage geprüft (live, kein Caching).
 * - Nicht sperrende Lesung: Innerhalb einer offenen Transaktion gilt deren Lesesicht; es entstehen
 *   keine zusätzlichen Sperren und keine neue Sperrreihenfolge.
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
            $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin'");
            $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
        } elseif ($actor->role === 'zoneadmin') {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM users u JOIN user_zones uz ON uz.user_id = u.id"
                . " WHERE u.id = ? AND u.role = 'zoneadmin' AND uz.zone_id = ?"
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
                'sql'    => "EXISTS (SELECT 1 FROM users ua_read WHERE ua_read.id = ? AND ua_read.role = 'admin')",
                'params' => [$actor->userId],
            ];
        }

        if ($actor->role !== 'zoneadmin') {
            return null;
        }

        return [
            'sql'    => "EXISTS (SELECT 1 FROM user_zones uz_read JOIN users ua_read ON ua_read.id = uz_read.user_id"
                . " WHERE uz_read.zone_id = $zoneIdColumn AND uz_read.user_id = ? AND ua_read.role = 'zoneadmin')",
            'params' => [$actor->userId],
        ];
    }
}
