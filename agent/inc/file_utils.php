<?php
/**
 * Datei: inc/file_utils.php
 * Zweck: Hilfsfunktionen für Dateisystemoperationen im Rahmen der REST-API
 *
 * Diese Datei enthält generische Funktionen zum Erstellen von Verzeichnissen
 * und Schreiben von Dateien mit sauberer Fehlerbehandlung.
 *
 * Verwendet in:
 * - conf_sync.php
 * - zone_sync.php
 */

/**
 * Stellt sicher, dass ein Verzeichnis existiert.
 * Erstellt es bei Bedarf rekursiv mit Standardrechten (0755).
 *
 * @param string $path Absoluter Pfad zum Verzeichnis
 *
 * @return void
 */
function ensureDirectory(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

/**
 * Schreibt eine Textdatei mit gegebenem Inhalt.
 *
 * @param string $path    Absoluter Pfad zur Zieldatei
 * @param string $content Inhalt, der geschrieben werden soll
 *
 * @return bool true bei Erfolg, false bei Fehler
 */
function writeTextFile(string $path, string $content): bool {
    return file_put_contents($path, $content) !== false;
}
