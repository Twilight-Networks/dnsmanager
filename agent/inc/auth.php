<?php
/**
 * Datei: inc/auth.php
 * Zweck: Token-basierte Authentifizierung für REST-Endpunkte
 *
 * Dieses Modul stellt Funktionen bereit, um:
 * - den Bearer-Token aus dem HTTP-Header zu extrahieren
 * - eine Tokenliste aus einer externen Datei zu laden
 * - einen gegebenen Token sicher gegen diese Liste zu validieren
 *
 * Alle Tokens müssen 256-Bit Hex-Strings sein (64 Zeichen)
 * und werden typischerweise aus /etc/dnsmanager/api_tokens.php geladen.
 */

/**
 * Extrahiert den Bearer-Token aus dem Authorization-Header.
 *
 * Erwartetes Format:
 *     Authorization: Bearer <token>
 *
 * @return string|null Der extrahierte Token oder null, wenn nicht vorhanden oder ungültig
 */
function getBearerToken(): ?string {
    $headers = getallheaders();
    if (!isset($headers['Authorization']) || !str_starts_with($headers['Authorization'], 'Bearer ')) {
        return null;
    }
    return trim(substr($headers['Authorization'], 7));
}

/**
 * Lädt gültige API-Tokens aus einer PHP-Datei.
 *
 * Die Datei muss ein Array mit Token-Strings zurückgeben.
 * Ungültige oder nicht ladbare Inhalte ergeben ein leeres Array.
 *
 * @param string $file Pfad zur Token-Datei (z. B. /etc/dnsmanager/api_tokens.php)
 *
 * @return array Liste gültiger Tokens
 */
function loadApiTokens(string $file): array {
    $tokens = @require $file;
    return is_array($tokens) ? $tokens : [];
}

/**
 * Prüft, ob ein gegebener Token in der Liste gültiger Tokens enthalten ist.
 *
 * Die Prüfung erfolgt mit `hash_equals()`, um Timing-Angriffe zu vermeiden.
 *
 * @param string $token  Übermittelter Token (z. B. aus dem Header)
 * @param array  $tokens Liste gültiger Tokens aus der Konfiguration
 *
 * @return bool true, wenn gültig, sonst false
 */
function isTokenValid(string $token, array $tokens): bool {
    foreach ($tokens as $valid) {
        if (hash_equals($valid, $token)) {
            return true;
        }
    }
    return false;
}

/**
 * Beendet die Anfrage mit einem Fehler, wenn kein gültiger Bearer-Token vorhanden ist.
 *
 * @param array $validTokens Liste erlaubter Tokens aus der API-Konfiguration
 */
function requireValidToken(array $validTokens): void {
    $token = getBearerToken();

    if ($token === null) {
        sendJsonResponse(401, ['status' => 'error', 'message' => 'Fehlender Authorization-Header']);
    }

    if (!isTokenValid($token, $validTokens)) {
        sendJsonResponse(403, ['status' => 'error', 'message' => 'Ungültiger API-Token']);
    }
}
