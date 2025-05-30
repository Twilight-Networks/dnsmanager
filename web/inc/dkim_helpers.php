<?php
/**
 * dkim_helpers.php
 *
 * Hilfsfunktionen zum Verarbeiten einer hochgeladenen DKIM-Konfigurationsdatei.
 * Extrahiert den Selector, die Domain und den Public Key aus einer OpenDKIM .conf-Datei.
 */

/**
 * Verarbeitet eine hochgeladene DKIM-Datei und extrahiert den Selector, die Domain und den Public Key.
 *
 * @param string $filePath Der Pfad zur hochgeladenen DKIM-Datei.
 * @return array|false Ein Array mit den DKIM-Daten oder false, wenn die Datei nicht verarbeitet werden kann.
 */
function parseDKIMFile(string $filePath) {
    if (!file_exists($filePath)) {
        return false;
    }

    $content = file_get_contents($filePath);
    $selector = extractSelector($content);
    $key = extractPublicKey($content);

    if (!$selector || !$key) {
        return false;
    }

    // Optional zusätzliche Parameter extrahieren
    $params = extractDKIMParameters($content);

    return array_merge([
        'selector' => $selector,
        'domain'   => $domain,
        'key'      => $key
    ], $params);
}

/**
 * Extrahiert den DKIM-Selector aus der Konfigurationsdatei.
 * Der Selector ist der Name des DKIM-Records vor dem "_domainkey".
 *
 * @param string $content Der Inhalt der Konfigurationsdatei.
 * @return string Der DKIM-Selector.
 */
function extractSelector($content) {
    // Extrahiert den Selector direkt vor "_domainkey"
    if (preg_match('/^([^\s]+?)\._domainkey/', $content, $matches)) {
        return $matches[1];
    }
    return ''; // Gibt einen leeren String zurück, wenn kein Selector gefunden wurde.
}

/**
 * Extrahiert optionale DKIM-Parameter wie Schlüsseltyp (k=), Hash-Algorithmen (h=),
 * Flags (t=) und Servicetyp (s=) aus einer gegebenen Zeichenkette.
 *
 * Diese Funktion nimmt den Inhalt eines DKIM-TXT-Records entgegen, extrahiert
 * zunächst alle in Anführungszeichen eingeschlossenen Segmente, fügt sie zu einem
 * zusammenhängenden String zusammen und sucht dann nach typischen DKIM-Schlüsselwertpaaren.
 *
 * Beispielinhalt (aus BIND-Zone):
 * "v=DKIM1; k=rsa; h=sha256; t=y; s=email; p=..."
 *
 * @param string $content Der vollständige TXT-Record-Inhalt mit ggf. gechunkten Quoted-Strings.
 * @return array Ein assoziatives Array mit den extrahierten Parametern:
 *               - key_type       (k=)
 *               - hash_algos     (h=)
 *               - flags          (t=)
 *               - service_type   (s=)
 *               Nur vorhandene Parameter werden zurückgegeben.
 */
function extractDKIMParameters(string $content): array {
    // Extrahiere alle "quoted strings" innerhalb des TXT-Eintrags
    if (!preg_match_all('/"([^"]+)"/', $content, $matches)) {
        return [];
    }

    // Füge alle Teile zu einem vollständigen DKIM-Headerstring zusammen
    $joined = implode('', $matches[1]);

    $params = [];

    // Schlüsseltyp (z. B. k=rsa)
    if (preg_match('/\bk=(rsa|ed25519)/i', $joined, $m)) {
        $params['key_type'] = strtolower($m[1]);
    }

    // Hash-Algorithmen (z. B. h=sha256:sha1)
    if (preg_match('/\bh=([a-z0-9:+\-]+)/i', $joined, $m)) {
        $params['hash_algos'] = $m[1];
    }

    // Flags (z. B. t=y)
    if (preg_match('/\bt=([a-z]+)/i', $joined, $m)) {
        $params['flags'] = $m[1];
    }

    // Service-Typ (z. B. s=email) – selten verwendet
    if (preg_match('/\bs=([a-z]+)/i', $joined, $m)) {
        $params['service_type'] = $m[1];
    }

    return $params;
}

/**
 * Extrahiert den Public Key aus der DKIM-Konfigurationsdatei.
 * Der Public Key befindet sich im "p="-Feld und muss korrekt extrahiert werden.
 *
 * @param string $content Der Inhalt der Konfigurationsdatei
 * @return string Der Public Key
 */
function extractPublicKey(string $content): string {
    if (!preg_match_all('/"([^"]+)"/', $content, $matches)) {
        return '';
    }

    $joined = implode('', $matches[1]);

    // Nur den reinen Base64-Key nach "p=" extrahieren
    if (preg_match('/p=([A-Za-z0-9+\/=]+)/', $joined, $match)) {
        return trim($match[1]);
    }

    return '';
}
?>
