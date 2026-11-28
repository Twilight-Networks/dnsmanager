-- Datei: sql/migrations/008_zone_serial_references.sql
-- Zweck: Migration 008 (AS5, D20): dauerhafte, nur anhängende Serial-Referenzereignisse je Zone × Server für die
--        RFC-1982-Serial-Strategie des Core (docs/ARCHITECTURE_HISTORY.md Abschnitt 5k). Einziger Schreiber und Leser:
--        web/inc/app/publish/SerialReferenceStore.php.
--
-- Ausführung nur über scripts/migrate_008_zone_serial_references.sh (Preflight 002–007, Sicherung, Publish-Lock in einer
-- Sitzung, Nachprüfung). Eine einzige, atomare DDL (CREATE TABLE); bestehende Tabellen bleiben unverändert (AS4b-Code
-- bleibt auf Schema 008 lauffähig, sein SchemaCheck prüft nur die eigenen Tabellen).
--
-- Quellen (source):
--   epoch        erster AS5-Schreibvorgang der Installation (run_key = Wasserstand); Grenze zur AS4-Historie
--   run          Laufmarke eines AS5-Publish-Laufs (Write-ahead vor dem ersten Agent-Aufruf; run_key = Lauf-ID)
--   intent       Write-ahead vor jedem zone_sync (Versuch × Server); ohne Abschluss ⇒ Referenzverlust
--   agent_check  gültiger serial_check-Vorher-Bericht des Agenten (Rohwerte, kein Nachweis)
--   no_report    Abschluss ohne Bericht (Legacy-Agent, keine Antwort, Ablehnung vor der Vorher-Prüfung)
--   loss         Verlustmarke (Persistenzfehler, ungültiger Bericht, nicht gespiegelte Beobachtung)
--   conf_sync, cleanup, monitoring  Beobachtungen (gleiche Werte wie zone_server_verification, mit Historie)
--   admin        administrativ erfasste Datei- (file_serial) bzw. ausgelieferte Serial (served_serial), mit Audit
-- MariaDB wertet eine CHECK-Bedingung mit Ergebnis UNKNOWN als erfüllt: Jeder Vergleich einer NULL-fähigen Spalte steht
-- hinter einem IS NOT NULL-Wächter (K1).

CREATE TABLE IF NOT EXISTS zone_serial_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('epoch', 'run', 'intent', 'agent_check', 'no_report', 'loss', 'conf_sync', 'cleanup', 'monitoring', 'admin')
        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    zone_id INT NULL DEFAULT NULL,
    zone_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    server_id INT NULL DEFAULT NULL,
    server_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    run_key BIGINT UNSIGNED NULL DEFAULT NULL,
    attempt_id BIGINT UNSIGNED NULL DEFAULT NULL,
    order_certain TINYINT(1) NOT NULL DEFAULT 1,
    obs_state ENUM('verified', 'serial_differs', 'still_served', 'absent', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    decision ENUM('accept', 'would_reject', 'reject') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    file_state ENUM('missing', 'valid', 'invalid', 'unreadable') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    file_serial INT UNSIGNED NULL DEFAULT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    config_state ENUM('yes', 'no', 'undeterminable') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    served_state ENUM('served', 'not_auth', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    served_serial INT UNSIGNED NULL DEFAULT NULL,
    evidence VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    change_set_id BIGINT UNSIGNED NULL DEFAULT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_zsr_report (attempt_id, server_id, source),
    KEY idx_zsr_pair (zone_name, server_id, id),
    KEY idx_zsr_zone (zone_id, server_id, id),
    KEY idx_zsr_source (source, run_key),
    KEY idx_zsr_change_set (change_set_id),
    CONSTRAINT fk_zsr_target FOREIGN KEY (attempt_id, server_id) REFERENCES zone_publish_targets (attempt_id, server_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_zsr_change_set FOREIGN KEY (change_set_id) REFERENCES history_change_sets (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_zsr_identity CHECK (
        (source IN ('epoch', 'run') AND zone_id IS NULL AND zone_name IS NULL AND server_id IS NULL AND server_name IS NULL
            AND run_key IS NOT NULL AND attempt_id IS NULL AND order_certain = 1)
        OR (source IN ('intent', 'agent_check', 'no_report', 'conf_sync') AND zone_id IS NOT NULL AND zone_id >= 1
            AND zone_name IS NOT NULL AND server_id IS NOT NULL AND server_id >= 1 AND run_key IS NOT NULL AND attempt_id IS NOT NULL)
        OR (source = 'loss' AND zone_id IS NOT NULL AND zone_id >= 1 AND zone_name IS NOT NULL AND server_id IS NOT NULL
            AND server_id >= 1 AND run_key IS NOT NULL)
        OR (source IN ('cleanup', 'monitoring', 'admin') AND zone_id IS NOT NULL AND zone_id >= 1 AND zone_name IS NOT NULL
            AND server_id IS NOT NULL AND server_id >= 1 AND run_key IS NOT NULL AND attempt_id IS NULL)
    ),
    CONSTRAINT chk_zsr_name CHECK (zone_name IS NULL OR zone_name REGEXP '^[a-z0-9_][a-z0-9._-]{0,252}$'),
    CONSTRAINT chk_zsr_order CHECK (order_certain IN (0, 1)),
    CONSTRAINT chk_zsr_values CHECK (
        (source IN ('epoch', 'run', 'intent') AND obs_state IS NULL AND reason IS NULL AND decision IS NULL AND code IS NULL
            AND file_state IS NULL AND file_serial IS NULL AND file_hash IS NULL AND config_state IS NULL AND served_state IS NULL
            AND served_serial IS NULL AND evidence IS NULL AND change_set_id IS NULL)
        OR (source IN ('no_report', 'loss') AND reason IS NOT NULL AND obs_state IS NULL AND decision IS NULL AND code IS NULL
            AND file_state IS NULL AND file_serial IS NULL AND file_hash IS NULL AND config_state IS NULL AND served_state IS NULL
            AND served_serial IS NULL AND evidence IS NULL AND change_set_id IS NULL)
        OR (source = 'agent_check' AND decision IS NOT NULL AND file_state IS NOT NULL AND config_state IS NOT NULL
            AND served_state IS NOT NULL AND obs_state IS NULL AND evidence IS NULL AND change_set_id IS NULL
            AND ((file_state = 'valid' AND file_serial IS NOT NULL) OR (file_state <> 'valid' AND file_serial IS NULL))
            AND ((served_state = 'served' AND served_serial IS NOT NULL) OR (served_state <> 'served' AND served_serial IS NULL))
            AND ((decision = 'accept' AND code IS NULL) OR (decision <> 'accept' AND code IS NOT NULL)))
        OR (source IN ('conf_sync', 'cleanup', 'monitoring') AND obs_state IS NOT NULL AND decision IS NULL AND code IS NULL
            AND file_state IS NULL AND config_state IS NULL AND served_state IS NULL AND evidence IS NULL AND change_set_id IS NULL)
        OR (source = 'admin' AND evidence IS NOT NULL AND change_set_id IS NOT NULL AND obs_state IS NULL AND reason IS NULL
            AND decision IS NULL AND code IS NULL AND file_state IS NULL AND file_hash IS NULL AND config_state IS NULL
            AND served_state IS NULL AND order_certain = 1
            AND ((file_serial IS NOT NULL AND served_serial IS NULL) OR (file_serial IS NULL AND served_serial IS NOT NULL)))
    ),
    CONSTRAINT chk_zsr_obs CHECK (
        obs_state IS NULL
        OR (obs_state = 'verified' AND served_serial IS NOT NULL AND file_serial IS NOT NULL AND file_hash IS NOT NULL
            AND served_serial = file_serial)
        OR (obs_state = 'serial_differs' AND served_serial IS NOT NULL AND file_serial IS NOT NULL AND served_serial <> file_serial)
        OR (obs_state = 'still_served' AND served_serial IS NOT NULL AND file_serial IS NULL AND file_hash IS NULL)
        OR (obs_state = 'absent' AND served_serial IS NULL AND file_serial IS NULL AND file_hash IS NULL)
        OR obs_state = 'unknown'
    ),
    CONSTRAINT chk_zsr_formats CHECK (
        (reason IS NULL OR reason REGEXP '^[a-z0-9_:]{1,64}$')
        AND (code IS NULL OR code REGEXP '^[a-z_]{1,32}$')
        AND (file_hash IS NULL OR file_hash REGEXP '^[0-9a-f]{64}$')
        AND (evidence IS NULL OR CHAR_LENGTH(evidence) >= 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
