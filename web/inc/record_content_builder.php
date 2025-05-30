<?php
/**
 * Datei: record_content_builder.php
 * Zweck: Kapselt die Logik zur Erstellung des Content-Felds für komplexe Record-Typen.
 * Verwendung in: record_add.php, record_update.php
 *
 * Unterstützte Typen:
 * - MX
 * - SRV
 * - DKIM (Typ wird in TXT umgewandelt, Name automatisch ergänzt)
 *
 * Rückgabewert:
 * - Bei Erfolg: ['type' => ..., 'name' => ..., 'content' => ...]
 * - Bei Fehler: ['error' => ...]
 */

/**
 * Baut das Content-Feld für einen komplexen DNS-Record.
 *
 * @param string $raw_type Eingegebener Typ, z. B. 'MX', 'SRV', 'DKIM'
 * @param array $post Das $_POST-Array
 * @return array ['type' => string, 'name' => string, 'content' => string] oder ['error' => string]
 */

function buildRecordContent(string $raw_type, array $post): array
{
    $name      = trim($post['name'] ?? '');
    $content   = trim($post['content'] ?? '');
    $ttl       = intval($post['ttl'] ?? 3600);
    $fqdn_mode = $post['fqdn_mode'] ?? 'dot';

    // Wenn leerer Name übergeben wurde, dann standardmäßig auf '@' setzen
    if ($name === '') {
        $name = '@';
    }

    // Standardtyp (z. B. A, TXT, etc.)
    $type = ($raw_type === 'SPF') ? 'TXT' : $raw_type;

    // MX
    if ($raw_type === 'MX') {
        $prio      = trim($post['mx_priority'] ?? '');
        $mx_target = trim($post['content'] ?? '');

        if (!ctype_digit($prio)) {
            return ['error' => 'MX-Priorität ist ungültig.'];
        }

        $fqdn = rtrim($mx_target, '.');
        $fqdn .= ($fqdn_mode === 'dot') ? '.' : '.' . rtrim($fqdn_mode, '.') . '.';
        $content = "$prio $fqdn";

        return [
            'type'    => $type,
            'name'    => $name,
            'content' => $content
        ];
    }

    // SRV
    if ($raw_type === 'SRV') {
        $prio   = trim($post['srv_priority'] ?? '');
        $weight = trim($post['srv_weight'] ?? '');
        $port   = trim($post['srv_port'] ?? '');
        $target = trim($post['srv_target'] ?? '');
        $mode   = $post['srv_target_mode'] ?? 'dot';

        if (!ctype_digit($prio) || !ctype_digit($weight) || !ctype_digit($port)) {
            return ['error' => 'SRV-Priorität, Gewicht und Port müssen numerisch sein.'];
        }

        $fqdn = rtrim($target, '.');
        $fqdn .= ($mode === 'dot') ? '.' : '.' . rtrim($mode, '.') . '.';
        if ($fqdn === '.') {
            return ['error' => 'SRV-Target darf nicht leer sein.'];
        }

        $content = "$prio $weight $port $fqdn";

        return [
            'type'    => $type,
            'name'    => $name,
            'content' => $content
        ];
    }

    // DKIM
    if ($raw_type === 'DKIM') {
        $selector = trim($post['dkim_selector'] ?? '');
        $sub      = trim($post['dkim_subdomain'] ?? '');
        $key      = trim($post['dkim_key'] ?? '');

        if ($selector === '' || $key === '') {
            return ['error' => 'DKIM-Selector und Public Key dürfen nicht leer sein.'];
        }

        // Der Name des Records setzt sich aus dem Selector und der Domain zusammen
        $name = $selector . '._domainkey';
        if ($sub !== '') {
            $name .= '.' . $sub;
        }

        $type = 'TXT';

        // DKIM-Content zusammensetzen
        // Die zusätzlichen Parameter 'h' und 's' können nach Bedarf hinzugefügt werden, z.B. 'h=sha256; s=email;'
        $content = "\"v=DKIM1; k=rsa; s=email; h=sha256; p=$key\"";  // h=sha256; s=email sind Standardparameter, die hinzugefügt wurden

        return [
            'type'    => $type,
            'name'    => $name,
            'content' => $content
        ];
    }

    // NAPTR
    if ($raw_type === 'NAPTR') {
        $order      = trim($post['naptr_order'] ?? '');
        $pref       = trim($post['naptr_pref'] ?? '');
        $flags      = trim($post['naptr_flags'] ?? '');
        $service    = trim($post['naptr_service'] ?? '');
        $regexp     = trim($post['naptr_regexp'] ?? '');
        $replace    = trim($post['naptr_replacement'] ?? '');

        if (!ctype_digit($order) || !ctype_digit($pref)) {
            return ['error' => 'NAPTR: Order und Preference müssen numerisch sein.'];
        }

        if ($flags === '' || $service === '' || $regexp === '' || $replace === '') {
            return ['error' => 'NAPTR: Alle Felder (Flags, Service, Regexp, Replacement) müssen ausgefüllt sein.'];
        }

        $flags    = '"' . addslashes($flags) . '"';
        $service  = '"' . addslashes($service) . '"';
        $regexp   = '"' . addslashes($regexp) . '"';
        $replace  = rtrim($replace, '.') . '.';

        $content = "$order $pref $flags $service $regexp $replace";

        return [
            'type'    => $type,
            'name'    => $name,
            'content' => $content
        ];
    }

    // Default: Keine spezielle Verarbeitung
    return [
        'type'    => $type,
        'name'    => $name,
        'content' => $content
    ];
}
