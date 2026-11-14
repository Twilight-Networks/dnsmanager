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
    'js/record_add_form.js' => [
        'for_example',
        'record_caa_missing_name',
        'record_dkim_missing_fields',
        'record_info_forward_a',
        'record_info_forward_aaaa',
        'record_info_forward_caa',
        'record_info_forward_cname',
        'record_info_forward_dkim',
        'record_info_forward_loc',
        'record_info_forward_mx',
        'record_info_forward_naptr',
        'record_info_forward_ns',
        'record_info_forward_ptr',
        'record_info_forward_spf',
        'record_info_forward_srv',
        'record_info_forward_txt',
        'record_info_forward_uri',
        'record_info_reverse_ptr',
        'record_info_reverse_txt',
        'record_mx_invalid_priority',
        'record_mx_missing_target',
        'record_naptr_missing_fields',
        'record_naptr_missing_name',
        'record_srv_invalid_numbers',
        'record_srv_missing_fields',
        'record_srv_missing_target',
    ],
    'js/record_edit_form.js' => [
        'record_dkim_missing_fields',
        'record_srv_name_invalid',
        'record_uri_missing_fields',
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
