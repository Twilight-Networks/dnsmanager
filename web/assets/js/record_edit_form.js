/**
 * Datei: assets/js/record_edit_form.js
 * Zweck: Dynamische Steuerung des Bearbeitungsformulars für DNS-Records
 *
 * Diese Datei aktiviert und steuert typabhängige Eingabefelder innerhalb des
 * Bearbeitungsformulars (editForm) in record_edit_form.php. Sie setzt Sichtbarkeit,
 * ergänzt zusammengesetzte Namen und verarbeitet dynamische Zonenpräfixe.
 *
 * Technische Anforderungen:
 * - Das Formular enthält die Attribute data-record-id und data-zone-suffix.
 * - Die IDs der relevanten Felder sind eindeutig per "edit_"-Präfix definiert.
 * - Das Script muss NACH dem Laden des Formulars eingebunden werden.
 *
 * Unterstützte Typen mit Sonderverhalten:
 * - MX     → Zeigt Prioritätsfeld
 * - SRV    → Zeigt SRV-Felder, blendet Content aus
 * - NAPTR  → Zeigt NAPTR-Felder, ersetzt Content-Input
 * - DKIM   → Zeigt DKIM-spezifische Felder
 * - CAA    → Zeigt Prefixfeld mit Zonensuffix, ersetzt normalen Namen
 *
 * Abhängigkeiten:
 * - Muss direkt nach dem zugehörigen HTML geladen werden (kein `defer`).
 * - Erfordert HTML-Struktur gemäß record_edit_form.php.
 */

(function () {
    /**
     * Blendet ein HTML-Element ein, indem die Klasse 'd-none' entfernt wird.
     *
     * @function show
     * @param {HTMLElement|null} el - Das DOM-Element, das eingeblendet werden soll.
     */
    const show = (el) => el?.classList.remove('d-none');

    /**
     * Blendet ein HTML-Element aus, indem die Klasse 'd-none' hinzugefügt wird.
     *
     * @function hide
     * @param {HTMLElement|null} el - Das DOM-Element, das ausgeblendet werden soll.
     */
    const hide = (el) => el?.classList.add('d-none');

    /**
     * Macht ein HTML-Element unsichtbar, ohne es aus dem Layoutfluss zu entfernen,
     * indem die Klasse 'invisible' hinzugefügt wird.
     *
     * @function invisible
     * @param {HTMLElement|null} el - Das DOM-Element, das unsichtbar gemacht werden soll.
     */
    const invisible = (el) => el?.classList.add('invisible');

    const isDKIM = document.getElementById('is_dkim_record')?.value === '1';
    const type = document.getElementById('edit_record_type')?.value;
    if (!type) return;

    const form = document.querySelector('[data-record-id]');
    const zoneName = form?.dataset.zoneName;
    const zoneType = form?.dataset.zoneType;
    const zoneSuffix = zoneName;
    const uriServiceField = document.getElementById('edit_uri_service');
    const uriProtocolField = document.getElementById('edit_uri_protocol');
    console.log('Zone name:', zoneName);

    const inputPrefix = document.getElementById('edit_input_name_prefix');
    const inputFullName = document.getElementById('edit_input_name');
    const wrapperCAA = document.getElementById('edit_caa_name_wrapper');

    const mxFields = document.getElementById('edit_mx_fields');
    const srvFields = document.getElementById('edit_srv_fields');
    const naptrFields = document.getElementById('edit_naptr_fields');
    const dkimFields = document.getElementById('edit_dkim_fields');
    const contentWrapper = document.getElementById('edit_content_wrapper');

    // Initiale Sichtbarkeitslogik
    hide(mxFields);
    hide(srvFields);
    hide(naptrFields);
    hide(dkimFields);
    hide(wrapperCAA);
    show(inputFullName);
    show(contentWrapper);

    switch (type) {
        case 'MX':
            show(mxFields);
            break;
        case 'SRV':
            show(srvFields);
            hide(contentWrapper);
            break;
        case 'NAPTR':
            show(naptrFields);
            invisible(contentWrapper);
            show(wrapperCAA);
            hide(inputFullName);
            break;
        case 'URI':
            hide(contentWrapper);
            inputFullName.readOnly = true;
            inputFullName.classList.add('bg-light', 'text-muted');
            inputFullName.classList.remove('d-none');
            break;
        case 'CAA':
            show(wrapperCAA);
            hide(inputFullName);
            break;
    }

    // DKIM separat behandeln
    if (isDKIM) {
        show(dkimFields);
        invisible(contentWrapper);
    }

    // SRV: beim Absenden zusammensetzen
    if (type === 'SRV' && form) {
        form.addEventListener('submit', function () {
            const protoField = form.querySelector('[name="srv_protocol"]');
            const nameField = form.querySelector('[name="name"]');
            if (!protoField || !nameField) return;

            const proto = protoField.value.trim().toLowerCase();
            const name = nameField.value.trim().replace(/\._(tcp|udp)$/i, ''); // doppelt absichern

            if (!name.startsWith('_')) {
                alert("Dienstname muss mit Unterstrich beginnen, z. B. _sip");
                return false;
            }

            nameField.value = `${name}.${proto}`;
        });
    }

    // DKIM: beim Absenden zusammensetzen
    if (isDKIM && form) {
        form.addEventListener('submit', function () {
            const selector = form.querySelector('input[name="dkim_selector"]')?.value.trim();
            const subdomain = form.querySelector('input[name="dkim_subdomain"]')?.value.trim();
            const key = form.querySelector('textarea[name="dkim_key"]')?.value.trim();
            const keyType = form.querySelector('select[name="dkim_key_type"]')?.value || 'rsa';
            const hash = form.querySelector('input[name="dkim_hash_algos"]')?.value.trim();
            const flags = form.querySelector('input[name="dkim_flags"]')?.value.trim();

            const nameField = document.getElementById('edit_input_name');
            const contentField = document.getElementById('edit_input_content');
            const typeField = form.querySelector('input[name="type"]');

            if (!selector || !key) {
                alert("Bitte alle DKIM-Felder ausfüllen.");
                return false;
            }

            // Name zusammensetzen inkl. optionaler Subdomain
            let name = `${selector}._domainkey`;
            if (subdomain) {
                name += `.${subdomain}`;
            }
            nameField.value = name;

            // Header-Parameter
            const parts = [`v=DKIM1`, `k=${keyType}`];
            if (hash) parts.push(`h=${hash}`);
            if (flags) parts.push(`t=${flags}`);
            const dkimHeader = parts.join('; ') + ';';

            // Key ggf. mit "p=" ergänzen
            const keyWithP = key.startsWith("p=") ? key : "p=" + key;

            // Aufsplitten in 255-Zeichen-Stücke
            const chunks = [`"${dkimHeader}"`];
            for (let i = 0; i < keyWithP.length; i += 255) {
                chunks.push('"' + keyWithP.slice(i, i + 255) + '"');
            }

            contentField.value = chunks.join(' ');

            if (typeField) {
                typeField.value = "TXT";
            }

        // is_dkim-Feld sicherstellen
        let isDkimField = form.querySelector('input[name="is_dkim"]');
        if (!isDkimField) {
            isDkimField = document.createElement('input');
            isDkimField.type = 'hidden';
            isDkimField.name = 'is_dkim';
            isDkimField.value = '1';
            form.appendChild(isDkimField);
        }
    });
}

// DKIM-Modus verlassen → hidden field entfernen
if (!isDKIM) {
    const field = document.querySelector('[data-record-id] input[name="is_dkim"]');
    if (field) field.remove();
}

    // URI: beim Absenden zusammensetzen
    if (type === 'URI' && form) {
        form.addEventListener('submit', function () {
            const service = document.getElementById('edit_uri_service')?.value.trim();
            const proto = document.getElementById('edit_uri_protocol')?.value.trim();
            const priority = document.getElementById('edit_uri_priority')?.value.trim();
            const weight = document.getElementById('edit_uri_weight')?.value.trim();
            const target = document.getElementById('edit_uri_target')?.value.trim();
            const nameField = document.getElementById('edit_input_name');
            const contentField = document.getElementById('edit_input_content');

            if (!service || !proto || !priority || !weight || !target) {
                alert("Bitte alle URI-Felder ausfüllen.");
                return false;
            }

            // Name zusammensetzen
            nameField.value = `${service}.${proto}`;

            // Inhalt zusammensetzen
            contentField.value = `${priority} ${weight} "${target}"`;
        });

    // URI: Namensfeld live aktualisieren
    if (type === 'URI') {
        const serviceField = document.getElementById('edit_uri_service');
        const protoField = document.getElementById('edit_uri_protocol');
        const nameField = document.getElementById('edit_input_name');

        const updateName = () => {
            const service = serviceField?.value.trim() || '';
            const proto = protoField?.value.trim() || '';
            if (nameField) nameField.value = `${service}.${proto}`;
        };

        serviceField?.addEventListener('input', updateName);
        protoField?.addEventListener('change', updateName);
    }
}})();
