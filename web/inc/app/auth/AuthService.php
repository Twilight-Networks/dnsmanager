<?php
/**
 * Datei: inc/app/auth/AuthService.php
 * Zweck: Fachliche Authentifizierung unabhängig von Session, Cookie und HTTP (Paket 5c):
 *        Prüfung von Zugangsdaten und Kontostatus (bisher login_user() in inc/auth.php) sowie Prüfung einer
 *        bestehenden Anmeldung gegen die Datenbank (bisher revalidate_session() in inc/web/session_guard.php).
 *
 * Logik, Reihenfolge und Abfragen unverändert übernommen. Der Service setzt keine Session, schreibt keine Logs,
 * sendet keine Header und leitet nicht weiter; das übernehmen die Web-Adapter (inc/auth.php,
 * inc/web/session_guard.php). Ergebnis jeweils als ServiceResult mit genau einer ServiceMessage.
 *
 * Sicherheitseigenschaften (unverändert):
 * - Passwortprüfung ausschließlich mit password_verify() gegen den gespeicherten Hash; das Hash-Verfahren
 *   bestimmt weiterhin UserService beim Setzen des Passworts.
 * - Nur aktive Konten (users.active = 1) werden angemeldet (S27). password_verify läuft auch für deaktivierte
 *   Konten; ein deaktiviertes Konto mit falschem Passwort ist von einem falschen Passwort nicht unterscheidbar.
 * - S31 (SP1): Für unbekannte Benutzernamen erfolgt eine Prüfung gegen DummyPasswordHash (Ergebnis verworfen),
 *   um die zeitliche Unterscheidbarkeit zu verringern; Ergebnis-Codes und Protokolle unverändert.
 * - Eine Anmeldung ist nur gültig, wenn Benutzer-ID, Benutzername, Rolle (admin|zoneadmin), Aktivstatus und
 *   Sitzungsgeneration (session_epoch, S26) mit der Datenbank übereinstimmen; die Prüfung erfolgt in der
 *   bisherigen Reihenfolge und liefert den ersten zutreffenden Widerrufsgrund.
 */

require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../SchemaCheck.php';
require_once __DIR__ . '/DummyPasswordHash.php';

final class AuthService
{
    /** Zulässige Rollen einer gültigen Anmeldung */
    public const ROLES = ['admin', 'zoneadmin'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Prüft Zugangsdaten und Kontostatus.
     *
     * Meldungen:
     * - auth.login.success (userId, username, role, sessionEpoch): Anmeldung zulässig; sessionEpoch als
     *   Zeichenkette (wie bisher in der Session gespeichert)
     * - auth.login.inactive (username): Passwort korrekt, Konto deaktiviert
     * - auth.login.failed (username): unbekannter Benutzer oder falsches Passwort (auch bei deaktiviertem Konto)
     */
    public function authenticate(string $username, string $password): ServiceResult
    {
        $stmt = $this->pdo->prepare("SELECT id, password_hash, role, active, session_epoch FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$user) {
            // S31 (SP1): unbekannter Benutzer – Prüfung gegen festen Dummy-Hash, Ergebnis wird verworfen
            DummyPasswordHash::consumeVerification($password);
        }
        $passwordOk = $user && password_verify($password, $user['password_hash']);

        if ($passwordOk && (int)$user['active'] === 1) {
            return new ServiceResult(true, [new ServiceMessage('success', 'auth.login.success', [
                'userId'       => $user['id'],
                'username'     => $username,
                'role'         => $user['role'],
                'sessionEpoch' => (string)$user['session_epoch'],
            ])]);
        }

        if ($passwordOk) {
            return new ServiceResult(false, [new ServiceMessage('audit', 'auth.login.inactive', ['username' => $username])]);
        }

        return new ServiceResult(false, [new ServiceMessage('audit', 'auth.login.failed', ['username' => $username])]);
    }

    /**
     * Prüft eine bestehende Anmeldung (Werte so, wie sie beim Login in der Session gespeichert wurden).
     *
     * @param mixed $userId       gespeicherte Benutzer-ID
     * @param mixed $username     gespeicherter Benutzername
     * @param mixed $role         gespeicherte Rolle
     * @param mixed $sessionEpoch gespeicherte Sitzungsgeneration
     *
     * Meldungen (genau eine):
     * - auth.session.valid (userId)
     * - auth.session.revoked (userId, reason): session_invalid | user_missing | username_changed | role_changed
     *   | role_invalid | account_inactive | epoch_missing | epoch_changed – erster zutreffender Grund in der
     *   bisherigen Reihenfolge
     * - auth.session.schema_incompatible (problems): vorausgesetztes Schema fehlt bzw. weicht ab (SchemaCheck)
     * - auth.session.check_failed (userId, errorClass, errorCode): technischer Fehler bei der Datenbankprüfung
     *   (ohne SQL-Details, Zugangsdaten oder Session-ID)
     */
    public function validateSession(mixed $userId, mixed $username, mixed $role, mixed $sessionEpoch): ServiceResult
    {
        $sessionUser = (string)($username ?? '');
        $id = filter_var($userId, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            return self::revoked(0, 'session_invalid');
        }

        try {
            $problems = SchemaCheck::problems($this->pdo);
        } catch (Throwable $e) {
            $problems = ['Prüfung fehlgeschlagen (' . get_class($e) . ')'];
        }
        if ($problems !== []) {
            return new ServiceResult(false, [new ServiceMessage('error', 'auth.session.schema_incompatible', ['problems' => $problems])]);
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id, username, role, active, session_epoch FROM users WHERE id = ?');
            $stmt->bindValue(1, $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } catch (Throwable $e) {
            $code = $e instanceof PDOException ? (string)$e->getCode() : '-';
            return new ServiceResult(false, [new ServiceMessage('error', 'auth.session.check_failed', [
                'userId'     => $id,
                'errorClass' => get_class($e),
                'errorCode'  => preg_replace('/[^A-Za-z0-9]/', '', $code),
            ])]);
        }

        if ($row === false) {
            return self::revoked($id, 'user_missing');
        }
        if ((string)$row['username'] !== $sessionUser) {
            return self::revoked($id, 'username_changed');
        }
        if (!is_string($role) || (string)$row['role'] !== $role) {
            return self::revoked($id, 'role_changed');
        }
        if (!in_array((string)$row['role'], self::ROLES, true)) {
            return self::revoked($id, 'role_invalid');
        }
        if ((int)$row['active'] !== 1) {
            return self::revoked($id, 'account_inactive');
        }
        if (!is_string($sessionEpoch) || !ctype_digit($sessionEpoch)) {
            return self::revoked($id, 'epoch_missing');
        }
        if ($sessionEpoch !== (string)$row['session_epoch']) {
            return self::revoked($id, 'epoch_changed');
        }

        return new ServiceResult(true, [new ServiceMessage('success', 'auth.session.valid', ['userId' => $id])]);
    }

    private static function revoked(int $userId, string $reason): ServiceResult
    {
        return new ServiceResult(false, [new ServiceMessage('audit', 'auth.session.revoked', ['userId' => $userId, 'reason' => $reason])]);
    }
}
