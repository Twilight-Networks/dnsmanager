<?php
/**
 * Datei: inc/app/publish/RunLockLost.php
 * Zweck: Verlust der Laufberechtigung (AS4b, E83): Publish-Lock oder laufeigene Sperre wird von der Verbindung des Laufs
 *        nicht mehr gehalten. Vor einem Agent-Aufruf festgestellt, beendet sie den Lauf fail-closed: kein weiterer
 *        Request, der PublishService endet mit 'exception' (bestehender Fehlerpfad, Flags 2 → 1).
 */

final class RunLockLost extends RuntimeException
{
}
