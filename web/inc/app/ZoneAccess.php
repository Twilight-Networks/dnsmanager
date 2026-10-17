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
}
