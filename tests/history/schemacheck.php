<?php
/**
 * Datei: tests/history/schemacheck.php
 * Zweck: SchemaCheck::problems() und die Strukturbewertung für eine isolierte Testdatenbank ausgeben
 *        (Gegenprobe zum Migrationsskript, AP1/H2a).
 *
 * Aufruf (als root über den MariaDB-Socket): php schemacheck.php <App-Verzeichnis> <Datenbank>
 * Ausgabe: je History-Tabelle "Tabelle<TAB>Zustand", danach je Befund "problem<TAB>Text".
 */

[$app, $db] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!preg_match('/^dnsmgr_t21_[a-z0-9_]+$/D', $db) || !is_file("$app/web/inc/app/SchemaCheck.php")) {
    fwrite(STDERR, "Aufruf: php schemacheck.php <App-Verzeichnis> <dnsmgr_t21_*>\n");
    exit(2);
}
require_once "$app/web/inc/app/SchemaCheck.php";

$pdo = new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$db;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (SchemaCheck::evaluateHistory(SchemaCheck::historySignatureRows($pdo)) as $table => $r) {
    echo "$table\t{$r['state']}\n";
}
foreach (SchemaCheck::problems($pdo) as $p) {
    echo "problem\t$p\n";
}
