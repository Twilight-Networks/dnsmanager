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
 * - zone_cleanup.php
 * - system/control.php (nur agentLockPath)
 * - fence.php (AS1: writeFileDurable, syncDirectory)
 */

/**
 * Pfad der gemeinsamen Agent-Sperrdatei (S34b, SP4-E1).
 *
 * zone_sync, conf_sync, zone_cleanup und system/control serialisieren Änderungen an verwalteten BIND-Dateien und
 * den BIND-Reload über ein exklusives flock() auf diese Datei. Sie liegt im agenteneigenen Zonenverzeichnis
 * (zone_data_dir, Eigentümer des Agenten, nicht allgemein beschreibbar) statt unter einem festen /tmp-Pfad, der
 * von anderen lokalen Benutzern vorab angelegt werden könnte. Als Punktdatei wird sie weder von BIND noch von
 * zone_cleanup (entfernt nur gepaarte db.<zone>-Dateien verwaister .conf-Dateien) berührt.
 *
 * @param array $config Agent-Konfiguration (api_config.php)
 *
 * @return string Absoluter Pfad der Sperrdatei
 */
function agentLockPath(array $config): string {
    return rtrim((string)$config['zone_data_dir'], '/') . '/.dnsmanager-agent.lock';
}

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
 * AS1: Die Temp-Datei wird vor dem rename() mit fsync() synchronisiert. Das Verzeichnis synchronisiert der
 * Aufrufer an seinem Übernahmepunkt (syncDirectories), weil false hier stets "Ziel unverändert" bedeutet.
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

    if ($mode === null && is_file($path)) {
        $existingPerms = @fileperms($path);
        if ($existingPerms !== false) {
            $mode = $existingPerms & 0777;
        }
    }

    // AS1: Inhalt (und Rechte) vor dem rename() dauerhaft schreiben (fsync der Temp-Datei)
    if (!writeFileDurable($tmpPath, $content, $mode)) {
        @unlink($tmpPath);
        return false;
    }

    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}

/**
 * Legt eine neue Datei exklusiv an (O_CREAT|O_EXCL), schreibt den vollständigen Inhalt und synchronisiert sie mit
 * fsync() (AS1). Die Datei darf noch nicht existieren. Rechte: $mode best effort (wie writeTextFileAtomic), vor dem
 * fsync gesetzt.
 *
 * Bei false ist der Zustand der Datei unbestimmt (ggf. angelegt, unvollständig oder nicht dauerhaft); der Aufrufer
 * entfernt sie bzw. behandelt den Fehler fail-closed.
 *
 * @param string   $path    Absoluter Pfad der neu anzulegenden Datei
 * @param string   $content Vollständiger Inhalt
 * @param int|null $mode    Optionale Zugriffsrechte
 *
 * @return bool true nur, wenn Anlegen, vollständiges Schreiben, fsync und Schließen gelungen sind
 */
function writeFileDurable(string $path, string $content, ?int $mode = null): bool {
    $fh = @fopen($path, 'x');
    if ($fh === false) {
        return false;
    }
    $ok = true;
    $len = strlen($content);
    $off = 0;
    while ($off < $len) {
        $w = @fwrite($fh, substr($content, $off));
        if ($w === false || $w === 0) {
            $ok = false;
            break;
        }
        $off += $w;
    }
    if ($ok && $mode !== null) {
        @chmod($path, $mode);
    }
    if ($ok) {
        $ok = @fflush($fh) && @fsync($fh);
    }
    $closed = @fclose($fh);

    return $ok && $closed;
}

/**
 * Synchronisiert einen Verzeichniseintrag dauerhaft: open(O_RDONLY) auf das Verzeichnis und fsync() (AS1, AS0/A:
 * unter PHP 8.5 auf ext4 nachgewiesen; opendir()-Handles sind dafür ungeeignet, uutils-sync ist kein Ersatz).
 * Nötig nach rename() und unlink(), damit die Namensänderung einen Absturz übersteht.
 *
 * @param string $dir Absoluter Verzeichnispfad
 *
 * @return bool true bei Erfolg
 */
function syncDirectory(string $dir): bool {
    $dh = @fopen($dir, 'r');
    if ($dh === false) {
        return false;
    }
    $ok = @fsync($dh);
    @fclose($dh);

    return $ok;
}

/**
 * Synchronisiert mehrere Verzeichnisse (Duplikate einmal), bricht beim ersten Fehler ab.
 *
 * @param string[] $dirs
 *
 * @return bool true, wenn alle synchronisiert wurden
 */
function syncDirectories(array $dirs): bool {
    foreach (array_unique(array_map(static fn (string $d): string => rtrim($d, '/') === '' ? '/' : rtrim($d, '/'), $dirs)) as $dir) {
        if (!syncDirectory($dir)) {
            return false;
        }
    }

    return true;
}
