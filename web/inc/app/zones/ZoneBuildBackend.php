<?php
/**
 * Datei: inc/app/zones/ZoneBuildBackend.php
 * Zweck: Schnittstelle der Services (Zone, Record, Server, DynDNS) zur Zonendatei-Prüfung und zum
 *        NS-/Glue-Neuaufbau.
 *
 * Beide Operationen laufen ausschließlich innerhalb der offenen Transaktion des Aufrufers und dürfen keine
 * eigene Transaktion beginnen oder beenden. Ohne offene Transaktion bricht die Implementierung mit
 * LogicException ab. Commit und Rollback (auch bei status 'error') verantwortet der Aufrufer; er hält die
 * Zonenzeile gesperrt. Ergebnisstruktur: ['status' => 'ok'|'warning'|'error', 'output' => string].
 *
 * Implementierung: BindZoneBuildBackend (inc/deploy).
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
