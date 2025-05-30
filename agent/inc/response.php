<?php
/**
 * Datei: inc/response.php
 * Zweck: Einheitliche JSON-Antworten für REST-Endpunkte
 *
 * Diese Hilfsfunktion kapselt das Setzen des HTTP-Statuscodes,
 * das Setzen des Content-Type-Headers und das JSON-Encoding der Nutzdaten.
 *
 * Vorteile:
 * - Konsistente Antwortstruktur
 * - Korrekte HTTP-Statuscodes
 * - Sofortiger Abbruch nach Antwortausgabe
 *
 * Typische Verwendung in API-Endpunkten:
 *     sendJsonResponse(200, ['status' => 'success', 'message' => 'OK']);
 */

/**
 * Sendet eine standardisierte JSON-Antwort und beendet die Ausführung.
 *
 * @param int   $statusCode HTTP-Statuscode (z. B. 200, 400, 403, 500)
 * @param array $data       Array mit Antwortdaten (z. B. 'status', 'message', 'data')
 *
 * @return never            Gibt niemals zurück (exit nach Ausgabe)
 */
function sendJsonResponse(int $statusCode, array $data): never {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
