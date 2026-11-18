-- Datei: tests/history/fixture_h2b.sql
-- Zweck: Testdaten der Recorder-Tests (AP1/H2b) für isolierte Datenbanken dnsmgr_t21_*. Gültige Zonen (named-checkzone),
-- nur Dokumentationsbereiche (.test, 192.0.2.0/24, 2001:db8::/32). Tokens sind Platzhalter mit erkennbarem Muster;
-- Passwort-Hashes setzt der Test zur Laufzeit. Die Tests weisen nach, dass beides in keiner History-Tabelle erscheint.
--
-- Zonen: 1 alpha (DynDNS, Server 1 Master + 2), 2 beta (Server 1), 3 Reverse 2.0.192.in-addr.arpa (Server 1),
--        4 gamma (DynDNS, Server 1), 6 delta (Server 2, zum Löschen); ID 5 ist eine Lücke.

INSERT INTO users (id, username, password_hash, role, active) VALUES
    (21, 'h2b_admin', 'H2BFIXTURE-PLATZHALTER', 'admin', 1),
    (22, 'h2b_zoneadmin', 'H2BFIXTURE-PLATZHALTER', 'zoneadmin', 1),
    (23, 'h2b_inaktiv', 'H2BFIXTURE-PLATZHALTER', 'admin', 0);

INSERT INTO servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) VALUES
    (1, 'ns1.h2b.test', '192.0.2.1', '2001:db8::1', '192.0.2.1', 'H2BTOKEN-eins-0123456789abcdef0123456789', 1, 1),
    (2, 'ns2.h2b.test', '192.0.2.2', NULL, '192.0.2.2', 'H2BTOKEN-zwei-0123456789abcdef0123456789', 0, 1);

INSERT INTO zones (id, name, type, ttl, prefix_length, description, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire,
                   soa_minimum, soa_serial, changed, allow_dyndns) VALUES
    (1, 'alpha.h2b.test', 'forward', 3600, NULL, 'Alpha', 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 1),
    (2, 'beta.h2b.test', 'forward', 3600, NULL, NULL, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 1, 0),
    (3, '2.0.192.in-addr.arpa', 'reverse', 3600, 24, NULL, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 0),
    (4, 'gamma.h2b.test', 'forward', 3600, NULL, NULL, 'ns1.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 1),
    (6, 'delta.h2b.test', 'forward', 3600, NULL, NULL, 'ns2.h2b.test.', 'hostmaster.h2b.test.', 3600, 1800, 1209600, 86400, 2026111801, 0, 0);

INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES
    (1, 1, 1), (1, 2, 0), (2, 1, 1), (3, 1, 1), (4, 1, 1), (6, 2, 1);

INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES
    (1, '@', 'NS', 'ns1.h2b.test.', 3600, 1),
    (1, '@', 'NS', 'ns2.h2b.test.', 3600, 2),
    (1, 'www', 'A', '192.0.2.10', 3600, NULL),
    (1, 'mail', 'MX', '10 mx.h2b.test.', 3600, NULL),
    (1, 'dyn', 'A', '192.0.2.50', 300, NULL),
    (2, '@', 'NS', 'ns1.h2b.test.', 3600, 1),
    (2, 'web', 'AAAA', '2001:db8::10', 3600, NULL),
    (2, 'alt', 'A', '192.0.2.20', 3600, NULL),
    (3, '@', 'NS', 'ns1.h2b.test.', 3600, 1),
    (3, '10', 'PTR', 'www.alpha.h2b.test.', 3600, NULL),
    (4, '@', 'NS', 'ns1.h2b.test.', 3600, 1),
    (6, '@', 'NS', 'ns2.h2b.test.', 3600, 2),
    (6, 'x', 'TXT', '"delta"', 3600, NULL);

INSERT INTO user_zones (user_id, zone_id) VALUES (22, 1);

INSERT INTO dyndns_accounts (id, username, password_hash, zone_id, hostname, current_ipv4)
    VALUES (1, 'h2b_dynkonto', 'H2BFIXTURE-PLATZHALTER', 1, 'dyn', '192.0.2.50');

UPDATE system_status SET bind_dirty = 0 WHERE id = 1;
