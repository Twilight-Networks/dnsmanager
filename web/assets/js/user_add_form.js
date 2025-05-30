/**
 * Datei: assets/js/user_add_form.js
 * Zweck: Dynamische Steuerung der Zonen-Auswahl im Benutzeranlage-Formular.
 *
 * Details:
 * - Wird verwendet in: templates/user_add_form.php
 * - Passt die Auswahlmöglichkeit der Zonen dynamisch an die gewählte Rolle an:
 *   - Bei Rolle "admin": Nur ein Platzhalter-Eintrag „Alle Zonen“, deaktiviert.
 *   - Bei Rolle "zoneadmin": Alle Zonen einzeln auswählbar.
 */

// Schaltet Sichtbarkeit und Aktivierung der Zonenoptionen je nach Rolle um
function toggleZoneOptions(select) {
    const zoneSelect = document.getElementById('zone_select');
    const adminPlaceholder = document.getElementById('admin-placeholder');

    if (select.value === 'admin') {
        // Nur Platzhalter anzeigen, alle anderen ausblenden und deaktivieren
        zoneSelect.size = 1;
        for (const option of zoneSelect.options) {
            option.style.display = (option.id === 'admin-placeholder') ? 'block' : 'none';
            option.disabled = true;
        }
    } else {
        // Alle Optionen sichtbar und aktivierbar
        zoneSelect.size = 5;
        for (const option of zoneSelect.options) {
            option.style.display = 'block';
            option.disabled = false;
        }
    }
}

// Initialisierung beim Laden des Dokuments
window.addEventListener('DOMContentLoaded', function () {
    const roleSelect = document.getElementById('new_user_role');

    // Initiale Einstellung basierend auf voreingestellter Rolle
    toggleZoneOptions(roleSelect);

    // Reaktion auf Rollenwechsel
    roleSelect.addEventListener('change', function () {
        toggleZoneOptions(this);
    });
});
