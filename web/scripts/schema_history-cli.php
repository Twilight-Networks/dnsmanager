#!/usr/bin/php
<?php
/**
 * Datei: schema_history-cli.php
 * Zweck: Hilfswerkzeug des Migrationsskripts scripts/migrate_002_history_foundation.sh (AP1/H2a, E26).
 *
 * Stellt die Strukturerwartung der History-Grundtabellen aus SchemaCheck für das Skript bereit, damit Preflight,
 * Nachprüfung und die fail-closed Login-Prüfung dieselbe und nur eine Definition verwenden.
 *
 * Aufruf (ohne Datenbankzugriff, ohne Konfiguration):
 *   php schema_history-cli.php sql        Signaturabfrage ausgeben (für mariadb -N -B <db>)
 *   php schema_history-cli.php evaluate   Ausgabe von mariadb -N -B (Tabelle<TAB>Zeile) von STDIN bewerten;
 *                                         je Tabelle eine Zeile "Tabelle<TAB>ok|absent|mismatch<TAB>Detail"
 *
 * Exitcodes: 0 = ausgeführt, 2 = Aufruf- oder Eingabefehler.
 * Nur CLI, nicht über Web aufrufbar (cliGuard).
 */

require_once __DIR__ . '/../inc/helpers_cli.php';
cliGuard();
require_once __DIR__ . '/../inc/app/SchemaCheck.php';

$mode = $argv[1] ?? '';

if ($mode === 'sql') {
    echo SchemaCheck::historySignatureSql(), "\n";
    exit(0);
}

if ($mode === 'evaluate') {
    $rows = [];
    $input = stream_get_contents(STDIN);
    if ($input === false) {
        fwrite(STDERR, "Eingabe nicht lesbar\n");
        exit(2);
    }
    foreach (explode("\n", $input) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) !== 2) {
            fwrite(STDERR, "Ungültige Eingabezeile\n");
            exit(2);
        }
        // mariadb -B maskiert \, Tabulator, Zeilenumbruch und NUL
        $unescape = static fn (string $v): string => strtr($v, ['\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\0' => "\0"]);
        $rows[] = [$unescape($parts[0]), $unescape($parts[1])];
    }
    foreach (SchemaCheck::evaluateHistory($rows) as $table => $result) {
        echo $table, "\t", $result['state'], "\t", str_replace(["\t", "\n"], ' ', $result['detail']), "\n";
    }
    exit(0);
}

fwrite(STDERR, "Aufruf: php schema_history-cli.php sql|evaluate\n");
exit(2);
