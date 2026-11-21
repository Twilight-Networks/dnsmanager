-- Migration 007, Schritt c: Fencing-Domäne der Installation in system_status (AS4a, E74, O2; docs/ARCHITECTURE_HISTORY.md
-- Abschnitt 5i)
--
-- Zweck: Installationsdomäne (UUID in Kleinbuchstaben), die der Core ab AS4b vor jedem Lauf mit der Web-Konfiguration
-- abgleicht und an die Agenten sendet. Migration 007 setzt keinen Wert; die Initialisierung erfolgt mit AS4b
-- kontrolliert per Admin-CLI. NULL = nicht initialisiert. Nicht Teil der History-Signatur.
--
-- Ausführung ausschließlich über scripts/migrate_007_journal_agent_results.sh, siehe sql/migrations/README.md.

ALTER TABLE system_status
    ADD COLUMN fence_domain CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD CONSTRAINT chk_ss_fence_domain CHECK (
        fence_domain IS NULL
        OR (fence_domain IS NOT NULL AND fence_domain REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
    ),
    ALGORITHM=COPY, LOCK=SHARED;
