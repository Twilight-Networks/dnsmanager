<?php
/**
 * Datei: inc/app/monitoring/MonitoringNotifier.php
 * Zweck: Schnittstelle des MonitoringService zum Versand einer Benachrichtigung (bisher sendMail()).
 *
 * Ergebnis: ['status' => 'ok'|'error', 'errors' => string[], 'output' => string]
 * Implementierung: PhpMailerNotifier (inc/deploy).
 */
interface MonitoringNotifier
{
    public function send(string $subject, string $body): array;
}
