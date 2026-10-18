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
 * Schutzmechanismen (PR #37):
 * - `myip` ausschließlich IPv4, `myip6` ausschließlich IPv6; ungültige, nichtskalare oder
 *   familienfremde Werte → dnserr ohne Änderung. Ohne IP-Parameter → nochg (wie bisher).
 * - Authentifizierung ohne Transaktion; danach Zonensperre als erste Abfrage der Transaktion,
 *   Account unter Sperre neu laden und mit den authentifizierten Daten abgleichen.
 * - Zone muss DynDNS erlauben und eine Forward-Zone sein, gespeicherter Hostname muss gültig sein.
 * - Kollisionsprüfung wie in der Accountverwaltung (Doppelbelegung, Glue/Systemrecords,
 *   Servernamen, CNAME, Delegation, DNAME).
 * - Strenge Record-Zuordnung je Adressfamilie (dyndnsPlanApiRecordChanges); jede unklare Lage
 *   lehnt die gesamte Anfrage ab (atomar, keine Teilupdates).
 * - Records der Zone werden vor der Entscheidung gesperrt; Updates erfolgen bedingt auf die
 *   geprüften alten Werte.
 *
 * Grenze: Die Verteilung an die Nameserver (publish_single_zone) erfolgt vor dem Commit und kann
 * durch ein späteres Datenbank-Rollback nicht rückgängig gemacht werden.
 */

declare(strict_types=1);
define('IN_APP', true);
define('ALLOW_UNAUTHENTICATED', true);

require_once __DIR__ . '/../../../common.php';
require_once __DIR__ . '/../../../inc/helpers.php';
require_once __DIR__ . '/../../../inc/deploy/publish_single.php';
require_once __DIR__ . '/../../../inc/dyndns_guard.php';

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

// Account laden (ein Benutzer = ein DynDNS-Eintrag) – ohne Transaktion, nur zur Authentifizierung
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

$account_id = (int)$account['id'];
$hostname   = (string)$account['hostname'];
$zone_id    = (int)$account['zone_id'];
$zone_name  = (string)$account['zone_name'];
$fqdn       = $hostname . '.' . $zone_name;

// Protokollhilfe für abgelehnte bzw. fehlgeschlagene Updates
$logReject = function (string $level, string $reason, string $detail = '') use ($username, $fqdn): void {
    appLog($level, sprintf(
        'event=dyndns_update status=rejected reason=%s user="%s" fqdn="%s" ip="%s"%s',
        $reason,
        $username,
        $fqdn,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $detail !== '' ? ' detail="' . addslashes($detail) . '"' : ''
    ));
};

// IP-Parameter streng prüfen: myip nur IPv4, myip6 nur IPv6
$ip4 = dyndnsParseApiIp($_GET['myip'] ?? null, 'A');
$ip6 = dyndnsParseApiIp($_GET['myip6'] ?? null, 'AAAA');

if ($ip4['status'] === 'invalid' || $ip6['status'] === 'invalid') {
    $logReject('warning', 'invalid_ip', 'myip_status=' . $ip4['status'] . ' myip6_status=' . $ip6['status']);
    echo "dnserr\n";
    exit;
}

$requested = ['A' => $ip4['ip'], 'AAAA' => $ip6['ip']];

// Keine IP angegeben → wie bisher nochg, ohne Datenbankänderung
if ($requested['A'] === null && $requested['AAAA'] === null) {
    echo "nochg\n";
    exit;
}

// Ergebnisse sammeln: „good <ip>“ für Änderungen, „nochg <ip>“ bei identischen Werten
$results = [];

try {
    $pdo->beginTransaction();

    // 1. Zone sperren – erste Abfrage der Transaktion (serialisiert mit API, Accountverwaltung und Publish)
    $zones = dyndnsLockZones($pdo, [$zone_id]);
    $zone  = $zones[$zone_id] ?? null;
    if ($zone === null) {
        throw new DyndnsApiReject('dnserr', 'zone_not_found', "zone_id={$zone_id}");
    }

    // 2. Account unter Sperre neu laden und mit den authentifizierten Daten abgleichen
    $stmt = $pdo->prepare("
        SELECT id, username, password_hash, zone_id, hostname, current_ipv4, current_ipv6
        FROM dyndns_accounts
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$account_id]);
    $locked = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$locked) {
        throw new DyndnsApiReject('badauth', 'account_deleted');
    }
    if ((string)$locked['username'] !== (string)$account['username']
        || (string)$locked['password_hash'] !== (string)$account['password_hash']) {
        throw new DyndnsApiReject('badauth', 'credentials_changed');
    }
    if ((int)$locked['zone_id'] !== $zone_id || (string)$locked['hostname'] !== $hostname) {
        throw new DyndnsApiReject('dnserr', 'account_changed');
    }
    // Zonenname unter Sperre mit dem authentifizierten Vorabzustand abgleichen (wird für das Publishing verwendet)
    if ((string)$zone['name'] !== $zone_name) {
        throw new DyndnsApiReject('dnserr', 'zone_changed', 'vorher=' . $zone_name . ' jetzt=' . (string)$zone['name']);
    }

    // 3. Records der Zone sperren, bevor einfache SELECTs den Lese-Snapshot festlegen
    $zoneRecords = dyndnsLockZoneAddressRecords($pdo, $zone_id);

    // 4. Zone und gespeicherten Hostnamen prüfen
    $zoneError = dyndnsZoneAssignmentError($zone);
    if ($zoneError !== null) {
        throw new DyndnsApiReject('dnserr', strtolower($zoneError));
    }

    $hostErrors = validateDyndnsHostname($hostname, (string)$zone['name']);
    if (!empty($hostErrors)) {
        throw new DyndnsApiReject('dnserr', 'invalid_stored_hostname', $hostErrors[0]);
    }

    // 5. Kollisionen (A/AAAA-Records selbst bewertet die Record-Zuordnung)
    $conflicts = array_values(array_filter(
        dyndnsFindHostnameConflicts($pdo, $zone_id, (string)$zone['name'], $hostname, $account_id),
        fn($c) => $c['code'] !== 'DYNDNS_CONFLICT_ADDRESS_RECORD'
    ));
    if (!empty($conflicts)) {
        throw new DyndnsApiReject(
            'dnserr',
            strtolower($conflicts[0]['code']),
            implode('; ', array_map(fn($c) => $c['code'] . ' (' . $c['detail'] . ')', $conflicts))
        );
    }

    // 6. Strenge Record-Zuordnung für beide Adressfamilien
    $plan = dyndnsPlanApiRecordChanges(
        $zoneRecords,
        (string)$zone['name'],
        $hostname,
        [
            'A'    => $locked['current_ipv4'] !== null ? (string)$locked['current_ipv4'] : null,
            'AAAA' => $locked['current_ipv6'] !== null ? (string)$locked['current_ipv6'] : null,
        ],
        $requested
    );
    if ($plan['reject'] !== null) {
        throw new DyndnsApiReject('dnserr', $plan['reject'], $plan['detail']);
    }

    // 7. Ausschließlich geprüfte Änderungen schreiben
    $fields = ['A' => 'current_ipv4', 'AAAA' => 'current_ipv6'];
    $changed = false;

    foreach (['A', 'AAAA'] as $type) {
        $step = $plan['types'][$type];

        if ($step['action'] === 'none') {
            continue;
        }

        if ($step['action'] === 'nochg') {
            $results[] = "nochg {$step['ip']}";
            continue;
        }

        if ($step['action'] === 'update') {
            dyndnsApplyApiRecordUpdate($pdo, $zone_id, $step['record'], $step['ip']);
        } elseif ($step['action'] === 'insert') {
            $stmt = $pdo->prepare("
                INSERT INTO records (zone_id, name, type, content, ttl)
                VALUES (?, ?, ?, ?, 300)
            ");
            $stmt->execute([$zone_id, $hostname, $type, $step['ip']]);
        } else {
            throw new LogicException('Unbekannte Aktion: ' . $step['action']);
        }

        $stmt = $pdo->prepare("
            UPDATE dyndns_accounts
            SET {$fields[$type]} = ?, last_update = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$step['ip'], $account_id]);

        $results[] = "good {$step['ip']}";
        $changed = true;
    }

    // 8. Nur wenn mindestens ein Record geändert wurde → Rebuild + Deployment
    if ($changed) {
        $rebuild = rebuild_zone_and_flag_if_valid($zone_id, $pdo);
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

    // Hinweis: Bei einem Fehler ab hier ist die Zone bereits verteilt; ein Rollback betrifft nur die Datenbank.
    $pdo->commit();
    echo empty($results) ? "nochg\n" : implode("\n", $results) . "\n";

// Rollback bei Ablehnung oder unerwartetem Fehler, Logging & DynDNS-kompatible Fehlermeldung
} catch (Throwable $e) {
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $rollbackError) {
        appLog('error', 'event=dyndns_update status=rollback_failed message="' . addslashes($rollbackError->getMessage()) . '"');
    }

    if ($e instanceof DyndnsApiReject) {
        $logReject('warning', $e->reason, $e->getMessage());
        echo $e->response . "\n";
        exit;
    }

    appLog('error', sprintf(
        'event=dyndns_update status=exception user="%s" fqdn="%s" ip="%s" lock_error=%s message="%s"',
        $username,
        $fqdn,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        dyndnsIsLockError($e) ? 'yes' : 'no',
        addslashes($e->getMessage())
    ));
    echo "dnserr\n";
    exit;
}
