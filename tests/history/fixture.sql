-- Datei: tests/history/fixture.sql
-- Zweck: Testdaten der History-Tests (AP1/H2a) für isolierte Datenbanken dnsmgr_t21_*.
-- Nur Dokumentationsbereiche (.test, 192.0.2.0/24, 2001:db8::/32). Passwort-Hashes und Tokens sind Platzhalter mit
-- erkennbaren Mustern; die Tests weisen nach, dass sie in keiner History-Tabelle erscheinen.

INSERT INTO users (id, username, password_hash, role) VALUES
    (11, 'h2a_admin', '$2y$12$H2AFIXTUREHASHadminxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'admin'),
    (12, 'h2a_zoneadmin', '$2y$12$H2AFIXTUREHASHzoneadminxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'zoneadmin');

INSERT INTO servers (id, name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active) VALUES
    (1, 'ns1.h2a.test', '192.0.2.1', '2001:db8::1', '192.0.2.1', 'H2ATOKEN-server-one-0123456789abcdef', 1, 1),
    (2, 'ns2.h2a.test', '192.0.2.2', NULL, '192.0.2.2', 'H2ATOKEN-server-two-0123456789abcdef', 0, 1);

-- Zone 1: ausstehender Stand (changed = 1), Duplikat, Kleinschreibung im Typ, NULL-TTL, Unicode, Systemrecords
-- Zone 2: veröffentlicht (changed = 0)
-- Zone 3: Reverse-Zone mit prefix_length
-- Zone 5: Lücke in der ID-Folge (Zone 4 gelöscht), leere Zone ohne Records und ohne Server
INSERT INTO zones (id, name, type, ttl, prefix_length, description, soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire,
                   soa_minimum, soa_serial, changed, allow_dyndns) VALUES
    (1, 'alpha.h2a.test', 'forward', 3600, NULL, 'ausstehend', 'ns1.h2a.test.', 'hostmaster.h2a.test.', 3600, 1800, 1209600, 86400, 2026111801, 1, 1),
    (2, 'beta.h2a.test', 'forward', 7200, NULL, NULL, 'ns1.h2a.test.', 'hostmaster.h2a.test.', 3600, 1800, 1209600, 86400, 2026111705, 0, 0),
    (3, '2.0.192.in-addr.arpa', 'reverse', 3600, 24, '', 'ns1.h2a.test.', 'hostmaster.h2a.test.', 3600, 1800, 1209600, 86400, 2026111600, 0, 0),
    (5, 'empty.h2a.test', 'forward', NULL, NULL, NULL, 'ns1.h2a.test.', 'hostmaster.h2a.test.', 3600, 1800, 1209600, 86400, 2147483000, 0, 0);

INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (1, 1, 1), (1, 2, 0), (2, 1, 1), (3, 2, 1);

INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES
    (1, '@', 'NS', 'ns1.h2a.test.', 3600, 1),
    (1, '@', 'NS', 'ns2.h2a.test.', 3600, 2),
    (1, 'www', 'A', '192.0.2.10', 3600, NULL),
    (1, 'www', 'A', '192.0.2.10', 3600, NULL),
    (1, 'mail', 'mx', '10 mx.h2a.test.', NULL, NULL),
    (1, 'txt', 'TXT', '"Grüße / ÄÖÜ"', 300, NULL),
    (1, 'dyn', 'A', '192.0.2.50', 60, NULL),
    (2, '@', 'NS', 'ns1.h2a.test.', 7200, 1),
    (2, 'web', 'AAAA', '2001:db8::10', 7200, NULL),
    (3, '@', 'NS', 'ns2.h2a.test.', 3600, 2),
    (3, '10', 'PTR', 'www.alpha.h2a.test.', 3600, NULL);

INSERT INTO user_zones (user_id, zone_id) VALUES (12, 1);

INSERT INTO dyndns_accounts (username, password_hash, zone_id, hostname, current_ipv4)
    VALUES ('h2a_dyn', '$2y$12$H2AFIXTUREHASHdyndnsxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 1, 'dyn', '192.0.2.50');

UPDATE system_status SET bind_dirty = 1 WHERE id = 1;
