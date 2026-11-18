<?php
/**
 * Datei: tests/history/lib.php
 * Zweck: Minimale Prüfhilfen der History-Tests (AP1/H2a). Kein Framework; Ausgabe je Fall-ID, Exitcode 1 bei Fehler.
 */

final class T
{
    private static int $pass = 0;
    private static int $fail = 0;

    public static function ok(bool $cond, string $id, string $msg): void
    {
        if ($cond) {
            self::$pass++;
            echo "  ok   $id $msg\n";
        } else {
            self::$fail++;
            echo "  FAIL $id $msg\n";
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $id, string $msg): void
    {
        $ok = $expected === $actual;
        self::ok($ok, $id, $msg);
        if (!$ok) {
            echo '       erwartet: ' . self::show($expected) . "\n       erhalten: " . self::show($actual) . "\n";
        }
    }

    /** Erwartet eine Ausnahme der Klasse $class */
    public static function throws(string $class, callable $fn, string $id, string $msg): void
    {
        try {
            $fn();
            self::ok(false, $id, "$msg (keine Ausnahme)");
        } catch (Throwable $e) {
            self::ok($e instanceof $class, $id, $msg . ($e instanceof $class ? '' : ' (' . get_class($e) . ': ' . $e->getMessage() . ')'));
        }
    }

    public static function section(string $title): void
    {
        echo "== $title\n";
    }

    public static function finish(string $suite): never
    {
        echo "ERGEBNIS $suite: " . self::$pass . ' bestanden, ' . self::$fail . " fehlgeschlagen\n";
        exit(self::$fail === 0 ? 0 : 1);
    }

    private static function show(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: var_export($v, true);
    }
}
