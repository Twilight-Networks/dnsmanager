<?php
/**
 * Datei: soa_serial.php
 * Zweck: Generierung des nächsten SOA-Serials für eine DNS-Zone.
 *
 * Details:
 * - Das SOA-Serial wird basierend auf dem aktuellen Tagesdatum erzeugt.
 * - Falls das bestehende Serial kleiner ist als das heutige Datum, wird neu mit heutigem Datum begonnen (Format: YYYYMMDDnn).
 * - Ansonsten wird das bestehende Serial einfach hochgezählt.
 *
 * Typisches Format eines Serialnumbers:
 *   - YYYYMMDDnn (z.B. 2025042801 für 28. April 2025, erste Änderung des Tages)
 *
 * Nutzung:
 * - Wird beim Anlegen oder Aktualisieren von Zonendateien verwendet.
 *
 * @param int $current Aktuelles Serial (z.B. aus bestehender Zonendatei).
 * @return int Neues gültiges Serial.
 */
function generate_next_serial(int $current): int {
    // Heutiges Datum im Format YYYYMMDD00
    $today = intval(date('Ymd') . '00');

    // Neues Serial bestimmen: entweder neuer Tagesstart oder Inkrementierung
    return $current < $today ? $today + 1 : $current + 1;
}
?>
