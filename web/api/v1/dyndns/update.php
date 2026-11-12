<?php
/**
 * Datei: update.php
 * Zweck: DynDNS-kompatibler Update-Endpunkt (z. B. für FRITZ!Box oder OpenWRT)
 *
 * Beschreibung:
 * - Authentifiziert per HTTP Basic (Benutzername + Passwort)
 * - Aktualisiert IPv4 (A, Parameter `myip`) und IPv6 (AAAA, Parameter `myip6`) für einen festen Hostnamen
 * - Der betroffene DynDNS-Account definiert Hostname und Zone serverseitig
 * - Rebuild und Deployment erfolgen nur bei Änderungen
 * - Rückgabe im DynDNS-Format: „good <ip>“, „nochg <ip>“, „badauth“ oder „dnserr“
 *
 * Transport-Adapter: Fachlogik (Authentifizierung, Sperren, Planung, Anwendung, Validierung, Publish) liegt im
 * DyndnsUpdateService (Schutzmechanismen PR #37 unverändert). Dieser Endpunkt liest HTTP-Basic-Daten und
 * Parameter, übersetzt die strukturierten ServiceMessages in die bisherigen Logzeilen und ist allein für das
 * DynDNS-Antwortprotokoll (good/nochg/badauth/dnserr) mit den bisherigen HTTP-Status/-Headern zuständig.
 *
 * Paket 4b (S32): Die Änderung wird zuerst festgeschrieben, danach über PublishService::publishZone()
 * veröffentlicht (gemeinsamer Lock und gemeinsame Zonenlogik mit Publish All). Antworten:
 * - Änderung bzw. Wiederholung eines ausstehenden Publish vollständig veröffentlicht → "good <ip>"
 * - Veröffentlichung zurückgestellt, teilweise oder nicht erfolgt → "dnserr" (Änderung bleibt festgeschrieben,
 *   Zone bleibt ausstehend; das nächste Update mit denselben IPs wiederholt den Publish)
 * - keine Änderung und nichts ausstehend → "nochg <ip>"; ohne IP-Parameter → "nochg" (ohne Wiederholung)
 */

declare(strict_types=1);
define('IN_APP', true);
define('ALLOW_UNAUTHENTICATED', true);

require_once __DIR__ . '/../../../common.php';
require_once __DIR__ . '/../../../inc/helpers.php';
require_once __DIR__ . '/../../../inc/app/dyndns/DyndnsUpdateService.php';
require_once __DIR__ . '/../../../inc/deploy/BindZoneBuildBackend.php';
require_once __DIR__ . '/../../../inc/app/publish/PublishService.php';
require_once __DIR__ . '/../../../inc/deploy/LegacyBindPublishBackend.php';

header('Content-Type: text/plain');

// Authentifizierung per HTTP Basic
$username = $_SERVER['PHP_AUTH_USER'] ?? '';
$password = $_SERVER['PHP_AUTH_PW'] ?? '';

$publisher = new PublishService(
    $pdo,
    new LegacyBindPublishBackend($pdo),
    static function (string $level, string $message): void {
        appLog($level, $message);
    }
);
$service = new DyndnsUpdateService($pdo, new BindZoneBuildBackend($pdo), $publisher);
$result = $service->update($username, $password, $_GET['myip'] ?? null, $_GET['myip6'] ?? null);

// Protokollhilfe für abgelehnte bzw. fehlgeschlagene Updates (unverändertes Format)
$logReject = function (string $level, string $reason, string $detail, array $ctx): void {
    appLog($level, sprintf(
        'event=dyndns_update status=rejected reason=%s user="%s" fqdn="%s" ip="%s"%s',
        $reason,
        $ctx['username'],
        $ctx['fqdn'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $detail !== '' ? ' detail="' . addslashes($detail) . '"' : ''
    ));
};

foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'dyndns.api.empty_credentials':
            logFailedLogin('empty');
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="DynDNS"');
            echo "badauth\n";
            exit;

        case 'dyndns.api.auth_failed':
            logFailedLogin($ctx['username']);
            echo "badauth\n";
            exit;

        case 'dyndns.api.rejected':
            $logReject('warning', $ctx['reason'], $ctx['detail'], $ctx);
            // Ungültig gewordene Zugangsdaten → badauth, jede andere Ablehnung → dnserr (wie bisher)
            echo ($ctx['credentialsInvalid'] ? 'badauth' : 'dnserr') . "\n";
            exit;

        case 'dyndns.api.no_ip':
            echo "nochg\n";
            exit;

        case 'dyndns.api.validate_failed':
            appLog('error', 'event=dyndns_publish_failure stage=validate zone=' . $ctx['zoneName'] . ' msg="' . addslashes($ctx['output']) . '"');
            echo "dnserr\n";
            exit;

        case 'dyndns.api.publish_retry':
            appLog('info', sprintf('event=dyndns_publish_retry zone=%s user=%s ip=%s', $ctx['zoneName'], $ctx['username'], $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            break;

        case 'dyndns.api.publish_pending':
            // Änderung festgeschrieben, Veröffentlichung ausstehend (Lock belegt, Teil- oder Fehlverteilung)
            $detail = implode('; ', array_merge($ctx['errors'], $ctx['warnings'], $ctx['exception'] !== null ? [$ctx['exception']] : []));
            appLog('warning', sprintf(
                'event=dyndns_publish_pending zone=%s status=%s user=%s ip=%s retry=%s%s',
                $ctx['zoneName'],
                $ctx['status'],
                $ctx['username'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $ctx['retry'] ? 'yes' : 'no',
                $detail !== '' ? ' msg="' . addslashes($detail) . '"' : ''
            ));
            echo "dnserr\n";
            exit;

        case 'dyndns.api.publish_success':
            appLog(
                'info',
                sprintf('event=dyndns_publish_success zone=%s user=%s ip=%s', $ctx['zoneName'], $ctx['username'], $_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
            break;

        case 'dyndns.api.rollback_failed':
            appLog('error', 'event=dyndns_update status=rollback_failed message="' . addslashes($ctx['message']) . '"');
            break;

        case 'dyndns.api.exception':
            appLog('error', sprintf(
                'event=dyndns_update status=exception user="%s" fqdn="%s" ip="%s" lock_error=%s message="%s"',
                $ctx['username'],
                $ctx['fqdn'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $ctx['lockError'] ? 'yes' : 'no',
                addslashes($ctx['message'])
            ));
            echo "dnserr\n";
            exit;

        case 'dyndns.api.done':
            // Je Adressfamilie (Reihenfolge A, AAAA) "good <ip>" bei Änderung bzw. erfolgreich wiederholtem Publish (F1),
            // sonst "nochg <ip>"; ohne Ergebnis "nochg"
            $lines = array_map(fn(array $r) => (($r['changed'] || $ctx['retry']) ? 'good ' : 'nochg ') . $r['ip'], $ctx['results']);
            echo empty($lines) ? "nochg\n" : implode("\n", $lines) . "\n";
            exit;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            appLog('error', "Unbehandelte ServiceMessage '{$message->code}' (dyndns update)");
            echo "dnserr\n";
            exit;
    }
}

// Ohne abschließende Meldung (sollte nicht vorkommen)
appLog('error', 'DynDNS-Update ohne abschließende ServiceMessage');
echo "dnserr\n";
exit;
