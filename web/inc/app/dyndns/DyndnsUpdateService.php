<?php
/**
 * Datei: inc/app/dyndns/DyndnsUpdateService.php
 * Zweck: DynDNS-Update (FRITZ!Box/OpenWRT-kompatibel) als webunabhängiger Ablauf: authentifizieren, sperren,
 *        planen, anwenden, validieren, veröffentlichen.
 *
 * Fachlogik unverändert aus api/v1/dyndns/update.php übernommen (PR #37: strenge IP-Prüfung je Familie,
 * Authentifizierung ohne Transaktion, Zonensperre als erste Abfrage der Transaktion, Account unter Sperre neu
 * laden und abgleichen, Records der Zone sperren, Kollisions- und Zuordnungsprüfung, bedingte Updates,
 * Rebuild und Einzel-Publish nur bei Änderung, Publish vor dem Commit).
 *
 * Keine Session, kein HTTP, kein Logging, keine Protokollausgabe. Das Antwortprotokoll (good/nochg/badauth/
 * dnserr), HTTP-Status/-Header und die Logzeilen bleiben im Endpunkt. Ergebnis: ServiceResult mit
 * ServiceMessages "dyndns.api.<ergebnis>" in der bisherigen Reihenfolge der Logzeilen; die letzte Message
 * bestimmt die Antwort:
 * - empty_credentials, auth_failed (username)                 → badauth
 * - rejected (response, reason, detail)                       → response (badauth|dnserr)
 * - no_ip                                                     → nochg
 * - validate_failed / publish_failed (zoneName, output)       → dnserr
 * - exception (lockError, message)                            → dnserr
 * - done (results: string[])                                  → Ergebniszeilen bzw. nochg
 * Zwischenmeldungen: publish_warning, publish_success (zoneName), rollback_failed (message).
 * Kontext ab erfolgreicher Authentifizierung zusätzlich: username, fqdn.
 *
 * Bekannte, bewusst unveränderte Grenzen (Architektur-Inventar S31, S32): kein Dummy-password_verify bei
 * unbekanntem Benutzer; Verteilung vor dem Commit ohne Publish-Advisory-Lock.
 */

declare(strict_types=1);

require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../zones/ZoneBuildBackend.php';
require_once __DIR__ . '/../../dyndns_guard.php';
require_once __DIR__ . '/DyndnsPublishBackend.php';

final class DyndnsUpdateService
{
    private PDO $pdo;
    private ZoneBuildBackend $build;
    private DyndnsPublishBackend $publish;

    public function __construct(PDO $pdo, ZoneBuildBackend $build, DyndnsPublishBackend $publish)
    {
        $this->pdo = $pdo;
        $this->build = $build;
        $this->publish = $publish;
    }

    /**
     * @param string $username Benutzername (HTTP Basic)
     * @param string $password Passwort (HTTP Basic)
     * @param mixed  $rawIp4   Rohwert des Parameters myip (string, array, null …)
     * @param mixed  $rawIp6   Rohwert des Parameters myip6
     */
    public function update(string $username, string $password, mixed $rawIp4, mixed $rawIp6): ServiceResult
    {
        if ($username === '' || $password === '') {
            return self::final('error', 'dyndns.api.empty_credentials', []);
        }

        // Account laden (ein Benutzer = ein DynDNS-Eintrag) – ohne Transaktion, nur zur Authentifizierung
        $stmt = $this->pdo->prepare("
            SELECT d.*, z.id AS zone_id, z.name AS zone_name
            FROM dyndns_accounts d
            JOIN zones z ON d.zone_id = z.id
            WHERE d.username = ?
        ");
        $stmt->execute([$username]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            return self::final('audit', 'dyndns.api.auth_failed', ['username' => $username]);
        }

        $account_id = (int)$account['id'];
        $hostname   = (string)$account['hostname'];
        $zone_id    = (int)$account['zone_id'];
        $zone_name  = (string)$account['zone_name'];
        $ctx        = ['username' => $username, 'fqdn' => $hostname . '.' . $zone_name];

        // IP-Parameter streng prüfen: myip nur IPv4, myip6 nur IPv6
        $ip4 = dyndnsParseApiIp($rawIp4, 'A');
        $ip6 = dyndnsParseApiIp($rawIp6, 'AAAA');

        if ($ip4['status'] === 'invalid' || $ip6['status'] === 'invalid') {
            return self::final('error', 'dyndns.api.rejected', $ctx + ['response' => 'dnserr', 'reason' => 'invalid_ip',
                'detail' => 'myip_status=' . $ip4['status'] . ' myip6_status=' . $ip6['status']]);
        }

        $requested = ['A' => $ip4['ip'], 'AAAA' => $ip6['ip']];

        // Keine IP angegeben → wie bisher nochg, ohne Datenbankänderung
        if ($requested['A'] === null && $requested['AAAA'] === null) {
            return self::final('success', 'dyndns.api.no_ip', $ctx);
        }

        $m = [];
        $results = [];

        try {
            $this->pdo->beginTransaction();

            // 1. Zone sperren – erste Abfrage der Transaktion
            $zones = dyndnsLockZones($this->pdo, [$zone_id]);
            $zone  = $zones[$zone_id] ?? null;
            if ($zone === null) {
                throw new DyndnsApiReject('dnserr', 'zone_not_found', "zone_id={$zone_id}");
            }

            // 2. Account unter Sperre neu laden und mit den authentifizierten Daten abgleichen
            $stmt = $this->pdo->prepare("
                SELECT id, username, password_hash, zone_id, hostname, current_ipv4, current_ipv6
                FROM dyndns_accounts
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$account_id]);
            $locked = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

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
            if ((string)$zone['name'] !== $zone_name) {
                throw new DyndnsApiReject('dnserr', 'zone_changed', 'vorher=' . $zone_name . ' jetzt=' . (string)$zone['name']);
            }

            // 3. Records der Zone sperren, bevor einfache SELECTs den Lese-Snapshot festlegen
            $zoneRecords = dyndnsLockZoneAddressRecords($this->pdo, $zone_id);

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
                dyndnsFindHostnameConflicts($this->pdo, $zone_id, (string)$zone['name'], $hostname, $account_id),
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
                    dyndnsApplyApiRecordUpdate($this->pdo, $zone_id, $step['record'], $step['ip']);
                } elseif ($step['action'] === 'insert') {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO records (zone_id, name, type, content, ttl)
                        VALUES (?, ?, ?, ?, 300)
                    ");
                    $stmt->execute([$zone_id, $hostname, $type, $step['ip']]);
                } else {
                    throw new LogicException('Unbekannte Aktion: ' . $step['action']);
                }

                $stmt = $this->pdo->prepare("
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
                $rebuild = $this->build->validateAndFlag($zone_id);
                if ($rebuild['status'] === 'error') {
                    $this->pdo->rollBack();
                    $m[] = new ServiceMessage('error', 'dyndns.api.validate_failed', $ctx + ['zoneName' => $zone_name, 'output' => $rebuild['output']]);
                    return new ServiceResult(false, $m);
                }

                $publish = $this->publish->publishZone($zone_id, $zone_name);
                if ($publish['status'] === 'error') {
                    $this->pdo->rollBack();
                    $m[] = new ServiceMessage('error', 'dyndns.api.publish_failed', $ctx + ['zoneName' => $zone_name, 'output' => $publish['output']]);
                    return new ServiceResult(false, $m);
                }

                if ($publish['status'] === 'warning') {
                    $m[] = new ServiceMessage('warning', 'dyndns.api.publish_warning', $ctx + ['zoneName' => $zone_name, 'output' => $publish['output']]);
                } else {
                    $m[] = new ServiceMessage('success', 'dyndns.api.publish_success', $ctx + ['zoneName' => $zone_name]);
                }
            }

            // Hinweis: Bei einem Fehler ab hier ist die Zone bereits verteilt; ein Rollback betrifft nur die Datenbank.
            $this->pdo->commit();
            $m[] = new ServiceMessage('success', 'dyndns.api.done', $ctx + ['results' => $results]);

            return new ServiceResult(true, $m);
        } catch (Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable $rollbackError) {
                $m[] = new ServiceMessage('error', 'dyndns.api.rollback_failed', $ctx + ['message' => $rollbackError->getMessage()]);
            }

            if ($e instanceof DyndnsApiReject) {
                // detail wie bisher: vollständige Ausnahmenachricht ("<reason>[: <detail>]")
                $m[] = new ServiceMessage('error', 'dyndns.api.rejected', $ctx + ['response' => $e->response, 'reason' => $e->reason,
                    'detail' => $e->getMessage()]);
            } else {
                $m[] = new ServiceMessage('error', 'dyndns.api.exception', $ctx + ['lockError' => dyndnsIsLockError($e), 'message' => $e->getMessage()]);
            }

            return new ServiceResult(false, $m);
        }
    }

    private static function final(string $kind, string $code, array $context): ServiceResult
    {
        return new ServiceResult($kind === 'success', [new ServiceMessage($kind, $code, $context)]);
    }
}
