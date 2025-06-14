<?php
/**
 * Datei: pages/update.php
 * Zweck: Prüft, ob eine neue Version des DNSManagers verfügbar ist.
 *
 * - Ruft die aktuelle Version von einer JSON-Datei über HTTPS ab.
 * - Vergleicht sie mit der lokal installierten Version.
 * - Gibt entsprechende Meldungen (aktuell / Update verfügbar / Fehler) aus.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../config/version.php';

// Remote-URL zur JSON-Datei mit Versionsinformationen
$update_url = 'https://www.twilight-networks.com/dnsmanager-update/latest.json';

// Lokale installierte Version
$current_version = DNSMANAGER_VERSION;

// Initialisierung
$remote_version = null;
$release_date = null;
$changelog_url = null;
$error = null;

// cURL-basierte Abfrage
$ch = curl_init($update_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);

if ($response === false) {
    $error = sprintf($LANG['update_connection_failed'], curl_error($ch));
} else {
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['version'])) {
        $error = $LANG['update_invalid_response'];
    } else {
        $remote_version = $data['version'];
        $release_date = $data['release_date'] ?? $LANG['unknown'];
        $download_url = $data['download_url'] ?? null;
        $changelog_url = $data['changelog_url'] ?? null;
    }
}

curl_close($ch);

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
<?php elseif (version_compare($remote_version, $current_version, '>')): ?>
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
