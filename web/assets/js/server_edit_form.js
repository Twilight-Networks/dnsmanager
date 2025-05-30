/**
 * Datei: server_edit_form.js
 *
 * Zweck:
 * - Synchronisiert das Eingabefeld „API-IP“ mit der „DNS-IP“, wenn die Checkbox „API-IP = DNS-IP“ aktiviert ist.
 * - Stellt sicher, dass die API-IP nicht bearbeitet werden kann, wenn sie identisch zur DNS-IP sein soll.
 * - Reagiert dynamisch auf Eingaben im DNS-IP-Feld.
 *
 * Hinweise:
 * - Skript wird nur ausgeführt, wenn alle erforderlichen DOM-Elemente vorhanden sind.
 */

document.addEventListener('DOMContentLoaded', function () {
    const isEditForm = document.querySelector('[id^="editForm_"]');
    if (!isEditForm) return; // Nur aktiv, wenn Bearbeitungsformular existiert
    const sameIpCheckbox   = document.getElementById('same_ip_checkbox');
    const sameIp6Checkbox  = document.getElementById('same_ip6_checkbox');
    const dnsIpField       = document.getElementById('dns_ip4');
    const dnsIp6Field      = document.getElementById('dns_ip6');
    const apiIpField       = document.getElementById('api_ip');
    const apiTokenField    = document.querySelector('input[name="api_token"]');
    const isLocalCheckbox  = document.querySelector('input[name="is_local"]');
    const generateBtn      = document.getElementById('generateBtn');
    const toggleBtn        = document.getElementById('toggleTokenVisibility');
    const originalApiIp    = apiIpField.value;
    if (apiIpField.value === dnsIpField.value) {
        sameIpCheckbox.checked = true;
    }

    if (dnsIp6Field && apiIpField.value === dnsIp6Field.value) {
        sameIp6Checkbox.checked = true;
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const isHidden = apiTokenField.type === 'password';
            apiTokenField.type = isHidden ? 'text' : 'password';
            toggleBtn.textContent = isHidden ? '🙈' : '👁️';
        });
    }

    function toggleApiIpField() {
        if (!isLocalCheckbox.checked) {
            if (this === sameIpCheckbox && sameIpCheckbox.checked) {
                sameIp6Checkbox.checked = false;
            } else if (this === sameIp6Checkbox && sameIp6Checkbox.checked) {
                sameIpCheckbox.checked = false;
            }

            if (sameIpCheckbox.checked) {
                apiIpField.readOnly = true;
                apiIpField.value = dnsIpField.value;
            } else if (sameIp6Checkbox.checked) {
                apiIpField.readOnly = true;
                apiIpField.value = dnsIp6Field.value;
            } else {
                apiIpField.readOnly = false;
            }
        }
    }

    function toggleLocalServerFields() {
        const disabled = isLocalCheckbox.checked;

        dnsIpField.readOnly = false;
        dnsIpField.classList.remove('bg-light', 'text-muted');

        if (dnsIp6Field) {
            dnsIp6Field.readOnly = false;
            dnsIp6Field.classList.remove('bg-light', 'text-muted');
        }

        apiIpField.readOnly = disabled;
        apiIpField.value = disabled ? '127.0.0.1' : originalApiIp;

        // API-Token bleibt immer aktiv – auch bei lokalen Servern
        apiTokenField.readOnly = false;
        generateBtn.disabled = false;

        sameIpCheckbox.disabled = disabled;
        sameIp6Checkbox.disabled = disabled;

        if (disabled) {
            sameIpCheckbox.checked = false;
            sameIp6Checkbox.checked = false;
        }

        [apiIpField, sameIpCheckbox, sameIp6Checkbox].forEach(field => {
            field.classList.toggle('bg-light', disabled);
            field.classList.toggle('text-muted', disabled);
        });

        apiTokenField.classList.remove('bg-light', 'text-muted');

        toggleApiIpField();
    }

    dnsIpField.addEventListener('input', function () {
        if (sameIpCheckbox.checked && !isLocalCheckbox.checked) {
            apiIpField.value = dnsIpField.value;
        }
    });

    dnsIp6Field.addEventListener('input', function () {
        if (sameIp6Checkbox.checked && !isLocalCheckbox.checked) {
            apiIpField.value = dnsIp6Field.value;
        }
    });

    sameIpCheckbox.addEventListener('change', toggleApiIpField);
    sameIp6Checkbox.addEventListener('change', toggleApiIpField);
    isLocalCheckbox.addEventListener('change', toggleLocalServerFields);

    toggleLocalServerFields(); // initial
});
