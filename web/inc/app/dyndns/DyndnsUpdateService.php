<?php
/**
 * Datei: inc/app/dyndns/DyndnsUpdateService.php
 * Zweck: DynDNS-Update (FRITZ!Box/OpenWRT-kompatibel) als webunabhängiger Ablauf: authentifizieren, sperren,
 *        planen, anwenden, validieren, veröffentlichen.
 *
 * Fachlogik aus api/v1/dyndns/update.php (PR #37: strenge IP-Prüfung je Familie, Authentifizierung ohne
 * Transaktion, Zonensperre als erste Abfrage der Transaktion, Account unter Sperre neu laden und abgleichen,
 * Records der Zone sperren, Kollisions- und Zuordnungsprüfung, bedingte Updates).
 *
 * Paket 4b (S32) – Reihenfolge:
 * 1. Transaktion: prüfen, Records/Account schreiben, Zonenprüfung und changed = 1 (ZoneBuildBackend), Commit.
 *    Ein Prüffehler oder eine Ablehnung verwirft die Änderung vollständig (Rollback).
 * 2. Danach Veröffentlichung über PublishService::publishZone() (gemeinsame Zonenlogik und Lock mit Publish
 *    All; Serial vor der Verteilung festgeschrieben). Ein Verteilungsfehler rollt die festgeschriebene Änderung
 *    nicht zurück; die Zone bleibt ausstehend (changed = 1).
 * 3. Ohne Änderung (alle Adressfamilien unverändert) wird ein noch ausstehender Publish der Zone erneut
 *    versucht (F1/F2: die vollständige Zone einschließlich zwischenzeitlicher Admin-Änderungen). Ohne IP-Parameter
 *    bleibt es bei no_ip ohne Wiederholung (F3).
 *
 * Keine Session, kein HTTP, kein Logging und kein DynDNS-Antwortprotokoll: Der Service liefert strukturierte
 * fachliche Ergebnisse; die Übersetzung in Protokollantworten, HTTP-Status/-Header und Logzeilen erfolgt
 * ausschließlich im Endpunkt api/v1/dyndns/update.php. Ergebnis: ServiceResult mit ServiceMessages
 * "dyndns.api.<ergebnis>"; die letzte Message ist das Endergebnis:
 * - empty_credentials                     Zugangsdaten fehlen
 * - auth_failed (username)                Anmeldung fehlgeschlagen
 * - rejected (credentialsInvalid, reason, detail)
 *                                         Anfrage abgelehnt; credentialsInvalid = true, wenn die Zugangsdaten
 *                                         unter Sperre nicht mehr gültig sind (Account gelöscht bzw.
 *                                         Benutzername/Passwort geändert), sonst false
 * - no_ip                                 keine IP-Adresse übergeben, keine Änderung, kein Publish
 * - validate_failed (zoneName, output)    Zonenprüfung fehlgeschlagen, Rollback
 * - exception (lockError, message)        unerwarteter Fehler vor dem Commit, Rollback
 * - publish_pending (zoneName, status, errors, warnings, exception, retry)
 *                                         Änderung festgeschrieben bzw. unverändert, Veröffentlichung aber
 *                                         zurückgestellt (Lock belegt), teilweise oder nicht erfolgt; Zone bleibt
 *                                         ausstehend. status not_found: Zone inzwischen gelöscht (kein Erfolg)
 * - done (results, retry)                 abgeschlossen; results = Liste je Adressfamilie in Reihenfolge A, AAAA:
 *                                         ['type' => 'A'|'AAAA', 'ip' => string, 'changed' => bool]; retry = true,
 *                                         wenn ein ausstehender Publish ohne Änderung erfolgreich wiederholt wurde
 * Zwischenmeldungen: publish_retry (zoneName), publish_success (zoneName, status published|already_published,
 * keptPending, retry), rollback_failed (message).
 * Kontext ab erfolgreicher Authentifizierung zusätzlich: username, fqdn.
 *
 * S31 (SP1): Bei unbekanntem Benutzer erfolgt eine Prüfung gegen DummyPasswordHash (Ergebnis verworfen), um die
 * zeitliche Unterscheidbarkeit zu verringern; Ergebnis (auth_failed) und Protokoll unverändert.
 */

declare(strict_types=1);

require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../zones/ZoneBuildBackend.php';
require_once __DIR__ . '/../../dyndns_guard.php';
require_once __DIR__ . '/../publish/PublishService.php';
require_once __DIR__ . '/../auth/DummyPasswordHash.php';

/**
 * Interne fachliche Ablehnung des Updates (nur innerhalb des Service verwendet). Die Ausnahmenachricht lautet
 * wie bisher "<reason>[: <detail>]".
 */
final class DyndnsUpdateReject extends RuntimeException
{
    public function __construct(
        public readonly bool $credentialsInvalid,
        public readonly string $reason,
        string $detail = ''
    ) {
        parent::__construct($reason . ($detail !== '' ? ': ' . $detail : ''));
    }
}

final class DyndnsUpdateService
{
    private PDO $pdo;
    private ZoneBuildBackend $build;
    private PublishService $publisher;

    /**
     * @param PDO              $pdo       Verbindung (dieselbe wie die des PublishService; Lock und Flags)
     * @param ZoneBuildBackend $build     Zonenprüfung in der Transaktion
     * @param PublishService   $publisher Einzelzonen-Publish nach dem Commit (publishZone)
     */
    public function __construct(PDO $pdo, ZoneBuildBackend $build, PublishService $publisher)
    {
        $this->pdo = $pdo;
        $this->build = $build;
        $this->publisher = $publisher;
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

        if (!$account) {
            // S31 (SP1): unbekannter Benutzer – Prüfung gegen festen Dummy-Hash, Ergebnis wird verworfen
            DummyPasswordHash::consumeVerification($password);
        }
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
            return self::final('error', 'dyndns.api.rejected', $ctx + ['credentialsInvalid' => false, 'reason' => 'invalid_ip',
                'detail' => 'myip_status=' . $ip4['status'] . ' myip6_status=' . $ip6['status']]);
        }

        $requested = ['A' => $ip4['ip'], 'AAAA' => $ip6['ip']];

        // Keine IP angegeben → Ergebnis no_ip, ohne Datenbankänderung
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
                throw new DyndnsUpdateReject(false, 'zone_not_found', "zone_id={$zone_id}");
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
                throw new DyndnsUpdateReject(true, 'account_deleted');
            }
            if ((string)$locked['username'] !== (string)$account['username']
                || (string)$locked['password_hash'] !== (string)$account['password_hash']) {
                throw new DyndnsUpdateReject(true, 'credentials_changed');
            }
            if ((int)$locked['zone_id'] !== $zone_id || (string)$locked['hostname'] !== $hostname) {
                throw new DyndnsUpdateReject(false, 'account_changed');
            }
            if ((string)$zone['name'] !== $zone_name) {
                throw new DyndnsUpdateReject(false, 'zone_changed', 'vorher=' . $zone_name . ' jetzt=' . (string)$zone['name']);
            }

            // 3. Records der Zone sperren, bevor einfache SELECTs den Lese-Snapshot festlegen
            $zoneRecords = dyndnsLockZoneAddressRecords($this->pdo, $zone_id);

            // 4. Zone und gespeicherten Hostnamen prüfen
            $zoneError = dyndnsZoneAssignmentError($zone);
            if ($zoneError !== null) {
                throw new DyndnsUpdateReject(false, strtolower($zoneError));
            }

            $hostErrors = validateDyndnsHostname($hostname, (string)$zone['name']);
            if (!empty($hostErrors)) {
                throw new DyndnsUpdateReject(false, 'invalid_stored_hostname', $hostErrors[0]);
            }

            // 5. Kollisionen (A/AAAA-Records selbst bewertet die Record-Zuordnung)
            $conflicts = array_values(array_filter(
                dyndnsFindHostnameConflicts($this->pdo, $zone_id, (string)$zone['name'], $hostname, $account_id),
                fn($c) => $c['code'] !== 'DYNDNS_CONFLICT_ADDRESS_RECORD'
            ));
            if (!empty($conflicts)) {
                throw new DyndnsUpdateReject(
                    false,
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
                throw new DyndnsUpdateReject(false, $plan['reject'], $plan['detail']);
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
                    $results[] = ['type' => $type, 'ip' => $step['ip'], 'changed' => false];
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

                $results[] = ['type' => $type, 'ip' => $step['ip'], 'changed' => true];
                $changed = true;
            }

            // 8. Nur wenn mindestens ein Record geändert wurde → Zonenprüfung und Markierung (changed = 1)
            //    in derselben Transaktion; ein Prüffehler verwirft die gesamte Änderung
            if ($changed) {
                $rebuild = $this->build->validateAndFlag($zone_id);
                if ($rebuild['status'] === 'error') {
                    $this->pdo->rollBack();
                    $m[] = new ServiceMessage('error', 'dyndns.api.validate_failed', $ctx + ['zoneName' => $zone_name, 'output' => $rebuild['output']]);
                    return new ServiceResult(false, $m);
                }
            }

            // 9. Festschreiben – vor jeder Verteilung (S32)
            $this->pdo->commit();
        } catch (Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable $rollbackError) {
                $m[] = new ServiceMessage('error', 'dyndns.api.rollback_failed', $ctx + ['message' => $rollbackError->getMessage()]);
            }

            if ($e instanceof DyndnsUpdateReject) {
                // detail wie bisher: vollständige Ausnahmenachricht ("<reason>[: <detail>]")
                $m[] = new ServiceMessage('error', 'dyndns.api.rejected', $ctx + ['credentialsInvalid' => $e->credentialsInvalid, 'reason' => $e->reason,
                    'detail' => $e->getMessage()]);
            } else {
                $m[] = new ServiceMessage('error', 'dyndns.api.exception', $ctx + ['lockError' => dyndnsIsLockError($e), 'message' => $e->getMessage()]);
            }

            return new ServiceResult(false, $m);
        }

        // 10. Veröffentlichen – nach dem Commit; ein Verteilungsfehler rollt die festgeschriebene Änderung nicht zurück.
        //     Ohne Änderung nur, wenn für die Zone noch ein Publish aussteht (Wiederholung, F1/F2); sonst nochg.
        $retry = false;
        if (!$changed) {
            try {
                $stmt = $this->pdo->prepare("SELECT changed FROM zones WHERE id = ?");
                $stmt->execute([$zone_id]);
                $pendingFlag = $stmt->fetchColumn();
                $stmt->closeCursor();
            } catch (Throwable $e) {
                $m[] = new ServiceMessage('error', 'dyndns.api.exception', $ctx + ['lockError' => dyndnsIsLockError($e), 'message' => $e->getMessage()]);
                return new ServiceResult(false, $m);
            }
            if ($pendingFlag === false) {
                // Zone nach dem Commit gelöscht: kein erfolgreiches Ergebnis
                $m[] = new ServiceMessage('error', 'dyndns.api.publish_pending', $ctx + [
                    'zoneName' => $zone_name, 'status' => 'not_found', 'errors' => [], 'warnings' => [], 'exception' => null, 'retry' => false,
                ]);
                return new ServiceResult(false, $m);
            }
            if ((int)$pendingFlag === 0) {
                $m[] = new ServiceMessage('success', 'dyndns.api.done', $ctx + ['results' => $results, 'retry' => false]);
                return new ServiceResult(true, $m);
            }
            $retry = true;
            $m[] = new ServiceMessage('audit', 'dyndns.api.publish_retry', $ctx + ['zoneName' => $zone_name]);
        }

        try {
            $published = $this->publisher->publishZone($zone_id);
            $last = $published->messages[count($published->messages) - 1] ?? null;
            $zr = ($last !== null && $last->code === 'publish.zone_result') ? $last->context : ['status' => 'exception', 'errors' => [], 'warnings' => [], 'exception' => 'Kein Publish-Ergebnis'];
        } catch (Throwable $e) {
            $published = null;
            $zr = ['status' => 'exception', 'errors' => [], 'warnings' => [], 'exception' => $e->getMessage()];
        }

        if ($published !== null && $published->ok) {
            $m[] = new ServiceMessage('success', 'dyndns.api.publish_success', $ctx + ['zoneName' => $zone_name, 'status' => $zr['status'],
                'keptPending' => (bool)($zr['keptPending'] ?? false), 'retry' => $retry]);
            $m[] = new ServiceMessage('success', 'dyndns.api.done', $ctx + ['results' => $results, 'retry' => $retry]);
            return new ServiceResult(true, $m);
        }

        // Zurückgestellt, teilweise oder nicht verteilt: Änderung bleibt festgeschrieben, Zone bleibt ausstehend
        $m[] = new ServiceMessage('error', 'dyndns.api.publish_pending', $ctx + [
            'zoneName'  => $zone_name,
            'status'    => (string)$zr['status'],
            'errors'    => array_values((array)($zr['errors'] ?? [])),
            'warnings'  => array_values((array)($zr['warnings'] ?? [])),
            'exception' => $zr['exception'] ?? null,
            'retry'     => $retry,
        ]);
        return new ServiceResult(false, $m);
    }

    private static function final(string $kind, string $code, array $context): ServiceResult
    {
        return new ServiceResult($kind === 'success', [new ServiceMessage($kind, $code, $context)]);
    }
}
