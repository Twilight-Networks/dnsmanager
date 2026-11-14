<?php
/**
 * Datei: inc/js_lang_keys.php
 * Zweck: Sprachschlüssel, die die Skripte unter assets/ per lang() benötigen (U2, CSP).
 *
 * - layout_footer.php gibt nur die Schlüssel der tatsächlich eingebundenen Skripte als
 *   <script type="application/json" id="app-lang"> aus (seitenbezogen statt des kompletten $LANG).
 * - Schlüssel: Pfad wie bei AssetRegistry::enqueueScript(); Wert: Liste der Sprachschlüssel.
 * - Neue lang()-Aufrufe in einem Skript erfordern hier den passenden Eintrag; fehlende Schlüssel
 *   liefert lang() wie bisher als Schlüsselnamen bzw. Fallback zurück.
 */

return [
    'js/dyndns.js' => [
        'clipboard_failed',
        'clipboard_success',
    ],
    'js/zone_add_form.js' => [
        'for_example',
        'zone_form_master_not_among_selected',
        'zone_form_no_master_selected',
        'zone_form_no_server_selected',
    ],
    'js/zone_edit_form.js' => [
        'zone_form_master_not_among_selected',
        'zone_form_no_master_selected',
        'zone_form_no_server_selected',
    ],
];
