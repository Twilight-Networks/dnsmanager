<?php
/**
 * Datei: inc/app/users/UserReadService.php
 * Zweck: Lesender Zugriff auf Benutzer und ihre Zonenzuweisungen für beliebige Aufrufkanäle
 *        (Web, CLI, späterer MCP-Adapter).
 *
 * - Ausschließlich lesend (nur SELECT), keine Seiteneffekte, keine Transaktionen, keine Sperren.
 * - Berechtigung anhand der tatsächlichen DB-Rolle des Akteurs (users.id = Actor::$userId),
 *   als Bedingung derselben Abfrage, die die Daten liest:
 *     admin     -> alle Benutzer
 *     zoneadmin -> nur der eigene Datensatz
 *     sonst / Akteur nicht (mehr) vorhanden -> keine Benutzer
 *   Die Rolle im Actor wird nicht ausgewertet.
 * - Berechtigungsfilterung und Pagination erfolgen auf der Benutzerebene (abgeleitete Tabelle);
 *   die Zonenzuweisungen werden erst danach angehängt und verändern die Benutzerseite nicht.
 * - Expliziter Read-Contract: id, username, role, createdAt, allZones (= Rolle admin), zones
 *   (gespeicherte Zuweisungen [{id, name}]; für admin ohne Wirkung). Niemals password_hash.
 * - Stabile Sortierung: Rolle (admin vor zoneadmin), dann ID; Zonen je Benutzer nach Zonen-ID.
 *
 * Fehlerfälle:
 * - Nicht vorhandene und nicht berechtigte Benutzer werden gleich behandelt (null).
 * - Ungültige Limit-/Offset-Werte führen zu einer InvalidArgumentException.
 */

require_once __DIR__ . '/../Actor.php';

final class UserReadService
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    /** Sichtbarkeitsbedingung: a = Akteur (DB-Stand), u = gelesener Benutzer */
    private const VISIBLE = "(a.role = 'admin' OR (a.role = 'zoneadmin' AND u.id = a.id))";

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Für den Akteur sichtbare Benutzer inkl. Zonenzuweisungen.
     *
     * @return array{users: array<int, array>, limit: int, offset: int, hasMore: bool}
     *
     * @throws InvalidArgumentException bei ungültigem Limit oder Offset
     */
    public function listUsers(Actor $actor, int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit muss zwischen 1 und ' . self::MAX_LIMIT . ' liegen.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('offset darf nicht negativ sein.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.username, p.role, p.created_at, z.id AS zone_id, z.name AS zone_name'
            . ' FROM (SELECT u.id, u.username, u.role, u.created_at FROM users u JOIN users a ON a.id = ?'
            . ' WHERE ' . self::VISIBLE
            . ' ORDER BY u.role ASC, u.id ASC LIMIT ? OFFSET ?) p'
            . ' LEFT JOIN user_zones uz ON uz.user_id = p.id'
            . ' LEFT JOIN zones z ON z.id = uz.zone_id'
            . ' ORDER BY p.role ASC, p.id ASC, z.id ASC'
        );
        $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit + 1, PDO::PARAM_INT); // ein Benutzer mehr zur Ermittlung von hasMore
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = self::group($stmt->fetchAll(PDO::FETCH_ASSOC));
        $hasMore = count($users) > $limit;

        return [
            'users'   => array_slice($users, 0, $limit),
            'limit'   => $limit,
            'offset'  => $offset,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Ein Benutzer inkl. Zonenzuweisungen, sofern er existiert und für den Akteur sichtbar ist.
     *
     * @return array|null null = nicht vorhanden oder nicht berechtigt
     */
    public function getUser(Actor $actor, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.role, u.created_at, z.id AS zone_id, z.name AS zone_name'
            . ' FROM users u JOIN users a ON a.id = ?'
            . ' LEFT JOIN user_zones uz ON uz.user_id = u.id'
            . ' LEFT JOIN zones z ON z.id = uz.zone_id'
            . ' WHERE u.id = ? AND ' . self::VISIBLE
            . ' ORDER BY z.id ASC'
        );
        $stmt->bindValue(1, $actor->userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->execute();

        $users = self::group($stmt->fetchAll(PDO::FETCH_ASSOC));

        return $users[0] ?? null;
    }

    /**
     * Fasst Zeilen (Benutzer × Zonenzuweisung) zu Benutzern zusammen; Reihenfolge bleibt erhalten.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array>
     */
    private static function group(array $rows): array
    {
        $users = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (!isset($users[$id])) {
                $users[$id] = [
                    'id'        => $id,
                    'username'  => (string)$r['username'],
                    'role'      => (string)$r['role'],
                    'createdAt' => $r['created_at'] === null ? null : (string)$r['created_at'],
                    'allZones'  => $r['role'] === 'admin',
                    'zones'     => [],
                ];
            }
            if ($r['zone_id'] !== null) {
                $users[$id]['zones'][] = ['id' => (int)$r['zone_id'], 'name' => (string)$r['zone_name']];
            }
        }

        return array_values($users);
    }
}
