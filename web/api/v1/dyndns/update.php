<?php
/**
 * Datei: update.php
 * Zweck: DynDNS-kompatibler Update-Endpunkt (z. B. für FRITZ!Box oder OpenWRT)
 *
 * Beschreibung:
 * - Authentifiziert per HTTP Basic (Benutzername + Passwort)
 * - Aktualisiert IPv4 (A) und IPv6 (AAAA) Records für einen festen Hostnamen
 * - Der betroffene DynDNS-Account definiert Hostname und Zone serverseitig
 * - Rebuild und Deployment erfolgen nur bei Änderungen
 * - Rückgabe im DynDNS-Format: „good <ip>“ oder „nochg <ip>“
 *
 */

declare(strict_types=1);
define('IN_APP', true);
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

// Account laden (ein Benutzer = ein DynDNS-Eintrag)
$stmt = $pdo->prepare("
    SELECT d.*, z.id AS zone_id, z.name AS zone_name
    FROM dyndns_accounts d
    JOIN zones z ON d.zone_id = z.id
    WHERE d.username = ?
");
$stmt->execute([$username]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || !password_verify($password, $account['password_hash'])) {
    logFailedLogin($username);
    echo "badauth\n";
    exit;
}

$hostname = $account['hostname'];
$zone_id = (int)$account['zone_id'];
$zone_name = $account['zone_name'];
$fqdn = $hostname . '.' . $zone_name;

$ip4 = trim($_GET['myip'] ?? '');
$ip6 = trim($_GET['myip6'] ?? '');

// Ergebnisse sammeln: „good <ip>“ für Änderungen, „nochg <ip>“ bei identischen Werten
$results = [];

try {
    $pdo->beginTransaction();

    foreach (
        [['ip' => $ip4, 'type' => 'A', 'field' => 'current_ipv4'],
         ['ip' => $ip6, 'type' => 'AAAA', 'field' => 'current_ipv6']]
    as $entry) {
        $ip = $entry['ip'];
        $record_type = $entry['type'];
        $current_field = $entry['field'];

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }

        $stmt = $pdo->prepare("
            SELECT id, content FROM records
            WHERE zone_id = ? AND name = ? AND type = ?
        ");
        $stmt->execute([$zone_id, $hostname, $record_type]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        // Wenn bereits ein Record vorhanden ist und sich die IP nicht geändert hat → überspringen
        if ($existing && $existing['content'] === $ip) {
            $results[] = "nochg $ip";
            continue;
        }

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE records SET content = ? WHERE id = ?");
            $stmt->execute([$ip, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO records (zone_id, name, type, content, ttl)
                VALUES (?, ?, ?, ?, 300)
            ");
            $stmt->execute([$zone_id, $hostname, $record_type, $ip]);
        }

        $stmt = $pdo->prepare("
            UPDATE dyndns_accounts
            SET $current_field = ?, last_update = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$ip, $account['id']]);

        $results[] = "good $ip";
    }

    // Nur wenn mindestens ein Record geändert wurde → Rebuild + Deployment
    if (array_filter($results, fn($r) => str_starts_with($r, 'good'))) {
        $rebuild = rebuild_zone_and_flag_if_valid($zone_id);
        if ($rebuild['status'] === 'error') {
            $pdo->rollBack();
            appLog('error', 'event=dyndns_publish_failure stage=validate zone=' . $zone_name . ' msg="' . addslashes($rebuild['output']) . '"');
            echo "dnserr\n";
            exit;
        }

        $publish = publish_single_zone($pdo, $zone_id, $zone_name);
        if ($publish['status'] === 'error') {
            $pdo->rollBack();
            appLog('error', 'event=dyndns_publish_failure stage=publish zone=' . $zone_name . ' msg="' . addslashes($publish['output']) . '"');
            echo "dnserr\n";
            exit;
        }

        if ($publish['status'] === 'warning') {
            appLog('warning', 'event=dyndns_publish_warning zone=' . $zone_name . ' msg="' . addslashes($publish['output']) . '"');
        } else {
            appLog(
                'info',
                sprintf('event=dyndns_publish_success zone=%s user=%s ip=%s', $zone_name, $username, $_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
        }
    }

    $pdo->commit();
    echo empty($results) ? "nochg\n" : implode("\n", $results) . "\n";

// Rollback bei unerwartetem Fehler, Logging & DynDNS-kompatible Fehlermeldung
} catch (Throwable $e) {
    $pdo->rollBack();
    appLog('error', sprintf(
        'event=dyndns_update status=exception user="%s" fqdn="%s" ip="%s" message="%s"',
        $username,
        $fqdn,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        addslashes($e->getMessage())
    ));
    echo "dnserr\n";
    exit;
}
