<?php
/**
 * Datei: inc/app/publish/FenceStatusProvider.php
 * Zweck: Optionale Fähigkeit eines PublishBackend (AS4b, E81): Fencing-Status der aktiven Agenten für den
 *        Restore-Abgleich vor J6/J1 (FenceContext::checkRestore). Backends ohne Agenten (Tests) implementieren sie nicht;
 *        dann entfällt der Abgleich.
 */

interface FenceStatusProvider
{
    /**
     * Fencing-Status je aktivem Server aus system/status (nur lesend).
     *
     * @return array<int, array{name: string, mode: ?string, high: ?int, reachable: bool}>
     *         mode/high nur, wenn der Agent sie meldet (observe/enforce); sonst null
     */
    public function agentFenceStatus(): array;
}
