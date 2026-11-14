-- Datei: tests/ui/records/matrix_fixture.sql
-- Zweck: Zusätzliche Records für die Typ-Matrix (U4-Core) auf der Golden-Fixture (tests/ui/fixture.php).
--        Bestehende Inhalte in den heute tatsächlich gespeicherten Formen, einschließlich Altformen
--        (DKIM mit s=email als eine Zeichenkette, relative Ziele), für Bearbeitungs-Roundtrips.
-- Zone 1 = u0.test (forward), Zone 3 = 2.0.192.in-addr.arpa (reverse).
INSERT INTO records (id, zone_id, name, type, content, ttl, server_id) VALUES
 (101, 1, 'alias', 'CNAME', 'www.u0.test.', 3600, NULL),
 (102, 1, 'sub', 'NS', 'ns.other.test.', 3600, NULL),
 (103, 1, '_sip._tcp', 'SRV', '10 5 5060 sip.u0.test.', 3600, NULL),
 (104, 1, '_xmpp._tcp', 'SRV', '0 5 5222 xmpp', 3600, NULL),
 (105, 1, 'enum', 'NAPTR', '100 10 "U" "E2U+sip" "!^.*$!sip:info@u0.test!" .', 3600, NULL),
 (106, 1, '@', 'CAA', '0 issue "letsencrypt.org"', 3600, NULL),
 (107, 1, 'www.u0.test.', 'CAA', '0 issuewild ";"', 3600, NULL),
 (108, 1, '_ftp._tcp', 'URI', '10 1 "ftp://ftp.u0.test/pub"', 3600, NULL),
 (109, 1, 'old._domainkey', 'TXT', '"v=DKIM1; k=rsa; s=email; h=sha256; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC1oldkey"', 3600, NULL),
 (110, 1, 'new._domainkey', 'TXT', '"v=DKIM1; k=rsa; h=sha256;" "p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAx1x2x3x4x5x6x7x8x9x0aAbBcCdDeEfFgGhHiIjJkKlLmMnNoOpPqQrRsStTuUvVwWxXyYzZ0123456789x1x2x3x4x5x6x7x8x9x0aAbBcCdDeEfFgGhHiIjJkKlLmMnNoOpPqQrRsStTuUvVwWxXyYzZ0123456789x1x2x3x4x5x6x7x8x9x0aAbBcCdDeEfFgG" "hHiIjJkKlLmMnNoOpPqQrRsStTuUvVwWxXyYzZ0123456789IDAQAB"', 3600, NULL),
 (111, 1, 'ed._domainkey.sub', 'TXT', '"v=DKIM1; k=ed25519; t=y;" "p=11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo="', 3600, NULL),
 (112, 1, 'mx2', 'MX', '20 mail', 3600, NULL),
 (113, 1, 'loc', 'LOC', '52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m', 3600, NULL),
 (114, 1, 'verify', 'TXT', '"v=verify123"', 300, NULL),
 (115, 3, '81', 'PTR', 'mail.u0.test.', 3600, NULL);
