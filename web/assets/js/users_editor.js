/**
 * Datei: assets/js/users_editor.js
 * Zweck: Progressive Verbesserung des Benutzereditors (U5b); ersetzt user_add_form.js und user_edit_form.js.
 *
 * - Anlage: Bei Rolle admin werden die Zonenoptionen wie bisher deaktiviert (nicht gesendet) und nur „alle Zonen“
 *   gezeigt; bei zoneadmin sind alle Optionen wählbar.
 * - Bearbeitung: Bei Rolle admin wird die Zonenauswahl wie bisher ausgeblendet (die Auswahl bleibt unverändert im
 *   Formular), stattdessen „alle Zonen“ angezeigt.
 * - Ohne JavaScript bleibt die Auswahl sichtbar; der UserService wertet Zonen nur für Zone-Admins aus.
 * - Nur addEventListener (CSP, U2).
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[data-dm-user-form]');
    if (!form) {
        return;
    }
    const isCreate = form.getAttribute('data-dm-user-form') === 'create';
    const role = form.querySelector('[data-dm-user-role]');
    const zonesBox = form.querySelector('[data-dm-user-zones]');
    const allZones = form.querySelector('[data-dm-user-allzones]');
    const select = form.querySelector('select[name="zones[]"]');
    if (!role || !select) {
        return;
    }
    const apply = function () {
        const admin = role.value === 'admin';
        if (isCreate) {
            Array.from(select.options).forEach(function (o) {
                const placeholder = o.hasAttribute('data-dm-all-placeholder');
                o.hidden = admin && !placeholder;
                o.disabled = admin;
            });
        } else {
            if (zonesBox) { zonesBox.hidden = admin; }
            if (allZones) { allZones.hidden = !admin; }
        }
    };
    role.addEventListener('change', apply);
    apply();
});
