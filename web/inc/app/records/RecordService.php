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
 * Zonen-Rebuild/-Validierung wird als explizite, zwingende Dependency
 * (callable $zoneRebuilder) injiziert. Der Service kennt keine konkrete
 * Rebuild-Implementierung. Der Rebuilder erhält eine Zonen-ID und liefert
 * das bestehende Ergebnis-Array (u. a. 'status'). Er muss auf derselben
 * DB-Connection/Transaktion arbeiten wie das übergebene PDO-Objekt, damit noch
 * nicht committete Record-Änderungen sichtbar sind.
 *
 * Nebenläufigkeit (zone-first): Create, Update und Delete beginnen ihre Transaktion mit dem
 * Sperren der betroffenen Zonenzeile(n) (dyndnsLockZones, aufsteigende Zonen-ID, erste Abfrage
 * der Transaktion) - dieselbe Reihenfolge wie DynDNS-Verwaltung und DynDNS-API. Alle
 * zustandsabhängigen Lesezugriffe und Prüfungen erfolgen erst unter dieser Sperre. Die Garantie
 * gilt nur für Schreibpfade, die dieselbe Zonensperre verwenden. AutoPTR (createAutoPtr) nutzt
 * eine eigene, nachgelagerte Transaktion, die ausschließlich die Reverse-Zone sperrt.
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ZoneAccess.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/../../glue_check.php';
require_once __DIR__ . '/../../validators.php';
require_once __DIR__ . '/../../dyndns_guard.php';
require_once __DIR__ . '/RecordInput.php';
require_once __DIR__ . '/AutoPtr.php';

final class RecordService
{
    private PDO $pdo;
    private ZoneAccess $zoneAccess;
    private Closure $zoneRebuilder;

    public function __construct(
        PDO $pdo,
        ZoneAccess $zoneAccess,
        callable $zoneRebuilder
    ) {
        $this->pdo = $pdo;
        $this->zoneAccess = $zoneAccess;
        $this->zoneRebuilder = Closure::fromCallable($zoneRebuilder);
    }

    /**
     * Ruft ausschließlich den injizierten Zone-Rebuilder auf und gibt dessen
     * Ergebnis unverändert zurück.
     */
    private function rebuildZone(int $zoneId): array
    {
        return ($this->zoneRebuilder)($zoneId);
    }

    /**
     * Rollback nur, wenn noch eine Transaktion aktiv ist (z. B. nicht nach einem
     * von InnoDB bereits zurückgerollten Deadlock/Lock-Timeout).
     */
    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Löscht die angegebenen Records atomar in einer Transaktion.
     *
     * Ein Fehler bei einem Record (fehlender Record, fehlende Berechtigung,
     * Glue-/NS-Schutz, Rebuild-Fehler) führt zum vollständigen Rollback.
     *
     * Ablauf (zone-first): Die Zonen der Records werden zunächst ohne Sperre ermittelt.
     * Danach beginnt die Transaktion mit dem Sperren ALLER betroffenen Zonen, in denen der
     * Akteur schreiben darf, in aufsteigender ID-Reihenfolge (dyndnsLockZones, erste Abfrage
     * der Transaktion). Erst unter diesen
     * Sperren werden Records, Zonenname, Berechtigung und Schutzregeln geprüft (jeweils frisch
     * gelesen) und gelöscht. Die Verarbeitungsreihenfolge der IDs und alle Meldungen bleiben
     * wie bisher.
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

        try {
            // Zonen der Records ohne Sperre ermitteln (nur zur Bestimmung der Sperrmenge).
            // Fehlende Records werden erst in der Verarbeitungsreihenfolge unter der Sperre gemeldet.
            $expectedZone = [];
            $zoneIds = [];
            $stmt = $pdo->prepare("SELECT zone_id FROM records WHERE id = ?");
            foreach ($ids as $id) {
                $stmt->execute([$id]);
                $zid = $stmt->fetchColumn();
                $stmt->closeCursor();
                $expectedZone[$id] = ($zid === false) ? null : (int)$zid;
                if ($zid !== false) {
                    $zoneIds[] = (int)$zid;
                }
            }

            // Nur Zonen sperren, in denen der Akteur schreiben darf: Unberechtigte können keine
            // Zonensperren anfordern. Die Berechtigung wird unter der Sperre erneut geprüft.
            $lockIds = [];
            foreach (array_unique($zoneIds) as $zid) {
                if ($this->zoneAccess->canWriteZone($actor, $zid)) {
                    $lockIds[] = $zid;
                }
            }

            $pdo->beginTransaction();

            // Erste Abfrage der Transaktion: alle betroffenen Zonen aufsteigend sperren
            $zones = dyndnsLockZones($pdo, $lockIds);

            $affected_zones = [];

            foreach ($ids as $id) {
                $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ?");
                $stmt->execute([$id]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);

                // Record muss noch existieren und weiterhin in der erwarteten Zone liegen
                if (!$record || $expectedZone[$id] === null || (int)$record['zone_id'] !== $expectedZone[$id]) {
                    throw new RuntimeException("Record mit ID $id nicht gefunden.");
                }

                $zone_id = (int)$record['zone_id'];
                $lastZoneId = $zone_id;

                // Rechte prüfen (unter der Zonensperre bzw. ohne Sperre für nicht berechtigte Zonen)
                if (!$this->zoneAccess->canWriteZone($actor, $zone_id)) {
                    $messages[] = new ServiceMessage('audit', 'record.delete.access_denied', [
                        'userId'   => $actor->userId,
                        'recordId' => $id,
                        'zoneId'   => $zone_id,
                    ]);
                    throw new RuntimeException("Keine Berechtigung für Record-ID $id.");
                }

                // Nur gesperrte Zonen dürfen verändert werden (fail-closed, z. B. bei zwischenzeitlich
                // erteilter Berechtigung oder gelöschter Zone)
                if (!isset($zones[$zone_id])) {
                    throw new RuntimeException("Zone $zone_id zu Record-ID $id nicht gesperrt oder nicht gefunden.");
                }

                // Aktueller Zonenname unter der Sperre
                $zone_name = (string)$zones[$zone_id]['name'];

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

                // DynDNS-Schutz: Verwaltete oder nicht zuordenbare A/AAAA-Records an reservierten
                // Hostnamen dürfen nicht über den RecordService gelöscht werden
                $violation = dyndnsCheckRecordChange($pdo, $zone_id, $zone_name, $record, null);
                if ($violation !== null) {
                    $messages[] = new ServiceMessage('error', 'record.delete.dyndns_managed', [
                        'reason'     => $violation['code'],
                        'recordName' => $record['name'],
                        'recordType' => $record['type'],
                        'hostname'   => $violation['hostname'],
                        'zoneName'   => $zone_name,
                    ]);
                    throw new RuntimeException("Löschen eines von DynDNS verwalteten Records nicht erlaubt ({$violation['code']}).");
                }

                // Löschen bedingt auf den zuvor unter der Sperre gelesenen Zustand
                // (analog zum bedingten UPDATE in updateRecord(); NULL-sicher für ttl/server_id)
                $stmt = $pdo->prepare("
                    DELETE FROM records
                    WHERE id = ?
                      AND zone_id = ?
                      AND BINARY name = ?
                      AND type = ?
                      AND BINARY content = ?
                      AND ttl <=> ?
                      AND server_id <=> ?
                ");
                $stmt->execute([
                    $id,
                    $zone_id,
                    (string)$record['name'],
                    (string)$record['type'],
                    (string)$record['content'],
                    $record['ttl'],
                    $record['server_id'],
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException("Record mit ID $id wurde zwischenzeitlich geändert.");
                }

                // Merken, welche Zonen betroffen sind
                $affected_zones[$zone_id] = $zone_name;
            }

            // Zonendateien prüfen (alle Zonen sind bereits gesperrt; die Reihenfolge ist wie bisher)
            foreach ($affected_zones as $zone_id => $zone_name) {
                // Wie bisher: die Schleifenvariable bestimmt auch die letzte Zone
                $lastZoneId = $zone_id;

                $rebuild = $this->rebuildZone($zone_id);

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
            $this->rollbackIfActive();
            $messages[] = new ServiceMessage('error', 'record.delete.failed', [
                'detail' => $e->getMessage(),
            ]);

            return new ServiceResult(false, $messages, $lastZoneId);
        }
    }

    /**
     * Aktualisiert einen bestehenden Record.
     *
     * Ablauf (zone-first): Berechtigung -> Validierung (ohne Datenbankzustand) -> Transaktion.
     * Die erste Abfrage der Transaktion sperrt die Zone (dyndnsLockZones). Erst danach werden
     * alle zustandsabhängigen Informationen frisch gelesen und geprüft: Zonenname,
     * Schreibberechtigung, Record samt Zonenzuordnung, No-Change-Prüfung, Glue-Schutz,
     * NS-Schutz und DynDNS-Reservierungen. Das UPDATE ist bedingt auf den zuvor gelesenen
     * Zustand des Records.
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

        // Berechtigung prüfen (frühe Ablehnung; wird unter der Zonensperre erneut geprüft)
        if (!$this->zoneAccess->canWriteZone($actor, $zoneId)) {
            $messages[] = new ServiceMessage('audit', 'record.update.access_denied', [
                'userId' => $actor->userId,
                'zoneId' => $zoneId,
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Zonenname für Validierungsmeldungen (maßgeblich ist der Name unter der Zonensperre)
        $zoneNameRaw = getZoneName($pdo, $zoneId);
        $zoneName    = rtrim($zoneNameRaw, '.');

        // Validierung (unabhängig vom Datenbankzustand)
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

        try {
            $pdo->beginTransaction();

            // Erste Abfrage der Transaktion: Zone sperren
            $zones = dyndnsLockZones($pdo, [$zoneId]);

            if (!isset($zones[$zoneId])) {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('error', 'record.update.not_found', [
                    'recordId' => $recordId,
                    'zoneId'   => $zoneId,
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            // Aktueller Zonenname unter der Sperre (die Zone kann zwischenzeitlich umbenannt worden sein)
            $zoneNameRaw = (string)$zones[$zoneId]['name'];
            $zoneName    = rtrim($zoneNameRaw, '.');

            // Berechtigung unter der Sperre erneut prüfen (z. B. Entzug der Zonenzuweisung)
            if (!$this->zoneAccess->canWriteZone($actor, $zoneId)) {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('audit', 'record.update.access_denied', [
                    'userId' => $actor->userId,
                    'zoneId' => $zoneId,
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            // Record frisch laden; die Zonenzuordnung wird über zone_id mitgeprüft
            $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ? AND zone_id = ?");
            $stmt->execute([$recordId, $zoneId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $this->rollbackIfActive();
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
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('success', 'record.update.no_changes', [
                    'zoneId' => $zoneId,
                ]);

                return new ServiceResult(true, $messages, $zoneId);
            }

            // Glue-Check
            $allRecords = getAllZoneRecords($pdo, $zoneId);
            if (isGlueRecord($existing, $allRecords, $zoneNameRaw)) {
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
                    $this->rollbackIfActive();

                    return new ServiceResult(false, $messages, $zoneId);
                }
            }

            // Geschützte NS-Records dürfen nicht bearbeitet werden
            if (
                $existing['type'] === 'NS' &&
                isProtectedNsRecord($existing, $allRecords, $zoneNameRaw, $pdo, $zoneId)
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
                    $this->rollbackIfActive();

                    return new ServiceResult(false, $messages, $zoneId);
                }
            }

            // DynDNS-Reservierungen: bisherigen UND beabsichtigten Namen/Typ bewerten.
            // Gilt auch für Glue-/Systemrecords an reservierten Hostnamen (DynDNS hat Vorrang).
            $dyndnsViolation = dyndnsCheckRecordChange(
                $pdo,
                $zoneId,
                $zoneNameRaw,
                $existing,
                ['name' => $name, 'type' => $type, 'content' => $content, 'ttl' => $ttl]
            );
            if ($dyndnsViolation !== null) {
                $this->rollbackIfActive();
                $isReservedName = in_array($dyndnsViolation['code'], ['MANAGED_RECORD', 'AMBIGUOUS_RECORD'], true) === false;
                $messages[] = new ServiceMessage(
                    'error',
                    $isReservedName ? 'record.update.dyndns_reserved' : 'record.update.dyndns_managed',
                    [
                        'reason'       => $dyndnsViolation['code'],
                        'type'         => $type,
                        'name'         => $name,
                        'existingName' => $existing['name'],
                        'existingType' => $existing['type'],
                        'hostname'     => $dyndnsViolation['hostname'],
                        'zoneName'     => $zoneName,
                    ]
                );

                return new ServiceResult(false, $messages, $zoneId);
            }

            // Record bedingt auf den zuvor unter der Sperre gelesenen Zustand aktualisieren
            // (kein "server_id IS NULL": erlaubte Glue-/Systemrecord-Änderungen bleiben möglich)
            $stmt = $pdo->prepare("
                UPDATE records
                SET name = ?, type = ?, content = ?, ttl = ?
                WHERE id = ?
                  AND zone_id = ?
                  AND BINARY name = ?
                  AND type = ?
                  AND BINARY content = ?
                  AND ttl <=> ?
                  AND server_id <=> ?
            ");
            $stmt->execute([
                $name,
                $type,
                $content,
                $ttl,
                $recordId,
                $zoneId,
                (string)$existing['name'],
                (string)$existing['type'],
                (string)$existing['content'],
                $existing['ttl'],
                $existing['server_id'],
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Record wurde zwischenzeitlich geändert (rowCount=' . $stmt->rowCount() . ').');
            }

            // Zonendatei testen
            $rebuild = $this->rebuildZone($zoneId);

            if ($rebuild['status'] === 'error') {
                $this->rollbackIfActive();
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
            $this->rollbackIfActive();
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
     * Ablauf Forward-Record (zone-first): Berechtigung -> Validierung (ohne Datenbankzustand)
     * -> Transaktion. Die erste Abfrage der Transaktion sperrt die Zone (dyndnsLockZones).
     * Unter der Sperre werden Zonenname, Schreibberechtigung, Duplikat und DynDNS-Reservierungen
     * frisch geprüft; danach INSERT, Rebuild und Commit.
     *
     * AutoPTR (nur A/AAAA und $autoPtr === true) läuft bewusst erst NACH dem
     * Commit des Forward-Records in einer eigenen Transaktion (siehe createAutoPtr):
     * Scheitert der PTR, bleibt der Forward-Record bestehen.
     *
     * Ergebnisvertrag: ok bezeichnet ausschließlich den Erfolg der Forward-Anlage. Bei
     * angefordertem AutoPTR enthält das Ergebnis zusätzlich genau eine Meldung mit
     * context['ptrOutcome'] ('created' | 'exists' | 'skipped' | 'failed'); ein fehlgeschlagener
     * oder übersprungener PTR ändert ok nicht.
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

        // Berechtigung prüfen (frühe Ablehnung; wird unter der Zonensperre erneut geprüft)
        if (!$this->zoneAccess->canWriteZone($actor, $zoneId)) {
            $messages[] = new ServiceMessage('audit', 'record.create.access_denied', [
                'userId' => $actor->userId,
                'zoneId' => $zoneId,
            ]);

            return new ServiceResult(false, $messages, $zoneId);
        }

        // Zonenname für Validierungsmeldungen (maßgeblich ist der Name unter der Zonensperre)
        $zoneName = rtrim(getZoneName($pdo, $zoneId), '.');

        // Validierung (unabhängig vom Datenbankzustand)
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

        try {
            $pdo->beginTransaction();

            // Erste Abfrage der Transaktion: Zone sperren
            $zones = dyndnsLockZones($pdo, [$zoneId]);

            if (!isset($zones[$zoneId])) {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('error', 'record.create.db_save_failed', [
                    'type'   => $type,
                    'name'   => $name,
                    'zoneId' => $zoneId,
                    'detail' => "Zone $zoneId nicht gefunden.",
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            // Aktueller Zonenname unter der Sperre (die Zone kann zwischenzeitlich umbenannt worden sein)
            $zoneNameRaw = (string)$zones[$zoneId]['name'];
            $zoneName    = rtrim($zoneNameRaw, '.');

            // Berechtigung unter der Sperre erneut prüfen (z. B. Entzug der Zonenzuweisung)
            if (!$this->zoneAccess->canWriteZone($actor, $zoneId)) {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('audit', 'record.create.access_denied', [
                    'userId' => $actor->userId,
                    'zoneId' => $zoneId,
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            // Auf Duplikate prüfen (unter der Sperre)
            if (isDuplicateRecord($pdo, $zoneId, $name, $type, $content)) {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('error', 'record.create.duplicate', [
                    'type'     => $type,
                    'name'     => $name,
                    'zoneName' => $zoneName,
                    'zoneId'   => $zoneId,
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            // DynDNS-Reservierungen prüfen (beabsichtigter Name/Typ)
            $dyndnsViolation = dyndnsCheckRecordChange(
                $pdo,
                $zoneId,
                $zoneNameRaw,
                null,
                ['name' => $name, 'type' => $type, 'content' => $content, 'ttl' => $ttl]
            );
            if ($dyndnsViolation !== null) {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('error', 'record.create.dyndns_reserved', [
                    'reason'   => $dyndnsViolation['code'],
                    'type'     => $type,
                    'name'     => $name,
                    'hostname' => $dyndnsViolation['hostname'],
                    'zoneName' => $zoneName,
                    'zoneId'   => $zoneId,
                ]);

                return new ServiceResult(false, $messages, $zoneId);
            }

            // Record schreiben
            $stmt = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$zoneId, $name, $type, $content, $ttl]);

            // Zonendatei testen
            $rebuild = $this->rebuildZone($zoneId);

            if ($rebuild['status'] === 'error') {
                $this->rollbackIfActive();
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
            $this->rollbackIfActive();
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
     * Versucht, für einen A- oder AAAA-Record automatisch einen PTR-Record anzulegen (AutoPTR).
     *
     * Läuft erst nach dem Commit des Forward-Records (T1) in einer eigenen Transaktion T2.
     * Ein Fehlschlag rollt ausschließlich T2 zurück; der Forward-Record bleibt bestehen.
     *
     * Ablauf T2 (zone-first): Ziel-FQDN und Reverse-Position berechnen (ohne Datenbank) ->
     * Reverse-Zone ohne Sperre ermitteln und Berechtigung früh prüfen -> Transaktion, erste
     * Abfrage sperrt die Reverse-Zone (dyndnsLockZones) -> unter der Sperre Zonenidentität,
     * Zonentyp, Berechtigung und vorhandene PTR-Records frisch prüfen -> INSERT, Rebuild
     * (setzt changed=1 der Reverse-Zone in derselben Transaktion) und Commit. Rebuild-Fehler und
     * alle Exceptions (inkl. Deadlock/Lock-Timeout) führen zum Rollback von T2.
     *
     * Ergebnis: Es wird genau eine Ergebnismeldung mit context['ptrOutcome'] angehängt:
     * - 'created': record.create.ptr_created
     * - 'exists':  record.create.ptr_exists (genau ein identischer PTR vorhanden, No-op)
     * - 'skipped': record.create.ptr_skipped_wildcard, record.create.ptr_skipped_out_of_zone
     * - 'failed':  record.create.ptr_invalid_ip, ptr_zone_missing, ptr_access_denied (audit),
     *              ptr_duplicate (genau ein abweichender PTR vorhanden), ptr_ambiguous (mehrere
     *              PTR-Datensätze für denselben Owner, auch bei identischen Zielen),
     *              ptr_zonefile_invalid, ptr_db_failed
     * Zusätzlich kann record.create.ptr_zonefile_warning (ohne ptrOutcome) auftreten.
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

        // Ziel-FQDN berechnen (Wildcards und Namen außerhalb der Zone werden übersprungen)
        $target = AutoPtr::targetFqdn($name, $zoneName);

        if ($target['status'] === 'wildcard') {
            $messages[] = new ServiceMessage('warning', 'record.create.ptr_skipped_wildcard', [
                'ptrOutcome' => 'skipped',
                'name'       => $name,
                'zoneName'   => $zoneName,
                'ip'         => $ip,
            ]);
            return;
        }

        if ($target['status'] === 'out_of_zone') {
            $messages[] = new ServiceMessage('warning', 'record.create.ptr_skipped_out_of_zone', [
                'ptrOutcome' => 'skipped',
                'name'       => $name,
                'zoneName'   => $zoneName,
                'ip'         => $ip,
            ]);
            return;
        }

        $hostname = (string)$target['fqdn'];

        // Reverse-Zone und PTR-Owner berechnen (bisherige Zonenauswahl: IPv4 /24, IPv6 /124)
        $location = AutoPtr::reverseLocation($type, $ip);

        if ($location === null) {
            $messages[] = new ServiceMessage('warning', 'record.create.ptr_invalid_ip', [
                'ptrOutcome' => 'failed',
                'type'       => $type,
                'ip'         => $ip,
                'zoneId'     => $zoneId,
                'name'       => $name,
            ]);
            return;
        }

        $ptr_zone = $location['zone'];
        $ptr_name = $location['name'];

        $zoneMissing = function () use (&$messages, $ptr_zone, $ip): void {
            $messages[] = new ServiceMessage('warning', 'record.create.ptr_zone_missing', [
                'ptrOutcome' => 'failed',
                'ptrZone'    => $ptr_zone,
                'ip'         => $ip,
            ]);
        };

        $accessDenied = function (int $ptrZoneId) use (&$messages, $actor, $ptr_zone): void {
            $messages[] = new ServiceMessage('audit', 'record.create.ptr_access_denied', [
                'ptrOutcome' => 'failed',
                'userId'     => $actor->userId,
                'ptrZoneId'  => $ptrZoneId,
                'ptrZone'    => $ptr_zone,
            ]);
        };

        try {
            // Reverse-Zone ohne Sperre ermitteln (nur zur Bestimmung der Sperre; Prüfung erneut unter der Sperre)
            $stmt = $pdo->prepare("SELECT id FROM zones WHERE name = ? AND type = 'reverse'");
            $stmt->execute([$ptr_zone]);
            $ptr_zone_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($ptr_zone_id === false) {
                $zoneMissing();
                return;
            }
            $ptr_zone_id = (int)$ptr_zone_id;

            // Frühe Berechtigungsprüfung: ohne Schreibrecht wird keine Zonensperre angefordert
            if (!$this->zoneAccess->canWriteZone($actor, $ptr_zone_id)) {
                $accessDenied($ptr_zone_id);
                return;
            }

            $pdo->beginTransaction();

            // Erste Abfrage der Transaktion: Reverse-Zone sperren
            $zones = dyndnsLockZones($pdo, [$ptr_zone_id]);

            // Zonenidentität unter der Sperre: Zone existiert, ist Reverse-Zone und trägt weiterhin den berechneten Namen
            $locked = $zones[$ptr_zone_id] ?? null;
            if ($locked === null
                || (string)$locked['type'] !== 'reverse'
                || strtolower(rtrim((string)$locked['name'], '.')) !== strtolower($ptr_zone)
            ) {
                $this->rollbackIfActive();
                $zoneMissing();
                return;
            }

            // Berechtigung unter der Sperre erneut prüfen (z. B. zwischenzeitlich entzogene Zonenzuweisung)
            if (!$this->zoneAccess->canWriteZone($actor, $ptr_zone_id)) {
                $this->rollbackIfActive();
                $accessDenied($ptr_zone_id);
                return;
            }

            // Vorhandene PTR-Records unter der Sperre prüfen
            $stmt = $pdo->prepare("SELECT content FROM records WHERE zone_id = ? AND name = ? AND type = 'PTR'");
            $stmt->execute([$ptr_zone_id, $ptr_name]);
            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($existing) > 1) {
                // Mehrere PTR-Datensätze für denselben Owner (Altbestand): mehrdeutig, nichts ändern,
                // auch wenn alle Ziele identisch sind (keine automatische Bereinigung)
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('warning', 'record.create.ptr_ambiguous', [
                    'ptrOutcome'      => 'failed',
                    'ptrZone'         => $ptr_zone,
                    'ptrName'         => $ptr_name,
                    'target'          => $hostname,
                    'existingTargets' => array_values(array_map('strval', $existing)),
                ]);
                return;
            }

            if (count($existing) === 1) {
                $this->rollbackIfActive();

                if (strtolower((string)$existing[0]) === strtolower($hostname)) {
                    // Genau ein identischer PTR vorhanden: idempotenter No-op
                    $messages[] = new ServiceMessage('success', 'record.create.ptr_exists', [
                        'ptrOutcome' => 'exists',
                        'ptrZone'    => $ptr_zone,
                        'ptrName'    => $ptr_name,
                        'target'     => $hostname,
                    ]);
                } else {
                    // Genau ein abweichender PTR vorhanden: Konflikt, nichts geändert
                    $messages[] = new ServiceMessage('warning', 'record.create.ptr_duplicate', [
                        'ptrOutcome'      => 'failed',
                        'ptrZone'         => $ptr_zone,
                        'ptrName'         => $ptr_name,
                        'target'          => $hostname,
                        'existingTargets' => [(string)$existing[0]],
                    ]);
                }
                return;
            }

            // PTR schreiben
            $stmt = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (?, ?, 'PTR', ?, ?)");
            $stmt->execute([$ptr_zone_id, $ptr_name, $hostname, $ttl]);

            // Reverse-Zone in derselben Transaktion validieren und als geändert markieren
            $rebuild = $this->rebuildZone($ptr_zone_id);

            if ($rebuild['status'] === 'error') {
                $this->rollbackIfActive();
                $messages[] = new ServiceMessage('warning', 'record.create.ptr_zonefile_invalid', [
                    'ptrOutcome' => 'failed',
                    'ptrZone'    => $ptr_zone,
                    'ptrName'    => $ptr_name,
                    'output'     => $rebuild['output'] ?? '',
                ]);
                return;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfActive();
            $messages[] = new ServiceMessage('warning', 'record.create.ptr_db_failed', [
                'ptrOutcome' => 'failed',
                'ptrZone'    => $ptr_zone,
                'ptrName'    => $ptr_name,
                'detail'     => $e->getMessage(),
            ]);
            return;
        }

        if ($rebuild['status'] === 'warning') {
            $messages[] = new ServiceMessage('warning', 'record.create.ptr_zonefile_warning', [
                'ptrZone' => $ptr_zone,
                'output'  => $rebuild['output'] ?? '',
            ]);
        }

        $messages[] = new ServiceMessage('success', 'record.create.ptr_created', [
            'ptrOutcome' => 'created',
            'ptrZone'    => $ptr_zone,
            'ptrName'    => $ptr_name,
            'target'     => $hostname,
        ]);
    }
}
