<?php
/**
 * Datei: inc/ttl_defaults.php
 * Zweck: Liefert standardisierte TTL-Werte für den Auto-Modus je Record-Typ
 *
 * Diese Datei enthält die Definition der TTL-Vorgabewerte, die bei Auswahl von "Auto"
 * im Frontend automatisch für den jeweiligen DNS-Record-Typ gesetzt werden.
 *
 * Anwendungsbereiche:
 * - record_add_form.php (bei Auswahl "Auto" im TTL-Select-Feld)
 * - record_edit_form.php (für Vergleich zur Anzeige "Auto")
 * - record_add.php / record_update.php (serverseitige Substitution des Auto-Werts)
 * - records.php (Anzeige "Auto" in TTL-Spalte bei Übereinstimmung mit Default)
 *
 * Hinweise:
 * - Die Werte sind an Cloudflare angelehnt (minimale Granularität: 60 Sekunden)
 * - Wird ein unbekannter Record-Typ übergeben, greift ein Fallback-Wert (300 Sekunden)
 */

/**
 * Gibt den vordefinierten TTL-Wert (in Sekunden) für den angegebenen Record-Typ zurück.
 *
 * @param string $type DNS-Record-Typ (z. B. A, MX, CNAME)
 * @return int TTL-Wert in Sekunden für Auto-Modus
 */
function getAutoTTL(string $type): int {
    $defaults = [
        'A'     => 300,     // 5 Minuten
        'AAAA'  => 300,     // 5 Minuten
        'CNAME' => 300,     // 5 Minuten
        'MX'    => 600,     // 10 Minuten
        'NS'    => 3600,    // 1 Stunde
        'TXT'   => 300,     // 5 Minuten
        'SPF'   => 300,     // 5 Minuten
        'DKIM'  => 3600,    // 1 Stunde
        'LOC'   => 3600,    // 1 Stunde
        'CAA'   => 3600,    // 1 Stunde
        'SRV'   => 300,     // 5 Minuten
        'NAPTR' => 300,     // 5 Minuten
        'PTR'   => 86400    // 1 Tag
    ];

    return $defaults[$type] ?? 300;
}
