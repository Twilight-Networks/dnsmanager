<?php
/**
 * Datei: inc/app/zones/SoaMname.php
 * Zweck: Gemeinsame Bildung und Prüfung des SOA-MNAME (primärer Nameserver, Spalte zones.soa_ns) für
 *        ZoneService (Neuanlage, Masterwechsel, ausdrückliche Übernahme) und ServerService (Umbenennung).
 *
 * Zielsemantik (Forward- und Reverse-Zonen): SOA-MNAME = vollständiger FQDN des zugewiesenen Master-Servers mit
 * genau einem abschließenden Punkt. Servernamen sind FQDN mit mindestens zwei Labels (ServerService::validate);
 * es wird weder der Zonenname noch eine weitere Domain angehängt. Der gespeicherte Wert bleibt für die
 * Zonendatei maßgeblich; der Generator rechnet nichts neu.
 */

require_once __DIR__ . '/../../validators.php';

final class SoaMname
{
    /** SOA-MNAME aus dem Namen des Master-Servers (mit oder ohne abschließenden Punkt gespeichert) */
    public static function fromServerName(string $serverName): string
    {
        return rtrim($serverName, '.') . '.';
    }

    /**
     * Gültiger Master-Name für den SOA-MNAME: sichere Zeichen und FQDN-Regeln (isValidSoaName, S17) sowie
     * mindestens zwei Labels wie bei Servernamen. Ungültige oder unvollständige Namen (nur aus Altbeständen
     * möglich) werden abgelehnt statt ergänzt.
     */
    public static function isValidServerName(string $serverName): bool
    {
        $name = rtrim($serverName, '.');
        return $name !== '' && substr_count($name, '.') >= 1 && isValidSoaName($name . '.');
    }

    /** Gleicher DNS-Name: ohne Beachtung von Groß-/Kleinschreibung und abschließendem Punkt */
    public static function sameName(string $a, string $b): bool
    {
        return strcasecmp(rtrim($a, '.'), rtrim($b, '.')) === 0;
    }
}
