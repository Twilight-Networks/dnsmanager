<?php
/**
 * Datei: inc/app/status/SystemCheckService.php
 * Zweck: Systemprüfungen der Installation (bisher inc/diagnostics.php): Konfigurationswerte, PHP-Version,
 *        benötigte PHP-Module, Eigentümer und Rechte der Dateien unterhalb des Webverzeichnisses.
 *
 * Prüflogik unverändert. Ohne Sprachdateien und ohne Web-Bootstrap: Konfigurationsprobleme werden als
 * fachliche Codes (mit Rohwert) gemeldet; die Texte liefert der Aufrufer (Web: inc/web/system_check_messages.php).
 * Liest die bestehenden Konfigurationskonstanten (BASE_URL, NAMED_CHECKZONE, PASSWORD_MIN_LENGTH,
 * PHP_ERR_REPORT, LOG_LEVEL, LOG_TARGET, EXPECTED_OWNER, EXPECTED_GROUP, WEBSERVER_GROUP).
 */

final class SystemCheckService
{
    public const BASE_URL_MISSING        = 'BASE_URL_MISSING';
    public const NAMED_CHECKZONE_MISSING = 'NAMED_CHECKZONE_MISSING';
    public const PASSWORD_MIN_INVALID    = 'PASSWORD_MIN_INVALID';
    public const PHP_DEV_MODE            = 'PHP_DEV_MODE';
    public const LOG_LEVEL_INVALID       = 'LOG_LEVEL_INVALID';
    public const LOG_TARGET_INVALID      = 'LOG_TARGET_INVALID';

    /** Mindestversion für den Hinweis "PHP-Version unterstützt" */
    public const MIN_PHP_VERSION = '8.1';

    /**
     * Konfigurationsprobleme (bisher check_config_validity()).
     *
     * @return array<int, array{code: string, value: ?string}> value = aktueller Wert (nur LOG_LEVEL/LOG_TARGET;
     *                                                         null = nicht definiert)
     */
    public function configIssues(): array
    {
        $issues = [];

        if (!defined('BASE_URL')) {
            $issues[] = ['code' => self::BASE_URL_MISSING, 'value' => null];
        }
        if (!defined('NAMED_CHECKZONE') || !is_executable(NAMED_CHECKZONE)) {
            $issues[] = ['code' => self::NAMED_CHECKZONE_MISSING, 'value' => null];
        }
        if (!defined('PASSWORD_MIN_LENGTH') || PASSWORD_MIN_LENGTH < 4) {
            $issues[] = ['code' => self::PASSWORD_MIN_INVALID, 'value' => null];
        }
        if (!defined('PHP_ERR_REPORT') || PHP_ERR_REPORT == true) {
            $issues[] = ['code' => self::PHP_DEV_MODE, 'value' => null];
        }
        $valid_levels = ['debug', 'info', 'warning', 'error'];
        if (!defined('LOG_LEVEL') || !in_array(LOG_LEVEL, $valid_levels, true)) {
            $issues[] = ['code' => self::LOG_LEVEL_INVALID, 'value' => defined('LOG_LEVEL') ? (string)LOG_LEVEL : null];
        }
        $valid_targets = ['apache', 'syslog'];
        if (!defined('LOG_TARGET') || !in_array(strtolower(LOG_TARGET), $valid_targets, true)) {
            $issues[] = ['code' => self::LOG_TARGET_INVALID, 'value' => defined('LOG_TARGET') ? (string)LOG_TARGET : null];
        }

        return $issues;
    }

    /** Installierte PHP-Version (bisher get_php_version()) */
    public function phpVersion(): string
    {
        return (string)phpversion();
    }

    public function phpVersionSupported(): bool
    {
        return version_compare($this->phpVersion(), self::MIN_PHP_VERSION, '>=');
    }

    /**
     * Benötigte PHP-Module (bisher check_required_php_modules()).
     *
     * @return array<string, bool> Bezeichnung => geladen
     */
    public function requiredModules(): array
    {
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
     * Eigentümer und Rechte aller Dateien/Verzeichnisse unterhalb von $baseDir (bisher
     * check_all_file_permissions()).
     *
     * @return array<int, array{path: string, actual: string, expected: array|string}>
     */
    public function filePermissionIssues(string $baseDir): array
    {
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
            new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            $path = str_replace($baseDir, '', $file->getPathname());

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
}
