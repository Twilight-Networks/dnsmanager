<?php
/**
 * Datei: tests/ui/records/u4ui_zonefiles.php
 * Zweck: Erzeugt mit generate_zone_file() der angegebenen Anwendungskopie die Zonendateien aller Zonen der
 *        Testdatenbank (Modus publish, eigenes Verzeichnis) und gibt Inhalt und Status als JSON aus (E3-Vergleich in
 *        u4ui.php; je Anwendungskopie ein eigener Prozess, weil die Funktionsnamen gleich sind).
 * Aufruf: php u4ui_zonefiles.php <Anwendungskopie> <Datenbank>
 */
[$_, $app, $db] = $argv + [null, null, null];
require $app . '/web/inc/deploy/bind_file_generator.php';
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dir = sys_get_temp_dir() . '/dnsmgr-uig-e3-' . getmypid();
mkdir($dir, 0700);
$out = [];
foreach ($pdo->query('SELECT id, name FROM zones ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $z) {
    $r = generate_zone_file((int)$z['id'], $dir, 'publish', $pdo);
    $content = isset($r['path']) && is_file($r['path']) ? (string)file_get_contents($r['path']) : '';
    if (isset($r['path']) && is_file($r['path'])) { unlink($r['path']); }
    $out[$z['name']] = ['status' => $r['status'] ?? '?', 'content' => $content];
}
rmdir($dir);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
