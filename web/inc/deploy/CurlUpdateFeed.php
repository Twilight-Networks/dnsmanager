<?php
/**
 * Datei: inc/deploy/CurlUpdateFeed.php
 * Zweck: Infrastruktur-Adapter für UpdateFeed: Abruf der Versionsinformationen per HTTPS (Paket 5d).
 *
 * URL und cURL-Optionen unverändert aus pages/update.php übernommen (Zeitgrenze 5 s, Zertifikatsprüfung,
 * User-Agent). Ein fehlgeschlagener Abruf wird als RuntimeException mit der cURL-Fehlerbeschreibung gemeldet.
 */

require_once __DIR__ . '/../app/update/UpdateFeed.php';

final class CurlUpdateFeed implements UpdateFeed
{
    /** Remote-URL zur JSON-Datei mit Versionsinformationen */
    public const DEFAULT_URL = 'https://www.twilight-networks.com/dnsmanager-update/latest.json';

    private string $url;

    public function __construct(string $url = self::DEFAULT_URL)
    {
        $this->url = $url;
    }

    public function fetch(): string
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new RuntimeException(curl_error($ch));
        }

        return (string)$response;
    }
}
