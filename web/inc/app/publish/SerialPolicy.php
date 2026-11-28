<?php
/**
 * Datei: inc/app/publish/SerialPolicy.php
 * Zweck: Auswahl der nächsten SOA-Serial einer Zone nach RFC 1982 (AS5, E78, D2/D3; docs/ARCHITECTURE_HISTORY.md
 *        Abschnitt 5k). Rein: Eingabe ist die verbindliche Referenzmenge R (SerialReferenceReader) und das Kalenderdatum.
 *
 * Regel (D2, ohne Sortierung, ohne numerisches Maximum):
 * 1. Kandidaten: Kalenderkandidat K = Ymd·100 + 1 sowie (r + 1) mod 2^32 für jedes r ∈ R.
 * 2. Gültig ist ein Kandidat nur, wenn er gegenüber JEDER Referenz ausdrücklich newer ist (SerialNumber::newerThanAll).
 * 3. K gültig ⇒ N = K (Basis calendar). Sonst der gültige Kandidat r + 1 (Basis increment). Es kann höchstens einer
 *    gültig sein (wären r + 1 und t + 1 mit r ≠ t gültig, läge r echt vor t und t echt vor r); findet die Prüfung mehr
 *    als einen, ist das eine Invariantenverletzung (LogicException, fail-closed).
 * 4. Kein gültiger Kandidat ⇒ serial_unresolvable (R liegt in keinem Bogen der Länge ≤ 2^31 − 2, z. B. ein Paar mit
 *    Abstand genau 2^31).
 * 5. Unmittelbar vor der Rückgabe wird N erneut gegen alle Referenzen geprüft.
 * Serial 0 ist gültig und wird nicht übersprungen (D3, Review AS5: R = {2147483649, 4294967295} ⇒ N = 0).
 *
 * Sperren (D22): Liegt für ein Ziel ein nicht aufgelöster Referenzverlust oder eine Lücke vor, gibt es keine Auswahl
 * (serial_reference_lost bzw. serial_reference_gap). Eine Sperre wird nie durch eine Auswahl umgangen.
 * Fencing-Token, Höchststand H und Lauf-IDs spielen keine Rolle (strikt getrennt).
 */

require_once __DIR__ . '/SerialNumber.php';

final class SerialPolicy
{
    public const UNRESOLVABLE = 'serial_unresolvable';
    public const LOST = 'serial_reference_lost';
    public const GAP = 'serial_reference_gap';

    /**
     * Kalenderkandidat K = Ymd·100 + 1 (bisherige Konvention, nur bevorzugter Kandidat).
     *
     * @throws InvalidArgumentException bei ungültigem Datum
     */
    public static function calendarCandidate(string $ymd): int
    {
        if (preg_match('/^[0-9]{8}$/D', $ymd) !== 1 || !checkdate((int)substr($ymd, 4, 2), (int)substr($ymd, 6, 2), (int)substr($ymd, 0, 4))) {
            throw new InvalidArgumentException('Kalenderdatum JJJJMMTT erwartet');
        }
        $k = (int)$ymd * 100 + 1;
        if ($k > SerialNumber::MAX) {
            throw new InvalidArgumentException('Kalenderkandidat außerhalb von 32 Bit');
        }

        return $k;
    }

    /**
     * @param list<int>                                      $refs     verbindliche Referenzmenge R (mindestens die DB-Serial)
     * @param int|null                                       $calendar Kalenderkandidat K (null = keiner, nur im Modell)
     * @param list<array{reason: string, detail?: string}>   $blocks   Sperren (Referenzverlust, Lücke)
     * @return array{ok: bool, serial?: int, basis?: string, reason?: string, detail?: string, refs: list<int>}
     * @throws LogicException bei leerer Referenzmenge oder verletzter Invariante
     */
    public static function decide(array $refs, ?int $calendar, array $blocks = [], int $bits = SerialNumber::BITS): array
    {
        $values = [];
        foreach ($refs as $r) {
            if (!is_int($r) || $r < 0 || $r > SerialNumber::mask($bits)) {
                throw new LogicException('Ungültige Referenz in R');
            }
            $values[$r] = true;
        }
        $values = array_keys($values);
        if ($values === []) {
            throw new LogicException('Leere Referenzmenge (mindestens die DB-Serial erwartet)');
        }
        if ($blocks !== []) {
            $b = $blocks[0];
            return ['ok' => false, 'reason' => (string)$b['reason'], 'detail' => (string)($b['detail'] ?? ''), 'refs' => $values];
        }

        $chosen = null;
        $basis = null;
        if ($calendar !== null && SerialNumber::newerThanAll($calendar, $values, $bits)) {
            [$chosen, $basis] = [$calendar, 'calendar'];
        } else {
            $valid = [];
            foreach ($values as $r) {
                $c = SerialNumber::next($r, $bits);
                if (SerialNumber::newerThanAll($c, $values, $bits)) {
                    $valid[$c] = true;
                }
            }
            if (count($valid) > 1) {
                throw new LogicException('Invariante verletzt: mehr als ein gültiger Nachfolgerkandidat');
            }
            if ($valid !== []) {
                [$chosen, $basis] = [(int)array_key_first($valid), 'increment'];
            }
        }
        if ($chosen === null) {
            return ['ok' => false, 'reason' => self::UNRESOLVABLE,
                'detail' => 'keine Serial ist gegenüber allen ' . count($values) . ' Referenzen nach RFC 1982 neuer', 'refs' => $values];
        }
        // Abschließende ausdrückliche Prüfung gegen jede Referenz
        if (!SerialNumber::newerThanAll($chosen, $values, $bits)) {
            throw new LogicException('Invariante verletzt: gewählte Serial nicht gegenüber allen Referenzen neuer');
        }

        return ['ok' => true, 'serial' => $chosen, 'basis' => $basis, 'refs' => $values];
    }
}
