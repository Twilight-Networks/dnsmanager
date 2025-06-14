/**
 * Datei: assets/js/zone_edit_form.js
 * Zweck: Dynamik und Validierung für zone_edit_form.php
 * Hinweise:
 * - Markiert manuell geänderte SOA-Felder.
 * - Prüft beim Speichern auf gültige Serverzuweisung.
 */

window.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-zone-id]').forEach(form => {
        const soaNs   = form.querySelector('#soa_ns');
        const soaMail = form.querySelector('#soa_mail');
        const zoneType = form.dataset.zoneType || '';
        const zoneName = form.dataset.zoneName || '';

        // Nur für Forward-Zonen: dynamisch SOA NS aktualisieren
        if (zoneType === 'forward' && soaNs) {
            function updatePrimaryNameserverField() {
                const masterRadio = form.querySelector('input[name="master_server_id"]:checked');
                if (!masterRadio || soaNs.dataset.changed) return;

                const row = masterRadio.closest('tr');
                if (!row) return;

                const label = row.querySelector('label');
                if (!label) return;

                let fqdn = label.textContent.trim();
                if (!fqdn.endsWith('.')) {
                    fqdn += '.';
                }

                soaNs.value = fqdn;
            }

            updatePrimaryNameserverField(); // initial setzen

            form.querySelectorAll('input[name="master_server_id"]').forEach(radio => {
                radio.addEventListener('change', updatePrimaryNameserverField);
            });

            soaNs.addEventListener('input', () => {
                soaNs.dataset.changed = true;
            });
        }

        if (soaMail) {
            soaMail.addEventListener('input', () => {
                soaMail.dataset.changed = true;
            });
        }

        // Validierung bei Submit
        form.addEventListener('submit', function (e) {
            const serverCheckboxes = form.querySelectorAll('input[name="server_ids[]"]:checked');
            const masterRadio = form.querySelector('input[name="master_server_id"]:checked');

            if (serverCheckboxes.length === 0) {
                alert(lang('zone_form_no_server_selected'));
                e.preventDefault();
                return;
            }

            if (!masterRadio) {
                alert(lang('zone_form_no_master_selected'));
                e.preventDefault();
                return;
            }

            const selectedServerIds = Array.from(serverCheckboxes).map(cb => cb.value);
            if (!selectedServerIds.includes(masterRadio.value)) {
                alert(lang('zone_form_master_not_among_selected'));
                e.preventDefault();
            }
        });
    });
});

