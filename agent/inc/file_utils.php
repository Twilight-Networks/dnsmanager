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
 * Listet <dir>/*.conf wie glob() (gleiche Einträge und Reihenfolge wie bisher), erkennt aber Lesefehler (Befund S43).
 *
 * PHP 8.5 glob() liefert bei einem nicht lesbaren Verzeichnis auch mit GLOB_ERR eine leere Liste statt false (auf ns2
 * nachgewiesen). Deshalb wird das Verzeichnis zusätzlich mit scandir() gelesen (false bei Fehler) und die Menge der
 * *.conf-Namen (ohne Punktdateien, wie das glob-Muster) mit dem glob-Ergebnis abgeglichen. Jede Abweichung gilt als
 * Lesefehler. Aufruf unter dem Agent-flock (keine gleichzeitigen Änderungen durch andere Endpunkte).
 *
 * @param string $dir Verzeichnis
 *
 * @return string[]|false Pfade wie glob("$dir/*.conf") oder false bei Lesefehler
 */
function listConfFiles(string $dir): array|false {
    $names = @scandir($dir);
    if ($names === false) {
        return false;
    }
    $glob = glob(rtrim($dir, '/') . '/*.conf');
    if ($glob === false) {
        return false;
    }
    $expected = array_values(array_filter($names, static fn (string $n): bool => $n !== '' && $n[0] !== '.' && str_ends_with($n, '.conf')));
    $got = array_map('basename', $glob);
    sort($expected, SORT_STRING);
    sort($got, SORT_STRING);

    return $expected === $got ? $glob : false;
}

/**
 * Existenz eines Pfads mit Unterscheidung von Prüffehlern (E99).
 *
 * file_exists()/is_file() liefern bei JEDEM stat-Fehler false, also "nicht vorhanden" (auf ns2 nachgewiesen: vorhandene
 * Datei, stat/access mit EIO ⇒ file_exists() = false). Für sicherheitsrelevante Entscheidungen (Fehlermarke, H,
 * Sicherung vor Änderungen, Entfernen verwaister Dateien) gilt deshalb nur ENOENT als "nicht vorhanden"; jeder andere
 * Fehler ist "nicht prüfbar" und wird vom Aufrufer fail-closed behandelt. Symlinks (auch defekte) gelten als vorhanden.
 *
 * Ohne die PHP-Erweiterung posix (auf ns1/ns2 vorhanden, Ubuntu php-common) fällt die Funktion auf file_exists() zurück;
 * Prüffehler sind dann nicht unterscheidbar (dokumentierte Grenze).
 *
 * @param string $path Pfad
 *
 * @return string 'present' | 'absent' | 'error'
 */
function pathState(string $path): string {
    clearstatcache(true, $path);
    if (is_link($path)) {
        return 'present';
    }
    if (!function_exists('posix_access')) {
        return file_exists($path) ? 'present' : 'absent';
    }
    if (@posix_access($path, POSIX_F_OK)) {
        return 'present';
    }

    return posix_get_last_error() === 2 ? 'absent' : 'error';   // 2 = ENOENT
}

/**
 * Liest eine Datei nur dann, wenn sie nachweislich vollständig gelesen wurde (S43-Ergänzung, E98).
 *
 * file_get_contents() liefert bei einem read()-Fehler eine leere bzw. verkürzte Zeichenkette statt false (unter
 * PHP 8.5 nachgewiesen). Deshalb wird die gelesene Länge mit der Größe aus fstat() desselben Dateideskriptors
 * verglichen; jede Abweichung gilt als Lesefehler.
 *
 * @param string $path Pfad der Datei
 *
 * @return string|false vollständiger Inhalt oder false
 */
function readFileComplete(string $path): string|false {
    $fh = @fopen($path, 'r');
    if ($fh === false) {
        return false;
    }
    $st = @fstat($fh);
    $data = $st === false ? false : @stream_get_contents($fh);
    @fclose($fh);
    if (!is_string($data) || $st === false || strlen($data) !== (int)$st['size']) {
        return false;
    }

    return $data;
}

/**
 * Pfade aller include-Zeilen einer vom Agenten erzeugten zones.conf (Format: include "<pfad>";).
 *
 * @param string $content Inhalt der zones.conf
 *
 * @return string[] eingebundene Pfade (ohne Duplikate)
 */
function zonesConfIncludes(string $content): array {
    preg_match_all('/^include "([^"\r\n]*)";\r?$/m', $content, $m);

    return array_values(array_unique($m[1]));
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
