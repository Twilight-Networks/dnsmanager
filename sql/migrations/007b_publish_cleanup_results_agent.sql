-- Migration 007, Schritt b: Agent-Ergebnisfelder in publish_cleanup_results (AS4a, docs/ARCHITECTURE_HISTORY.md
-- Abschnitt 5i, J4a, Q5)
--
-- Zweck: tatsächlich verwendetes Protokoll, effect, Fencing-Entscheidung und – nur Zähler (Q5, keine Zonennamen) – das
-- Ergebnis des Entfernungsnachweises (AS2b): absent, still_served, unknown sowie nicht eindeutig zuordenbare Ergebnisse
-- (unassigned_count, AS4b O5). Geschrieben erst ab AS4b, einmal zusammen mit dem Endzustand (J4a). agent_protocol
-- bleibt unverändert (Registrierungszustand).
--
-- Alle Spalten NULL-fähig ohne Standardwert; bestehende Zeilen erfüllen alle neuen CHECKs. Jeder Vergleich einer
-- NULL-fähigen Spalte steht hinter "<spalte> IS NOT NULL"; jede Belegung ergibt TRUE oder FALSE (K1).
--
-- Ausführung ausschließlich über scripts/migrate_007_journal_agent_results.sh, siehe sql/migrations/README.md.

ALTER TABLE publish_cleanup_results
    ADD COLUMN cleanup_protocol ENUM('legacy', 'as2') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN cleanup_effect ENUM('none', 'unchanged', 'applied', 'partial', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN fence_decision ENUM('accept', 'would_reject', 'reject') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN fence_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN served_absent_count INT UNSIGNED NULL,
    ADD COLUMN served_still_count INT UNSIGNED NULL,
    ADD COLUMN served_unknown_count INT UNSIGNED NULL,
    ADD COLUMN unassigned_count INT UNSIGNED NULL,
    ADD CONSTRAINT chk_pcr_agent CHECK (
        (cleanup_protocol IS NULL AND cleanup_effect IS NULL AND fence_decision IS NULL AND fence_code IS NULL)
        OR (cleanup_protocol IS NOT NULL AND cleanup_protocol = 'legacy' AND status IN ('ok', 'partial', 'rejected', 'unknown')
            AND cleanup_effect IS NULL AND fence_decision IS NULL AND fence_code IS NULL)
        OR (cleanup_protocol IS NOT NULL AND cleanup_protocol = 'as2' AND status IN ('ok', 'partial', 'rejected', 'unknown')
            AND cleanup_effect IS NOT NULL)
    ),
    ADD CONSTRAINT chk_pcr_fence CHECK (
        (fence_decision IS NULL AND fence_code IS NULL)
        OR (fence_decision IS NOT NULL AND fence_code IS NULL)
        OR (fence_decision IS NOT NULL AND fence_code IS NOT NULL AND fence_code REGEXP '^[a-z_]{1,32}$')
    ),
    -- Zähler des Entfernungsnachweises gemeinsam und nur mit Protokoll as2
    ADD CONSTRAINT chk_pcr_served CHECK (
        (served_absent_count IS NULL AND served_still_count IS NULL AND served_unknown_count IS NULL AND unassigned_count IS NULL)
        OR (served_absent_count IS NOT NULL AND served_still_count IS NOT NULL AND served_unknown_count IS NOT NULL
            AND unassigned_count IS NOT NULL AND cleanup_protocol IS NOT NULL AND cleanup_protocol = 'as2')
    ),
    ALGORITHM=COPY, LOCK=SHARED;
