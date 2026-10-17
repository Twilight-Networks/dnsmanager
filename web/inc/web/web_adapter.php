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
