-- Migration 007, Schritt a: Agent-Ergebnisfelder je Kanal in zone_publish_targets (AS4a, docs/ARCHITECTURE_HISTORY.md
-- Abschnitt 5i, J4a)
--
-- Zweck: Rohwerte der Agentenantwort je Kanal (zone_sync, conf_sync) als Lebenszyklus-/Ergebnisfelder: tatsächlich
-- verwendetes Protokoll (legacy | as2), effect, installierte Serial und Datei-Hash, Reload-Ergebnis, Fencing-Entscheidung
-- und – nur conf_sync – das L3-Ergebnis der Nachverifikation (AS2b). Geschrieben werden die Felder erst ab AS4b, und
-- zwar genau einmal zusammen mit dem Endzustand des Kanals (J4a). agent_protocol bleibt unverändert: Es bezeichnet nur
-- den Registrierungszustand (J2) und sagt nichts über die spätere Antwort.
--
-- Alle Spalten NULL-fähig ohne Standardwert; bestehende Zeilen erfüllen alle neuen CHECKs. CHECK und NULL: MariaDB
-- weist eine Zeile nur bei FALSE zurück, UNKNOWN gilt als erfüllt. Jeder Vergleich einer NULL-fähigen Spalte steht
-- deshalb in seinem AND-Zweig hinter "<spalte> IS NOT NULL"; jede Belegung ergibt TRUE oder FALSE (K1).
--
-- Ausführung ausschließlich über scripts/migrate_007_journal_agent_results.sh (Publish-Lock, Preflight unter dem Lock,
-- Sitzungsparameter, Nachprüfung), siehe sql/migrations/README.md. Identisch mit sql/dnsmanager_schema.sql und der
-- Erwartung in web/inc/app/SchemaCheck.php (HISTORY_TABLES_007).

ALTER TABLE zone_publish_targets
    ADD COLUMN zone_protocol ENUM('legacy', 'as2') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN zone_effect ENUM('none', 'unchanged', 'applied', 'partial', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN zone_installed_serial INT UNSIGNED NULL,
    ADD COLUMN zone_installed_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN zone_reload ENUM('ok', 'failed', 'timeout', 'not_started') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN zone_fence_decision ENUM('accept', 'would_reject', 'reject') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN zone_fence_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_protocol ENUM('legacy', 'as2') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_effect ENUM('none', 'unchanged', 'applied', 'partial', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_reload ENUM('ok', 'failed', 'timeout', 'not_started') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_fence_decision ENUM('accept', 'would_reject', 'reject') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_fence_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_verify_state ENUM('verified', 'serial_differs', 'unknown') CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_verify_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_verify_file_serial INT UNSIGNED NULL,
    ADD COLUMN conf_verify_file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN conf_verify_served_serial INT UNSIGNED NULL,
    -- Protokoll nur mit einer Agentenantwort; legacy ohne AS2-Felder; as2 mit effect
    ADD CONSTRAINT chk_zpt_zone_agent CHECK (
        (zone_protocol IS NULL AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL
            AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL)
        OR (zone_protocol IS NOT NULL AND zone_protocol = 'legacy' AND zone_sync IN ('ok', 'rejected', 'unknown')
            AND zone_effect IS NULL AND zone_installed_serial IS NULL AND zone_installed_hash IS NULL
            AND zone_reload IS NULL AND zone_fence_decision IS NULL AND zone_fence_code IS NULL)
        OR (zone_protocol IS NOT NULL AND zone_protocol = 'as2' AND zone_sync IN ('ok', 'rejected', 'unknown')
            AND zone_effect IS NOT NULL)
    ),
    -- installierter Stand (Rohwert der Agentenantwort, nie selbst eine L2-/L3-Bestätigung): Serial und Hash gemeinsam,
    -- nur mit nachgewiesener Dateiübernahme (as2, effect applied/unchanged) und Reload-Bericht des Agenten – so meldet
    -- zone_sync ihn: Reload ok ⇒ HTTP 200 (zone_sync = ok); Reload failed/timeout/not_started ⇒ HTTP 500, nach E113
    -- unknown (R1). Ohne Agentenantwort (Transport), bei effect none/partial/unknown, rejected oder failed nie.
    ADD CONSTRAINT chk_zpt_zone_installed CHECK (
        (zone_installed_serial IS NULL AND zone_installed_hash IS NULL)
        OR (zone_installed_serial IS NOT NULL AND zone_installed_hash IS NOT NULL
            AND zone_installed_hash REGEXP '^[0-9a-f]{64}$'
            AND zone_protocol IS NOT NULL AND zone_protocol = 'as2'
            AND zone_effect IS NOT NULL AND zone_effect IN ('applied', 'unchanged')
            AND zone_reload IS NOT NULL
            AND ((zone_sync = 'ok' AND zone_reload = 'ok')
                OR (zone_sync = 'unknown' AND zone_reload IN ('failed', 'timeout', 'not_started'))))
    ),
    ADD CONSTRAINT chk_zpt_zone_fence CHECK (
        (zone_fence_decision IS NULL AND zone_fence_code IS NULL)
        OR (zone_fence_decision IS NOT NULL AND zone_fence_code IS NULL)
        OR (zone_fence_decision IS NOT NULL AND zone_fence_code IS NOT NULL AND zone_fence_code REGEXP '^[a-z_]{1,32}$')
    ),
    ADD CONSTRAINT chk_zpt_conf_agent CHECK (
        (conf_protocol IS NULL AND conf_effect IS NULL AND conf_reload IS NULL AND conf_fence_decision IS NULL
            AND conf_fence_code IS NULL AND conf_verify_state IS NULL)
        OR (conf_protocol IS NOT NULL AND conf_protocol = 'legacy' AND conf_sync IN ('ok', 'rejected', 'unknown')
            AND conf_effect IS NULL AND conf_reload IS NULL AND conf_fence_decision IS NULL AND conf_fence_code IS NULL
            AND conf_verify_state IS NULL)
        OR (conf_protocol IS NOT NULL AND conf_protocol = 'as2' AND conf_sync IN ('ok', 'rejected', 'unknown')
            AND conf_effect IS NOT NULL)
    ),
    ADD CONSTRAINT chk_zpt_conf_fence CHECK (
        (conf_fence_decision IS NULL AND conf_fence_code IS NULL)
        OR (conf_fence_decision IS NOT NULL AND conf_fence_code IS NULL)
        OR (conf_fence_decision IS NOT NULL AND conf_fence_code IS NOT NULL AND conf_fence_code REGEXP '^[a-z_]{1,32}$')
    ),
    -- L3-Ergebnis (AS2b): verified nur mit gleicher Serial, Datei-Hash und conf_sync = ok; serial_differs mit beiden,
    -- verschiedenen Serials; unknown ohne weitere Bedingung; ohne Zustand keine Werte
    ADD CONSTRAINT chk_zpt_conf_verify CHECK (
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
    ADD CONSTRAINT chk_zpt_conf_verify_fmt CHECK (
        (conf_verify_reason IS NULL OR (conf_verify_reason IS NOT NULL AND conf_verify_reason REGEXP '^[a-z0-9_:]{1,64}$'))
        AND (conf_verify_file_hash IS NULL OR (conf_verify_file_hash IS NOT NULL AND conf_verify_file_hash REGEXP '^[0-9a-f]{64}$'))
    ),
    ALGORITHM=COPY, LOCK=SHARED;
