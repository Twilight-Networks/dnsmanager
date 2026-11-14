/**
 * Datei: assets/js/zone_editor.js
 * Zweck: Progressive Verbesserung des Zoneneditors (U5a) in pages/zones.php; ersetzt zone_add_form.js,
 *        zone_edit_form.js und zones.js. Ohne JavaScript bleibt alles bedienbar (Typwechsel über „Typ übernehmen“).
 *
 * - Anlage: Typwechsel blendet die typabhängigen Felder ein/aus (Präfixlänge, SOA-Domain, angezeigter Primary NS),
 *   setzt Suffix, Platzhalter und – solange nicht selbst geändert – die Vorgabe der Präfixlänge. Alle Felder bleiben
 *   gesendet; eingegebene Werte gehen beim Typwechsel nicht verloren.
 * - Anlage (Forward): SOA-Mail aus dem Zonennamen vorbelegen, solange nicht selbst geändert; angezeigter Primary NS
 *   aus dem gewählten Master.
 * - Bearbeitung (Forward): SOA-NS folgt dem gewählten Master, solange nicht selbst geändert (wie bisher).
 * - Serverzuweisung: Prüfung vor dem Absenden (mindestens ein Server, Master gewählt und zugewiesen) nur als
 *   Bedienhilfe mit Hinweis im Formular; maßgeblich ist die Prüfung im ZoneService. Ohne Eingabefelder (zoneadmin)
 *   keine Prüfung.
 * - Nur addEventListener und textContent (CSP, U2).
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[data-dm-zone-form]');
    if (!form) {
        return;
    }
    const isCreate = form.getAttribute('data-dm-zone-form') === 'create';
    const markChanged = function (el) {
        if (el) {
            el.addEventListener('input', function () { el.dataset.changed = '1'; });
        }
    };
    const masterFqdn = function () {
        const radio = form.querySelector('input[name="master_server_id"]:checked');
        if (!radio) {
            return '';
        }
        let fqdn = (radio.getAttribute('data-server-name') || '').trim();
        if (fqdn !== '' && !fqdn.endsWith('.')) {
            fqdn += '.';
        }
        return fqdn;
    };

    if (isCreate) {
        const typeSelect = form.querySelector('[data-dm-zone-type]');
        const prefix = form.querySelector('[name="zone_prefix"]');
        const suffix = form.querySelector('[data-dm-zone-suffix]');
        const prefixLength = form.querySelector('[name="prefix_length"]');
        const soaMail = form.querySelector('[name="soa_mail"]');
        const soaNs = form.querySelector('#zf_soa_ns');
        const suffixes = {forward: '', reverse_ipv4: '.in-addr.arpa', reverse_ipv6: '.ip6.arpa'};
        const placeholders = {forward: 'example.com', reverse_ipv4: '1.168.192', reverse_ipv6: 'b.a.9.8.7'};
        const defaults = {reverse_ipv4: '24', reverse_ipv6: '56'};
        markChanged(prefixLength);
        markChanged(soaMail);

        document.querySelectorAll('[data-dm-zone-apply]').forEach(function (btn) { btn.hidden = true; });

        const applyType = function () {
            const type = typeSelect.value;
            const reverse = type !== 'forward';
            form.querySelectorAll('[data-dm-zone-reverse-only]').forEach(function (el) { el.hidden = !reverse; });
            form.querySelectorAll('[data-dm-zone-forward-only]').forEach(function (el) { el.hidden = reverse; });
            if (suffix) {
                suffix.textContent = suffixes[type] || '';
                suffix.hidden = !reverse;
            }
            if (prefix) {
                prefix.placeholder = lang('for_example') + ' ' + (placeholders[type] || '');
            }
            if (reverse && prefixLength && !prefixLength.dataset.changed && defaults[type]) {
                prefixLength.value = defaults[type];
            }
            updateSoa();
        };
        const updateSoa = function () {
            if (soaNs) {
                soaNs.value = masterFqdn();
            }
            if (soaMail && !soaMail.dataset.changed && typeSelect.value === 'forward' && prefix) {
                const zone = prefix.value.trim();
                soaMail.value = zone === '' ? '' : 'hostmaster.' + zone + '.';
            }
        };
        typeSelect.addEventListener('change', applyType);
        if (prefix) {
            prefix.addEventListener('input', updateSoa);
        }
        form.querySelectorAll('input[name="master_server_id"]').forEach(function (r) { r.addEventListener('change', updateSoa); });
        applyType();
    } else if (form.getAttribute('data-zone-type') === 'forward') {
        const soaNs = form.querySelector('[name="soa_ns"]');
        markChanged(soaNs);
        form.querySelectorAll('input[name="master_server_id"]').forEach(function (r) {
            r.addEventListener('change', function () {
                const fqdn = masterFqdn();
                if (soaNs && !soaNs.dataset.changed && fqdn !== '') {
                    soaNs.value = fqdn;
                }
            });
        });
    }

    // Bedienhilfe: Serverzuweisung vor dem Absenden prüfen (nur wenn Eingabefelder vorhanden sind)
    const check = form.querySelector('[data-dm-zone-check]');
    form.addEventListener('submit', function (e) {
        const boxes = form.querySelectorAll('input[name="server_ids[]"]');
        if (boxes.length === 0 || (e.submitter && e.submitter.hasAttribute('data-dm-zone-apply'))) {
            return;
        }
        const selected = Array.from(form.querySelectorAll('input[name="server_ids[]"]:checked')).map(function (cb) { return cb.value; });
        const master = form.querySelector('input[name="master_server_id"]:checked');
        let message = '';
        if (selected.length === 0) {
            message = lang('zone_form_no_server_selected');
        } else if (!master) {
            message = lang('zone_form_no_master_selected');
        } else if (selected.indexOf(master.value) === -1) {
            message = lang('zone_form_master_not_among_selected');
        }
        if (message !== '' && check) {
            e.preventDefault();
            check.textContent = message;
            check.hidden = false;
            check.scrollIntoView({block: 'nearest'});
        }
    });
});
