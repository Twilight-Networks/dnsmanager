<?php
/**
 * Datei: inc/app/dyndns/DyndnsAccountService.php
 * Zweck: Schreibende DynDNS-Accountverwaltung (Anlegen, Ändern, Passwort ändern, Löschen) für beliebige
 *        Aufrufkanäle (Web, CLI, späterer MCP-Adapter).
 *
 * Fachlogik unverändert aus actions/dyndns_add.php, dyndns_update.php, dyndns_password.php und
 * dyndns_delete.php übernommen (Regeln, Reihenfolge, Hash-Verfahren, Record-Behandlung). Keine Session, kein
 * HTTP, keine Toasts, kein Logging, keine Sprachschlüsseltexte.
 *
 * Ergebnis: ServiceResult mit ServiceMessages ("dyndns.<operation>.<ergebnis>"):
 * - rejected (reason, param, detail): fachliche Ablehnung; reason = fachlicher Fehlercode (Codes aus
 *   inc/dyndns_guard.php wie DYNDNS_ZONE_NOT_ALLOWED, DYNDNS_HOST_*, DYNDNS_CONFLICT_* sowie
 *   DYNDNS_ZONE_NOT_FOUND, DYNDNS_ACCOUNT_NOT_FOUND, DYNDNS_CONCURRENT_CHANGE, ZONE_REBUILD_FAILED),
 *   param = betroffener Rohwert (Hostname, Zone-ID oder null), detail = technische Detailmeldung.
 * - warning (reason, params, detail): Hinweis nach erfolgreichem Speichern; reason = DYNDNS_SHARED_HOSTNAME_KEPT,
 *   DYNDNS_RECORDS_KEPT_AMBIGUOUS, DYNDNS_NO_IP oder ZONE_REBUILD_WARNING, params = Rohwerte.
 *   Die Zuordnung zu Meldungstexten erfolgt ausschließlich im Web-Adapter (inc/web/dyndns_messages.php).
 * - duplicate_username, locked, failed: Datenbankfehler wie bisher unterschieden (detail = Ausnahmetext).
 * - access_denied (actorId, reason role|db_role), success, unchanged sowie operationsspezifische Codes.
 *
 * Berechtigung: nur admin. Die Rolle im Actor kann nur ablehnen; maßgeblich ist der Datenbankstand (Rolle
 * admin, Konto aktiv), geprüft in der schreibenden Transaktion unter geteilter Zeilensperre auf users.
 *
 * Sperren (unverändert, ergänzt um users vorab):
 * - Transaktion → users (S, Akteur) → zones (X, dyndnsLockZones, aufsteigend) → dyndns_accounts (X) → records.
 *   users vor zones entspricht der projektweiten Reihenfolge (UserService/ZoneService). Beide Anweisungen
 *   sind sperrende Lesezugriffe; der Lese-Snapshot der nachfolgenden einfachen SELECTs entsteht weiterhin
 *   erst nach der Zonensperre (siehe inc/dyndns_guard.php).
 * - Passwortänderung: Transaktion → users (S) → UPDATE dyndns_accounts (keine Zonensperre, wie bisher).
 * - Rebuild betroffener Zonen in derselben Transaktion über ZoneBuildBackend::validateAndFlag()
 *   (bisher rebuild_zone_and_flag_if_valid()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../zones/ZoneBuildBackend.php';
require_once __DIR__ . '/../../dyndns_guard.php';
require_once __DIR__ . '/DyndnsAccountInput.php';

/** Interne fachliche Ablehnung (nur innerhalb des Service verwendet) */
final class DyndnsServiceReject extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string|int|null $param,
        public readonly ?string $detail
    ) {
        parent::__construct($detail ?? $reason);
    }
}

final class DyndnsAccountService
{
    private PDO $pdo;
    private ZoneBuildBackend $build;

    public function __construct(PDO $pdo, ZoneBuildBackend $build)
    {
        $this->pdo = $pdo;
        $this->build = $build;
    }

    /* ======================================================================================== */
    /* Anlegen                                                                                  */
    /* ======================================================================================== */

    /**
     * Codes: access_denied, missing_fields, rejected, duplicate_username (username), locked, failed, success
     */
    public function createAccount(Actor $actor, DyndnsAccountInput $in): ServiceResult
    {
        if (!$actor->isAdmin()) {
            return self::one(false, 'audit', 'dyndns.create.access_denied', ['actorId' => $actor->userId, 'reason' => 'role']);
        }

        $username = $in->username;
        $hostname = $in->hostname;
        $zone_id  = $in->zoneId;

        if ($username === '' || $in->password === '' || $hostname === '' || $zone_id <= 0) {
            return self::one(false, 'error', 'dyndns.create.missing_fields', []);
        }

        $hash = password_hash($in->password, PASSWORD_BCRYPT);

        try {
            $this->pdo->beginTransaction();

            if (!$this->isDbAdmin($actor)) {
                $this->rollbackIfActive();
                return self::one(false, 'audit', 'dyndns.create.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }

            // 1. Zone sperren (serialisiert mit DynDNS-API und Publish)
            $zones = dyndnsLockZones($this->pdo, [$zone_id]);
            $zone  = $zones[$zone_id] ?? null;

            if ($zone === null) {
                throw new DyndnsServiceReject('DYNDNS_ZONE_NOT_FOUND', null, "DynDNS-Anlage: Zone-ID {$zone_id} nicht gefunden.");
            }

            $zoneError = dyndnsZoneAssignmentError($zone);
            if ($zoneError !== null) {
                throw new DyndnsServiceReject($zoneError, null, "DynDNS-Anlage abgelehnt: {$zoneError} (Zone-ID {$zone_id}).");
            }

            // 2. Hostname validieren und normalisieren
            $hostErrors = validateDyndnsHostname($hostname, (string)$zone['name']);
            if (!empty($hostErrors)) {
                throw new DyndnsServiceReject(
                    $hostErrors[0],
                    $hostname,
                    "DynDNS-Anlage abgelehnt: {$hostErrors[0]} (Hostname " . json_encode($hostname, JSON_INVALID_UTF8_SUBSTITUTE) . ", Zone-ID {$zone_id})."
                );
            }
            $hostname = normalizeDyndnsHostname($hostname);

            // 3. Kollisionsprüfung unter Zonensperre (autoritativ)
            $conflicts = dyndnsFindHostnameConflicts($this->pdo, $zone_id, (string)$zone['name'], $hostname, null);
            if (!empty($conflicts)) {
                $first = $conflicts[0];
                throw new DyndnsServiceReject(
                    $first['code'],
                    $hostname,
                    "DynDNS-Anlage abgelehnt: Konflikte für '{$hostname}' in Zone-ID {$zone_id}: "
                        . implode('; ', array_map(fn($c) => $c['code'] . ' (' . $c['detail'] . ')', $conflicts))
                );
            }

            // 4. Account anlegen
            $stmt = $this->pdo->prepare("
                INSERT INTO dyndns_accounts (username, password_hash, hostname, zone_id)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$username, $hash, $hostname, $zone_id]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::one(false, ...self::errorMessage('create', $e, $username));
        }

        return self::one(true, 'success', 'dyndns.create.success', ['username' => $username, 'hostname' => $hostname, 'zoneId' => $zone_id]);
    }

    /* ======================================================================================== */
    /* Ändern                                                                                   */
    /* ======================================================================================== */

    /**
     * Ändert Benutzername, Hostname, Zone und optional das Passwort ($in->password leer = unverändert).
     *
     * Codes: access_denied, invalid_input, not_found (vor der Transaktion), unchanged, rejected, warning (je
     *        Hinweis, nach Commit in bisheriger Reihenfolge), duplicate_username, locked, failed,
     *        success (rebuilt: bool)
     */
    public function updateAccount(Actor $actor, int $id, DyndnsAccountInput $in): ServiceResult
    {
        if (!$actor->isAdmin()) {
            return self::one(false, 'audit', 'dyndns.update.access_denied', ['actorId' => $actor->userId, 'reason' => 'role']);
        }

        $username = $in->username;
        $password = $in->password;
        $hostname = $in->hostname;
        $zone_id  = $in->zoneId;

        if ($id <= 0 || $hostname === '' || $zone_id <= 0 || $username === '') {
            return self::one(false, 'error', 'dyndns.update.invalid_input', []);
        }

        // Bestehende Werte vorläufig laden (ohne Sperre): nur zur Bestimmung der zu sperrenden Zonen und der
        // Änderungsart. Unter Sperre wird der Account erneut geladen und verglichen.
        $stmt = $this->pdo->prepare("SELECT zone_id, hostname, username FROM dyndns_accounts WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$existing) {
            return self::one(false, 'error', 'dyndns.update.not_found', []);
        }

        $oldZoneId = (int)$existing['zone_id'];

        $usernameChanged = trim((string)$existing['username']) !== $username;
        $hostnameChanged = (string)$existing['hostname'] !== $hostname;
        $zoneChanged     = $oldZoneId !== $zone_id;
        $passwordChanged = $password !== '';
        $nameChanged     = $hostnameChanged || $zoneChanged;

        if (!$hostnameChanged && !$zoneChanged && !$usernameChanged && !$passwordChanged) {
            return self::one(true, 'success', 'dyndns.update.unchanged', []);
        }

        $warnings       = [];
        $zonesToRebuild = [];

        try {
            $this->pdo->beginTransaction();

            if (!$this->isDbAdmin($actor)) {
                $this->rollbackIfActive();
                return self::one(false, 'audit', 'dyndns.update.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }

            // 1. Alte und neue Zone sperren (aufsteigende ID-Reihenfolge)
            $zones = dyndnsLockZones($this->pdo, [$oldZoneId, $zone_id]);

            // 2. Account unter Sperre neu laden und auf zwischenzeitliche Änderungen prüfen
            $stmt = $this->pdo->prepare("
                SELECT id, zone_id, hostname, username, current_ipv4, current_ipv6
                FROM dyndns_accounts
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$account) {
                throw new DyndnsServiceReject('DYNDNS_ACCOUNT_NOT_FOUND', null, "DynDNS-Update: Account-ID {$id} nicht (mehr) vorhanden.");
            }

            if (
                (int)$account['zone_id'] !== $oldZoneId
                || (string)$account['hostname'] !== (string)$existing['hostname']
                || !isset($zones[$oldZoneId])
            ) {
                throw new DyndnsServiceReject('DYNDNS_CONCURRENT_CHANGE', null, "DynDNS-Update: Account-ID {$id} wurde zwischenzeitlich verändert.");
            }

            $oldHostname  = (string)$account['hostname'];
            $oldZoneName  = (string)$zones[$oldZoneId]['name'];
            $current_ipv4 = $account['current_ipv4'] !== null ? (string)$account['current_ipv4'] : null;
            $current_ipv6 = $account['current_ipv6'] !== null ? (string)$account['current_ipv6'] : null;

            $newHostname = $oldHostname; // reine Benutzername-/Passwortänderung: Alt-Hostname unverändert lassen
            $newZoneId   = $oldZoneId;
            $sameDnsName = true;

            // 3. Neue Zuordnung prüfen (nur bei Änderung von Hostname oder Zone)
            if ($nameChanged) {
                $targetZone = $zones[$zone_id] ?? null;

                if ($targetZone === null) {
                    throw new DyndnsServiceReject('DYNDNS_ZONE_NOT_FOUND', null, "DynDNS-Update: Ziel-Zone-ID {$zone_id} nicht gefunden.");
                }

                $zoneError = dyndnsZoneAssignmentError($targetZone);
                if ($zoneError !== null) {
                    throw new DyndnsServiceReject($zoneError, null, "DynDNS-Update abgelehnt: {$zoneError} (Account-ID {$id}, Ziel-Zone-ID {$zone_id}).");
                }

                $hostErrors = validateDyndnsHostname($hostname, (string)$targetZone['name']);
                if (!empty($hostErrors)) {
                    throw new DyndnsServiceReject(
                        $hostErrors[0],
                        $hostname,
                        "DynDNS-Update abgelehnt: {$hostErrors[0]} (Hostname " . json_encode($hostname, JSON_INVALID_UTF8_SUBSTITUTE) . ", Account-ID {$id})."
                    );
                }

                $newHostname = normalizeDyndnsHostname($hostname);
                $newZoneId   = $zone_id;

                // Gleicher DNS-Name (z. B. nur Groß-/Kleinschreibung geändert): keine Record-Änderung nötig
                $sameDnsName = !$zoneChanged && dyndnsCanonicalName($oldHostname, $oldZoneName) === $newHostname;

                if (!$sameDnsName) {
                    $conflicts = dyndnsFindHostnameConflicts($this->pdo, $newZoneId, (string)$targetZone['name'], $newHostname, $id);
                    if (!empty($conflicts)) {
                        $first = $conflicts[0];
                        throw new DyndnsServiceReject(
                            $first['code'],
                            $newHostname,
                            "DynDNS-Update abgelehnt: Konflikte für '{$newHostname}' in Zone-ID {$newZoneId} (Account-ID {$id}): "
                                . implode('; ', array_map(fn($c) => $c['code'] . ' (' . $c['detail'] . ')', $conflicts))
                        );
                    }
                }
            }

            // 4. Account aktualisieren
            if ($passwordChanged) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $this->pdo->prepare("
                    UPDATE dyndns_accounts
                    SET password_hash = ?, hostname = ?, zone_id = ?, username = ?
                    WHERE id = ?
                ");
                $stmt->execute([$hash, $newHostname, $newZoneId, $username, $id]);
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE dyndns_accounts
                    SET hostname = ?, zone_id = ?, username = ?
                    WHERE id = ?
                ");
                $stmt->execute([$newHostname, $newZoneId, $username, $id]);
            }

            // 5. DNS-Records anpassen, wenn sich der DNS-Name tatsächlich ändert
            if ($nameChanged && !$sameDnsName) {
                // 5a. Alter Name: gemeinsame Records bei Doppelbelegung erhalten, sonst nur eindeutig zuordenbare löschen
                $sharedWith = dyndnsFindAccountsSharingHostname($this->pdo, $oldZoneId, $oldZoneName, $oldHostname, $id);

                if (!empty($sharedWith)) {
                    $warnings[] = ['DYNDNS_SHARED_HOSTNAME_KEPT', [$oldHostname, implode(', ', $sharedWith)],
                        "DynDNS-Update: alter Hostname '{$oldHostname}' (Zone-ID {$oldZoneId}) wird auch von "
                            . implode(', ', $sharedWith) . " verwendet – A/AAAA-Records bleiben erhalten."];
                } else {
                    $plan = dyndnsClassifyAccountRecords($this->pdo, $oldZoneId, $oldHostname, $current_ipv4, $current_ipv6);
                    $deletedOldRecords = dyndnsDeleteRecords($this->pdo, $plan['delete']);

                    if ($plan['kept'] > 0) {
                        $warnings[] = ['DYNDNS_RECORDS_KEPT_AMBIGUOUS', [$plan['kept'], $oldHostname],
                            "DynDNS-Update: {$plan['kept']} A/AAAA-Record(s) unter '{$oldHostname}' (Zone-ID {$oldZoneId}) nicht eindeutig zuordenbar – erhalten."];
                    }

                    // Alte Zone nur markieren, wenn dort tatsächlich Records gelöscht wurden
                    if ($deletedOldRecords > 0) {
                        $zonesToRebuild[] = $oldZoneId;
                    }
                }

                // 5b. Neue A/AAAA-Records in neuer Zone anlegen – mit den zuletzt gemeldeten IPs
                $hasInsertedAny = false;

                if (!empty($current_ipv4)) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO records (zone_id, name, type, content, ttl)
                        VALUES (?, ?, 'A', ?, 300)
                    ");
                    $stmt->execute([$newZoneId, $newHostname, $current_ipv4]);
                    $hasInsertedAny = true;
                }

                if (!empty($current_ipv6)) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO records (zone_id, name, type, content, ttl)
                        VALUES (?, ?, 'AAAA', ?, 300)
                    ");
                    $stmt->execute([$newZoneId, $newHostname, $current_ipv6]);
                    $hasInsertedAny = true;
                }

                if (!$hasInsertedAny) {
                    $warnings[] = ['DYNDNS_NO_IP', [], 'Es wurden keine A-/AAAA-Records gesetzt, da keine aktuelle IP vorliegt.'];
                }

                // Neue Zone nur markieren, wenn dort tatsächlich Records angelegt wurden
                if ($hasInsertedAny) {
                    $zonesToRebuild[] = $newZoneId;
                }
            }

            // 6. Betroffene Zonen (je einmal) in derselben Transaktion validieren und markieren
            $zonesToRebuild = array_values(array_unique($zonesToRebuild));
            foreach ($zonesToRebuild as $zid) {
                $result = $this->build->validateAndFlag((int)$zid);
                if ($result['status'] === 'error') {
                    throw new DyndnsServiceReject('ZONE_REBUILD_FAILED', $zid, $result['output']);
                } elseif ($result['status'] === 'warning') {
                    $warnings[] = ['ZONE_REBUILD_WARNING', [$zid], $result['output']];
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::one(false, ...self::errorMessage('update', $e, $username));
        }

        $m = [];
        foreach ($warnings as [$reason, $params, $detail]) {
            $m[] = new ServiceMessage('warning', 'dyndns.update.warning', ['reason' => $reason, 'params' => $params, 'detail' => $detail]);
        }
        $m[] = new ServiceMessage('success', 'dyndns.update.success', ['rebuilt' => !empty($zonesToRebuild), 'accountId' => $id]);

        return new ServiceResult(true, $m);
    }

    /* ======================================================================================== */
    /* Passwort ändern                                                                          */
    /* ======================================================================================== */

    /**
     * Setzt ein neues Passwort (unverändert wie bisher: Mindestlänge PASSWORD_MIN_LENGTH, PASSWORD_DEFAULT,
     * keine Existenzprüfung des Accounts).
     *
     * Codes: access_denied, too_short (minLength), hash_failed, failed (detail), success
     */
    public function changePassword(Actor $actor, int $id, string $password): ServiceResult
    {
        if (!$actor->isAdmin()) {
            return self::one(false, 'audit', 'dyndns.password.access_denied', ['actorId' => $actor->userId, 'reason' => 'role']);
        }

        $minLength = defined('PASSWORD_MIN_LENGTH') ? (int)PASSWORD_MIN_LENGTH : 0;
        if (strlen($password) < $minLength) {
            return self::one(false, 'error', 'dyndns.password.too_short', ['minLength' => $minLength]);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return self::one(false, 'error', 'dyndns.password.hash_failed', []);
        }

        try {
            $this->pdo->beginTransaction();

            if (!$this->isDbAdmin($actor)) {
                $this->rollbackIfActive();
                return self::one(false, 'audit', 'dyndns.password.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }

            $stmt = $this->pdo->prepare("UPDATE dyndns_accounts SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::one(false, 'error', 'dyndns.password.failed', ['detail' => $e->getMessage()]);
        }

        return self::one(true, 'success', 'dyndns.password.success', ['accountId' => $id]);
    }

    /* ======================================================================================== */
    /* Löschen                                                                                  */
    /* ======================================================================================== */

    /**
     * Löscht einen Account samt eindeutig zuordenbarer A/AAAA-Records (gemeinsam genutzte bleiben).
     *
     * Codes: access_denied, invalid_id, not_found (vor der Transaktion, detail), rejected, warning, locked,
     *        failed, success (accountOnly: bool)
     */
    public function deleteAccount(Actor $actor, int $id): ServiceResult
    {
        if (!$actor->isAdmin()) {
            return self::one(false, 'audit', 'dyndns.delete.access_denied', ['actorId' => $actor->userId, 'reason' => 'role']);
        }
        if ($id <= 0) {
            return self::one(false, 'error', 'dyndns.delete.invalid_id', []);
        }

        // Zone des Accounts vorab ermitteln (nur zur Bestimmung der Sperre; wird unter Sperre erneut geprüft)
        $stmt = $this->pdo->prepare("SELECT zone_id FROM dyndns_accounts WHERE id = ?");
        $stmt->execute([$id]);
        $preZoneId = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($preZoneId === false) {
            return self::one(false, 'error', 'dyndns.delete.not_found', ['detail' => "DynDNS-Löschung: Account-ID {$id} nicht gefunden."]);
        }
        $preZoneId = (int)$preZoneId;

        $warnings    = [];
        $sharedWith  = [];
        $keptRecords = 0;

        try {
            $this->pdo->beginTransaction();

            if (!$this->isDbAdmin($actor)) {
                $this->rollbackIfActive();
                return self::one(false, 'audit', 'dyndns.delete.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }

            // 1. Zone sperren
            $zones = dyndnsLockZones($this->pdo, [$preZoneId]);

            // 2. Account unter Sperre neu laden
            $stmt = $this->pdo->prepare("
                SELECT id, username, zone_id, hostname, current_ipv4, current_ipv6
                FROM dyndns_accounts
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$account) {
                throw new DyndnsServiceReject('DYNDNS_ACCOUNT_NOT_FOUND', null, "DynDNS-Löschung: Account-ID {$id} nicht (mehr) vorhanden.");
            }

            if ((int)$account['zone_id'] !== $preZoneId || !isset($zones[$preZoneId])) {
                throw new DyndnsServiceReject(
                    'DYNDNS_CONCURRENT_CHANGE',
                    null,
                    "DynDNS-Löschung: Account-ID {$id} wurde zwischenzeitlich verändert (Zone {$preZoneId} → {$account['zone_id']})."
                );
            }

            $zid      = $preZoneId;
            $zoneName = (string)$zones[$zid]['name'];
            $hostname = (string)$account['hostname'];

            // 3. Bestehende Doppelbelegung? Dann gemeinsame Records nicht anfassen.
            $sharedWith     = dyndnsFindAccountsSharingHostname($this->pdo, $zid, $zoneName, $hostname, $id);
            $deletedRecords = 0;

            if (!empty($sharedWith)) {
                $warnings[] = ['DYNDNS_SHARED_HOSTNAME_KEPT', [$hostname, implode(', ', $sharedWith)],
                    "DynDNS-Löschung: Hostname '{$hostname}' (Zone-ID {$zid}) wird auch von " . implode(', ', $sharedWith)
                        . " verwendet – A/AAAA-Records bleiben erhalten."];
            } else {
                // 4. Nur eindeutig zuordenbare A/AAAA-Records löschen (nie Systemrecords)
                $plan = dyndnsClassifyAccountRecords(
                    $this->pdo,
                    $zid,
                    $hostname,
                    $account['current_ipv4'] !== null ? (string)$account['current_ipv4'] : null,
                    $account['current_ipv6'] !== null ? (string)$account['current_ipv6'] : null
                );
                $deletedRecords = dyndnsDeleteRecords($this->pdo, $plan['delete']);
                $keptRecords    = $plan['kept'];

                if ($keptRecords > 0) {
                    $warnings[] = ['DYNDNS_RECORDS_KEPT_AMBIGUOUS', [$keptRecords, $hostname],
                        "DynDNS-Löschung: {$keptRecords} A/AAAA-Record(s) unter '{$hostname}' (Zone-ID {$zid}) nicht eindeutig zuordenbar – erhalten."];
                }
            }

            // 5. DynDNS-Account löschen
            $stmt = $this->pdo->prepare("DELETE FROM dyndns_accounts WHERE id = ?");
            $stmt->execute([$id]);

            // 6. Zone nur validieren/markieren, wenn tatsächlich Records gelöscht wurden (gleiche Transaktion)
            if ($deletedRecords > 0) {
                $result = $this->build->validateAndFlag($zid);
                if ($result['status'] === 'error') {
                    throw new DyndnsServiceReject('ZONE_REBUILD_FAILED', $zid, $result['output']);
                } elseif ($result['status'] === 'warning') {
                    $warnings[] = ['ZONE_REBUILD_WARNING', [$zid], $result['output']];
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::one(false, ...self::errorMessage('delete', $e, null));
        }

        $m = [];
        foreach ($warnings as [$reason, $params, $detail]) {
            $m[] = new ServiceMessage('warning', 'dyndns.delete.warning', ['reason' => $reason, 'params' => $params, 'detail' => $detail]);
        }
        $m[] = new ServiceMessage('success', 'dyndns.delete.success', ['accountOnly' => !(empty($sharedWith) && $keptRecords === 0), 'accountId' => $id]);

        return new ServiceResult(true, $m);
    }

    /* ======================================================================================== */
    /* Hilfen                                                                                   */
    /* ======================================================================================== */

    /**
     * Fehlerabbildung wie bisher in den Actions: fachliche Ablehnung, doppelter Benutzername (nicht beim
     * Löschen), Lock-Timeout/Deadlock, sonstiger Fehler.
     *
     * @return array{0: string, 1: string, 2: array}
     */
    private static function errorMessage(string $op, Throwable $e, ?string $username): array
    {
        if ($e instanceof DyndnsServiceReject) {
            return ['error', "dyndns.$op.rejected", ['reason' => $e->reason, 'param' => $e->param, 'detail' => $e->detail]];
        }
        if ($op !== 'delete' && dyndnsIsDuplicateKeyError($e)) {
            return ['error', "dyndns.$op.duplicate_username", ['username' => (string)$username, 'detail' => $e->getMessage()]];
        }
        if (dyndnsIsLockError($e)) {
            return ['error', "dyndns.$op.locked", ['detail' => $e->getMessage()]];
        }

        return ['error', "dyndns.$op.failed", ['class' => get_class($e), 'detail' => $e->getMessage()]];
    }

    /** Akteur laut Datenbank aktiver admin, unter geteilter Zeilensperre (wie ZoneService/ServerService) */
    private function isDbAdmin(Actor $actor): bool
    {
        $stmt = $this->pdo->prepare("SELECT role, active FROM users WHERE id = ? LOCK IN SHARE MODE");
        $stmt->execute([$actor->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row !== false && (int)$row['active'] === 1 && (string)$row['role'] === 'admin';
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function one(bool $ok, string $kind, string $code, array $context): ServiceResult
    {
        return new ServiceResult($ok, [new ServiceMessage($kind, $code, $context)]);
    }
}
