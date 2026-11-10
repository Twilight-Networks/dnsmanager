-- Migration 001: Kontostatus und Sitzungsgeneration für Benutzer (S26/S27)
--
-- Zweck:
--   users.active        TINYINT(1) NOT NULL DEFAULT 1      (0 = Konto deaktiviert)
--   users.session_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0 (Sitzungsgeneration)
--
-- Reihenfolge beim Update: ZUERST diese Migration, DANN den neuen Code ausrollen.
--   - Alter Code + neues Schema: lauffähig (zusätzliche Spalten mit Standardwerten werden ignoriert).
--   - Neuer Code + altes Schema: Login und geschützte Seiten antworten mit HTTP 503 (Fail-Closed).
--
-- Wiederholbar: ADD COLUMN IF NOT EXISTS (MariaDB). Beide Spalten werden in EINER ALTER-Anweisung
-- angelegt (atomar: beide oder keine). Bestehende Konten werden aktiv (1), Generation 0.
-- Weicht eine bereits vorhandene Spalte von der erwarteten Definition ab, ändert diese Datei sie NICHT;
-- das Prüfskript scripts/migrate_001_users_active_session_epoch.sh bricht in diesem Fall vorher ab.
--
-- Empfohlen: Ausführung über das Skript (Preflight, Sicherung, Nachprüfung), siehe sql/migrations/README.md.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN IF NOT EXISTS session_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER active;
