CREATE DATABASE IF NOT EXISTS dnsmanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dnsmanager;

-- Benutzerverwaltung
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'zoneadmin') NOT NULL DEFAULT 'zoneadmin',
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

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
    FOREIGN KEY (diagnostic_id) REFERENCES diagnostics(id) ON DELETE CASCADE
);

-- Initialstatus
INSERT INTO system_status (id, bind_dirty) VALUES (1, 0);

-- Standard-Admin-Benutzer (Passwort: admin123)
INSERT INTO users (username, password_hash, role)
VALUES (
    'admin',
    '$2y$12$xZkX5ao/KMZHdcyN2ovv9OkbpQdCz4KDFRnY8bVNXHgjzHXE9hT8q',
    'admin'
);
