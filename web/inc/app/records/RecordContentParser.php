<?php
/**
 * Datei: inc/app/records/RecordContentParser.php
 * Zweck: Zerlegt den gespeicherten Inhalt eines DNS-Records in seine fachlichen Bestandteile (Gegenstück zu
 *        RecordContentBuilder), z. B. für Bearbeitungsformulare beliebiger Aufrufkanäle.
 *
 * Logik aus templates/record_edit_form.php übernommen (MX, SRV, DKIM, NAPTR, URI, CAA/NAPTR-Präfix). DKIM seit
 * U4-Core tag-basiert: Das Base64-Padding von p= bleibt erhalten, k/h/t werden nur als eigene Tags erkannt.
 * Rein funktional: keine Datenbank, keine Session, keine Ausgabe.
 */

final class RecordContentParser
{
    /**
     * @param string $type     Record-Typ wie gespeichert (z. B. 'MX', 'TXT')
     * @param string $name     Record-Name wie gespeichert
     * @param string $content  Record-Inhalt wie gespeichert
     * @param bool   $isDkim   true, wenn der TXT-Record als DKIM erkannt wurde
     * @param string $zoneName Zonenname
     *
     * @return array{name: string, content: string, mxPriority: string, srvPriority: string, srvWeight: string,
     *               srvPort: string, srvTarget: string, srvProto: string, dkimKeyType: string,
     *               dkimHashAlgos: string, dkimFlags: string, dkimSelector: string, dkimSubdomain: string,
     *               dkimKey: string, naptrOrder: string, naptrPref: string, naptrFlags: string,
     *               naptrService: string, naptrRegexp: string, naptrReplace: string, uriService: string,
     *               uriProto: string, uriPriority: string, uriWeight: string, uriTarget: string,
     *               caaPrefix: string}
     *         name/content: Werte zur Anzeige (MX: Ziel ohne Priorität und ohne abschließenden Punkt;
     *         SRV: nur Dienstname)
     */
    public static function parse(string $type, string $name, string $content, bool $isDkim, string $zoneName): array
    {
        // MX: Priorität extrahieren, Rest als Ziel belassen
        $mx_priority = '';
        if ($type === 'MX') {
            $parts = explode(' ', $content, 2);
            if (ctype_digit($parts[0] ?? '')) {
                $mx_priority = $parts[0];
                $content = $parts[1] ?? '';
                $content = rtrim($content, '.');
            }
        }

        // SRV: Werte extrahieren und Protokoll aus dem Namen ermitteln
        $srv_priority = $weight = $port = $target = $srv_proto = '';
        if ($type === 'SRV') {
            $parts = preg_split('/\s+/', $content, 4);
            if (count($parts) === 4 && array_filter(array_slice($parts, 0, 3), 'ctype_digit') === array_slice($parts, 0, 3)) {
                [$srv_priority, $weight, $port, $target] = $parts;
            }

            if (preg_match('/^(_[^.]+)\._(tcp|udp)\.?$/i', $name, $m)) {
                $srv_proto = strtolower($m[2]);
            }
            if (isset($m[1])) {
                // Nur den Dienstnamen (_sip) ins Namensfeld
                $name = $m[1];
            }
        }

        // DKIM (TXT-Record)
        $dkim_key_type = 'rsa';
        $dkim_hash_algos = '';
        $dkim_flags = '';
        $dkim_selector = '';
        $dkim_subdomain = '';
        $dkim_key = '';

        if ($isDkim) {
            // Selector und optionale Subdomain aus dem Namen
            if (preg_match(
                '/^([^.]+)\._domainkey(?:\.([^.]+))?(?:\.' . preg_quote(rtrim($zoneName, '.'), '/') . ')?\.?$/',
                $name,
                $matches
            )) {
                $dkim_selector = $matches[1];
                $dkim_subdomain = $matches[2] ?? '';
            }

            // DKIM-Tags aus dem zusammengefügten TXT-Inhalt (U4-Core): Zeichenketten verbinden, an „;“ in Tags
            // name=wert zerlegen. p behält das Base64-Padding (=); k, h und t werden nur als eigene Tags erkannt.
            $tags = [];
            if (preg_match_all('/"([^"]*)"/', $content, $matches)) {
                foreach (explode(';', implode('', $matches[1])) as $part) {
                    if (preg_match('/^\s*([A-Za-z]+)\s*=\s*(.*?)\s*$/s', $part, $m)) {
                        $tags[strtolower($m[1])] = $m[2];
                    }
                }
            }
            $p = preg_replace('/\s+/', '', (string)($tags['p'] ?? '')) ?? '';
            if ($p !== '' && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $p)) {
                $dkim_key = $p;
            }
            if (isset($tags['k']) && preg_match('/^(rsa|ed25519)$/i', $tags['k'])) {
                $dkim_key_type = strtolower($tags['k']);
            }
            if (isset($tags['h']) && preg_match('/^[A-Za-z0-9:+\-]+$/', $tags['h'])) {
                $dkim_hash_algos = $tags['h'];
            }
            if (isset($tags['t']) && preg_match('/^[A-Za-z:]+$/', $tags['t'])) {
                $dkim_flags = $tags['t'];
            }
        }

        // NAPTR (Anführungszeichen entfernen)
        $naptr_order = $naptr_pref = $naptr_flags = $naptr_service = $naptr_regexp = $naptr_replace = '';
        if ($type === 'NAPTR') {
            $parts = preg_split('/\s+/', $content, 7);

            if (count($parts) === 7) {
                [$naptr_order, $naptr_pref, $naptr_flags, $naptr_service, $naptr_regexp, $naptr_replace] = array_slice($parts, 0, 6);
                $naptr_replace = $parts[6];
            } elseif (count($parts) === 6) {
                [$naptr_order, $naptr_pref, $naptr_flags, $naptr_service, $naptr_regexp, $naptr_replace] = $parts;
            }

            $naptr_flags = trim($naptr_flags, '"');
            $naptr_service = trim($naptr_service, '"');
            $naptr_regexp = trim($naptr_regexp, '"');
            $naptr_replace = trim($naptr_replace, '"');
        }

        // URI: Name in Dienst und Protokoll, Inhalt in Priorität, Gewicht und Ziel zerlegen
        $uri_service = $uri_proto = $uri_priority = $uri_weight = $uri_target = '';
        if ($type === 'URI') {
            if (preg_match('/^(_[^.]+)\._(tcp|udp)\.?$/i', $name, $m)) {
                $uri_service = $m[1];
                $uri_proto = '_' . strtolower($m[2]);
            }

            $parts = preg_split('/\s+/', trim($content), 3);
            if (count($parts) === 3) {
                [$uri_priority, $uri_weight, $uri_target] = $parts;
                $uri_target = trim($uri_target, '"');
            }
        }

        // CAA/NAPTR: Präfix (Zonen-Suffix abtrennen)
        $zone_suffix = rtrim($zoneName, '.');
        $caa_prefix = $name;
        if (in_array($type, ['CAA', 'NAPTR'])) {
            if (rtrim($name, '.') === $zone_suffix) {
                $caa_prefix = '@';
            } elseif (preg_match('/^(.*)\.' . preg_quote($zone_suffix, '/') . '\.?$/', $name, $m)) {
                $caa_prefix = $m[1];
            }
        }

        return [
            'name'          => $name,
            'content'       => $content,
            'mxPriority'    => $mx_priority,
            'srvPriority'   => $srv_priority,
            'srvWeight'     => $weight,
            'srvPort'       => $port,
            'srvTarget'     => $target,
            'srvProto'      => $srv_proto,
            'dkimKeyType'   => $dkim_key_type,
            'dkimHashAlgos' => $dkim_hash_algos,
            'dkimFlags'     => $dkim_flags,
            'dkimSelector'  => $dkim_selector,
            'dkimSubdomain' => $dkim_subdomain,
            'dkimKey'       => $dkim_key,
            'naptrOrder'    => $naptr_order,
            'naptrPref'     => $naptr_pref,
            'naptrFlags'    => $naptr_flags,
            'naptrService'  => $naptr_service,
            'naptrRegexp'   => $naptr_regexp,
            'naptrReplace'  => $naptr_replace,
            'uriService'    => $uri_service,
            'uriProto'      => $uri_proto,
            'uriPriority'   => $uri_priority,
            'uriWeight'     => $uri_weight,
            'uriTarget'     => $uri_target,
            'caaPrefix'     => $caa_prefix,
        ];
    }
}
