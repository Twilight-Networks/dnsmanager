<?php
/**
 * Datei: helpers.php
 * Zweck: Sammlung von Hilfsfunktionen der Weboberfläche.
 *
 * Abgrenzung (Core-/UI-Trennung, docs/ARCHITECTURE_CORE.md):
 * - Enthält ausschließlich Web-Funktionen: requireRole (Rollenprüfung nach Session, 403), isActive
 *   (Navigation), csrf_input (Formularfeld), safe_return_path (Rücksprungziel).
 * - Die früheren Rebuild-Funktionen (rebuild_zone_and_validate, rebuild_zone_and_flag_if_valid,
 *   rebuild_ns_and_glue_for_zone_and_flag_if_valid) sind mit Paket 4a nach inc/deploy/BindZoneBuildBackend.php
 *   (ZoneBuildBackend) verlagert.
 * Fachlogik der Records liegt in inc/app/records (RecordService, RecordReadService, RecordContentBuilder,
 * RecordContentParser).
 */

/**
 * Prüft, ob der aktuell eingeloggte Benutzer eine der erlaubten Rollen besitzt.
 *
 * Bei fehlender oder unzureichender Rolle:
 * - Zugriff verweigern (HTTP 403)
 * - Ereignis protokollieren (Fail2Ban-kompatibles Format)
 *
 * @param array $allowedRoles Liste erlaubter Rollen (z.B. ['admin', 'zoneadmin']).
 *
 * @return void
 */
function requireRole(array $allowedRoles): void
{
    $role = $_SESSION['role'] ?? null;

    if ($role === null) {
        logAccessDenied('requireRole', 'Fehlende Benutzerrolle.');
        http_response_code(403);
        exit('Zugriff verweigert: Fehlende Benutzerrolle.');
    }

    if (!in_array($role, $allowedRoles, true)) {
        logAccessDenied('requireRole', 'Unzureichende Berechtigungen für Rolle: ' . $role);
        http_response_code(403);
        exit('Zugriff verweigert: Unzureichende Berechtigungen.');
    }
}

/**
 * Ermittelt, ob eine bestimmte Seite aktiv ist (z.B. für Navigation).
 *
 * @param string $page Dateiname der Seite (z.B. 'zones.php').
 *
 * @return string Gibt 'active' zurück, falls die Seite aktuell ist, ansonsten ein leerer String.
 */
function isActive(string $page): string
{
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

/**
 * Gibt ein CSRF-Token als <input>-Feld zurück.
 *
 * @return string HTML-Input-Feld mit gültigem CSRF-Token
 */
function csrf_input(): string
{
    $token = $_SESSION['csrf_token'] ?? '';
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Prüft ein Rücksprungziel und liefert es nur zurück, wenn es ein interner, root-relativer Pfad
 * zu einer vorhandenen Seite unter BASE_URL/pages/ ist; andernfalls wird $fallback geliefert.
 *
 * Zulässig ist ausschließlich: <Basispfad>/pages/<seitenname>.php[?<query>]
 * - Basispfad = Pfadanteil von BASE_URL (BASE_URL darf auch eine vollständige URL sein)
 * - seitenname: nur [a-z0-9_], die Datei muss in pages/ existieren
 * - query: nur URL-sichere Zeichen; Prozentkodierungen müssen gültig sein
 * - keine Schemas, Hosts, Backslashes, Steuer- oder Leerzeichen, kein "..", keine
 *   Prozentkodierung im Pfadanteil, keine Nicht-String-Werte, höchstens 2048 Zeichen
 *
 * Keine Datenbank- oder Session-Zugriffe.
 *
 * @param mixed  $candidate Ungeprüftes Rücksprungziel (z. B. aus $_POST)
 * @param string $fallback  Internes Standardziel bei ungültiger Eingabe
 * @return string Geprüftes Rücksprungziel oder $fallback
 */
function safe_return_path(mixed $candidate, string $fallback): string
{
    if (!is_string($candidate) || $candidate === '' || strlen($candidate) > 2048 || !defined('BASE_URL')) {
        return $fallback;
    }

    // Steuerzeichen, Leerzeichen, DEL und Backslashes ablehnen
    if (preg_match('/[\x00-\x20\x7F\\\\]/', $candidate)) {
        return $fallback;
    }

    // Basispfad aus BASE_URL (auch bei vollständiger URL nur der Pfadanteil)
    $base_path = parse_url((string)BASE_URL, PHP_URL_PATH);
    $base_path = rtrim(is_string($base_path) ? $base_path : '', '/');
    if ($base_path !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $base_path)) {
        return $fallback;
    }

    // Exakt <Basispfad>/pages/<seitenname>.php mit optionalem Query-String
    $pattern = '#^' . preg_quote($base_path, '#') . '/pages/([a-z0-9_]+)\.php(\?[A-Za-z0-9._~%&=+-]*)?$#D';
    if (!preg_match($pattern, $candidate, $m)) {
        return $fallback;
    }

    // Nur gültige Prozentkodierungen im Query-String
    $query = $m[2] ?? '';
    if ($query !== '' && preg_match('/%(?![0-9A-Fa-f]{2})/', $query)) {
        return $fallback;
    }

    // Nur tatsächlich vorhandene Seiten
    if (!is_file(__DIR__ . '/../pages/' . $m[1] . '.php')) {
        return $fallback;
    }

    return $candidate;
}
?>
