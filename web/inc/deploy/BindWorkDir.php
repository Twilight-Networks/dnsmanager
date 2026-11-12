<?php
/**
 * Datei: inc/deploy/BindWorkDir.php
 * Zweck: Eigenes temporäres Arbeitsverzeichnis für Zonen- und Konfigurationsdateien (Sicherheitsbefund S34).
 *
 * - Zufälliger, nicht vorhersagbarer Name unterhalb des System-Temp-Verzeichnisses mit eindeutigem Präfix
 *   (dnsmanager-work-<32 Hex>), angelegt mit mkdir 0700. mkdir schlägt fehl, wenn der Pfad bereits existiert
 *   (auch als Symlink) – ein vorab angelegtes Verzeichnis eines anderen Benutzers wird nie verwendet.
 * - remove() löscht ausschließlich den Inhalt dieses Verzeichnisses (rekursiv, ohne Symlinks zu folgen) und
 *   das Verzeichnis selbst; idempotent. Aufrufer rufen remove() in finally-Blöcken auf.
 * - Restdateien nach hartem Prozessabbruch (kein finally): create() entfernt vorher eigene Arbeitsverzeichnisse
 *   desselben Präfixes, die demselben Benutzer gehören und älter als STALE_SECONDS sind.
 */

final class BindWorkDir
{
    public const PREFIX = 'dnsmanager-work-';

    /** Arbeitsverzeichnisse älter als 24 h gelten als Restbestand eines abgebrochenen Laufs */
    public const STALE_SECONDS = 86400;

    private string $path;
    private bool $removed = false;

    private function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @param string|null $baseDir Basisverzeichnis (Standard: sys_get_temp_dir())
     * @throws RuntimeException wenn kein Arbeitsverzeichnis angelegt werden kann
     */
    public static function create(?string $baseDir = null): self
    {
        $base = rtrim($baseDir ?? sys_get_temp_dir(), '/');
        self::purgeStale($base);

        for ($i = 0; $i < 5; $i++) {
            $path = $base . '/' . self::PREFIX . bin2hex(random_bytes(16));
            if (@mkdir($path, 0700)) {
                @chmod($path, 0700); // unabhängig von der umask
                return new self($path);
            }
        }

        throw new RuntimeException('Temporäres Arbeitsverzeichnis konnte nicht angelegt werden.');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** true, wenn $file innerhalb dieses Arbeitsverzeichnisses liegt (rein lexikalisch) */
    public function contains(string $file): bool
    {
        return str_starts_with($file, $this->path . '/') && !str_contains(substr($file, strlen($this->path)), '/..');
    }

    /** Löscht das Arbeitsverzeichnis samt Inhalt (idempotent) */
    public function remove(): void
    {
        if ($this->removed) {
            return;
        }
        self::removeTree($this->path);
        $this->removed = true;
    }

    /**
     * Entfernt eigene, veraltete Arbeitsverzeichnisse (gleiches Präfix, gleicher Eigentümer, älter als
     * STALE_SECONDS). Fremde Verzeichnisse und Dateien anderer Programme werden nicht berührt.
     */
    public static function purgeStale(string $baseDir): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $limit = time() - self::STALE_SECONDS;

        foreach (glob(rtrim($baseDir, '/') . '/' . self::PREFIX . '*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            if (!preg_match('/\/' . preg_quote(self::PREFIX, '/') . '[0-9a-f]{32}\z/', $dir) || is_link($dir)) {
                continue;
            }
            $stat = @lstat($dir);
            if ($stat === false || $stat['uid'] !== $uid || $stat['mtime'] >= $limit) {
                continue;
            }
            self::removeTree($dir);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p) && !is_link($p)) {
                self::removeTree($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
