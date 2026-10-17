<?php
/**
 * Datei: inc/app/records/RecordService.php
 * Zweck: Application Service für DNS-Record-Operationen (Pilot: Löschen).
 *
 * Der Service kennt keine Web-Schicht: keine Session, kein HTTP, keine Toasts,
 * keine Übersetzungen, kein HTML. Er arbeitet mit einem Actor und einer
 * ZoneAccess-Komponente und meldet Ereignisse als ServiceMessage-Objekte
 * innerhalb eines ServiceResult zurück.
 *
 * Voraussetzung (Legacy): Die Funktionen aus inc/helpers.php
 * (rebuild_zone_and_flag_if_valid) müssen geladen sein; sie arbeiten weiterhin
 * über $GLOBALS['pdo']. Der Aufrufer übergibt daher dasselbe PDO-Objekt.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ZoneAccess.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../../glue_check.php';
require_once __DIR__ . '/../../validators.php';
require_once __DIR__ . '/RecordInput.php';

final class RecordService
{
    private PDO $pdo;
    private ZoneAccess $zoneAccess;

    public function __construct(PDO $pdo, ZoneAccess $zoneAccess)
    {
        $this->pdo = $pdo;
        $this->zoneAccess = $zoneAccess;
    }

    /**
     * Löscht die angegebenen Records atomar in einer Transaktion.
     *
     * Ein Fehler bei einem Record (fehlender Record, fehlende Berechtigung,
     * Glue-/NS-Schutz, Rebuild-Fehler) führt zum vollständigen Rollback.
     *
     * @param Actor $actor
     * @param int[] $ids Record-IDs
     *
     * @return ServiceResult
     */
    public function deleteRecords(Actor $actor, array $ids): ServiceResult
    {
        $pdo = $this->pdo;
        $messages = [];
        $lastZoneId = null;

        $pdo->beginTransaction();

        try {
            $affected_zones = [];

            foreach ($ids as $id) {
                $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ?");
                $stmt->execute([$id]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$record) {
                    throw new RuntimeException("Record mit ID $id nicht gefunden.");
                }

                $zone_id = (int)$record['zone_id'];
                $lastZoneId = $zone_id;
                $zone_name = getZoneName($pdo, $zone_id);

                // Rechte prüfen
                if (!$this->zoneAccess->canWriteZone($actor, $zone_id)) {
                    $messages[] = new ServiceMessage('audit', 'record.delete.access_denied', [
                        'userId'   => $actor->userId,
                        'recordId' => $id,
                        'zoneId'   => $zone_id,
                    ]);
                    throw new RuntimeException("Keine Berechtigung für Record-ID $id.");
                }

                // Glue-Check
                $all_records = getAllZoneRecords($pdo, $zone_id);
                if (isGlueRecord($record, $all_records, $zone_name)) {
                    $messages[] = new ServiceMessage('error', 'record.delete.glue_protected', [
                        'recordName' => $record['name'],
                        'zoneName'   => $zone_name,
                    ]);
                    throw new RuntimeException("Löschen von Glue-Record nicht erlaubt.");
                }

                // Geschützte NS-Records dürfen nicht gelöscht werden
                if ($record['type'] === 'NS' && isProtectedNsRecord($record, $all_records, $zone_name, $pdo, $zone_id)) {
                    $messages[] = new ServiceMessage('error', 'record.delete.ns_protected', [
                        'recordContent' => $record['content'],
                        'zoneName'      => $zone_name,
                    ]);
                    throw new RuntimeException("Löschen von geschütztem NS-Record nicht erlaubt.");
                }

                // Löschen
                $stmt = $pdo->prepare("DELETE FROM records WHERE id = ?");
                $stmt->execute([$id]);

                // Merken, welche Zonen betroffen sind
                $affected_zones[$zone_id] = $zone_name;
            }

            // Zonendateien prüfen
            foreach ($affected_zones as $zone_id => $zone_name) {
                // Wie bisher: die Schleifenvariable bestimmt auch die letzte Zone
                $lastZoneId = $zone_id;

                $rebuild = rebuild_zone_and_flag_if_valid($zone_id);

                if ($rebuild['status'] === 'error') {
                    throw new RuntimeException("Zonendatei-Fehler nach Löschung in Zone '{$zone_name}': {$rebuild['output']}");
                }

                if ($rebuild['status'] === 'warning') {
                    $messages[] = new ServiceMessage('warning', 'record.delete.zonefile_warning', [
                        'zoneName' => $zone_name,
                        'output'   => $rebuild['output'],
                    ]);
                }
            }

            $pdo->commit();
            $messages[] = new ServiceMessage('success', 'record.delete.success');

            return new ServiceResult(true, $messages, $lastZoneId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $messages[] = new ServiceMessage('error', 'record.delete.failed', [
                'detail' => $e->getMessage(),
            ]);

            return new ServiceResult(false, $messages, $lastZoneId);
        }
    }

    /**
     * Aktualisiert einen bestehenden Record.
     *
     * Ablauf: Berechtigung -> Validierung -> Record laden -> No-Change-Prüfung
     * -> Glue-Schutz -> NS-Schutz -> Transaktion (UPDATE, Rebuild, Commit).
     * Die Transaktion beginnt erst nach allen Vorprüfungen.
     *
     * @param Actor       $actor
     * @param int         $recordId
     * @param int         $zoneId
     * @param RecordInput $input
     *
     * @return ServiceResult
     */
    public function updateRecord(Actor $actor, int $recordId, int $zoneId, RecordInput $input): ServiceResult
    {
        $pdo = $this->pdo;
        $messages = [];

        $type    = $input->type;
        $name    = $input->name;
        $content = $input->content;
        $ttl     = $input->ttl;

        // Berechtigung prüfen
        if (!$this->zoneAccess->canWriteZone($actor, $zoneId)) {
            $messages[] = new ServiceMessage('audit', 'record.update.access_denied', [
                'userId' => $actor->userId,
                'zoneId' => $zoneId,
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Zonenname (für Meldungen ohne abschließenden Punkt, für Helper unverändert)
        $zoneNameRaw = getZoneName($pdo, $zoneId);
        $zoneName    = rtrim($zoneNameRaw, '.');

        // Validierung
        $validatorType = $input->validationType ?? (($type === 'TXT' && $input->isDkim) ? 'DKIM' : $type);
        $errors = validateDnsRecord($validatorType, $name, $content, $ttl);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $messages[] = new ServiceMessage('error', 'record.update.validation_error', [
                    'errorCode' => $error,
                    'type'      => $type,
                    'name'      => $name,
                    'zoneName'  => $zoneName,
                ]);
            }

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Record holen
        $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ? AND zone_id = ?");
        $stmt->execute([$recordId, $zoneId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $messages[] = new ServiceMessage('error', 'record.update.not_found', [
                'recordId' => $recordId,
                'zoneId'   => $zoneId,
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Änderungen vergleichen: Wenn sich nichts geändert hat, abbrechen
        $hasChanged = false;
        $hasChanged |= trim($existing['name']) !== trim($name);
        $hasChanged |= trim($existing['type']) !== trim($type);
        $hasChanged |= trim($existing['content']) !== trim($content);
        $hasChanged |= (int)$existing['ttl'] !== (int)$ttl;

        if (!$hasChanged) {
            $messages[] = new ServiceMessage('success', 'record.update.no_changes', [
                'zoneId' => $zoneId,
            ]);

            return new ServiceResult(true, $messages, $zoneId);
        }

        // Glue-Check
        if (isGlueRecord($existing, getAllZoneRecords($pdo, $zoneId), $zoneNameRaw)) {
            $violation = false;

            if ($existing['name'] !== $name) {
                $messages[] = new ServiceMessage('error', 'record.update.glue_name_change', [
                    'existingName' => $existing['name'],
                    'zoneName'     => $zoneName,
                ]);
                $violation = true;
            }
            if ($existing['type'] !== $type) {
                $messages[] = new ServiceMessage('error', 'record.update.glue_type_change', [
                    'existingType' => $existing['type'],
                    'zoneName'     => $zoneName,
                ]);
                $violation = true;
            }
            if ($violation) {
                return new ServiceResult(false, $messages, $zoneId);
            }
        }

        // Geschützte NS-Records dürfen nicht bearbeitet werden
        if (
            $existing['type'] === 'NS' &&
            isProtectedNsRecord($existing, getAllZoneRecords($pdo, $zoneId), $zoneNameRaw, $pdo, $zoneId)
        ) {
            $violation = false;

            if ($existing['name'] !== $name) {
                $messages[] = new ServiceMessage('error', 'record.update.ns_name_change', [
                    'existingName' => $existing['name'],
                    'zoneName'     => $zoneName,
                ]);
                $violation = true;
            }
            if ($existing['content'] !== $content) {
                $messages[] = new ServiceMessage('error', 'record.update.ns_content_change', [
                    'existingContent' => $existing['content'],
                    'zoneName'        => $zoneName,
                ]);
                $violation = true;
            }
            if ($violation) {
                return new ServiceResult(false, $messages, $zoneId);
            }
        }

        // Update durchführen
        // Transaktion starten
        $pdo->beginTransaction();

        try {
            // Record aktualisieren
            $stmt = $pdo->prepare("
                UPDATE records
                SET name = ?, type = ?, content = ?, ttl = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $type, $content, $ttl, $recordId]);

            // Zonendatei testen
            $rebuild = rebuild_zone_and_flag_if_valid($zoneId);

            if ($rebuild['status'] === 'error') {
                $pdo->rollBack();
                $messages[] = new ServiceMessage('error', 'record.update.zonefile_invalid', [
                    'output' => $rebuild['output'],
                    'zoneId' => $zoneId,
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            if ($rebuild['status'] === 'warning') {
                $messages[] = new ServiceMessage('warning', 'record.update.zonefile_warning', [
                    'output' => $rebuild['output'],
                    'zoneId' => $zoneId,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $messages[] = new ServiceMessage('error', 'record.update.db_save_failed', [
                'type'     => $type,
                'name'     => $name,
                'zoneName' => $zoneName,
                'recordId' => $recordId,
                'detail'   => $e->getMessage(),
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        $messages[] = new ServiceMessage('success', 'record.update.success', [
            'type'     => $type,
            'name'     => $name,
            'zoneName' => $zoneName,
            'recordId' => $recordId,
        ]);

        return new ServiceResult(true, $messages, $zoneId);
    }

    /**
     * Legt einen neuen Record an und versucht optional einen automatischen PTR.
     *
     * Ablauf Forward-Record: Berechtigung -> Validierung -> Duplikat-Prüfung
     * -> Transaktion (INSERT, Rebuild, Commit).
     *
     * AutoPTR (nur A/AAAA und $autoPtr === true) läuft bewusst erst NACH dem
     * Commit des Forward-Records und in einer eigenen Verarbeitung: Scheitert
     * der PTR, bleibt der Forward-Record bestehen. Das Ergebnis ist dann
     * weiterhin ok=true; der PTR-Ausgang steht in den ServiceMessages
     * (record.create.ptr_*).
     *
     * @param Actor       $actor
     * @param int         $zoneId  Forward-Zone
     * @param RecordInput $input
     * @param bool        $autoPtr PTR automatisch anlegen (nur A/AAAA)
     *
     * @return ServiceResult
     */
    public function createRecord(Actor $actor, int $zoneId, RecordInput $input, bool $autoPtr = false): ServiceResult
    {
        $pdo = $this->pdo;
        $messages = [];

        $type    = $input->type;
        $name    = $input->name;
        $content = $input->content;
        $ttl     = $input->ttl;

        // Berechtigung prüfen
        if (!$this->zoneAccess->canWriteZone($actor, $zoneId)) {
            $messages[] = new ServiceMessage('audit', 'record.create.access_denied', [
                'userId' => $actor->userId,
                'zoneId' => $zoneId,
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Zonenname (für Meldungen ohne abschließenden Punkt)
        $zoneName = rtrim(getZoneName($pdo, $zoneId), '.');

        // Validierung
        $validatorType = $input->validationType ?? (($type === 'TXT' && $input->isDkim) ? 'DKIM' : $type);
        $errors = validateDnsRecord($validatorType, $name, $content, $ttl);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $messages[] = new ServiceMessage('error', 'record.create.validation_error', [
                    'errorCode' => $error,
                    'type'      => $type,
                    'name'      => $name,
                    'zoneName'  => $zoneName,
                ]);
            }

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Auf Duplikate prüfen
        if (isDuplicateRecord($pdo, $zoneId, $name, $type, $content)) {
            $messages[] = new ServiceMessage('error', 'record.create.duplicate', [
                'type'     => $type,
                'name'     => $name,
                'zoneName' => $zoneName,
                'zoneId'   => $zoneId,
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Transaktion starten
        $pdo->beginTransaction();

        try {
            // Record schreiben
            $stmt = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$zoneId, $name, $type, $content, $ttl]);

            // Zonendatei testen
            $rebuild = rebuild_zone_and_flag_if_valid($zoneId);

            if ($rebuild['status'] === 'error') {
                $pdo->rollBack();
                $messages[] = new ServiceMessage('error', 'record.create.zonefile_invalid', [
                    'output' => $rebuild['output'],
                    'zoneId' => $zoneId,
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            if ($rebuild['status'] === 'warning') {
                $messages[] = new ServiceMessage('warning', 'record.create.zonefile_warning', [
                    'output' => $rebuild['output'],
                    'zoneId' => $zoneId,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $messages[] = new ServiceMessage('error', 'record.create.db_save_failed', [
                'type'   => $type,
                'name'   => $name,
                'zoneId' => $zoneId,
                'detail' => $e->getMessage(),
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Forward-Record ist committed
        $messages[] = new ServiceMessage('success', 'record.create.success', [
            'type'     => $type,
            'name'     => $name,
            'zoneName' => $zoneName,
            'zoneId'   => $zoneId,
        ]);

        // Automatischen PTR erzeugen (optional, nach dem Commit; Fehler rollen den Forward-Record nicht zurück)
        if (($type === 'A' || $type === 'AAAA') && $autoPtr) {
            $this->createAutoPtr($actor, $zoneId, $zoneName, $type, $content, $name, $ttl, $messages);
        }

        return new ServiceResult(true, $messages, $zoneId);
    }

    /**
     * Versucht, für einen A- oder AAAA-Record automatisch einen PTR-Record anzulegen.
     *
     * Voraussetzungen: Reverse-Zone existiert, Actor darf in die Reverse-Zone
     * schreiben, kein PTR mit gleichem Namen vorhanden. Der Ausgang wird als
     * ServiceMessages an $messages angehängt (Erfolg: record.create.ptr_created).
     * Bewusst ohne eigene Transaktion (bestehendes Verhalten).
     *
     * @param ServiceMessage[] $messages
     */
    private function createAutoPtr(
        Actor $actor,
        int $zoneId,
        string $zoneName,
        string $type,
        string $ip,
        string $name,
        int $ttl,
        array &$messages
    ): void {
        $pdo = $this->pdo;

        // Hostname ermitteln
        $hostname = ($name === '@' || trim($name) === '')
            ? $zoneName . '.'
            : rtrim($name, '.') . '.' . $zoneName . '.';

        // Reverse-Zone und Name berechnen
        $ptr_zone = null;
        $ptr_name = null;

        if ($type === 'A' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $ptr_zone = "{$parts[2]}.{$parts[1]}.{$parts[0]}.in-addr.arpa";
                $ptr_name = $parts[3];
            }
        } elseif ($type === 'AAAA' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            $hex = unpack('H*', $bin)[1];
            $nibbles = str_split($hex);
            $reverse = array_reverse($nibbles);
            $ptr_zone = implode('.', array_slice($reverse, 1)) . '.ip6.arpa';
            $ptr_name = $reverse[0];
        }

        if (!$ptr_zone || !$ptr_name) {
            $messages[] = new ServiceMessage('error', 'record.create.ptr_invalid_ip', [
                'type'   => $type,
                'ip'     => $ip,
                'zoneId' => $zoneId,
                'name'   => $name,
            ]);
            return;
        }

        // Reverse-Zone muss existieren
        $stmt = $pdo->prepare("SELECT id FROM zones WHERE name = ? AND type = 'reverse'");
        $stmt->execute([$ptr_zone]);
        $ptr_zone_id = $stmt->fetchColumn();

        if (!$ptr_zone_id) {
            $messages[] = new ServiceMessage('error', 'record.create.ptr_zone_missing', [
                'ptrZone' => $ptr_zone,
                'ip'      => $ip,
            ]);
            return;
        }

        // Zonenberechtigung für die Reverse-Zone prüfen (zoneadmin benötigt user_zones-Eintrag)
        if (!$this->zoneAccess->canWriteZone($actor, (int)$ptr_zone_id)) {
            $messages[] = new ServiceMessage('audit', 'record.create.ptr_access_denied', [
                'userId'    => $actor->userId,
                'ptrZoneId' => (int)$ptr_zone_id,
                'ptrZone'   => $ptr_zone,
            ]);
            return;
        }

        // Prüfen auf Duplikat
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM records WHERE zone_id = ? AND name = ? AND type = 'PTR'");
        $stmt->execute([$ptr_zone_id, $ptr_name]);
        if ((int)$stmt->fetchColumn() > 0) {
            $messages[] = new ServiceMessage('error', 'record.create.ptr_duplicate', [
                'ptrZone' => $ptr_zone,
                'ptrName' => $ptr_name,
            ]);
            return;
        }

        // Einfügen
        try {
            $stmt = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (?, ?, 'PTR', ?, ?)");
            $stmt->execute([$ptr_zone_id, $ptr_name, $hostname, $ttl]);

            $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
            rebuild_zone_and_flag_if_valid((int)$ptr_zone_id);

            $messages[] = new ServiceMessage('success', 'record.create.ptr_created', [
                'ptrZone' => $ptr_zone,
                'ptrName' => $ptr_name,
            ]);
        } catch (PDOException $e) {
            $messages[] = new ServiceMessage('error', 'record.create.ptr_db_failed', [
                'ptrZone' => $ptr_zone,
                'ptrName' => $ptr_name,
                'detail'  => $e->getMessage(),
            ]);
        }
    }
}
