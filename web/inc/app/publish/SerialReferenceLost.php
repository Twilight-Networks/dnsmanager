<?php
/**
 * Datei: inc/app/publish/SerialReferenceLost.php
 * Zweck: Sicherheitsrelevanter Persistenzfehler einer Serial-Referenz (AS5, D22; docs/ARCHITECTURE_HISTORY.md Abschnitt 5k).
 *
 * Wird geworfen, wenn ein Write-ahead (intent) vor einem zone_sync nicht gespeichert werden kann (der Aufruf unterbleibt)
 * oder wenn ein gemeldeter Serial-Rohwert bzw. eine Beobachtung nicht gespeichert werden konnte. Der Publish-Lauf endet
 * dann mit der bestehenden Ausnahme-Behandlung (exception, übernommene Flags 2 → 1, kein weiterer Agent-Aufruf).
 */

final class SerialReferenceLost extends RuntimeException
{
}
