<?php
/**
 * Datei: inc/app/zones/ZoneUpdateInput.php
 * Zweck: Eingabedaten für ZoneService::updateZone().
 *
 * Rohwerte wie im bisherigen Formular (null = Feld nicht vorhanden). Validierung und Normalisierung
 * erfolgen ausschließlich im Service – exakt wie bisher in actions/zone_update.php (Legacy-Brücke, E17).
 *
 * - soaNs:          nur bei Reverse-Zonen ohne Serveränderung wirksam (sonst wie bisher ignoriert)
 * - allowDyndns:    bereits ausgewertet (Formular: Feld vorhanden)
 * - serverIds, masterServerId: nur für admin wirksam (Serverzuweisung); für zoneadmin ignoriert
 * - type:           nur für validateZoneInput() durchgereicht (wie bisher $_POST['type']; das Formular sendet es nicht)
 * - expectedVersion: Bearbeitungsstand, auf dem die Eingabe beruht (ZoneReadService::getZone(), Feld
 *                    "editVersion"; S21). Pflicht für jeden Aufrufkanal: fehlend, formal ungültig oder
 *                    veraltet → "zone.update.conflict", keine Änderung. Es gibt keinen Modus ohne Prüfung.
 */

final class ZoneUpdateInput
{
    public function __construct(
        public readonly mixed $ttl = null,
        public readonly mixed $prefixLength = null,
        public readonly mixed $description = null,
        public readonly bool $allowDyndns = false,
        public readonly mixed $soaMail = null,
        public readonly mixed $soaNs = null,
        public readonly mixed $soaRefresh = null,
        public readonly mixed $soaRetry = null,
        public readonly mixed $soaExpire = null,
        public readonly mixed $soaMinimum = null,
        public readonly mixed $serverIds = null,
        public readonly mixed $masterServerId = null,
        public readonly mixed $type = null,
        public readonly mixed $expectedVersion = null
    ) {
    }

    /**
     * Formularförmige Sicht für validateZoneInput() (nur gesetzte Felder).
     *
     * @return array<string, mixed>
     */
    public function toFormArray(): array
    {
        return array_filter([
            'type'          => $this->type,
            'ttl'           => $this->ttl,
            'prefix_length' => $this->prefixLength,
            'description'   => $this->description,
            'soa_mail'      => $this->soaMail,
            'soa_ns'        => $this->soaNs,
            'soa_refresh'   => $this->soaRefresh,
            'soa_retry'     => $this->soaRetry,
            'soa_expire'    => $this->soaExpire,
            'soa_minimum'   => $this->soaMinimum,
        ], static fn ($v): bool => $v !== null);
    }
}
