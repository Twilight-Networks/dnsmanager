<?php
/**
 * Datei: tests/ui/records/matrix_cases.php
 * Zweck: Fälle der Record-Typ-Matrix (U4-Core). Jeder Fall beschreibt die Eingaben eines Benutzers im bestehenden
 *        Anlage- bzw. Bearbeitungsformular als Liste von Bedienschritten:
 *        [CSS-Selektor, Wert, Feldname oder null].
 *        - Mit JS (capture.js): Schritte im echten Formular ausführen (inkl. change/input-Ereignissen), danach das
 *          tatsächlich gesendete Formular erfassen (die vorhandenen Submit-Handler laufen unverändert).
 *        - Ohne JS (matrix.php): Felder des ausgelieferten Formulars übernehmen und nur benannte Felder setzen –
 *          unbenannte Felder sendet ein Browser ohne JS nicht. 'nojs' ersetzt die Schritte, wenn ein Benutzer ohne JS
 *          sichtbar andere Felder ausfüllt (z. B. Name statt des per JS eingeblendeten Präfixfelds).
 */

// Echte öffentliche Schlüssel (mit openssl erzeugt; öffentliche Schlüssel sind keine Geheimnisse) und Grenzfälle
$k1024 = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDWjarb4FVCK/CDnuO3pyTdcxTjRYfTy0Fux9ZUBNBbhLi4+Nl7Nl3okhdxXEsXxzN3aJlKL1eCH6zhltoRxGUeBY4kzI4tBs4aDhOvqcijVpkE5QJbf+flaq9r/R6Q/QmXCK2jDpHODIsS7BV9kloKPEVx4A9X/k7mcdnZrICycQIDAQAB';
$k2048 = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtWYPzKp8o8pmgHw/BbwkXfIAwcbPO5cHPAmRDKTXp00m4OIEhSFFhxEzIuFlQ1Q+D2oTwBvVWSo4O/ftasTumqDy6cJ+NKcUIZiwteGUNVwwtj0LeoyTtYYEjjoPJ6aRZH8K5rYuiG+XQkN0Ct+7FPSThOUIMlw9wT9dXyQ5jagawN74IEeDEx3y4x9js/5TTucp+yyxihlH3g/XHnFraQa24BxkZFa86QieyY4e70mwXlVceAgUSMI1VYnqUq5nowcfosahddzZBs97IQGw/oj2V8oBR10HyoKJt2puc3z7ehRBof8S5dpKrQXZl5MPaIsnHwZssvq3F6hhwhLiGQIDAQAB';
$k4096 = 'MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA5GICGYdXSXCRFe62Od/mPYem1hVlbRIfRtt78n0TlxF4bGDiYhRSKz3H9vDcxmyUrXktakSfNhyxIDl4oa047nvTTjQhytpr/ZX5J2Ep3+5SHaKLI5eNdRVet9jMgmwwiuwMsLRmUSdMSyTi/DDnRxgoZz7dltIpwoesRyVuEyQ7XIleBtDz+3odInoPeEGu5HMhog/Q6SBBmu+Yj66LwWNc8emAiKRqAPYdKiuk3u2URnZHD93yV0MP/M3iNF/WW8/JpWT2qSaa44bXL9NYLbUpOYvRrsZZF+qd458SdaodJaaBLSm+9MpeFkg0t0T5mpY4EpXLdhp+zUMl3VZ8/Qas7VOoVYiDZy84CnUmNc60JHzERc13492p/dF28hLHvK+IiHbPWAvlHqSJTNtvWRlCFZFMpRaynqh9YvKoYImyYFM/tpP4k8tgnjdaeHkVtUmndtkaC48xUCRQVtFUVtmNhdkofcgWSUuBPrts4BFHNS7utTL/2ri7jY/mGL4rCHrejF0U++uckj3mqX8Xzx8NnVg9XMUyl7zT/0NitO6JSXoKOX3eVJz/uhLShUoFxPYHY2lpLERlwOeUh+h0W6Mk2Wlt316qMixGgsgcD3O5G1/PBZ4z/kc46eQmmH9jglu8eta4ym5exg6Qv3ju5+bMgAYSTVZlLhb5DXj+zUUCAwEAAQ==';   // mit Padding ==
$ked = 'i512/KfbacAnNRit53WGTABLWfzQr+PgMopN5RIxdHQ=';   // Ed25519, mit Padding =
$k252 = str_repeat('AAAA', 63);   // p= + 252 = 254 Zeichen: eine Zeichenkette
$k256 = str_repeat('AAAA', 64);   // p= + 256 = 258 Zeichen: 255 + 3

$add = fn(string $type, array $ops, ?array $nojs = null, int $zone = 1): array => [
    'mode' => 'add', 'zone' => $zone, 'page' => "pages/records.php?zone_id=$zone&add_new=1", 'form' => 'recordForm',
    'action' => 'actions/record_add.php', 'ops' => array_merge([['#record_type', $type, 'type']], $ops),
    'nojs' => $nojs === null ? null : array_merge([['#record_type', $type, 'type']], $nojs),
];
$edit = fn(int $id, array $ops, ?array $nojs = null, int $zone = 1): array => [
    'mode' => 'edit', 'zone' => $zone, 'record' => $id, 'page' => "pages/records.php?zone_id=$zone&edit_id=$id", 'form' => "editForm_$id",
    'action' => 'actions/record_update.php', 'ops' => $ops, 'nojs' => $nojs,
];
$ttl = fn(string $v) => ['#edit_ttl_select', $v, 'ttl'];

return [
    // ---------- Anlage ----------
    'add_a'             => $add('A', [['#input_name', 'www2', 'name'], ['#input_content', '192.0.2.10', 'content']]),
    'add_aaaa'          => $add('AAAA', [['#input_name', 'v6', 'name'], ['#input_content', '2001:db8::10', 'content']]),
    'add_cname_dot'     => $add('CNAME', [['#input_name', 'c1', 'name'], ['#input_content', 'web.other.test', 'content'], ['[name=fqdn_mode]', 'dot', 'fqdn_mode']]),
    'add_cname_zone'    => $add('CNAME', [['#input_name', 'c2', 'name'], ['#input_content', 'web', 'content'], ['[name=fqdn_mode]', 'u0.test.', 'fqdn_mode']]),
    'add_cname_abszone' => $add('CNAME', [['#input_name', 'c3', 'name'], ['#input_content', 'web.other.test.', 'content'], ['[name=fqdn_mode]', 'u0.test.', 'fqdn_mode']]),
    'add_ns'            => $add('NS', [['#input_name', 'deleg', 'name'], ['#input_content', 'ns.other.test', 'content'], ['[name=fqdn_mode]', 'dot', 'fqdn_mode']]),
    'add_mx_zone'       => $add('MX', [['#input_name', 'm1', 'name'], ['#input_mx_priority', '20', 'mx_priority'], ['#input_mx_target', 'mx1', null], ['#mx_target_mode', 'u0.test.', 'mx_target_mode']],
                            [['#input_name', 'm1', 'name'], ['#input_mx_priority', '20', 'mx_priority'], ['#input_content', 'mx1', 'content'], ['#mx_target_mode', 'u0.test.', 'mx_target_mode']]),
    'add_mx_dot'        => $add('MX', [['#input_name', 'm2', 'name'], ['#input_mx_priority', '30', 'mx_priority'], ['#input_mx_target', 'mx.other.test', null], ['#mx_target_mode', 'dot', 'mx_target_mode']],
                            [['#input_name', 'm2', 'name'], ['#input_mx_priority', '30', 'mx_priority'], ['#input_content', 'mx.other.test', 'content'], ['#mx_target_mode', 'dot', 'mx_target_mode']]),
    'add_mx_abszone'    => $add('MX', [['#input_name', 'm3', 'name'], ['#input_mx_priority', '40', 'mx_priority'], ['#input_mx_target', 'mx.other.test.', null], ['#mx_target_mode', 'u0.test.', 'mx_target_mode']],
                            [['#input_name', 'm3', 'name'], ['#input_mx_priority', '40', 'mx_priority'], ['#input_content', 'mx.other.test.', 'content'], ['#mx_target_mode', 'u0.test.', 'mx_target_mode']]),
    'add_txt'           => $add('TXT', [['#input_name', 't1', 'name'], ['#input_content', '"hello world"', 'content']]),
    'add_spf'           => $add('SPF', [['#input_name', 'spf2', 'name'], ['#input_content', '"v=spf1 -all"', 'content']]),
    'add_dkim_rsa'      => $add('DKIM', [['#dkim_selector', 's1', null], ['#dkim_key_type', 'rsa', null], ['#dkim_hash_algos', 'sha256', null], ['#dkim_key', $k2048, null]]),
    'add_dkim_ed'       => $add('DKIM', [['#dkim_selector', 's2', null], ['#dkim_subdomain', 'mail', 'dkim_subdomain'], ['#dkim_key_type', 'ed25519', null], ['#dkim_flags', 'y', null], ['#dkim_key', $ked, null]]),
    'add_dkim_252'      => $add('DKIM', [['#dkim_selector', 's3', null], ['#dkim_key', $k252, null]]),
    'add_dkim_256'      => $add('DKIM', [['#dkim_selector', 's4', null], ['#dkim_key', $k256, null]]),
    'add_dkim_4096'     => $add('DKIM', [['#dkim_selector', 's5', null], ['#dkim_hash_algos', 'sha256', null], ['#dkim_key', $k4096, null]]),
    'add_srv_zone'      => $add('SRV', [['#input_name', 'sip', 'name'], ['#srv_protocol', '_udp', 'srv_protocol'], ['#input_srv_priority', '10', 'srv_priority'], ['#srv_weight', '5', 'srv_weight'],
                            ['#srv_port', '5060', 'srv_port'], ['#srv_target', 'sip', 'srv_target'], ['#srv_target_mode', 'u0.test.', 'srv_target_mode']]),
    'add_srv_abs'       => $add('SRV', [['#input_name', '_xmpp', 'name'], ['#srv_protocol', '_tcp', 'srv_protocol'], ['#input_srv_priority', '0', 'srv_priority'], ['#srv_weight', '0', 'srv_weight'],
                            ['#srv_port', '5222', 'srv_port'], ['#srv_target', 'xmpp.other.test.', 'srv_target'], ['#srv_target_mode', 'dot', 'srv_target_mode']]),
    // U4C-R1: SRV-Ziel absolut/relativ × Zielmodus dot/Zone (zusammen mit add_srv_zone und add_srv_abs alle vier Kombinationen)
    'add_srv_abs_zone'  => $add('SRV', [['#input_name', '_ldap', 'name'], ['#srv_protocol', '_tcp', 'srv_protocol'], ['#input_srv_priority', '0', 'srv_priority'], ['#srv_weight', '0', 'srv_weight'],
                            ['#srv_port', '389', 'srv_port'], ['#srv_target', 'ldap.other.test.', 'srv_target'], ['#srv_target_mode', 'u0.test.', 'srv_target_mode']]),
    'add_srv_rel_dot'   => $add('SRV', [['#input_name', '_imap', 'name'], ['#srv_protocol', '_tcp', 'srv_protocol'], ['#input_srv_priority', '0', 'srv_priority'], ['#srv_weight', '0', 'srv_weight'],
                            ['#srv_port', '143', 'srv_port'], ['#srv_target', 'imap.other.test', 'srv_target'], ['#srv_target_mode', 'dot', 'srv_target_mode']]),
    'add_naptr'         => $add('NAPTR', [['#input_name_prefix', 'enum2', null], ['#naptr_order', '100', 'naptr_order'], ['#naptr_pref', '10', 'naptr_pref'], ['#naptr_flags', 'U', 'naptr_flags'],
                            ['#naptr_service', 'E2U+sip', 'naptr_service'], ['#naptr_regexp', '!^.*$!sip:x@u0.test!', 'naptr_regexp'], ['#naptr_replacement', '.', 'naptr_replacement']],
                            [['#input_name', 'enum2', 'name'], ['#naptr_order', '100', 'naptr_order'], ['#naptr_pref', '10', 'naptr_pref'], ['#naptr_flags', 'U', 'naptr_flags'],
                            ['#naptr_service', 'E2U+sip', 'naptr_service'], ['#naptr_regexp', '!^.*$!sip:x@u0.test!', 'naptr_regexp'], ['#naptr_replacement', '.', 'naptr_replacement']]),
    'add_caa'           => $add('CAA', [['#input_name_prefix', 'shop', null], ['#input_content', '0 issue "example.org"', 'content']],
                            [['#input_name', 'shop', 'name'], ['#input_content', '0 issue "example.org"', 'content']]),
    'add_uri'           => $add('URI', [['#uri_service', '_ftp2', 'uri_service'], ['#uri_protocol', '_tcp', 'uri_protocol'], ['#uri_priority', '10', 'uri_priority'], ['#uri_weight', '1', 'uri_weight'],
                            ['#uri_target', 'ftp://ftp.u0.test/x', 'uri_target']]),
    'add_loc'           => $add('LOC', [['#input_name', 'loc2', 'name'], ['#input_content', '52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m', 'content']]),
    'add_ptr'           => $add('PTR', [['#input_name', '10', 'name'], ['#input_content', 'host.u0.test', 'content']], null, 3),

    // ---------- Bearbeitung: nur TTL (Roundtrip) ----------
    'ttl_a'          => $edit(3, [$ttl('300')]),
    'ttl_aaaa'       => $edit(4, [$ttl('300')]),
    'ttl_mx'         => $edit(5, [$ttl('300')]),
    'ttl_mx_rel'     => $edit(112, [$ttl('300')]),
    'ttl_txt_spf'    => $edit(7, [$ttl('300')]),
    'ttl_txt'        => $edit(114, [$ttl('3600')]),
    'ttl_cname'      => $edit(101, [$ttl('300')]),
    'ttl_ns'         => $edit(102, [$ttl('300')]),
    'ttl_srv'        => $edit(103, [$ttl('300')]),
    'ttl_srv_rel'    => $edit(104, [$ttl('300')]),
    'ttl_naptr'      => $edit(105, [$ttl('300')]),
    'ttl_caa'        => $edit(106, [$ttl('300')]),
    'ttl_caa_abs'    => $edit(107, [$ttl('300')]),
    'ttl_uri'        => $edit(108, [$ttl('300')]),
    'ttl_dkim_old'   => $edit(109, [$ttl('300')]),
    'ttl_dkim_new'   => $edit(110, [$ttl('300')]),
    'ttl_dkim_ed'    => $edit(111, [$ttl('300')]),
    'ttl_loc'        => $edit(113, [$ttl('300')]),
    'ttl_glue'       => $edit(13, [$ttl('300')]),
    'ttl_ptr'        => $edit(115, [$ttl('300')], null, 3),

    // ---------- Bearbeitung: fachliche Änderung ----------
    'chg_mx_prio'    => $edit(5, [['#edit_input_mx_priority', '15', 'mx_priority']]),
    'chg_cname'      => $edit(101, [['#edit_input_content', 'mail.u0.test.', 'content']]),
    'chg_srv_port'   => $edit(103, [['[name=srv_port]', '5061', 'srv_port']]),
    'chg_srv_proto'  => $edit(103, [['#edit_srv_protocol', '_udp', 'srv_protocol']]),
    // U4C-R1: Ziel absolut bzw. relativ ändern (das Bearbeitungsformular hat keine Zielmodus-Auswahl, Modus „dot“)
    'chg_srv_target_abs' => $edit(103, [['[name=srv_target]', 'sip2.other.test.', 'srv_target']]),
    'chg_srv_target_rel' => $edit(103, [['[name=srv_target]', 'sip2', 'srv_target']]),
    'chg_naptr'      => $edit(105, [['[name=naptr_order]', '200', 'naptr_order']]),
    'chg_caa_prefix' => $edit(107, [['#edit_input_name_prefix', 'api', 'edit_name_prefix']]),
    'chg_caa'        => $edit(106, [['#edit_input_content', '0 issue "example.net"', 'content']]),
    'chg_uri'        => $edit(108, [['#edit_uri_target', 'ftp://ftp.u0.test/neu', 'uri_target']]),
    'chg_dkim_key'   => $edit(110, [['[name=dkim_key]', $k1024, 'dkim_key']]),
    'chg_dkim_hash'  => $edit(109, [['[name=dkim_hash_algos]', 'sha1:sha256', 'dkim_hash_algos']]),
    'chg_ptr'        => $edit(115, [['#edit_input_content', 'mx.u0.test.', 'content']], null, 3),
];
