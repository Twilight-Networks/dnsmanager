<?php
/**
 * Datei: inc/app/dyndns/DyndnsPublishBackend.php
 * Zweck: Schnittstelle für die sofortige Veröffentlichung einer Zone nach einem DynDNS-Update.
 *
 * Wird INNERHALB der offenen Transaktion des DyndnsUpdateService aufgerufen (unverändertes Verhalten:
 * Verteilung vor dem Commit, siehe Architektur-Inventar S32). Implementierung: LegacyDyndnsPublishBackend.
 * Rückgabe: ['status' => 'ok'|'warning'|'error', 'output' => string].
 */

interface DyndnsPublishBackend
{
    /** @return array{status: string, output: string} */
    public function publishZone(int $zoneId, string $zoneName): array;
}
