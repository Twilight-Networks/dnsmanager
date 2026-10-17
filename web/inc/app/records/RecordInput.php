<?php
/**
 * Datei: inc/app/records/RecordInput.php
 * Zweck: Unveränderlicher DTO für bereits normalisierte Record-Daten.
 *
 * Wird von Adaptern (Web, später z. B. MCP) erzeugt und an den RecordService
 * übergeben. Enthält keine POST-Daten, keine Session, keine Übersetzung und
 * keine Validierung; die fachliche Prüfung erfolgt im RecordService.
 */

final class RecordInput
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $content,
        public readonly int $ttl,
        public readonly bool $isDkim = false
    ) {
    }
}
