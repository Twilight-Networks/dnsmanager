<?php
/**
 * Datei: inc/app/update/UpdateFeed.php
 * Zweck: Schnittstelle zur Quelle der Versionsinformationen für die Update-Prüfung (Paket 5d).
 *
 * Die Implementierung (z. B. CurlUpdateFeed) übernimmt den Netzwerkzugriff; UpdateCheckService bleibt
 * frei von HTTP-Clients und ist mit einer Attrappe prüfbar.
 */

interface UpdateFeed
{
    /**
     * Ruft die Versionsinformationen ab.
     *
     * @return string Antwortkörper (unverändert, erwartet: JSON)
     *
     * @throws RuntimeException wenn keine Antwort empfangen wurde; die Meldung ist die Fehlerbeschreibung
     *                          des Transports (bisher curl_error)
     */
    public function fetch(): string;
}
