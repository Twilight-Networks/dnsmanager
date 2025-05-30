<?php
/**
 * Datei: record_add.php
 * Zweck: Fügt einen neuen DNS-Record zu einer bestehenden Zone hinzu.
 *
 * Details:
 * - Verarbeitet POST-Daten aus dem Formular zum Anlegen neuer DNS-Einträge.
 * - CSRF-Token wird geprüft, um Manipulationen und Angriffe zu verhindern.
 * - Rollenbasierte Zugriffskontrolle: Admins können systemweit schreiben,
 *   Zone-Admins nur innerhalb der ihnen zugewiesenen Zonen.
 * - Unterstützt verschiedenste Record-Typen (A, AAAA, MX, DKIM, SRV etc.)
 *   und verarbeitet diese über `record_content_builder.php`.
 * - Eingabedaten werden validiert und in die Datenbank übernommen.
 *
 * Zugriff:
 * - Nur per POST erlaubt.
 * - Zugriffsschutz erfolgt durch `verify_csrf_token()`, `require_login()`,
 *   sowie durch eine Rollen- und Zonenprüfung mittels `requireRole()` und Zonenbesitz.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
require_once __DIR__ . '/../inc/record_content_builder.php';
require_once __DIR__ . '/../inc/dkim_helpers.php';
require_once __DIR__ . '/../inc/validators.php';

/**
 * Versucht, für einen A- oder AAAA-Record automatisch einen PTR-Record anzulegen.
 *
 * Voraussetzungen:
 * - Die zugehörige Reverse-Zone muss bereits existieren.
 * - Es darf kein PTR mit gleichem Namen in der Reverse-Zone existieren.
 *
 * @param PDO $pdo
 * @param int $zone_id Forward-Zonen-ID
 * @param string $type 'A' oder 'AAAA'
 * @param string $ip Ziel-IP-Adresse
 * @param string $name Eingetragener Name des A/AAAA-Records
 * @param int $ttl TTL-Wert
 *
 * @return bool true bei Erfolg, false bei Fehler (Fehlermeldungen werden als Toast gesetzt)
 */
function tryAutoPtr(PDO $pdo, int $zone_id, string $type, string $ip, string $name, int $ttl): bool
{
    // Zonenname laden
    $stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    $zone_name = rtrim($stmt->fetchColumn(), '.');

    // Hostname ermitteln
    $hostname = ($name === '@' || trim($name) === '')
        ? $zone_name . '.'
        : rtrim($name, '.') . '.' . $zone_name . '.';

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
        toastError(
            "PTR-Eintrag konnte nicht erzeugt werden – ungültige IP-Adresse oder Formatfehler.",
            "Fehler bei PTR-Berechnung für {$type} {$ip} → Zone-ID {$zone_id}, Name '{$name}'"
        );
        return false;
    }

    // Reverse-Zone muss existieren
    $stmt = $pdo->prepare("SELECT id FROM zones WHERE name = ? AND type = 'reverse'");
    $stmt->execute([$ptr_zone]);
    $ptr_zone_id = $stmt->fetchColumn();

    if (!$ptr_zone_id) {
        toastError(
            "PTR-Eintrag nicht möglich – passende Reverse-Zone <code>{$ptr_zone}</code> ist nicht vorhanden.",
            "PTR-Zuordnung fehlgeschlagen: keine Reverse-Zone '{$ptr_zone}' für IP {$ip}"
        );
        return false;
    }

    // Prüfen auf Duplikat
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM records WHERE zone_id = ? AND name = ? AND type = 'PTR'");
    $stmt->execute([$ptr_zone_id, $ptr_name]);
    if ((int)$stmt->fetchColumn() > 0) {
        toastError(
            "PTR-Eintrag nicht möglich – in der Zone <code>{$ptr_zone}</code> existiert bereits ein PTR für <code>{$ptr_name}</code>.",
            "PTR-Duplikat erkannt: PTR {$ptr_name}.{$ptr_zone} existiert bereits"
        );
        return false;
    }

    // Einfügen
    try {
        $stmt = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (?, ?, 'PTR', ?, ?)");
        $stmt->execute([$ptr_zone_id, $ptr_name, $hostname, $ttl]);

        $pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
        rebuild_zone_and_flag_if_valid((int)$ptr_zone_id);

        return true;
    } catch (PDOException $e) {
        toastError(
            "Fehler beim automatischen PTR-Eintrag.",
            "Datenbankfehler beim Einfügen von PTR {$ptr_name}.{$ptr_zone}: " . $e->getMessage()
        );
        return false;
    }
}

// Nur POST-Anfragen zulassen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireRole(['admin', 'zoneadmin']);

    $zone_id = (int)($_POST['zone_id'] ?? 0);
    $ptr_created = false;

    // Zonenberechtigung prüfen
    if ($_SESSION['role'] !== 'admin') {
        $stmt = $pdo->prepare("SELECT 1 FROM user_zones WHERE zone_id = ? AND user_id = ?");
        $stmt->execute([$zone_id, $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            logAccessDenied('Benutzer-ID ' . $_SESSION['user_id'] . ' versucht unbefugten Zugriff auf Zone-ID ' . $zone_id);
            http_response_code(403);
            exit('Zugriff verweigert.');
        }
    }

    // Zonennamen der Forward-Zone für die Toast-Ausgabe
    $stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    $zone_name = rtrim($stmt->fetchColumn(), '.');

    $raw_type = $_POST['type'] ?? '';
    $is_dkim = !empty($_POST['is_dkim']); // z. B. aus hidden input

    // Sonderfall: Wenn DKIM als TXT gespeichert wird, aber speziell validiert werden muss
    $validator_type = ($raw_type === 'TXT' && $is_dkim) ? 'DKIM' : $raw_type;

    // Überprüfen, ob eine DKIM-Datei hochgeladen wurde
    if (isset($_FILES['dkim_file']) && $_FILES['dkim_file']['error'] === UPLOAD_ERR_OK) {
        // Maximale Dateigröße auf 2 KB festlegen
        $maxFileSize = 2048; // 2 KB

        // Überprüfen, ob die Datei nicht größer als die maximal erlaubte Größe ist
        if ($_FILES['dkim_file']['size'] > $maxFileSize) {
        toastError(
            "Die Datei ist zu groß. Die maximale Dateigröße beträgt 2 KB.",
            "DKIM-Upload abgebrochen: Datei überschreitet 2 KB Limit."
        );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }

        // Überprüfen der Dateiendung (nur .txt-Dateien erlauben)
        $fileInfo = pathinfo($_FILES['dkim_file']['name']);
        $fileExtension = strtolower($fileInfo['extension']);
        if ($fileExtension !== 'txt') {
            toastError(
                "Nur .txt-Dateien sind erlaubt.",
                "DKIM-Upload abgelehnt: Dateiendung '{$fileExtension}' ist nicht erlaubt."
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }

        // Pfad zur temporären Datei
        $filePath = $_FILES['dkim_file']['tmp_name'];

        // Dateiinhalt überprüfen (keine schädlichen Tags oder PHP-Code)
        $fileContent = file_get_contents($filePath);
        if (preg_match('/<\?php|<script|<\/script>/', $fileContent)) {
            toastError(
                "Die Datei enthält unerlaubten Code und konnte nicht verarbeitet werden.",
                "DKIM-Datei abgewiesen: Enthält verbotene Zeichenfolgen."
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }

        // DKIM-Datei verarbeiten
        $dkimData = parseDKIMFile($filePath);

        if ($dkimData) {
            // DKIM-Daten extrahiert, an das Formular übergeben
            $_POST['dkim_selector'] = $dkimData['selector'];
            $_POST['dkim_key'] = $dkimData['key'];
        } else {
            toastError(
                "Die DKIM-Datei konnte nicht verarbeitet werden.",
                "Fehler beim Parsen der hochgeladenen DKIM-Datei."
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
            exit;
        }
    }

    // TTL auf Basis des Record-Typs setzen, wenn "auto" gewählt wurde
    if (isset($_POST['ttl']) && $_POST['ttl'] === 'auto') {
        require_once __DIR__ . '/../inc/ttl_defaults.php';
        $_POST['ttl'] = getAutoTTL($raw_type);
    }

    // Content zusammensetzen (inkl. spezieller Typ-Logik)
    $result = buildRecordContent($raw_type, $_POST);

    if (isset($result['error'])) {
        toastError(
            $result['error'],
            "Fehler beim Verarbeiten des Record-Typs '{$raw_type}': {$result['error']}"
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
        exit;
    }

    $type    = $result['type'];
    $name    = $result['name'];
    $content = $result['content'];
    $ttl     = intval($_POST['ttl']) ?: 3600;

    // Validierung
    $errors = validateDnsRecord($validator_type, $name, $content, null, $ttl);
    if (!empty($errors)) {
        foreach ($errors as $error) {
            toastError(
                $error,
                "Fehler bei der Validierung von Record '{$type} {$name}': {$error}"
            );
        }
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=" . urlencode($zone_id));
        exit;
    }

    // Auf Duplikate prüfen
    if (isDuplicateRecord($pdo, $zone_id, $name, $type, $content)) {
        toastError(
            "Ein identischer $type-Eintrag für <code>" . htmlspecialchars($name) . "</code> existiert bereits.",
            "Record-Duplikat erkannt: {$type} {$name} bereits vorhanden in Zone {$zone_name} (ID {$zone_id})"
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
        exit;
    }

    // Transaktion starten
    $pdo->beginTransaction();

    try {
        // Record schreiben
        $stmt = $pdo->prepare("INSERT INTO records (zone_id, name, type, content, ttl) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$zone_id, $name, $type, $content, $ttl]);

        // Zonendatei testen
        $rebuild = rebuild_zone_and_flag_if_valid($zone_id);

        if ($rebuild['status'] === 'error') {
            $pdo->rollBack();
            toastError(
                "Der Record konnte nicht gespeichert werden, da die Zonendatei ungültig wäre.",
                $rebuild['output']
            );
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
            exit;
        }

        if ($rebuild['status'] === 'warning') {
            toastWarning(
                "Record gespeichert – Warnung beim Zonendatei-Check.",
                $rebuild['output']
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        toastError(
            "Beim Speichern des Records ist ein Fehler aufgetreten.",
            "Datenbankfehler beim Speichern von {$type} {$name} in Zone-ID {$zone_id}: " . $e->getMessage()
        );
        header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
        exit;
    }

    // Automatischen PTR erzeugen (optional)
    if (($type === 'A' || $type === 'AAAA') && ($_POST['auto_ptr'] ?? '') === 'on') {
        if (!tryAutoPtr($pdo, $zone_id, $type, $content, $name, $ttl)) {
            header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
            exit;
        }
    }

    if (empty($_SESSION['toast_errors'])) {
        toastSuccess(
            "Record <strong>" . htmlspecialchars($type) . " {$name}</strong> erfolgreich in <strong>" . htmlspecialchars($zone_name) . "</strong> hinzugefügt.",
            "Neuer DNS-Record {$type} {$name} erfolgreich gespeichert in Zone '{$zone_name}' (ID {$zone_id})"
        );
    }

    header("Location: " . rtrim(BASE_URL, '/') . "/pages/records.php?zone_id=$zone_id");
    exit;
}
?>
