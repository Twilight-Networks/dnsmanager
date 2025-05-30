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
$update_url = 'https://www.twilight-networks.com/dnsmanager/latest.json';

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
    $error = 'Verbindung zum Update-Server fehlgeschlagen: ' . curl_error($ch);
} else {
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['version'])) {
        $error = 'Ungültige Antwort vom Update-Server.';
    } else {
        $remote_version = $data['version'];
        $release_date = $data['release_date'] ?? 'unbekannt';
        $changelog_url = $data['changelog_url'] ?? null;
    }
}

curl_close($ch);

// Layout einbinden
include __DIR__ . '/../templates/layout.php';
?>

<br>
<br>
<h2>Update-Prüfung</h2>
<br>

<?php if ($error): ?>
    <div class="alert alert-danger">
        ❌ <?= htmlspecialchars($error) ?>
    </div>
<?php elseif (version_compare($remote_version, $current_version, '>')): ?>
    <div class="alert alert-warning">
        ⚠️ Eine neue Version ist verfügbar:<br>
        <strong>Version <?= htmlspecialchars($remote_version) ?></strong>
        (veröffentlicht am <?= htmlspecialchars($release_date) ?>)
        <br><br>
        <?php if ($changelog_url): ?>
            <a href="<?= htmlspecialchars($changelog_url) ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                Zum Changelog
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="alert alert-success">
        ✅ Du verwendest die aktuellste Version (<strong><?= htmlspecialchars($current_version) ?></strong>).
    </div>
<?php endif; ?>
