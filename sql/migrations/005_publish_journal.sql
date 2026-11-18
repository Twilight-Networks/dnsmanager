-- Migration 005: Publish-Journal der Änderungshistorie (AP1/H2d, docs/ARCHITECTURE_HISTORY.md Abschnitt 5d, E55–E73)
--
-- Zweck: Das Journal hält fest, welcher Zonenstand in welchem Publish-Lauf erzeugt, an welche Zielserver übertragen
-- und mit welchem Ergebnis vom Agenten beantwortet wurde:
--   publish_runs             je Publish All bzw. Publish Zone unter dem Advisory-Lock (ID = künftiger Fencing-Token, AS)
--   zone_publish_attempts    je erzeugter Zonendatei: Revision, content_hash (zs1), render_hash (rh1), file_hash, Serial
--   zone_publish_targets     je Versuch und Zielserver: Ergebnis von zone_sync und conf_sync
--   publish_cleanup_results  je Lauf und aktivem Server: Ergebnis des Orphan-Cleanups (nur Zähler)
-- Registrierungsdaten sind unveränderlich; Lebenszyklusfelder wechseln genau einmal vom offenen in einen Endzustand
-- (J4a). Alle Bestätigungen sind vor AS "legacy" (keine Agent-Serial, kein Agent-Hash, kein Reload-Nachweis).
--
-- Voraussetzung: Migration 002 (zone_revisions für den Revisionsbezug der Versuche). Fremdschlüssel nur innerhalb von
-- Historie und Journal (RESTRICT), keine auf Fachtabellen, keine Kaskaden; append-only (E8). Bestehende Tabellen werden
-- nicht verändert; die ungenutzte Tabelle deployments bleibt unberührt.
--
-- CHECK-Bedingungen und NULL: MariaDB weist eine Zeile nur zurück, wenn die Bedingung FALSE ergibt; UNKNOWN (NULL)
-- gilt als erfüllt. Jede Bedingung ist so formuliert, dass sie für jede Zeile TRUE oder FALSE ergibt.
--
-- Reihenfolge beim Update: ZUERST diese Migration, DANN der Code (SchemaCheck ist fail-closed).
--   - Alter Code + neues Schema: lauffähig (die Tabellen werden nicht gelesen oder geschrieben).
--   - Neuer Code + altes Schema: Login und geschützte Seiten antworten mit HTTP 503; Publish und DynDNS-Publish enden
--     ohne Seiteneffekt mit dem Status exception (Laufregistrierung J1 scheitert).
--
-- Wiederholbar: CREATE TABLE IF NOT EXISTS, atomares DDL je Tabelle. Empfohlen über
-- scripts/migrate_005_publish_journal.sh (Preflight, Sicherung, Nachprüfung), siehe sql/migrations/README.md.
-- Identisch mit sql/dnsmanager_schema.sql und der Erwartung in web/inc/app/SchemaCheck.php.

CREATE TABLE IF NOT EXISTS publish_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('all', 'zone') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_zone_id INT NULL DEFAULT NULL,
    full_rebuild TINYINT(1) NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    history_watermark BIGINT UNSIGNED NOT NULL,
    actor_type ENUM('user', 'dyndns_account', 'system') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id INT NULL DEFAULT NULL,
    actor_dyndns_account_id INT NULL DEFAULT NULL,
    channel VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    correlation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status ENUM('running', 'finished', 'abandoned') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
    result VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    finished_at DATETIME(6) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_pr_status (status, id),
    KEY idx_pr_started (started_at),
    KEY idx_pr_correlation (correlation_id),
    CONSTRAINT chk_pr_kind CHECK (
        (kind = 'all' AND requested_zone_id IS NULL AND full_rebuild IN (0, 1))
        OR (kind = 'zone' AND requested_zone_id IS NOT NULL AND requested_zone_id >= 1 AND full_rebuild = 0)
    ),
    CONSTRAINT chk_pr_actor CHECK (
        (actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_dyndns_account_id IS NULL)
        OR (actor_type = 'dyndns_account' AND actor_dyndns_account_id IS NOT NULL AND actor_user_id IS NULL)
        OR (actor_type = 'system' AND actor_user_id IS NULL AND actor_dyndns_account_id IS NULL)
    ),
    CONSTRAINT chk_pr_channel CHECK (channel REGEXP '^[a-z][a-z0-9_]{0,15}$'),
    CONSTRAINT chk_pr_correlation CHECK (correlation_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_pr_lifecycle CHECK (
        (status = 'running' AND finished_at IS NULL AND result IS NULL)
        OR (status = 'finished' AND finished_at IS NOT NULL AND result IS NOT NULL AND result REGEXP '^[a-z_]{1,32}$')
        OR (status = 'abandoned' AND finished_at IS NOT NULL AND result IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zone_publish_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    zone_id INT NOT NULL,
    zone_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    revision_no INT UNSIGNED NULL DEFAULT NULL,
    revision_basis ENUM('head', 'head_mismatch', 'no_head') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_contract VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    render_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    render_contract VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    soa_serial INT UNSIGNED NOT NULL,
    target_count SMALLINT UNSIGNED NOT NULL,
    generated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    outcome ENUM('published', 'partial', 'failed', 'abandoned') CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    finished_at DATETIME(6) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zpa_run_zone (run_id, zone_id),
    KEY idx_zpa_zone (zone_id, id),
    KEY idx_zpa_revision (zone_id, revision_no, content_hash),
    CONSTRAINT fk_zpa_run FOREIGN KEY (run_id) REFERENCES publish_runs (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_zpa_revision FOREIGN KEY (zone_id, revision_no, content_hash)
        REFERENCES zone_revisions (zone_id, revision_no, content_hash) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_zpa_zone CHECK (zone_id >= 1 AND CHAR_LENGTH(zone_name) >= 1),
    CONSTRAINT chk_zpa_revision CHECK (
        (revision_basis = 'head' AND revision_no IS NOT NULL AND revision_no >= 1)
        OR (revision_basis IN ('head_mismatch', 'no_head') AND revision_no IS NULL)
    ),
    CONSTRAINT chk_zpa_hashes CHECK (
        content_hash REGEXP '^[0-9a-f]{64}$' AND render_hash REGEXP '^[0-9a-f]{64}$' AND file_hash REGEXP '^[0-9a-f]{64}$'
    ),
    CONSTRAINT chk_zpa_contracts CHECK (snapshot_contract = 'zs1' AND render_contract = 'rh1'),
    CONSTRAINT chk_zpa_lifecycle CHECK (
        (outcome IS NULL AND finished_at IS NULL) OR (outcome IS NOT NULL AND finished_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zone_publish_targets (
    attempt_id BIGINT UNSIGNED NOT NULL,
    server_id INT NOT NULL,
    server_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    agent_protocol ENUM('legacy') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy',
    zone_sync ENUM('sending', 'ok', 'failed', 'rejected', 'unknown', 'abandoned') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sending',
    zone_sync_at DATETIME(6) NULL DEFAULT NULL,
    zone_http_status SMALLINT UNSIGNED NULL DEFAULT NULL,
    zone_error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    zone_error_detail VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    conf_sync ENUM('sending', 'ok', 'failed', 'rejected', 'unknown', 'abandoned') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sending',
    conf_sync_at DATETIME(6) NULL DEFAULT NULL,
    conf_http_status SMALLINT UNSIGNED NULL DEFAULT NULL,
    conf_error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    conf_error_detail VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    conf_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    PRIMARY KEY (attempt_id, server_id),
    KEY idx_zpt_server (server_id, attempt_id),
    CONSTRAINT fk_zpt_attempt FOREIGN KEY (attempt_id) REFERENCES zone_publish_attempts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_zpt_target CHECK (server_id >= 1 AND CHAR_LENGTH(server_name) >= 1),
    CONSTRAINT chk_zpt_zone_sync CHECK (
        (zone_sync = 'sending' AND zone_sync_at IS NULL AND zone_http_status IS NULL AND zone_error_code IS NULL AND zone_error_detail IS NULL)
        OR (zone_sync IN ('ok', 'abandoned') AND zone_sync_at IS NOT NULL AND zone_error_code IS NULL AND zone_error_detail IS NULL)
        OR (zone_sync IN ('failed', 'rejected', 'unknown') AND zone_sync_at IS NOT NULL AND zone_error_code IS NOT NULL
            AND zone_error_code REGEXP '^[a-z_]{1,32}$')
    ),
    CONSTRAINT chk_zpt_conf_sync CHECK (
        (conf_sync = 'sending' AND conf_sync_at IS NULL AND conf_http_status IS NULL AND conf_error_code IS NULL AND conf_error_detail IS NULL)
        OR (conf_sync IN ('ok', 'abandoned') AND conf_sync_at IS NOT NULL AND conf_error_code IS NULL AND conf_error_detail IS NULL)
        OR (conf_sync IN ('failed', 'rejected', 'unknown') AND conf_sync_at IS NOT NULL AND conf_error_code IS NOT NULL
            AND conf_error_code REGEXP '^[a-z_]{1,32}$')
    ),
    CONSTRAINT chk_zpt_conf_hash CHECK (conf_hash IS NULL OR conf_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_zpt_http CHECK (
        (zone_http_status IS NULL OR zone_http_status BETWEEN 100 AND 599)
        AND (conf_http_status IS NULL OR conf_http_status BETWEEN 100 AND 599)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publish_cleanup_results (
    run_id BIGINT UNSIGNED NOT NULL,
    server_id INT NOT NULL,
    server_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    agent_protocol ENUM('legacy') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy',
    registered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status ENUM('sending', 'ok', 'partial', 'failed', 'rejected', 'unknown', 'abandoned') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sending',
    finished_at DATETIME(6) NULL DEFAULT NULL,
    valid_zone_count INT UNSIGNED NULL DEFAULT NULL,
    removed_conf_count INT UNSIGNED NULL DEFAULT NULL,
    removed_zone_file_count INT UNSIGNED NULL DEFAULT NULL,
    http_status SMALLINT UNSIGNED NULL DEFAULT NULL,
    error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    error_detail VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    PRIMARY KEY (run_id, server_id),
    KEY idx_pcr_server (server_id, run_id),
    CONSTRAINT fk_pcr_run FOREIGN KEY (run_id) REFERENCES publish_runs (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_pcr_target CHECK (server_id >= 1 AND CHAR_LENGTH(server_name) >= 1),
    CONSTRAINT chk_pcr_status CHECK (
        (status = 'sending' AND finished_at IS NULL AND http_status IS NULL AND error_code IS NULL AND error_detail IS NULL
            AND removed_conf_count IS NULL AND removed_zone_file_count IS NULL)
        OR (status IN ('ok', 'abandoned') AND finished_at IS NOT NULL AND error_code IS NULL AND error_detail IS NULL)
        OR (status IN ('partial', 'failed', 'rejected', 'unknown') AND finished_at IS NOT NULL AND error_code IS NOT NULL
            AND error_code REGEXP '^[a-z_]{1,32}$')
    ),
    CONSTRAINT chk_pcr_counts CHECK (
        (status IN ('ok', 'partial') AND removed_conf_count IS NOT NULL AND removed_zone_file_count IS NOT NULL)
        OR (status NOT IN ('ok', 'partial') AND removed_conf_count IS NULL AND removed_zone_file_count IS NULL)
    ),
    CONSTRAINT chk_pcr_http CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
