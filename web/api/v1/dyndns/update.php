<?php
/**
 * Datei: update.php
 * Zweck: DynDNS-kompatibler Update-Endpunkt (z. B. für FRITZ!Box oder OpenWRT)
 * Pfad:  /web/api/v1/dyndns/update.php
 *
 * Beschreibung:
 * - Ermöglicht authentifizierten DynDNS-Nutzern das Aktualisieren ihrer A-/AAAA-Einträge
 * - Unterstützt IPv4 (Parameter: myip) und IPv6 (Parameter: myip6)
 * - Authentifizierung erfolgt via HTTP Basic Auth (Username + Passwort)
 * - Verwendet die DynDNS-Konten aus der Tabelle `dyndns_accounts`
 * - Erkennt, ob ein DNS-Update erforderlich ist („good“ vs. „nochg“)
 * - Erstellt oder aktualisiert die passenden DNS-Records mit TTL=300
 * - Löst bei Änderungen sofortige Zonengenerierung und Deployment aus
 * - Rückgabe im bekannten DynDNS-Format (z. B. good 1.2.3.4, nochg 1.2.3.4)
 *
 * Sicherheit:
 * - Unterstützt nur Domains mit `allow_dyndns=1`
 * - Alle kritischen Aktionen erfolgen in einer Datenbanktransaktion
 * - Fehlerhafte oder fehlgeschlagene Deployments werden sauber protokolliert
 */

declare(strict_types=1);
define('IN_APP', true);
// Diese Seite darf auch ohne bestehende Anmeldung aufgerufen werden
define('ALLOW_UNAUTHENTICATED', true);

require_once __DIR__ . '/../../../common.php';
require_once __DIR__ . '/../../../inc/helpers.php';
require_once __DIR__ . '/../../../inc/deploy/publish_single.php';

header('Content-Type: text/plain');

// Authentifizierung per HTTP Basic
$username = $_SERVER['PHP_AUTH_USER'] ?? '';
$password = $_SERVER['PHP_AUTH_PW'] ?? '';

if ($username === '' || $password === '') {
    logFailedLogin('empty');
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="DynDNS"');
    echo "badauth\n";
    exit;
}

// Hostname (FQDN) aus dem Parameter lesen
$full_fqdn = trim($_GET['hostname'] ?? '');
if ($full_fqdn === '' || !str_contains($full_fqdn, '.')) {
    echo "nohost\n";
    exit;
}

// Zerlege FQDN in Subdomain und Zone
$fqdn_parts = explode('.', $full_fqdn);
if (count($fqdn_parts) < 2) {
    echo "nohost\n";
    exit;
}
$subdomain = array_shift($fqdn_parts);
$zone_name = implode('.', $fqdn_parts);

// Account anhand von Hostname (subdomain), Zone, Username suchen
$stmt = $pdo->prepare("
    SELECT d.*, z.id AS zone_id, z.name AS zone_name, z.allow_dyndns
    FROM dyndns_accounts d
    JOIN zones z ON d.zone_id = z.id
    WHERE d.hostname = ? AND z.name = ? AND d.username = ?
");
$stmt->execute([$subdomain, $zone_name, $username]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || !password_verify($password, $account['password_hash'])) {
    logFailedLogin($username);
    echo "badauth\n";
    exit;
}

if ((int)$account['allow_dyndns'] !== 1) {
    echo "nohost\n";
    exit;
}

// IPv4 und IPv6 aus Parametern lesen (optional)
$ip4 = trim($_GET['myip'] ?? '');
$ip6 = trim($_GET['myip6'] ?? '');

$results = [];

try {
    $pdo->beginTransaction();

    foreach (
        [['ip' => $ip4, 'type' => 'A', 'field' => 'current_ipv4'],
         ['ip' => $ip6, 'type' => 'AAAA', 'field' => 'current_ipv6']]
    as $entry) {
        $ip = $entry['ip'];

        // Wenn keine gültige IP: weiter zur nächsten
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }

        $record_type = $entry['type'];
        $current_field = $entry['field'];

        // Existierenden Record suchen
        $stmt = $pdo->prepare("
            SELECT id, content FROM records
            WHERE zone_id = ? AND name = ? AND type = ?
        ");
        $stmt->execute([$account['zone_id'], $account['hostname'], $record_type]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        // Wenn Record existiert und IP unverändert → keine Aktion
        if ($existing && $existing['content'] === $ip) {
            $results[] = "nochg $ip";
            continue;
        }

        // Existierenden Record suchen
        $stmt = $pdo->prepare("
            SELECT id FROM records
            WHERE zone_id = ? AND name = ? AND type = ?
        ");
        $stmt->execute([$account['zone_id'], $account['hostname'], $record_type]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        // Update oder Insert ausführen
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE records SET content = ? WHERE id = ?");
            $stmt->execute([$ip, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO records (zone_id, name, type, content, ttl)
                VALUES (?, ?, ?, ?, 300)
            ");
            $stmt->execute([$account['zone_id'], $account['hostname'], $record_type, $ip]);
        }

        // DynDNS-Account aktualisieren
        $stmt = $pdo->prepare("
            UPDATE dyndns_accounts
            SET $current_field = ?, last_update = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$ip, $account['id']]);

        $results[] = "good $ip";
    }

    // Wenn mindestens ein Record geändert oder neu angelegt wurde (also „good“ vorkommt)
    $wasUpdated = array_filter($results, fn($r) => str_starts_with($r, 'good'));

    if (!empty($wasUpdated)) {
        $rebuild = rebuild_zone_and_flag_if_valid((int)$account['zone_id']);

        if ($rebuild['status'] === 'error') {
            $pdo->rollBack();
            appLog('error', 'event=dyndns_publish_failure stage=validate zone=' . $account['zone_name'] . ' msg="' . addslashes($rebuild['output']) . '"');
            echo "dnserr\n";
            exit;
        }

        $publish = publish_single_zone($pdo, (int)$account['zone_id'], $account['zone_name']);

        if ($publish['status'] === 'error') {
            $pdo->rollBack();
            appLog('error', 'event=dyndns_publish_failure stage=publish zone=' . $account['zone_name'] . ' msg="' . addslashes($publish['output']) . '"');
            echo "dnserr\n";
            exit;
        }

        if ($publish['status'] === 'warning') {
            appLog('warning', 'event=dyndns_publish_warning zone=' . $account['zone_name'] . ' msg="' . addslashes($publish['output']) . '"');
        } else {
            appLog(
                'info',
                sprintf(
                    'event=dyndns_publish_success zone=%s user=%s ip=%s',
                    $account['zone_name'],
                    $username,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                )
            );
        }
    }

    $pdo->commit();
    echo empty($results) ? "nochg\n" : implode("\n", $results) . "\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    appLog('error', sprintf(
        'event=dyndns_update status=exception user="%s" fqdn="%s" ip="%s" message="%s"',
        $username,
        $full_fqdn ?? 'unknown',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        addslashes($e->getMessage())
    ));
    echo "dnserr\n";
    exit;
}
