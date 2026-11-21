<?php
/**
 * Datei: inc/deploy/AgentClient.php
 * Zweck: Schnittstelle des HTTP-Clients für die Agent-API (AS4b). Einzige produktive Implementierung ist
 *        BindAgentClient (Verhalten unverändert); die Tests ersetzen sie durch einen Client mit festgelegten Antworten,
 *        damit Verteilung, Fencing-Felder und Laufprüfung ohne zusätzliche Server oder named-Instanzen prüfbar sind.
 */

interface AgentClient
{
    /**
     * @param array<string, mixed> $payload
     * @return array{body: string|false, httpCode: int, curlError: string, curlErrno: int, tlsConfigError: bool}
     */
    public function postJson(?string $apiIp, string $endpoint, string $token, array $payload, ?int $connectTimeout, int $timeout): array;

    /** @return array{body: string|false, httpCode: int, curlError: string, curlErrno: int, tlsConfigError: bool} */
    public function get(?string $apiIp, string $endpoint, string $token, ?int $connectTimeout, int $timeout): array;
}
