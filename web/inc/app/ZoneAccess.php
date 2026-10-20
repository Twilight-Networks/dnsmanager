<?php
/**
 * Datei: inc/app/ZoneAccess.php
 * Zweck: Zentrale Schreibberechtigungsprüfung für Zonen.
 *
 * Semantik (fail-closed):
 * - admin      -> immer erlaubt
 * - zoneadmin  -> nur mit Eintrag in user_zones (live geprüft, kein Caching)
 * - jede andere Rolle -> verweigert, ohne Datenbankabfrage
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
     * @param Actor $actor
     * @param int   $zoneId Tatsächliche Zonen-ID
     *
     * @return bool
     */
    public function canWriteZone(Actor $actor, int $zoneId): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if ($actor->role !== 'zoneadmin') {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT 1 FROM user_zones WHERE zone_id = ? AND user_id = ?");
        $stmt->execute([$zoneId, $actor->userId]);

        return (bool)$stmt->fetch();
    }

    /**
     * SQL-Bedingung "Zone ist für den Akteur lesbar" zur direkten Verwendung in der eigentlichen
     * Datenabfrage (WHERE-Teil). Dadurch wird die aktuelle Zonenzuweisung in derselben Anweisung
     * geprüft, in der die Daten gelesen werden (kein Zeitfenster zwischen Prüfung und Lesen).
     *
     * Regel (identisch mit canWriteZone()): admin: alle Zonen; zoneadmin: nur über user_zones
     * zugewiesene Zonen; sonstige Rollen: keine Zone.
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
            return ['sql' => '1 = 1', 'params' => []];
        }

        if ($actor->role !== 'zoneadmin') {
            return null;
        }

        return [
            'sql'    => "EXISTS (SELECT 1 FROM user_zones uz_read WHERE uz_read.zone_id = $zoneIdColumn AND uz_read.user_id = ?)",
            'params' => [$actor->userId],
        ];
    }
}
