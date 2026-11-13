<?php
/**
 * Datei: pages/update.php
 * Zweck: Prüft, ob eine neue Version des DNSManagers verfügbar ist.
 *
 * - Abruf (CurlUpdateFeed), Auswertung und Versionsvergleich im webunabhängigen UpdateCheckService (Paket 5d).
 * - Diese Seite übersetzt das Ergebnis in Meldungen (aktuell / Update verfügbar / Fehler) und stellt sie dar.
 * - Nur für Administratoren (S36a): requireRole() vor dem Abruf und vor jeder Ausgabe.
 * - Offen bis SP3b (S36b): Links aus der Antwort ohne Schemaprüfung.
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
?>

<br>
<br>
<h2><?= $LANG['update_check'] ?></h2>
<br>

<?php if ($error): ?>
    <div class="alert alert-danger">
        ❌ <?= htmlspecialchars($LANG['update_error'] . ': ' . $error) ?>
    </div>
<?php elseif ($update_available): ?>
    <div class="alert alert-warning">
        ⚠️ <?= $LANG['update_available'] ?><br><br>
        <strong><?= sprintf($LANG['update_version'], htmlspecialchars($remote_version)) ?></strong>
        (<?= sprintf($LANG['update_released'], htmlspecialchars($release_date)) ?>)
        <br><br>
        <?php if ($download_url): ?>
            <a href="<?= htmlspecialchars($download_url) ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                <?= $LANG['update_download'] ?>
            </a>
        <?php endif; ?>
        <?php if ($changelog_url): ?>
            <a href="<?= htmlspecialchars($changelog_url) ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                <?= $LANG['update_changelog'] ?>
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="alert alert-success">
        ✅ <?= sprintf($LANG['update_current'], htmlspecialchars($current_version)) ?>
    </div>
<?php endif; ?>
