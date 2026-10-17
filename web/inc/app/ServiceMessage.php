<?php
/**
 * Datei: inc/app/ServiceMessage.php
 * Zweck: Unveränderlicher Transporttyp für strukturierte Application-Meldungen.
 *
 * - kind:    success | warning | error | audit
 * - code:    stabiler Application-/Domain-Code (z. B. "record.delete.success"),
 *            KEIN Sprachschlüssel
 * - context: Rohdaten zur Meldung (unescaped, ohne Übersetzung)
 *
 * Keine Session, kein HTTP, keine Toasts, kein Logging.
 * Die Übersetzung in UI-Texte erfolgt ausschließlich im jeweiligen Adapter.
 */

final class ServiceMessage
{
    public function __construct(
        public readonly string $kind,
        public readonly string $code,
        public readonly array $context = []
    ) {
    }
}
