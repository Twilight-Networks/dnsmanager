<?php
/**
 * Datei: layout_footer.php
 * Zweck:
 * - Abschluss des HTML-Layouts.
 * - Einbindung von Bootstrap (JS + Popper).
 * - Bereitstellung eines globalen Modals zur Bestätigung von Löschvorgängen.
 * - Zentrale JavaScript-Logik zur Abfangung aller <form class="confirm-delete">-Submits.
 */

?>
</div> <!-- .content (Hauptinhalt der Seite) -->

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
AssetRegistry::enqueueScript('bootstrap/bootstrap.bundle.min.js');

// Dynamisches Einbinden aller vorgemerkten Skripte mit Cache-Busting
foreach (AssetRegistry::getScripts() as $script) {
    $path = __DIR__ . '/../assets/' . $script;
    $version = file_exists($path) ? filemtime($path) : time();
    echo '<script src="' . rtrim(BASE_URL, '/') . '/assets/' . htmlspecialchars($script) . '?v=' . $version . '"></script>' . PHP_EOL;
}
?>

<?php
/**
 * Sprachdaten für JavaScript verfügbar machen
 *
 * Technische Hinweise:
 * - Die Ausgabe erfolgt als JSON-Objekt direkt im <script>-Tag am Ende der Seite.
 * - Die Daten stehen danach in allen JavaScript-Dateien über window.LANG zur Verfügung.
 * - Encoding-Flags stellen sicher, dass Sonderzeichen (z. B. Umlaute, HTML-Tags) korrekt ausgegeben werden.
 */
?>

<?php if (is_array($LANG)): ?>
<script>
    window.LANG = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
</script>
<?php endif; ?>

</body>
</html>
