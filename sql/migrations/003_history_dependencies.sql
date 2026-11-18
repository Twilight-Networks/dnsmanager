-- Migration 003: zonenübergreifende Abhängigkeiten der Änderungshistorie (AP1/H2b, docs/ARCHITECTURE_HISTORY.md, E37)
--
-- Zweck: history_dependencies hält fest, dass ein Record einer Änderungsmenge (z. B. AutoPTR in der Reverse-Zone, T2)
-- von einem Record einer früheren Änderungsmenge abhängt (Forward-Record, T1). Record-Schlüssel ist der
-- Inhaltsschlüssel (name, type, content) wie gespeichert, nie die Record-ID.
--
-- Voraussetzung: Migration 002 (history_change_sets, zone_revisions). Fremdschlüssel nur innerhalb der Historie, alle
-- RESTRICT, keine auf Fachtabellen. Bestehende Tabellen werden nicht verändert.
--
-- Reihenfolge beim Update: ZUERST diese Migration, DANN der Code (SchemaCheck ist fail-closed).
--   - Alter Code + neues Schema: lauffähig (die Tabelle wird nicht gelesen oder geschrieben).
--   - Neuer Code + altes Schema: Login und geschützte Seiten antworten mit HTTP 503.
--
-- Wiederholbar: CREATE TABLE IF NOT EXISTS, atomares DDL. Empfohlen über scripts/migrate_003_history_dependencies.sh
-- (Preflight, Sicherung, Nachprüfung), siehe sql/migrations/README.md. Identisch mit sql/dnsmanager_schema.sql und
-- der Erwartung in web/inc/app/SchemaCheck.php.

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
