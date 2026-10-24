<?php
/**
 * Datei: inc/app/users/UserInput.php
 * Zweck: Unveränderliche Eingabedaten für UserService::createUser() und UserService::updateUser().
 *
 * - username: bereits vom Aufrufer bereinigt (Web: trim); der Service validiert, ändert aber nichts
 * - role:     'admin' | 'zoneadmin' (strikt im Service geprüft)
 * - zoneIds:  Zonen-IDs (int); Werte <= 0 werden wie bisher ignoriert, Duplikate entfernt
 * - allZones: nur createUser(): alle zum Zeitpunkt der Anlage vorhandenen Zonen zuweisen
 *
 * Passwörter sind bewusst NICHT Teil dieses Objekts (eigene Methodenparameter).
 */

final class UserInput
{
    /**
     * @param string $username
     * @param string $role
     * @param int[]  $zoneIds
     * @param bool   $allZones
     */
    public function __construct(
        public readonly string $username,
        public readonly string $role,
        public readonly array $zoneIds = [],
        public readonly bool $allZones = false
    ) {
    }
}
