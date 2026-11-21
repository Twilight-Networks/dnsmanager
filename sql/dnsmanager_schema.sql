CREATE DATABASE IF NOT EXISTS dnsmanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dnsmanager;

-- Benutzerverwaltung
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'zoneadmin') NOT NULL DEFAULT 'zoneadmin',
    -- Kontostatus (S27): 0 = deaktiviert (keine Anmeldung, keine Service-Operationen; Daten bleiben erhalten)
    active TINYINT(1) NOT NULL DEFAULT 1,
    -- Sitzungsgeneration (S26/S27): steigt bei Passwort-, Rollen-, Namens- und Statusänderung;
    -- Sessions mit abweichender Generation werden widerrufen
    session_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- DNS-Zonen
CREATE TABLE zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    type ENUM('forward', 'reverse') NOT NULL,
    ttl INT DEFAULT 3600,
    prefix_length TINYINT DEFAULT NULL,
    description TEXT DEFAULT NULL,

    -- SOA Felder
    soa_ns VARCHAR(255) NOT NULL,
    soa_mail VARCHAR(255) NOT NULL,
    soa_refresh INT NOT NULL DEFAULT 3600,
    soa_retry INT NOT NULL DEFAULT 1800,
    soa_expire INT NOT NULL DEFAULT 1209600,
    soa_minimum INT NOT NULL DEFAULT 86400,
    soa_serial INT UNSIGNED NOT NULL DEFAULT 2025010101,

    -- Änderungs Flag
    changed TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- DynDNS-Option
    allow_dyndns TINYINT(1) NOT NULL DEFAULT 0
);

-- Tabelle: servers
CREATE TABLE IF NOT EXISTS servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    dns_ip4 VARCHAR(45) DEFAULT NULL,
    dns_ip6 VARCHAR(45) DEFAULT NULL,
    api_ip VARCHAR(45) DEFAULT NULL,
    api_token VARCHAR(255) NOT NULL,
    is_local BOOLEAN NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT 1,
    CHECK (is_local IN (0,1)),
    CHECK (active IN (0,1))
);

-- DNS-Records
CREATE TABLE records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    content TEXT NOT NULL,
    ttl INT DEFAULT 3600,
    server_id INT NULL,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- Zonen-Zuweisung für Benutzer (nur für 'zoneadmin')
CREATE TABLE user_zones (
    user_id INT NOT NULL,
    zone_id INT NOT NULL,
    PRIMARY KEY (user_id, zone_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
);

-- Systemstatus (für Publish-Trigger)
CREATE TABLE system_status (
    id INT PRIMARY KEY CHECK (id = 1),
    bind_dirty BOOLEAN NOT NULL DEFAULT 0,
    -- AS4a (Migration 007c, E74/O2): Fencing-Domäne der Installation; NULL = nicht initialisiert (AS4b: Admin-CLI)
    fence_domain CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    CONSTRAINT chk_ss_fence_domain CHECK (
        fence_domain IS NULL
        OR (fence_domain IS NOT NULL AND fence_domain REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
    )
);

-- Tabelle: zone_servers (Zuordnung Zone ↔ Server)
CREATE TABLE IF NOT EXISTS zone_servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    server_id INT NOT NULL,
    is_master BOOLEAN NOT NULL DEFAULT 0,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE(zone_id, server_id),
    CHECK (is_master IN (0,1))
);

-- DynDNS-Accounts
CREATE TABLE dyndns_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    zone_id INT NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    current_ipv4 VARCHAR(45) DEFAULT NULL,
    current_ipv6 VARCHAR(45) DEFAULT NULL,
    last_update DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_dyndns_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    INDEX idx_zone_hostname (zone_id, hostname)
);

-- Optionales Logging von Deployments
CREATE TABLE IF NOT EXISTS deployments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    server_id INT NOT NULL,
    status ENUM('success', 'failed') NOT NULL,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES zones(id),
    FOREIGN KEY (server_id) REFERENCES servers(id)
);

-- Tabelle: diagnostics (Letzter bekannter Prüfstatus pro Zielobjekt und Server)
CREATE TABLE IF NOT EXISTS diagnostics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_type ENUM('server', 'zone') NOT NULL,
    target_id INT NOT NULL,
    check_type ENUM('zone_status', 'zone_conf_status', 'server_status') NOT NULL,
    status ENUM('ok', 'warning', 'error', 'not_found') NOT NULL,
    message TEXT,
    checked_at DATETIME NOT NULL,
    server_id INT DEFAULT NULL,
    UNIQUE KEY unique_check (target_type, target_id, check_type, server_id)
);

-- Tabelle: diagnostic_log (Verlauf protokollierter Statusänderungen)
CREATE TABLE IF NOT EXISTS diagnostic_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    diagnostic_id INT NOT NULL,
    old_status ENUM('ok', 'warning', 'error', 'not_found') NOT NULL,
    new_status ENUM('ok', 'warning', 'error', 'not_found') NOT NULL,
    changed_at DATETIME NOT NULL,
    message TEXT,
    server_id INT DEFAULT NULL,
    notified BOOLEAN NOT NULL DEFAULT 0,
    FOREIGN KEY (diagnostic_id) REFERENCES diagnostics(id) ON DELETE CASCADE
);

-- Änderungshistorie (AP1/H2a, Migration 002; docs/ARCHITECTURE_HISTORY.md). Definition identisch mit
-- sql/migrations/002_history_foundation.sql und der Erwartung in web/inc/app/SchemaCheck.php.
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

-- Zonenübergreifende Abhängigkeiten der Historie (Migration 003, AP1/H2b)
CREATE TABLE IF NOT EXISTS history_dependencies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    change_set_id BIGINT UNSIGNED NOT NULL,
    zone_id INT NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    record_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    record_type VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_content TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    dep_change_set_id BIGINT UNSIGNED NOT NULL,
    dep_zone_id INT NOT NULL,
    dep_revision_no INT UNSIGNED NOT NULL,
    dep_record_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    dep_record_type VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dep_record_content TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    kind ENUM('auto_ptr') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_hd_change_set (change_set_id),
    KEY idx_hd_dep_change_set (dep_change_set_id),
    KEY idx_hd_zone_revision (zone_id, revision_no),
    KEY idx_hd_dep_zone_revision (dep_zone_id, dep_revision_no),
    CONSTRAINT fk_hd_change_set FOREIGN KEY (change_set_id) REFERENCES history_change_sets (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_hd_dep_change_set FOREIGN KEY (dep_change_set_id) REFERENCES history_change_sets (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_hd_revision FOREIGN KEY (zone_id, revision_no) REFERENCES zone_revisions (zone_id, revision_no)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_hd_dep_revision FOREIGN KEY (dep_zone_id, dep_revision_no) REFERENCES zone_revisions (zone_id, revision_no)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_hd_distinct CHECK (change_set_id <> dep_change_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fachliche Audit-Ereignisse der Historie (Migration 004, AP1/H2c)
CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    change_set_id BIGINT UNSIGNED NOT NULL,
    seq SMALLINT UNSIGNED NOT NULL,
    entity_type ENUM('zone', 'server', 'dyndns_account', 'user') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id INT NOT NULL,
    entity_label VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    zone_id INT NULL DEFAULT NULL,
    action ENUM('create', 'update', 'delete', 'reload') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    changes LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ae_change_set_seq (change_set_id, seq),
    KEY idx_ae_entity (entity_type, entity_id, change_set_id),
    KEY idx_ae_zone (zone_id, change_set_id),
    CONSTRAINT fk_ae_change_set FOREIGN KEY (change_set_id) REFERENCES history_change_sets (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_ae_seq CHECK (seq >= 1),
    CONSTRAINT chk_ae_entity_id CHECK (entity_id >= 1),
    CONSTRAINT chk_ae_label CHECK (CHAR_LENGTH(entity_label) >= 1),
    CONSTRAINT chk_ae_zone CHECK (
        (entity_type = 'zone' AND zone_id IS NOT NULL AND zone_id = entity_id)
        OR (entity_type = 'dyndns_account' AND zone_id IS NOT NULL AND zone_id >= 1)
        OR (entity_type IN ('server', 'user') AND zone_id IS NULL)
    ),
    CONSTRAINT chk_ae_action CHECK (
        (action = 'reload' AND entity_type = 'server') OR action IN ('create', 'update', 'delete')
    ),
    CONSTRAINT chk_ae_changes CHECK (
        JSON_VALID(changes) AND JSON_TYPE(changes) = 'OBJECT' AND JSON_LENGTH(changes) >= 1
        AND LENGTH(changes) <= 16384
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publish-Journal der Änderungshistorie (Migration 005, AP1/H2d)
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
    -- AS4a (Migration 007a): Agent-Ergebnisfelder je Kanal (J4a); agent_protocol = Registrierungszustand
    zone_protocol ENUM('legacy', 'as2') CHARACTER SET ascii COLLATE ascii_bin NULL,
    zone_effect ENUM('none', 'unchanged', 'applied', 'partial', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    zone_installed_serial INT UNSIGNED NULL,
    zone_installed_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    zone_reload ENUM('ok', 'failed', 'timeout', 'not_started') CHARACTER SET ascii COLLATE ascii_bin NULL,
    zone_fence_decision ENUM('accept', 'would_reject', 'reject') CHARACTER SET ascii COLLATE ascii_bin NULL,
    zone_fence_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_protocol ENUM('legacy', 'as2') CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_effect ENUM('none', 'unchanged', 'applied', 'partial', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_reload ENUM('ok', 'failed', 'timeout', 'not_started') CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_fence_decision ENUM('accept', 'would_reject', 'reject') CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_fence_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_verify_state ENUM('verified', 'serial_differs', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_verify_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_verify_file_serial INT UNSIGNED NULL,
    conf_verify_file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    conf_verify_served_serial INT UNSIGNED NULL,
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
    ),
    CONSTRAINT chk_zpt_zone_agent CHECK (
        (zone_protocol IS NULL AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL
            AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL)
        OR (zone_protocol IS NOT NULL AND zone_protocol = 'legacy' AND zone_sync IN ('ok', 'rejected', 'unknown')
            AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL
            AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL)
        OR (zone_protocol IS NOT NULL AND zone_protocol = 'as2' AND zone_sync IN ('ok', 'rejected', 'unknown')
            AND zone_effect IS NOT NULL)
    ),
    CONSTRAINT chk_zpt_zone_installed CHECK (
        (zone_installed_serial IS NULL AND zone_installed_hash IS NULL)
        OR (zone_installed_serial IS NOT NULL AND zone_installed_hash IS NOT NULL
            AND zone_installed_hash REGEXP '^[0-9a-f]{64}$'
            AND zone_protocol IS NOT NULL AND zone_protocol = 'as2'
            AND zone_effect IS NOT NULL AND zone_effect IN ('applied', 'unchanged')
            AND zone_reload IS NOT NULL
            AND ((zone_sync = 'ok' AND zone_reload = 'ok')
                OR (zone_sync = 'unknown' AND zone_reload IN ('failed', 'timeout', 'not_started'))))
    ),
    CONSTRAINT chk_zpt_zone_fence CHECK (
        (zone_fence_decision IS NULL AND zone_fence_code IS NULL)
        OR (zone_fence_decision IS NOT NULL AND zone_fence_code IS NULL)
        OR (zone_fence_decision IS NOT NULL AND zone_fence_code IS NOT NULL AND zone_fence_code REGEXP '^[a-z_]{1,32}$')
    ),
    CONSTRAINT chk_zpt_conf_agent CHECK (
        (conf_protocol IS NULL AND conf_effect IS NULL AND conf_reload IS NULL AND conf_fence_decision IS NULL
            AND conf_fence_code IS NULL AND conf_verify_state IS NULL)
        OR (conf_protocol IS NOT NULL AND conf_protocol = 'legacy' AND conf_sync IN ('ok', 'rejected', 'unknown')
            AND conf_effect IS NULL AND conf_reload IS NULL AND conf_fence_decision IS NULL AND conf_fence_code IS NULL
            AND conf_verify_state IS NULL)
        OR (conf_protocol IS NOT NULL AND conf_protocol = 'as2' AND conf_sync IN ('ok', 'rejected', 'unknown')
            AND conf_effect IS NOT NULL)
    ),
    CONSTRAINT chk_zpt_conf_fence CHECK (
        (conf_fence_decision IS NULL AND conf_fence_code IS NULL)
        OR (conf_fence_decision IS NOT NULL AND conf_fence_code IS NULL)
        OR (conf_fence_decision IS NOT NULL AND conf_fence_code IS NOT NULL AND conf_fence_code REGEXP '^[a-z_]{1,32}$')
    ),
    CONSTRAINT chk_zpt_conf_verify CHECK (
        (conf_verify_state IS NULL AND conf_verify_reason IS NULL AND conf_verify_file_serial IS NULL
            AND conf_verify_file_hash IS NULL AND conf_verify_served_serial IS NULL)
        OR (conf_verify_state IS NOT NULL AND conf_verify_state = 'verified' AND conf_sync = 'ok'
            AND conf_verify_file_serial IS NOT NULL AND conf_verify_served_serial IS NOT NULL
            AND conf_verify_file_serial = conf_verify_served_serial AND conf_verify_file_hash IS NOT NULL)
        OR (conf_verify_state IS NOT NULL AND conf_verify_state = 'serial_differs'
            AND conf_verify_file_serial IS NOT NULL AND conf_verify_served_serial IS NOT NULL
            AND conf_verify_file_serial <> conf_verify_served_serial)
        OR (conf_verify_state IS NOT NULL AND conf_verify_state = 'unknown')
    ),
    CONSTRAINT chk_zpt_conf_verify_fmt CHECK (
        (conf_verify_reason IS NULL OR (conf_verify_reason IS NOT NULL AND conf_verify_reason REGEXP '^[a-z0-9_:]{1,64}$'))
        AND (conf_verify_file_hash IS NULL OR (conf_verify_file_hash IS NOT NULL AND conf_verify_file_hash REGEXP '^[0-9a-f]{64}$'))
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
    -- AS4a (Migration 007b): Agent-Ergebnisfelder (J4a, nur Zähler Q5)
    cleanup_protocol ENUM('legacy', 'as2') CHARACTER SET ascii COLLATE ascii_bin NULL,
    cleanup_effect ENUM('none', 'unchanged', 'applied', 'partial', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    fence_decision ENUM('accept', 'would_reject', 'reject') CHARACTER SET ascii COLLATE ascii_bin NULL,
    fence_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    served_absent_count INT UNSIGNED NULL,
    served_still_count INT UNSIGNED NULL,
    served_unknown_count INT UNSIGNED NULL,
    unassigned_count INT UNSIGNED NULL,
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
    CONSTRAINT chk_pcr_http CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599),
    CONSTRAINT chk_pcr_agent CHECK (
        (cleanup_protocol IS NULL AND cleanup_effect IS NULL AND fence_decision IS NULL AND fence_code IS NULL)
        OR (cleanup_protocol IS NOT NULL AND cleanup_protocol = 'legacy' AND status IN ('ok', 'partial', 'rejected', 'unknown')
            AND cleanup_effect IS NULL AND fence_decision IS NULL AND fence_code IS NULL)
        OR (cleanup_protocol IS NOT NULL AND cleanup_protocol = 'as2' AND status IN ('ok', 'partial', 'rejected', 'unknown')
            AND cleanup_effect IS NOT NULL)
    ),
    CONSTRAINT chk_pcr_fence CHECK (
        (fence_decision IS NULL AND fence_code IS NULL)
        OR (fence_decision IS NOT NULL AND fence_code IS NULL)
        OR (fence_decision IS NOT NULL AND fence_code IS NOT NULL AND fence_code REGEXP '^[a-z_]{1,32}$')
    ),
    CONSTRAINT chk_pcr_served CHECK (
        (served_absent_count IS NULL AND served_still_count IS NULL AND served_unknown_count IS NULL AND unassigned_count IS NULL)
        OR (served_absent_count IS NOT NULL AND served_still_count IS NOT NULL AND served_unknown_count IS NOT NULL
            AND unassigned_count IS NOT NULL AND cleanup_protocol IS NOT NULL AND cleanup_protocol = 'as2')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ausgelieferter Stand je Zone und Server (Migration 006, AS3, E87); retentionsfest, ohne Fremdschlüssel
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
    CONSTRAINT chk_zsv_last_state CHECK (
        (last_state = 'verified' AND last_served_serial IS NOT NULL AND last_file_serial IS NOT NULL
            AND last_file_hash IS NOT NULL AND last_served_serial = last_file_serial)
        OR (last_state = 'serial_differs' AND last_served_serial IS NOT NULL AND last_file_serial IS NOT NULL
            AND last_served_serial <> last_file_serial)
        OR (last_state = 'still_served' AND last_served_serial IS NOT NULL)
        OR (last_state = 'absent' AND last_served_serial IS NULL)
        OR last_state = 'unknown'
    ),
    CONSTRAINT chk_zsv_verified_bundle CHECK (
        (verified_serial IS NULL AND verified_file_hash IS NULL AND verified_at IS NULL AND verified_run_id IS NULL)
        OR (verified_serial IS NOT NULL AND verified_file_hash IS NOT NULL AND verified_at IS NOT NULL)
    ),
    CONSTRAINT chk_zsv_verified_order CHECK (verified_at IS NULL OR verified_at <= last_observed_at),
    CONSTRAINT chk_zsv_verified_current CHECK (
        last_state <> 'verified'
        OR (verified_serial IS NOT NULL AND verified_file_hash IS NOT NULL AND verified_at IS NOT NULL
            AND last_served_serial IS NOT NULL AND last_file_hash IS NOT NULL
            AND verified_serial = last_served_serial AND verified_file_hash = last_file_hash AND verified_at = last_observed_at)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialstatus
INSERT INTO system_status (id, bind_dirty) VALUES (1, 0);

-- Standard-Admin-Benutzer (Passwort: admin123)
INSERT INTO users (username, password_hash, role)
VALUES (
    'admin',
    '$2y$12$xZkX5ao/KMZHdcyN2ovv9OkbpQdCz4KDFRnY8bVNXHgjzHXE9hT8q',
    'admin'
);
