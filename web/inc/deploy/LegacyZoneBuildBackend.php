<?php
/**
 * Datei: inc/deploy/LegacyZoneBuildBackend.php
 * Zweck: Übergangs-Implementierung von ZoneBuildBackend auf Basis der bestehenden Legacy-Funktionen
 *        rebuild_zone_and_flag_if_valid() und rebuild_ns_and_glue_for_zone_and_flag_if_valid()
 *        (inc/helpers.php → generate_zone_file() im Modus 'validate').
 *
 * Klar abgegrenzter Legacy-Adapter (E16):
 * - Die Funktionen werden IMMER mit der übergebenen PDO-Verbindung aufgerufen (kein $GLOBALS['pdo']).
 * - Die Generatorprüfungen (SOA-Felder, Record-Felder) wirken dadurch unverändert mit.
 * - inc/helpers.php enthält zusätzlich Web-Funktionen; diese werden hier nicht verwendet.
 * - Wird abgelöst, sobald NS/Glue-Aufbau und Zonenprüfung in der Application-Schicht liegen.
 */

require_once __DIR__ . '/../app/zones/ZoneBuildBackend.php';
require_once __DIR__ . '/../helpers.php';

final class LegacyZoneBuildBackend implements ZoneBuildBackend
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function validateAndFlag(int $zoneId): array
    {
        $r = rebuild_zone_and_flag_if_valid($zoneId, $this->pdo);

        return ['status' => (string)($r['status'] ?? 'error'), 'output' => (string)($r['output'] ?? '')];
    }

    public function rebuildNsGlueAndFlag(int $zoneId): array
    {
        $r = rebuild_ns_and_glue_for_zone_and_flag_if_valid($this->pdo, $zoneId);

        return ['status' => (string)($r['status'] ?? 'error'), 'output' => (string)($r['output'] ?? '')];
    }
}
