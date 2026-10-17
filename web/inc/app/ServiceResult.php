<?php
/**
 * Datei: inc/app/ServiceResult.php
 * Zweck: Unveränderlicher Rückgabetyp von Application-Services.
 *
 * - ok:         true, wenn die Operation vollständig erfolgreich war
 * - messages:   ServiceMessage-Objekte in der Reihenfolge ihres Auftretens
 * - lastZoneId: zuletzt ermittelte Zonen-ID der Operation (für Adapter-Redirects)
 *
 * Keine HTTP-Statuscodes, keine Redirect-URLs, keine Sprachschlüssel.
 */

require_once __DIR__ . '/ServiceMessage.php';

final class ServiceResult
{
    /**
     * @param bool             $ok
     * @param ServiceMessage[] $messages
     * @param int|null         $lastZoneId
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $messages = [],
        public readonly ?int $lastZoneId = null
    ) {
    }
}
