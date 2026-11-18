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
    soa_serial INT NOT NULL DEFAULT 2025010101,

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
    bind_dirty BOOLEAN NOT NULL DEFAULT 0
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

-- Initialstatus
INSERT INTO system_status (id, bind_dirty) VALUES (1, 0);

-- Standard-Admin-Benutzer (Passwort: admin123)
INSERT INTO users (username, password_hash, role)
VALUES (
    'admin',
    '$2y$12$xZkX5ao/KMZHdcyN2ovv9OkbpQdCz4KDFRnY8bVNXHgjzHXE9hT8q',
    'admin'
);
