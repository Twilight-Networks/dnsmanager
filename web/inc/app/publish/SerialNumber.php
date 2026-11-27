<?php
/**
 * Datei: inc/app/publish/SerialNumber.php
 * Zweck: SOA-Serial-Arithmetik nach RFC 1982 im Core (AS5, E110; docs/ARCHITECTURE_HISTORY.md Abschnitte 10, 5f, 5k).
 *
 * Rein (keine Datenbank, kein Netzwerk). Fachlich identisch mit agent/inc/serial.php (E102); beide Implementierungen
 * müssen die normative Vektordatei tests/rfc1982_vectors.json (rfc1982-v1) bestehen.
 *
 * - parse(): einzige zulässige Eingabeform – Ganzzahl 0 … 4294967295 oder Dezimalzeichenkette ohne Vorzeichen und ohne
 *   führende Null; alles andere ⇒ null (kein stiller Rückfall). 0 ist eine gültige Serial (Review AS5, D3).
 * - compare($a, $b): Lage von $a gegenüber $b mit genau vier Ergebnissen: equal, newer ((a − b) mod 2^n ∈ [1, 2^(n−1) − 1]),
 *   older ((a − b) mod 2^n ∈ [2^(n−1) + 1, 2^n − 1]), undefined (Abstand genau 2^(n−1); nie älter oder neuer).
 * - newerThanAll(): ausdrückliche Prüfung eines Kandidaten gegen JEDE Referenz (kein Vergleich als Sortierfunktion).
 *
 * Die Bitbreite n ist nur für den Brute-Force-Abgleich im reduzierten Modell (Tests) wählbar; produktiv gilt immer 32.
 */

final class SerialNumber
{
    public const BITS = 32;
    public const MAX = 4294967295;
    public const HALF = 2147483648;

    public const NEWER = 'newer';
    public const OLDER = 'older';
    public const EQUAL = 'equal';
    public const UNDEFINED = 'undefined';

    /** Prüft und normalisiert eine Serial (32 Bit); null = ungültig */
    public static function parse(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 && $v <= self::MAX ? $v : null;
        }
        if (is_string($v) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) === 1) {
            $i = (int)$v;

            return $i <= self::MAX && (string)$i === $v ? $i : null;
        }

        return null;
    }

    /**
     * Vergleich nach RFC 1982.
     *
     * @return string newer | older | equal | undefined (Lage von $a gegenüber $b)
     * @throws InvalidArgumentException bei ungültigen Werten oder Bitbreite
     */
    public static function compare(mixed $a, mixed $b, int $bits = self::BITS): string
    {
        $max = self::mask($bits);
        // Nur echte Ganzzahlen (keine Zeichenketten, Gleitkommazahlen oder NULL; Vektoren compare_invalid)
        if (!is_int($a) || !is_int($b) || $a < 0 || $a > $max || $b < 0 || $b > $max) {
            throw new InvalidArgumentException("Serial außerhalb von 0 … $max oder keine Ganzzahl");
        }
        if ($a === $b) {
            return self::EQUAL;
        }
        $half = 1 << ($bits - 1);
        $d = ($a - $b) & $max;   // (a − b) mod 2^n (64-Bit-PHP)
        if ($d === $half) {
            return self::UNDEFINED;
        }

        return $d < $half ? self::NEWER : self::OLDER;
    }

    /**
     * true, wenn $candidate gegenüber jeder Referenz nach RFC 1982 newer ist (leere Menge ⇒ true).
     *
     * @param iterable<int> $refs
     */
    public static function newerThanAll(int $candidate, iterable $refs, int $bits = self::BITS): bool
    {
        foreach ($refs as $r) {
            if (self::compare($candidate, $r, $bits) !== self::NEWER) {
                return false;
            }
        }

        return true;
    }

    /** Nachfolger (r + 1) mod 2^n */
    public static function next(int $r, int $bits = self::BITS): int
    {
        return ($r + 1) & self::mask($bits);
    }

    /** Größter Wert der Bitbreite (2^n − 1) */
    public static function mask(int $bits): int
    {
        if ($bits < 2 || $bits > self::BITS) {
            throw new InvalidArgumentException('Bitbreite 2 … 32');
        }

        return $bits === 32 ? self::MAX : (1 << $bits) - 1;
    }
}
