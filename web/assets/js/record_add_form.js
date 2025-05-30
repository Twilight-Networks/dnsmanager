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

const recordTypeDescriptionsForward = {
    A: "<strong>A-Record:</strong><br><br>Verweist auf eine IPv4-Adresse (z. B. 192.0.2.1).",
    AAAA: "<strong>AAAA-Record:</strong><br><br>Verweist auf eine IPv6-Adresse (z. B. 2001:db8::1).",
    CNAME: "<strong>CNAME-Record:</strong><br><br>Alias für einen anderen Hostnamen.<br>Wichtig: Nicht mit anderen Record-Typen kombinieren.",
    MX: "<strong>MX-Record:</strong><br><br>Definiert Mailserver für die Domain.<br>Der Wert ist ein Hostname (FQDN), keine IP.",
    NS: "<strong>NS-Record:</strong><br><br>Autoritativer Nameserver der Zone.<br>Wird meist automatisch gesetzt.",
    PTR: "<strong>PTR-Record:</strong><br><br>Zeigt auf den Hostnamen einer IP-Adresse.<br>Erforderlich: vollständiger FQDN mit Punkt.",
    TXT: "<strong>TXT-Record:</strong><br><br>Freitext oder strukturierte Daten.<br>Beispiel: \"v=verify123\"",
    SPF: "<strong>SPF-Record:</strong><br><br>Definiert erlaubte Mailserver (Teil der TXT-Records).",
    DKIM: "<strong>DKIM:</strong><br><br>Erzeugt automatisch einen TXT-Record mit öffentlichem Schlüssel.",
    LOC: "<strong>LOC-Record:</strong><br><br>Speichert geographische Koordinaten.<br>Format: \"52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m\"",
    CAA: "<strong>CAA-Record:</strong><br><br>Legt fest, welche Zertifizierungsstellen Zertifikate ausstellen dürfen.<br>Beispiel: 0 issue \"letsencrypt.org\"",
    SRV: "<strong>SRV-Record:</strong><br><br>Definiert Services mit Priorität, Gewichtung, Port und Ziel.<br>Beispiel: 0 5 5060 sipserver.example.com",
    NAPTR: "<strong>NAPTR-Record:</strong><br><br>Mapping-Mechanismus für Dienste (z. B. SIP, ENUM).<br>Format: Order Preference \"Flags\" \"Service\" \"Regexp\" Replacement<br>Beispiel: 100 10 \"U\" \"E2U+sip\" \"!^.*$!sip:info@example.com!\" .",
    URI: "<strong>URI-Record:</strong><br><br>Definiert einen Dienst über Dienstname, Protokoll, Priorität, Gewichtung und Ziel-URI.<br>Format: &lt;Prio&gt; &lt;Weight&gt; \"&lt;URI&gt;\"<br>Beispiel: 10 1 \"ftp://ftp1.example.com/public\""
};

const recordTypeDescriptionsReverse = {
    PTR: "<strong>PTR-Record:</strong><br><br>Zeigt auf den Hostnamen einer IP-Adresse.<br>Erforderlich: vollständiger FQDN mit Punkt.",
    TXT: "<strong>TXT-Record:</strong><br><br>Freitext oder strukturierte Daten.<br>Beispiel: \"Zertifikat gültig bis ...\""
};

/**
 * Aktualisiert den Beschreibungstext in der Info-Box abhängig vom Record-Typ.
 * @param {string} type - Gewählter Record-Typ (z. B. A, MX, etc.)
 */
function updateInfoBox(type) {
    const content = document.getElementById('typeInfoContent');
    const form = document.getElementById('recordForm');
    const zoneType = form.dataset.zoneType;
    let text = "";

    if (zoneType === 'forward') {
        text = recordTypeDescriptionsForward[type] || "";
    } else if (zoneType === 'reverse') {
        text = recordTypeDescriptionsReverse[type] || "";
    }

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
        A: "z. B. 192.0.2.1",
        AAAA: "z. B. 2001:db8::1",
        CNAME: "z. B. alias.example.com",
        MX: "z. B. mail.example.com",
        NS: "z. B. ns1.example.com",
        PTR: "z. B. mail.example.com.",
        TXT: "z. B. \"v=verify123\"",
        SPF: "z. B. \"v=spf1 mx ~all\"",
        LOC: "z. B. 52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m",
        CAA: 'z. B. 0 issue "letsencrypt.org"',
        SRV: "z. B. _sip._tcp.example.com 0 5 5060 sipserver.example.com",
        NAPTR: 'z. B. 100 10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .',
    };

    const namePlaceholders = {
        MX: "z. B. @ oder sub",
        DKIM: "@",
        URI: "@",
        CAA: "z. B. www oder sub",
        SRV: "z. B. _sip",
        NAPTR: zoneType === 'reverse' ? "z. B. 42" : "z. B. www oder sub",
        default: zoneType === 'reverse' ? "z. B. 42" : "z. B. www oder @"
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
                alert("Bitte eine gültige Priorität (Zahl) für den MX-Eintrag angeben.");
                e.preventDefault();
                return;
            }

            if (!target) {
                alert("Bitte einen gültigen Mailserver angeben.");
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
                alert("Bitte alle NAPTR-Felder ausfüllen.");
                e.preventDefault();
                return;
            }
            // Namensfeld
            const prefix = document.getElementById('input_name_prefix').value.trim();
            if (!prefix) {
                alert("Bitte einen Namen für den NAPTR-Eintrag angeben.");
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
                alert("Bitte Dienstname und Protokoll für den SRV-Eintrag angeben.");
                e.preventDefault();
                return;
            }

            if (!/^\d+$/.test(priority) || !/^\d+$/.test(weight) || !/^\d+$/.test(port)) {
                alert("SRV: Priorität, Weight und Port müssen numerisch sein.");
                e.preventDefault();
                return;
            }

            if (!target) {
                alert("Bitte ein gültiges Ziel für den SRV-Eintrag angeben.");
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
                alert("Bitte einen Namen für den CAA-Eintrag angeben.");
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
                alert("Bitte alle DKIM-Felder ausfüllen.");
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
