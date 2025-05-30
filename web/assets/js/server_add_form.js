/**
 * Datei: server_add_form.js
 * Zweck: Steuert das Verhalten des Formulars zur Servererstellung.
 *
 * Funktionen:
 * - Synchronisation der API-IP mit der DNS-IP bei aktivierter Checkbox.
 * - Generierung eines 256-Bit API-Tokens in Hex-Darstellung.
 * - Sichtbarkeitssteuerung für das Serverformular.
 */

document.addEventListener('DOMContentLoaded', function () {
    const checkbox = document.getElementById('same_ip_checkbox');
    const checkbox6 = document.getElementById('same_ip6_checkbox');
    const apiIpField = document.getElementById('api_ip');
    const dnsIpField = document.getElementById('dns_ip4');
    const dnsIp6Field = document.getElementById('dns_ip6');
    const isLocal = document.getElementById('is_local');
    const apiToken = document.getElementById('api_token');
    const genBtn = document.querySelector('button[onclick="generateApiKey()"]');

    if (!checkbox || !apiIpField || !dnsIpField || !isLocal || !apiToken) return;

    // Synchronisiert API-IP mit DNS-IP bei gesetzter Checkbox
    function toggleApiIpField() {
        if (!isLocal.checked) {
            // Gegenseitiges Deaktivieren
            if (this === checkbox && checkbox.checked) {
                checkbox6.checked = false;
            } else if (this === checkbox6 && checkbox6.checked) {
                checkbox.checked = false;
            }

            // API-IP setzen oder freigeben
            if (checkbox.checked) {
                apiIpField.readOnly = true;
                apiIpField.value = dnsIpField.value;
            } else if (checkbox6.checked) {
                apiIpField.readOnly = true;
                apiIpField.value = dnsIp6Field.value;
            } else {
                apiIpField.readOnly = false;
            }
        }
    }

    dnsIpField.addEventListener('input', function () {
        if (checkbox.checked && !isLocal.checked) {
            apiIpField.value = dnsIpField.value;
        }
    });
    dnsIp6Field.addEventListener('input', function () {
        if (checkbox6.checked && !isLocal.checked) {
            apiIpField.value = dnsIp6Field.value;
        }
    });

    checkbox.addEventListener('change', toggleApiIpField);
    checkbox6.addEventListener('change', toggleApiIpField);

    window.generateApiKey = function () {
        const array = new Uint8Array(32);
        crypto.getRandomValues(array);
        const hex = Array.from(array).map(b => b.toString(16).padStart(2, '0')).join('');
        apiToken.value = hex;
    };

    function toggleLocalServerFields() {
        const disabled = isLocal.checked;

        // DNS IPv6 bleibt immer aktiv
        if (dnsIp6Field) {
            dnsIp6Field.readOnly = false;
            dnsIp6Field.classList.remove('bg-light', 'text-muted');
        }

        // Bei lokalem Server => API-IP fest auf 127.0.0.1 setzen und readonly
        if (disabled) {
            apiIpField.readOnly = true;
            apiIpField.value = '127.0.0.1';
            apiIpField.classList.add('bg-light', 'text-muted');
        } else {
            apiIpField.readOnly = false;
            apiIpField.value = '';
            apiIpField.classList.remove('bg-light', 'text-muted');
        }

        // API-Key bleibt IMMER editierbar (auch für lokale Server)
        apiToken.readOnly = false;
        apiToken.classList.remove('bg-light', 'text-muted');
        if (genBtn) genBtn.disabled = false;

        // Checkboxen = API-IP-DNS-Sync deaktivieren bei lokalen Servern
        checkbox.disabled = disabled;
        checkbox6.disabled = disabled;
        if (disabled) {
            checkbox.checked = false;
            checkbox6.checked = false;
        }

        // Visuelles Feedback
        [checkbox, checkbox6].forEach(field => {
            if (disabled) {
                field.classList.add('bg-light', 'text-muted');
            } else {
                field.classList.remove('bg-light', 'text-muted');
            }
        });
    }

    isLocal.addEventListener('change', toggleLocalServerFields);

    // Initialstatus korrekt setzen
    toggleApiIpField();
    toggleLocalServerFields();
});
