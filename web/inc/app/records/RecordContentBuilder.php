<?php
/**
 * Datei: inc/app/records/RecordContentBuilder.php
 * Zweck: Setzt Typ, Name und Inhalt eines DNS-Records aus den Eingabefeldern zusammen (MX, SRV, DKIM, NAPTR,
 *        SPF → TXT). Bisher buildRecordContent() in inc/record_content_builder.php; Logik unverändert.
 *
 * Rein funktional: keine Datenbank, keine Session, keine Meldungstexte. Fehler werden als fachliche Codes
 * gemeldet; die Übersetzung in Meldungstexte erfolgt im Aufrufkanal (Web: inc/web/record_messages.php).
 */

final class RecordContentBuilder
{
    public const MX_PRIORITY_INVALID      = 'RECORD_BUILD_MX_PRIORITY_INVALID';
    public const SRV_NUMBERS_INVALID      = 'RECORD_BUILD_SRV_NUMBERS_INVALID';
    public const SRV_TARGET_EMPTY         = 'RECORD_BUILD_SRV_TARGET_EMPTY';
    public const DKIM_FIELDS_EMPTY        = 'RECORD_BUILD_DKIM_FIELDS_EMPTY';
    public const NAPTR_NUMBERS_INVALID    = 'RECORD_BUILD_NAPTR_NUMBERS_INVALID';
    public const NAPTR_FIELDS_EMPTY       = 'RECORD_BUILD_NAPTR_FIELDS_EMPTY';

    /**
     * @param string $rawType Eingegebener Typ, z. B. 'MX', 'SRV', 'DKIM', 'SPF'
     * @param array<string, mixed> $fields Eingabefelder (name, content, mx_priority, srv_*, dkim_*, naptr_*,
     *                                     fqdn_mode, srv_target_mode)
     *
     * @return array{type: string, name: string, content: string}|array{errorCode: string}
     */
    public static function build(string $rawType, array $fields): array
    {
        $name      = trim($fields['name'] ?? '');
        $content   = trim($fields['content'] ?? '');
        $fqdn_mode = $fields['fqdn_mode'] ?? 'dot';

        // Leerer Name → '@'
        if ($name === '') {
            $name = '@';
        }

        // Standardtyp (SPF wird als TXT gespeichert)
        $type = ($rawType === 'SPF') ? 'TXT' : $rawType;

        // MX
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

        // SRV
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

        // DKIM (als TXT gespeichert, Name aus Selector und optionaler Subdomain)
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

            // Standardparameter h=sha256 und s=email
            return ['type' => 'TXT', 'name' => $name, 'content' => "\"v=DKIM1; k=rsa; s=email; h=sha256; p=$key\""];
        }

        // NAPTR
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

        // Übrige Typen: keine besondere Verarbeitung
        return ['type' => $type, 'name' => $name, 'content' => $content];
    }
}
