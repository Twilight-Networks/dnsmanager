<?php
/**
 * Datei: tests/ui/fixture.php
 * Zweck: Deterministische Testdaten der UI-Golden-Basis (U0). Gibt SQL für die Vorlagendatenbank aus.
 *        Passwörter werden je Lauf zufällig erzeugt und nur in /tmp/dnsmgr-uig/creds.php (0600) abgelegt.
 *        Alle Namen und Adressen stammen aus Dokumentations-/Testbereichen (.test, 192.0.2.0/24, 2001:db8::/32).
 *
 * Inhalt:
 * - Benutzer: 1 u0admin (admin), 2 u0zone (zoneadmin, nur Zone 1), 3 u0off (admin, deaktiviert), 4 u0admin2 (admin)
 * - Server: 1 ns1.u0.test (lokal, Agent-Platzhalter 127.0.0.2:443), 2 ns2.u0.test (remote, Platzhalter 127.0.0.3:443)
 * - Zonen: 1 u0.test (Server 1 Master + 2, DynDNS erlaubt, ausstehend), 2 other.u0.test (nur Server 1),
 *          3 2.0.192.in-addr.arpa (Reverse /24, Server 1)
 * - Records inkl. Glue für ns1/ns2.u0.test (systemgeneriert, server_id gesetzt)
 * - DynDNS: 1 u0dyn in Zone 1, Host dyn
 * - Diagnosen mit festen Zeitstempeln (ok und eine Warnung)
 */
$f = '/tmp/dnsmgr-uig/creds.php';
if (!is_file($f)) {
    $c = [];
    foreach (['u0admin', 'u0zone', 'u0off', 'u0admin2', 'u0dyn'] as $u) { $c[$u] = 'U0-' . bin2hex(random_bytes(10)); }
    file_put_contents($f, '<?php return ' . var_export($c, true) . ";\n");
    chmod($f, 0600);
}
$c = require $f;
$h = fn(string $u) => addslashes(password_hash($c[$u], PASSWORD_DEFAULT));
$tok1 = str_repeat('a1', 20);
$tok2 = str_repeat('b2', 20);
echo "DELETE FROM users;
INSERT INTO users (id, username, password_hash, role, active, session_epoch) VALUES
 (1, 'u0admin', '{$h('u0admin')}', 'admin', 1, 0),
 (2, 'u0zone', '{$h('u0zone')}', 'zoneadmin', 1, 0),
 (3, 'u0off', '{$h('u0off')}', 'admin', 0, 0),
 (4, 'u0admin2', '{$h('u0admin2')}', 'admin', 1, 0);
INSERT INTO servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) VALUES
 (1, 'ns1.u0.test', '192.0.2.11', '2001:db8::11', '127.0.0.2', '$tok1', 1, 1),
 (2, 'ns2.u0.test', '192.0.2.12', NULL, '127.0.0.3', '$tok2', 0, 1);
INSERT INTO zones (id, name, type, ttl, prefix_length, description, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns) VALUES
 (1, 'u0.test', 'forward', 3600, NULL, 'Golden-Testzone', 'ns1.u0.test.', 'hostmaster.u0.test.', 3600, 900, 1209600, 300, 2026111401, 1, 1),
 (2, 'other.u0.test', 'forward', 3600, NULL, NULL, 'ns1.u0.test.', 'hostmaster.other.u0.test.', 3600, 900, 1209600, 300, 2026111401, 0, 0),
 (3, '2.0.192.in-addr.arpa', 'reverse', 3600, 24, NULL, 'ns1.u0.test.', 'hostmaster.u0.test.', 3600, 900, 1209600, 300, 2026111401, 0, 0);
INSERT INTO zone_servers (id, zone_id, server_id, is_master) VALUES (1, 1, 1, 1), (2, 1, 2, 0), (3, 2, 1, 1), (4, 3, 1, 1);
INSERT INTO user_zones (user_id, zone_id) VALUES (2, 1);
INSERT INTO records (id, zone_id, name, type, content, ttl, server_id) VALUES
 (1, 1, '@', 'NS', 'ns1.u0.test.', 3600, 1),
 (2, 1, '@', 'NS', 'ns2.u0.test.', 3600, 2),
 (3, 1, 'www', 'A', '192.0.2.80', 3600, NULL),
 (4, 1, 'www', 'AAAA', '2001:db8::80', 3600, NULL),
 (5, 1, '@', 'MX', '10 mail.u0.test.', 3600, NULL),
 (6, 1, 'mail', 'A', '192.0.2.25', 3600, NULL),
 (7, 1, '@', 'TXT', '\"v=spf1 mx -all\"', 3600, NULL),
 (8, 1, 'dyn', 'A', '192.0.2.99', 300, NULL),
 (9, 2, '@', 'NS', 'ns1.u0.test.', 3600, 1),
 (10, 2, 'app', 'A', '192.0.2.81', 3600, NULL),
 (11, 3, '@', 'NS', 'ns1.u0.test.', 3600, 1),
 (12, 3, '80', 'PTR', 'www.u0.test.', 3600, NULL),
 (13, 1, 'ns1', 'A', '192.0.2.11', 3600, 1),
 (14, 1, 'ns1', 'AAAA', '2001:db8::11', 3600, 1),
 (15, 1, 'ns2', 'A', '192.0.2.12', 3600, 2);
INSERT INTO dyndns_accounts (id, username, password_hash, zone_id, hostname, current_ipv4, current_ipv6, last_update, created_at, updated_at) VALUES
 (1, 'u0dyn', '{$h('u0dyn')}', 1, 'dyn', '192.0.2.99', NULL, '2026-11-14 08:00:00', '2026-11-14 08:00:00', '2026-11-14 08:00:00');
INSERT INTO diagnostics (id, target_type, target_id, check_type, status, message, checked_at, server_id) VALUES
 (1, 'server', 1, 'server_status', 'ok', NULL, '2026-11-14 08:00:00', 1),
 (2, 'server', 2, 'server_status', 'warning', 'Agent-Zertifikat läuft in 20 Tagen ab', '2026-11-14 08:00:00', 2),
 (3, 'server', 1, 'zone_conf_status', 'ok', NULL, '2026-11-14 08:00:00', 1),
 (4, 'server', 2, 'zone_conf_status', 'ok', NULL, '2026-11-14 08:00:00', 2),
 (5, 'zone', 1, 'zone_status', 'ok', NULL, '2026-11-14 08:00:00', 1),
 (6, 'zone', 1, 'zone_status', 'ok', NULL, '2026-11-14 08:00:00', 2),
 (7, 'zone', 2, 'zone_status', 'ok', NULL, '2026-11-14 08:00:00', 1),
 (8, 'zone', 3, 'zone_status', 'ok', NULL, '2026-11-14 08:00:00', 1);
";
