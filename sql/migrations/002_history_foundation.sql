-- Migration 002: Grundtabellen der Änderungshistorie (AP1/H2a, docs/ARCHITECTURE_HISTORY.md)
--
-- Zweck (E18): vier History-Grundtabellen
--   history_change_sets  Klammer je Fachtransaktion (Akteur, Kanal, Operation)
--   zone_snapshots       inhaltsadressierte, kanonische Zonen-Snapshots (Vertrag zs1, SHA-256)
--   zone_revisions       unveränderliche Revisionen je Zone
--   zone_history_state   Head je Zone (tracked / baseline_unverified)
-- Weitere Tabellen (history_dependencies, audit_events, Publish-Journal, zone_server_verification) folgen in den
-- jeweiligen Meilensteinen. Keine Serial-Spalten (E15/E16 offen, Meilenstein AS).
--
-- Grundsätze:
--   - Keine Fremdschlüssel auf Fachtabellen (zones, users, servers, dyndns_accounts): Die Historie gelöschter Zonen
--     und Akteure bleibt erhalten. Fremdschlüssel nur innerhalb der Historie, ohne Cascade.
--   - Bestehende Tabellen werden nicht verändert.
--
-- Reihenfolge beim Update: ZUERST diese Migration, DANN den neuen Code (SchemaCheck ist fail-closed).
--   - Alter Code + neues Schema: lauffähig (die neuen Tabellen werden nicht gelesen oder geschrieben).
--   - Neuer Code + altes Schema: Login und geschützte Seiten antworten mit HTTP 503.
--
-- Wiederholbar: CREATE TABLE IF NOT EXISTS in Abhängigkeitsreihenfolge; jede Anweisung ist für sich atomar
-- (atomares DDL, MariaDB ab 10.6). Vorhandene Tabellen ändert diese Datei NICHT; ihre Struktur prüft das Skript
-- scripts/migrate_002_history_foundation.sh vor der ersten DDL-Anweisung (Abweichung ⇒ Abbruch ohne Änderung).
--
-- Empfohlen: Ausführung über das Skript (Preflight, Sicherung, Nachprüfung), siehe sql/migrations/README.md.
-- Die Definitionen sind identisch mit sql/dnsmanager_schema.sql und der Erwartung in web/inc/app/SchemaCheck.php.

CREATE TABLE IF NOT EXISTS history_change_sets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    operation VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_type ENUM('user', 'dyndns_account', 'system') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id INT NULL DEFAULT NULL,
    actor_label VARCHAR(64) NULL DEFAULT NULL,
    actor_dyndns_account_id INT NULL DEFAULT NULL,
    channel VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    correlation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_hcs_created (created_at),
    KEY idx_hcs_actor_user (actor_user_id, created_at),
    KEY idx_hcs_correlation (correlation_id),
    CONSTRAINT chk_hcs_actor CHECK (
        (actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_dyndns_account_id IS NULL)
        OR (actor_type = 'dyndns_account' AND actor_dyndns_account_id IS NOT NULL AND actor_user_id IS NULL
            AND actor_label IS NULL)
        OR (actor_type = 'system' AND actor_user_id IS NULL AND actor_dyndns_account_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zone_snapshots (
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contract VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    record_count INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (content_hash),
    CONSTRAINT chk_zs_hash CHECK (content_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_zs_payload CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zone_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_id INT NOT NULL,
    zone_name VARCHAR(255) NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    change_set_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('baseline', 'create', 'change', 'discard', 'restore', 'delete', 'drift') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_revision_no INT UNSIGNED NULL DEFAULT NULL,
    diff_summary LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zr_zone_revision (zone_id, revision_no),
    KEY idx_zr_zone_revision_hash (zone_id, revision_no, content_hash),
    KEY idx_zr_change_set (change_set_id),
    KEY idx_zr_content_hash (content_hash),
    CONSTRAINT fk_zr_change_set FOREIGN KEY (change_set_id) REFERENCES history_change_sets (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_zr_snapshot FOREIGN KEY (content_hash) REFERENCES zone_snapshots (content_hash)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_zr_revision_no CHECK (revision_no >= 1),
    CONSTRAINT chk_zr_diff_summary CHECK (diff_summary IS NULL OR JSON_VALID(diff_summary))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zone_history_state (
    zone_id INT NOT NULL,
    head_revision_no INT UNSIGNED NOT NULL,
    head_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tracking ENUM('tracked', 'baseline_unverified') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    deleted_at DATETIME(6) NULL DEFAULT NULL,
    PRIMARY KEY (zone_id),
    KEY idx_zhs_head (zone_id, head_revision_no, head_hash),
    CONSTRAINT fk_zhs_head FOREIGN KEY (zone_id, head_revision_no, head_hash)
        REFERENCES zone_revisions (zone_id, revision_no, content_hash)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
