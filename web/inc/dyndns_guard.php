<?php
/**
 * Datei: inc/dyndns_guard.php
 * Zweck: Gemeinsame Schutzfunktionen für die DynDNS-Accountverwaltung
 *
 * Enthalten:
 * - DynDNS-spezifische Ausnahme für verständliche Abbrüche (DyndnsActionError)
 * - Kanonisierung gespeicherter DNS-Namen (Groß-/Kleinschreibung, absolute Namen)
 * - Deterministische Zonen-Zeilensperren (dyndnsLockZones)
 * - Kollisionsprüfung für neue/geänderte DynDNS-Hostnamen (dyndnsFindHostnameConflicts)
 * - Erkennung bestehender Doppelbelegungen (dyndnsFindAccountsSharingHostname)
 * - Konservative Auswahl löschbarer A/AAAA-Records eines Accounts (dyndnsClassifyAccountRecords)
 * - Erkennung von Lock-Timeouts/Deadlocks und Duplicate-Key-Fehlern
 *
 * Die Funktionen kennen keine Web-Schicht (keine Session, keine Toasts, kein HTML).
 *
 * Synchronisierung:
 * Alle Prüfungen sind nur dann autoritativ, wenn sie innerhalb einer Transaktion NACH
 * dyndnsLockZones() ausgeführt werden. dyndnsLockZones() muss die erste Abfrage der
 * Transaktion sein: Die Sperre ist ein Locking Read; die nachfolgenden einfachen SELECTs
 * erzeugen ihren Read-Snapshot erst nach dem Warten auf die Sperre und sehen damit alle
 * zuvor committeten Änderungen anderer Sperrinhaber (DynDNS-Accountverwaltung, DynDNS-API,
 * Publish). Schreibpfade, die diese Zonensperre NICHT verwenden (derzeit RecordService),
 * werden dadurch nicht serialisiert. Ohne UNIQUE-Constraint auf (zone_id, hostname) ist die
 * Zonensperre der einzige Schutz gegen parallele Doppelbelegungen.
 */

declare(strict_types=1);

require_once __DIR__ . '/validators.php';

/**
 * Fachlicher Abbruch einer DynDNS-Aktion mit einer für Administratoren verständlichen
 * Meldung (Toast, bereits HTML-sicher) und einer optionalen Detailmeldung fürs Log.
 */
final class DyndnsActionError extends RuntimeException
{
    public string $toastMessage;
    public ?string $logMessage;

    public function __construct(string $toastMessage, ?string $logMessage = null)
    {
        parent::__construct($logMessage ?? strip_tags($toastMessage));
        $this->toastMessage = $toastMessage;
        $this->logMessage = $logMessage;
    }
}

/**
 * Bringt einen gespeicherten DNS-Namen in eine kanonische, relative Form (Kleinbuchstaben).
 *
 * - "@" bzw. leerer Name             → "@"
 * - relativer Name ("Home")          → "home"
 * - absoluter Name in der Zone       → relativer Name ("home.example.com." → "home")
 * - absoluter Zonenname              → "@"
 * - absoluter Name außerhalb der Zone → null (wird von BIND ignoriert)
 *
 * Führende/abschließende Leerzeichen werden entfernt, damit auch Altbestände erkannt werden,
 * die die Datenbank-Collation (PAD SPACE) bisher als gleich behandelt hat.
 *
 * @param string $name     Gespeicherter Name (records.name oder dyndns_accounts.hostname)
 * @param string $zoneName Zonenname (mit oder ohne abschließenden Punkt)
 * @return string|null     Kanonischer relativer Name oder null (außerhalb der Zone)
 */
function dyndnsCanonicalName(string $name, string $zoneName): ?string
{
    $n    = strtolower(trim($name));
    $zone = strtolower(rtrim(trim($zoneName), '.'));

    if ($n === '' || $n === '@') {
        return '@';
    }

    if (str_ends_with($n, '.')) {
        $abs = rtrim($n, '.');

        if ($abs === $zone) {
            return '@';
        }

        if ($zone !== '' && str_ends_with($abs, '.' . $zone)) {
            return substr($abs, 0, -(strlen($zone) + 1));
        }

        return null;
    }

    return $n;
}

/**
 * Sperrt die angegebenen Zonen-Zeilen (SELECT … FOR UPDATE) in aufsteigender ID-Reihenfolge.
 *
 * Die feste Reihenfolge verhindert Deadlocks zwischen DynDNS-Aktionen, die zwei Zonen
 * betreffen (Zonenwechsel). Es wird dieselbe Zeilensperre verwendet wie in der DynDNS-API
 * und beim Publish. Muss die erste Abfrage der Transaktion sein (siehe Dateikopf).
 *
 * @param PDO   $pdo     Verbindung mit aktiver Transaktion
 * @param int[] $zoneIds Zu sperrende Zonen-IDs (Duplikate werden entfernt)
 * @return array<int, array{id:int|string, name:string, type:string, allow_dyndns:int|string}>
 *               Gesperrte Zonen, indiziert nach ID; nicht existierende Zonen fehlen
 */
function dyndnsLockZones(PDO $pdo, array $zoneIds): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('dyndnsLockZones() erfordert eine aktive Transaktion.');
    }

    $ids = array_values(array_unique(array_map('intval', $zoneIds)));
    sort($ids, SORT_NUMERIC);

    $stmt = $pdo->prepare("SELECT id, name, type, allow_dyndns FROM zones WHERE id = ? FOR UPDATE");
    $zones = [];

    foreach ($ids as $zid) {
        $stmt->execute([$zid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row !== false) {
            $zones[$zid] = $row;
        }
    }

    return $zones;
}

/**
 * Prüft, ob einer Zone neue DynDNS-Zuordnungen zugewiesen werden dürfen.
 *
 * @param array $zone Zonen-Zeile (mind. type, allow_dyndns)
 * @return string|null Fehlercode oder null, wenn zulässig
 */
function dyndnsZoneAssignmentError(array $zone): ?string
{
    if ((int)($zone['allow_dyndns'] ?? 0) !== 1) {
        return 'DYNDNS_ZONE_NOT_ALLOWED';
    }

    if (($zone['type'] ?? '') !== 'forward') {
        return 'DYNDNS_ZONE_NOT_FORWARD';
    }

    return null;
}

/**
 * Ermittelt Kollisionen für einen neuen bzw. geänderten DynDNS-Hostnamen in einer Zone.
 *
 * Konflikte (Codes):
 * - DYNDNS_CONFLICT_ACCOUNT        anderer DynDNS-Account mit demselben DNS-Namen
 * - DYNDNS_CONFLICT_ADDRESS_RECORD vorhandene manuelle A/AAAA-Records (server_id IS NULL)
 * - DYNDNS_CONFLICT_SYSTEM_RECORD  systemgenerierte A/AAAA-Records (server_id IS NOT NULL)
 * - DYNDNS_CONFLICT_SERVER_NAME    Hostname eines der Zone zugewiesenen DNS-Servers
 * - DYNDNS_CONFLICT_CNAME          CNAME am Namen
 * - DYNDNS_CONFLICT_DELEGATION     NS-Record am Namen oder an einem übergeordneten Namen (außer Apex)
 * - DYNDNS_CONFLICT_DNAME          DNAME an einem übergeordneten Namen (inkl. Apex)
 *
 * Andere Record-Typen (z. B. TXT, MX, CAA) am selben Namen sind kein Konflikt.
 * Vergleiche erfolgen kanonisch in PHP (Kleinbuchstaben, absolute Namen der Zone werden
 * relativ betrachtet) und damit unabhängig von der Datenbank-Collation.
 *
 * Autoritativ nur innerhalb einer Transaktion nach dyndnsLockZones() für $zoneId.
 *
 * @param PDO      $pdo
 * @param int      $zoneId
 * @param string   $zoneName
 * @param string   $hostname          Validierter, normalisierter relativer Hostname
 * @param int|null $excludeAccountId  Eigener Account (beim Bearbeiten) oder null
 * @return array<int, array{code:string, detail:string}>
 */
function dyndnsFindHostnameConflicts(
    PDO $pdo,
    int $zoneId,
    string $zoneName,
    string $hostname,
    ?int $excludeAccountId = null
): array {
    $host = dyndnsCanonicalName($hostname, $zoneName);
    $conflicts = [];

    if ($host === null || $host === '@') {
        return [['code' => 'DYNDNS_HOST_APEX', 'detail' => $hostname]];
    }

    // Übergeordnete Namen innerhalb der Zone (ohne den Namen selbst), inkl. Apex
    $labels = explode('.', $host);
    $ancestors = ['@'];
    for ($i = 1; $i < count($labels); $i++) {
        $ancestors[] = implode('.', array_slice($labels, $i));
    }

    // 1. Andere DynDNS-Accounts
    $stmt = $pdo->prepare("SELECT id, username, hostname FROM dyndns_accounts WHERE zone_id = ?");
    $stmt->execute([$zoneId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $acc) {
        if ($excludeAccountId !== null && (int)$acc['id'] === $excludeAccountId) {
            continue;
        }
        if (dyndnsCanonicalName((string)$acc['hostname'], $zoneName) === $host) {
            $conflicts[] = ['code' => 'DYNDNS_CONFLICT_ACCOUNT', 'detail' => (string)$acc['username']];
        }
    }

    // 2. Vorhandene Records der Zone
    $stmt = $pdo->prepare("SELECT id, name, type, server_id FROM records WHERE zone_id = ?");
    $stmt->execute([$zoneId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rec) {
        $name = dyndnsCanonicalName((string)$rec['name'], $zoneName);
        if ($name === null) {
            continue; // außerhalb der Zone, von BIND ignoriert
        }

        $type = strtoupper((string)$rec['type']);
        $detail = 'record_id=' . $rec['id'] . ' ' . $type . ' ' . $rec['name'];

        if ($name === $host) {
            if ($type === 'A' || $type === 'AAAA') {
                $code = $rec['server_id'] !== null ? 'DYNDNS_CONFLICT_SYSTEM_RECORD' : 'DYNDNS_CONFLICT_ADDRESS_RECORD';
                $conflicts[] = ['code' => $code, 'detail' => $detail];
            } elseif ($type === 'CNAME') {
                $conflicts[] = ['code' => 'DYNDNS_CONFLICT_CNAME', 'detail' => $detail];
            } elseif ($type === 'NS') {
                $conflicts[] = ['code' => 'DYNDNS_CONFLICT_DELEGATION', 'detail' => $detail];
            }
        } elseif (in_array($name, $ancestors, true)) {
            if ($type === 'NS' && $name !== '@') {
                $conflicts[] = ['code' => 'DYNDNS_CONFLICT_DELEGATION', 'detail' => $detail];
            } elseif ($type === 'DNAME') {
                $conflicts[] = ['code' => 'DYNDNS_CONFLICT_DNAME', 'detail' => $detail];
            }
        }
    }

    // 3. Reservierte Hostnamen der zugewiesenen DNS-Server (Glue, auch wenn noch nicht erzeugt)
    $stmt = $pdo->prepare("
        SELECT s.name
        FROM servers s
        JOIN zone_servers zs ON zs.server_id = s.id
        WHERE zs.zone_id = ?
    ");
    $stmt->execute([$zoneId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $serverName) {
        $serverName = trim((string)$serverName);
        if ($serverName === '') {
            continue;
        }
        if (dyndnsCanonicalName(rtrim($serverName, '.') . '.', $zoneName) === $host) {
            $conflicts[] = ['code' => 'DYNDNS_CONFLICT_SERVER_NAME', 'detail' => $serverName];
        }
    }

    return $conflicts;
}

/**
 * Liefert die Benutzernamen anderer DynDNS-Accounts derselben Zone, die denselben
 * kanonischen DNS-Namen verwenden (bestehende Doppelbelegung / Altbestand).
 *
 * Autoritativ nur innerhalb einer Transaktion nach dyndnsLockZones() für $zoneId.
 *
 * @param PDO    $pdo
 * @param int    $zoneId
 * @param string $zoneName
 * @param string $hostname          Gespeicherter Hostname des eigenen Accounts (ggf. Altbestand)
 * @param int    $excludeAccountId  Eigener Account
 * @return string[] Benutzernamen der anderen Accounts
 */
function dyndnsFindAccountsSharingHostname(
    PDO $pdo,
    int $zoneId,
    string $zoneName,
    string $hostname,
    int $excludeAccountId
): array {
    $host = dyndnsCanonicalName($hostname, $zoneName);
    if ($host === null) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT id, username, hostname FROM dyndns_accounts WHERE zone_id = ? AND id <> ?");
    $stmt->execute([$zoneId, $excludeAccountId]);

    $users = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $acc) {
        if (dyndnsCanonicalName((string)$acc['hostname'], $zoneName) === $host) {
            $users[] = (string)$acc['username'];
        }
    }

    return $users;
}

/**
 * Ermittelt konservativ, welche A/AAAA-Records unter dem Hostnamen eines Accounts gelöscht
 * werden dürfen.
 *
 * Ohne Record-Ownership ist eine sichere Zuordnung nicht möglich. Gelöscht wird pro Typ nur,
 * wenn genau EIN nicht systemgenerierter Record (server_id IS NULL) existiert und sein Inhalt
 * exakt der zuletzt vom Account gemeldeten IP (current_ipv4 / current_ipv6) entspricht.
 * Alle anderen Records (mehrere Records, abweichender Inhalt, keine gemeldete IP) bleiben
 * erhalten und werden als "kept" gezählt. Systemrecords werden nie berücksichtigt.
 *
 * Die SQL-Abfrage (Spalten-Collation) dient nur der Vorauswahl. Maßgeblich ist ein strenger
 * Namensvergleich in PHP (nur ASCII-Groß-/Kleinschreibung wird ignoriert): Records, deren
 * tatsächlicher Name davon abweicht (z. B. Akzente, Leerzeichen), sind nie Löschkandidaten
 * und werden als "kept" gezählt. Absolut gespeicherte Namen werden wie bisher nicht erfasst.
 *
 * @param PDO         $pdo
 * @param int         $zoneId
 * @param string      $hostname     Gespeicherter Hostname des Accounts
 * @param string|null $currentIpv4
 * @param string|null $currentIpv6
 * @return array{delete:int[], kept:int}
 */
function dyndnsClassifyAccountRecords(
    PDO $pdo,
    int $zoneId,
    string $hostname,
    ?string $currentIpv4,
    ?string $currentIpv6
): array {
    $stmt = $pdo->prepare("
        SELECT id, name, type, content
        FROM records
        WHERE zone_id = ?
          AND name = ?
          AND type IN ('A', 'AAAA')
          AND server_id IS NULL
        ORDER BY id
    ");
    $stmt->execute([$zoneId, $hostname]);

    $wantedName = dyndnsAsciiLower($hostname);
    $byType = ['A' => [], 'AAAA' => []];
    $kept = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rec) {
        // Strenger Vergleich unabhängig von der Collation (z. B. "hóme" ≠ "home", "home " ≠ "home")
        if (dyndnsAsciiLower((string)$rec['name']) !== $wantedName) {
            $kept++;
            continue;
        }
        $byType[strtoupper((string)$rec['type'])][] = $rec;
    }

    $expected = ['A' => $currentIpv4, 'AAAA' => $currentIpv6];
    $delete = [];

    foreach ($byType as $type => $records) {
        $ip = $expected[$type];

        if (count($records) === 1 && $ip !== null && $ip !== '' && (string)$records[0]['content'] === $ip) {
            $delete[] = (int)$records[0]['id'];
        } else {
            $kept += count($records);
        }
    }

    return ['delete' => $delete, 'kept' => $kept];
}

/**
 * Wandelt ausschließlich ASCII-Großbuchstaben A–Z in Kleinbuchstaben um (locale-unabhängig).
 * Alle anderen Bytes bleiben unverändert, sodass z. B. "hóme" und "home" verschieden bleiben.
 */
function dyndnsAsciiLower(string $value): string
{
    return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}

/**
 * Löscht die angegebenen Records; systemgenerierte Records (server_id IS NOT NULL) werden
 * dabei grundsätzlich ausgeschlossen.
 *
 * @param PDO   $pdo
 * @param int[] $ids
 * @return int Anzahl tatsächlich gelöschter Records
 */
function dyndnsDeleteRecords(PDO $pdo, array $ids): int
{
    $stmt = $pdo->prepare("DELETE FROM records WHERE id = ? AND server_id IS NULL");
    $deleted = 0;

    foreach ($ids as $rid) {
        $stmt->execute([(int)$rid]);
        $deleted += $stmt->rowCount();
    }

    return $deleted;
}

/**
 * Liefert den Sprachschlüssel zu einem Fehler- oder Konfliktcode
 * (z. B. DYNDNS_CONFLICT_CNAME → dyndns_conflict_cname).
 */
function dyndnsLangKey(string $code): string
{
    return strtolower($code);
}

/**
 * true bei Lock-Wait-Timeout (1205) oder Deadlock (1213) in MariaDB/MySQL.
 */
function dyndnsIsLockError(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    $driverCode = (int)($e->errorInfo[1] ?? 0);

    return $driverCode === 1205 || $driverCode === 1213;
}

/**
 * true bei Verletzung eines UNIQUE-Schlüssels (1062), z. B. doppelter Benutzername.
 */
function dyndnsIsDuplicateKeyError(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    return (int)($e->errorInfo[1] ?? 0) === 1062;
}
