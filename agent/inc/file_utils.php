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

/**
 * Ersetzt eine Textdatei atomar: vollständiger Inhalt in eine eindeutige Temp-Datei im selben
 * Verzeichnis (gleiches Dateisystem), danach rename() auf den Zielpfad.
 *
 * Rechte: $mode, falls angegeben; sonst best effort die Rechte der bisherigen Zieldatei.
 * Owner/Gruppe sind die des schreibenden Prozesses.
 * Bei Fehlern bleibt die Zieldatei unverändert und die Temp-Datei wird entfernt.
 *
 * @param string   $path    Absoluter Pfad zur Zieldatei
 * @param string   $content Vollständiger neuer Inhalt
 * @param int|null $mode    Optionale Zugriffsrechte (z. B. 0644)
 *
 * @return bool true bei Erfolg, false bei Fehler
 */
function writeTextFileAtomic(string $path, string $content, ?int $mode = null): bool {
    try {
        $tmpPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    } catch (Throwable $e) {
        return false;
    }

    if (file_put_contents($tmpPath, $content) !== strlen($content)) {
        @unlink($tmpPath);
        return false;
    }

    if ($mode === null && is_file($path)) {
        $existingPerms = @fileperms($path);
        if ($existingPerms !== false) {
            $mode = $existingPerms & 0777;
        }
    }
    if ($mode !== null) {
        @chmod($tmpPath, $mode);
    }

    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}
