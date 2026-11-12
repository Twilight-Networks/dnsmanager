<?php
/**
 * Datei: inc/app/servers/ServerService.php
 * Zweck: Schreibende Serververwaltung (Anlegen, Ändern, Löschen) und BIND-Reload eines Servers für beliebige
 *        Aufrufkanäle (Web, CLI, späterer MCP-Adapter).
 *
 * Keine Session, kein HTTP, keine Toasts, kein Logging, keine Sprachschlüssel. Ergebnis: ServiceResult mit
 * ServiceMessages (Codes "server.<operation>.<ergebnis>", Rohdaten im Kontext). Reihenfolge der Messages wie
 * bisher die Reihenfolge der Toasts/Logs in actions/server_*.php bzw. actions/bind_reload.php.
 *
 * Berechtigung: nur admin. Die Rolle im Actor kann nur ablehnen. Maßgeblich ist der Datenbankstand des
 * Akteurs (Rolle admin und Konto aktiv, S27):
 * - Anlegen/Ändern/Löschen: in der schreibenden Transaktion unter geteilter Zeilensperre (users LOCK IN SHARE
 *   MODE, wie ZoneService) als erste Anweisung der Transaktion.
 * - BIND-Reload (keine Transaktion): Momentaufnahme über ActorAccess.
 * Abweichung → "<operation>.access_denied" (reason role | db_role).
 *
 * Sperren:
 * - Ändern: Topologie-Lock (Try-Lock, nur wenn sich "active" ändert, wie bisher) → Transaktion → users (S)
 *   → servers (X, Zielzeile) → ggf. servers (X, bisher lokaler Server) → system_status (X, nur Reaktivierung)
 *   → Commit → NS/Glue-Neuaufbau der zugewiesenen Zonen (ZoneBuildBackend, bei Änderung von Name/DNS-IP)
 *   → Freigabe des Topologie-Locks. Die Master-Prüfung bei Deaktivierung läuft wie bisher nach dem
 *   Topologie-Lock; Serverzuweisungen ändern sich nur unter demselben Lock (ZoneService).
 * - Anlegen: Transaktion → users (S) → servers (X, vorhandene lokale Server) → INSERT. Die Prüfung "nur ein
 *   lokaler Server" läuft für die bisherige Meldungsreihenfolge zusätzlich ungesperrt in der Validierung und
 *   wird auf dem gesperrten Stand wiederholt.
 * - Löschen: Transaktion → users (S) → servers (X, Zielzeile) → zone_servers (S) → Löschen von Diagnosen,
 *   Diagnoseprotokoll und Server. Die Zuweisungsprüfung erfolgt damit auf dem gesperrten Stand (eine
 *   parallele Zuweisung wartet auf die Serverzeile, zone_servers.server_id hat ON DELETE CASCADE).
 * - Deadlocks sind nicht grundsätzlich ausgeschlossen; sie führen zu vollständigem Rollback und
 *   "<operation>.failed". Keine automatischen Wiederholungen.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ActorAccess.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../TopologyLock.php';
require_once __DIR__ . '/../zones/ZoneBuildBackend.php';
require_once __DIR__ . '/../../validators.php';
require_once __DIR__ . '/ServerControlBackend.php';
require_once __DIR__ . '/ServerInput.php';

final class ServerService
{
    private PDO $pdo;
    private ZoneBuildBackend $build;
    private TopologyLock $lock;
    private ?ServerControlBackend $control;

    public function __construct(PDO $pdo, ZoneBuildBackend $build, TopologyLock $lock, ?ServerControlBackend $control = null)
    {
        $this->pdo = $pdo;
        $this->build = $build;
        $this->lock = $lock;
        $this->control = $control;
    }

    /* ======================================================================================== */
    /* Anlegen                                                                                  */
    /* ======================================================================================== */

    /**
     * Legt einen Server an (nur admin).
     *
     * Codes: access_denied, invalid_input (errors: Liste von Fehlercodes), local_exists, failed (detail),
     *        success (serverId, name, dnsIp4, dnsIp6, isLocal, active)
     */
    public function createServer(Actor $actor, ServerInput $input): ServiceResult
    {
        if (!$actor->isAdmin()) {
            return self::fail('audit', 'server.create.access_denied', ['actorId' => $actor->userId, 'reason' => 'role']);
        }

        $errors = self::validate($input, null);
        // Nur ein lokaler Server erlaubt (ungesperrt für die bisherige Meldungsreihenfolge, gesperrt wiederholt)
        if ($input->isLocal && $this->localServerIds(false, 0) !== []) {
            $errors[] = 'local_exists';
        }
        if ($errors !== []) {
            return self::fail('error', 'server.create.invalid_input', ['errors' => $errors]);
        }

        $row = self::rowFromInput($input);
        try {
            $this->pdo->beginTransaction();

            if ($this->dbRole($actor->userId) !== 'admin') {
                $this->rollbackIfActive();
                return self::fail('audit', 'server.create.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role']);
            }
            if ($input->isLocal && $this->localServerIds(true, 0) !== []) {
                $this->rollbackIfActive();
                return self::fail('error', 'server.create.invalid_input', ['errors' => ['local_exists']]);
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO servers (name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active)
                VALUES (:name, :dns_ip4, :dns_ip6, :api_ip, :api_token, :is_local, :active)
            ");
            $stmt->execute([
                ':name'      => $row['name'],
                ':dns_ip4'   => $row['dns_ip4'],
                ':dns_ip6'   => $row['dns_ip6'],
                ':api_ip'    => $row['api_ip'],
                ':api_token' => $row['api_token'],
                ':is_local'  => $row['is_local'],
                ':active'    => $row['active'],
            ]);
            $serverId = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::fail('error', 'server.create.failed', ['name' => $input->name, 'detail' => $e->getMessage()]);
        }

        return new ServiceResult(true, [new ServiceMessage('success', 'server.create.success', [
            'serverId' => $serverId,
            'name'     => $input->name,
            'dnsIp4'   => $input->dnsIp4,
            'dnsIp6'   => $input->dnsIp6,
            'isLocal'  => $row['is_local'],
            'active'   => $row['active'],
        ])]);
    }

    /* ======================================================================================== */
    /* Ändern                                                                                   */
    /* ======================================================================================== */

    /**
     * Ändert einen Server (nur admin). Bei Änderung von Name oder DNS-IP: NS/Glue-Neuaufbau aller Zonen, denen
     * der Server zugewiesen ist. Reaktivierung setzt system_status.bind_dirty = 1.
     *
     * Codes: access_denied, invalid_input (errors), not_found, unchanged, lock_acquire_error, topology_locked,
     *        master_deactivation_blocked, failed (detail), zone_rebuild_error / zone_rebuild_warning (zoneId,
     *        output; je Zone), success (serverId, name), lock_release_unconfirmed, lock_release_error
     */
    public function updateServer(Actor $actor, int $serverId, ServerInput $input): ServiceResult
    {
        $m = [];
        $ctx = ['serverId' => $serverId, 'name' => $input->name];
        if (!$actor->isAdmin()) {
            return self::fail('audit', 'server.update.access_denied', ['actorId' => $actor->userId, 'reason' => 'role'] + $ctx);
        }

        $errors = self::validate($input, $serverId);
        if ($errors !== []) {
            return self::fail('error', 'server.update.invalid_input', ['errors' => $errors] + $ctx);
        }

        // Bestehende Daten (ungesperrt, wie bisher; für Meldungsreihenfolge und Änderungserkennung)
        $stmt = $this->pdo->prepare("
            SELECT name, dns_ip4, dns_ip6, api_ip, api_token, is_local, active
            FROM servers
            WHERE id = ?
        ");
        $stmt->execute([$serverId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$existing) {
            return self::fail('error', 'server.update.not_found', $ctx);
        }

        $update_data = self::rowFromInput($input);

        // Änderungserkennung: unveränderte Ausdrücke aus actions/server_update.php (inkl. Typvergleich)
        $server_data_changed = (
            $existing['name']    !== $update_data['name'] ||
            $existing['dns_ip4'] !== $update_data['dns_ip4'] ||
            $existing['dns_ip6'] !== $update_data['dns_ip6']
        );
        $nonCriticalChanged =
            $existing['active'] !== $update_data['active'] ||
            $existing['is_local'] !== $update_data['is_local'] ||
            $existing['api_ip'] !== $update_data['api_ip'] ||
            $existing['api_token'] !== $update_data['api_token'];

        if (!$server_data_changed && !$nonCriticalChanged) {
            return new ServiceResult(true, [new ServiceMessage('success', 'server.update.unchanged', $ctx)]);
        }

        // Topologieänderung im Sinne von Publish All: ausschließlich servers.active
        $activeChanged = (int)$existing['active'] !== (int)$update_data['active'];
        $reactivated   = $activeChanged && (int)$update_data['active'] === 1;

        if ($activeChanged && !$this->acquireLock($m)) {
            $m[] = new ServiceMessage('warning', 'server.update.topology_locked', ['lock' => TopologyLock::NAME] + $ctx);
            return new ServiceResult(false, $m);
        }

        // Kein aktiver Master-Server darf deaktiviert werden
        if ($update_data['active'] === 0) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM zone_servers WHERE server_id = ? AND is_master = 1");
            $stmt->execute([$serverId]);
            $count = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($count > 0) {
                $m[] = new ServiceMessage('error', 'server.update.master_deactivation_blocked', $ctx);
                $this->releaseLock($m);
                return new ServiceResult(false, $m);
            }
        }

        $ok = false;
        try {
            $this->pdo->beginTransaction();

            if ($this->dbRole($actor->userId) !== 'admin') {
                $this->rollbackIfActive();
                $m[] = new ServiceMessage('audit', 'server.update.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role'] + $ctx);
                $this->releaseLock($m);
                return new ServiceResult(false, $m);
            }

            // Zielzeile sperren (serialisiert parallele Änderungen desselben Servers)
            $stmt = $this->pdo->prepare('SELECT id FROM servers WHERE id = ? FOR UPDATE');
            $stmt->execute([$serverId]);
            $lockedRow = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($lockedRow === false) {
                $this->rollbackIfActive();
                $m[] = new ServiceMessage('error', 'server.update.not_found', $ctx);
                $this->releaseLock($m);
                return new ServiceResult(false, $m);
            }

            if ($update_data['is_local']) {
                $stmt = $this->pdo->prepare("UPDATE servers SET is_local = 0 WHERE is_local = 1 AND id != ?");
                $stmt->execute([$serverId]);
            }

            $stmt = $this->pdo->prepare("
                UPDATE servers
                SET name = :name,
                    dns_ip4 = :dns_ip4,
                    dns_ip6 = :dns_ip6,
                    api_ip = :api_ip,
                    api_token = :api_token,
                    is_local = :is_local,
                    active = :active
                WHERE id = :id
            ");
            $stmt->execute($update_data + [':id' => $serverId]);

            // Reaktivierung (0 -> 1): Server kann einen älteren DNS-Stand besitzen → Full-Rebuild ausstehend
            // (bind_dirty = 1, Tri-State siehe PublishService). Deaktivierung setzt kein bind_dirty.
            if ($reactivated) {
                $this->pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
            }

            $this->pdo->commit();

            // NS/Glue-Neuaufbau für alle zugewiesenen Zonen bei Änderung von Name/DNS-IP (wie bisher nach Commit)
            if ($server_data_changed) {
                $stmt = $this->pdo->prepare("SELECT zone_id FROM zone_servers WHERE server_id = ?");
                $stmt->execute([$serverId]);
                $zone_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($zone_ids as $zid) {
                    $result = $this->build->rebuildNsGlueAndFlag((int)$zid);
                    if ($result['status'] === 'error') {
                        $m[] = new ServiceMessage('error', 'server.update.zone_rebuild_error', ['zoneId' => $zid, 'output' => $result['output']] + $ctx);
                    } elseif ($result['status'] === 'warning') {
                        $m[] = new ServiceMessage('warning', 'server.update.zone_rebuild_warning', ['zoneId' => $zid, 'output' => $result['output']] + $ctx);
                    }
                }
            }

            $m[] = new ServiceMessage('success', 'server.update.success', $ctx);
            $ok = true;
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            $m[] = new ServiceMessage('error', 'server.update.failed', ['detail' => $e->getMessage()] + $ctx);
        }

        // Lock (falls gehalten) nach Commit/Rollback und Zonen-Rebuilds freigeben
        $this->releaseLock($m);

        return new ServiceResult($ok, $m);
    }

    /* ======================================================================================== */
    /* Löschen                                                                                  */
    /* ======================================================================================== */

    /**
     * Löscht einen Server (nur admin), sofern er keiner Zone zugewiesen ist; entfernt zugehörige Diagnosen.
     *
     * Codes: access_denied, invalid_id, not_found, assigned (name), failed (name, detail), success (name)
     */
    public function deleteServer(Actor $actor, int $serverId): ServiceResult
    {
        $ctx = ['serverId' => $serverId];
        if (!$actor->isAdmin()) {
            return self::fail('audit', 'server.delete.access_denied', ['actorId' => $actor->userId, 'reason' => 'role'] + $ctx);
        }
        if ($serverId < 1) {
            return self::fail('error', 'server.delete.invalid_id', $ctx);
        }

        $name = null;
        try {
            $this->pdo->beginTransaction();

            if ($this->dbRole($actor->userId) !== 'admin') {
                $this->rollbackIfActive();
                return self::fail('audit', 'server.delete.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role'] + $ctx);
            }

            $stmt = $this->pdo->prepare("SELECT name FROM servers WHERE id = ? FOR UPDATE");
            $stmt->execute([$serverId]);
            $name = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($name === false) {
                $this->rollbackIfActive();
                return self::fail('error', 'server.delete.not_found', $ctx);
            }
            $ctx['name'] = (string)$name;

            // Noch einer Zone zugewiesen (Master oder Slave)? Geprüft auf dem gesperrten Stand
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM zone_servers WHERE server_id = ? LOCK IN SHARE MODE");
            $stmt->execute([$serverId]);
            $usage = (int)$stmt->fetchColumn();
            $stmt->closeCursor();
            if ($usage > 0) {
                $this->rollbackIfActive();
                return self::fail('error', 'server.delete.assigned', $ctx);
            }

            $this->pdo->prepare("DELETE FROM diagnostics WHERE server_id = ?")->execute([$serverId]);
            $this->pdo->prepare("DELETE FROM diagnostic_log WHERE server_id = ?")->execute([$serverId]);
            $this->pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$serverId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            return self::fail('error', 'server.delete.failed', $ctx + ['name' => is_string($name) ? $name : '', 'detail' => $e->getMessage()]);
        }

        return new ServiceResult(true, [new ServiceMessage('success', 'server.delete.success', $ctx)]);
    }

    /* ======================================================================================== */
    /* BIND-Reload                                                                              */
    /* ======================================================================================== */

    /**
     * Lädt BIND auf einem Server neu (nur admin) über das ServerControlBackend.
     *
     * Codes: access_denied, not_found, failed (name, message), success (name, message)
     */
    public function reloadBind(Actor $actor, int $serverId): ServiceResult
    {
        $ctx = ['serverId' => $serverId];
        if (!$actor->isAdmin()) {
            return self::fail('audit', 'server.reload.access_denied', ['actorId' => $actor->userId, 'reason' => 'role'] + $ctx);
        }
        if (!(new ActorAccess($this->pdo))->isActive($actor)) {
            return self::fail('audit', 'server.reload.access_denied', ['actorId' => $actor->userId, 'reason' => 'db_role'] + $ctx);
        }
        if ($this->control === null) {
            throw new LogicException('ServerService::reloadBind() benötigt ein ServerControlBackend.');
        }

        $stmt = $this->pdo->prepare("SELECT name, api_ip, api_token FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$server) {
            return self::fail('error', 'server.reload.not_found', $ctx);
        }
        $ctx['name'] = (string)$server['name'];

        $r = $this->control->reloadBind($server['api_ip'] === null ? null : (string)$server['api_ip'], (string)$server['api_token']);
        $ctx['message'] = $r['message'];
        if ($r['status'] === 'ok') {
            return new ServiceResult(true, [new ServiceMessage('success', 'server.reload.success', $ctx)]);
        }

        return self::fail('error', 'server.reload.failed', $ctx);
    }

    /* ======================================================================================== */
    /* Hilfen                                                                                   */
    /* ======================================================================================== */

    /**
     * Eingabevalidierung (unveränderte Regeln und Reihenfolge aus actions/server_add.php bzw. server_update.php).
     * $serverId = null beim Anlegen (keine ID-Prüfung).
     *
     * @return string[] Fehlercodes (invalid_id, invalid_name, invalid_dns_ip, invalid_ipv4, invalid_ipv6,
     *                  invalid_api_ip, api_ip_required, api_token_required)
     */
    private static function validate(ServerInput $in, ?int $serverId): array
    {
        $errors = [];
        if ($serverId !== null && $serverId < 1) {
            $errors[] = 'invalid_id';
        }
        $name = $in->name;
        if ($name === '' || strlen($name) > 100 || !isValidFqdn($name) || substr_count(rtrim($name, '.'), '.') < 1) {
            $errors[] = 'invalid_name';
        }
        $valid4 = filter_var($in->dnsIp4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $valid6 = filter_var($in->dnsIp6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if (!$valid4 && !$valid6) {
            $errors[] = 'invalid_dns_ip';
        }
        if ($in->dnsIp4 !== '' && !$valid4) {
            $errors[] = 'invalid_ipv4';
        }
        if ($in->dnsIp6 !== '' && !$valid6) {
            $errors[] = 'invalid_ipv6';
        }
        if ($in->apiIp !== '' && !filter_var($in->apiIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'invalid_api_ip';
        }
        if (!$in->isLocal && $in->apiIp === '') {
            $errors[] = 'api_ip_required';
        }
        if (!$in->isLocal && ($in->apiToken === '' || strlen($in->apiToken) < 32)) {
            $errors[] = 'api_token_required';
        }

        return $errors;
    }

    /**
     * Spaltenwerte wie bisher in den Actions (ungültige/leere DNS-IP → NULL, leere API-IP → NULL, Flags 0/1).
     *
     * @return array{name: string, dns_ip4: ?string, dns_ip6: ?string, api_ip: ?string, api_token: string, is_local: int, active: int}
     */
    private static function rowFromInput(ServerInput $in): array
    {
        return [
            'name'      => $in->name,
            'dns_ip4'   => filter_var($in->dnsIp4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $in->dnsIp4 : null,
            'dns_ip6'   => filter_var($in->dnsIp6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $in->dnsIp6 : null,
            'api_ip'    => $in->apiIp !== '' ? $in->apiIp : null,
            'api_token' => $in->apiToken,
            'is_local'  => $in->isLocal ? 1 : 0,
            'active'    => $in->active ? 1 : 0,
        ];
    }

    /**
     * IDs der als lokal markierten Server (ohne $exceptId); $lock = true sperrt die Zeilen (FOR UPDATE).
     *
     * @return int[]
     */
    private function localServerIds(bool $lock, int $exceptId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM servers WHERE is_local = 1 AND id <> ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$exceptId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt->closeCursor();

        return $ids;
    }

    /** DB-Rolle des Akteurs unter geteilter Zeilensperre (null = nicht vorhanden oder deaktiviert; wie ZoneService) */
    private function dbRole(int $userId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT role, active FROM users WHERE id = ? LOCK IN SHARE MODE");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return ($row === false || (int)$row['active'] !== 1) ? null : (string)$row['role'];
    }

    /** @param ServiceMessage[] $m */
    private function acquireLock(array &$m): bool
    {
        $r = $this->lock->acquire();
        if ($r['error'] !== null) {
            $m[] = new ServiceMessage('warning', 'server.update.lock_acquire_error', ['lock' => TopologyLock::NAME, 'detail' => $r['error']]);
        }

        return $r['acquired'];
    }

    /** @param ServiceMessage[] $m */
    private function releaseLock(array &$m): void
    {
        $r = $this->lock->release();
        if ($r === null) {
            return;
        }
        if ($r['error'] !== null) {
            $m[] = new ServiceMessage('warning', 'server.update.lock_release_error', ['lock' => TopologyLock::NAME, 'detail' => $r['error']]);
        } elseif (!$r['confirmed']) {
            $m[] = new ServiceMessage('warning', 'server.update.lock_release_unconfirmed', ['lock' => TopologyLock::NAME, 'result' => $r['result']]);
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function fail(string $kind, string $code, array $context): ServiceResult
    {
        return new ServiceResult(false, [new ServiceMessage($kind, $code, $context)]);
    }
}
