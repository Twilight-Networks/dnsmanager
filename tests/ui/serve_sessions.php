<?php
/**
 * Datei: tests/ui/serve_sessions.php
 * Zweck: Screenshot-Modus der UI-Golden-Basis (U0): setzt die Testdatenbank auf die Fixture zurück und meldet admin
 *        und zoneadmin regulär über login.php an. Schreibt die Session-IDs nach /tmp/dnsmgr-uig/sid_<rolle> (0600).
 */
require __DIR__ . '/lib.php';
resetDb();
foreach (['admin', 'zoneadmin'] as $role) {
    $c = session($role);
    $f = T . "/sid_$role";
    file_put_contents($f, $c['twl_dnsmgr_sid'] ?? '');
    chmod($f, 0600);
    echo "Session $role angelegt\n";
}
