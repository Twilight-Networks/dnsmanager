<?php
/**
 * Datei: inc/api_client.php
 * Zweck: Stellt eine Hilfsfunktion bereit, um JSON-Daten per HTTP POST an eine API zu senden.
 *
 * Beschreibung:
 * - Baut eine HTTP-POST-Anfrage mit Bearer-Token-Authentifizierung auf.
 * - Sendet JSON-Daten an den angegebenen API-Endpunkt.
 * - Erwartet eine JSON-Antwort vom Server.
 * - Gibt ein assoziatives Array mit 'status' und 'message' zurück.
 *
 * Sicherheit:
 * - SSL-Zertifikatsprüfung ist aktiviert.
 * - Token muss manuell übergeben werden.
 *
 * Verwendung:
 * $response = apiPostJson($url, ['action' => 'reload-bind'], $token);
 */

/**
 * Führt einen HTTP POST Request mit JSON-Daten und Bearer-Token aus.
 *
 * @param string $url     Die vollständige URL des API-Endpunkts.
 * @param array  $data    Die zu übermittelnden Daten als assoziatives Array.
 * @param string $token   Der Bearer-Token zur Authentifizierung.
 *
 * @return array          Antwort als assoziatives Array mit 'status' und optional 'message'.
 */
function apiPostJson(string $url, array $data, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFYPEER,
        CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFYHOST,
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if (!empty($error)) {
        return ['status' => 'error', 'message' => $error];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['status' => 'error', 'message' => 'Ungültige Antwort'];
}
