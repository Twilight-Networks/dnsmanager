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
}
