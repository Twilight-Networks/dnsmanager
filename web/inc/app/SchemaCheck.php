<?php
/**
 * Datei: inc/app/SchemaCheck.php
 * Zweck: Prüfung, ob das Datenbankschema die vom Code vorausgesetzten Spalten und Tabellen in der erwarteten Form
 *        enthält (Fail-Closed vor Anmeldung und Session-Revalidierung).
 *
 * Hintergrund (S26/S27): Der Code setzt users.active und users.session_epoch voraus. Die Migration
 * (sql/migrations/001_users_active_session_epoch.sql bzw. scripts/migrate_001_users_active_session_epoch.sh)
 * muss VOR dem Code ausgerollt werden. Fehlt sie oder weicht die Spaltenstruktur ab, verweigern Login und
 * Revalidierung den Dienst, statt mit unvollständigen Sicherheitsdaten zu arbeiten.
 *
 * AP1/H2a (E25): Zusätzlich werden die History-Grundtabellen aus Migration 002
 * (sql/migrations/002_history_foundation.sql) vollständig geprüft: Tabellenart und Engine, alle Spalten (Position,
 * Typ, NULL, Standardwert, Extra, Zeichensatz, Sortierung), alle Indizes, Fremdschlüssel (Spalten, Ziel, Regeln) und
 * die CHECK-Bedingungen (Name, Ebene und normalisierter Ausdruck). Jede Abweichung, auch eine zusätzliche Spalte
 * oder ein zusätzlicher Index, ist ein Befund. Dieselbe Erwartung und dieselbe Abfrage verwendet das Migrationsskript
 * scripts/migrate_002_history_foundation.sh (über web/scripts/schema_history-cli.php) für Preflight und
 * Nachprüfung; es gibt nur diese eine Definition der Erwartung.
 *
 * AP1/H2b (E37): Ebenso history_dependencies aus Migration 003 (sql/migrations/003_history_dependencies.sql). Jedes
 * Migrationsskript bewertet nur seine eigenen Tabellen aus derselben Erwartung (002: vier Grundtabellen, 003:
 * history_dependencies).
 *
 * AP1/H2c (E46): Ebenso audit_events aus Migration 004 (sql/migrations/004_audit_events.sql); das Migrationsskript 004
 * bewertet nur audit_events (Voraussetzung: history_change_sets aus 002 unverändert).
 *
 * AP1/H2d (E55/E56): Ebenso die Tabellen des Publish-Journals aus Migration 005 (sql/migrations/005_publish_journal.sql:
 * publish_runs, zone_publish_attempts, zone_publish_targets, publish_cleanup_results); das Migrationsskript 005 bewertet
 * nur diese vier Tabellen (Voraussetzung: die Grundtabellen aus 002 unverändert).
 *
 * AS3 (E79, E87): zones.soa_serial als INT UNSIGNED (REQUIRED) und die Tabelle zone_server_verification aus Migration
 * 006 (sql/migrations/006a_zone_server_verification.sql, 006b_zones_soa_serial_unsigned.sql); das Migrationsskript 006
 * bewertet nur zone_server_verification und zones.soa_serial (Voraussetzung: Migrationen 002–005 unverändert).
 *
 * AS4b (Migration 007 verbindlich): zone_publish_targets und publish_cleanup_results nur noch mit exakt der Struktur
 * nach Migration 007, system_status.fence_domain in REQUIRED. Die AS4a-Kompatibilitätsbrücke entfällt für Login und
 * Revalidierung; der AS4a-Code bleibt der betriebliche Rückfallstand. Nur die Migrationswerkzeuge
 * (schema_history-cli.php evaluate) kennen zusätzlich den Zwischenstand vor 007 (HISTORY_TABLES_PRE007), den die
 * Migrationen 005/006 erzeugen und 007 überführt.
 *
 * AS5 (D20, Migration 008): zusätzlich zone_serial_references (sql/migrations/008_zone_serial_references.sql) strikt;
 * vor 008 ist Login und Publish fail-closed. Das Migrationsskript 008 bewertet nur diese Tabelle (Voraussetzung:
 * 002–007 unverändert). Der AS4b-Code prüft nur seine Tabellen und bleibt auf Schema 008 lauffähig.
 *
 * Es werden niemals Schemaänderungen vorgenommen (keine automatische Migration beim Seitenaufruf).
 * Das Ergebnis wird je Prozess zwischengespeichert (eine Prüfung je Anfrage).
 */

final class SchemaCheck
{
    /**
     * Erwartete Spalten: Tabelle => Spalte => [COLUMN_TYPE (klein), IS_NULLABLE, COLUMN_DEFAULT]
     */
    public const REQUIRED = [
        'users' => [
            'active'        => ['tinyint(1)', 'NO', '1'],
            'session_epoch' => ['bigint(20) unsigned', 'NO', '0'],
        ],
        // AS3 (E79): voller 32-Bit-Bereich der SOA-Serial ohne Vorzeichen
        'zones' => [
            'soa_serial' => ['int(10) unsigned', 'NO', '2025010101'],
        ],
        // AS4b (E74/O2, Migration 007c): Fencing-Domäne der Installation (NULL = nicht initialisiert)
        'system_status' => [
            'fence_domain' => ['char(36)', 'YES', 'NULL'],
        ],
    ];

    /**
     * Erwartete Struktur der History-Grundtabellen (Migration 002) als Signaturzeilen in der Reihenfolge von
     * HISTORY_SIGNATURE_SQL. Format je Zeile (Felder mit "|" getrennt, "~" = NULL):
     *   table|<TABLE_TYPE>|<ENGINE>
     *   col|<Name>|<Typ>|<IS_NULLABLE>|<Standardwert>|<Extra>|<Zeichensatz>|<Sortierung>   (nach Position)
     *   idx|<Index>|<NON_UNIQUE>|<Position>|<Spalte>|<SUB_PART>|<INDEX_TYPE>
     *   fk|<Name>|<Position>|<Spalte>|<Zieltabelle>|<Zielspalte>|<UPDATE_RULE>|<DELETE_RULE>
     *   chk|<Name>|<LEVEL>|<CHECK_CLAUSE>   (Ausdruck in der von MariaDB normalisierten Form)
     * Werte wie von MariaDB 11.8 in information_schema geliefert (erhoben auf ns1 aus der Migrationsdatei; identisch
     * für sql/dnsmanager_schema.sql, siehe tests/history).
     */
    public const HISTORY_TABLES = [
        'history_change_sets' => [
            'table|BASE TABLE|InnoDB',
            'col|id|bigint(20) unsigned|NO|~|auto_increment|~|~',
            'col|created_at|datetime(6)|NO|current_timestamp(6)||~|~',
            'col|operation|varchar(64)|NO|~||ascii|ascii_bin',
            "col|actor_type|enum('user','dyndns_account','system')|NO|~||ascii|ascii_bin",
            'col|actor_user_id|int(11)|YES|NULL||~|~',
            'col|actor_label|varchar(64)|YES|NULL||utf8mb4|utf8mb4_unicode_ci',
            'col|actor_dyndns_account_id|int(11)|YES|NULL||~|~',
            'col|channel|varchar(16)|NO|~||ascii|ascii_bin',
            'col|correlation_id|char(36)|YES|NULL||ascii|ascii_bin',
            'idx|idx_hcs_actor_user|1|1|actor_user_id|~|BTREE',
            'idx|idx_hcs_actor_user|1|2|created_at|~|BTREE',
            'idx|idx_hcs_correlation|1|1|correlation_id|~|BTREE',
            'idx|idx_hcs_created|1|1|created_at|~|BTREE',
            'idx|PRIMARY|0|1|id|~|BTREE',
            "chk|chk_hcs_actor|Table|`actor_type` = 'user' and `actor_user_id` is not null and `actor_dyndns_account_id` is null"
                . " or `actor_type` = 'dyndns_account' and `actor_dyndns_account_id` is not null and `actor_user_id` is null"
                . " and `actor_label` is null or `actor_type` = 'system' and `actor_user_id` is null and `actor_dyndns_account_id` is null",
        ],
        'zone_snapshots' => [
            'table|BASE TABLE|InnoDB',
            'col|content_hash|char(64)|NO|~||ascii|ascii_bin',
            'col|contract|varchar(16)|NO|~||ascii|ascii_bin',
            'col|payload|longtext|NO|~||utf8mb4|utf8mb4_bin',
            'col|record_count|int(10) unsigned|NO|~||~|~',
            'col|created_at|datetime(6)|NO|current_timestamp(6)||~|~',
            'idx|PRIMARY|0|1|content_hash|~|BTREE',
            "chk|chk_zs_hash|Table|`content_hash` regexp '^[0-9a-f]{64}\$'",
            'chk|chk_zs_payload|Table|json_valid(`payload`)',
        ],
        'zone_revisions' => [
            'table|BASE TABLE|InnoDB',
            'col|id|bigint(20) unsigned|NO|~|auto_increment|~|~',
            'col|zone_id|int(11)|NO|~||~|~',
            'col|zone_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            'col|revision_no|int(10) unsigned|NO|~||~|~',
            'col|change_set_id|bigint(20) unsigned|NO|~||~|~',
            "col|kind|enum('baseline','create','change','discard','restore','delete','drift')|NO|~||ascii|ascii_bin",
            'col|content_hash|char(64)|NO|~||ascii|ascii_bin',
            'col|source_revision_no|int(10) unsigned|YES|NULL||~|~',
            'col|diff_summary|longtext|YES|NULL||utf8mb4|utf8mb4_bin',
            'idx|idx_zr_change_set|1|1|change_set_id|~|BTREE',
            'idx|idx_zr_content_hash|1|1|content_hash|~|BTREE',
            'idx|idx_zr_zone_revision_hash|1|1|zone_id|~|BTREE',
            'idx|idx_zr_zone_revision_hash|1|2|revision_no|~|BTREE',
            'idx|idx_zr_zone_revision_hash|1|3|content_hash|~|BTREE',
            'idx|PRIMARY|0|1|id|~|BTREE',
            'idx|uq_zr_zone_revision|0|1|zone_id|~|BTREE',
            'idx|uq_zr_zone_revision|0|2|revision_no|~|BTREE',
            'fk|fk_zr_change_set|1|change_set_id|history_change_sets|id|RESTRICT|RESTRICT',
            'fk|fk_zr_snapshot|1|content_hash|zone_snapshots|content_hash|RESTRICT|RESTRICT',
            'chk|chk_zr_diff_summary|Table|`diff_summary` is null or json_valid(`diff_summary`)',
            'chk|chk_zr_revision_no|Table|`revision_no` >= 1',
        ],
        'zone_history_state' => [
            'table|BASE TABLE|InnoDB',
            'col|zone_id|int(11)|NO|~||~|~',
            'col|head_revision_no|int(10) unsigned|NO|~||~|~',
            'col|head_hash|char(64)|NO|~||ascii|ascii_bin',
            "col|tracking|enum('tracked','baseline_unverified')|NO|~||ascii|ascii_bin",
            'col|deleted_at|datetime(6)|YES|NULL||~|~',
            'idx|idx_zhs_head|1|1|zone_id|~|BTREE',
            'idx|idx_zhs_head|1|2|head_revision_no|~|BTREE',
            'idx|idx_zhs_head|1|3|head_hash|~|BTREE',
            'idx|PRIMARY|0|1|zone_id|~|BTREE',
            'fk|fk_zhs_head|1|zone_id|zone_revisions|zone_id|RESTRICT|RESTRICT',
            'fk|fk_zhs_head|2|head_revision_no|zone_revisions|revision_no|RESTRICT|RESTRICT',
            'fk|fk_zhs_head|3|head_hash|zone_revisions|content_hash|RESTRICT|RESTRICT',
        ],
        'history_dependencies' => [
            'table|BASE TABLE|InnoDB',
            'col|id|bigint(20) unsigned|NO|~|auto_increment|~|~',
            'col|change_set_id|bigint(20) unsigned|NO|~||~|~',
            'col|zone_id|int(11)|NO|~||~|~',
            'col|revision_no|int(10) unsigned|NO|~||~|~',
            'col|record_name|varchar(255)|NO|~||utf8mb4|utf8mb4_bin',
            'col|record_type|varchar(10)|NO|~||ascii|ascii_bin',
            'col|record_content|text|NO|~||utf8mb4|utf8mb4_bin',
            'col|dep_change_set_id|bigint(20) unsigned|NO|~||~|~',
            'col|dep_zone_id|int(11)|NO|~||~|~',
            'col|dep_revision_no|int(10) unsigned|NO|~||~|~',
            'col|dep_record_name|varchar(255)|NO|~||utf8mb4|utf8mb4_bin',
            'col|dep_record_type|varchar(10)|NO|~||ascii|ascii_bin',
            'col|dep_record_content|text|NO|~||utf8mb4|utf8mb4_bin',
            "col|kind|enum('auto_ptr')|NO|~||ascii|ascii_bin",
            'col|created_at|datetime(6)|NO|current_timestamp(6)||~|~',
            'idx|idx_hd_change_set|1|1|change_set_id|~|BTREE',
            'idx|idx_hd_dep_change_set|1|1|dep_change_set_id|~|BTREE',
            'idx|idx_hd_dep_zone_revision|1|1|dep_zone_id|~|BTREE',
            'idx|idx_hd_dep_zone_revision|1|2|dep_revision_no|~|BTREE',
            'idx|idx_hd_zone_revision|1|1|zone_id|~|BTREE',
            'idx|idx_hd_zone_revision|1|2|revision_no|~|BTREE',
            'idx|PRIMARY|0|1|id|~|BTREE',
            'fk|fk_hd_change_set|1|change_set_id|history_change_sets|id|RESTRICT|RESTRICT',
            'fk|fk_hd_dep_change_set|1|dep_change_set_id|history_change_sets|id|RESTRICT|RESTRICT',
            'fk|fk_hd_dep_revision|1|dep_zone_id|zone_revisions|zone_id|RESTRICT|RESTRICT',
            'fk|fk_hd_dep_revision|2|dep_revision_no|zone_revisions|revision_no|RESTRICT|RESTRICT',
            'fk|fk_hd_revision|1|zone_id|zone_revisions|zone_id|RESTRICT|RESTRICT',
            'fk|fk_hd_revision|2|revision_no|zone_revisions|revision_no|RESTRICT|RESTRICT',
            'chk|chk_hd_distinct|Table|`change_set_id` <> `dep_change_set_id`',
        ],
        'audit_events' => [
            'table|BASE TABLE|InnoDB',
            'col|id|bigint(20) unsigned|NO|~|auto_increment|~|~',
            'col|change_set_id|bigint(20) unsigned|NO|~||~|~',
            'col|seq|smallint(5) unsigned|NO|~||~|~',
            "col|entity_type|enum('zone','server','dyndns_account','user')|NO|~||ascii|ascii_bin",
            'col|entity_id|int(11)|NO|~||~|~',
            'col|entity_label|varchar(255)|NO|~||utf8mb4|utf8mb4_bin',
            'col|zone_id|int(11)|YES|NULL||~|~',
            "col|action|enum('create','update','delete','reload')|NO|~||ascii|ascii_bin",
            'col|changes|longtext|NO|~||utf8mb4|utf8mb4_bin',
            'idx|idx_ae_entity|1|1|entity_type|~|BTREE',
            'idx|idx_ae_entity|1|2|entity_id|~|BTREE',
            'idx|idx_ae_entity|1|3|change_set_id|~|BTREE',
            'idx|idx_ae_zone|1|1|zone_id|~|BTREE',
            'idx|idx_ae_zone|1|2|change_set_id|~|BTREE',
            'idx|PRIMARY|0|1|id|~|BTREE',
            'idx|uq_ae_change_set_seq|0|1|change_set_id|~|BTREE',
            'idx|uq_ae_change_set_seq|0|2|seq|~|BTREE',
            'fk|fk_ae_change_set|1|change_set_id|history_change_sets|id|RESTRICT|RESTRICT',
            "chk|chk_ae_action|Table|`action` = 'reload' and `entity_type` = 'server' or `action` in ('create','update','delete')",
            "chk|chk_ae_changes|Table|json_valid(`changes`) and json_type(`changes`) = 'OBJECT' and json_length(`changes`) >= 1"
                . ' and octet_length(`changes`) <= 16384',
            'chk|chk_ae_entity_id|Table|`entity_id` >= 1',
            'chk|chk_ae_label|Table|char_length(`entity_label`) >= 1',
            'chk|chk_ae_seq|Table|`seq` >= 1',
            "chk|chk_ae_zone|Table|`entity_type` = 'zone' and `zone_id` is not null and `zone_id` = `entity_id`"
                . " or `entity_type` = 'dyndns_account' and `zone_id` is not null and `zone_id` >= 1"
                . " or `entity_type` in ('server','user') and `zone_id` is null",
        ],
        'publish_runs' => [
            'table|BASE TABLE|InnoDB',
            'col|id|bigint(20) unsigned|NO|~|auto_increment|~|~',
            "col|kind|enum('all','zone')|NO|~||ascii|ascii_bin",
            'col|requested_zone_id|int(11)|YES|NULL||~|~',
            'col|full_rebuild|tinyint(1)|NO|~||~|~',
            'col|connection_id|bigint(20) unsigned|NO|~||~|~',
            'col|history_watermark|bigint(20) unsigned|NO|~||~|~',
            "col|actor_type|enum('user','dyndns_account','system')|NO|~||ascii|ascii_bin",
            'col|actor_user_id|int(11)|YES|NULL||~|~',
            'col|actor_dyndns_account_id|int(11)|YES|NULL||~|~',
            'col|channel|varchar(16)|NO|~||ascii|ascii_bin',
            'col|correlation_id|char(36)|NO|~||ascii|ascii_bin',
            'col|started_at|datetime(6)|NO|current_timestamp(6)||~|~',
            "col|status|enum('running','finished','abandoned')|NO|'running'||ascii|ascii_bin",
            'col|result|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|finished_at|datetime(6)|YES|NULL||~|~',
            'idx|idx_pr_correlation|1|1|correlation_id|~|BTREE',
            'idx|idx_pr_started|1|1|started_at|~|BTREE',
            'idx|idx_pr_status|1|1|status|~|BTREE',
            'idx|idx_pr_status|1|2|id|~|BTREE',
            'idx|PRIMARY|0|1|id|~|BTREE',
            "chk|chk_pr_actor|Table|`actor_type` = 'user' and `actor_user_id` is not null and `actor_dyndns_account_id` is null or `actor_type` = 'dyndns_account' and `actor_dyndns_account_id` is not null and `actor_user_id` is null or `actor_type` = 'system' and `actor_user_id` is null and `actor_dyndns_account_id` is null",
            "chk|chk_pr_channel|Table|`channel` regexp '^[a-z][a-z0-9_]{0,15}\$'",
            "chk|chk_pr_correlation|Table|`correlation_id` regexp '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\$'",
            "chk|chk_pr_kind|Table|`kind` = 'all' and `requested_zone_id` is null and `full_rebuild` in (0,1) or `kind` = 'zone' and `requested_zone_id` is not null and `requested_zone_id` >= 1 and `full_rebuild` = 0",
            "chk|chk_pr_lifecycle|Table|`status` = 'running' and `finished_at` is null and `result` is null or `status` = 'finished' and `finished_at` is not null and `result` is not null and `result` regexp '^[a-z_]{1,32}\$' or `status` = 'abandoned' and `finished_at` is not null and `result` is null",
        ],
        'zone_publish_attempts' => [
            'table|BASE TABLE|InnoDB',
            'col|id|bigint(20) unsigned|NO|~|auto_increment|~|~',
            'col|run_id|bigint(20) unsigned|NO|~||~|~',
            'col|zone_id|int(11)|NO|~||~|~',
            'col|zone_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            'col|revision_no|int(10) unsigned|YES|NULL||~|~',
            "col|revision_basis|enum('head','head_mismatch','no_head')|NO|~||ascii|ascii_bin",
            'col|content_hash|char(64)|NO|~||ascii|ascii_bin',
            'col|snapshot_contract|varchar(16)|NO|~||ascii|ascii_bin',
            'col|render_hash|char(64)|NO|~||ascii|ascii_bin',
            'col|file_hash|char(64)|NO|~||ascii|ascii_bin',
            'col|render_contract|varchar(16)|NO|~||ascii|ascii_bin',
            'col|soa_serial|int(10) unsigned|NO|~||~|~',
            'col|target_count|smallint(5) unsigned|NO|~||~|~',
            'col|generated_at|datetime(6)|NO|current_timestamp(6)||~|~',
            "col|outcome|enum('published','partial','failed','abandoned')|YES|NULL||ascii|ascii_bin",
            'col|finished_at|datetime(6)|YES|NULL||~|~',
            'idx|idx_zpa_revision|1|1|zone_id|~|BTREE',
            'idx|idx_zpa_revision|1|2|revision_no|~|BTREE',
            'idx|idx_zpa_revision|1|3|content_hash|~|BTREE',
            'idx|idx_zpa_zone|1|1|zone_id|~|BTREE',
            'idx|idx_zpa_zone|1|2|id|~|BTREE',
            'idx|PRIMARY|0|1|id|~|BTREE',
            'idx|uq_zpa_run_zone|0|1|run_id|~|BTREE',
            'idx|uq_zpa_run_zone|0|2|zone_id|~|BTREE',
            'fk|fk_zpa_revision|1|zone_id|zone_revisions|zone_id|RESTRICT|RESTRICT',
            'fk|fk_zpa_revision|2|revision_no|zone_revisions|revision_no|RESTRICT|RESTRICT',
            'fk|fk_zpa_revision|3|content_hash|zone_revisions|content_hash|RESTRICT|RESTRICT',
            'fk|fk_zpa_run|1|run_id|publish_runs|id|RESTRICT|RESTRICT',
            "chk|chk_zpa_contracts|Table|`snapshot_contract` = 'zs1' and `render_contract` = 'rh1'",
            "chk|chk_zpa_hashes|Table|`content_hash` regexp '^[0-9a-f]{64}\$' and `render_hash` regexp '^[0-9a-f]{64}\$' and `file_hash` regexp '^[0-9a-f]{64}\$'",
            'chk|chk_zpa_lifecycle|Table|`outcome` is null and `finished_at` is null or `outcome` is not null and `finished_at` is not null',
            "chk|chk_zpa_revision|Table|`revision_basis` = 'head' and `revision_no` is not null and `revision_no` >= 1 or `revision_basis` in ('head_mismatch','no_head') and `revision_no` is null",
            'chk|chk_zpa_zone|Table|`zone_id` >= 1 and char_length(`zone_name`) >= 1',
        ],
        'zone_publish_targets' => [
            'table|BASE TABLE|InnoDB',
            'col|attempt_id|bigint(20) unsigned|NO|~||~|~',
            'col|server_id|int(11)|NO|~||~|~',
            'col|server_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            "col|agent_protocol|enum('legacy')|NO|'legacy'||ascii|ascii_bin",
            "col|zone_sync|enum('sending','ok','failed','rejected','unknown','abandoned')|NO|'sending'||ascii|ascii_bin",
            'col|zone_sync_at|datetime(6)|YES|NULL||~|~',
            'col|zone_http_status|smallint(5) unsigned|YES|NULL||~|~',
            'col|zone_error_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|zone_error_detail|varchar(500)|YES|NULL||utf8mb4|utf8mb4_bin',
            "col|conf_sync|enum('sending','ok','failed','rejected','unknown','abandoned')|NO|'sending'||ascii|ascii_bin",
            'col|conf_sync_at|datetime(6)|YES|NULL||~|~',
            'col|conf_http_status|smallint(5) unsigned|YES|NULL||~|~',
            'col|conf_error_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|conf_error_detail|varchar(500)|YES|NULL||utf8mb4|utf8mb4_bin',
            'col|conf_hash|char(64)|YES|NULL||ascii|ascii_bin',
            "col|zone_protocol|enum('legacy','as2')|YES|NULL||ascii|ascii_bin",
            "col|zone_effect|enum('none','unchanged','applied','partial','unknown')|YES|NULL||ascii|ascii_bin",
            'col|zone_installed_serial|int(10) unsigned|YES|NULL||~|~',
            'col|zone_installed_hash|char(64)|YES|NULL||ascii|ascii_bin',
            "col|zone_reload|enum('ok','failed','timeout','not_started')|YES|NULL||ascii|ascii_bin",
            "col|zone_fence_decision|enum('accept','would_reject','reject')|YES|NULL||ascii|ascii_bin",
            'col|zone_fence_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            "col|conf_protocol|enum('legacy','as2')|YES|NULL||ascii|ascii_bin",
            "col|conf_effect|enum('none','unchanged','applied','partial','unknown')|YES|NULL||ascii|ascii_bin",
            "col|conf_reload|enum('ok','failed','timeout','not_started')|YES|NULL||ascii|ascii_bin",
            "col|conf_fence_decision|enum('accept','would_reject','reject')|YES|NULL||ascii|ascii_bin",
            'col|conf_fence_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            "col|conf_verify_state|enum('verified','serial_differs','unknown')|YES|NULL||ascii|ascii_bin",
            'col|conf_verify_reason|varchar(64)|YES|NULL||ascii|ascii_bin',
            'col|conf_verify_file_serial|int(10) unsigned|YES|NULL||~|~',
            'col|conf_verify_file_hash|char(64)|YES|NULL||ascii|ascii_bin',
            'col|conf_verify_served_serial|int(10) unsigned|YES|NULL||~|~',
            'idx|idx_zpt_server|1|1|server_id|~|BTREE',
            'idx|idx_zpt_server|1|2|attempt_id|~|BTREE',
            'idx|PRIMARY|0|1|attempt_id|~|BTREE',
            'idx|PRIMARY|0|2|server_id|~|BTREE',
            'fk|fk_zpt_attempt|1|attempt_id|zone_publish_attempts|id|RESTRICT|RESTRICT',
            "chk|chk_zpt_conf_agent|Table|`conf_protocol` is null and `conf_effect` is null and `conf_reload` is null and `conf_fence_decision` is null and `conf_fence_code` is null and `conf_verify_state` is null or `conf_protocol` is not null and `conf_protocol` = 'legacy' and `conf_sync` in ('ok','rejected','unknown') and `conf_effect` is null and `conf_reload` is null and `conf_fence_decision` is null and `conf_fence_code` is null and `conf_verify_state` is null or `conf_protocol` is not null and `conf_protocol` = 'as2' and `conf_sync` in ('ok','rejected','unknown') and `conf_effect` is not null",
            "chk|chk_zpt_conf_fence|Table|`conf_fence_decision` is null and `conf_fence_code` is null or `conf_fence_decision` is not null and `conf_fence_code` is null or `conf_fence_decision` is not null and `conf_fence_code` is not null and `conf_fence_code` regexp '^[a-z_]{1,32}\$'",
            "chk|chk_zpt_conf_hash|Table|`conf_hash` is null or `conf_hash` regexp '^[0-9a-f]{64}\$'",
            "chk|chk_zpt_conf_sync|Table|`conf_sync` = 'sending' and `conf_sync_at` is null and `conf_http_status` is null and `conf_error_code` is null and `conf_error_detail` is null or `conf_sync` in ('ok','abandoned') and `conf_sync_at` is not null and `conf_error_code` is null and `conf_error_detail` is null or `conf_sync` in ('failed','rejected','unknown') and `conf_sync_at` is not null and `conf_error_code` is not null and `conf_error_code` regexp '^[a-z_]{1,32}\$'",
            "chk|chk_zpt_conf_verify|Table|`conf_verify_state` is null and `conf_verify_reason` is null and `conf_verify_file_serial` is null and `conf_verify_file_hash` is null and `conf_verify_served_serial` is null or `conf_verify_state` is not null and `conf_verify_state` = 'verified' and `conf_sync` = 'ok' and `conf_verify_file_serial` is not null and `conf_verify_served_serial` is not null and `conf_verify_file_serial` = `conf_verify_served_serial` and `conf_verify_file_hash` is not null or `conf_verify_state` is not null and `conf_verify_state` = 'serial_differs' and `conf_verify_file_serial` is not null and `conf_verify_served_serial` is not null and `conf_verify_file_serial` <> `conf_verify_served_serial` or `conf_verify_state` is not null and `conf_verify_state` = 'unknown'",
            "chk|chk_zpt_conf_verify_fmt|Table|(`conf_verify_reason` is null or `conf_verify_reason` is not null and `conf_verify_reason` regexp '^[a-z0-9_:]{1,64}\$') and (`conf_verify_file_hash` is null or `conf_verify_file_hash` is not null and `conf_verify_file_hash` regexp '^[0-9a-f]{64}\$')",
            'chk|chk_zpt_http|Table|(`zone_http_status` is null or `zone_http_status` between 100 and 599) and (`conf_http_status` is null or `conf_http_status` between 100 and 599)',
            'chk|chk_zpt_target|Table|`server_id` >= 1 and char_length(`server_name`) >= 1',
            "chk|chk_zpt_zone_agent|Table|`zone_protocol` is null and `zone_effect` is null and `zone_installed_serial` is null and `zone_installed_hash` is null and `zone_reload` is null and `zone_fence_decision` is null and `zone_fence_code` is null or `zone_protocol` is not null and `zone_protocol` = 'legacy' and `zone_sync` in ('ok','rejected','unknown') and `zone_effect` is null and `zone_installed_serial` is null and `zone_installed_hash` is null and `zone_reload` is null and `zone_fence_decision` is null and `zone_fence_code` is null or `zone_protocol` is not null and `zone_protocol` = 'as2' and `zone_sync` in ('ok','rejected','unknown') and `zone_effect` is not null",
            "chk|chk_zpt_zone_fence|Table|`zone_fence_decision` is null and `zone_fence_code` is null or `zone_fence_decision` is not null and `zone_fence_code` is null or `zone_fence_decision` is not null and `zone_fence_code` is not null and `zone_fence_code` regexp '^[a-z_]{1,32}\$'",
            "chk|chk_zpt_zone_installed|Table|`zone_installed_serial` is null and `zone_installed_hash` is null or `zone_installed_serial` is not null and `zone_installed_hash` is not null and `zone_installed_hash` regexp '^[0-9a-f]{64}\$' and `zone_protocol` is not null and `zone_protocol` = 'as2' and `zone_effect` is not null and `zone_effect` in ('applied','unchanged') and `zone_reload` is not null and (`zone_sync` = 'ok' and `zone_reload` = 'ok' or `zone_sync` = 'unknown' and `zone_reload` in ('failed','timeout','not_started'))",
            "chk|chk_zpt_zone_sync|Table|`zone_sync` = 'sending' and `zone_sync_at` is null and `zone_http_status` is null and `zone_error_code` is null and `zone_error_detail` is null or `zone_sync` in ('ok','abandoned') and `zone_sync_at` is not null and `zone_error_code` is null and `zone_error_detail` is null or `zone_sync` in ('failed','rejected','unknown') and `zone_sync_at` is not null and `zone_error_code` is not null and `zone_error_code` regexp '^[a-z_]{1,32}\$'",
        ],
        'publish_cleanup_results' => [
            'table|BASE TABLE|InnoDB',
            'col|run_id|bigint(20) unsigned|NO|~||~|~',
            'col|server_id|int(11)|NO|~||~|~',
            'col|server_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            "col|agent_protocol|enum('legacy')|NO|'legacy'||ascii|ascii_bin",
            'col|registered_at|datetime(6)|NO|current_timestamp(6)||~|~',
            "col|status|enum('sending','ok','partial','failed','rejected','unknown','abandoned')|NO|'sending'||ascii|ascii_bin",
            'col|finished_at|datetime(6)|YES|NULL||~|~',
            'col|valid_zone_count|int(10) unsigned|YES|NULL||~|~',
            'col|removed_conf_count|int(10) unsigned|YES|NULL||~|~',
            'col|removed_zone_file_count|int(10) unsigned|YES|NULL||~|~',
            'col|http_status|smallint(5) unsigned|YES|NULL||~|~',
            'col|error_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|error_detail|varchar(500)|YES|NULL||utf8mb4|utf8mb4_bin',
            "col|cleanup_protocol|enum('legacy','as2')|YES|NULL||ascii|ascii_bin",
            "col|cleanup_effect|enum('none','unchanged','applied','partial','unknown')|YES|NULL||ascii|ascii_bin",
            "col|fence_decision|enum('accept','would_reject','reject')|YES|NULL||ascii|ascii_bin",
            'col|fence_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|served_absent_count|int(10) unsigned|YES|NULL||~|~',
            'col|served_still_count|int(10) unsigned|YES|NULL||~|~',
            'col|served_unknown_count|int(10) unsigned|YES|NULL||~|~',
            'col|unassigned_count|int(10) unsigned|YES|NULL||~|~',
            'idx|idx_pcr_server|1|1|server_id|~|BTREE',
            'idx|idx_pcr_server|1|2|run_id|~|BTREE',
            'idx|PRIMARY|0|1|run_id|~|BTREE',
            'idx|PRIMARY|0|2|server_id|~|BTREE',
            'fk|fk_pcr_run|1|run_id|publish_runs|id|RESTRICT|RESTRICT',
            "chk|chk_pcr_agent|Table|`cleanup_protocol` is null and `cleanup_effect` is null and `fence_decision` is null and `fence_code` is null or `cleanup_protocol` is not null and `cleanup_protocol` = 'legacy' and `status` in ('ok','partial','rejected','unknown') and `cleanup_effect` is null and `fence_decision` is null and `fence_code` is null or `cleanup_protocol` is not null and `cleanup_protocol` = 'as2' and `status` in ('ok','partial','rejected','unknown') and `cleanup_effect` is not null",
            "chk|chk_pcr_counts|Table|`status` in ('ok','partial') and `removed_conf_count` is not null and `removed_zone_file_count` is not null or `status` not in ('ok','partial') and `removed_conf_count` is null and `removed_zone_file_count` is null",
            "chk|chk_pcr_fence|Table|`fence_decision` is null and `fence_code` is null or `fence_decision` is not null and `fence_code` is null or `fence_decision` is not null and `fence_code` is not null and `fence_code` regexp '^[a-z_]{1,32}\$'",
            'chk|chk_pcr_http|Table|`http_status` is null or `http_status` between 100 and 599',
            "chk|chk_pcr_served|Table|`served_absent_count` is null and `served_still_count` is null and `served_unknown_count` is null and `unassigned_count` is null or `served_absent_count` is not null and `served_still_count` is not null and `served_unknown_count` is not null and `unassigned_count` is not null and `cleanup_protocol` is not null and `cleanup_protocol` = 'as2'",
            "chk|chk_pcr_status|Table|`status` = 'sending' and `finished_at` is null and `http_status` is null and `error_code` is null and `error_detail` is null and `removed_conf_count` is null and `removed_zone_file_count` is null or `status` in ('ok','abandoned') and `finished_at` is not null and `error_code` is null and `error_detail` is null or `status` in ('partial','failed','rejected','unknown') and `finished_at` is not null and `error_code` is not null and `error_code` regexp '^[a-z_]{1,32}\$'",
            'chk|chk_pcr_target|Table|`server_id` >= 1 and char_length(`server_name`) >= 1',
        ],
        // AS3 (E87, Migration 006a): letzter ausgelieferter Zustand und letzter positiver Nachweis je Zone und Server
        'zone_server_verification' => [
            'table|BASE TABLE|InnoDB',
            'col|zone_id|int(11)|NO|~||~|~',
            'col|server_id|int(11)|NO|~||~|~',
            'col|zone_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            'col|server_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            "col|last_state|enum('verified','serial_differs','still_served','absent','unknown')|NO|~||ascii|ascii_bin",
            'col|last_reason|varchar(64)|YES|NULL||ascii|ascii_bin',
            "col|last_method|enum('conf_sync','cleanup','zone_verify','monitoring')|NO|~||ascii|ascii_bin",
            'col|last_served_serial|int(10) unsigned|YES|NULL||~|~',
            'col|last_file_serial|int(10) unsigned|YES|NULL||~|~',
            'col|last_file_hash|char(64)|YES|NULL||ascii|ascii_bin',
            'col|last_run_id|bigint(20) unsigned|YES|NULL||~|~',
            'col|last_attempt_id|bigint(20) unsigned|YES|NULL||~|~',
            'col|last_observed_at|datetime(6)|NO|~||~|~',
            'col|verified_serial|int(10) unsigned|YES|NULL||~|~',
            'col|verified_file_hash|char(64)|YES|NULL||ascii|ascii_bin',
            'col|verified_run_id|bigint(20) unsigned|YES|NULL||~|~',
            'col|verified_at|datetime(6)|YES|NULL||~|~',
            'idx|idx_zsv_server|1|1|server_id|~|BTREE',
            'idx|idx_zsv_zone_name|1|1|zone_name|~|BTREE',
            'idx|PRIMARY|0|1|zone_id|~|BTREE',
            'idx|PRIMARY|0|2|server_id|~|BTREE',
            "chk|chk_zsv_hashes|Table|(`last_file_hash` is null or `last_file_hash` regexp '^[0-9a-f]{64}\$') and (`verified_file_hash` is null or `verified_file_hash` regexp '^[0-9a-f]{64}\$')",
            "chk|chk_zsv_last_state|Table|`last_state` = 'verified' and `last_served_serial` is not null and `last_file_serial` is not null and `last_file_hash` is not null and `last_served_serial` = `last_file_serial` or `last_state` = 'serial_differs' and `last_served_serial` is not null and `last_file_serial` is not null and `last_served_serial` <> `last_file_serial` or `last_state` = 'still_served' and `last_served_serial` is not null or `last_state` = 'absent' and `last_served_serial` is null or `last_state` = 'unknown'",
            "chk|chk_zsv_reason|Table|`last_reason` is null or `last_reason` regexp '^[a-z0-9_:]{1,64}\$'",
            'chk|chk_zsv_target|Table|`zone_id` >= 1 and `server_id` >= 1 and char_length(`zone_name`) >= 1 and char_length(`server_name`) >= 1',
            'chk|chk_zsv_verified_bundle|Table|`verified_serial` is null and `verified_file_hash` is null and `verified_at` is null and `verified_run_id` is null or `verified_serial` is not null and `verified_file_hash` is not null and `verified_at` is not null',
            "chk|chk_zsv_verified_current|Table|`last_state` <> 'verified' or `verified_serial` is not null and `verified_file_hash` is not null and `verified_at` is not null and `last_served_serial` is not null and `last_file_hash` is not null and `verified_serial` = `last_served_serial` and `verified_file_hash` = `last_file_hash` and `verified_at` = `last_observed_at`",
            'chk|chk_zsv_verified_order|Table|`verified_at` is null or `verified_at` <= `last_observed_at`',
        ],
        // AS5 (D20, Migration 008): Serial-Referenzereignisse je Zone × Server (nur anhängend, SerialReferenceStore)
        'zone_serial_references' => [
            'table|BASE TABLE|InnoDB',
            'col|id|bigint(20) unsigned|NO|~|auto_increment|~|~',
            "col|source|enum('epoch','run','intent','agent_check','no_report','loss','conf_sync','cleanup','monitoring','admin')|NO|~||ascii|ascii_bin",
            'col|zone_id|int(11)|YES|NULL||~|~',
            'col|zone_name|varchar(255)|YES|NULL||ascii|ascii_bin',
            'col|server_id|int(11)|YES|NULL||~|~',
            'col|server_name|varchar(255)|YES|NULL||utf8mb4|utf8mb4_unicode_ci',
            'col|run_key|bigint(20) unsigned|YES|NULL||~|~',
            'col|attempt_id|bigint(20) unsigned|YES|NULL||~|~',
            'col|order_certain|tinyint(1)|NO|1||~|~',
            "col|obs_state|enum('verified','serial_differs','still_served','absent','unknown')|YES|NULL||ascii|ascii_bin",
            'col|reason|varchar(64)|YES|NULL||ascii|ascii_bin',
            "col|decision|enum('accept','would_reject','reject')|YES|NULL||ascii|ascii_bin",
            'col|code|varchar(32)|YES|NULL||ascii|ascii_bin',
            "col|file_state|enum('missing','valid','invalid','unreadable')|YES|NULL||ascii|ascii_bin",
            'col|file_serial|int(10) unsigned|YES|NULL||~|~',
            'col|file_hash|char(64)|YES|NULL||ascii|ascii_bin',
            "col|config_state|enum('yes','no','undeterminable')|YES|NULL||ascii|ascii_bin",
            "col|served_state|enum('served','not_auth','unknown')|YES|NULL||ascii|ascii_bin",
            'col|served_serial|int(10) unsigned|YES|NULL||~|~',
            'col|evidence|varchar(500)|YES|NULL||utf8mb4|utf8mb4_bin',
            'col|change_set_id|bigint(20) unsigned|YES|NULL||~|~',
            'col|recorded_at|datetime(6)|NO|current_timestamp(6)||~|~',
            'idx|idx_zsr_change_set|1|1|change_set_id|~|BTREE',
            'idx|idx_zsr_pair|1|1|zone_name|~|BTREE',
            'idx|idx_zsr_pair|1|2|server_id|~|BTREE',
            'idx|idx_zsr_pair|1|3|id|~|BTREE',
            'idx|idx_zsr_source|1|1|source|~|BTREE',
            'idx|idx_zsr_source|1|2|run_key|~|BTREE',
            'idx|idx_zsr_zone|1|1|zone_id|~|BTREE',
            'idx|idx_zsr_zone|1|2|server_id|~|BTREE',
            'idx|idx_zsr_zone|1|3|id|~|BTREE',
            'idx|PRIMARY|0|1|id|~|BTREE',
            'idx|uq_zsr_report|0|1|attempt_id|~|BTREE',
            'idx|uq_zsr_report|0|2|server_id|~|BTREE',
            'idx|uq_zsr_report|0|3|source|~|BTREE',
            'fk|fk_zsr_change_set|1|change_set_id|history_change_sets|id|RESTRICT|RESTRICT',
            'fk|fk_zsr_target|1|attempt_id|zone_publish_targets|attempt_id|RESTRICT|RESTRICT',
            'fk|fk_zsr_target|2|server_id|zone_publish_targets|server_id|RESTRICT|RESTRICT',
            "chk|chk_zsr_formats|Table|(`reason` is null or `reason` regexp '^[a-z0-9_:]{1,64}\$') and (`code` is null or `code` regexp '^[a-z_]{1,32}\$') and (`file_hash` is null or `file_hash` regexp '^[0-9a-f]{64}\$') and (`evidence` is null or char_length(`evidence`) >= 1)",
            "chk|chk_zsr_identity|Table|`source` in ('epoch','run') and `zone_id` is null and `zone_name` is null and `server_id` is null and `server_name` is null and `run_key` is not null and `attempt_id` is null and `order_certain` = 1 or `source` in ('intent','agent_check','no_report','conf_sync') and `zone_id` is not null and `zone_id` >= 1 and `zone_name` is not null and `server_id` is not null and `server_id` >= 1 and `run_key` is not null and `attempt_id` is not null or `source` = 'loss' and `zone_id` is not null and `zone_id` >= 1 and `zone_name` is not null and `server_id` is not null and `server_id` >= 1 and `run_key` is not null or `source` in ('cleanup','monitoring','admin') and `zone_id` is not null and `zone_id` >= 1 and `zone_name` is not null and `server_id` is not null and `server_id` >= 1 and `run_key` is not null and `attempt_id` is null",
            "chk|chk_zsr_name|Table|`zone_name` is null or `zone_name` regexp '^[a-z0-9_][a-z0-9._-]{0,252}\$'",
            "chk|chk_zsr_obs|Table|`obs_state` is null or `obs_state` = 'verified' and `served_serial` is not null and `file_serial` is not null and `file_hash` is not null and `served_serial` = `file_serial` or `obs_state` = 'serial_differs' and `served_serial` is not null and `file_serial` is not null and `served_serial` <> `file_serial` or `obs_state` = 'still_served' and `served_serial` is not null and `file_serial` is null and `file_hash` is null or `obs_state` = 'absent' and `served_serial` is null and `file_serial` is null and `file_hash` is null or `obs_state` = 'unknown'",
            'chk|chk_zsr_order|Table|`order_certain` in (0,1)',
            "chk|chk_zsr_values|Table|`source` in ('epoch','run','intent') and `obs_state` is null and `reason` is null and `decision` is null and `code` is null and `file_state` is null and `file_serial` is null and `file_hash` is null and `config_state` is null and `served_state` is null and `served_serial` is null and `evidence` is null and `change_set_id` is null or `source` in ('no_report','loss') and `reason` is not null and `obs_state` is null and `decision` is null and `code` is null and `file_state` is null and `file_serial` is null and `file_hash` is null and `config_state` is null and `served_state` is null and `served_serial` is null and `evidence` is null and `change_set_id` is null or `source` = 'agent_check' and `decision` is not null and `file_state` is not null and `config_state` is not null and `served_state` is not null and `obs_state` is null and `evidence` is null and `change_set_id` is null and (`file_state` = 'valid' and `file_serial` is not null or `file_state` <> 'valid' and `file_serial` is null) and (`served_state` = 'served' and `served_serial` is not null or `served_state` <> 'served' and `served_serial` is null) and (`decision` = 'accept' and `code` is null or `decision` <> 'accept' and `code` is not null) or `source` in ('conf_sync','cleanup','monitoring') and `obs_state` is not null and `decision` is null and `code` is null and `file_state` is null and `config_state` is null and `served_state` is null and `evidence` is null and `change_set_id` is null or `source` = 'admin' and `evidence` is not null and `change_set_id` is not null and `obs_state` is null and `reason` is null and `decision` is null and `code` is null and `file_state` is null and `file_hash` is null and `config_state` is null and `served_state` is null and `order_certain` = 1 and (`file_serial` is not null and `served_serial` is null or `file_serial` is null and `served_serial` is not null)",
        ],
    ];

    /**
     * Strukturen VOR Migration 007 (AS4a-Zwischenstand) für zone_publish_targets und publish_cleanup_results –
     * ausschließlich für die Migrationswerkzeuge (web/scripts/schema_history-cli.php, d. h. scripts/migrate_00*.sh):
     * Die Migrationen 005 und 006 legen diesen Zwischenstand legitim an bzw. setzen ihn voraus, Migration 007 überführt
     * ihn. Login und Session-Revalidierung (problems()) akzeptieren seit AS4b ausschließlich die Struktur nach 007.
     */
    public const HISTORY_TABLES_PRE007 = [
        'zone_publish_targets' => [
            'table|BASE TABLE|InnoDB',
            'col|attempt_id|bigint(20) unsigned|NO|~||~|~',
            'col|server_id|int(11)|NO|~||~|~',
            'col|server_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            "col|agent_protocol|enum('legacy')|NO|'legacy'||ascii|ascii_bin",
            "col|zone_sync|enum('sending','ok','failed','rejected','unknown','abandoned')|NO|'sending'||ascii|ascii_bin",
            'col|zone_sync_at|datetime(6)|YES|NULL||~|~',
            'col|zone_http_status|smallint(5) unsigned|YES|NULL||~|~',
            'col|zone_error_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|zone_error_detail|varchar(500)|YES|NULL||utf8mb4|utf8mb4_bin',
            "col|conf_sync|enum('sending','ok','failed','rejected','unknown','abandoned')|NO|'sending'||ascii|ascii_bin",
            'col|conf_sync_at|datetime(6)|YES|NULL||~|~',
            'col|conf_http_status|smallint(5) unsigned|YES|NULL||~|~',
            'col|conf_error_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|conf_error_detail|varchar(500)|YES|NULL||utf8mb4|utf8mb4_bin',
            'col|conf_hash|char(64)|YES|NULL||ascii|ascii_bin',
            'idx|idx_zpt_server|1|1|server_id|~|BTREE',
            'idx|idx_zpt_server|1|2|attempt_id|~|BTREE',
            'idx|PRIMARY|0|1|attempt_id|~|BTREE',
            'idx|PRIMARY|0|2|server_id|~|BTREE',
            'fk|fk_zpt_attempt|1|attempt_id|zone_publish_attempts|id|RESTRICT|RESTRICT',
            "chk|chk_zpt_conf_hash|Table|`conf_hash` is null or `conf_hash` regexp '^[0-9a-f]{64}\$'",
            "chk|chk_zpt_conf_sync|Table|`conf_sync` = 'sending' and `conf_sync_at` is null and `conf_http_status` is null and `conf_error_code` is null and `conf_error_detail` is null or `conf_sync` in ('ok','abandoned') and `conf_sync_at` is not null and `conf_error_code` is null and `conf_error_detail` is null or `conf_sync` in ('failed','rejected','unknown') and `conf_sync_at` is not null and `conf_error_code` is not null and `conf_error_code` regexp '^[a-z_]{1,32}\$'",
            'chk|chk_zpt_http|Table|(`zone_http_status` is null or `zone_http_status` between 100 and 599) and (`conf_http_status` is null or `conf_http_status` between 100 and 599)',
            'chk|chk_zpt_target|Table|`server_id` >= 1 and char_length(`server_name`) >= 1',
            "chk|chk_zpt_zone_sync|Table|`zone_sync` = 'sending' and `zone_sync_at` is null and `zone_http_status` is null and `zone_error_code` is null and `zone_error_detail` is null or `zone_sync` in ('ok','abandoned') and `zone_sync_at` is not null and `zone_error_code` is null and `zone_error_detail` is null or `zone_sync` in ('failed','rejected','unknown') and `zone_sync_at` is not null and `zone_error_code` is not null and `zone_error_code` regexp '^[a-z_]{1,32}\$'",
        ],
        'publish_cleanup_results' => [
            'table|BASE TABLE|InnoDB',
            'col|run_id|bigint(20) unsigned|NO|~||~|~',
            'col|server_id|int(11)|NO|~||~|~',
            'col|server_name|varchar(255)|NO|~||utf8mb4|utf8mb4_unicode_ci',
            "col|agent_protocol|enum('legacy')|NO|'legacy'||ascii|ascii_bin",
            'col|registered_at|datetime(6)|NO|current_timestamp(6)||~|~',
            "col|status|enum('sending','ok','partial','failed','rejected','unknown','abandoned')|NO|'sending'||ascii|ascii_bin",
            'col|finished_at|datetime(6)|YES|NULL||~|~',
            'col|valid_zone_count|int(10) unsigned|YES|NULL||~|~',
            'col|removed_conf_count|int(10) unsigned|YES|NULL||~|~',
            'col|removed_zone_file_count|int(10) unsigned|YES|NULL||~|~',
            'col|http_status|smallint(5) unsigned|YES|NULL||~|~',
            'col|error_code|varchar(32)|YES|NULL||ascii|ascii_bin',
            'col|error_detail|varchar(500)|YES|NULL||utf8mb4|utf8mb4_bin',
            'idx|idx_pcr_server|1|1|server_id|~|BTREE',
            'idx|idx_pcr_server|1|2|run_id|~|BTREE',
            'idx|PRIMARY|0|1|run_id|~|BTREE',
            'idx|PRIMARY|0|2|server_id|~|BTREE',
            'fk|fk_pcr_run|1|run_id|publish_runs|id|RESTRICT|RESTRICT',
            "chk|chk_pcr_counts|Table|`status` in ('ok','partial') and `removed_conf_count` is not null and `removed_zone_file_count` is not null or `status` not in ('ok','partial') and `removed_conf_count` is null and `removed_zone_file_count` is null",
            'chk|chk_pcr_http|Table|`http_status` is null or `http_status` between 100 and 599',
            "chk|chk_pcr_status|Table|`status` = 'sending' and `finished_at` is null and `http_status` is null and `error_code` is null and `error_detail` is null and `removed_conf_count` is null and `removed_zone_file_count` is null or `status` in ('ok','abandoned') and `finished_at` is not null and `error_code` is null and `error_detail` is null or `status` in ('partial','failed','rejected','unknown') and `finished_at` is not null and `error_code` is not null and `error_code` regexp '^[a-z_]{1,32}\$'",
            'chk|chk_pcr_target|Table|`server_id` >= 1 and char_length(`server_name`) >= 1',
        ],
    ];

    /**
     * Signaturabfrage für die aktuelle Datenbank (DATABASE()): Zeilen [Tabelle, Signaturzeile], deterministisch
     * sortiert. Der Platzhalter {TABLES} wird durch die festen Namen aus HISTORY_TABLES ersetzt.
     */
    private const HISTORY_SIGNATURE_SQL = <<<'SQL'
SELECT t, l FROM (
 SELECT TABLE_NAME AS t, 1 AS k, '' AS o1, 0 AS o2, CONCAT_WS('|', 'table', TABLE_TYPE, IFNULL(ENGINE, '~')) AS l
   FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({TABLES})
 UNION ALL
 SELECT TABLE_NAME, 2, '', ORDINAL_POSITION, CONCAT_WS('|', 'col', COLUMN_NAME, LOWER(COLUMN_TYPE), IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), LOWER(EXTRA), IFNULL(CHARACTER_SET_NAME, '~'), IFNULL(COLLATION_NAME, '~'))
   FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({TABLES})
 UNION ALL
 SELECT TABLE_NAME, 3, INDEX_NAME, SEQ_IN_INDEX, CONCAT_WS('|', 'idx', INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, IFNULL(SUB_PART, '~'), INDEX_TYPE)
   FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({TABLES})
 UNION ALL
 SELECT k.TABLE_NAME, 4, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, CONCAT_WS('|', 'fk', k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE)
   FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
     ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
  WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ({TABLES}) AND k.REFERENCED_TABLE_NAME IS NOT NULL
 UNION ALL
 SELECT TABLE_NAME, 5, CONSTRAINT_NAME, 0, CONCAT_WS('|', 'chk', CONSTRAINT_NAME, LEVEL, CHECK_CLAUSE)
   FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ({TABLES})
) s ORDER BY t, k, o1, o2
SQL;

    public const STATE_OK = 'ok';
    public const STATE_ABSENT = 'absent';
    public const STATE_MISMATCH = 'mismatch';

    /** @var array<string, string[]> Cache: DB-Name => Abweichungen */
    private static array $cache = [];

    /**
     * Abweichungen vom erwarteten Schema (leer = in Ordnung).
     *
     * @return string[] z. B. ["users.active fehlt", "users.session_epoch: Typ int(11) statt bigint(20) unsigned",
     *                  "zone_revisions fehlt", "zone_snapshots: Struktur weicht ab (erwartet …, gefunden …)"]
     *
     * @throws PDOException bei Datenbankfehlern (Aufrufer entscheidet über Fail-Closed)
     */
    public static function problems(PDO $pdo): array
    {
        $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (isset(self::$cache[$db])) {
            return self::$cache[$db];
        }

        $problems = [];
        $stmt = $pdo->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        foreach (self::REQUIRED as $table => $columns) {
            foreach ($columns as $column => [$type, $nullable, $default]) {
                $stmt->execute([$table, $column]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                if ($row === false) {
                    $problems[] = "$table.$column fehlt";
                    continue;
                }
                // MariaDB liefert Standardwerte numerischer Spalten ohne Anführungszeichen ("1"), ältere
                // Versionen ggf. mit ("'1'"); beides ist gleichwertig.
                $actualDefault = $row['COLUMN_DEFAULT'] === null ? null : trim((string)$row['COLUMN_DEFAULT'], "'");
                if (strtolower((string)$row['COLUMN_TYPE']) !== $type) {
                    $problems[] = "$table.$column: Typ {$row['COLUMN_TYPE']} statt $type";
                }
                if ((string)$row['IS_NULLABLE'] !== $nullable) {
                    $problems[] = "$table.$column: NULL zulässig";
                }
                if ($actualDefault !== $default) {
                    $problems[] = "$table.$column: Standardwert " . var_export($actualDefault, true) . " statt $default";
                }
            }
        }

        foreach (self::evaluateHistory(self::historySignatureRows($pdo)) as $table => $result) {
            if ($result['state'] === self::STATE_ABSENT) {
                $problems[] = "$table fehlt";
            } elseif ($result['state'] === self::STATE_MISMATCH) {
                $problems[] = "$table: Struktur weicht ab (" . $result['detail'] . ')';
            }
        }

        return self::$cache[$db] = $problems;
    }

    /**
     * Signaturabfrage mit eingesetzten Tabellennamen (für PDO und das Migrationsskript).
     */
    public static function historySignatureSql(): string
    {
        $names = implode(',', array_map(static fn (string $t): string => "'$t'", array_keys(self::HISTORY_TABLES)));

        return str_replace('{TABLES}', $names, self::HISTORY_SIGNATURE_SQL);
    }

    /**
     * Ermittelt die Signaturzeilen der History-Tabellen in der aktuellen Datenbank.
     *
     * @return array<int, array{0: string, 1: string}> [Tabelle, Signaturzeile]
     *
     * @throws PDOException bei Datenbankfehlern
     */
    public static function historySignatureRows(PDO $pdo): array
    {
        $rows = [];
        foreach ($pdo->query(self::historySignatureSql())->fetchAll(PDO::FETCH_NUM) as $row) {
            $rows[] = [(string)$row[0], (string)$row[1]];
        }

        return $rows;
    }

    /**
     * Bewertet Signaturzeilen gegen die Erwartung (rein, ohne Datenbank).
     *
     * @param array<int, array{0: string, 1: string}> $rows [Tabelle, Signaturzeile] wie von historySignatureRows()
     *
     * @return array<string, array{state: string, detail: string}> je erwarteter Tabelle in fester Reihenfolge:
     *         ok | absent | mismatch; detail = erste Abweichung (erwartet/gefunden) bei mismatch, sonst ""
     *         (gegenüber der Erwartung aus HISTORY_TABLES).
     * @param bool $acceptPre007 nur für die Migrationswerkzeuge: zone_publish_targets und publish_cleanup_results sind
     *                           zusätzlich mit exakt der Struktur vor Migration 007 ok (HISTORY_TABLES_PRE007)
     */
    public static function evaluateHistory(array $rows, bool $acceptPre007 = false): array
    {
        $actual = [];
        foreach ($rows as [$table, $line]) {
            $actual[$table][] = $line;
        }

        $result = [];
        foreach (self::HISTORY_TABLES as $table => $expected) {
            $lines = $actual[$table] ?? [];
            if ($lines === []) {
                $result[$table] = ['state' => self::STATE_ABSENT, 'detail' => ''];
                continue;
            }
            if ($lines === $expected) {
                $result[$table] = ['state' => self::STATE_OK, 'detail' => ''];
                continue;
            }
            // Nur Migrationswerkzeuge: Zwischenstand vor Migration 007 (HISTORY_TABLES_PRE007)
            if ($acceptPre007 && isset(self::HISTORY_TABLES_PRE007[$table]) && $lines === self::HISTORY_TABLES_PRE007[$table]) {
                $result[$table] = ['state' => self::STATE_OK, 'detail' => ''];
                continue;
            }
            $detail = '';
            $count = max(count($lines), count($expected));
            for ($i = 0; $i < $count; $i++) {
                $e = $expected[$i] ?? null;
                $a = $lines[$i] ?? null;
                if ($e !== $a) {
                    $detail = 'erwartet ' . ($e ?? '(nichts)') . ', gefunden ' . ($a ?? '(nichts)');
                    break;
                }
            }
            $result[$table] = ['state' => self::STATE_MISMATCH, 'detail' => $detail];
        }

        return $result;
    }
}
