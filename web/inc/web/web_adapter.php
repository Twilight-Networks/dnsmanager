<?php
/**
 * Datei: inc/web/web_adapter.php
 * Zweck: Adapter zwischen der Web-Session und dem Anwendungscode.
 *
 * Nur dieser Adapter darf $_SESSION und $_SERVER kennen. Die Authentifizierung
 * bleibt bei require_login() bzw. requireRole(); hier wird nichts geprüft.
 */

require_once __DIR__ . '/../app/Actor.php';

/**
 * Übersetzt die bestehende, eingeloggte Web-Session in einen Actor.
 *
 * @return Actor
 */
function actorFromSession(): Actor
{
    return new Actor(
        (int)($_SESSION['user_id'] ?? 0),
        (string)($_SESSION['username'] ?? ''),
        (string)($_SESSION['role'] ?? ''),
        isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null,
        'web'
    );
}

/**
 * Strukturprüfung einer Mehrfachauswahl aus einem Formular (z. B. $_POST['zones']).
 *
 * Erlaubt ist nur eine Liste (fortlaufende Schlüssel ab 0, array_is_list()) aus Zeichenketten, wie sie
 * ein <select multiple name="x[]"> liefert; eine leere Liste ist zulässig. Skalare Werte, assoziative
 * bzw. nicht fortlaufend indizierte Arrays (z. B. x[5]=…) und verschachtelte Arrays gelten als ungültige
 * Eingabestruktur.
 *
 * @param mixed $value Rohwert aus $_POST
 *
 * @return string[]|null Werte in Eingabereihenfolge oder null bei ungültiger Struktur
 */
function postStringList(mixed $value): ?array
{
    if (!is_array($value) || !array_is_list($value)) {
        return null;
    }
    foreach ($value as $item) {
        if (!is_string($item)) {
            return null;
        }
    }

    return array_values($value);
}
