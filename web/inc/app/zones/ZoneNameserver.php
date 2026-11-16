<?php
/**
 * Datei: inc/app/zones/ZoneNameserver.php
 * Zweck: Gemeinsame Regel für die systemgenerierten Adressrecords (A/AAAA) eines Nameservers innerhalb seiner eigenen
 *        Forward-Zone – genutzt bei der Neuanlage (ZoneService::createZone) und beim NS-/Glue-Neuaufbau
 *        (BindZoneBuildBackend::rebuildNsGlueAndFlag).
 *
 * Liegt der FQDN des Nameservers in der Zone (gleich dem Zonennamen oder ein untergeordneter Name, Vergleich ohne
 * Beachtung von Groß-/Kleinschreibung und an Labelgrenzen, abschließender Punkt ohne Bedeutung), braucht die Zone
 * autoritative Adressrecords für diesen Namen; sonst lehnt named-checkzone sie ab ("NS … has no address records").
 * Das sind Daten der eigenen Zone, nicht der Delegations-Glue einer übergeordneten Zone (den verwaltet die
 * Anwendung nicht).
 */

final class ZoneNameserver
{
    /**
     * Name des Adressrecords relativ zur Zone: '@' am Zonen-Apex, sonst der Teil vor dem Zonennamen (Schreibweise
     * wie im Servernamen); null, wenn der Nameserver nicht in der Zone liegt.
     */
    public static function inZoneHost(string $nsName, string $zoneName): ?string
    {
        $ns = rtrim($nsName, '.');
        $zone = rtrim($zoneName, '.');
        if ($ns === '' || $zone === '') {
            return null;
        }
        if (strcasecmp($ns, $zone) === 0) {
            return '@';
        }
        $suffix = '.' . $zone;
        if (strlen($ns) > strlen($suffix) && strcasecmp(substr($ns, -strlen($suffix)), $suffix) === 0) {
            return substr($ns, 0, strlen($ns) - strlen($suffix));
        }
        return null;
    }
}
