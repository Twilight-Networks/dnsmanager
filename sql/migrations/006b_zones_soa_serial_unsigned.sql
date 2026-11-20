-- Migration 006, Schritt b: zones.soa_serial als INT UNSIGNED (AS3, docs/ARCHITECTURE_HISTORY.md Abschnitt 5h, E79)
--
-- Zweck: voller 32-Bit-Bereich der SOA-Serial ohne Vorzeichen (0 … 4294967295), wie bereits
-- zone_publish_attempts.soa_serial. Werte, Standardwert und NOT NULL bleiben unverändert. Die Serial-Wahl
-- (generate_next_serial(), Wraparound nach RFC 1982) bleibt unverändert und folgt mit AS5.
--
-- ALGORITHM=COPY, LOCK=SHARED: Die Typänderung kopiert die Tabelle; Leser bleiben möglich, Schreiber warten bis zum
-- Ende. Die Anweisung ist atomar (MariaDB ab 10.6): entweder alter oder neuer Typ, nie ein Zwischenstand.
-- Ausführung ausschließlich über scripts/migrate_006_zone_serial_verification.sh: dieselbe Verbindung hält den
-- Publish-Lock, setzt sql_mode (strikt) und lock_wait_timeout (10 s) und prüft unmittelbar davor unter dem Lock, dass
-- die Spalte noch im Ausgangszustand ist und keine negativen Werte enthält.

ALTER TABLE zones
    MODIFY soa_serial INT UNSIGNED NOT NULL DEFAULT 2025010101,
    ALGORITHM=COPY, LOCK=SHARED;
