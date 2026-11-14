<?php
/**
 * Datei: layout_footer.php
 * Zweck:
 * - Abschluss des HTML-Layouts.
 * - Einbindung von Bootstrap (JS + Popper).
 * - Bereitstellung eines globalen Modals zur Bestätigung von Löschvorgängen.
 * - Zentrale JavaScript-Logik zur Abfangung aller <form class="confirm-delete">-Submits.
 * - Seitenbezogene Sprachdaten für JavaScript als JSON-Datenblock (U2, CSP; kein Inline-Skript).
 */

?>
</main> <!-- .content (Hauptinhalt der Seite) -->
</div> <!-- .app-main -->

<!-- Bestätigungs-Modal für Löschaktionen -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-danger">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="confirmDeleteModalLabel"><?= $LANG['modal_confirm_delete_title'] ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= $LANG['close'] ?>"></button>
      </div>
      <div class="modal-body">
        <?= $LANG['modal_confirm_delete_text'] ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $LANG['cancel'] ?></button>
        <button type="button" class="btn btn-danger" id="modalDeleteConfirmBtn"><?= $LANG['delete'] ?></button>
      </div>
    </div>
  </div>
</div>

<?php
// Basis-JavaScript-Dateien registrieren
AssetRegistry::enqueueScript('js/global.js');
AssetRegistry::enqueueScript('js/components.js');
AssetRegistry::enqueueScript('bootstrap/bootstrap.bundle.min.js');

/**
 * Sprachdaten für JavaScript (U2, CSP): nur die Schlüssel der eingebundenen Skripte (inc/js_lang_keys.php),
 * als nicht ausführbarer JSON-Datenblock vor den Skripten. global.js liest ihn mit JSON.parse (lang()).
 * JSON_HEX_* verhindert, dass Inhalte den Datenblock beenden oder als Markup wirken.
 */
$jsLangKeys = require __DIR__ . '/../inc/js_lang_keys.php';
$jsLang = [];
if (is_array($LANG)) {
    foreach (AssetRegistry::getScripts() as $script) {
        foreach ($jsLangKeys[$script] ?? [] as $key) {
            if (array_key_exists($key, $LANG)) {
                $jsLang[$key] = $LANG[$key];
            }
        }
    }
}
echo '<script type="application/json" id="app-lang">'
    . json_encode((object)$jsLang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . '</script>' . PHP_EOL;

// Dynamisches Einbinden aller vorgemerkten Skripte mit Cache-Busting
foreach (AssetRegistry::getScripts() as $script) {
    $path = __DIR__ . '/../assets/' . $script;
    $version = file_exists($path) ? filemtime($path) : time();
    echo '<script src="' . rtrim(BASE_URL, '/') . '/assets/' . htmlspecialchars($script) . '?v=' . $version . '"></script>' . PHP_EOL;
}
?>

</body>
</html>
