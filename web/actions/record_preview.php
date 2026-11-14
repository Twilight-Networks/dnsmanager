<?php
/**
 * Datei: actions/record_preview.php
 * Zweck: Serverseitige Vorschau eines Record-Formulars (U4-UI, E2/E4) als JSON für das Live-DIFF.
 *
 * - Nur POST (sonst 405 mit Allow: POST), CSRF-Prüfung, Rollen admin/zoneadmin.
 * - Dieselbe Vorschaufunktion wie der No-JS-POST auf pages/records.php (recordEditorPreview()): identische Rohfelder,
 *   identischer Builder-Kontext, Typprüfung, TTL-Auflösung und Zonendateizeile wie beim Speichern.
 * - Ausschließlich lesend: keine Datenbankänderung, keine Veröffentlichung, keine Toasts; die Sitzung wird nach der
 *   Prüfung ohne Änderung geschlossen.
 * - Nicht vorhandene oder fremde Zonen und Records: einheitlich 403 {"status":"denied"} ohne weitere Angaben.
 * - Antwort: {"status":"ok"|"invalid"|"denied", "mode", "oldLine", "newLine", "unchanged", "errors", "hints",
 *   "seq"}. seq gibt die vom Skript gesendete Folgenummer zurück (Zuordnung überholter Antworten).
 */

require_once __DIR__ . '/../common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

verify_csrf_token();
requireRole(['admin', 'zoneadmin']);

require_once __DIR__ . '/../inc/web/web_adapter.php';
require_once __DIR__ . '/../inc/web/record_editor.php';

$actor = actorFromSession();
// Keine Schreibzugriffe auf die Sitzung: Sperre früh freigeben (parallele Vorschauanfragen blockieren sich nicht)
session_write_close();

$preview = recordEditorPreview($pdo, $actor, new ZoneAccess($pdo), $_POST, $_FILES);
$seq = isset($_POST['preview_seq']) && ctype_digit((string)$_POST['preview_seq']) ? (int)$_POST['preview_seq'] : null;

if ($preview['status'] === 'denied') {
    logAccessDenied('record_preview', 'Benutzer-ID ' . $actor->userId . ' – Vorschau ohne Berechtigung (Zone-ID ' . $preview['zoneId']
        . ($preview['mode'] === 'update' ? ', Record-ID ' . $preview['recordId'] : '') . ')');
    http_response_code(403);
    echo json_encode(['status' => 'denied', 'seq' => $seq]);
    exit;
}

echo json_encode([
    'status'    => $preview['status'],
    'mode'      => $preview['mode'],
    'oldLine'   => $preview['oldLine'],
    'newLine'   => $preview['newLine'],
    'unchanged' => $preview['unchanged'],
    'errors'    => $preview['errors'],
    'hints'     => $preview['hints'],
    'seq'       => $seq,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
