<?php
/**
 * Datei: inc/serial.php
 * Zweck: SOA-Serial-Arithmetik nach RFC 1982 (32 Bit ohne Vorzeichen) für den Agenten (AS2a, I-A1,
 *        docs/ARCHITECTURE_HISTORY.md Abschnitt 10 und 5f).
 *
 * - serialParse(): einzige zulässige Eingabeform – JSON-Ganzzahl 0 … 4294967295 oder Dezimalzeichenkette ohne
 *   Vorzeichen und ohne führende Null; alles andere ⇒ null (kein stiller Rückfall).
 * - serialCompare($a, $b): Lage von $a gegenüber $b mit genau vier Ergebnissen:
 *     equal     a = b
 *     newer     a liegt nach RFC 1982 hinter b ((a − b) mod 2³² ∈ [1, 2³¹ − 1])
 *     older     a liegt vor b ((a − b) mod 2³² ∈ [2³¹ + 1, 2³² − 1])
 *     undefined Abstand genau 2³¹ – nie als älter oder neuer gewertet
 *   Ungültige Werte ⇒ InvalidArgumentException.
 * - checkzoneSerial(): Serial aus der Ausgabe von named-checkzone (genau eine Zeile "loaded serial N").
 *
 * Normativ sind Abschnitt 10 und die versionierte Vektordatei tests/rfc1982_vectors.json; jede Implementierung (Agent,
 * später Core in AS5, E110) muss alle Vektoren bestehen.
 *
 * Die Datei definiert ausschließlich Funktionen und Konstanten (kein Seiteneffekt beim Einbinden).
 */

const SERIAL_MAX = 4294967295;
const SERIAL_HALF = 2147483648;

/**
 * Prüft und normalisiert eine Serial.
 *
 * @return int|null 0 … 4294967295 oder null
 */
function serialParse(mixed $v): ?int {
    if (is_int($v)) {
        return $v >= 0 && $v <= SERIAL_MAX ? $v : null;
    }
    if (is_string($v) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $v) === 1) {
        $i = (int)$v;

        return $i <= SERIAL_MAX && (string)$i === $v ? $i : null;
    }

    return null;
}

/**
 * Vergleich nach RFC 1982.
 *
 * @return string newer | older | equal | undefined (Lage von $a gegenüber $b)
 */
function serialCompare(mixed $a, mixed $b): string {
    $x = is_int($a) ? serialParse($a) : null;
    $y = is_int($b) ? serialParse($b) : null;
    if ($x === null || $y === null) {
        throw new InvalidArgumentException('Serial außerhalb von 0 … 4294967295 oder keine Ganzzahl');
    }
    if ($x === $y) {
        return 'equal';
    }
    $d = ($x - $y) & SERIAL_MAX;   // (a − b) mod 2³² (64-Bit-PHP)
    if ($d === SERIAL_HALF) {
        return 'undefined';
    }

    return $d < SERIAL_HALF ? 'newer' : 'older';
}

/**
 * Serial aus der Ausgabe von named-checkzone: genau eine Zeile "zone <name>/IN: loaded serial <N>" mit gültigem N.
 *
 * @return int|null
 */
function checkzoneSerial(string $output): ?int {
    if (preg_match_all('/^zone \S+: loaded serial ([0-9]+)$/m', $output, $m) !== 1) {
        return null;
    }

    return serialParse($m[1][0]);
}
