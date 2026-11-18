<?php
/**
 * Datei: inc/app/publish/PublishHashContract.php
 * Zweck: Hash-Verträge des Publish-Journals (AP1/H2d, E60; docs/ARCHITECTURE_HISTORY.md Abschnitte 3, 5d und 10).
 *
 * Rein: keine Datenbank, keine Dateien, keine Session, kein Logging.
 *
 * Drei Hashes je Publish-Versuch, alle SHA-256 (hex, klein):
 * - content_hash (Vertrag zs1): Hash des kanonischen Snapshots (ZoneStateSnapshot) – berechnet aus GENAU den Zeilen,
 *   die der Generator in derselben Transaktion unter der Zonensperre rendert; nie aus head_hash übernommen.
 * - file_hash: Hash der exakten Bytes der erzeugten und gesendeten Zonendatei (einschließlich Serial).
 * - render_hash (Vertrag rh1): Hash der Zonendatei mit maskierter Serial. Definiert als die Ausgabe des Generators
 *   (zone_file_render) mit dem Serial-Platzhalter "@SERIAL@" anstelle der Dezimalzahl. Gleichwertig und aus einer
 *   vorliegenden Datei reproduzierbar: In den Datei-Bytes wird das einzige Vorkommen der SOA-Serial-Zeile
 *   "\n" + 12 Leerzeichen + <Serial> + "   ; Serial\n" durch dieselbe Zeile mit "@SERIAL@" ersetzt (maskSerial()).
 *   Kommt die Zeile nicht genau einmal vor, ist die Datei nicht nach rh1 maskierbar (Fehler).
 * - conf_hash: Hash der je Server gesendeten Konfigurationsdatei (Bytes).
 *
 * render_hash ist nötig, weil dieselbe Revision je Generator-Stand verschiedene Dateien ergeben kann (z. B. RRset-TTL,
 * PR #95). Gleicher render_hash bei abweichender Serial ist "inhaltsgleich", nie "synchron" (Abschnitt 10).
 *
 * Eine Änderung der Maskierung, des Platzhalters oder des Zeilenformats erfordert eine neue Vertragskennung.
 */

final class PublishHashContract
{
    /** Kennung des Render-Vertrags */
    public const RENDER_CONTRACT = 'rh1';

    /** Platzhalter der Serial im maskierten Rendering */
    public const SERIAL_PLACEHOLDER = '@SERIAL@';

    /** Einrückung der SOA-Serial-Zeile im Generator (zone_file_render) */
    private const SERIAL_LINE_PREFIX = "\n            ";
    private const SERIAL_LINE_SUFFIX = "   ; Serial\n";

    public static function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /** Größter zulässiger Serial-Wert (32 Bit ohne Vorzeichen, RFC 1982) */
    public const SERIAL_MAX = 4294967295;

    /**
     * Maskiert die Serial in den Bytes einer Zonendatei nach rh1.
     *
     * @throws InvalidArgumentException bei ungültiger Serial oder wenn die Serial-Zeile nicht genau einmal vorkommt
     */
    public static function maskSerial(string $fileBytes, int $serial): string
    {
        if ($serial < 0 || $serial > self::SERIAL_MAX) {
            throw new InvalidArgumentException('rh1: Serial außerhalb von 0 … 2^32-1');
        }
        $needle = self::SERIAL_LINE_PREFIX . $serial . self::SERIAL_LINE_SUFFIX;
        if (substr_count($fileBytes, $needle) !== 1) {
            throw new InvalidArgumentException('rh1: SOA-Serial-Zeile nicht genau einmal vorhanden');
        }

        return str_replace($needle, self::SERIAL_LINE_PREFIX . self::SERIAL_PLACEHOLDER . self::SERIAL_LINE_SUFFIX, $fileBytes);
    }

    /** render_hash einer vorliegenden Zonendatei mit bekannter Serial */
    public static function renderHashFromFile(string $fileBytes, int $serial): string
    {
        return self::sha256(self::maskSerial($fileBytes, $serial));
    }

    public static function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }
}
