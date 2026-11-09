<?php
/**
 * Datei: inc/app/zones/ZoneInput.php
 * Zweck: Eingabedaten für ZoneService::createZone().
 *
 * Die Felder entsprechen den bisherigen Formularfeldern und werden als ROHWERTE übergeben (null =
 * Feld nicht vorhanden). Validierung und Normalisierung (trim, Integer-Umwandlung, Namensbildung)
 * erfolgen ausschließlich im Service – exakt wie bisher in actions/zone_add.php –, damit Prüfreihenfolge
 * und Ergebnisse unverändert bleiben (Legacy-Brücke, E17). Nicht-Web-Aufrufer übergeben Strings bzw.
 * Integer.
 *
 * - type:           'forward' | 'reverse_ipv4' | 'reverse_ipv6' (andere Werte gelten wie bisher als forward)
 * - zonePrefix:     Zonenname (forward) bzw. Präfix ohne Reverse-Suffix
 * - prefixLength:   nur Reverse
 * - ttl, soa*:      wie Formular
 * - soaDomain:      nur Reverse: Domain des primären Nameservers (SOA-NS = Master + Domain)
 * - allowDyndns:    bereits ausgewertet (Formular: allow_dyndns === '1')
 * - serverIds:      Liste der Server-IDs (Rohwert; Struktur prüft der Service)
 * - masterServerId: Master-Server-ID (Rohwert)
 */

final class ZoneInput
{
    public function __construct(
        public readonly mixed $type = null,
        public readonly mixed $zonePrefix = null,
        public readonly mixed $prefixLength = null,
        public readonly mixed $ttl = null,
        public readonly mixed $description = null,
        public readonly mixed $soaMail = null,
        public readonly mixed $soaRefresh = null,
        public readonly mixed $soaRetry = null,
        public readonly mixed $soaExpire = null,
        public readonly mixed $soaMinimum = null,
        public readonly mixed $soaDomain = null,
        public readonly bool $allowDyndns = false,
        public readonly mixed $serverIds = null,
        public readonly mixed $masterServerId = null
    ) {
    }

    /**
     * Formularförmige Sicht für validateZoneInput() (nur gesetzte Felder; null = nicht vorhanden).
     *
     * @return array<string, mixed>
     */
    public function toFormArray(): array
    {
        return array_filter([
            'type'          => $this->type,
            'zone_prefix'   => $this->zonePrefix,
            'prefix_length' => $this->prefixLength,
            'ttl'           => $this->ttl,
            'description'   => $this->description,
            'soa_mail'      => $this->soaMail,
            'soa_refresh'   => $this->soaRefresh,
            'soa_retry'     => $this->soaRetry,
            'soa_expire'    => $this->soaExpire,
            'soa_minimum'   => $this->soaMinimum,
            'soa_domain'    => $this->soaDomain,
        ], static fn ($v): bool => $v !== null);
    }
}
