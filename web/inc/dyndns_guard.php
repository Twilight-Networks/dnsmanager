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
 * - Reservierungsschutz für gewöhnliche Record-Operationen (dyndnsCheckRecordChange)
 *
 * Die Funktionen kennen keine Web-Schicht (keine Session, keine Toasts, kein HTML).
 *
 * Synchronisierung:
 * Alle Prüfungen sind nur dann autoritativ, wenn sie innerhalb einer Transaktion NACH
 * dyndnsLockZones() ausgeführt werden. dyndnsLockZones() muss die erste Abfrage der
 * Transaktion sein: Die Sperre ist ein Locking Read; die nachfolgenden einfachen SELECTs
 * erzeugen ihren Read-Snapshot erst nach dem Warten auf die Sperre und sehen damit alle
 * zuvor committeten Änderungen anderer Sperrinhaber (DynDNS-Accountverwaltung, DynDNS-API,
 * RecordService, Publish). Schreibpfade, die diese Zonensperre NICHT verwenden (z. B. das
 * Neuaufbauen der Server-NS/Glue-Records, die Zonenverwaltung und AutoPTR), werden dadurch nicht
 * serialisiert. Ohne UNIQUE-Constraint auf (zone_id, hostname) ist die Zonensperre der einzige
 * Schutz gegen parallele Doppelbelegungen.
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

/* ---------------------------------------------------------------------------------------------
 * DynDNS-Update-API (PR #37)
 * ------------------------------------------------------------------------------------------- */

/**
 * Sicherheitsbedingte Ablehnung eines DynDNS-API-Updates.
 *
 * $response ist die an den Client gelieferte DynDNS-Antwort ('badauth' oder 'dnserr'),
 * $reason ein maschinenlesbarer Grund fürs Log.
 */
final class DyndnsApiReject extends RuntimeException
{
    public string $response;
    public string $reason;

    public function __construct(string $response, string $reason, string $detail = '')
    {
        parent::__construct($reason . ($detail !== '' ? ': ' . $detail : ''));
        $this->response = $response;
        $this->reason = $reason;
    }
}

/**
 * Prüft und normalisiert einen IP-Parameter der DynDNS-API.
 *
 * - 'A'    akzeptiert ausschließlich IPv4, 'AAAA' ausschließlich IPv6
 * - nicht vorhandener oder leerer Parameter → status 'empty'
 * - nichtskalare Werte (z. B. myip[]=…), ungültige Adressen oder falsche Familie → status 'invalid'
 * - gültige Adressen werden über inet_pton()/inet_ntop() normalisiert
 *
 * @param mixed  $raw  Rohwert aus $_GET (string, array, null …)
 * @param string $type 'A' oder 'AAAA'
 * @return array{status:string, ip:?string}
 */
function dyndnsParseApiIp(mixed $raw, string $type): array
{
    if ($raw === null) {
        return ['status' => 'empty', 'ip' => null];
    }

    if (!is_string($raw)) {
        return ['status' => 'invalid', 'ip' => null];
    }

    $value = trim($raw);
    if ($value === '') {
        return ['status' => 'empty', 'ip' => null];
    }

    $flag = $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
    if (filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
        return ['status' => 'invalid', 'ip' => null];
    }

    $bin = @inet_pton($value);
    $normalized = $bin !== false ? inet_ntop($bin) : false;
    if ($normalized === false) {
        return ['status' => 'invalid', 'ip' => null];
    }

    return ['status' => 'ok', 'ip' => $normalized];
}

/**
 * Vergleicht zwei IP-Adressen binär (inet_pton). Ungültige Werte sind nie gleich.
 * Unterschiedliche gültige Schreibweisen derselben IPv6-Adresse gelten als gleich.
 */
function dyndnsIpEquals(?string $a, ?string $b): bool
{
    if ($a === null || $b === null) {
        return false;
    }

    $binA = @inet_pton(trim($a));
    $binB = @inet_pton(trim($b));

    return $binA !== false && $binB !== false && $binA === $binB;
}

/**
 * Sperrt alle Records einer Zone (SELECT … FOR UPDATE über den zone_id-Index) und liefert
 * die aktuellen A/AAAA-Records.
 *
 * Unter REPEATABLE READ (InnoDB-Standard) sperrt die Abfrage zusätzlich den Indexbereich der
 * Zone, sodass andere Transaktionen bis zum Commit keine Records dieser Zone ändern, löschen oder
 * neu anlegen können. Unter READ COMMITTED entfallen diese Bereichssperren; dann schützt nur
 * noch die Zeilensperre bestehender Records und das bedingte UPDATE.
 *
 * Muss nach dyndnsLockZones() und vor allen einfachen SELECTs der Transaktion aufgerufen werden.
 *
 * @return array<int, array{id:int|string, name:string, type:string, content:string, server_id:int|string|null}>
 */
function dyndnsLockZoneAddressRecords(PDO $pdo, int $zoneId): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('dyndnsLockZoneAddressRecords() erfordert eine aktive Transaktion.');
    }

    $stmt = $pdo->prepare("SELECT id, name, type, content, server_id FROM records WHERE zone_id = ? FOR UPDATE");
    $stmt->execute([$zoneId]);

    $records = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rec) {
        $type = strtoupper((string)$rec['type']);
        if ($type === 'A' || $type === 'AAAA') {
            $records[] = $rec;
        }
    }

    return $records;
}

/**
 * Entscheidet für beide Adressfamilien, ob ein DynDNS-API-Update zulässig ist.
 *
 * Reine Funktion ohne Datenbankzugriff; Namensvergleiche sind unabhängig von der Collation.
 * Für jede Familie (auch nicht angeforderte) gilt:
 * - Kandidaten: alle Records des Typs, deren kanonischer Name (dyndnsCanonicalName) dem Hostnamen
 *   entspricht (inkl. absolut gespeicherter Namen und Namen mit Leerzeichen am Rand).
 * - Systemrecord (server_id) unter den Kandidaten                → reject 'system_record'
 * - Kandidat in abweichender Schreibweise (nur ASCII-Groß-/Kleinschreibung zulässig) → 'ambiguous_name'
 * - mehr als ein Kandidat                                         → reject 'multiple_records'
 * - genau ein Kandidat: gespeicherte current_ipv4/6 muss vorhanden und binär gleich dem
 *   Record-Inhalt sein, sonst reject 'foreign_record' – auch wenn der Record zufällig bereits
 *   der neuen IP entspricht. Erst danach: neue IP gleich → 'nochg', sonst → 'update'.
 * - kein Kandidat: angefordert → 'insert', sonst → 'none'
 * Ein Ablehnungsgrund in einer Familie lehnt die gesamte Anfrage ab.
 *
 * @param array  $records   A/AAAA-Records der Zone (dyndnsLockZoneAddressRecords)
 * @param string $zoneName
 * @param string $hostname  Gespeicherter, validierter Hostname des Accounts
 * @param array  $current   ['A' => ?string, 'AAAA' => ?string] (current_ipv4 / current_ipv6)
 * @param array  $requested ['A' => ?string, 'AAAA' => ?string] normalisierte neue IPs oder null
 * @return array{reject:?string, detail:string, types:array<string, array{action:string, record:?array, ip:?string}>}
 */
function dyndnsPlanApiRecordChanges(
    array $records,
    string $zoneName,
    string $hostname,
    array $current,
    array $requested
): array {
    $host       = dyndnsCanonicalName($hostname, $zoneName);
    $strictName = dyndnsAsciiLower($hostname);
    $types      = [];

    foreach (['A', 'AAAA'] as $type) {
        $candidates = [];
        foreach ($records as $rec) {
            if (strtoupper((string)$rec['type']) !== $type) {
                continue;
            }
            if ($host !== null && dyndnsCanonicalName((string)$rec['name'], $zoneName) === $host) {
                $candidates[] = $rec;
            }
        }

        $newIp = $requested[$type] ?? null;

        foreach ($candidates as $rec) {
            if ($rec['server_id'] !== null) {
                return ['reject' => 'system_record', 'detail' => "$type record_id={$rec['id']}", 'types' => []];
            }
        }

        foreach ($candidates as $rec) {
            if (dyndnsAsciiLower((string)$rec['name']) !== $strictName) {
                return ['reject' => 'ambiguous_name', 'detail' => "$type record_id={$rec['id']} name=" . json_encode((string)$rec['name'], JSON_INVALID_UTF8_SUBSTITUTE), 'types' => []];
            }
        }

        if (count($candidates) > 1) {
            return ['reject' => 'multiple_records', 'detail' => "$type count=" . count($candidates), 'types' => []];
        }

        if (count($candidates) === 1) {
            $rec = $candidates[0];
            $stored = $current[$type] ?? null;

            // Zuordnung nur bei vorhandener, inhaltlich gleicher gespeicherter Account-IP
            if ($stored === null || trim($stored) === '' || !dyndnsIpEquals($stored, (string)$rec['content'])) {
                return ['reject' => 'foreign_record', 'detail' => "$type record_id={$rec['id']}", 'types' => []];
            }

            if ($newIp === null) {
                $types[$type] = ['action' => 'none', 'record' => $rec, 'ip' => null];
            } elseif (dyndnsIpEquals($newIp, (string)$rec['content'])) {
                $types[$type] = ['action' => 'nochg', 'record' => $rec, 'ip' => $newIp];
            } else {
                $types[$type] = ['action' => 'update', 'record' => $rec, 'ip' => $newIp];
            }
            continue;
        }

        $types[$type] = $newIp === null
            ? ['action' => 'none', 'record' => null, 'ip' => null]
            : ['action' => 'insert', 'record' => null, 'ip' => $newIp];
    }

    return ['reject' => null, 'detail' => '', 'types' => $types];
}

/**
 * Aktualisiert genau einen zuvor geprüften A/AAAA-Record bedingt: Das UPDATE greift nur, wenn
 * ID, Zone, Name, Typ und bisheriger Inhalt unverändert sind und kein Systemrecord vorliegt.
 * Wirkt es nicht auf genau eine Zeile, wird eine Exception geworfen (Aufrufer: Rollback).
 *
 * @param array  $record Geprüfter Record (id, name, type, content)
 * @param string $newIp  Normalisierte neue IP (unterscheidet sich vom bisherigen Inhalt)
 */
function dyndnsApplyApiRecordUpdate(PDO $pdo, int $zoneId, array $record, string $newIp): void
{
    $stmt = $pdo->prepare("
        UPDATE records
        SET content = ?
        WHERE id = ?
          AND zone_id = ?
          AND BINARY name = ?
          AND type = ?
          AND BINARY content = ?
          AND server_id IS NULL
    ");
    $stmt->execute([
        $newIp,
        (int)$record['id'],
        $zoneId,
        (string)$record['name'],
        strtoupper((string)$record['type']),
        (string)$record['content'],
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new DyndnsApiReject('dnserr', 'record_changed', "record_id={$record['id']} rowCount=" . $stmt->rowCount());
    }
}

// ---------------------------------------------------------------------------------------------
// Reservierungsschutz für gewöhnliche Record-Operationen (RecordService)
// ---------------------------------------------------------------------------------------------

/**
 * Lädt alle DynDNS-Accounts einer Zone. Maßgeblich ist ausschließlich zones.id (zone_id);
 * allow_dyndns und Zonentyp werden bewusst NICHT berücksichtigt, damit auch Altbestände in
 * deaktivierten oder ungewöhnlichen Zonen geschützt bleiben.
 *
 * Autoritativ nur innerhalb einer Transaktion nach dyndnsLockZones() für $zoneId.
 *
 * @return array<int, array{id:int|string, username:string, hostname:string, current_ipv4:?string, current_ipv6:?string}>
 */
function dyndnsLoadZoneAccounts(PDO $pdo, int $zoneId): array
{
    $stmt = $pdo->prepare("
        SELECT id, username, hostname, current_ipv4, current_ipv6
        FROM dyndns_accounts
        WHERE zone_id = ?
        ORDER BY id
    ");
    $stmt->execute([$zoneId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Liefert Schreibweisen eines Namens für den losen Vergleich über die Datenbank-Collation:
 * Original, ohne Rand-Leerzeichen, ohne abschließenden Punkt, relativ zur Zone (Groß-/Kleinschreibung
 * bleibt erhalten) sowie die absoluten Schreibweisen mit und ohne abschließenden Punkt.
 *
 * @return string[]
 */
function dyndnsLooseNameForms(string $name, string $zoneName): array
{
    $zone  = rtrim(trim($zoneName), '.');
    $trim  = trim($name);
    $noDot = rtrim($trim, '.');
    $rel   = $noDot;

    if ($zone !== '' && strlen($noDot) > strlen($zone) + 1
        && dyndnsAsciiLower(substr($noDot, -(strlen($zone) + 1))) === '.' . dyndnsAsciiLower($zone)) {
        $rel = substr($noDot, 0, -(strlen($zone) + 1));
    }

    $forms = [$name, $trim, $noDot, $rel];

    if ($rel !== '' && $rel !== '@' && $zone !== '') {
        $forms[] = $rel . '.' . $zone;
        $forms[] = $rel . '.' . $zone . '.';
    }

    return array_values(array_unique($forms));
}

/**
 * Ermittelt, welche DynDNS-Accounts einen Owner-Namen reservieren.
 *
 * - strict:      kanonisch gleicher Name (dyndnsCanonicalName; DNS-Semantik: nur ASCII-Groß-/
 *                Kleinschreibung, relativ/absolut gleichwertig, Rand-Leerzeichen ignoriert)
 * - loose:       Accounts, deren Hostname nur unter der Datenbank-Collation gleich ist (z. B. "hóme"
 *                = "home"). Diese Treffer dürfen eine konservative Ablehnung auslösen, begründen
 *                aber NIE die Zuordnung eines Records zu einem Account.
 * - descendants: Accounts, deren Hostname unterhalb des Owner-Namens liegt (Owner ist echter
 *                Vorfahr; der Apex ist Vorfahr aller Namen)
 *
 * @param array $accounts Ergebnis von dyndnsLoadZoneAccounts()
 * @return array{owner:?string, strict:array, loose:array, descendants:array}
 */
function dyndnsFindOwnerReservations(PDO $pdo, int $zoneId, string $zoneName, string $ownerName, array $accounts): array
{
    $owner = dyndnsCanonicalName($ownerName, $zoneName);
    $res = ['owner' => $owner, 'strict' => [], 'loose' => [], 'descendants' => []];

    if ($owner === null) {
        return $res; // außerhalb der Zone, von BIND ignoriert
    }

    $strictIds = [];
    foreach ($accounts as $acc) {
        $host = dyndnsCanonicalName((string)$acc['hostname'], $zoneName);
        if ($host === null) {
            continue;
        }
        if ($host === $owner) {
            $res['strict'][] = $acc;
            $strictIds[(int)$acc['id']] = true;
        } elseif ($owner === '@' || str_ends_with($host, '.' . $owner)) {
            $res['descendants'][] = $acc;
        }
    }

    // Loser Treffer über die Collation der Spalte (nur für Nicht-Apex-Namen)
    if ($owner !== '@' && $accounts !== []) {
        $forms = dyndnsLooseNameForms($ownerName, $zoneName);
        $in = implode(',', array_fill(0, count($forms), '?'));
        $stmt = $pdo->prepare("
            SELECT id FROM dyndns_accounts
            WHERE zone_id = ?
              AND (hostname IN ($in) OR TRIM(TRAILING '.' FROM TRIM(hostname)) IN ($in))
        ");
        $stmt->execute(array_merge([$zoneId], $forms, $forms));
        $looseIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($accounts as $acc) {
            $id = (int)$acc['id'];
            if (in_array($id, $looseIds, true) && !isset($strictIds[$id])) {
                $res['loose'][] = $acc;
            }
        }
    }

    return $res;
}

/**
 * Ordnet unter allen A/AAAA-Records einer Zone den Record eines DynDNS-Accounts zu – mit denselben
 * Kriterien wie die DynDNS-API (dyndnsPlanApiRecordChanges), jedoch für genau eine Adressfamilie.
 *
 * Reine Funktion ohne Datenbankzugriff, unabhängig von der Collation. Zustände:
 * - NONE       kein Record des Typs unter dem Hostnamen
 * - MANAGED    genau ein Record (kein Systemrecord, Name nur in ASCII-Groß-/Kleinschreibung vom
 *              Hostnamen verschieden), dessen Inhalt der gespeicherten Account-IP entspricht
 * - AMBIGUOUS  alles andere (Systemrecord, abweichende Schreibweise/absoluter Name, mehrere
 *              Records, fehlende oder abweichende gespeicherte IP)
 *
 * @param array       $zoneRecords A/AAAA-Records der Zone (id, name, type, content, server_id)
 * @param string      $type        'A' oder 'AAAA'
 * @param string|null $storedIp    current_ipv4 bzw. current_ipv6 des Accounts
 * @return array{state:string, record:?array}
 */
function dyndnsAttributeAddressRecord(array $zoneRecords, string $zoneName, string $hostname, string $type, ?string $storedIp): array
{
    $host = dyndnsCanonicalName($hostname, $zoneName);
    $strictName = dyndnsAsciiLower($hostname);
    $candidates = [];

    foreach ($zoneRecords as $rec) {
        if (strtoupper((string)$rec['type']) !== $type) {
            continue;
        }
        if ($host !== null && dyndnsCanonicalName((string)$rec['name'], $zoneName) === $host) {
            $candidates[] = $rec;
        }
    }

    if ($candidates === []) {
        return ['state' => 'NONE', 'record' => null];
    }

    foreach ($candidates as $rec) {
        if ($rec['server_id'] !== null || dyndnsAsciiLower((string)$rec['name']) !== $strictName) {
            return ['state' => 'AMBIGUOUS', 'record' => null];
        }
    }

    if (count($candidates) > 1) {
        return ['state' => 'AMBIGUOUS', 'record' => null];
    }

    $rec = $candidates[0];
    if ($storedIp === null || trim($storedIp) === '' || !dyndnsIpEquals($storedIp, (string)$rec['content'])) {
        return ['state' => 'AMBIGUOUS', 'record' => null];
    }

    return ['state' => 'MANAGED', 'record' => $rec];
}

/**
 * Prüft, ob eine geplante Record-Operation der RecordService-Schicht DynDNS-Reservierungen verletzt.
 *
 * Bewertet den bisherigen Zustand ($old, "verlassen") und den beabsichtigten Zustand ($new,
 * "betreten") getrennt:
 *
 * Verlassen – nur für A/AAAA an einem reservierten Namen (strikt oder lose):
 * - MANAGED (eindeutig zuordenbar, strikt): nur TTL-Änderungen zulässig; Name, Typ oder IP ändern
 *   und Löschen → MANAGED_RECORD
 * - sonst (mehrdeutig, geteilter Hostname, Systemrecord, nur loser Treffer) → AMBIGUOUS_RECORD,
 *   auch bei reiner TTL-Änderung (fail-closed)
 *
 * Betreten – nur wenn sich (kanonischer Name, Typ) gegenüber $old ändert oder $old fehlt:
 * - A/AAAA an reserviertem Namen                      → RESERVED_ADDRESS
 * - CNAME an reserviertem Namen                       → RESERVED_CNAME
 * - NS an reserviertem Namen oder an einem echten Vorfahren eines reservierten Namens
 *   (Apex ausgenommen)                                → RESERVED_DELEGATION
 * - DNAME an reserviertem Namen oder Vorfahren (Apex) → RESERVED_DNAME
 * Kompatible Typen (TXT, MX, CAA, SRV, …) sind erlaubt. Ein bereits bestehender CNAME/NS an
 * einem reservierten Namen darf in Inhalt/TTL geändert werden (kein neuer Konflikt).
 *
 * Glue- und Systemrecords werden hier nicht ausgenommen: An reservierten Hostnamen hat der
 * DynDNS-Schutz Vorrang vor den Glue-Ausnahmen. An nicht reservierten Namen greift diese
 * Funktion nicht ein.
 *
 * Autoritativ nur innerhalb einer Transaktion nach dyndnsLockZones() für $zoneId; $zoneName ist
 * der unter dieser Sperre gelesene Zonenname.
 *
 * @param array|null $old Bestehender Record (id, name, type, content, ttl, server_id) oder null (Anlegen)
 * @param array|null $new Beabsichtigter Zustand (name, type, content, ttl) oder null (Löschen)
 * @return array{code:string, name:string, type:string, hostname:string, accountIds:int[]}|null
 */
function dyndnsCheckRecordChange(PDO $pdo, int $zoneId, string $zoneName, ?array $old, ?array $new): ?array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('dyndnsCheckRecordChange() erfordert eine aktive Transaktion.');
    }

    $accounts = dyndnsLoadZoneAccounts($pdo, $zoneId);
    if ($accounts === []) {
        return null;
    }

    $violation = static function (string $code, string $name, string $type, array $accs): array {
        return [
            'code'       => $code,
            'name'       => $name,
            'type'       => $type,
            'hostname'   => $accs !== [] ? (string)$accs[0]['hostname'] : '',
            'accountIds' => array_map(static fn (array $a): int => (int)$a['id'], $accs),
        ];
    };

    // 1. Verlassen: bisheriger A/AAAA-Record an einem reservierten Namen
    if ($old !== null && in_array(strtoupper((string)$old['type']), ['A', 'AAAA'], true)) {
        $res = dyndnsFindOwnerReservations($pdo, $zoneId, $zoneName, (string)$old['name'], $accounts);
        $reserving = array_merge($res['strict'], $res['loose']);

        if ($reserving !== []) {
            $oldType = strtoupper((string)$old['type']);
            $state = 'AMBIGUOUS';

            if (count($res['strict']) === 1 && $res['loose'] === [] && $old['server_id'] === null) {
                $acc = $res['strict'][0];
                $stmt = $pdo->prepare("SELECT id, name, type, content, server_id FROM records WHERE zone_id = ? AND type = ?");
                $stmt->execute([$zoneId, $oldType]);
                $attr = dyndnsAttributeAddressRecord(
                    $stmt->fetchAll(PDO::FETCH_ASSOC),
                    $zoneName,
                    (string)$acc['hostname'],
                    $oldType,
                    $oldType === 'A' ? ($acc['current_ipv4'] ?? null) : ($acc['current_ipv6'] ?? null)
                );
                if ($attr['state'] === 'MANAGED' && (int)$attr['record']['id'] === (int)$old['id']) {
                    $state = 'MANAGED';
                }
            }

            if ($state !== 'MANAGED') {
                return $violation('AMBIGUOUS_RECORD', (string)$old['name'], $oldType, $reserving);
            }

            // MANAGED: ausschließlich TTL-Änderungen (Name, Typ und IP-Inhalt unverändert)
            $identityKept = $new !== null
                && (string)$new['type'] === (string)$old['type']
                && (string)$new['name'] === (string)$old['name']
                && (string)$new['content'] === (string)$old['content'];

            if (!$identityKept) {
                return $violation('MANAGED_RECORD', (string)$old['name'], $oldType, $reserving);
            }
        }
    }

    // 2. Betreten: beabsichtigter Name/Typ
    if ($new !== null) {
        $newType  = strtoupper((string)$new['type']);
        $newOwner = dyndnsCanonicalName((string)$new['name'], $zoneName);

        $keyUnchanged = $old !== null
            && $newOwner !== null
            && strtoupper((string)$old['type']) === $newType
            && dyndnsCanonicalName((string)$old['name'], $zoneName) === $newOwner;

        if (!$keyUnchanged && in_array($newType, ['A', 'AAAA', 'CNAME', 'NS', 'DNAME'], true)) {
            $res = dyndnsFindOwnerReservations($pdo, $zoneId, $zoneName, (string)$new['name'], $accounts);
            $atName = array_merge($res['strict'], $res['loose']);
            $code = null;
            $involved = $atName;

            if ($newType === 'A' || $newType === 'AAAA') {
                $code = $atName !== [] ? 'RESERVED_ADDRESS' : null;
            } elseif ($newType === 'CNAME') {
                $code = $atName !== [] ? 'RESERVED_CNAME' : null;
            } elseif ($newType === 'NS') {
                if ($atName !== []) {
                    $code = 'RESERVED_DELEGATION';
                } elseif ($res['owner'] !== '@' && $res['descendants'] !== []) {
                    $code = 'RESERVED_DELEGATION';
                    $involved = $res['descendants'];
                }
            } else { // DNAME
                if ($atName !== []) {
                    $code = 'RESERVED_DNAME';
                } elseif ($res['descendants'] !== []) {
                    $code = 'RESERVED_DNAME';
                    $involved = $res['descendants'];
                }
            }

            if ($code !== null) {
                return $violation($code, (string)$new['name'], $newType, $involved);
            }
        }
    }

    return null;
}
