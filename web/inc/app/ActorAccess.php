<?php
/**
 * Datei: inc/app/ActorAccess.php
 * Zweck: Prüfung, ob ein Akteur laut Datenbank (noch) aktiv ist und die Rolle des Actor-Objekts hat (S27).
 *
 * Für Application Services ohne Zonenbezug (z. B. PublishService, ZoneReadService::listAssignableServers).
 * Zonenbezogene Prüfungen liegen weiterhin in ZoneAccess, benutzerbezogene in UserService; beide prüfen
 * users.active in ihren bestehenden Abfragen bzw. auf den bereits gesperrten Zeilen.
 *
 * Semantik (fail-closed, wie S3/E34): Rechte bestehen nur, wenn
 * - der Benutzer existiert,
 * - users.active = 1 ist und
 * - users.role der Rolle im Actor entspricht (Schnittmenge, keine Rechteausweitung) und zu den
 *   zulässigen Rollen gehört.
 * Ein altes Actor-Objekt (z. B. aus einer früheren Anfrage oder einem langlebigen Prozess) gewährt damit
 * nach Deaktivierung, Löschung oder Rollenwechsel keine Rechte mehr.
 *
 * Keine Sperren, keine Transaktion: Die Prüfung ist eine Momentaufnahme. Für sperrende Prüfungen in
 * Schreibtransaktionen sind ZoneAccess::lockZoneWritePermissions() bzw. die Sperren in UserService und
 * ZoneService maßgeblich.
 *
 * Kein Zugriff auf Session/HTTP, keine Toasts, keine Redirects, kein Logging.
 */

require_once __DIR__ . '/Actor.php';

final class ActorAccess
{
    /** Rollen, die überhaupt Rechte haben können */
    public const ROLES = ['admin', 'zoneadmin'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Ist der Akteur laut Datenbank aktiv und hat er die Rolle des Actor-Objekts?
     *
     * Eine Abfrage über den Primärschlüssel, ohne Sperre.
     *
     * @return bool false auch bei unbekannter Rolle (ohne Datenbankzugriff)
     */
    public function isActive(Actor $actor): bool
    {
        if (!in_array($actor->role, self::ROLES, true) || $actor->userId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE id = ? AND role = ? AND active = 1');
        $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $actor->role, PDO::PARAM_STR);
        $stmt->execute();
        $ok = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $ok;
    }

    /**
     * SQL-Bedingung "Akteur ist aktiv und hat die Rolle des Actor-Objekts" zur Verwendung in der
     * eigentlichen Datenabfrage (WHERE-Teil), damit Prüfung und Lesen in derselben Anweisung erfolgen.
     *
     * @return array{sql: string, params: array<int, int>}|null null = Rolle ohne Rechte (keine Abfrage nötig)
     */
    public function activeCondition(Actor $actor): ?array
    {
        if (!in_array($actor->role, self::ROLES, true)) {
            return null;
        }

        // Rolle als Literal aus der festen Liste (kein Benutzerwert), Benutzer-ID als Parameter
        return [
            'sql'    => "EXISTS (SELECT 1 FROM users ua_act WHERE ua_act.id = ? AND ua_act.role = '" . $actor->role . "' AND ua_act.active = 1)",
            'params' => [$actor->userId],
        ];
    }
}
