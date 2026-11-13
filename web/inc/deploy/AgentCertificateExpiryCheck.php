<?php
/**
 * Datei: inc/deploy/AgentCertificateExpiryCheck.php
 * Zweck: Ablaufwarnung für Agent-TLS (S42-E6, Variante A): Restlaufzeit des vom Agenten ausgelieferten
 *        Zertifikats und jedes Zertifikats der Agent-CA-Datei (AGENT_TLS_CA_FILE) prüfen.
 *
 * Verwendung: AgentMonitoringBackend::serverStatus() ergänzt ein Ergebnis 'ok' bzw. 'warning' um die Warnungen
 * (apply()); dadurch erscheint die Warnung im bestehenden Systemstatus (Prüfart server_status), Statuswechsel
 * werden wie üblich in diagnostic_log protokolliert und – bei aktivierter Benachrichtigung – gemeldet.
 *
 * Regeln:
 * - Aktiv nur mit Konfiguration: AGENT_TLS_EXPIRY_WARN_DAYS (> 0; 0 = aus) oder, falls nicht definiert,
 *   AGENT_TLS_CA_FILE gesetzt (dann 30 Tage). Ohne beides: keine Prüfung, Verhalten unverändert.
 * - Ein Fehlerstatus (oder jeder andere Status außer 'ok'/'warning') wird nie verändert oder herabgestuft.
 * - Das Agent-Zertifikat wird über eine eigene, kurze TLS-Verbindung zur Agent-Adresse gelesen (ohne SNI, wie
 *   der Agent-Aufruf per IP-Adresse; ohne Vertrauensprüfung – es wird nur gelesen, nicht vertraut; die
 *   Vertrauensprüfung bleibt beim BindAgentClient). Ist die Restlaufzeit nicht ermittelbar, entsteht eine Warnung.
 * - Eine unlesbare CA-Datei erzeugt hier keine zusätzliche Meldung (der BindAgentClient lehnt dann bereits den
 *   Agent-Aufruf ab, server_status ist 'error').
 * - Keine Geheimnisse (kein Token), keine Datenbank, keine Session, kein HTTP-Kontext.
 */

final class AgentCertificateExpiryCheck
{
    public const DEFAULT_WARN_DAYS = 30;
    private const CONNECT_TIMEOUT = 5;

    private int $warnDays;
    private ?string $caFile;
    /** @var callable(string, int): (int|string) Ablaufzeitpunkt (Unix-Zeit) oder Fehlerbeschreibung */
    private $peerCertValidTo;
    /** @var callable(): int */
    private $clock;

    /**
     * @param callable|null $peerCertValidTo für Tests: fn(string $host, int $port): int|string
     * @param callable|null $clock           für Tests: fn(): int
     */
    public function __construct(int $warnDays, ?string $caFile, ?callable $peerCertValidTo = null, ?callable $clock = null)
    {
        $this->warnDays = $warnDays;
        $this->caFile = ($caFile === null || $caFile === '') ? null : $caFile;
        $this->peerCertValidTo = $peerCertValidTo ?? Closure::fromCallable([self::class, 'fetchPeerCertValidTo']);
        $this->clock = $clock ?? 'time';
    }

    /** Aus der Konfiguration; null = Prüfung nicht aktiv */
    public static function fromConfig(): ?self
    {
        $caFile = defined('AGENT_TLS_CA_FILE') ? (string)AGENT_TLS_CA_FILE : '';
        if (defined('AGENT_TLS_EXPIRY_WARN_DAYS')) {
            $days = (int)AGENT_TLS_EXPIRY_WARN_DAYS;
        } elseif ($caFile !== '') {
            $days = self::DEFAULT_WARN_DAYS;
        } else {
            return null;
        }

        return $days > 0 ? new self($days, $caFile) : null;
    }

    /**
     * Warnungen für den Agenten unter $apiIp (leer = nichts zu melden).
     *
     * @return list<string>
     */
    public function check(?string $apiIp): array
    {
        $now = (int)($this->clock)();
        $limit = $now + $this->warnDays * 86400;
        $warnings = [];

        [$host, $port] = self::target((string)$apiIp);
        $validTo = ($this->peerCertValidTo)($host, $port);
        if (is_string($validTo)) {
            $warnings[] = "Restlaufzeit des Agent-Zertifikats nicht ermittelbar ($validTo)";
        } elseif ($validTo < $limit) {
            $warnings[] = self::describe('Agent-Zertifikat', $validTo, $now);
        }

        if ($this->caFile !== null) {
            foreach (self::caCertificates($this->caFile) as [$subject, $caValidTo]) {
                if ($caValidTo < $limit) {
                    $warnings[] = self::describe("Agent-CA ($subject)", $caValidTo, $now);
                }
            }
        }

        return $warnings;
    }

    /**
     * Ergänzt ein Prüfergebnis um Zertifikatswarnungen: 'ok' → 'warning', 'warning' bleibt 'warning' (Meldung
     * ergänzt). Jeder andere Status (insbesondere 'error') bleibt vollständig unverändert.
     *
     * @param array{status?: mixed, message?: mixed} $result
     * @param list<string> $warnings
     */
    public static function apply(array $result, array $warnings): array
    {
        $status = $result['status'] ?? null;
        if ($warnings === [] || ($status !== 'ok' && $status !== 'warning')) {
            return $result;
        }

        $message = trim(is_scalar($result['message'] ?? null) ? (string)$result['message'] : '');
        $text = 'Zertifikatswarnung: ' . implode('; ', $warnings);

        $result['status'] = 'warning';
        $result['message'] = $message === '' ? $text : "$message – $text";

        return $result;
    }

    private static function describe(string $what, int $validTo, int $now): string
    {
        $date = gmdate('Y-m-d', $validTo);
        if ($validTo <= $now) {
            return "$what ist seit $date (UTC) abgelaufen";
        }

        return "$what läuft am $date (UTC) ab (noch " . intdiv($validTo - $now, 86400) . ' Tage)';
    }

    /**
     * Adresse wie im Agent-Aufruf (https://{api_ip}): IPv4/Name, optional mit Port; IPv6 mit oder ohne Klammern.
     *
     * @return array{0: string, 1: int}
     */
    private static function target(string $apiIp): array
    {
        $a = trim($apiIp);
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d+))?$/', $a, $m)) {
            return ['[' . $m[1] . ']', isset($m[2]) ? (int)$m[2] : 443];
        }
        $colons = substr_count($a, ':');
        if ($colons === 1) {
            [$host, $port] = explode(':', $a, 2);
            return [$host, (int)$port];
        }
        if ($colons > 1) {
            return ['[' . $a . ']', 443];
        }

        return [$a, 443];
    }

    /** @return int|string Ablaufzeitpunkt des ausgelieferten Zertifikats oder Fehlerbeschreibung */
    private static function fetchPeerCertValidTo(string $host, int $port): int|string
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'SNI_enabled' => false,
        ]]);

        // Die Zeitgrenze gilt für Verbindungsaufbau und TLS-Handshake
        $previousTimeout = ini_set('default_socket_timeout', (string)self::CONNECT_TIMEOUT);
        try {
            $errno = 0;
            $errstr = '';
            $stream = @stream_socket_client("ssl://$host:$port", $errno, $errstr, self::CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        } finally {
            if ($previousTimeout !== false) {
                ini_set('default_socket_timeout', $previousTimeout);
            }
        }
        if ($stream === false) {
            return 'Verbindung fehlgeschlagen' . ($errstr !== '' ? ": $errstr" : '');
        }

        $params = stream_context_get_params($stream);
        fclose($stream);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert !== null ? openssl_x509_parse($cert) : false;
        if (!is_array($info) || !isset($info['validTo_time_t'])) {
            return 'Zertifikat nicht lesbar';
        }

        return (int)$info['validTo_time_t'];
    }

    /** @return list<array{0: string, 1: int}> Bezeichnung und Ablaufzeitpunkt je CA-Zertifikat */
    private static function caCertificates(string $caFile): array
    {
        $pem = is_file($caFile) && is_readable($caFile) ? @file_get_contents($caFile) : false;
        if (!is_string($pem) || !preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) {
            return [];
        }

        $result = [];
        foreach ($m[0] as $block) {
            $info = openssl_x509_parse($block);
            if (!is_array($info) || !isset($info['validTo_time_t'])) {
                continue;
            }
            $subject = $info['subject']['CN'] ?? ($info['name'] ?? 'unbekannt');
            $result[] = [is_array($subject) ? implode(', ', $subject) : (string)$subject, (int)$info['validTo_time_t']];
        }

        return $result;
    }
}
