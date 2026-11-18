-- Migration 004: fachliche Audit-Ereignisse der Änderungshistorie (AP1/H2c, docs/ARCHITECTURE_HISTORY.md, E45/E46)
--
-- Zweck: audit_events hält fachlich relevante, committete Verwaltungsvorgänge fest (Zonenmetadaten, Server,
-- DynDNS-Konten, Benutzer und Berechtigungen, BIND-Reload). Jedes Ereignis gehört zu genau einer Änderungsmenge
-- (history_change_sets); ein Vorgang mit DNS-Wirkung verwendet dieselbe Änderungsmenge wie seine Revisionen.
-- "changes" enthält ausschließlich whitelist-geprüfte, geschwärzte Feldänderungen (AuditPolicy): keine Passwörter,
-- Passwort-Hashes, Tokens, DynDNS-Kontonamen oder Client-IP-Adressen; geheime Felder nur als Kennzeichen.
--
-- Voraussetzung: Migration 002 (history_change_sets). Fremdschlüssel nur auf history_change_sets (RESTRICT), keine
-- auf Fachtabellen und keine Kaskaden: Ereignisse gelöschter Entitäten bleiben erhalten (E8). Bestehende Tabellen
-- werden nicht verändert.
--
-- CHECK-Bedingungen und NULL: MariaDB weist eine Zeile nur zurück, wenn die Bedingung FALSE ergibt; UNKNOWN (NULL)
-- gilt als erfüllt. Jede Bedingung ist deshalb so formuliert, dass sie für jede Zeile TRUE oder FALSE ergibt
-- (explizite IS [NOT] NULL-Prüfungen vor Vergleichen; alle übrigen beteiligten Spalten sind NOT NULL).
--
-- Reihenfolge beim Update: ZUERST diese Migration, DANN der Code (SchemaCheck ist fail-closed).
--   - Alter Code + neues Schema: lauffähig (die Tabelle wird nicht gelesen oder geschrieben).
--   - Neuer Code + altes Schema: Login und geschützte Seiten antworten mit HTTP 503.
--
-- Wiederholbar: CREATE TABLE IF NOT EXISTS, atomares DDL. Empfohlen über scripts/migrate_004_audit_events.sh
-- (Preflight, Sicherung, Nachprüfung), siehe sql/migrations/README.md. Identisch mit sql/dnsmanager_schema.sql und
-- der Erwartung in web/inc/app/SchemaCheck.php.

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
