<?php
/**
 * Datei: inc/app/zones/ZoneBuildBackend.php
 * Zweck: Schnittstelle des ZoneService zur Zonendatei-Erzeugung und -Prüfung.
 *
 * Beide Operationen laufen innerhalb der offenen Transaktion des Aufrufers und dürfen keine eigene
 * Transaktion beginnen oder beenden. Ergebnisstruktur: ['status' => 'ok'|'warning'|'error',
 * 'output' => string].
 *
 * Einzige Implementierung derzeit: LegacyZoneBuildBackend (inc/deploy), Übergangslösung bis zur
 * Verlagerung von NS/Glue-Aufbau und Prüfung in die Application-Schicht.
 */

interface ZoneBuildBackend
{
    /**
     * Zonendatei erzeugen und prüfen (named-checkzone); bei Erfolg Zone als geändert markieren.
     *
     * @return array{status: string, output: string}
     */
    public function validateAndFlag(int $zoneId): array;

    /**
     * NS- und Glue-Records der Zone aus der Serverzuweisung neu aufbauen, danach wie validateAndFlag().
     *
     * @return array{status: string, output: string}
     */
    public function rebuildNsGlueAndFlag(int $zoneId): array;
}
