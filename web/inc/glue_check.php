<?php
/**
 * Datei: inc/glue_check.php
 * Zweck: Enthält Hilfsfunktionen zur Erkennung von Glue-Records.
 * Kontext: Wird von records.php verwendet, um A/AAAA-Einträge zu identifizieren,
 *          die als Glue-Records zu autoritativen NS-Records fungieren.
 *
 * Funktionsweise: Wenn ein A- oder AAAA-Record denselben Namen wie ein NS-Record (FQDN) trägt,
 *                 handelt es sich um einen Glue-Record. Solche Records dürfen nicht gelöscht
 *                 oder in ihrem Namen geändert werden, da sie zwingend für DNS-Auflösung notwendig sind.
 */

/**
 * Prüft, ob ein DNS-Record ein Glue-Record ist.
 *
 * @param array $record Der zu prüfende Record (z. B. ['type' => 'A', 'name' => 'ns1', ...])
 * @param array $all_records Alle Records der aktuellen Zone (SELECT * FROM records ...)
 * @param string $zone_name Der Name der Zone (z. B. 'example.com.')
 * @return bool true, wenn der Record ein Glue-Record ist, sonst false
 */
function isGlueRecord(array $record, array $all_records, string $zone_name): bool
{
    // Nur A- oder AAAA-Records können Glue-Records sein
    if (!in_array($record['type'], ['A', 'AAAA'], true)) {
        return false;
    }

    // Erwarteter FQDN des Glue-Records (z. B. ns1.example.com.)
    $fqdn = rtrim($record['name'], '.') . '.' . rtrim($zone_name, '.') . '.';

    // Prüfe, ob ein NS-Record mit exakt diesem FQDN existiert
    foreach ($all_records as $r) {
        if ($r['type'] === 'NS' && rtrim($r['content'], '.') === rtrim($fqdn, '.')) {
            return true;
        }
    }

    return false;
}

/**
 * Prüft, ob ein NS-Record durch einen Glue-Record referenziert wird.
 *
 * Ein NS-Record gilt dann als geschützt, wenn ein A- oder AAAA-Record existiert,
 * der denselben FQDN hat wie der NS-Eintrag (→ Glue-Record vorhanden).
 *
 * @param array $ns_record Der NS-Record, z. B. ['type' => 'NS', 'content' => 'ns1.example.com.']
 * @param array $all_records Alle Records der Zone
 * @return bool true, wenn ein Glue-Record für diesen NS-Eintrag existiert
 */
function isNsRecordReferencedByGlue(array $ns_record, array $all_records, string $zone_name): bool
{
    $ns_fqdn = rtrim($ns_record['content'], '.');

    foreach ($all_records as $r) {
        if (in_array($r['type'], ['A', 'AAAA'], true)) {
            $candidate_fqdn = rtrim($r['name'], '.') . '.' . rtrim($zone_name, '.');
            if ($ns_fqdn === $candidate_fqdn) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Prüft, ob ein NS-Record geschützt ist.
 *
 * Geschützt sind:
 * - NS-Records mit zugehörigem Glue-Record (A/AAAA)
 * - NS-Records, die auf einen Server aus zone_servers zeigen (auch wenn kein Glue-Record existiert)
 *
 * @param array $ns_record Der NS-Record
 * @param array $all_records Alle Records dieser Zone
 * @param string $zone_name Der Name der Zone
 * @param PDO $pdo Datenbankverbindung
 * @param int $zone_id ID der Zone
 * @return bool true, wenn der NS-Record geschützt ist
 */
function isProtectedNsRecord(array $ns_record, array $all_records, string $zone_name, PDO $pdo, int $zone_id): bool
{
    // Ist per Glue referenziert?
    if (isNsRecordReferencedByGlue($ns_record, $all_records, $zone_name)) {
        return true;
    }

    // Hostname extrahieren (ohne Punkt)
    $target = rtrim($ns_record['content'], '.');

    // Alle autoritativen Servernamen laden
    $stmt = $pdo->prepare("SELECT name FROM servers s JOIN zone_servers zs ON zs.server_id = s.id WHERE zs.zone_id = ?");
    $stmt->execute([$zone_id]);
    $server_names = array_map(fn($s) => rtrim($s['name'], '.'), $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Gehört der NS-Name zu einem autoritativen Server?
    return in_array($target, $server_names, true);
}

/**
 * Gibt alle DNS-Records einer bestimmten Zone zurück.
 *
 * Zweck: Diese Funktion wird verwendet, um sämtliche Records einer Zone zu laden,
 *        z. B. zur Prüfung von Glue-Records oder für Validierungen.
 *
 * @param PDO $pdo Datenbankverbindung
 * @param int $zone_id ID der Zone, deren Records geladen werden sollen
 * @return array Array mit assoziativen Arrays der Records (Felder: id, name, type, content, ...)
 */
function getAllZoneRecords(PDO $pdo, int $zone_id): array {
    $stmt = $pdo->prepare("SELECT * FROM records WHERE zone_id = ?");
    $stmt->execute([$zone_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ermittelt den Zonennamen zu einer gegebenen Zonen-ID.
 *
 * Zweck: Diese Funktion wird benötigt, um z. B. bei der Prüfung von Glue-Records
 *        den vollständigen FQDN zusammensetzen zu können.
 *
 * @param PDO $pdo Datenbankverbindung
 * @param int $zone_id ID der Zone, deren Name abgerufen werden soll
 * @return string Der Name der Zone (z. B. "example.com.") oder ein leerer String bei Fehler
 */
function getZoneName(PDO $pdo, int $zone_id): string {
    $stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    $zone = $stmt->fetch(PDO::FETCH_ASSOC);
    return $zone['name'] ?? '';
}

