<?php
/**
 * Datei: inc/app/update/UpdateCheckService.php
 * Zweck: Fachliche Update-Prüfung (bisher in pages/update.php, Paket 5d).
 *
 * Ruft die Versionsinformationen über einen UpdateFeed ab, wertet die Antwort aus und vergleicht die Version
 * mit der installierten. Auswertung und Vergleich unverändert: Antwort muss ein JSON-Objekt mit nicht leerem
 * Feld „version“ sein; „Update verfügbar“ = version_compare(remote, installiert, '>').
 *
 * Keine Session, kein HTTP-Client, keine Sprachtexte, keine Darstellung. Die Ausgabe-Maskierung bleibt Aufgabe
 * der Seite.
 *
 * Prüfung der Antwortfelder (S36b, SP3b):
 * - version: Zeichenkette aus Ziffern, Buchstaben, „.“, „-“, „+“ (höchstens 32 Zeichen), sonst ungültige Antwort;
 * - release_date: Zeichenkette aus Ziffern, Buchstaben, Leerzeichen, „.“, „-“, „/“, „:“ (höchstens 32 Zeichen),
 *   sonst null (Anzeige „unbekannt“);
 * - download_url, changelog_url: nur gültige absolute HTTPS-URL mit Host und ohne Zugangsdaten (höchstens
 *   2048 Zeichen), sonst null (Link entfällt).
 */

require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/UpdateFeed.php';

final class UpdateCheckService
{
    private UpdateFeed $feed;

    public function __construct(UpdateFeed $feed)
    {
        $this->feed = $feed;
    }

    /**
     * Prüft, ob eine neuere Version verfügbar ist.
     *
     * Meldungen (genau eine):
     * - update.check.result (currentVersion, remoteVersion, releaseDate|null, downloadUrl|null,
     *   changelogUrl|null, updateAvailable)
     * - update.check.connection_failed (detail): keine Antwort erhalten
     * - update.check.invalid_response: Antwort ist kein JSON-Objekt mit gültigem Feld „version“
     */
    public function check(string $currentVersion): ServiceResult
    {
        try {
            $response = $this->feed->fetch();
        } catch (RuntimeException $e) {
            return new ServiceResult(false, [new ServiceMessage('error', 'update.check.connection_failed', ['detail' => $e->getMessage()])]);
        }

        $data = json_decode($response, true);
        $version = is_array($data) ? ($data['version'] ?? null) : null;
        if (!is_string($version) || !preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,31}$/', $version)) {
            return new ServiceResult(false, [new ServiceMessage('error', 'update.check.invalid_response')]);
        }

        return new ServiceResult(true, [new ServiceMessage('success', 'update.check.result', [
            'currentVersion'  => $currentVersion,
            'remoteVersion'   => $version,
            'releaseDate'     => self::releaseDate($data['release_date'] ?? null),
            'downloadUrl'     => self::httpsUrl($data['download_url'] ?? null),
            'changelogUrl'    => self::httpsUrl($data['changelog_url'] ?? null),
            'updateAvailable' => version_compare($version, $currentVersion, '>'),
        ])]);
    }

    /** Veröffentlichungsdatum als kurze, unkritische Zeichenkette, sonst null */
    private static function releaseDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^[0-9A-Za-z][0-9A-Za-z .:/-]{0,31}$#', $value) ? $value : null;
    }

    /** Nur gültige absolute HTTPS-URL mit Host und ohne Zugangsdaten, sonst null */
    private static function httpsUrl(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }
}
