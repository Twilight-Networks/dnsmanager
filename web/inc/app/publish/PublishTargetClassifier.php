<?php
/**
 * Datei: inc/app/publish/PublishTargetClassifier.php
 * Zweck: Einordnung einer Agentenantwort für das Publish-Journal (AP1/H2d, E63, Q3) und Bereinigung von Fehlertexten.
 *
 * Rein: keine Datenbank, kein Netzwerk, kein Logging. Eingabe ist das Ergebnis von BindAgentClient::postJson()
 * (body, httpCode, curlError, curlErrno, tlsConfigError).
 *
 * Die Einordnung ist ausschließlich Journalwissen. Sie ändert NICHT die operative Auswertung der Verteilung
 * (bind_file_distributor.php: HTTP 200 = Erfolg, sonst Fehlertext; changed/bind_dirty, Meldungen und Web-Verträge
 * bleiben unverändert, J8). Das Journal ist strenger: Eine operativ als Erfolg gezählte Antwort ohne gültigen
 * Erfolgs-Body ist im Journal "unknown".
 *
 * Ergebnisse (Kanäle zone_sync, conf_sync und Cleanup):
 * - ok        Agent hat mit HTTP 200 und JSON-Status "success" geantwortet (vor AS: legacy, ohne Serial-, Hash- oder
 *             Reload-Nachweis – kein Beleg für den geladenen Stand, Abschnitt 9 L2/L3).
 * - rejected  definitive Ablehnung durch den Agenten ohne Änderung des Bestands: HTTP ≠ 200 mit Agent-JSON
 *             status = "error", ohne rollback = "failed" (Cleanup: ohne entfernte Dateien).
 * - failed    sicher nicht zugestellt: keine Verbindung (TLS-Konfiguration, Namensauflösung, Verbindungsaufbau,
 *             TLS-Handshake) oder ein lokaler Fehler vor dem Senden (Aufrufer).
 * - unknown   Zustellung möglich, Ergebnis nicht bestimmbar: Zeitüberschreitung (auch beim Verbindungsaufbau, Q3),
 *             Abbruch während der Übertragung, Antwort ohne gültiges Agent-JSON, rollback = "failed".
 * - partial   nur Cleanup: Agent meldet Fehler nach einer Änderung – Dateien entfernt oder zones.conf neu geschrieben
 *             und neu geladen (erkennbar an entfernten Dateien bzw. am Schlüssel "rndc" der Fehlerantwort).
 * Es wird nichts erfunden: Weder Serial noch Datei-Hash noch Reload-Ergebnis werden aus der Antwort abgeleitet.
 *
 * AS2a (E113, docs/ARCHITECTURE_HISTORY.md Abschnitt 5f): Enthält die Agentenantwort das Feld "effect" (Agenten in
 * observe/enforce), richtet sich die Einordnung ausschließlich nach HTTP-Status, Agent-Status und effect (Tabelle in
 * classifyEffect). effect beschreibt nur die nachgewiesene lokale Datei- bzw. Konfigurationswirkung; auch "ok" ist
 * weiterhin kein Nachweis des von BIND geladenen Stands. Ohne effect (Agenten im Modus off) gilt die Einordnung E63
 * unverändert. Die operative Auswertung bleibt unverändert (J8).
 */

final class PublishTargetClassifier
{
    public const KIND_ZONE = 'zone_sync';
    public const KIND_CONF = 'conf_sync';
    public const KIND_CLEANUP = 'zone_cleanup';

    /** Höchstlänge bereinigter Fehlertexte (Zeichen) */
    public const DETAIL_MAX = 500;

    /**
     * curl-Fehler, bei denen die Anfrage nachweislich nicht beim Agenten ankam (kein HTTP-Request gesendet):
     * Protokoll/URL/Initialisierung, Namensauflösung, Verbindungsaufbau abgelehnt, TLS-Handshake und Zertifikatsprüfung.
     * CURLE_OPERATION_TIMEDOUT (28) gehört bewusst NICHT dazu (Q3: Zeitüberschreitung ⇒ unknown).
     */
    private const CURL_NOT_SENT = [1, 2, 3, 5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /**
     * @param array{body?: string|false, httpCode?: int, curlError?: string, curlErrno?: int, tlsConfigError?: bool} $r
     * @return array{status: string, httpStatus: ?int, errorCode: ?string, errorDetail: ?string,
     *               removedConf: ?int, removedZoneFiles: ?int}
     */
    public static function classify(string $kind, array $r): array
    {
        if (!in_array($kind, [self::KIND_ZONE, self::KIND_CONF, self::KIND_CLEANUP], true)) {
            throw new InvalidArgumentException("Unbekannter Agent-Endpunkt '$kind'");
        }
        $http = (int)($r['httpCode'] ?? 0);
        $httpStatus = ($http >= 100 && $http <= 599) ? $http : null;
        $curlError = (string)($r['curlError'] ?? '');
        $errno = (int)($r['curlErrno'] ?? 0);

        if (!empty($r['tlsConfigError'])) {
            return self::result('failed', null, 'tls_config', $curlError);
        }
        if ($curlError !== '' || $errno !== 0) {
            if (in_array($errno, self::CURL_NOT_SENT, true)) {
                return self::result('failed', null, 'not_delivered', "curl $errno: $curlError");
            }
            $code = $errno === 28 ? 'timeout' : 'transport_unknown';
            return self::result('unknown', $httpStatus, $code, "curl $errno: $curlError");
        }

        $body = $r['body'] ?? false;
        $json = is_string($body) ? json_decode($body, true) : null;
        $agentJson = is_array($json) && isset($json['status']) && is_string($json['status']);
        $message = $agentJson ? self::messageOf($json) : '';

        if ($agentJson && array_key_exists('effect', $json)) {
            return self::classifyEffect($kind, $http, $httpStatus, $json, $message);
        }

        if ($http === 200) {
            if ($agentJson && $json['status'] === 'success') {
                if ($kind === self::KIND_CLEANUP) {
                    $removedConf = self::count($json['removed_conf'] ?? null);
                    $removedZone = self::count($json['removed_zone_files'] ?? null);
                    if ($removedConf === null || $removedZone === null) {
                        return self::result('unknown', 200, 'invalid_response', 'Cleanup-Antwort ohne Liste der entfernten Dateien');
                    }
                    return self::result('ok', 200, null, null, $removedConf, $removedZone);
                }
                return self::result('ok', 200, null, null);
            }
            return self::result('unknown', 200, 'invalid_response', $agentJson ? $message : 'HTTP 200 ohne gültige Agent-Antwort');
        }

        if (!$agentJson || $json['status'] !== 'error') {
            return self::result('unknown', $httpStatus, 'invalid_response', $httpStatus === null ? 'keine HTTP-Antwort' : "HTTP $http ohne gültige Agent-Antwort");
        }
        if (($json['rollback'] ?? null) === 'failed') {
            return self::result('unknown', $httpStatus, 'rollback_failed', $message);
        }
        if ($kind === self::KIND_CLEANUP) {
            $removedConf = self::count($json['removed_conf'] ?? []);
            $removedZone = self::count($json['removed_zone_files'] ?? []);
            // Praxistest H2d: Der Fehlerpfad des Agenten NACH dem Neuschreiben von zones.conf und dem Reload (z. B. nicht
            // löschbare Zonendatei) liefert ggf. leere Entfernt-Listen, trägt aber als einzige Fehlerantwort den Schlüssel
            // "rndc". Der Bestand ist dann verändert – partial, nie rejected.
            if (($removedConf ?? 0) > 0 || ($removedZone ?? 0) > 0 || array_key_exists('rndc', $json)) {
                return self::result('partial', $httpStatus, 'cleanup_incomplete', $message, $removedConf ?? 0, $removedZone ?? 0);
            }
        }

        return self::result('rejected', $httpStatus, 'agent_error', $message);
    }

    /** Zulässige Werte von effect (AS2a) */
    private const EFFECTS = ['none', 'unchanged', 'applied', 'partial', 'unknown'];

    /**
     * E113: Einordnung nach HTTP-Status × Agent-Status × effect.
     *
     * | HTTP  | Agent-Status | effect             | zone_sync/conf_sync | Cleanup  |
     * | 200   | success      | applied, unchanged | ok                  | ok       |
     * | 200   | success      | partial            | unknown (Widerspr.) | partial  |
     * | 200   | success      | none, unknown      | unknown (Widerspr.) | unknown  |
     * | ≠ 200 | error        | none               | rejected            | rejected |
     * | ≠ 200 | error        | unchanged          | unknown (Widerspr.) | unknown  |
     * | ≠ 200 | error        | applied, partial   | unknown             | partial  |
     * | ≠ 200 | error        | unknown            | unknown             | unknown  |
     * | jeder | jeder        | unbekannter Wert   | unknown             | unknown  |
     * | sonst (200 mit error, ≠ 200 mit success)    | unknown             | unknown  |
     */
    private static function classifyEffect(string $kind, int $http, ?int $httpStatus, array $json, string $message): array
    {
        $effect = $json['effect'];
        $cleanup = $kind === self::KIND_CLEANUP;
        $removedConf = $cleanup ? self::count($json['removed_conf'] ?? null) : null;
        $removedZone = $cleanup ? self::count($json['removed_zone_files'] ?? null) : null;
        $agentCode = is_string($json['code'] ?? null) && preg_match('/^[a-z_]{1,32}$/D', $json['code']) === 1 ? $json['code'] : null;

        if (!is_string($effect) || !in_array($effect, self::EFFECTS, true)) {
            return self::result('unknown', $httpStatus, 'invalid_effect', $message !== '' ? $message : 'unbekannte Wirkungsangabe');
        }
        $success = $http === 200 && $json['status'] === 'success';
        $error = $http !== 200 && $json['status'] === 'error';
        if (!$success && !$error) {
            return self::result('unknown', $httpStatus, 'invalid_response', $message !== '' ? $message : "HTTP $http mit Agent-Status {$json['status']}");
        }

        if ($success) {
            if ($effect === 'applied' || $effect === 'unchanged') {
                if ($cleanup && ($removedConf === null || $removedZone === null)) {
                    return self::result('unknown', 200, 'invalid_response', 'Cleanup-Antwort ohne Liste der entfernten Dateien');
                }
                return self::result('ok', 200, null, null, $removedConf, $removedZone);
            }
            if ($effect === 'partial' && $cleanup) {
                return self::result('partial', 200, 'cleanup_incomplete', $message !== '' ? $message : 'Cleanup teilweise', $removedConf ?? 0, $removedZone ?? 0);
            }
            return self::result('unknown', 200, 'effect_contradiction', "Erfolg mit effect=$effect");
        }

        return match ($effect) {
            'none'      => self::result('rejected', $httpStatus, $agentCode ?? 'agent_error', $message),
            'unchanged' => self::result('unknown', $httpStatus, 'effect_contradiction', $message !== '' ? $message : 'Fehler mit effect=unchanged'),
            'applied', 'partial' => $cleanup
                ? self::result('partial', $httpStatus, $agentCode ?? 'cleanup_incomplete', $message, $removedConf ?? 0, $removedZone ?? 0)
                : self::result('unknown', $httpStatus, $agentCode ?? 'effect_applied', $message),
            default     => self::result('unknown', $httpStatus, $agentCode ?? 'effect_unknown', $message),
        };
    }

    /** Ergebnis eines lokalen Fehlers vor dem Senden (Aufrufer: fehlende Datei, Hash-Abweichung, Ziel verändert …) */
    public static function localFailure(string $errorCode, string $detail): array
    {
        if (preg_match('/^[a-z_]{1,32}$/D', $errorCode) !== 1) {
            throw new InvalidArgumentException('Ungültiger Fehlercode');
        }

        return self::result('failed', null, $errorCode, $detail);
    }

    /**
     * Bereinigt einen Fehlertext für das Journal: Steuerzeichen entfernt, Leerraum zusammengefasst, tokenähnliche
     * Zeichenfolgen (≥ 32 Zeichen aus Buchstaben, Ziffern, +/=_-) geschwärzt, gültiges UTF-8, höchstens 500 Zeichen.
     */
    public static function sanitize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = mb_scrub($text, 'UTF-8');
        $t = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t);
        $t = (string)preg_replace('/[A-Za-z0-9+\/=_\-]{32,}/', '[…]', $t);
        $t = trim((string)preg_replace('/\s+/u', ' ', $t));
        if ($t === '') {
            return null;
        }
        if (mb_strlen($t, 'UTF-8') > self::DETAIL_MAX) {
            $t = mb_substr($t, 0, self::DETAIL_MAX - 1, 'UTF-8') . '…';
        }

        return $t;
    }

    private static function messageOf(array $json): string
    {
        $parts = [];
        foreach (['message', 'check_output'] as $k) {
            if (isset($json[$k]) && is_string($json[$k]) && trim($json[$k]) !== '') {
                $parts[] = trim($json[$k]);
            }
        }
        if (isset($json['errors']) && is_array($json['errors'])) {
            $parts[] = implode('; ', array_map('strval', array_filter($json['errors'], 'is_scalar')));
        }

        return implode(' – ', $parts);
    }

    private static function count(mixed $list): ?int
    {
        return is_array($list) ? count($list) : null;
    }

    private static function result(string $status, ?int $http, ?string $code, ?string $detail, ?int $removedConf = null, ?int $removedZone = null): array
    {
        return [
            'status'           => $status,
            'httpStatus'       => $http,
            'errorCode'        => $code,
            'errorDetail'      => $code === null ? null : (self::sanitize($detail) ?? $code),
            'removedConf'      => $removedConf,
            'removedZoneFiles' => $removedZone,
        ];
    }
}
