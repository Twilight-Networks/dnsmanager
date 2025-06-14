/**
 * Datei: assets/js/record_add_form.js
 * Zweck: Dynamische Steuerung des Formulars zur Erstellung von DNS-Records
 *
 * Enthält:
 * - Typabhängige Platzhalter und Hinweise
 * - Sichtbarkeitssteuerung für spezielle Felder (MX, SRV, DKIM, NAPTR, CAA)
 * - Validierungs- und Zusammenbau-Logik vor dem Submit
 * - Unterstützung für forward und reverse Zonen
 *
 * Anforderungen:
 * - Das HTML-Formular muss entsprechende IDs und Daten-Attribute enthalten:
 *   - data-zone-type (forward|reverse)
 *   - data-zone-name (FQDN der Zone)
 */

/**
 * Gibt die Beschreibungstexte für DNS-Record-Typen abhängig vom Zonentyp zurück.
 *
 * Die Texte werden zur Laufzeit über die `lang()`-Funktion geladen, um sicherzustellen,
 * dass die Übersetzungen erst dann abgerufen werden, wenn `window.LANG` verfügbar ist.
 *
 * @param {string} zoneType - Zonenkontext ('forward' oder 'reverse')
 * @returns {Object<string, string>} Schlüssel-Wert-Paar aus Record-Typ und HTML-Text
 */
function getRecordTypeDescriptions(zoneType) {
    if (zoneType === 'forward') {
        return {
            A: lang('record_info_forward_a'),
            AAAA: lang('record_info_forward_aaaa'),
            CNAME: lang('record_info_forward_cname'),
            MX: lang('record_info_forward_mx'),
            NS: lang('record_info_forward_ns'),
            PTR: lang('record_info_forward_ptr'),
            TXT: lang('record_info_forward_txt'),
            SPF: lang('record_info_forward_spf'),
            DKIM: lang('record_info_forward_dkim'),
            LOC: lang('record_info_forward_loc'),
            CAA: lang('record_info_forward_caa'),
            SRV: lang('record_info_forward_srv'),
            NAPTR: lang('record_info_forward_naptr'),
            URI: lang('record_info_forward_uri')
        };
    } else if (zoneType === 'reverse') {
        return {
            PTR: lang('record_info_reverse_ptr'),
            TXT: lang('record_info_reverse_txt')
        };
    }
    return {};
}

/**
 * Aktualisiert den Beschreibungstext in der Info-Box abhängig vom Record-Typ.
 *
 * @param {string} type - Gewählter Record-Typ (z. B. A, MX, etc.)
 */
function updateInfoBox(type) {
    const content = document.getElementById('typeInfoContent');
    const form = document.getElementById('recordForm');
    const zoneType = form.dataset.zoneType;

    const descriptions = getRecordTypeDescriptions(zoneType);
    const text = descriptions[type] || "";

    content.innerHTML = text;
}

/**
 * Steuert die Sichtbarkeit des DKIM-Feldbereichs.
 * @param {string} type - Gewählter Record-Typ
 */
function toggleDKIM(type) {
    document.getElementById('dkim_fields').style.display = (type === 'DKIM') ? 'block' : 'none';
}

/**
 * Zeigt oder versteckt das Content-Feld abhängig vom Typ.
 * SRV, NAPTR, DKIM, MX setzen das Content-Feld automatisch.
 * @param {string} type - Gewählter Record-Typ
 */
function toggleContentField(type) {
    const wrapper = document.getElementById('content_wrapper');
    const input = document.getElementById('input_content');
    const hide = ['SRV', 'NAPTR', 'DKIM', 'MX', 'URI'].includes(type);
    wrapper.classList.toggle('d-none', hide);
    input.required = !hide;
}

/**
 * Aktualisiert Platzhalter, Sichtbarkeit und Pflichtfelder abhängig vom Typ und der Zonenkonfiguration.
 * @param {string} type - Gewählter Record-Typ
 * @param {string} zoneType - forward oder reverse
 */
function updatePlaceholders(type, zoneType) {
    const form = document.getElementById('recordForm');
    const zoneName = form.dataset.zoneName;
    const contentField = document.getElementById('input_content');
    const nameField = document.getElementById('input_name');
    const prioMxField = document.getElementById('input_mx_priority');
    const prioSrvField = document.getElementById('input_srv_priority');
    const autoPtrWrapper = document.getElementById('auto_ptr')?.closest('.col-12');
    const fqdnWrapper = document.getElementById('fqdn_wrapper');
    const caaWrapper = document.getElementById('caa_name_wrapper');
    const prefixField = document.getElementById('input_name_prefix');

    const placeholders = {
        A: lang('for_example') + ' 192.0.2.1',
        AAAA: lang('for_example') + ' 2001:db8::1',
        CNAME: lang('for_example') + ' alias.example.com',
        MX: lang('for_example') + ' mail.example.com',
        NS: lang('for_example') + ' ns1.example.com',
        PTR: lang('for_example') + ' mail.example.com.',
        TXT: lang('for_example') + ' \"v=verify123\"',
        SPF: lang('for_example') + ' \"v=spf1 mx ~all\"',
        LOC: lang('for_example') + ' 52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m',
        CAA: lang('for_example') + ' 0 issue "letsencrypt.org"',
        SRV: lang('for_example') + ' _sip._tcp.example.com 0 5 5060 sipserver.example.com',
        NAPTR: lang('for_example') + ' 100 10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .',
    };

    const namePlaceholders = {
        MX: lang('for_example') + ' @ oder sub',
        DKIM: "@",
        URI: "@",
        CAA: lang('for_example') + ' www oder sub',
        SRV: lang('for_example') + ' _sip',
        NAPTR: zoneType === 'reverse' ? lang('for_example') + ' 42' : lang('for_example') + ' www oder sub',
        default: zoneType === 'reverse' ? lang('for_example') + ' 42' : lang('for_example') + ' www oder @'
    };

    // Content-Placeholder
    contentField.placeholder = placeholders[type] || "";

    if (type === 'MX') {
        document.getElementById('input_mx_target').value = '';
        document.getElementById('mx_target_mode').value = 'dot';
    }

    toggleContentField(type);
    toggleDKIM(type);
    nameField.required = !(type === 'CAA' || type === 'NAPTR');

    if (autoPtrWrapper) {
        autoPtrWrapper.classList.toggle('d-none', !(type === 'A' || type === 'AAAA'));
    }

    const fqdnVisible = ['CNAME', 'NS', 'PTR'].includes(type) && zoneType === 'forward';
    fqdnWrapper.classList.toggle('d-none', !fqdnVisible);

    document.getElementById('mx_fields_inline').classList.toggle('d-none', type !== 'MX');
    document.getElementById('srv_fields').classList.toggle('d-none', type !== 'SRV');
    document.getElementById('naptr_fields').classList.toggle('d-none', type !== 'NAPTR');
    document.getElementById('uri_fields')?.classList.toggle('d-none', type !== 'URI');

    if (type === 'SRV') {
        prioSrvField.value = "0";
    }

    if (type === 'MX') {
        //nameField.readOnly = true;
        //nameField.classList.add('bg-light', 'text-muted');
        prioMxField.value = "1";
        caaWrapper.classList.add('d-none');
        nameField.classList.remove('d-none');
        nameField.placeholder = namePlaceholders[type] || namePlaceholders.default;
        nameField.value = '';
    } else if (['CAA', 'NAPTR'].includes(type)) {
        caaWrapper.classList.remove('d-none');
        nameField.classList.add('d-none');
        prefixField.placeholder = namePlaceholders[type] || namePlaceholders.default;
        prefixField.value = '';
        nameField.value = '';
        nameField.readOnly = false;
        nameField.classList.remove('bg-light', 'text-muted');
    } else if (type === 'DKIM') {
        nameField.readOnly = true;
        nameField.classList.add('bg-light', 'text-muted');
        caaWrapper.classList.add('d-none');
        nameField.classList.remove('d-none');
        nameField.placeholder = namePlaceholders[type] || namePlaceholders.default;
        nameField.value = '';
    } else if (type === 'URI') {
        nameField.readOnly = true;
        nameField.classList.add('bg-light', 'text-muted');
        caaWrapper.classList.add('d-none');
        nameField.classList.remove('d-none');
        nameField.placeholder = namePlaceholders[type] || namePlaceholders.default;
        nameField.value = '';
    } else {
        caaWrapper.classList.add('d-none');
        nameField.classList.remove('d-none');
        nameField.placeholder = namePlaceholders[type] || namePlaceholders.default;
        nameField.readOnly = false;
        nameField.classList.remove('bg-light', 'text-muted');
    }
}

/**
 * Initialisiert die Formularverarbeitung und Ereignisbehandlung nach dem Laden des Dokuments.
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('recordForm');
    if (!form) return; // JS-Abbruch, wenn das Formular nicht vorhanden ist
    const typeSelect = document.getElementById('record_type');
    const contentField = document.getElementById('input_content');

    const zoneType = form.dataset.zoneType;
    const zoneName = form.dataset.zoneName;

    updateInfoBox(typeSelect.value);
    updatePlaceholders(typeSelect.value, zoneType);

    typeSelect.addEventListener('change', function () {
        const type = this.value;
        updateInfoBox(type);
        updatePlaceholders(type, zoneType);
    });

    form.addEventListener('submit', function(e) {
        const type = typeSelect.value;

        /**
         * PTR, NS, CNAME: FQDN-Vervollständigung bei gesetztem Modus
         */
        const fqdnMode = document.getElementsByName('fqdn_mode')[0];
        const fqdnWrapper = document.getElementById('fqdn_wrapper');
        const fqdnRelevantTypes = ['CNAME', 'NS', 'PTR'];

        if (fqdnWrapper && !fqdnWrapper.classList.contains('d-none') &&
            fqdnMode && fqdnRelevantTypes.includes(type)) {

            let val = contentField.value.trim();
            if (val && !val.endsWith('.')) val += '.';

            if (fqdnMode.value !== 'dot') {
                val += fqdnMode.value;
            }

            contentField.value = val;
        }

        // PTR-Records in reverse zones: Immer FQDN mit Punkt
        if (zoneType === 'reverse' && type === 'PTR') {
            let val = contentField.value.trim();
            if (val && !val.endsWith('.')) {
                contentField.value = val + '.';
            }
        }

        /**
         * MX-Eintrag zusammensetzen aus Priorität und Ziel
         */
        if (type === 'MX') {
            const prioField = document.getElementById('input_mx_priority');
            const targetField = document.getElementById('input_mx_target');
            const mode = document.getElementById('mx_target_mode').value;
            const fqdnMode = document.getElementsByName('fqdn_mode')[0];

            const prio = prioField.value.trim();
            const target = targetField.value.trim();

            if (!/^\d+$/.test(prio)) {
                alert(lang('record_mx_invalid_priority'));
                e.preventDefault();
                return;
            }

            if (!target) {
                alert(lang('record_mx_missing_target'));
                e.preventDefault();
                return;
            }

            let fqdn = target;
            if (!fqdn.endsWith('.')) fqdn += '.';
            if (mode !== 'dot') fqdn += mode;

            document.getElementById('input_content').value = fqdn;
        }

        /**
         * NAPTR-Eintrag validieren und zusammensetzen
         */
        if (type === 'NAPTR') {
            const requiredFields = ['naptr_order', 'naptr_pref', 'naptr_flags', 'naptr_service', 'naptr_regexp', 'naptr_replacement'];
            if (requiredFields.some(id => !document.getElementById(id).value.trim())) {
                alert(lang('record_naptr_missing_fields'));
                e.preventDefault();
                return;
            }
            // Namensfeld
            const prefix = document.getElementById('input_name_prefix').value.trim();
            if (!prefix) {
                alert(lang('record_naptr_missing_name'));
                e.preventDefault();
                return;
            }
            document.getElementById('input_name').value = prefix;

            const v = id => document.getElementById(id).value.trim();
            contentField.value = `${v('naptr_order')} ${v('naptr_pref')} "${v('naptr_flags')}" "${v('naptr_service')}" "${v('naptr_regexp')}" ${v('naptr_replacement')}`;
        }

        /**
         * SRV-Eintrag validieren und zusammensetzen
         */
        if (type === 'SRV') {
            const nameField = document.getElementById('input_name');
            const protoField = document.getElementById('srv_protocol');
            const priority = document.getElementById('input_srv_priority').value.trim();
            const weight = document.getElementById('srv_weight').value.trim();
            const port = document.getElementById('srv_port').value.trim();
            const target = document.getElementById('srv_target').value.trim();
            const mode = document.getElementById('srv_target_mode').value;

            if (!nameField.value.trim() || !protoField.value) {
                alert(lang('record_srv_missing_fields'));
                e.preventDefault();
                return;
            }

            if (!/^\d+$/.test(priority) || !/^\d+$/.test(weight) || !/^\d+$/.test(port)) {
                alert(lang('record_srv_invalid_numbers'));
                e.preventDefault();
                return;
            }

            if (!target) {
                alert(lang('record_srv_missing_target'));
                e.preventDefault();
                return;
            }

            // SRV-Name zusammensetzen aus Dienstname und Protokoll
            const service = nameField.value.trim().replace(/^_/, '');
            const proto = protoField.value;
            nameField.value = `_${service}.${proto}`;

            // Ziel-FQDN zusammensetzen
            let fqdn = target;
            if (!fqdn.endsWith('.')) fqdn += '.';
            if (mode !== 'dot') fqdn += mode;

            contentField.value = `${priority} ${weight} ${port} ${fqdn}`;
        }

        /**
         * CAA-Eintrag Namensfeld
         */
        if (type === 'CAA') {
            const prefix = document.getElementById('input_name_prefix').value.trim();
            if (!prefix) {
                alert(lang('record_caa_missing_name'));
                e.preventDefault();
                return;
            }
            document.getElementById('input_name').value = prefix;
        }

        /**
         * DKIM-Eintrag: Validierung und automatische Umwandlung in TXT
         */
        if (type === 'DKIM') {
            const selector = document.getElementById('dkim_selector').value.trim();
            const subdomain = document.getElementById('dkim_subdomain')?.value.trim();
            const domain = form.dataset.zoneName;
            const key = document.getElementById('dkim_key').value.trim();
            const k = (document.getElementById('dkim_key_type')?.value || 'rsa').trim();
            const h = document.getElementById('dkim_hash_algos')?.value.trim();
            const t = document.getElementById('dkim_flags')?.value.trim();

            if (!selector || !domain || !key) {
                alert(lang('record_dkim_missing_fields'));
                e.preventDefault();
                return;
            }

            document.getElementById('record_type').value = "TXT";

            let name = selector + "._domainkey";
            if (subdomain) {
                name += "." + subdomain;
            }
            document.getElementById('input_name').value = name;

            const params = [`v=DKIM1`, `k=${k}`];
            if (h) params.push(`h=${h}`);
            if (t) params.push(`t=${t}`);
            const dkimHeader = params.join('; ') + ';';

            const keyWithP = key.startsWith("p=") ? key : "p=" + key;
            const chunks = [`"${dkimHeader}"`];
            for (let i = 0; i < keyWithP.length; i += 255) {
                chunks.push('"' + keyWithP.slice(i, i + 255) + '"');
            }

            contentField.value = chunks.join(' ');

            // is_dkim-Feld sicherstellen
            let isDkimField = form.querySelector('input[name="is_dkim"]');
            if (!isDkimField) {
                isDkimField = document.createElement('input');
                isDkimField.type = 'hidden';
                isDkimField.name = 'is_dkim';
                isDkimField.value = '1';
                form.appendChild(isDkimField);
            }
        }
        // is_dkim-Feld entfernen, wenn es kein DKIM ist
        if (type !== 'DKIM') {
            const field = form.querySelector('input[name="is_dkim"]');
            if (field) field.remove();
        }
    });
});
