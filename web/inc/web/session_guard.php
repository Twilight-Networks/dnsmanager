<?php
/**
 * Datei: inc/web/session_guard.php
 * Zweck: Globale Session-Revalidierung (S3) für alle authentifizierten Web-Anfragen.
 *
 * Wird von common.php unmittelbar nach dem Aufbau der Datenbankverbindung aufgerufen, bevor Seiten- oder
 * Action-Code arbeitet – nur für geschützte Einstiegspunkte (nicht bei ALLOW_UNAUTHENTICATED: login.php,
 * logout.php, DynDNS-API).
 *
 * Erweiterung S26/S27: Zusätzlich müssen users.active = 1 und die Sitzungsgeneration (users.session_epoch)
 * mit der beim Login gespeicherten Generation übereinstimmen. Eine Session ohne Generation (z. B. aus der
 * Zeit vor dem Update) ist nie gültig. Vor der Prüfung wird das Schema kontrolliert (SchemaCheck):
 * fehlende oder abweichende Spalten → HTTP 503 (Fail-Closed), keine automatische Migration.
 *
 * Regeln (Entscheidungen E30–E33, Variante c1):
 * - Identität und Rolle der Session werden bei jeder Anfrage mit der Datenbank abgeglichen
 *   (eine Abfrage über den Primärschlüssel, ohne Sperre, ohne Passwort-Hash).
 * - Benutzer nicht vorhanden, Benutzername geändert, Rolle geändert, Rolle unbekannt, Konto deaktiviert,
 *   Sitzungsgeneration fehlt oder weicht ab → Session wird beendet; neue Rechte gelten erst nach erneuter Anmeldung. Die Session wird dabei
 *   nie angepasst (keine Rechteänderung „im Flug“).
 * - Beenden wie beim bestehenden Inaktivitäts-Timeout: Authentifizierungsdaten verwerfen, neue anonyme
 *   Session mit frischer Session-ID, die ausschließlich den Hinweis „session_expired“ enthält,
 *   Weiterleitung zur Login-Seite. Protokoll: event=session_revoked auf Level 'error' (unabhängig vom
 *   konfigurierten LOG_LEVEL stets ausgegeben, Facility LOG_AUTH bei Syslog).
 * - Technischer Fehler bei der Prüfung → Fail-Closed: Die Anfrage wird mit HTTP 503 abgebrochen,
 *   der Fehler protokolliert (ohne Zugangsdaten, Session-ID oder SQL-Details).
 *
 * Abhängigkeiten: laufende Session (inc/session.php), appLog() (inc/logging.php), BASE_URL.
 */

require_once __DIR__ . '/../app/SchemaCheck.php';

/** Zulässige Rollen einer gültigen Session */
const SESSION_GUARD_ROLES = ['admin', 'zoneadmin'];

/**
 * Prüft das vom Code vorausgesetzte Datenbankschema (SchemaCheck) und beendet die Anfrage bei
 * Abweichung oder Prüffehler mit HTTP 503 (Fail-Closed). Nimmt keine Schemaänderungen vor.
 *
 * @param PDO $pdo Aktive Datenbankverbindung
 *
 * @return void
 */
function session_guard_require_schema(PDO $pdo): void
{
    try {
        $problems = SchemaCheck::problems($pdo);
    } catch (Throwable $e) {
        $problems = ['Prüfung fehlgeschlagen (' . get_class($e) . ')'];
    }
    if ($problems === []) {
        return;
    }

    appLog('error', 'event=schema_incompatible detail=' . implode('; ', $problems)
        . ' hinweis=Migration sql/migrations/001 vor dem Code ausrollen', true);
    http_response_code(503);
    exit('Dienst vorübergehend nicht verfügbar.');
}

/**
 * Gleicht die angemeldete Session mit der Datenbank ab und beendet sie bei Abweichung.
 *
 * Kehrt nur zurück, wenn die Session gültig ist (oder keine Anmeldung besteht); andernfalls
 * endet die Anfrage mit Weiterleitung (ungültige Session) bzw. HTTP 503 (technischer Fehler).
 *
 * @param PDO $pdo Aktive Datenbankverbindung
 *
 * @return void
 */
function revalidate_session(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        return; // keine Anmeldung: require_login() hat bereits entschieden
    }

    $sessionUser = (string)($_SESSION['username'] ?? '');
    $sessionRole = $_SESSION['role'] ?? null;
    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);

    if ($userId === false || $userId <= 0) {
        session_guard_revoke($sessionUser, 0, 'session_invalid');
    }

    session_guard_require_schema($pdo);

    try {
        $stmt = $pdo->prepare('SELECT id, username, role, active, session_epoch FROM users WHERE id = ?');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fail-Closed: ohne bestätigte Identität und Rolle läuft die Anfrage nicht weiter.
        $code = $e instanceof PDOException ? (string)$e->getCode() : '-';
        appLog('error', sprintf(
            'event=session_revalidation_failed user=%s userId=%d ip=%s error=%s code=%s',
            $sessionUser,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            get_class($e),
            preg_replace('/[^A-Za-z0-9]/', '', $code)
        ), true);
        http_response_code(503);
        exit('Dienst vorübergehend nicht verfügbar.');
    }

    if ($row === false) {
        session_guard_revoke($sessionUser, $userId, 'user_missing');
    }
    if ((string)$row['username'] !== $sessionUser) {
        session_guard_revoke($sessionUser, $userId, 'username_changed');
    }
    if (!is_string($sessionRole) || (string)$row['role'] !== $sessionRole) {
        session_guard_revoke($sessionUser, $userId, 'role_changed');
    }
    if (!in_array((string)$row['role'], SESSION_GUARD_ROLES, true)) {
        session_guard_revoke($sessionUser, $userId, 'role_invalid');
    }
    if ((int)$row['active'] !== 1) {
        session_guard_revoke($sessionUser, $userId, 'account_inactive');
    }
    $sessionEpoch = $_SESSION['session_epoch'] ?? null;
    if (!is_string($sessionEpoch) || !ctype_digit($sessionEpoch)) {
        session_guard_revoke($sessionUser, $userId, 'epoch_missing');
    }
    if ($sessionEpoch !== (string)$row['session_epoch']) {
        session_guard_revoke($sessionUser, $userId, 'epoch_changed');
    }
}

/**
 * Beendet die Session (Widerruf), startet eine neue anonyme Session mit frischer ID, setzt nur den
 * Hinweis „session_expired“ und leitet zur Login-Seite weiter.
 *
 * @param string $sessionUser Benutzername laut bisheriger Session (nur für das Protokoll)
 * @param int    $userId      Benutzer-ID laut bisheriger Session (nur für das Protokoll)
 * @param string $reason      user_missing | username_changed | role_changed | role_invalid | session_invalid
 *                            | account_inactive | epoch_missing | epoch_changed
 *
 * @return never
 */
function session_guard_revoke(string $sessionUser, int $userId, string $reason): never
{
    // Sicherheitsereignis: Level 'error' (höchste Stufe), damit es bei keinem gültigen LOG_LEVEL
    // herausgefiltert wird – wie logAccessDenied(). Hinweis zur Semantik von appLog(): Die Level-Filterung
    // greift vor $forceSyslog; $forceSyslog wählt nur bei LOG_TARGET = 'syslog' die Facility LOG_AUTH und
    // erzeugt bei LOG_TARGET = 'apache' keine zusätzliche Syslog-Ausgabe.
    appLog('error', sprintf(
        'event=session_revoked user=%s userId=%d ip=%s reason=%s',
        $sessionUser,
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $reason
    ), true);

    // Bisherige Session vollständig verwerfen (Daten und Speicher)
    $_SESSION = [];
    session_unset();
    session_destroy();

    // Neue anonyme Session mit frischer ID; enthält ausschließlich den Hinweis für die Login-Seite
    session_start();
    session_regenerate_id(true);
    $_SESSION = ['session_expired' => true];

    header('Location: ' . rtrim(BASE_URL, '/') . '/login.php');
    exit;
}
