<?php
/**
 * Datei: inc/app/zones/ZoneService.php
 * Zweck: Schreibende Zonenverwaltung (Anlegen, Ändern inkl. Serverzuweisung, Löschen) für beliebige
 *        Aufrufkanäle (Web, CLI, späterer MCP-Adapter).
 *
 * Keine Session, kein HTTP, keine Toasts, kein Logging, keine Sprachschlüssel. Ergebnis: ServiceResult
 * mit ServiceMessages (Codes "zone.<operation>.<ergebnis>", Rohdaten im Kontext). Die Reihenfolge der
 * Messages entspricht der bisherigen Reihenfolge der Toasts/Logs in actions/zone_*.php.
 *
 * Berechtigung (E12):
 * - Maßgeblich ist der Datenbankstand des Akteurs, geprüft in der schreibenden Transaktion unter
 *   geteilter Zeilensperre (users LOCK IN SHARE MODE; zoneadmin zusätzlich user_zones). Die Rolle im
 *   Actor wählt nur den Pfad wie bisher (admin/zoneadmin) und kann ablehnen, nie erlauben: weicht die
 *   DB-Rolle von der Actor-Rolle ab (z. B. veraltete Web-Session) oder ist die Zuweisung entzogen,
 *   folgt "<operation>.access_denied".
 * - Anlegen/Löschen: nur admin. Ändern: admin (inkl. Serverzuweisung) oder zugewiesener zoneadmin.
 *
 * Sperren (E13/E14/E15):
 * - Reihenfolge: Topologie-Lock (Try-Lock, nur bei Anlage, Löschung und Änderung der Serverzuweisung)
 *   → Transaktion → users (S) → user_zones (S, zoneadmin) → zones (X, FOR UPDATE) → zone_servers (X)
 *   → Schreiben → Prüfung der Zonendatei → Commit → Freigabe des Topologie-Locks.
 * - Benutzer vor Zonen: gleiche Richtung wie UserService (users X → FK-S auf zones); Record-/DynDNS-
 *   Pfade sperren zones vor records/dyndns_accounts wie hier.
 * - Ändern und Löschen entscheiden ausschließlich auf der gesperrten Zonenzeile (z. B. Zone
 *   zwischenzeitlich gelöscht → not_found statt Scheinerfolg). Ungesperrte Vorprüfungen dienen nur der
 *   unveränderten Meldungsreihenfolge.
 * - Deadlocks sind nicht grundsätzlich ausgeschlossen; sie führen zu vollständigem Rollback und
 *   "<operation>.failed" bzw. dem bisherigen Fehlercode der Phase. Keine automatischen Wiederholungen.
 *
 * Sicherheit (PR #43/#44, Plan §11 A1/A2):
 * - SOA-NS von Reverse-Zonen wird wie bisher mit isValidSoaName() geprüft (Anlage: zusammengesetzt aus
 *   Master und soa_domain; Änderung: Eingabe, sofern verwendet), vor Lock und Transaktion.
 * - Zonendatei-Erzeugung über ZoneBuildBackend; die Generatorprüfungen (SOA- und Record-Felder) wirken
 *   unverändert mit.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../TopologyLock.php';
require_once __DIR__ . '/../../validators.php';
require_once __DIR__ . '/ZoneBuildBackend.php';
require_once __DIR__ . '/ZoneInput.php';
require_once __DIR__ . '/ZoneUpdateInput.php';

final class ZoneService
{
    private PDO $pdo;
    private ZoneBuildBackend $build;
    private TopologyLock $lock;

    public function __construct(PDO $pdo, ZoneBuildBackend $build, TopologyLock $lock)
    {
        $this->pdo = $pdo;
        $this->build = $build;
        $this->lock = $lock;
    }

    /* ======================================================================================== */
    /* Anlegen                                                                                  */
    /* ======================================================================================== */

    /**
     * Legt eine Zone an (nur admin): Zone, NS-Records je Server, Glue (Forward, In-Zone-NS),
     * Serverzuweisung, Prüfung der Zonendatei; alles in einer Transaktion unter Topologie-Lock.
     *
     * Codes: access_denied, invalid_input (errors), no_server, master_not_included, master_not_found,
     *        invalid_soa_ns, lock_acquire_error, topology_locked, lock_release_unconfirmed,
     *        lock_release_error, zonefile_invalid, zonefile_warning, failed, success
     */
    public function createZone(Actor $actor, ZoneInput $input): ServiceResult
    {
        $m = [];
        if (!$actor->isAdmin()) {
            return self::fail($m, 'audit', 'zone.create.access_denied', ['actorId' => $actor->userId, 'reason' => 'role']);
        }

        $post = $input->toFormArray();

        // Eingabevalidierung (unverändert validateZoneInput)
        $errors = validateZoneInput($post);
        if (!empty($errors)) {
            return self::fail($m, 'error', 'zone.create.invalid_input', ['errors' => array_values($errors)]);
        }

        // Zonenparameter vorbereiten: bisherige Ausdrücke aus zone_add.php unverändert, bewusst ohne "??",
        // wo der bisherige Code keines hatte (fehlende Felder erzeugen wie bisher dieselbe PHP-Warnung bzw.
        // Deprecation und dasselbe Ergebnis; Legacy-Brücke, E17).
        $prefix        = trim($post['zone_prefix']);
        $selected_type = $post['type'];
        $type          = in_array($selected_type, ['reverse_ipv4', 'reverse_ipv6']) ? 'reverse' : 'forward';
        $name          = $type === 'reverse'
            ? $prefix . ($selected_type === 'reverse_ipv6' ? '.ip6.arpa' : '.in-addr.arpa')
            : $prefix;

        $ttl           = intval($post['ttl']) ?: 86400;
        $ttl_ns        = 3600;
        $ttl_glue      = 300;
        $prefix_length = $type === 'reverse' ? (int)($post['prefix_length'] ?? null) : null;
        $description   = trim($post['description']) ?: null;

        $soa_serial   = (int)(date('Ymd') . '01');
        $soa_mail     = rtrim(trim($post['soa_mail']), '.') . '.';
        $soa_refresh  = (int)$post['soa_refresh'];
        $soa_retry    = (int)$post['soa_retry'];
        $soa_expire   = (int)$post['soa_expire'];
        $soa_minimum  = (int)$post['soa_minimum'];
        $allow_dyndns = $input->allowDyndns ? 1 : 0;

        // Serverzuweisung
        $server_ids = $input->serverIds ?? [];
        $master_id  = $input->masterServerId;

        if (!is_array($server_ids) || count($server_ids) < 1) {
            return self::fail($m, 'error', 'zone.create.no_server', []);
        }
        if (!$master_id || !in_array($master_id, $server_ids, true)) {
            return self::fail($m, 'error', 'zone.create.master_not_included', []);
        }

        // Master-Server laden (Pflicht für SOA NS)
        $stmt = $this->pdo->prepare("SELECT name, dns_ip4, dns_ip6 FROM servers WHERE id = ?");
        $stmt->execute([$master_id]);
        $master = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$master) {
            return self::fail($m, 'error', 'zone.create.master_not_found', ['masterId' => $master_id]);
        }

        // SOA-NS zusammensetzen (unverändert)
        $zone_base   = rtrim($name, '.');
        $master_name = rtrim($master['name'], '.');
        $soa_domain  = rtrim($post['soa_domain'] ?? '', '.');
        $soa_ns      = ($type === 'reverse')
            ? $master_name . '.' . $soa_domain . '.'
            : (
                str_ends_with($master_name, '.' . $zone_base)
                    ? $master_name . '.'
                    : $master_name . '.' . $zone_base . '.'
            );

        // S17: zusammengesetzter SOA-NS von Reverse-Zonen (freie Eingabe soa_domain)
        if ($type === 'reverse' && !isValidSoaName($soa_ns)) {
            return self::fail($m, 'error', 'zone.create.invalid_soa_ns', []);
        }

        // Topologie-Lock (Try-Lock) vor jeder Transaktion
        if (!$this->acquireLock('create', $m)) {
            return self::fail($m, 'warning', 'zone.create.topology_locked', ['name' => $name]);
        }

        try {
            $this->pdo->beginTransaction();

            // E12/E13: Akteur unter geteilter Sperre aus der DB (erste Anweisung der Transaktion)
            if ($this->dbRole($actor->userId) !== 'admin') {
                $this->rollbackIfActive();
                $this->releaseLock('create', $m);
                return self::fail($m, 'audit', 'zone.create.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO zones
                    (name, type, ttl, prefix_length, description,
                     soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt->execute([
                $name, $type, $ttl, $prefix_length ?: null, $description,
                $soa_ns, $soa_mail, $soa_refresh, $soa_retry, $soa_expire, $soa_minimum, $soa_serial, $allow_dyndns,
            ]);

            $zone_id = (int)$this->pdo->lastInsertId();
            if ($zone_id < 1) {
                throw new Exception("Fehler: Keine gültige Zonen-ID erzeugt.");
            }

            // NS-, A- und AAAA-Records für alle zugewiesenen Server (unverändert)
            $stmt_server = $this->pdo->prepare("SELECT name, dns_ip4, dns_ip6 FROM servers WHERE id = ?");
            $stmt_ns   = $this->pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, '@', 'NS', ?, ?, ?)");
            $stmt_a    = $this->pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, ?, 'A', ?, ?, ?)");
            $stmt_aaaa = $this->pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, ?, 'AAAA', ?, ?, ?)");

            foreach ($server_ids as $sid) {
                $stmt_server->execute([$sid]);
                $srv = $stmt_server->fetch(PDO::FETCH_ASSOC);
                if (!$srv) {
                    throw new Exception("Zugewiesener Server nicht gefunden (ID: $sid)");
                }

                $ns_fqdn = rtrim($srv['name'], '.') . '.';
                $is_glue = $type === 'forward' && str_ends_with($ns_fqdn, '.' . $zone_base . '.');

                $stmt_ns->execute([$zone_id, $ns_fqdn, $ttl_ns, $sid]);

                if ($is_glue) {
                    $host_part = rtrim(str_replace('.' . $zone_base . '.', '', $ns_fqdn), '.');
                    if (!empty($srv['dns_ip4'])) {
                        $stmt_a->execute([$zone_id, $host_part, $srv['dns_ip4'], $ttl_glue, $sid]);
                    }
                    if (!empty($srv['dns_ip6'])) {
                        $stmt_aaaa->execute([$zone_id, $host_part, $srv['dns_ip6'], $ttl_glue, $sid]);
                    }
                }
            }

            // Serverzuweisungen speichern (unverändert)
            $stmt = $this->pdo->prepare("
                INSERT INTO zone_servers (zone_id, server_id, is_master)
                VALUES (:zone_id, :server_id, :is_master)
            ");
            foreach ($server_ids as $sid) {
                $stmt->execute([
                    ':zone_id'   => $zone_id,
                    ':server_id' => (int)$sid,
                    ':is_master' => ((int)$sid === (int)$master_id) ? 1 : 0,
                ]);
            }

            // Zonendatei prüfen / Flag setzen
            $rebuild = $this->build->validateAndFlag($zone_id);
            if ($rebuild['status'] === 'error') {
                $this->rollbackIfActive();
                $this->releaseLock('create', $m);
                return self::fail($m, 'error', 'zone.create.zonefile_invalid', ['output' => $rebuild['output']], $zone_id);
            }
            if ($rebuild['status'] === 'warning') {
                $m[] = new ServiceMessage('warning', 'zone.create.zonefile_warning', ['output' => $rebuild['output']]);
            }

            $this->pdo->commit();
            $this->releaseLock('create', $m);
            $m[] = new ServiceMessage('success', 'zone.create.success', ['name' => $name, 'zoneId' => $zone_id]);

            return new ServiceResult(true, $m, $zone_id);
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            $this->releaseLock('create', $m);
            return self::fail($m, 'error', 'zone.create.failed', ['name' => $name, 'detail' => $e->getMessage()]);
        } finally {
            // Sicherheitsnetz (z. B. unerwartete Fehler außerhalb von Throwable-Pfaden)
            $this->releaseLock('create', $m);
        }
    }

    /* ======================================================================================== */
    /* Ändern                                                                                   */
    /* ======================================================================================== */

    /**
     * Ändert Metadaten einer Zone; admin zusätzlich die Serverzuweisung (NS/Glue-Neuaufbau).
     *
     * Codes: access_denied (reason: role|assignment|db_role|db_assignment), not_found, invalid_input
     *        (errors), invalid_prefix_length, no_servers, master_not_in_list, master_inactive,
     *        invalid_soa_ns, lock_acquire_error, topology_locked, lock_release_unconfirmed,
     *        lock_release_error, ns_glue_failed, ns_glue_warning, server_assignment_failed,
     *        zonefile_invalid, zonefile_warning, unchanged, failed, success
     */
    public function updateZone(Actor $actor, int $zoneId, ZoneUpdateInput $input): ServiceResult
    {
        $m = [];
        $id = $zoneId;

        if (!in_array($actor->role, ['admin', 'zoneadmin'], true)) {
            return self::fail($m, 'audit', 'zone.update.access_denied', ['actorId' => $actor->userId, 'zoneId' => $id, 'reason' => 'role']);
        }
        $adminPath = $actor->isAdmin();

        // Vorprüfung zoneadmin (wie bisher vor allen weiteren Prüfungen)
        if (!$adminPath) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM user_zones WHERE zone_id = ? AND user_id = ?");
            $stmt->execute([$id, $actor->userId]);
            if (!$stmt->fetch()) {
                return self::fail($m, 'audit', 'zone.update.access_denied', ['actorId' => $actor->userId, 'zoneId' => $id, 'reason' => 'assignment']);
            }
        }

        // Zone laden (ungesperrt; maßgeblich ist später die gesperrte Zeile)
        $stmt = $this->pdo->prepare("SELECT name, type FROM zones WHERE id = ?");
        $stmt->execute([$id]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zone) {
            return self::fail($m, 'error', 'zone.update.not_found', ['zoneId' => $id]);
        }
        $name = $zone['name'];
        $type = $zone['type'];

        // Eingaben (bisherige Ausdrücke aus zone_update.php)
        $ttl           = (int)($input->ttl ?? 3600);
        $prefix_length = $input->prefixLength !== null ? (int)$input->prefixLength : null;
        $description   = trim($input->description ?? '') ?: null;
        $allow_dyndns  = $input->allowDyndns ? 1 : 0;

        $soa_mail    = rtrim(trim($input->soaMail ?? ''), '.') . '.';
        $soa_refresh = (int)($input->soaRefresh ?? 3600);
        $soa_retry   = (int)($input->soaRetry ?? 1800);
        $soa_expire  = (int)($input->soaExpire ?? 1209600);
        $soa_minimum = (int)($input->soaMinimum ?? 86400);

        $errors = validateZoneInput($input->toFormArray(), false);
        if (!empty($errors)) {
            return self::fail($m, 'error', 'zone.update.invalid_input', ['zoneId' => $id, 'errors' => array_values($errors)]);
        }

        if ($type === 'reverse' && ($prefix_length < 8 || $prefix_length > 128)) {
            return self::fail($m, 'error', 'zone.update.invalid_prefix_length', ['zoneId' => $id, 'prefixLength' => $prefix_length]);
        }

        $server_ids = $input->serverIds ?? [];
        $master_id  = $input->masterServerId;

        // Vorphase Serverzuweisung (admin), ungesperrt wie bisher
        $active_ids = [];
        $preChanged = false;
        $soa_ns_input = null; // geprüfter SOA-NS aus der Eingabe (nur Reverse ohne Serveränderung)
        if ($adminPath) {
            if (!is_array($server_ids) || count($server_ids) < 1) {
                return self::fail($m, 'error', 'zone.update.no_servers', ['zoneId' => $id]);
            }
            if (!$master_id || !in_array($master_id, $server_ids, true)) {
                return self::fail($m, 'error', 'zone.update.master_not_in_list', ['zoneId' => $id, 'masterId' => $master_id]);
            }

            $stmt = $this->pdo->query("SELECT id FROM servers WHERE active = 1");
            $active_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

            if (!in_array((int)$master_id, $active_ids, true)) {
                return self::fail($m, 'error', 'zone.update.master_inactive', ['zoneId' => $id, 'masterId' => $master_id]);
            }

            $stmt = $this->pdo->prepare("SELECT server_id, is_master FROM zone_servers WHERE zone_id = ?");
            $stmt->execute([$id]);
            $preChanged = self::assignmentDiff($stmt->fetchAll(PDO::FETCH_KEY_PAIR), $server_ids, $master_id, $active_ids)['changed'];

            if (!$preChanged) {
                if ($type !== 'forward') {
                    $soa_ns_input = trim($input->soaNs ?? '');
                    if (!isValidSoaName($soa_ns_input)) {
                        return self::fail($m, 'error', 'zone.update.invalid_soa_ns', ['zoneId' => $id]);
                    }
                }
            } elseif (!$this->acquireLock('update', $m)) {
                return self::fail($m, 'warning', 'zone.update.topology_locked', ['zoneId' => $id]);
            }
        } elseif ($type !== 'forward') {
            $soa_ns_input = trim($input->soaNs ?? '');
            if (!isValidSoaName($soa_ns_input)) {
                return self::fail($m, 'error', 'zone.update.invalid_soa_ns', ['zoneId' => $id]);
            }
        }

        try {
            // ---- Transaktion: Berechtigung und gesperrter Altstand (E12/E13/E14) ----
            try {
                $this->pdo->beginTransaction();

                if ($this->dbRole($actor->userId) !== $actor->role) {
                    $this->rollbackIfActive();
                    $this->releaseLock('update', $m);
                    return self::fail($m, 'audit', 'zone.update.access_denied', ['actorId' => $actor->userId, 'zoneId' => $id, 'reason' => 'db_role']);
                }
                if (!$adminPath) {
                    $stmt = $this->pdo->prepare("SELECT 1 FROM user_zones WHERE user_id = ? AND zone_id = ? LOCK IN SHARE MODE");
                    $stmt->execute([$actor->userId, $id]);
                    if (!$stmt->fetch()) {
                        $this->rollbackIfActive();
                        $this->releaseLock('update', $m);
                        return self::fail($m, 'audit', 'zone.update.access_denied', ['actorId' => $actor->userId, 'zoneId' => $id, 'reason' => 'db_assignment']);
                    }
                }

                $stmt = $this->pdo->prepare("
                    SELECT ttl, prefix_length, description, allow_dyndns, soa_ns, soa_mail,
                           soa_refresh, soa_retry, soa_expire, soa_minimum
                    FROM zones
                    WHERE id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$id]);
                $old = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    $this->rollbackIfActive();
                    $this->releaseLock('update', $m);
                    return self::fail($m, 'error', 'zone.update.not_found', ['zoneId' => $id]);
                }
            } catch (Throwable $e) {
                $this->rollbackIfActive();
                $this->releaseLock('update', $m);
                return self::fail($m, 'error', 'zone.update.failed', ['zoneId' => $id, 'name' => $name, 'detail' => $e->getMessage()]);
            }

            // ---- Serverzuweisung (admin) auf gesperrtem Stand ----
            $serverListChanged = false;
            $masterChanged = false;
            if ($adminPath) {
                try {
                    $stmt = $this->pdo->prepare("SELECT server_id, is_master FROM zone_servers WHERE zone_id = ? FOR UPDATE");
                    $stmt->execute([$id]);
                    $diff = self::assignmentDiff($stmt->fetchAll(PDO::FETCH_KEY_PAIR), $server_ids, $master_id, $active_ids);
                    $serverListChanged = $diff['listChanged'];
                    $masterChanged = $diff['masterChanged'];

                    if ($diff['changed'] && !$this->lock->isHeld()) {
                        // Zuweisung hat sich ohne gehaltenen Topologie-Lock verändert: keine Schreiboperation
                        $this->rollbackIfActive();
                        return self::fail($m, 'warning', 'zone.update.topology_locked', ['zoneId' => $id]);
                    }

                    if ($diff['changed']) {
                        $this->pdo->prepare("DELETE FROM zone_servers WHERE zone_id = ?")->execute([$id]);
                        $stmt = $this->pdo->prepare("INSERT INTO zone_servers (zone_id, server_id, is_master) VALUES (?, ?, ?)");
                        foreach ($diff['effective'] as $sid) {
                            $stmt->execute([$id, $sid, ((int)$sid === $diff['newMaster']) ? 1 : 0]);
                        }

                        $result = $this->build->rebuildNsGlueAndFlag($id);
                        if ($result['status'] === 'error') {
                            $this->rollbackIfActive();
                            $this->releaseLock('update', $m);
                            return self::fail($m, 'error', 'zone.update.ns_glue_failed', ['zoneId' => $id, 'output' => $result['output']]);
                        }
                        if ($result['status'] === 'warning') {
                            $m[] = new ServiceMessage('warning', 'zone.update.ns_glue_warning', ['zoneId' => $id, 'output' => $result['output']]);
                        }
                        $soa_ns = (string)$old['soa_ns']; // NS/Glue-Neuaufbau ändert soa_ns nicht
                    } else {
                        if ($type === 'forward') {
                            $soa_ns = (string)$old['soa_ns'];
                        } else {
                            // Vorphase sah eine Änderung, gesperrter Stand nicht: Eingabe jetzt wie bisher prüfen
                            $soa_ns_input ??= trim($input->soaNs ?? '');
                            if (!isValidSoaName($soa_ns_input)) {
                                $this->rollbackIfActive();
                                $this->releaseLock('update', $m);
                                return self::fail($m, 'error', 'zone.update.invalid_soa_ns', ['zoneId' => $id]);
                            }
                            $soa_ns = $soa_ns_input;
                        }
                    }
                } catch (Throwable $e) {
                    $this->rollbackIfActive();
                    $this->releaseLock('update', $m);
                    return self::fail($m, 'error', 'zone.update.server_assignment_failed', ['zoneId' => $id, 'detail' => $e->getMessage()]);
                }
            } else {
                $soa_ns = $type === 'forward' ? (string)$old['soa_ns'] : (string)$soa_ns_input;
            }

            // ---- Änderungserkennung auf gesperrtem Altstand (unverändert) ----
            $hasZoneRelevantChanges =
                (int)$old['ttl'] !== $ttl ||
                ($type === 'reverse' && (int)$old['prefix_length'] !== $prefix_length) ||
                rtrim($old['soa_ns'], '.') !== rtrim($soa_ns, '.') ||
                rtrim($old['soa_mail'], '.') !== rtrim($soa_mail, '.') ||
                (int)$old['soa_refresh'] !== $soa_refresh ||
                (int)$old['soa_retry'] !== $soa_retry ||
                (int)$old['soa_expire'] !== $soa_expire ||
                (int)$old['soa_minimum'] !== $soa_minimum;

            $hasNonCriticalChanges =
                trim((string)($old['description'] ?? '')) !== trim((string)($description ?? '')) ||
                (int)$old['allow_dyndns'] !== $allow_dyndns;

            if (!$hasZoneRelevantChanges && !$serverListChanged && !$masterChanged && !$hasNonCriticalChanges) {
                $this->rollbackIfActive();
                $this->releaseLock('update', $m);
                $m[] = new ServiceMessage('success', 'zone.update.unchanged', ['zoneId' => $id, 'name' => $name]);
                return new ServiceResult(true, $m, $id);
            }

            // ---- Metadaten + ggf. Prüfung der Zonendatei ----
            try {
                $stmt = $this->pdo->prepare("
                    UPDATE zones SET
                        ttl = ?, prefix_length = ?, description = ?,
                        allow_dyndns = ?,
                        soa_ns = ?, soa_mail = ?,
                        soa_refresh = ?, soa_retry = ?, soa_expire = ?, soa_minimum = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $ttl,
                    $type === 'reverse' ? $prefix_length : null,
                    $description,
                    $allow_dyndns,
                    $soa_ns,
                    $soa_mail,
                    $soa_refresh,
                    $soa_retry,
                    $soa_expire,
                    $soa_minimum,
                    $id,
                ]);

                if ($hasZoneRelevantChanges) {
                    $rebuild = $this->build->validateAndFlag($id);
                    if ($rebuild['status'] === 'error') {
                        $this->rollbackIfActive();
                        $this->releaseLock('update', $m);
                        return self::fail($m, 'error', 'zone.update.zonefile_invalid', ['zoneId' => $id, 'output' => $rebuild['output']]);
                    }
                    if ($rebuild['status'] === 'warning') {
                        $m[] = new ServiceMessage('warning', 'zone.update.zonefile_warning', ['zoneId' => $id, 'output' => $rebuild['output']]);
                    }
                }

                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->rollbackIfActive();
                $this->releaseLock('update', $m);
                return self::fail($m, 'error', 'zone.update.failed', ['zoneId' => $id, 'name' => $name, 'detail' => $e->getMessage()]);
            }

            $this->releaseLock('update', $m);
            $m[] = new ServiceMessage('success', 'zone.update.success', ['zoneId' => $id, 'name' => $name]);

            return new ServiceResult(true, $m, $id);
        } finally {
            $this->rollbackIfActive();
            $this->releaseLock('update', $m);
        }
    }

    /* ======================================================================================== */
    /* Löschen                                                                                  */
    /* ======================================================================================== */

    /**
     * Löscht eine Zone (nur admin) samt abhängiger Daten (FK-Cascade) und markiert BIND als "dirty".
     *
     * Codes: access_denied, invalid_id, not_found, lock_acquire_error, topology_locked,
     *        lock_release_unconfirmed, lock_release_error, failed, success
     */
    public function deleteZone(Actor $actor, int $zoneId): ServiceResult
    {
        $m = [];
        $id = $zoneId;

        if (!$actor->isAdmin()) {
            return self::fail($m, 'audit', 'zone.delete.access_denied', ['actorId' => $actor->userId, 'zoneId' => $id, 'reason' => 'role']);
        }
        if ($id <= 0) {
            return self::fail($m, 'error', 'zone.delete.invalid_id', ['zoneId' => $id]);
        }

        $stmt = $this->pdo->prepare("SELECT name FROM zones WHERE id = ?");
        $stmt->execute([$id]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zone) {
            return self::fail($m, 'error', 'zone.delete.not_found', ['zoneId' => $id]);
        }
        $name = $zone['name'];

        if (!$this->acquireLock('delete', $m)) {
            return self::fail($m, 'warning', 'zone.delete.topology_locked', ['zoneId' => $id, 'name' => $name]);
        }

        $ok = false;
        try {
            $this->pdo->beginTransaction();

            if ($this->dbRole($actor->userId) !== 'admin') {
                $this->rollbackIfActive();
                $this->releaseLock('delete', $m); // vor der Ablehnung, da der Web-Adapter dort abbricht
                $m[] = new ServiceMessage('audit', 'zone.delete.access_denied', ['actorId' => $actor->userId, 'zoneId' => $id, 'reason' => 'db_role']);
            } else {
                // R4/E42: Zuweisungen der Zone VOR der Zonenzeile exklusiv sperren (Index zone_id des
                // Fremdschlüssels). Sonst entstünde die Sperre auf user_zones erst durch das kaskadierende
                // DELETE nach zones (X) – umgekehrt zur Reihenfolge users → user_zones → zones des
                // RecordService (lockZoneWritePermissions) und damit ein möglicher Sperrzyklus.
                $stmt = $this->pdo->prepare("SELECT user_id FROM user_zones WHERE zone_id = ? ORDER BY user_id FOR UPDATE");
                $stmt->execute([$id]);
                $stmt->fetchAll();

                $stmt = $this->pdo->prepare("SELECT id FROM zones WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) {
                    $this->rollbackIfActive();
                    $m[] = new ServiceMessage('error', 'zone.delete.not_found', ['zoneId' => $id]);
                } else {
                    $this->pdo->prepare("DELETE FROM zones WHERE id = ?")->execute([$id]);
                    $this->pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
                    $this->pdo->commit();
                    $ok = true;
                    $m[] = new ServiceMessage('success', 'zone.delete.success', ['zoneId' => $id, 'name' => $name]);
                }
            }
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            $m[] = new ServiceMessage('error', 'zone.delete.failed', ['zoneId' => $id, 'name' => $name, 'detail' => $e->getMessage()]);
        } finally {
            // Lock nach Commit bzw. Rollback freigeben (wie bisher nach der Meldung)
            $this->releaseLock('delete', $m);
        }

        return new ServiceResult($ok, $m, $id);
    }

    /* ======================================================================================== */
    /* Hilfsfunktionen                                                                          */
    /* ======================================================================================== */

    /**
     * Vergleich der Serverzuweisung (unveränderte Logik aus zone_update.php).
     *
     * @param array<int|string, int|string> $old_servers [server_id => is_master]
     * @return array{changed: bool, listChanged: bool, masterChanged: bool, effective: int[], newMaster: int}
     */
    private static function assignmentDiff(array $old_servers, array $server_ids, mixed $master_id, array $active_ids): array
    {
        $old_server_ids = array_keys($old_servers);
        $old_master_id  = array_search(1, $old_servers, true); // erster mit is_master = 1
        $new_master_id  = (int)$master_id;

        // Inaktive alte Slaves erhalten (Formular übermittelt sie nicht)
        $preserve_inactive_slaves = [];
        foreach ($old_servers as $sid => $is_master) {
            if (!in_array($sid, $active_ids, true) && (int)$is_master !== 1) {
                $preserve_inactive_slaves[] = (int)$sid;
            }
        }

        $effective_new_server_ids = array_unique(array_merge(array_map('intval', $server_ids), $preserve_inactive_slaves));
        sort($effective_new_server_ids);
        sort($old_server_ids);

        $listChanged   = $effective_new_server_ids !== $old_server_ids;
        $masterChanged = (int)$old_master_id !== $new_master_id;

        return [
            'changed'       => $listChanged || $masterChanged,
            'listChanged'   => $listChanged,
            'masterChanged' => $masterChanged,
            'effective'     => $effective_new_server_ids,
            'newMaster'     => $new_master_id,
        ];
    }

    /** DB-Rolle des Akteurs unter geteilter Zeilensperre (null = Benutzer existiert nicht) */
    private function dbRole(int $userId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ? LOCK IN SHARE MODE");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();

        return $role === false ? null : (string)$role;
    }

    /** @param ServiceMessage[] $m */
    private function acquireLock(string $op, array &$m): bool
    {
        $r = $this->lock->acquire();
        if ($r['error'] !== null) {
            $m[] = new ServiceMessage('warning', "zone.$op.lock_acquire_error", ['lock' => TopologyLock::NAME, 'detail' => $r['error']]);
        }

        return $r['acquired'];
    }

    /** @param ServiceMessage[] $m */
    private function releaseLock(string $op, array &$m): void
    {
        $r = $this->lock->release();
        if ($r === null) {
            return;
        }
        if ($r['error'] !== null) {
            $m[] = new ServiceMessage('warning', "zone.$op.lock_release_error", ['lock' => TopologyLock::NAME, 'detail' => $r['error']]);
        } elseif (!$r['confirmed']) {
            $m[] = new ServiceMessage('warning', "zone.$op.lock_release_unconfirmed", ['lock' => TopologyLock::NAME, 'result' => $r['result']]);
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @param ServiceMessage[] $m */
    private static function fail(array $m, string $kind, string $code, array $context, ?int $zoneId = null): ServiceResult
    {
        $m[] = new ServiceMessage($kind, $code, $context);

        return new ServiceResult(false, $m, $zoneId);
    }
}
