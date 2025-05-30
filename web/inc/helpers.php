<?php
/**
 * Datei: helpers.php
 * Zweck: Sammlung von Hilfsfunktionen für Zugriffsprüfungen, Validierungen und kleinere Utilities.
 *
 * Enthalten:
 * - Zugriffskontrolle auf Basis der Benutzerrolle (requireRole)
 * - Zugriff verweigert protokollieren (logAccessDenied)
 * - Passwort-Validierung gegen Mindestlänge (validatePassword)
 * - Markieren des aktuellen Navigationslinks (isActive)
 */
require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/deploy/bind_file_generator.php';

/**
 * Prüft, ob der aktuell eingeloggte Benutzer eine der erlaubten Rollen besitzt.
 *
 * Bei fehlender oder unzureichender Rolle:
 * - Zugriff verweigern (HTTP 403)
 * - Ereignis protokollieren (Fail2Ban-kompatibles Format)
 *
 * @param array $allowedRoles Liste erlaubter Rollen (z.B. ['admin', 'zoneadmin']).
 *
 * @return void
 */
function requireRole(array $allowedRoles): void
{
    $role = $_SESSION['role'] ?? null;

    if ($role === null) {
        logAccessDenied('requireRole', 'Fehlende Benutzerrolle.');
        http_response_code(403);
        exit('Zugriff verweigert: Fehlende Benutzerrolle.');
    }

    if (!in_array($role, $allowedRoles, true)) {
        logAccessDenied('requireRole', 'Unzureichende Berechtigungen für Rolle: ' . $role);
        http_response_code(403);
        exit('Zugriff verweigert: Unzureichende Berechtigungen.');
    }
}

/**
 * Prüft, ob ein Passwort die in der Konfiguration definierte Mindestlänge erreicht.
 *
 * @param string $password Das zu prüfende Passwort.
 *
 * @return bool true, wenn Passwort gültig; false, wenn zu kurz.
 */
function validatePassword(string $password): bool
{
    return strlen($password) >= PASSWORD_MIN_LENGTH;
}

/**
 * Ermittelt, ob eine bestimmte Seite aktiv ist (z.B. für Navigation).
 *
 * @param string $page Dateiname der Seite (z.B. 'zones.php').
 *
 * @return string Gibt 'active' zurück, falls die Seite aktuell ist, ansonsten ein leerer String.
 */
function isActive(string $page): string
{
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

/**
 * get_zone_by_id
 *
 * Lädt eine vollständige Zonenbeschreibung aus der Datenbank anhand ihrer ID.
 *
 * @param int $zone_id ID der gesuchten Zone
 * @return array|false Assoziatives Array mit Zonendaten oder false, wenn nicht gefunden
 */
function get_zone_by_id(int $zone_id)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Gibt ein CSRF-Token als <input>-Feld zurück.
 *
 * @return string HTML-Input-Feld mit gültigem CSRF-Token
 */
function csrf_input(): string
{
    $token = $_SESSION['csrf_token'] ?? '';
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * rebuild_zone_and_validate
 *
 * Erstellt eine temporäre Zonendatei für die angegebene Zone und prüft diese mit `named-checkzone`.
 * Diese Funktion dient ausschließlich der Validierung – es erfolgt keine produktive Ausgabe
 * und die temporäre Datei wird nach der Prüfung gelöscht.
 *
 * Ablauf:
 * - Ruft `generate_zone_file(..., '/tmp', 'validate')` auf.
 * - Die Zonendatei wird in `/tmp/db.{zone}` geschrieben und geprüft.
 * - Die Datei wird danach gelöscht.
 *
 * Rückgabewerte:
 * - ['status' => 'ok', 'output' => '...'] – Datei ist syntaktisch gültig
 * - ['status' => 'error', 'output' => '...'] – Generierung oder Prüfung schlug fehl
 *
 * @param int $zone_id ID der zu validierenden Zone
 * @return array ['status' => 'ok'|'error', 'output' => string]
 */function rebuild_zone_and_validate(int $zone_id): array
{
    // Nur temporär zur Validierung → kein produktives Schreiben
    $result = generate_zone_file($zone_id, '/tmp', 'validate');

    if (!is_array($result) || $result['status'] === 'error') {
        return [
            'status' => 'error',
            'output' => $result['output'] ?? 'Fehler beim Validieren der Zonendatei.'
        ];
    }

    return [
        'status' => $result['status'],
        'output' => $result['output'] ?? 'Zone erfolgreich validiert.'
    ];
}

/**
 * rebuild_zone_and_flag_if_valid
 *
 * Führt eine Validierung der Zonendatei durch und markiert die Zone als geändert,
 * falls die Validierung erfolgreich war. Dies ist sinnvoll nach jeder inhaltlichen Änderung
 * (z. B. beim Hinzufügen oder Bearbeiten von Records), um die Zone für einen späteren
 * `publish_all` vorzumerken.
 *
 * Ablauf:
 * - Ruft `rebuild_zone_and_validate()` zur Prüfung auf syntaktische Gültigkeit auf.
 * - Falls gültig, wird das Feld `changed` in der Tabelle `zones` auf `1` gesetzt.
 *
 * Rückgabewerte:
 * - ['status' => 'ok', 'output' => '...'] – Zone valide, Änderung markiert
 * - ['status' => 'error', 'output' => '...'] – Validierung fehlgeschlagen oder DB-Fehler
 *
 * @param int $zone_id ID der betroffenen Zone
 * @return array ['status' => 'ok'|'error', 'output' => string]
 */
function rebuild_zone_and_flag_if_valid(int $zone_id): array
{
    $result = rebuild_zone_and_validate($zone_id);

    if (!is_array($result) || $result['status'] === 'error') {
        return [
            'status' => 'error',
            'output' => $result['output'] ?? 'Zonenvalidierung fehlgeschlagen.'
        ];
    }

    // Zone als geändert markieren
    try {
        $stmt = $GLOBALS['pdo']->prepare("UPDATE zones SET changed = 1 WHERE id = ?");
        $stmt->execute([$zone_id]);
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'output' => "Zone konnte nicht als geändert markiert werden: " . $e->getMessage()
        ];
    }

    return [
        'status' => $result['status'], // ok oder warning
        'output' => $result['output'] ?? 'Zone wurde als geändert markiert.'
    ];
}

/**
 * Erzeugt die NS- und Glue-Records (A/AAAA) für alle Server einer Zone neu.
 *
 * Diese Funktion:
 * - löscht alle automatisch generierten NS-, A- und AAAA-Records mit server_id (also alle systemgesteuerten),
 * - erstellt für jeden zugewiesenen Server einen NS-Eintrag (Name = '@', Content = FQDN),
 * - erstellt Glue-Records (A/AAAA), falls ein Servername innerhalb der Zone liegt (FQDN endet auf .zone),
 * - verwendet bei der Neuerstellung die zuvor gespeicherten TTL-Werte (falls vorhanden),
 * - gibt das Ergebnis des Zonendatei-Rebuilds via rebuild_zone_and_flag_if_valid() zurück.
 *
 * @param PDO $pdo Verbindungsobjekt zur Datenbank
 * @param int $zone_id ID der DNS-Zone
 * @return array Ergebnisstruktur mit status ('ok', 'warning', 'error') und output (Meldung)
 */
function rebuild_ns_and_glue_for_zone_and_flag_if_valid(PDO $pdo, int $zone_id): array
{
    // Zone ermitteln
    $stmt = $pdo->prepare("SELECT name, type FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    $zone = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$zone) {
        return ['status' => 'error', 'output' => "Zone-ID {$zone_id} nicht gefunden."];
    }

    $zone_name = rtrim($zone['name'], '.');
    $type = $zone['type'];

    // Alle zugewiesenen Server abrufen
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.dns_ip4, s.dns_ip6
        FROM zone_servers zs
        JOIN servers s ON zs.server_id = s.id
        WHERE zs.zone_id = ?
    ");
    $stmt->execute([$zone_id]);
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($servers)) {
        return ['status' => 'error', 'output' => "Keine Server für Zone {$zone_name} zugewiesen."];
    }

    // TTLs vorhandener Records vor dem Löschen sichern
    $stmt = $pdo->prepare("
        SELECT name, type, ttl
        FROM records
        WHERE zone_id = ? AND server_id IS NOT NULL AND type IN ('NS', 'A', 'AAAA')
    ");
    $stmt->execute([$zone_id]);
    $existing_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ttl_map = []; // Format: ['NS']['@'] oder ['A']['ns1'] etc.
    foreach ($existing_records as $rec) {
        $key = ($rec['type'] === 'NS') ? '@' : strtolower($rec['name']);
        $ttl_map[$rec['type']][$key] = (int)$rec['ttl'];
    }

    // Alle vorhandenen systemgenerierten NS/A/AAAA-Records löschen
    $pdo->prepare("
        DELETE FROM records
        WHERE zone_id = ? AND server_id IS NOT NULL AND type IN ('NS', 'A', 'AAAA')
    ")->execute([$zone_id]);

    // Neue Insert-Statements vorbereiten
    $stmt_ns   = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, '@', 'NS', ?, ?, ?)");
    $stmt_a    = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, ?, 'A', ?, ?, ?)");
    $stmt_aaaa = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl, server_id) VALUES (?, ?, 'AAAA', ?, ?, ?)");

    foreach ($servers as $srv) {
        $sid     = $srv['id'];
        $fqdn    = rtrim($srv['name'], '.') . '.';
        $is_glue = $type === 'forward' && str_ends_with($fqdn, '.' . $zone_name . '.');

        // TTL für NS-Record holen oder Fallback
        $ns_ttl = $ttl_map['NS']['@'] ?? 3600;
        $stmt_ns->execute([$zone_id, $fqdn, $ns_ttl, $sid]);

        // Glue-Records nur bei Forward-Zonen und wenn FQDN in der Zone liegt
        if ($is_glue) {
            $host_part = rtrim(str_replace('.' . $zone_name . '.', '', $fqdn), '.');

            if (!empty($srv['dns_ip4'])) {
                $a_ttl = $ttl_map['A'][strtolower($host_part)] ?? 300;
                $stmt_a->execute([$zone_id, $host_part, $srv['dns_ip4'], $a_ttl, $sid]);
            }

            if (!empty($srv['dns_ip6'])) {
                $aaaa_ttl = $ttl_map['AAAA'][strtolower($host_part)] ?? 300;
                $stmt_aaaa->execute([$zone_id, $host_part, $srv['dns_ip6'], $aaaa_ttl, $sid]);
            }
        }
    }

    // Zonendatei neu generieren und prüfen
    return rebuild_zone_and_flag_if_valid($zone_id);
}
?>
