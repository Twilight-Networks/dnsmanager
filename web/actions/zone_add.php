<?php
/**
 * Datei: zone_add.php
 * Zweck: Legt eine neue DNS-Zone im System an (Forward oder Reverse).
 * Details:
 * - Nur Admins dürfen neue Zonen anlegen (geschützt über requireRole(['admin'])).
 * - Unterstützt Forward- und Reverse-Zonen mit dynamischem SOA.
 * - Nach erfolgreichem Insert wird:
 *   - die Zone gespeichert
 *   - NS-Records für alle Server (inkl. Master) werden eingetragen
 *   - A-Records für Glue-Hosts (nur bei Forward-Zonen)
 *   - die Serverzuweisung (inkl. Master) gepflegt
 *   - die Zonendatei regeneriert
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/validators.php';

requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Eingabevalidierung
$errors = validateZoneInput($_POST);
if (!empty($errors)) {
    foreach ($errors as $error) {
        toastError(
            $error,
            "Zoneneingabe ungültig: {$error}"
        );
    }
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?add_new=1");
    exit;
}

// Zonenparameter vorbereiten
$prefix        = trim($_POST['zone_prefix']);
$selected_type = $_POST['type'];
$type          = in_array($selected_type, ['reverse_ipv4', 'reverse_ipv6']) ? 'reverse' : 'forward';
$name          = $type === 'reverse'
    ? $prefix . ($selected_type === 'reverse_ipv6' ? '.ip6.arpa' : '.in-addr.arpa')
    : $prefix;

$ttl           = intval($_POST['ttl']) ?: 86400;
$ttl_ns        = 3600;
$ttl_glue      = 300;
$prefix_length = $type === 'reverse' ? (int)($_POST['prefix_length'] ?? null) : null;
$description   = trim($_POST['description']) ?: null;

$today        = date('Ymd');
$soa_serial   = (int)($today . '01');
$soa_mail     = rtrim(trim($_POST['soa_mail']), '.') . '.';
$soa_refresh  = (int)$_POST['soa_refresh'];
$soa_retry    = (int)$_POST['soa_retry'];
$soa_expire   = (int)$_POST['soa_expire'];
$soa_minimum  = (int)$_POST['soa_minimum'];
$allow_dyndns = isset($_POST['allow_dyndns']) && $_POST['allow_dyndns'] === '1' ? 1 : 0;

// Serverzuweisung vorbereiten
$server_ids = $_POST['server_ids'] ?? [];
$master_id  = $_POST['master_server_id'] ?? null;

if (!is_array($server_ids) || count($server_ids) < 1) {
    toastError(
        "Es muss mindestens ein Server ausgewählt werden.",
        "Zonenanlage fehlgeschlagen: Kein Server ausgewählt."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?add_new=1");
    exit;
}

if (!$master_id || !in_array($master_id, $server_ids, true)) {
    toastError(
        "Der Master-Server muss unter den gewählten Servern sein.",
        "Zonenanlage fehlgeschlagen: Master nicht unter den ausgewählten Servern."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?add_new=1");
    exit;
}

// Master-Server laden (Pflicht für SOA NS)
$stmt = $pdo->prepare("SELECT name, dns_ip4, dns_ip6 FROM servers WHERE id = ?");
$stmt->execute([$master_id]);
$master = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$master) {
    toastError(
        "Master-Server konnte nicht geladen werden.",
        "Zonenanlage fehlgeschlagen: Master-ID {$master_id} nicht ladbar."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?add_new=1");
    exit;
}

// SOA-NS zusammensetzen (FQDN):
// - Bei Reverse-Zonen: $master_name.$soa_domain.
// - Bei Forward-Zonen:
//    - Wenn Servername bereits auf .zone endet → keine Dopplung
//    - Andernfalls wird Zonenname ergänzt
$zone_base      = rtrim($name, '.');
$master_name    = rtrim($master['name'], '.');
$soa_domain     = rtrim($_POST['soa_domain'] ?? '', '.');

$soa_ns         = ($type === 'reverse')
    ? $master_name . '.' . $soa_domain . '.'
    : (
        str_ends_with($master_name, '.' . $zone_base)
            ? $master_name . '.'
            : $master_name . '.' . $zone_base . '.'
    );

// Transaktion starten
try {
    $pdo->beginTransaction();

    // Zone einfügen mit vollständigem SOA NS
    $stmt = $pdo->prepare("
        INSERT INTO zones
            (name, type, ttl, prefix_length, description,
             soa_ns, soa_mail, soa_refresh, soa_retry, soa_expire, soa_minimum, soa_serial, changed, allow_dyndns)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $stmt->execute([
        $name,
        $type,
        $ttl,
        $prefix_length ?: null,
        $description,
        $soa_ns,
        $soa_mail,
        $soa_refresh,
        $soa_retry,
        $soa_expire,
        $soa_minimum,
        $soa_serial,
        $allow_dyndns
    ]);

    $zone_id = (int)$pdo->lastInsertId();
    if ($zone_id < 1) {
        throw new Exception("Fehler: Keine gültige Zonen-ID erzeugt.");
    }

    //A und AAAA-Records für alle zugewiesenen Server
    $stmt_server = $pdo->prepare("SELECT name, dns_ip4, dns_ip6 FROM servers WHERE id = ?");

    $stmt_ns = $pdo->prepare("
        INSERT INTO records (zone_id, name, type, content, ttl, server_id)
        VALUES (?, '@', 'NS', ?, ?, ?)
    ");

    $stmt_a = $pdo->prepare("
        INSERT INTO records (zone_id, name, type, content, ttl, server_id)
        VALUES (?, ?, 'A', ?, ?, ?)
    ");

    $stmt_aaaa = $pdo->prepare("
        INSERT INTO records (zone_id, name, type, content, ttl, server_id)
        VALUES (?, ?, 'AAAA', ?, ?, ?)
    ");

    foreach ($server_ids as $sid) {
        $stmt_server->execute([$sid]);
        $srv = $stmt_server->fetch(PDO::FETCH_ASSOC);

        if (!$srv) {
            throw new Exception("Zugewiesener Server nicht gefunden (ID: $sid)");
        }

        // Vollqualifizierter NS-Name
        $ns_fqdn = rtrim($srv['name'], '.') . '.';
        $is_glue = $type === 'forward' && str_ends_with($ns_fqdn, '.' . $zone_base . '.');

        // NS-Record einfügen
        $stmt_ns->execute([$zone_id, $ns_fqdn, $ttl_ns, $sid]);


        // Glue-Records bei Forward-Zonen
        if ($is_glue) {
            // Kurzname extrahieren (z. B. ns1)
            $host_part = rtrim(str_replace('.' . $zone_base . '.', '', $ns_fqdn), '.');

            if (!empty($srv['dns_ip4'])) {
                $stmt_a->execute([$zone_id, $host_part, $srv['dns_ip4'], $ttl_glue, $sid]);
            }

            if (!empty($srv['dns_ip6'])) {
                $stmt_aaaa->execute([$zone_id, $host_part, $srv['dns_ip6'], $ttl_glue, $sid]);
            }
        }
    }

    // Serverzuweisungen speichern
    $stmt = $pdo->prepare("
        INSERT INTO zone_servers (zone_id, server_id, is_master)
        VALUES (:zone_id, :server_id, :is_master)
    ");
    foreach ($server_ids as $sid) {
        $stmt->execute([
            ':zone_id'   => $zone_id,
            ':server_id' => (int)$sid,
            ':is_master' => ((int)$sid === (int)$master_id) ? 1 : 0
        ]);
    }

    // Zonendatei prüfen / Flag setzen
    $rebuild = rebuild_zone_and_flag_if_valid($zone_id);

    if ($rebuild['status'] === 'error') {
        $pdo->rollBack();
        toastError(
            "Die Zone konnte nicht gespeichert werden, da die Zonendatei ungültig wäre.",
            $rebuild['output']
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?add_new=1");
        exit;
    }

    if ($rebuild['status'] === 'warning') {
        toastWarning(
            "Zone wurde erstellt – Warnung beim Zonendatei-Check.",
            $rebuild['output']
        );
    }

    $pdo->commit();
    toastSuccess(
        "Zone <strong>" . htmlspecialchars($name) . "</strong> erfolgreich angelegt.",
        "Zone '{$name}' wurde erfolgreich erstellt und gespeichert."
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    toastError(
        "Fehler beim Speichern der Zone.",
        "Datenbankfehler beim Speichern von Zone '{$name}': " . $e->getMessage()
    );
    header("Location: " . rtrim(BASE_URL, '/') . "/pages/zones.php?add_new=1");
    exit;
}
?>
