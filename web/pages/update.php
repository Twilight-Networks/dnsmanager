<?php
/**
 * Datei: pages/update.php
 * Zweck: Prüft, ob eine neue Version des DNSManagers verfügbar ist.
 *
 * - Abruf (CurlUpdateFeed), Auswertung und Versionsvergleich im webunabhängigen UpdateCheckService (Paket 5d).
 * - Diese Seite übersetzt das Ergebnis in Meldungen (aktuell / Update verfügbar / Fehler) und stellt sie dar.
 * - Nur für Administratoren (S36a): requireRole() vor dem Abruf und vor jeder Ausgabe.
 * - Antwortfelder werden im UpdateCheckService geprüft (S36b): Links nur als absolute HTTPS-URL, sonst entfallen sie.
 */

require_once __DIR__ . '/../common.php';

// Zugriffsbeschränkung: Nur Administratoren (vor dem Abruf der Update-Quelle und vor jeder Ausgabe, S36a)
requireRole(['admin']);

require_once __DIR__ . '/../config/version.php';
require_once __DIR__ . '/../inc/app/update/UpdateCheckService.php';
require_once __DIR__ . '/../inc/deploy/CurlUpdateFeed.php';

// Lokale installierte Version
$current_version = DNSMANAGER_VERSION;

// Initialisierung
$remote_version = null;
$release_date = null;
$download_url = null;
$changelog_url = null;
$update_available = false;
$error = null;

$result = (new UpdateCheckService(new CurlUpdateFeed()))->check($current_version);

foreach ($result->messages as $message) {
    $ctx = $message->context;
    switch ($message->code) {
        case 'update.check.connection_failed':
            $error = sprintf($LANG['update_connection_failed'], $ctx['detail']);
            break;
        case 'update.check.invalid_response':
            $error = $LANG['update_invalid_response'];
            break;
        case 'update.check.result':
            $remote_version = $ctx['remoteVersion'];
            $release_date = $ctx['releaseDate'] ?? $LANG['unknown'];
            $download_url = $ctx['downloadUrl'];
            $changelog_url = $ctx['changelogUrl'];
            $update_available = $ctx['updateAvailable'];
            break;
        default:
            // Unbekannte ServiceMessages nie still verwerfen
            appLog('error', "Unbehandelte ServiceMessage '{$message->code}' (update)");
            $error = $LANG['update_invalid_response'];
            break;
    }
}

// Layout einbinden
include __DIR__ . '/../templates/layout.php';

echo ui_page_header($LANG['update_check'], ['subtitle' => sprintf($LANG['update_installed'], $current_version)]);
if ($error) {
    echo ui_notice('err', $LANG['update_error'] . ':', ['text' => $error, 'role' => 'alert']);
} elseif ($update_available) {
    $links = '';
    if ($download_url) {
        $links .= '<a href="' . ui_e($download_url) . '" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">' . ui_e($LANG['update_download']) . '</a>';
    }
    if ($changelog_url) {
        $links .= '<a href="' . ui_e($changelog_url) . '" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">' . ui_e($LANG['update_changelog']) . '</a>';
    }
    echo ui_notice('warn', strip_tags($LANG['update_available']), [
        'text' => strip_tags(sprintf($LANG['update_version'], $remote_version)) . ' (' . strip_tags(sprintf($LANG['update_released'], $release_date)) . ')',
        'actionsHtml' => $links,
    ]);
} else {
    echo ui_notice('ok', strip_tags(sprintf($LANG['update_current'], $current_version)));
}
?>
