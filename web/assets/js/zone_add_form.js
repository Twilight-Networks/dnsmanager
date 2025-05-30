/**
 * Datei: assets/js/zone_add_form.js
 * Zweck: Dynamische UI-Logik für zone_add_form.php
 *
 * Beschreibung:
 * Dieses Skript steuert die interaktive Benutzeroberfläche beim Anlegen neuer DNS-Zonen.
 * Es reagiert auf den ausgewählten Zonentyp (forward, reverse_ipv4, reverse_ipv6) und passt
 * automatisch Sichtbarkeit, Platzhalter und Standardwerte für relevante Formularfelder an.
 *
 * Funktionen:
 * - Umschalten der UI-Elemente je nach Zonentyp
 * - Dynamisches Setzen von Standardwerten für Prefix-Länge
 * - Automatisches Vorbelegen von SOA-Feldern (Nameserver, E-Mail)
 * - Validierung durch Vorhandenseinsprüfung aller benötigten DOM-Elemente
 *
 * Anforderungen:
 * - HTML-Formular muss IDs wie zone_type, zone_prefix, zone_suffix_wrapper etc. enthalten
 */

function updateUIBasedOnType() {
    const zoneType = document.getElementById('zone_type');
    const suffixWrapper = document.getElementById('zone_suffix_wrapper');
    const suffixElement = document.getElementById('zone_suffix');
    const prefixLengthContainer = document.getElementById('prefix_length_container');
    const prefixLengthInput = document.querySelector('input[name="prefix_length"]');
    const soaIpWrapper = document.getElementById('soa_ns_ip_wrapper');
    const soaDomainWrapper = document.getElementById('soa_domain_wrapper');

    if (!zoneType || !suffixWrapper || !suffixElement || !prefixLengthContainer || !prefixLengthInput || !soaIpWrapper) return;

    const type = zoneType.value;

    if (type === 'reverse_ipv6') {
        suffixElement.textContent = '.ip6.arpa';
        suffixWrapper.classList.remove('d-none');
        prefixLengthContainer.style.display = 'block';
        soaIpWrapper.style.display = 'none';
        soaDomainWrapper.classList.remove('d-none');
        if (!prefixLengthInput.dataset.changed) {
            prefixLengthInput.value = 56;
        }
    } else if (type === 'reverse_ipv4') {
        suffixElement.textContent = '.in-addr.arpa';
        suffixWrapper.classList.remove('d-none');
        prefixLengthContainer.style.display = 'block';
        soaIpWrapper.style.display = 'none';
        soaDomainWrapper.classList.remove('d-none');
        if (!prefixLengthInput.dataset.changed) {
            prefixLengthInput.value = 24;
        }
    } else {
        suffixElement.textContent = '';
        suffixWrapper.classList.add('d-none');
        prefixLengthContainer.style.display = 'none';
        soaIpWrapper.style.display = 'block';
        soaDomainWrapper.classList.add('d-none');
        prefixLengthInput.value = '';
    }

    updateZonePrefixPlaceholder();
}

function updateZonePrefixPlaceholder() {
    const zoneType = document.getElementById('zone_type');
    const prefixField = document.getElementById('zone_prefix');
    if (!zoneType || !prefixField) return;

    const type = zoneType.value;
    if (type === 'forward') {
        prefixField.placeholder = 'z. B. example.com';
    } else if (type === 'reverse_ipv4') {
        prefixField.placeholder = 'z. B. 1.168.192';
    } else if (type === 'reverse_ipv6') {
        prefixField.placeholder = 'z. B. b.a.9.8.7';
    }
}

window.addEventListener('DOMContentLoaded', function () {
    const zoneType = document.getElementById('zone_type');
    const prefixField = document.getElementById('zone_prefix');
    const suffixElement = document.getElementById('zone_suffix');
    const soaNs = document.getElementById('soa_ns');
    const soaMail = document.getElementById('soa_mail');
    const form = document.querySelector('form');

    if (!zoneType || !prefixField || !suffixElement || !soaNs || !soaMail || !form) return;

    updateUIBasedOnType();

    zoneType.addEventListener('change', updateUIBasedOnType);

    prefixField.addEventListener('input', function () {
        updatePrimaryNameserverField();

        if (!soaMail.dataset.changed) {
            const prefix = prefixField.value.trim();
            const type = zoneType.value;
            const suffix = suffixElement.textContent;
            const zone = prefix + suffix;
            if (type === 'forward') {
                soaMail.value = 'hostmaster.' + zone + '.';
            } else {
                soaMail.value = '';
            }
        }
    updatePrimaryNameserverField();
    });

    function updatePrimaryNameserverField() {
        const masterRadio = document.querySelector('input[name="master_server_id"]:checked');
        if (!masterRadio) {
            soaNs.value = '';
            return;
        }

        const row = masterRadio.closest('tr');
        if (!row || !row.cells[2]) { // Spalte 2 = dritte Spalte = FQDN
            soaNs.value = '';
            return;
        }

        let fqdn = row.cells[2].textContent.trim(); // Spalte 2 = FQDN
        if (!fqdn.endsWith('.')) {
            fqdn += '.';
        }

        soaNs.value = fqdn;
    }

    document.querySelectorAll('input[name="master_server_id"]').forEach(radio => {
        radio.addEventListener('change', updatePrimaryNameserverField);
    });

    soaNs.addEventListener('input', function () {
        this.dataset.changed = true;
    });
    soaMail.addEventListener('input', function () {
        this.dataset.changed = true;
    });

    form.addEventListener('submit', function (e) {
        const serverCheckboxes = document.querySelectorAll('input[name="server_ids[]"]:checked');
        const masterRadio = document.querySelector('input[name="master_server_id"]:checked');

        if (serverCheckboxes.length === 0) {
            alert("Bitte mindestens einen DNS-Server auswählen.");
            e.preventDefault();
            return;
        }

        if (!masterRadio) {
            alert("Bitte einen Master-Server auswählen.");
            e.preventDefault();
            return;
        }

        const selectedServerIds = Array.from(serverCheckboxes).map(cb => cb.value);
        if (!selectedServerIds.includes(masterRadio.value)) {
            alert("Der gewählte Master-Server muss auch bei den ausgewählten DNS-Servern markiert sein.");
            e.preventDefault();
        }
    });
});

