<?php
/**
 * Datei: inc/app/records/RecordContentBuilder.php
 * Zweck: Setzt Typ, Name und Inhalt eines DNS-Records aus den Eingabefeldern zusammen. Maßgebliche und einzige
 *        serverseitige Zusammensetzung für Anlage, Bearbeitung und (U4-UI) Vorschau (U4-Core, Entscheidung U4-E1).
 *
 * Rein funktional: keine Datenbank, keine Session, keine Meldungstexte. Fehler werden als fachliche Codes gemeldet;
 * die Übersetzung erfolgt im Aufrufkanal (Web: inc/web/record_messages.php).
 *
 * Kontext ($context):
 * - mode: 'create' (Anlage) oder 'update' (Bearbeitung). Ohne mode gilt das bisherige Verhalten unverändert (build()
 *   ohne Kontext, nur für Altaufrufer).
 * - zoneName: Name der Zone (für Präfixe von CAA/NAPTR und die Zerlegung bestehender Records).
 * - existing: bei 'update' der gespeicherte Record (name, type, content), aus einer Leseabfrage mit Zonenberechtigung.
 *
 * Regeln (U4-Core, Typ-Matrix tests/ui/records):
 * - Bearbeitung ohne fachliche Änderung: Stimmen alle fachlichen Formularfelder mit der Zerlegung des gespeicherten
 *   Records überein (RecordContentParser), bleiben gespeicherter Name und Inhalt exakt erhalten. Reine TTL-Änderungen
 *   schreiben damit keinen Record um (auch keine Altformen wie DKIM mit s=email oder relative Ziele).
 * - Ziele (CNAME/NS/PTR bei Anlage, MX, SRV): Ein absolutes Ziel (abschließender Punkt) wird nie verlängert; ein relatives
 *   Ziel wird je Auswahl mit „.“ bzw. „.<Zone>.“ ergänzt. Bereits von den bisherigen Formularskripten
 *   zusammengesetzte Werte werden dadurch nicht ein zweites Mal ergänzt.
 * - DKIM: Anlage aus Selector, optionaler Subdomain, Schlüssel und den Parametern k (rsa|ed25519), optional h und t:
 *   "v=DKIM1; k=…[; h=…][; t=…];" "p=<höchstens 255 Bytes>" … (DNS-konforme TXT-Zeichenketten). Ein bereits als
 *   DKIM-TXT zusammengesetzter Inhalt (bisherige Formularskripte) wird unverändert übernommen, aber nach denselben Regeln
 *   geprüft (Tags, Zeichenketten ≤ 255 Bytes; U4C-R2).
 * - is_dkim wirkt nur bei Typ DKIM oder einem TXT-Inhalt mit "v=DKIM1"; ein gewöhnlicher TXT wird als TXT geprüft.
 * - SRV/URI: Name aus Dienst und Protokoll (_dienst._proto), sofern nicht bereits so angegeben.
 * - CAA/NAPTR (Bearbeitung): ein geändertes Namenspräfix (edit_name_prefix) wird übernommen.
 *
 * Rückgabe: ['type', 'name', 'content', 'validationType', 'isDkim'] oder ['errorCode'].
 */

require_once __DIR__ . '/RecordContentParser.php';

final class RecordContentBuilder
{
    public const MX_PRIORITY_INVALID      = 'RECORD_BUILD_MX_PRIORITY_INVALID';
    public const SRV_NUMBERS_INVALID      = 'RECORD_BUILD_SRV_NUMBERS_INVALID';
    public const SRV_TARGET_EMPTY         = 'RECORD_BUILD_SRV_TARGET_EMPTY';
    public const DKIM_FIELDS_EMPTY        = 'RECORD_BUILD_DKIM_FIELDS_EMPTY';
    public const DKIM_KEY_TYPE_INVALID    = 'RECORD_BUILD_DKIM_KEY_TYPE_INVALID';
    public const DKIM_HASH_INVALID        = 'RECORD_BUILD_DKIM_HASH_INVALID';
    public const DKIM_FLAGS_INVALID       = 'RECORD_BUILD_DKIM_FLAGS_INVALID';
    public const DKIM_KEY_INVALID         = 'RECORD_BUILD_DKIM_KEY_INVALID';
    public const DKIM_SELECTOR_INVALID    = 'RECORD_BUILD_DKIM_SELECTOR_INVALID';
    public const DKIM_CONTENT_INVALID     = 'RECORD_BUILD_DKIM_CONTENT_INVALID';
    public const NAPTR_NUMBERS_INVALID    = 'RECORD_BUILD_NAPTR_NUMBERS_INVALID';
    public const NAPTR_FIELDS_EMPTY       = 'RECORD_BUILD_NAPTR_FIELDS_EMPTY';

    /** Höchstlänge einer TXT-Zeichenkette in Bytes (RFC 1035, character-string) */
    public const TXT_STRING_MAX_BYTES = 255;

    /**
     * @param string $rawType Eingegebener Typ, z. B. 'MX', 'SRV', 'DKIM', 'SPF'
     * @param array<string, mixed> $fields Eingabefelder des Formulars
     * @param array{mode?: string, zoneName?: string, existing?: ?array} $context
     *
     * @return array{type: string, name: string, content: string, validationType: string, isDkim: bool}|array{errorCode: string}
     */
    public static function build(string $rawType, array $fields, array $context = []): array
    {
        $mode = $context['mode'] ?? null;
        if ($mode !== 'create' && $mode !== 'update') {
            return self::withLegacyMeta(self::buildLegacy($rawType, $fields), $rawType, $fields);
        }

        $zoneName = rtrim(trim((string)($context['zoneName'] ?? '')), '.');
        $existing = $context['existing'] ?? null;
        // Bestehender Record nur bei gleichem Typ maßgeblich (Typ ist bei der Bearbeitung nicht änderbar)
        if (!is_array($existing) || strtoupper((string)($existing['type'] ?? '')) !== strtoupper($rawType)) {
            $existing = null;
        }

        $name    = trim((string)($fields['name'] ?? ''));
        $content = trim((string)($fields['content'] ?? ''));
        if ($name === '') {
            $name = '@';
        }

        // DKIM: Typ DKIM oder ein als DKIM gekennzeichneter TXT-Inhalt
        $dkimTxt = $rawType === 'TXT' && !empty($fields['is_dkim'])
            && self::isDkimTxt($existing !== null ? (string)$existing['content'] : $content);
        if ($rawType === 'DKIM' || $dkimTxt) {
            return self::buildDkim($rawType, $fields, $mode, $existing, $zoneName, $name, $content);
        }

        $type = $rawType === 'SPF' ? 'TXT' : $rawType;
        $result = null;

        switch ($rawType) {
            case 'MX':
                $prio = trim((string)($fields['mx_priority'] ?? ''));
                if (!ctype_digit($prio)) {
                    return ['errorCode' => self::MX_PRIORITY_INVALID];
                }
                $targetMode = (string)($fields['mx_target_mode'] ?? ($fields['fqdn_mode'] ?? 'dot'));
                $target = $content === '' ? '.' : self::completeTarget($content, $targetMode);
                $result = [$name, "$prio $target"];
                break;

            case 'SRV':
                $prio   = trim((string)($fields['srv_priority'] ?? ''));
                $weight = trim((string)($fields['srv_weight'] ?? ''));
                $port   = trim((string)($fields['srv_port'] ?? ''));
                $target = trim((string)($fields['srv_target'] ?? ''));
                $tmode  = (string)($fields['srv_target_mode'] ?? 'dot');
                if (!ctype_digit($prio) || !ctype_digit($weight) || !ctype_digit($port)) {
                    return ['errorCode' => self::SRV_NUMBERS_INVALID];
                }
                // Ziel (U4C-R1): absolut bleibt unverändert, relativ wird je Modus ergänzt; leer bzw. nur „.“ wie bisher abgelehnt
                if (rtrim($target, '.') === '') {
                    return ['errorCode' => self::SRV_TARGET_EMPTY];
                }
                $fqdn = self::completeTarget($target, $tmode);
                $result = [self::serviceName($name, (string)($fields['srv_protocol'] ?? '_tcp')), "$prio $weight $port $fqdn"];
                break;

            case 'NAPTR':
                $order   = trim((string)($fields['naptr_order'] ?? ''));
                $pref    = trim((string)($fields['naptr_pref'] ?? ''));
                $flags   = trim((string)($fields['naptr_flags'] ?? ''));
                $service = trim((string)($fields['naptr_service'] ?? ''));
                $regexp  = trim((string)($fields['naptr_regexp'] ?? ''));
                $replace = trim((string)($fields['naptr_replacement'] ?? ''));
                if (!ctype_digit($order) || !ctype_digit($pref)) {
                    return ['errorCode' => self::NAPTR_NUMBERS_INVALID];
                }
                if ($flags === '' || $service === '' || $regexp === '' || $replace === '') {
                    return ['errorCode' => self::NAPTR_FIELDS_EMPTY];
                }
                $content = "$order $pref \"" . addslashes($flags) . '" "' . addslashes($service) . '" "' . addslashes($regexp) . '" '
                    . rtrim($replace, '.') . '.';
                $result = [self::prefixedName($name, $fields, $mode, $existing, 'NAPTR', $zoneName), $content];
                break;

            case 'URI':
                if (trim((string)($fields['uri_target'] ?? '')) !== '' || trim((string)($fields['uri_service'] ?? '')) !== '') {
                    $result = [
                        self::serviceName(trim((string)($fields['uri_service'] ?? '')), (string)($fields['uri_protocol'] ?? '_tcp')),
                        trim((string)($fields['uri_priority'] ?? '')) . ' ' . trim((string)($fields['uri_weight'] ?? '')) . ' "'
                            . trim((string)($fields['uri_target'] ?? '')) . '"',
                    ];
                } else {
                    $result = [$name, $content];
                }
                break;

            case 'CNAME':
            case 'NS':
            case 'PTR':
                // Bei der Anlage Ziel ergänzen (Auswahl fqdn_mode); bei der Bearbeitung wie eingegeben
                $result = [$name, $mode === 'create' && $content !== '' ? self::completeTarget($content, (string)($fields['fqdn_mode'] ?? 'dot')) : $content];
                break;

            case 'CAA':
                $result = [self::prefixedName($name, $fields, $mode, $existing, 'CAA', $zoneName), $content];
                break;

            default:
                $result = [$name, $content];
                break;
        }

        [$name, $content] = $result;

        // Bearbeitung ohne fachliche Änderung: gespeicherte Darstellung exakt erhalten
        if ($mode === 'update' && $existing !== null && self::unchanged($rawType, $fields, $name, $content, $existing, $zoneName)) {
            $name = (string)$existing['name'];
            $content = (string)$existing['content'];
        }

        return ['type' => $type, 'name' => $name, 'content' => $content, 'validationType' => $rawType, 'isDkim' => false];
    }

    /** TXT-Inhalt eines DKIM-Schlüssels ("v=DKIM1" am Beginn einer Zeichenkette) */
    public static function isDkimTxt(string $content): bool
    {
        return preg_match('/(^|\s)"\s*v=DKIM1\s*(;|")/', $content) === 1;
    }

    /**
     * Zeichenkette als DNS-konforme TXT-Zeichenketten (je höchstens 255 Bytes, in Anführungszeichen, durch
     * Leerzeichen getrennt). Nur für Inhalte ohne Anführungszeichen und Backslashes (DKIM-Schlüssel).
     */
    public static function txtStrings(string $value): string
    {
        $parts = $value === '' ? [''] : str_split($value, self::TXT_STRING_MAX_BYTES);
        return implode(' ', array_map(static fn (string $p): string => '"' . $p . '"', $parts));
    }

    /** Ziel ergänzen: absolut bleibt absolut, relativ wird mit „.“ bzw. „.<Auswahl>.“ ergänzt */
    private static function completeTarget(string $target, string $mode): string
    {
        if (str_ends_with($target, '.')) {
            return $target;
        }
        return ($mode === 'dot' || $mode === '') ? $target . '.' : $target . '.' . rtrim($mode, '.') . '.';
    }

    /** Dienstname _dienst._proto (unverändert, wenn bereits so angegeben) */
    private static function serviceName(string $name, string $proto): string
    {
        if ($name === '' || $name === '@') {
            return $name === '' ? '@' : $name;
        }
        if (preg_match('/^_?[^.]+\._[A-Za-z0-9-]+$/', $name)) {
            return $name;
        }
        $proto = '_' . ltrim(trim($proto) === '' ? '_tcp' : trim($proto), '_');
        return '_' . ltrim($name, '_') . '.' . strtolower($proto);
    }

    /** CAA/NAPTR: bei der Bearbeitung ein geändertes Präfix übernehmen, sonst den bisherigen Namen */
    private static function prefixedName(string $name, array $fields, string $mode, ?array $existing, string $type, string $zoneName): string
    {
        if ($mode !== 'update' || !array_key_exists('edit_name_prefix', $fields)) {
            return $name;
        }
        $current = $existing !== null ? (string)$existing['name'] : $name;
        $prefix = trim((string)$fields['edit_name_prefix']);
        $currentPrefix = RecordContentParser::parse($type, $current, '', false, $zoneName)['caaPrefix'];
        return ($prefix !== '' && $prefix !== $currentPrefix) ? $prefix : $current;
    }

    /**
     * Fachliche Formularwerte gegenüber der Zerlegung des gespeicherten Records unverändert?
     */
    private static function unchanged(string $type, array $fields, string $name, string $content, array $existing, string $zoneName): bool
    {
        $exName = (string)$existing['name'];
        $exContent = (string)$existing['content'];
        $p = RecordContentParser::parse($type, $exName, $exContent, false, $zoneName);
        $f = static fn (string $k): string => trim((string)($fields[$k] ?? ''));

        switch ($type) {
            case 'MX':
                return $f('mx_priority') === $p['mxPriority'] && rtrim($f('content'), '.') === $p['content'] && $name === $exName;
            case 'SRV':
                $service = preg_replace('/\._[A-Za-z0-9-]+$/', '', $f('name'));
                return $p['srvPriority'] !== '' && $f('srv_priority') === $p['srvPriority'] && $f('srv_weight') === $p['srvWeight']
                    && $f('srv_port') === $p['srvPort'] && $f('srv_target') === $p['srvTarget']
                    && strtolower(ltrim($f('srv_protocol'), '_')) === $p['srvProto'] && $service === $p['name'];
            case 'NAPTR':
                return $p['naptrOrder'] !== '' && $f('naptr_order') === $p['naptrOrder'] && $f('naptr_pref') === $p['naptrPref']
                    && $f('naptr_flags') === $p['naptrFlags'] && $f('naptr_service') === $p['naptrService']
                    && $f('naptr_regexp') === $p['naptrRegexp'] && $f('naptr_replacement') === $p['naptrReplace'] && $name === $exName;
            case 'URI':
                return $p['uriPriority'] !== '' && $f('uri_service') === $p['uriService'] && $f('uri_protocol') === $p['uriProto']
                    && $f('uri_priority') === $p['uriPriority'] && $f('uri_weight') === $p['uriWeight'] && $f('uri_target') === $p['uriTarget'];
            default:
                return $name === $exName && $content === $exContent;
        }
    }

    /**
     * DKIM: Anlage aus den Feldern; bereits zusammengesetzter TXT-Inhalt (bisherige Formularskripte) unverändert;
     * Bearbeitung ohne Änderung der DKIM-Felder erhält den gespeicherten Inhalt exakt.
     */
    private static function buildDkim(string $rawType, array $fields, string $mode, ?array $existing, string $zoneName, string $name, string $content): array
    {
        $meta = ['type' => 'TXT', 'validationType' => 'DKIM', 'isDkim' => true];
        $hasFields = array_key_exists('dkim_selector', $fields) && array_key_exists('dkim_key', $fields);

        if ($mode === 'update' && $existing !== null && $hasFields) {
            $p = RecordContentParser::parse('TXT', (string)$existing['name'], (string)$existing['content'], true, $zoneName);
            $norm = self::dkimFieldValues($fields);
            if ($norm['selector'] === $p['dkimSelector'] && $norm['subdomain'] === $p['dkimSubdomain'] && $norm['key'] === $p['dkimKey']
                && $norm['k'] === $p['dkimKeyType'] && $norm['h'] === $p['dkimHashAlgos'] && $norm['t'] === $p['dkimFlags']) {
                return $meta + ['name' => (string)$existing['name'], 'content' => (string)$existing['content']];
            }
        }

        if ($rawType === 'DKIM' || ($mode === 'update' && $hasFields)) {
            $built = self::composeDkim($fields);
            return isset($built['errorCode']) ? $built : $meta + $built;
        }

        // Unveränderter gespeicherter Inhalt (Bearbeitung ohne DKIM-Felder): exakt erhalten, auch Altformen
        if ($mode === 'update' && $existing !== null && $name === (string)$existing['name'] && $content === (string)$existing['content']) {
            return $meta + ['name' => $name, 'content' => $content];
        }

        // Bereits zusammengesetzter DKIM-TXT (z. B. bisheriges Formularskript): unverändert übernehmen, aber nach denselben
        // verbindlichen Regeln wie die Zusammensetzung aus den Feldern prüfen (U4C-R2)
        $error = self::dkimTxtError($name, $content);
        return $error !== null ? ['errorCode' => $error] : $meta + ['name' => $name, 'content' => $content];
    }

    /**
     * Prüft einen bereits zusammengesetzten DKIM-TXT-Inhalt nach den Regeln von composeDkim() (U4C-R2):
     * - Name selector._domainkey[.subdomain] aus zulässigen Namensbestandteilen;
     * - Inhalt ausschließlich aus Zeichenketten in Anführungszeichen (ohne Anführungszeichen/Backslash darin), je höchstens
     *   255 Bytes, nur durch Leerraum getrennt;
     * - Tags (verbundene Zeichenketten, getrennt durch „;“): zuerst v=DKIM1, danach nur k, h, t, p, jeweils höchstens
     *   einmal; k rsa|ed25519, h und t im zulässigen Format, p vorhanden und nur Base64.
     *
     * @return string|null Fehlercode oder null
     */
    private static function dkimTxtError(string $name, string $content): ?string
    {
        // Selector und Subdomain wie in composeDkim() (Namensbestandteile, höchstens 63 bzw. 190 Zeichen)
        $label = '[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*';
        if (!preg_match('/^(' . $label . ')\._domainkey(?:\.(' . $label . '))?$/', $name, $n)
            || strlen($n[1]) > 63 || strlen($n[2] ?? '') > 190) {
            return self::DKIM_SELECTOR_INVALID;
        }
        if (!preg_match('/^\s*"[^"\\\\]*"(?:\s+"[^"\\\\]*")*\s*$/', $content) || !preg_match_all('/"([^"]*)"/', $content, $m)) {
            return self::DKIM_CONTENT_INVALID;
        }
        foreach ($m[1] as $s) {
            if (strlen($s) > self::TXT_STRING_MAX_BYTES) {
                return self::DKIM_CONTENT_INVALID;
            }
        }
        $tags = [];
        $parts = array_values(array_filter(array_map('trim', explode(';', implode('', $m[1]))), static fn (string $p): bool => $p !== ''));
        foreach ($parts as $i => $part) {
            if (!preg_match('/^([A-Za-z]+)\s*=\s*(.*)$/s', $part, $t)) {
                return self::DKIM_CONTENT_INVALID;
            }
            $tag = strtolower($t[1]);
            if (($i === 0) !== ($tag === 'v') || isset($tags[$tag]) || !in_array($tag, ['v', 'k', 'h', 't', 'p'], true)) {
                return self::DKIM_CONTENT_INVALID;
            }
            $tags[$tag] = trim($t[2]);
        }
        if (($tags['v'] ?? '') !== 'DKIM1') {
            return self::DKIM_CONTENT_INVALID;
        }
        if (isset($tags['k']) && !in_array(strtolower($tags['k']), ['rsa', 'ed25519'], true)) {
            return self::DKIM_KEY_TYPE_INVALID;
        }
        if (isset($tags['h']) && (strlen($tags['h']) > 64 || !preg_match('/^[A-Za-z0-9]+(?::[A-Za-z0-9]+)*$/', $tags['h']))) {
            return self::DKIM_HASH_INVALID;
        }
        if (isset($tags['t']) && (strlen($tags['t']) > 16 || !preg_match('/^[A-Za-z](?::[A-Za-z])*$/', $tags['t']))) {
            return self::DKIM_FLAGS_INVALID;
        }
        if (!isset($tags['p']) || $tags['p'] === '') {
            return self::DKIM_FIELDS_EMPTY;
        }
        if (strlen($tags['p']) > 4096 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $tags['p'])) {
            return self::DKIM_KEY_INVALID;
        }
        return null;
    }

    /** Normalisierte DKIM-Formularwerte */
    private static function dkimFieldValues(array $fields): array
    {
        $key = preg_replace('/\s+/', '', (string)($fields['dkim_key'] ?? '')) ?? '';
        if (str_starts_with($key, 'p=')) {
            $key = substr($key, 2);
        }
        $k = strtolower(trim((string)($fields['dkim_key_type'] ?? '')));
        return [
            'selector'  => trim((string)($fields['dkim_selector'] ?? '')),
            'subdomain' => trim((string)($fields['dkim_subdomain'] ?? '')),
            'key'       => $key,
            'k'         => $k === '' ? 'rsa' : $k,
            'h'         => trim((string)($fields['dkim_hash_algos'] ?? '')),
            't'         => trim((string)($fields['dkim_flags'] ?? '')),
        ];
    }

    /** DKIM-Name und -Inhalt aus den Feldern, Parameter serverseitig geprüft */
    private static function composeDkim(array $fields): array
    {
        $v = self::dkimFieldValues($fields);
        if ($v['selector'] === '' || $v['key'] === '') {
            return ['errorCode' => self::DKIM_FIELDS_EMPTY];
        }
        // Selector und Subdomain: Namensbestandteile (Buchstaben, Ziffern, Bindestrich, Unterstrich, Punkte dazwischen)
        $label = '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/';
        if (strlen($v['selector']) > 63 || !preg_match($label, $v['selector'])
            || ($v['subdomain'] !== '' && (strlen($v['subdomain']) > 190 || !preg_match($label, $v['subdomain'])))) {
            return ['errorCode' => self::DKIM_SELECTOR_INVALID];
        }
        if (!in_array($v['k'], ['rsa', 'ed25519'], true)) {
            return ['errorCode' => self::DKIM_KEY_TYPE_INVALID];
        }
        if ($v['h'] !== '' && (strlen($v['h']) > 64 || !preg_match('/^[A-Za-z0-9]+(?::[A-Za-z0-9]+)*$/', $v['h']))) {
            return ['errorCode' => self::DKIM_HASH_INVALID];
        }
        if ($v['t'] !== '' && (strlen($v['t']) > 16 || !preg_match('/^[A-Za-z](?::[A-Za-z])*$/', $v['t']))) {
            return ['errorCode' => self::DKIM_FLAGS_INVALID];
        }
        // Schlüssel: nur Base64-Zeichen, keine Anführungszeichen o. Ä. (vollständige Prüfung in validateDnsRecord('DKIM'))
        if (strlen($v['key']) > 4096 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $v['key'])) {
            return ['errorCode' => self::DKIM_KEY_INVALID];
        }

        $params = ['v=DKIM1', 'k=' . $v['k']];
        if ($v['h'] !== '') {
            $params[] = 'h=' . $v['h'];
        }
        if ($v['t'] !== '') {
            $params[] = 't=' . $v['t'];
        }
        $name = $v['selector'] . '._domainkey' . ($v['subdomain'] !== '' ? '.' . $v['subdomain'] : '');
        $content = '"' . implode('; ', $params) . ';" ' . self::txtStrings('p=' . $v['key']);

        return ['name' => $name, 'content' => $content];
    }

    /** Bisherige Rückgabe um die Prüfangaben ergänzen (Altaufrufer ohne Kontext) */
    private static function withLegacyMeta(array $result, string $rawType, array $fields): array
    {
        if (isset($result['errorCode'])) {
            return $result;
        }
        $isDkim = !empty($fields['is_dkim']);
        return $result + ['validationType' => ($rawType === 'TXT' && $isDkim) ? 'DKIM' : $rawType, 'isDkim' => $isDkim];
    }

    /**
     * Bisheriges Verhalten (vor U4-Core) für Aufrufer ohne Kontext; unverändert übernommen.
     *
     * @return array{type: string, name: string, content: string}|array{errorCode: string}
     */
    private static function buildLegacy(string $rawType, array $fields): array
    {
        $name      = trim($fields['name'] ?? '');
        $content   = trim($fields['content'] ?? '');
        $fqdn_mode = $fields['fqdn_mode'] ?? 'dot';

        if ($name === '') {
            $name = '@';
        }
        $type = ($rawType === 'SPF') ? 'TXT' : $rawType;

        if ($rawType === 'MX') {
            $prio      = trim($fields['mx_priority'] ?? '');
            $mx_target = trim($fields['content'] ?? '');
            if (!ctype_digit($prio)) {
                return ['errorCode' => self::MX_PRIORITY_INVALID];
            }
            $fqdn = rtrim($mx_target, '.');
            $fqdn .= ($fqdn_mode === 'dot') ? '.' : '.' . rtrim($fqdn_mode, '.') . '.';
            return ['type' => $type, 'name' => $name, 'content' => "$prio $fqdn"];
        }

        if ($rawType === 'SRV') {
            $prio   = trim($fields['srv_priority'] ?? '');
            $weight = trim($fields['srv_weight'] ?? '');
            $port   = trim($fields['srv_port'] ?? '');
            $target = trim($fields['srv_target'] ?? '');
            $mode   = $fields['srv_target_mode'] ?? 'dot';
            if (!ctype_digit($prio) || !ctype_digit($weight) || !ctype_digit($port)) {
                return ['errorCode' => self::SRV_NUMBERS_INVALID];
            }
            $fqdn = rtrim($target, '.');
            $fqdn .= ($mode === 'dot') ? '.' : '.' . rtrim($mode, '.') . '.';
            if ($fqdn === '.') {
                return ['errorCode' => self::SRV_TARGET_EMPTY];
            }
            return ['type' => $type, 'name' => $name, 'content' => "$prio $weight $port $fqdn"];
        }

        if ($rawType === 'DKIM') {
            $selector = trim($fields['dkim_selector'] ?? '');
            $sub      = trim($fields['dkim_subdomain'] ?? '');
            $key      = trim($fields['dkim_key'] ?? '');
            if ($selector === '' || $key === '') {
                return ['errorCode' => self::DKIM_FIELDS_EMPTY];
            }
            $name = $selector . '._domainkey';
            if ($sub !== '') {
                $name .= '.' . $sub;
            }
            return ['type' => 'TXT', 'name' => $name, 'content' => "\"v=DKIM1; k=rsa; s=email; h=sha256; p=$key\""];
        }

        if ($rawType === 'NAPTR') {
            $order   = trim($fields['naptr_order'] ?? '');
            $pref    = trim($fields['naptr_pref'] ?? '');
            $flags   = trim($fields['naptr_flags'] ?? '');
            $service = trim($fields['naptr_service'] ?? '');
            $regexp  = trim($fields['naptr_regexp'] ?? '');
            $replace = trim($fields['naptr_replacement'] ?? '');
            if (!ctype_digit($order) || !ctype_digit($pref)) {
                return ['errorCode' => self::NAPTR_NUMBERS_INVALID];
            }
            if ($flags === '' || $service === '' || $regexp === '' || $replace === '') {
                return ['errorCode' => self::NAPTR_FIELDS_EMPTY];
            }
            $flags   = '"' . addslashes($flags) . '"';
            $service = '"' . addslashes($service) . '"';
            $regexp  = '"' . addslashes($regexp) . '"';
            $replace = rtrim($replace, '.') . '.';
            return ['type' => $type, 'name' => $name, 'content' => "$order $pref $flags $service $regexp $replace"];
        }

        return ['type' => $type, 'name' => $name, 'content' => $content];
    }
}
