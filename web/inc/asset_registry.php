<?php
/**
 * Klasse: AssetRegistry
 * Zweck: Kapselt und verwaltet eingebundene JS- und CSS-Dateien
 */

class AssetRegistry {
    private static array $scripts = [];
    private static array $styles = [];

    public static function enqueueScript(string $filename): void {
        self::$scripts[$filename] = true;
    }

    public static function enqueueStyle(string $filename): void {
        self::$styles[$filename] = true;
    }

    public static function getScripts(): array {
        return array_keys(self::$scripts);
    }

    public static function getStyles(): array {
        return array_keys(self::$styles);
    }
}
