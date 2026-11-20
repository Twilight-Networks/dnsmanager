-- Migration 006, Schritt a: Tabelle zone_server_verification (AS3, docs/ARCHITECTURE_HISTORY.md Abschnitt 5h, E87)
--
-- Zweck: je Zone und Server genau eine Zeile mit dem zuletzt beobachteten ausgelieferten Zustand (L3) und, getrennt
-- davon, dem letzten positiven Nachweis. Eine spätere unbekannte oder negative Beobachtung ändert nur den Teil
-- "letzte Beobachtung"; der letzte positive Nachweis bleibt erhalten (Referenzmenge der Serial-Wahl, E78/AS5).
-- AS3 legt nur die Tabelle an; Schreiber, Upsert-Regeln und Zuordnung zu Publish-Versuchen folgen mit AS4.
--
-- Retentionsfest (E87, Meilenstein R): Zeilen gelöschter Zonen und Server bleiben erhalten. Deshalb keine Fremdschlüssel
-- auf Fach- oder Publish-Tabellen; Lauf- und Versuchs-IDs werden nur als Werte gespeichert (Ordnung nach J7).
--
-- CHECK-Bedingungen und NULL: MariaDB weist eine Zeile nur zurück, wenn die Bedingung FALSE ergibt; UNKNOWN (NULL)
-- gilt als erfüllt. Jede Bedingung ist so formuliert, dass sie für jede Zeile TRUE oder FALSE ergibt (Vergleiche nur
-- nach IS NOT NULL bzw. auf NOT NULL-Spalten).
--
-- Reihenfolge beim Update: ZUERST Migration 006 (Schritte a und b), DANN der Code (SchemaCheck ist fail-closed).
-- Ausführung ausschließlich über scripts/migrate_006_zone_serial_verification.sh (Publish-Lock, Preflight unter dem
-- Lock, Sitzungsparameter, Nachprüfung), siehe sql/migrations/README.md. Identisch mit sql/dnsmanager_schema.sql und
-- der Erwartung in web/inc/app/SchemaCheck.php.

CREATE TABLE IF NOT EXISTS zone_server_verification (
    zone_id INT NOT NULL,
    server_id INT NOT NULL,
    zone_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    server_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    last_state ENUM('verified', 'serial_differs', 'still_served', 'absent', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    last_method ENUM('conf_sync', 'cleanup', 'zone_verify', 'monitoring') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_served_serial INT UNSIGNED NULL DEFAULT NULL,
    last_file_serial INT UNSIGNED NULL DEFAULT NULL,
    last_file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    last_run_id BIGINT UNSIGNED NULL DEFAULT NULL,
    last_attempt_id BIGINT UNSIGNED NULL DEFAULT NULL,
    last_observed_at DATETIME(6) NOT NULL,
    verified_serial INT UNSIGNED NULL DEFAULT NULL,
    verified_file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    verified_run_id BIGINT UNSIGNED NULL DEFAULT NULL,
    verified_at DATETIME(6) NULL DEFAULT NULL,
    PRIMARY KEY (zone_id, server_id),
    KEY idx_zsv_zone_name (zone_name),
    KEY idx_zsv_server (server_id),
    CONSTRAINT chk_zsv_target CHECK (zone_id >= 1 AND server_id >= 1 AND CHAR_LENGTH(zone_name) >= 1 AND CHAR_LENGTH(server_name) >= 1),
    CONSTRAINT chk_zsv_reason CHECK (last_reason IS NULL OR last_reason REGEXP '^[a-z0-9_:]{1,64}$'),
    CONSTRAINT chk_zsv_hashes CHECK (
        (last_file_hash IS NULL OR last_file_hash REGEXP '^[0-9a-f]{64}$')
        AND (verified_file_hash IS NULL OR verified_file_hash REGEXP '^[0-9a-f]{64}$')
    ),
    -- letzte Beobachtung: Pflichtwerte je Zustand
    CONSTRAINT chk_zsv_last_state CHECK (
        (last_state = 'verified' AND last_served_serial IS NOT NULL AND last_file_serial IS NOT NULL
            AND last_file_hash IS NOT NULL AND last_served_serial = last_file_serial)
        OR (last_state = 'serial_differs' AND last_served_serial IS NOT NULL AND last_file_serial IS NOT NULL
            AND last_served_serial <> last_file_serial)
        OR (last_state = 'still_served' AND last_served_serial IS NOT NULL)
        OR (last_state = 'absent' AND last_served_serial IS NULL)
        OR last_state = 'unknown'
    ),
    -- letzter positiver Nachweis: Serial, Dateihash und Zeitpunkt gemeinsam gesetzt oder gemeinsam NULL;
    -- verified_run_id ist optional (Nachweis ohne Publish-Lauf, z. B. Monitoring), aber nur mit einem Nachweis
    CONSTRAINT chk_zsv_verified_bundle CHECK (
        (verified_serial IS NULL AND verified_file_hash IS NULL AND verified_at IS NULL AND verified_run_id IS NULL)
        OR (verified_serial IS NOT NULL AND verified_file_hash IS NOT NULL AND verified_at IS NOT NULL)
    ),
    -- der positive Nachweis liegt nie nach der letzten Beobachtung
    CONSTRAINT chk_zsv_verified_order CHECK (verified_at IS NULL OR verified_at <= last_observed_at),
    -- ist die letzte Beobachtung ein positiver Nachweis, ist sie zugleich der letzte positive Nachweis
    CONSTRAINT chk_zsv_verified_current CHECK (
        last_state <> 'verified'
        OR (verified_serial IS NOT NULL AND verified_file_hash IS NOT NULL AND verified_at IS NOT NULL
            AND last_served_serial IS NOT NULL AND last_file_hash IS NOT NULL
            AND verified_serial = last_served_serial AND verified_file_hash = last_file_hash AND verified_at = last_observed_at)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
