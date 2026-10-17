<?php
/**
 * Datei: inc/app/Actor.php
 * Zweck: Minimaler, unveränderlicher Akteur (Principal) für Anwendungscode.
 *
 * Der Actor beschreibt, WER eine Aktion über WELCHEN Kanal auslöst
 * (z. B. Web-UI, später API/MCP). Er kennt weder $_SESSION noch $_SERVER
 * und greift nicht auf die Datenbank zu. Er wird von Adaptern erzeugt
 * (siehe inc/web/web_adapter.php).
 *
 * Bewusst nicht enthalten: Rechteliste, Zonen-Scope, Caching.
 */

final class Actor
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $role,
        public readonly ?string $clientIp,
        public readonly string $channel = 'web'
    ) {
    }

    /**
     * @return bool true, wenn der Akteur die Rolle "admin" hat
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
