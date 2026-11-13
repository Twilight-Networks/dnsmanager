<?php
/**
 * Datei: inc/app/update/UpdateCheckService.php
 * Zweck: Fachliche Update-Prüfung (bisher in pages/update.php, Paket 5d).
 *
 * Ruft die Versionsinformationen über einen UpdateFeed ab, wertet die Antwort aus und vergleicht die Version
 * mit der installierten. Auswertung und Vergleich unverändert: Antwort muss ein JSON-Objekt mit nicht leerem
 * Feld „version“ sein; „Update verfügbar“ = version_compare(remote, installiert, '>').
 *
 * Keine Session, kein HTTP-Client, keine Sprachtexte, keine Darstellung. Werte aus der Antwort werden
 * unverändert (ungeprüft) im Kontext zurückgegeben; die Ausgabe-Maskierung bleibt Aufgabe der Seite.
 * Bekannt und bewusst unverändert (S36): keine Schemaprüfung der Link-Felder.
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
     * - update.check.invalid_response: Antwort ist kein JSON-Objekt mit nicht leerem Feld „version“
     */
    public function check(string $currentVersion): ServiceResult
    {
        try {
            $response = $this->feed->fetch();
        } catch (RuntimeException $e) {
            return new ServiceResult(false, [new ServiceMessage('error', 'update.check.connection_failed', ['detail' => $e->getMessage()])]);
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['version'])) {
            return new ServiceResult(false, [new ServiceMessage('error', 'update.check.invalid_response')]);
        }

        return new ServiceResult(true, [new ServiceMessage('success', 'update.check.result', [
            'currentVersion'  => $currentVersion,
            'remoteVersion'   => $data['version'],
            'releaseDate'     => $data['release_date'] ?? null,
            'downloadUrl'     => $data['download_url'] ?? null,
            'changelogUrl'    => $data['changelog_url'] ?? null,
            'updateAvailable' => version_compare($data['version'], $currentVersion, '>'),
        ])]);
    }
}
