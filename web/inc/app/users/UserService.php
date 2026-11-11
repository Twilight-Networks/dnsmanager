<?php
/**
 * Datei: inc/app/users/UserService.php
 * Zweck: Schreibende Benutzerverwaltung (Anlegen, Ändern inkl. Rolle und Zonenzuweisungen,
 *        Löschen, Passwortänderung) für beliebige Aufrufkanäle (Web, CLI, späterer MCP-Adapter).
 *
 * Keine Session, kein HTTP, keine Toasts, kein Logging, keine Sprachschlüssel.
 * Ergebnis: ServiceResult mit ServiceMessages (Codes "user.<operation>.<ergebnis>").
 * Passwörter und Passwort-Hashes erscheinen nie in ServiceMessages.
 *
 * Berechtigung:
 * - Maßgeblich ist ausschließlich der Datenbankstand des Akteurs (users.id → users.role), geprüft
 *   in derselben Transaktion unter Zeilensperre (FOR UPDATE). Die Rolle im Actor ist keine
 *   Berechtigungsquelle: Sie wird nur vorab genutzt, um offensichtlich unzulässige Aufrufe ohne
 *   Datenbankzugriff abzulehnen (kann nur verweigern, nie erlauben). Ein Akteur, dessen
 *   Datensatz fehlt oder dessen DB-Rolle nicht passt (z. B. veraltete Web-Session), erhält
 *   "<operation>.access_denied".
 * - Benutzerverwaltung (anlegen, ändern, löschen, deaktivieren, reaktivieren): nur admin.
 * - Passwortänderung: admin für jedes Konto, sonst nur das eigene Konto.
 * - Kontostatus (S27): Ein Akteur mit users.active = 0 gilt wie ein fehlender Datensatz
 *   ("<operation>.access_denied"), geprüft auf der gesperrten Akteurszeile. Ein altes Actor-Objekt
 *   gewährt nach einer Deaktivierung keine Rechte mehr.
 *
 * Sitzungsgeneration (S26/S27): users.session_epoch steigt in derselben Transaktion wie die Änderung
 * bei jeder erfolgreichen Passwortänderung, Rollenänderung, Umbenennung, Deaktivierung und
 * Reaktivierung (nicht bei reinen Zonenänderungen und nicht bei "unchanged"). Die Web-Revalidierung
 * widerruft daraufhin alle Sessions des Kontos.
 *
 * Nebenläufigkeit / Schutz des letzten aktiven Administrators:
 * - Jede Operation, die die Anzahl der aktiven Admins verringern kann (Löschen, Herabstufen
 *   admin → zoneadmin, Deaktivieren), sperrt in EINER Anweisung Akteur, Ziel und ALLE aktuell
 *   committeten Admin-Zeilen (SELECT … WHERE id IN (…) OR role = 'admin' … FOR UPDATE, unverändert)
 *   und zählt auf genau diesem gesperrten Stand nur aktive Admins (active = 1). Zwei solche Transaktionen benötigen
 *   dieselben Admin-Zeilen und werden dadurch serialisiert; die zweite liest nach dem Commit der
 *   ersten den neuen Stand (sperrende Lesezugriffe lesen unter RR und RC die letzte committete
 *   Version). Dadurch bleibt mindestens ein Admin erhalten.
 * - Deadlocks werden dadurch NICHT grundsätzlich ausgeschlossen (z. B. Gap-Locks unter
 *   REPEATABLE READ, Fremdschlüssel-/Cascade-Sperren durch paralleles Löschen von Zonen,
 *   Sperren auf dem UNIQUE-Index username). Ein Deadlock oder Lock-Timeout führt zu einem
 *   vollständigen Rollback und "<operation>.failed"; die Invariante bleibt in jedem Fall
 *   erhalten, weil Prüfung und Änderung in derselben Transaktion unter denselben Sperren laufen.
 *   Es gibt keine automatischen Wiederholungen.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/UserInput.php';

final class UserService
{
    /** Bestehende Username-Regel; \z statt $, damit kein abschließender Zeilenumbruch akzeptiert wird */
    public const USERNAME_PATTERN = '/^[a-zA-Z0-9_\-\.@]+\z/';

    /** Zulässige Rollen (users.role) */
    public const ROLES = ['admin', 'zoneadmin'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Legt einen Benutzer an (nur admin).
     *
     * Ablauf wie bisher user_add.php: Benutzername, Passwortlänge, Existenz, dann Anlage.
     * Für zoneadmin werden Zonen zugewiesen: allZones = alle zum Zeitpunkt der Anlage
     * vorhandenen Zonen; sonst die Zonen-IDs > 0 (dedupliziert). Eine nicht vorhandene Zone
     * führt zum vollständigen Rollback (user.create.failed).
     *
     * Codes: access_denied, invalid_input, invalid_zones, invalid_username, password_too_short,
     *        username_exists, failed, success (Kontext: userId, username, role)
     */
    public function createUser(Actor $actor, UserInput $input, string $password): ServiceResult
    {
        $ctx = ['username' => $input->username, 'role' => $input->role];

        if (!$actor->isAdmin()) {
            return self::result(false, 'audit', 'user.create.access_denied', $ctx + ['actorId' => $actor->userId, 'reason' => 'role']);
        }
        if (!in_array($input->role, self::ROLES, true)) {
            return self::result(false, 'error', 'user.create.invalid_input', $ctx);
        }
        $zoneIds = self::normalizeZoneIds($input->zoneIds);
        if ($zoneIds === null) {
            return self::result(false, 'error', 'user.create.invalid_zones', $ctx);
        }
        if (!preg_match(self::USERNAME_PATTERN, $input->username)) {
            return self::result(false, 'error', 'user.create.invalid_username', $ctx);
        }
        if (strlen($password) < self::passwordMinLength()) {
            return self::result(false, 'error', 'user.create.password_too_short', $ctx + ['minLength' => self::passwordMinLength()]);
        }

        $hash = self::hashPassword($password);
        if ($hash === null) {
            return self::result(false, 'error', 'user.create.failed', $ctx + ['detail' => 'Passwort-Hashing fehlgeschlagen.']);
        }

        try {
            $this->pdo->beginTransaction();

            $locked = $this->lockUsers($actor->userId, $actor->userId, true);
            if (!self::isDbAdmin($locked, $actor->userId)) {
                $this->rollbackIfActive();
                return self::result(false, 'audit', 'user.create.access_denied', $ctx + ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$input->username]);
            if ((int)$stmt->fetchColumn() > 0) {
                $this->rollbackIfActive();
                return self::result(false, 'error', 'user.create.username_exists', $ctx);
            }

            $stmt = $this->pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
            $stmt->execute([$input->username, $hash, $input->role]);

            $userId = (int)$this->pdo->lastInsertId();
            if ($userId < 1) {
                throw new RuntimeException("Benutzer-ID konnte nicht ermittelt werden.");
            }

            if ($input->role === 'zoneadmin') {
                if ($input->allZones) {
                    $stmt = $this->pdo->prepare("INSERT INTO user_zones (user_id, zone_id) SELECT ?, id FROM zones");
                    $stmt->execute([$userId]);
                } else {
                    $this->insertZones($userId, $zoneIds);
                }
            }

            $this->pdo->commit();

            return self::result(true, 'success', 'user.create.success', $ctx + ['userId' => $userId]);
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::result(false, 'error', 'user.create.failed', $ctx + ['detail' => $e->getMessage()]);
        }
    }

    /**
     * Ändert Benutzername, Rolle und Zonenzuweisungen (Ersetzungssemantik wie im Webformular; nur admin).
     *
     * - Rolle admin: alle Zonenzuweisungen werden entfernt.
     * - Rolle zoneadmin: Zuweisungen = zoneIds (> 0, dedupliziert); nicht vorhandene Zone → Rollback.
     * - Keine Änderung (Name, Rolle und – bei zoneadmin – Zonenmenge gleich): unchanged, nichts geschrieben.
     * - Herabstufung des letzten Admins (auch des eigenen Kontos): last_admin.
     *
     * Codes: access_denied, invalid_input, invalid_zones, invalid_username, not_found, unchanged,
     *        last_admin, failed, success (Kontext: userId, username, role, oldRole)
     */
    public function updateUser(Actor $actor, int $userId, UserInput $input): ServiceResult
    {
        $ctx = ['userId' => $userId, 'username' => $input->username, 'role' => $input->role];

        if (!$actor->isAdmin()) {
            return self::result(false, 'audit', 'user.update.access_denied', $ctx + ['actorId' => $actor->userId, 'reason' => 'role']);
        }
        if ($userId <= 0 || !in_array($input->role, self::ROLES, true) || $input->allZones) {
            return self::result(false, 'error', 'user.update.invalid_input', $ctx);
        }
        if (!preg_match(self::USERNAME_PATTERN, $input->username)) {
            return self::result(false, 'error', 'user.update.invalid_username', $ctx);
        }
        $zoneIds = self::normalizeZoneIds($input->zoneIds);
        if ($zoneIds === null) {
            return self::result(false, 'error', 'user.update.invalid_zones', $ctx);
        }

        try {
            $this->pdo->beginTransaction();

            $locked = $this->lockUsers($actor->userId, $userId, true);
            if (!self::isDbAdmin($locked, $actor->userId)) {
                $this->rollbackIfActive();
                return self::result(false, 'audit', 'user.update.access_denied', $ctx + ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }
            if (!isset($locked[$userId])) {
                $this->rollbackIfActive();
                return self::result(false, 'error', 'user.update.not_found', $ctx);
            }

            $old = $locked[$userId];
            $ctx['oldRole'] = $old['role'];

            $stmt = $this->pdo->prepare("SELECT zone_id FROM user_zones WHERE user_id = ? ORDER BY zone_id FOR UPDATE");
            $stmt->execute([$userId]);
            $existingZoneIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $usernameChanged = $old['username'] !== $input->username;
            $roleChanged     = $old['role'] !== $input->role;
            $zonesChanged    = $input->role === 'zoneadmin' && $existingZoneIds !== $zoneIds;

            if (!$usernameChanged && !$roleChanged && !$zonesChanged) {
                $this->rollbackIfActive();
                return self::result(true, 'success', 'user.update.unchanged', $ctx);
            }

            if ($old['role'] === 'admin' && $old['active'] && $input->role !== 'admin' && self::countActiveAdmins($locked) <= 1) {
                $this->rollbackIfActive();
                return self::result(false, 'error', 'user.update.last_admin', $ctx);
            }

            // Sitzungsgeneration nur bei Umbenennung oder Rollenänderung erhöhen (nicht bei reiner Zonenänderung)
            $stmt = $this->pdo->prepare("UPDATE users SET username = ?, role = ?, session_epoch = session_epoch + ? WHERE id = ?");
            $stmt->execute([$input->username, $input->role, ($usernameChanged || $roleChanged) ? 1 : 0, $userId]);

            $stmt = $this->pdo->prepare("DELETE FROM user_zones WHERE user_id = ?");
            $stmt->execute([$userId]);

            if ($input->role === 'zoneadmin') {
                $this->insertZones($userId, $zoneIds);
            }

            $this->pdo->commit();

            return self::result(true, 'success', 'user.update.success', $ctx);
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::result(false, 'error', 'user.update.failed', $ctx + ['detail' => $e->getMessage()]);
        }
    }

    /**
     * Löscht einen Benutzer inkl. Zonenzuweisungen (nur admin). Das eigene Konto darf gelöscht
     * werden, solange ein weiterer aktiver Admin existiert. Deaktivierte Admins zählen nicht.
     *
     * Codes: access_denied, invalid_id, not_found, last_admin, failed, success (Kontext: userId, self)
     */
    public function deleteUser(Actor $actor, int $userId): ServiceResult
    {
        $ctx = ['userId' => $userId, 'self' => $userId === $actor->userId];

        if (!$actor->isAdmin()) {
            return self::result(false, 'audit', 'user.delete.access_denied', $ctx + ['actorId' => $actor->userId, 'reason' => 'role']);
        }
        if ($userId <= 0) {
            return self::result(false, 'error', 'user.delete.invalid_id', $ctx);
        }

        try {
            $this->pdo->beginTransaction();

            $locked = $this->lockUsers($actor->userId, $userId, true);
            if (!self::isDbAdmin($locked, $actor->userId)) {
                $this->rollbackIfActive();
                return self::result(false, 'audit', 'user.delete.access_denied', $ctx + ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }
            if (!isset($locked[$userId])) {
                $this->rollbackIfActive();
                return self::result(false, 'error', 'user.delete.not_found', $ctx);
            }
            if ($locked[$userId]['role'] === 'admin' && $locked[$userId]['active'] && self::countActiveAdmins($locked) <= 1) {
                $this->rollbackIfActive();
                return self::result(false, 'error', 'user.delete.last_admin', $ctx);
            }

            $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            $this->pdo->prepare("DELETE FROM user_zones WHERE user_id = ?")->execute([$userId]);

            $this->pdo->commit();

            return self::result(true, 'success', 'user.delete.success', $ctx);
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::result(false, 'error', 'user.delete.failed', $ctx + ['detail' => $e->getMessage()]);
        }
    }

    /**
     * Ändert ein Passwort. admin: jedes Konto (keine Wiederholung nötig); eigenes Konto: Wiederholung
     * muss übereinstimmen (auch für admins). Reihenfolge der Prüfungen wie bisher user_password.php.
     * Das Passwort wird unverändert übernommen (Bereinigung, z. B. trim, ist Sache des Aufrufers).
     * Der Akteur muss aktiv sein; das Zielkonto darf deaktiviert sein (Reset durch admin).
     * Erfolg erhöht die Sitzungsgeneration des Zielkontos: Alle Sessions dieses Kontos enden – bei
     * eigener Änderung auch die eigene (Kontext self = true; der Web-Adapter beendet sie sofort).
     *
     * Codes: unauthorized, password_too_short, mismatch, hash_failed, access_denied, not_found,
     *        failed, success (Kontext: userId, actorId, self)
     */
    public function changePassword(Actor $actor, int $userId, string $password, ?string $passwordRepeat): ServiceResult
    {
        $isSelf = $userId === $actor->userId;
        $ctx = ['userId' => $userId, 'actorId' => $actor->userId, 'self' => $isSelf];

        if (!$actor->isAdmin() && !$isSelf) {
            return self::result(false, 'error', 'user.password.unauthorized', $ctx);
        }
        if (strlen($password) < self::passwordMinLength()) {
            return self::result(false, 'error', 'user.password.password_too_short', $ctx + ['minLength' => self::passwordMinLength()]);
        }
        if ($isSelf && ($passwordRepeat === null || $password !== $passwordRepeat)) {
            return self::result(false, 'error', 'user.password.mismatch', $ctx);
        }

        $hash = self::hashPassword($password);
        if ($hash === null) {
            return self::result(false, 'error', 'user.password.hash_failed', $ctx);
        }

        try {
            $this->pdo->beginTransaction();

            $locked = $this->lockUsers($actor->userId, $userId, false);
            if (!isset($locked[$actor->userId]) || !$locked[$actor->userId]['active']
                || (!$isSelf && $locked[$actor->userId]['role'] !== 'admin')) {
                $this->rollbackIfActive();
                return self::result(false, 'audit', 'user.password.access_denied', $ctx + ['reason' => 'db_role']);
            }
            if (!isset($locked[$userId])) {
                $this->rollbackIfActive();
                return self::result(false, 'error', 'user.password.not_found', $ctx);
            }

            $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1 WHERE id = ?");
            $stmt->execute([$hash, $userId]);

            $this->pdo->commit();

            return self::result(true, 'success', 'user.password.success', $ctx);
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::result(false, 'error', 'user.password.failed', $ctx + ['detail' => $e->getMessage()]);
        }
    }

    /**
     * Deaktiviert ($active = false) oder reaktiviert ($active = true) ein Konto (nur admin, S27).
     *
     * - Name, Rolle und Zonenzuweisungen bleiben erhalten.
     * - Das eigene Konto kann nicht deaktiviert werden (self).
     * - Der letzte aktive Admin kann nicht deaktiviert werden (last_admin; Sperrung wie beim Löschen).
     * - Erfolg erhöht die Sitzungsgeneration; bestehende Sessions des Kontos enden.
     * - Bereits im Zielzustand: unchanged, nichts geschrieben.
     *
     * Codes (Präfix user.disable bzw. user.enable): access_denied (reason: role|db_role), invalid_id, self,
     *        not_found, last_admin, unchanged, failed, success (Kontext: userId, username, role, self)
     */
    public function setActive(Actor $actor, int $userId, bool $active): ServiceResult
    {
        $op = $active ? 'user.enable' : 'user.disable';
        $ctx = ['userId' => $userId, 'self' => $userId === $actor->userId];

        if (!$actor->isAdmin()) {
            return self::result(false, 'audit', "$op.access_denied", $ctx + ['actorId' => $actor->userId, 'reason' => 'role']);
        }
        if ($userId <= 0) {
            return self::result(false, 'error', "$op.invalid_id", $ctx);
        }
        if (!$active && $userId === $actor->userId) {
            return self::result(false, 'error', "$op.self", $ctx);
        }

        try {
            $this->pdo->beginTransaction();

            // Deaktivieren kann die Zahl aktiver Admins verringern: alle Admin-Zeilen mitsperren
            $locked = $this->lockUsers($actor->userId, $userId, !$active);
            if (!self::isDbAdmin($locked, $actor->userId)) {
                $this->rollbackIfActive();
                return self::result(false, 'audit', "$op.access_denied", $ctx + ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }
            if (!isset($locked[$userId])) {
                $this->rollbackIfActive();
                return self::result(false, 'error', "$op.not_found", $ctx);
            }

            $target = $locked[$userId];
            $ctx += ['username' => $target['username'], 'role' => $target['role']];

            if ($target['active'] === $active) {
                $this->rollbackIfActive();
                return self::result(true, 'success', "$op.unchanged", $ctx);
            }
            if (!$active && $target['role'] === 'admin' && self::countActiveAdmins($locked) <= 1) {
                $this->rollbackIfActive();
                return self::result(false, 'error', "$op.last_admin", $ctx);
            }

            $stmt = $this->pdo->prepare("UPDATE users SET active = ?, session_epoch = session_epoch + 1 WHERE id = ?");
            $stmt->execute([$active ? 1 : 0, $userId]);

            $this->pdo->commit();

            return self::result(true, 'success', "$op.success", $ctx);
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::result(false, 'error', "$op.failed", $ctx + ['detail' => $e->getMessage()]);
        }
    }

    /**
     * Sperrt Akteur und Ziel sowie – mit $includeAdmins – alle aktuell committeten Admin-Zeilen in
     * EINER Anweisung (erste Anweisung der Transaktion). Liefert die gesperrten Zeilen nach ID.
     * Die Sperrmenge ist gegenüber vor S27 unverändert (alle Admin-Zeilen, aktiv oder nicht).
     *
     * @return array<int, array{id: int, username: string, role: string, active: bool}>
     */
    private function lockUsers(int $actorId, int $targetId, bool $includeAdmins): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, active FROM users WHERE id IN (?, ?)'
            . ($includeAdmins ? " OR role = 'admin'" : '')
            . ' ORDER BY id FOR UPDATE'
        );
        $stmt->bindValue(1, $actorId, PDO::PARAM_INT);
        $stmt->bindValue(2, $targetId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'role' => (string)$row['role'], 'active' => (int)$row['active'] === 1];
        }

        return $rows;
    }

    /**
     * @param int[] $zoneIds bereits normalisiert
     */
    private function insertZones(int $userId, array $zoneIds): void
    {
        $insert = $this->pdo->prepare("INSERT INTO user_zones (user_id, zone_id) VALUES (?, ?)");
        foreach ($zoneIds as $zoneId) {
            $insert->execute([$userId, $zoneId]);
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Akteur ist laut gesperrter Zeile aktiver admin.
     *
     * @param array<int, array{role: string, active: bool}> $locked
     */
    private static function isDbAdmin(array $locked, int $actorId): bool
    {
        return isset($locked[$actorId]) && $locked[$actorId]['role'] === 'admin' && $locked[$actorId]['active'];
    }

    /**
     * Anzahl aktiver Admins im gesperrten Stand (deaktivierte Admins zählen nicht).
     *
     * @param array<int, array{role: string, active: bool}> $locked
     */
    private static function countActiveAdmins(array $locked): int
    {
        return count(array_filter($locked, static fn (array $r): bool => $r['role'] === 'admin' && $r['active']));
    }

    /**
     * Zonen-IDs: nur int erlaubt (sonst null = ungültige Eingabe); Werte <= 0 werden wie bisher
     * ignoriert, Duplikate entfernt, aufsteigend sortiert.
     *
     * @param array<mixed> $zoneIds
     *
     * @return int[]|null
     */
    private static function normalizeZoneIds(array $zoneIds): ?array
    {
        $result = [];
        foreach ($zoneIds as $zoneId) {
            if (!is_int($zoneId)) {
                return null;
            }
            if ($zoneId > 0) {
                $result[$zoneId] = $zoneId;
            }
        }
        ksort($result);

        return array_values($result);
    }

    private static function hashPassword(string $password): ?string
    {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
        } catch (ValueError $e) {
            return null;
        }

        return is_string($hash) ? $hash : null;
    }

    private static function passwordMinLength(): int
    {
        if (!defined('PASSWORD_MIN_LENGTH')) {
            throw new LogicException('PASSWORD_MIN_LENGTH ist nicht definiert (Konfiguration nicht geladen).');
        }

        return (int)PASSWORD_MIN_LENGTH;
    }

    private static function result(bool $ok, string $kind, string $code, array $context): ServiceResult
    {
        return new ServiceResult($ok, [new ServiceMessage($kind, $code, $context)]);
    }
}
