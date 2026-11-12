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
 * Paket 5c: Die fachliche Prüfung (Regeln und Reihenfolge unverändert) liegt im webunabhängigen
 * AuthService::validateSession(); diese Datei bildet dessen Ergebnis auf Widerruf, HTTP 503 und die
 * bisherigen Protokollzeilen ab. Zulässige Rollen: AuthService::ROLES.
 *
 * Abhängigkeiten: laufende Session (inc/session.php), appLog() (inc/logging.php), BASE_URL.
 */

require_once __DIR__ . '/../app/SchemaCheck.php';
require_once __DIR__ . '/../app/auth/AuthService.php';

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

    session_guard_schema_failed($problems);
}

/**
 * Protokolliert ein inkompatibles Schema und beendet die Anfrage mit HTTP 503 (Fail-Closed).
 *
 * @param array<int, string> $problems Befunde aus SchemaCheck
 *
 * @return never
 */
function session_guard_schema_failed(array $problems): never
{
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

    // Fachliche Prüfung (Identität, Rolle, Aktivstatus, Sitzungsgeneration, Schema) im Core (Paket 5c);
    // hier nur Abbildung auf Widerruf, HTTP 503 und die bisherigen Protokollzeilen.
    $result = (new AuthService($pdo))->validateSession(
        $_SESSION['user_id'],
        $_SESSION['username'] ?? null,
        $_SESSION['role'] ?? null,
        $_SESSION['session_epoch'] ?? null
    );

    foreach ($result->messages as $message) {
        $ctx = $message->context;
        switch ($message->code) {
            case 'auth.session.valid':
                return;

            case 'auth.session.revoked':
                session_guard_revoke($sessionUser, (int)$ctx['userId'], (string)$ctx['reason']);

            case 'auth.session.schema_incompatible':
                session_guard_schema_failed($ctx['problems']);

            case 'auth.session.check_failed':
                // Fail-Closed: ohne bestätigte Identität und Rolle läuft die Anfrage nicht weiter.
                appLog('error', sprintf(
                    'event=session_revalidation_failed user=%s userId=%d ip=%s error=%s code=%s',
                    $sessionUser,
                    (int)$ctx['userId'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $ctx['errorClass'],
                    $ctx['errorCode']
                ), true);
                http_response_code(503);
                exit('Dienst vorübergehend nicht verfügbar.');

            default:
                // Unbekannte ServiceMessages nie still verwerfen (Fail-Closed, siehe unten)
                appLog('error', "Unbehandelte ServiceMessage '{$message->code}' (session_guard)", true);
                break;
        }
    }

    // Kein eindeutiges Ergebnis: Fail-Closed wie bei einem technischen Fehler
    http_response_code(503);
    exit('Dienst vorübergehend nicht verfügbar.');
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
