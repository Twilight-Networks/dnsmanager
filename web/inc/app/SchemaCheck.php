<?php
/**
 * Datei: inc/app/SchemaCheck.php
 * Zweck: Prüfung, ob das Datenbankschema die vom Code vorausgesetzten Spalten in der erwarteten Form
 *        enthält (Fail-Closed vor Anmeldung und Session-Revalidierung).
 *
 * Hintergrund (S26/S27): Der Code setzt users.active und users.session_epoch voraus. Die Migration
 * (sql/migrations/001_users_active_session_epoch.sql bzw. scripts/migrate_001_users_active_session_epoch.sh)
 * muss VOR dem Code ausgerollt werden. Fehlt sie oder weicht die Spaltenstruktur ab, verweigern Login und
 * Revalidierung den Dienst, statt mit unvollständigen Sicherheitsdaten zu arbeiten.
 *
 * Es werden niemals Schemaänderungen vorgenommen (keine automatische Migration beim Seitenaufruf).
 * Das Ergebnis wird je Prozess zwischengespeichert (eine Abfrage auf information_schema je Anfrage).
 */

final class SchemaCheck
{
    /**
     * Erwartete Spalten: Tabelle => Spalte => [COLUMN_TYPE (klein), IS_NULLABLE, COLUMN_DEFAULT]
     */
    public const REQUIRED = [
        'users' => [
            'active'        => ['tinyint(1)', 'NO', '1'],
            'session_epoch' => ['bigint(20) unsigned', 'NO', '0'],
        ],
    ];

    /** @var array<string, string[]> Cache: DB-Name => Abweichungen */
    private static array $cache = [];

    /**
     * Abweichungen vom erwarteten Schema (leer = in Ordnung).
     *
     * @return string[] z. B. ["users.active fehlt", "users.session_epoch: Typ int(11) statt bigint(20) unsigned"]
     *
     * @throws PDOException bei Datenbankfehlern (Aufrufer entscheidet über Fail-Closed)
     */
    public static function problems(PDO $pdo): array
    {
        $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (isset(self::$cache[$db])) {
            return self::$cache[$db];
        }

        $problems = [];
        $stmt = $pdo->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        foreach (self::REQUIRED as $table => $columns) {
            foreach ($columns as $column => [$type, $nullable, $default]) {
                $stmt->execute([$table, $column]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                if ($row === false) {
                    $problems[] = "$table.$column fehlt";
                    continue;
                }
                // MariaDB liefert Standardwerte numerischer Spalten ohne Anführungszeichen ("1"), ältere
                // Versionen ggf. mit ("'1'"); beides ist gleichwertig.
                $actualDefault = $row['COLUMN_DEFAULT'] === null ? null : trim((string)$row['COLUMN_DEFAULT'], "'");
                if (strtolower((string)$row['COLUMN_TYPE']) !== $type) {
                    $problems[] = "$table.$column: Typ {$row['COLUMN_TYPE']} statt $type";
                }
                if ((string)$row['IS_NULLABLE'] !== $nullable) {
                    $problems[] = "$table.$column: NULL zulässig";
                }
                if ($actualDefault !== $default) {
                    $problems[] = "$table.$column: Standardwert " . var_export($actualDefault, true) . " statt $default";
                }
            }
        }

        return self::$cache[$db] = $problems;
    }
}
