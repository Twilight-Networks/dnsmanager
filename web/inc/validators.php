<?php
/**
 * Datei: validators.php
 * Zweck: Zentrale Validierung von DNS-Records anhand ihres Typs.
 * Details:
 * - Verwendet für record_add.php und record_update.php
 * - Gültigkeit von IPs, Hostnames, SPF/DKIM etc. wird geprüft.
 * Zugriff: Nur intern per require_once einbinden.
 */

declare(strict_types=1);

/**
 * Validiert einen DNS-Record anhand von Typ, Namen und Inhalt.
 *
 * @param string    $type     Record-Typ (z. B. A, MX, NS, PTR, TXT, etc.)
 * @param string    $name     Hostname oder Subdomain (z. B. www, @, 42)
 * @param string    $content  Inhalt des Records (IP, Domain, Text, etc.)
 * @param int|null  $ttl      TTL in Sekunden (optional)
 * @return string[]           Array von Fehlermeldungen
 */
function validateDnsRecord(string $type, string $name, string $content, ?int $ttl = null): array
{
    $errors = [];

    // Namen dürfen keine Leer- oder Steuerzeichen enthalten
    if (preg_match('/[\s\p{C}]/u', $name)) {
        $errors[] = 'ERR_INVALID_NAME_CHARS';
    }

    // TTL prüfen (darf nicht negativ sein)
    if ($ttl !== null && $ttl < 0) {
        $errors[] = 'ERR_TTL_NEGATIVE';
    }

    switch (strtoupper($type)) {
        case 'A':
            // Inhalt muss gültige IPv4-Adresse sein
            if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = 'ERR_INVALID_IPV4';
            }
            break;

        case 'AAAA':
            // Inhalt muss gültige IPv6-Adresse sein
            if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $errors[] = 'ERR_INVALID_IPV6';
            }
            break;

        case 'MX':
            $parts = preg_split('/\s+/', trim($content));
            if (count($parts) !== 2) {
                $errors[] = 'ERR_MX_PARTS_INVALID';
            } else {
                [$prio, $target] = $parts;

                if (!ctype_digit($prio) || (int)$prio < 0 || (int)$prio > 65535) {
                    $errors[] = 'ERR_MX_PRIORITY_INVALID';
                }

                if (!isValidFqdn($target)) {
                    $errors[] = 'ERR_MX_TARGET_INVALID';
                }
            }
            break;

        case 'CNAME':
        case 'NS':
        case 'PTR':
            // Inhalt muss ein gültiger FQDN sein
            if (!isValidFqdn($content)) {
                $errors[] = 'ERR_FQDN_REQUIRED';
            }
            break;

        case 'SPF':
            // Inhalt muss mit "v=spf1 ..." beginnen (inkl. Anführungszeichen)
            if (!preg_match('/^"v=spf1\s.+"/', $content)) {
                $errors[] = 'ERR_SPF_FORMAT';
            }
            break;

        case 'DKIM':
            // Alle DKIM-Abschnitte aus Quoted-Strings zusammenführen
            if (!preg_match_all('/"([^"]+)"/', $content, $matches)) {
                $errors[] = 'ERR_DKIM_QUOTES';
                break;
            }

            $joined = implode('', $matches[1]);

            if (!str_starts_with($joined, 'v=DKIM1')) {
                $errors[] = 'ERR_DKIM_VERSION';
            }

            if (!preg_match('/\bk=(rsa|ed25519)\b/i', $joined)) {
                $errors[] = 'ERR_DKIM_KEYTYPE';
            }

            if (preg_match('/\bp=([A-Za-z0-9+\/=]{32,})\b/', $joined, $m)) {
                $key = $m[1];

                // Versuchen, Base64 zu dekodieren
                $decoded = base64_decode($key, true);

                // Zusätzlich sicherstellen, dass es ein echter Base64-„Roundtrip“ ist
                if ($decoded === false || base64_encode($decoded) !== $key) {
                    $errors[] = 'ERR_DKIM_KEY_INVALID';
                }
            } else {
                $errors[] = 'ERR_DKIM_KEY_MISSING';
            }

            break;

        case 'TXT':
            // TXT-Eintrag darf nicht länger als 512 Zeichen sein
            if (strlen($content) > 512) {
                $errors[] = 'ERR_TXT_TOO_LONG';
            }
            break;

        case 'LOC':
            $loc_pattern = '/^\s*
                \d{1,2}\s+                # Breitengrad Grad
                \d{1,2}\s+                # Breitengrad Minuten
                (?:\d{1,2}(?:\.\d{1,3})?\s+)?  # Breitengrad Sekunden (optional)
                [NS]\s+
                \d{1,3}\s+               # Längengrad Grad
                \d{1,2}\s+               # Längengrad Minuten
                (?:\d{1,2}(?:\.\d{1,3})?\s+)?  # Längengrad Sekunden (optional)
                [EW]
                (?:\s+-?\d+(?:\.\d+)?m   # Höhe (optional)
                    (?:\s+\d+(?:\.\d+)?m # Größe (optional)
                        (?:\s+\d+(?:\.\d+)?m # Horizontale Präzision (optional)
                            (?:\s+\d+(?:\.\d+)?m)? # Vertikale Präzision (optional)
                        )?
                    )?
                )?
                \s*$/x';
            if (!preg_match($loc_pattern, $content)) {
                $errors[] = 'ERR_LOC_FORMAT';
            }
            break;

        case 'CAA':
                    // CAA-Record: Format "<flag> <tag> <value>"
                    if (!preg_match('/^(0|128) (issue|issuewild|iodef) ".+?"$/', $content)) {
                        $errors[] = 'ERR_CAA_FORMAT';
                    }
                    break;

        case 'SRV':
            $parts = preg_split('/\s+/', trim($content));
            if (count($parts) !== 4) {
                $errors[] = 'ERR_SRV_PARTS_INVALID';
            } else {
                [$prio, $weight, $port, $target] = $parts;

                foreach ([$prio, $weight, $port] as $val) {
                    if (!ctype_digit($val) || (int)$val < 0 || (int)$val > 65535) {
                        $errors[] = 'ERR_SRV_NUMERIC_FIELDS';
                        break;
                    }
                }

                if (!isValidFqdn($target)) {
                    $errors[] = "Target im SRV-Record ist kein gültiger FQDN.";
                }
            }
            break;

        case 'NAPTR':
            $parts = preg_split('/\s+/', trim($content), 6);
            if (count($parts) !== 6) {
                $errors[] = 'ERR_NAPTR_PARTS_INVALID';
            } else {
                [$order, $preference, $flags, $service, $regexp, $replacement] = $parts;

                // Order und Preference prüfen (Ganzzahlen)
                foreach (['Order' => $order, 'Preference' => $preference] as $label => $val) {
                    if (!ctype_digit($val) || (int)$val < 0 || (int)$val > 65535) {
                        $errors[] = 'ERR_NAPTR_ORDER_PREF';
                    }
                }

                // Flags und Service müssen in doppelte Anführungszeichen eingeschlossen sein
                if (!preg_match('/^".*"$/', $flags)) {
                    $errors[] = 'ERR_NAPTR_FLAGS';
                }

                if (!preg_match('/^".+?"$/', $service)) {
                    $errors[] = 'ERR_NAPTR_SERVICE';
                }

                // Regexp validieren: Muss mit Anführungszeichen eingeschlossen sein
                if (!preg_match('/^".*?"$/', $regexp)) {
                    $errors[] = 'ERR_NAPTR_REGEXP';
                }

                // Replacement: gültiger FQDN oder ein einzelner Punkt (".")
                if ($replacement !== '.' && !isValidFqdn($replacement)) {
                    $errors[] = 'ERR_NAPTR_REPLACEMENT';
                }
            }
            break;

            case 'URI':
                $parts = preg_split('/\s+/', trim($content), 3);
                if (count($parts) !== 3) {
                    $errors[] = 'ERR_URI_PARTS_INVALID';
                } else {
                    [$priority, $weight, $target] = $parts;

                    if (!ctype_digit($priority) || (int)$priority < 0 || (int)$priority > 65535) {
                        $errors[] = 'ERR_URI_PRIORITY';
                    }

                    if (!ctype_digit($weight) || (int)$weight < 0 || (int)$weight > 65535) {
                        $errors[] = 'ERR_URI_WEIGHT';
                    }

                    if (!preg_match('/^".+?"$/', $target)) {
                        $errors[] = 'ERR_URI_TARGET';
                    }
                }
                break;
        default:
            // Unbekannter/unsupported Record-Typ
            $errors[] = 'ERR_UNKNOWN_RECORD_TYPE';
    }

    return $errors;
}

/**
 * Funktion: validateZoneInput
 * Zweck:
 *   - Validiert die Eingaben zur Erstellung oder Bearbeitung einer DNS-Zone.
 *   - Unterstützt sowohl Forward- als auch Reverse-Zonen (IPv4, IPv6).
 *   - Prüft Zonenname, Prefix-Length, SOA-Felder und IP-Adressen.
 *
 * @param array $input  Assoziatives Array (z. B. $_POST), enthält die Formularwerte.
 * @return string[]     Liste von Fehlermeldungen (leeres Array = gültig)
 */
function validateZoneInput(array $input, bool $requirePrefix = true): array
{
    $errors = [];

    $prefix = trim($input['zone_prefix'] ?? '');
    $type_raw = $input['type'] ?? '';
    $type = in_array($type_raw, ['reverse_ipv4', 'reverse_ipv6']) ? 'reverse' : 'forward';

    // Reverse-Suffix anhängen (nur zur Validierung des Gesamtnamens)
    if ($type === 'reverse') {
        $suffix = $type_raw === 'reverse_ipv6' ? '.ip6.arpa' : '.in-addr.arpa';
        $name = $prefix . $suffix;
    } else {
        $name = $prefix;
    }

    // Nur wenn explizit gefordert, Zonenprefix + Name prüfen
    if ($requirePrefix) {
        if ($name === '') {
            $errors[] = 'ERR_ZONE_EMPTY';
        }

        if (!preg_match('/^[a-zA-Z0-9._\-]+$/', str_replace(['.in-addr.arpa', '.ip6.arpa'], '', $name))) {
            $errors[] = 'ERR_ZONE_NAME_INVALID';
        }
    }

    if ($type === 'reverse') {
        $prefix_length = (int)($input['prefix_length'] ?? 0);
        if ($prefix_length < 8 || $prefix_length > 128) {
            $errors[] = 'ERR_ZONE_PREFIX_LENGTH';
        }
    }

    // SOA-Felder prüfen
    $soa_mail = trim($input['soa_mail'] ?? '');

    if ($soa_mail === '' || !isValidFqdn($soa_mail)) {
        $errors[] = 'ERR_ZONE_SOA_MAIL';
    }

    // SOA-Zeiten prüfen
    $soa_refresh = (int)($input['soa_refresh'] ?? 0);
    $soa_retry   = (int)($input['soa_retry'] ?? 0);
    $soa_expire  = (int)($input['soa_expire'] ?? 0);
    $soa_minimum = (int)($input['soa_minimum'] ?? 0);

    if ($soa_refresh < 1200 || $soa_refresh > 86400) {
        $errors[] = 'ERR_ZONE_SOA_REFRESH';
    }

    if ($soa_retry < 180 || $soa_retry > 7200) {
        $errors[] = 'ERR_ZONE_SOA_RETRY';
    }

    if ($soa_expire < 1209600 || $soa_expire > 2419200) {
        $errors[] = 'ERR_ZONE_SOA_EXPIRE';
    }

    if ($soa_minimum < 300 || $soa_minimum > 86400) {
        $errors[] = 'ERR_ZONE_SOA_MINIMUM';
    }

    return $errors;
}

/**
 * Prüft, ob ein String ein gültiger FQDN (Fully Qualified Domain Name) ist.
 *
 * Kriterien:
 * - Darf keine IP-Adresse sein (auch nicht mit Punkt am Ende)
 * - Muss aus gültigen DNS-Labels bestehen (max. 63 Zeichen pro Label, nur a–z, A–Z, 0–9, -)
 * - Kein Label darf mit Bindestrich beginnen oder enden
 * - Gesamtlänge max. 253 Zeichen (ohne Endpunkt)
 *
 * @param string $fqdn Eingabe, z. B. "mail.example.com." oder "sub.domain.de"
 * @return bool true, wenn valider FQDN; sonst false
 */
function isValidFqdn(string $fqdn): bool
{
    // IP-Adressen (auch mit Punkt am Ende) sind explizit KEIN FQDN
    if (filter_var(rtrim($fqdn, '.'), FILTER_VALIDATE_IP)) {
        return false;
    }

    // Endpunkt entfernen (ist bei FQDN optional)
    $fqdn = rtrim($fqdn, '.');

    // Maximale Gesamtlänge prüfen (253 Zeichen ohne Endpunkt)
    if (strlen($fqdn) > 253) {
        return false;
    }

    // In Labels aufteilen und einzeln prüfen
    $labels = explode('.', $fqdn);
    foreach ($labels as $label) {
        // Label darf nur aus a–z, A–Z, 0–9 und - bestehen, 1–63 Zeichen lang
        if (!preg_match('/^[a-zA-Z0-9-]{1,63}$/', $label)) {
            return false;
        }

        // Kein Label darf mit - beginnen oder enden
        if ($label[0] === '-' || $label[strlen($label) - 1] === '-') {
            return false;
        }
    }

    return true;
}

/**
 * Prüft, ob ein identischer DNS-Record in der Datenbank bereits existiert.
 *
 * Ein Record gilt als identisch, wenn alle vier Schlüsselattribute übereinstimmen:
 * - zone_id
 * - name
 * - type
 * - content
 *
 * @param PDO    $pdo      Datenbankverbindung
 * @param int    $zone_id  Zonenkontext
 * @param string $name     Name des DNS-Eintrags
 * @param string $type     Record-Typ (z. B. A, MX, SRV, ...)
 * @param string $content  Inhalt des Eintrags
 *
 * @return bool true = bereits vorhanden, false = eindeutig
 */
function isDuplicateRecord(PDO $pdo, int $zone_id, string $name, string $type, string $content): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM records
        WHERE zone_id = ? AND name = ? AND type = ? AND content = ?
    ");
    $stmt->execute([$zone_id, $name, strtoupper($type), $content]);

    return ((int)$stmt->fetchColumn()) > 0;
}

