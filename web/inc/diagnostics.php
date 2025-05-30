<?php
/**
 * Datei: diagnostics.php
 * Zweck: Sammlung von Funktionen zur Überprüfung des Systemstatus (Dateiberechtigungen, PHP-Module, BIND-Dienste).
 *
 * Details:
 * - Prüfung von Dateibesitzern und Dateirechten innerhalb des Webverzeichnisses.
 * - Überwachung der benötigten PHP-Module.
 * - Überprüfung des laufenden Status des BIND-DNS-Servers (named).
 * - Validierung der BIND-Konfigurationsdateien und Zonen.
 *
 * Sicherheit:
 * - Keine direkten Benutzerinteraktionen oder Sitzungsabhängigkeiten.
 * - Zugriff erfolgt ausschließlich über interne Aufrufe.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Prüft, ob kritische Konfigurationswerte gültig gesetzt sind.
 *
 * @return array Liste der gefundenen Probleme (leeres Array bei keiner Abweichung).
 */
function check_config_validity(): array
{
    $issues = [];

    // BASE_URL prüfen (muss definiert sein)
    if (!defined('BASE_URL')) {
        $issues[] = 'BASE_URL ist nicht definiert.';
    }

    // NAMED_CHECKZONE prüfen
    if (!defined('NAMED_CHECKZONE') || !is_executable(NAMED_CHECKZONE)) {
        $issues[] = 'NAMED_CHECKZONE ist nicht vorhanden oder nicht ausführbar.';
    }

    // PASSWORD_MIN_LENGTH prüfen
    if (!defined('PASSWORD_MIN_LENGTH') || PASSWORD_MIN_LENGTH < 4) {
        $issues[] = 'PASSWORD_MIN_LENGTH ist zu niedrig oder nicht gesetzt.';
    }

    // PHP_ERR_REPORT prüfen
    if (!defined('PHP_ERR_REPORT') || PHP_ERR_REPORT == true) {
        $issues[] = 'PHP_ERR_REPORT steht auf true (Entwicklungsmodus aktiv).';
    }

        // LOG_LEVEL prüfen
    $valid_levels = ['debug', 'info', 'warning', 'error'];
    if (!defined('LOG_LEVEL') || !in_array(LOG_LEVEL, $valid_levels, true)) {
        $issues[] = 'Ungültiger LOG_LEVEL: ' . (defined('LOG_LEVEL') ? LOG_LEVEL : 'nicht definiert');
    }

    // LOG_TARGET prüfen
    $valid_targets = ['apache', 'syslog'];
    if (!defined('LOG_TARGET') || !in_array(strtolower(LOG_TARGET), $valid_targets, true)) {
        $issues[] = 'Ungültiger LOG_TARGET: ' . (defined('LOG_TARGET') ? LOG_TARGET : 'nicht definiert');
    }

    return $issues;
}

/**
 * Überprüft alle Dateien und Verzeichnisse unterhalb eines Basisverzeichnisses auf Eigentümer und Berechtigungen.
 *
 * @param string $base_dir Basisverzeichnis, das geprüft werden soll.
 * @return array Liste der gefundenen Abweichungen.
 */
function check_all_file_permissions($base_dir) {
    $expected_owner = EXPECTED_OWNER;
    $expected_group = EXPECTED_GROUP;

    // Sonderregeln für spezifische Dateien
    $expected_file_perms = [
        '/config/ui_config.php' => [
            'owner' => 'root',
            'group' => WEBSERVER_GROUP,
            'perms' => '0640'
        ],
    ];

    $default_dir_perms = '0755';
    $default_file_perms = '0644';

    $issues = [];

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($rii as $file) {
        $path = str_replace($base_dir, '', $file->getPathname());

        $stat = @stat($file->getPathname());
        if ($stat === false) {
            $issues[] = [
                'path' => $path,
                'actual' => 'Fehler beim Lesen',
                'expected' => 'k.A.'
            ];
            continue;
        }

        $owner = posix_getpwuid($stat['uid'])['name'] ?? '';
        $group = posix_getgrgid($stat['gid'])['name'] ?? '';
        $perms = substr(sprintf('%o', $stat['mode']), -4);

        // Spezifische Datei oder Standardregel
        $expected = $expected_file_perms[$path] ?? [
            'owner' => $expected_owner,
            'group' => $expected_group,
            'perms' => $file->isDir() ? $default_dir_perms : $default_file_perms
        ];

        if ($owner !== $expected['owner'] || $group !== $expected['group'] || $perms !== $expected['perms']) {
            $issues[] = [
                'path' => $path,
                'actual' => "Owner: $owner, Group: $group, Perms: $perms",
                'expected' => $expected
            ];
        }
    }

    return $issues;
}

/**
 * Gibt die aktuell installierte PHP-Version zurück.
 *
 * @return string PHP-Versionsnummer (z.B. 8.1.10)
 */
function get_php_version() {
    return phpversion();
}

/**
 * Prüft, ob alle für den DNS-Manager notwendigen PHP-Module installiert sind.
 *
 * @return array Assoziatives Array Modulname => Status (true/false).
 */
function check_required_php_modules() {
    $required_modules = [
        'pdo' => 'PDO',
        'pdo_mysql' => 'PDO MySQL',
        'openssl' => 'OpenSSL',
        'filter' => 'Filter',
        'mbstring' => 'Multibyte String',
        'session' => 'Session',
        'json' => 'JSON',
        'ctype' => 'CType'
    ];

    $results = [];

    foreach ($required_modules as $module => $label) {
        $results[$label] = extension_loaded($module);
    }

    return $results;
}

/**
 * Lädt die aktuellen Diagnosedaten aus der Datenbank, gruppiert nach Typ (Server / Zone).
 *
 * Details:
 * - Holt alle Einträge aus der `diagnostics`-Tabelle, inklusive zugehöriger Zonen- und Servernamen.
 * - Verwendet LEFT JOIN, um sowohl Zone- als auch Servernamen bei Bedarf einzubinden.
 * - Gruppiert die Ergebnisse nach `target_type`:
 *   - `server`: verschachtelt nach `target_id` und `check_type`, enthält Status, Nachricht und Servername.
 *   - `zone`: als flaches Array mit Zone, Server und Diagnosedaten pro `check_type`.
 *
 * Rückgabestruktur:
 * [
 *   'server' => [
 *       [target_id] => [
 *           [check_type] => [
 *               'status'  => 'ok'|'warning'|'error',
 *               'message' => '...',
 *               'server'  => 'Servername'
 *           ],
 *           ...
 *       ],
 *       ...
 *   ],
 *   'zone' => [
 *       [
 *           'zone'   => 'Zonenname',
 *           'server' => 'Servername',
 *           [check_type] => [
 *               'status'  => 'ok'|'warning'|'error',
 *               'message' => '...'
 *           ]
 *       ],
 *       ...
 *   ]
 * ]
 *
 * @param PDO $pdo Aktive PDO-Verbindung zur Datenbank
 * @return array Gruppierte Diagnoseergebnisse nach Typ
 */
function getDiagnosticResults(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT
            d.*,
            z.name AS zone_name,
            s.name AS server_name
        FROM diagnostics d
        LEFT JOIN zones z ON d.target_type = 'zone' AND d.target_id = z.id
        LEFT JOIN servers s ON d.server_id = s.id
    ");
    $stmt->execute();
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $by_type = [
        'server' => [],
        'zone' => []
    ];

    foreach ($all as $row) {
        if ($row['target_type'] === 'server') {
            $by_type['server'][$row['target_id']][$row['check_type']] = [
                'status' => $row['status'],
                'message' => $row['message'],
                'server' => $row['server_name'] ?? null,
            ];
        } elseif ($row['target_type'] === 'zone') {
            $compound_key = $row['target_id'] . '_' . $row['server_id'];

            $by_type['zone'][] = [
                'zone' => $row['zone_name'] ?? '(unbekannt)',
                'server' => $row['server_name'] ?? '(unbekannt)',
                $row['check_type'] => [
                    'status' => $row['status'],
                    'message' => $row['message']
                ]
            ];
        }
    }

    return $by_type;
}
?>
