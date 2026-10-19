<?php
/**
 * Datei: inc/app/records/AutoPtr.php
 * Zweck: Reine Berechnungsfunktionen für den automatischen PTR (AutoPTR).
 *
 * Keine Datenbank, keine Session, kein HTTP. Die Funktionen sind ohne Bootstrap testbar.
 * Die Auswahl der Reverse-Zone entspricht bewusst dem bisherigen Verhalten:
 * IPv4 nur /24-Zonen (c.b.a.in-addr.arpa), IPv6 nur Zonen mit 31 Nibbles (/124).
 */

final class AutoPtr
{
    /**
     * Ermittelt Reverse-Zonenname und PTR-Owner (relativ zur Reverse-Zone) für eine IP-Adresse.
     *
     * @param string $type 'A' oder 'AAAA'
     * @param string $ip   IP-Adresse passend zum Typ
     *
     * @return array{zone: string, name: string}|null null bei ungültiger IP oder unpassendem Typ
     */
    public static function reverseLocation(string $type, string $ip): ?array
    {
        if ($type === 'A' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) !== 4) {
                return null;
            }

            // Das letzte Oktett darf "0" sein (kein Falsy-Vergleich)
            return [
                'zone' => "{$parts[2]}.{$parts[1]}.{$parts[0]}.in-addr.arpa",
                'name' => $parts[3],
            ];
        }

        if ($type === 'AAAA' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false || strlen($bin) !== 16) {
                return null;
            }

            $nibbles = array_reverse(str_split(bin2hex($bin)));

            // Das letzte Nibble darf "0" sein (kein Falsy-Vergleich)
            return [
                'zone' => implode('.', array_slice($nibbles, 1)) . '.ip6.arpa',
                'name' => $nibbles[0],
            ];
        }

        return null;
    }

    /**
     * Berechnet den Ziel-FQDN (PTR-Inhalt, mit abschließendem Punkt) für einen Forward-Owner.
     *
     * - "@" oder leer      -> Zonen-Apex
     * - relativer Name     -> Name + "." + Zone + "."
     * - absoluter Name     -> unverändert, sofern er in der Forward-Zone liegt; sonst 'out_of_zone'
     * - Wildcard-Owner     -> 'wildcard' (erstes Label "*"); es wird kein PTR erzeugt
     *
     * @param string $name     Owner-Name des Forward-Records, wie gespeichert
     * @param string $zoneName Name der Forward-Zone (mit oder ohne abschließenden Punkt)
     *
     * @return array{status: 'ok'|'wildcard'|'out_of_zone', fqdn: ?string}
     */
    public static function targetFqdn(string $name, string $zoneName): array
    {
        $zone = rtrim($zoneName, '.');

        if ($name === '@' || trim($name) === '') {
            return ['status' => 'ok', 'fqdn' => $zone . '.'];
        }

        $absolute = str_ends_with($name, '.');
        $base     = $absolute ? substr($name, 0, -1) : $name;

        if (explode('.', $base)[0] === '*') {
            return ['status' => 'wildcard', 'fqdn' => null];
        }

        if (!$absolute) {
            return ['status' => 'ok', 'fqdn' => $base . '.' . $zone . '.'];
        }

        $baseLc = strtolower($base);
        $zoneLc = strtolower($zone);

        if ($zoneLc !== '' && ($baseLc === $zoneLc || str_ends_with($baseLc, '.' . $zoneLc))) {
            return ['status' => 'ok', 'fqdn' => $base . '.'];
        }

        return ['status' => 'out_of_zone', 'fqdn' => null];
    }
}
