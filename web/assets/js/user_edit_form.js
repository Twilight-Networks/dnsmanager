/**
 * Datei: assets/js/user_edit_form.js
 * Zweck: Dynamisches Umschalten der Zonenauswahl im Benutzer-Bearbeitungsformular.
 *
 * Beschreibung:
 * - Wenn ein Benutzer im Bearbeiten-Modus ist, zeigt dieses Skript beim Wechsel der Rolle
 *   (admin ⇄ zoneadmin) dynamisch entweder den statischen Text „Alle Zonen“ oder
 *   die Zonen-Auswahl-Mehrfachliste an.
 * - Verhindert Layout-Verschiebungen, indem beide Container existieren, aber jeweils
 *   per .d-none (Bootstrap 5) sichtbar oder verborgen sind.
 *
 * Voraussetzungen:
 * - Die Zeile mit dem Rollen-<select> enthält ein verstecktes Feld mit dem Namen="id".
 * - Es existieren zwei Container:
 *   - <div id="zoneInfo[ID]"> … für Admin-Anzeige
 *   - <div id="zoneSelect[ID]"> … für Zoneadmin-Auswahl
 */

document.addEventListener('DOMContentLoaded', () => {
    // Alle Rollen-Dropdowns im Bearbeitungsformular durchgehen
    document.querySelectorAll('select[name="role"]').forEach(select => {
        // Event-Listener bei Änderung der Rolle
        select.addEventListener('change', function () {
            // Nächstgelegene Tabellenzeile suchen
            const row = this.closest('tr');

            // Benutzer-ID aus dem Hidden-Input extrahieren
            const userId = row.querySelector('input[name="id"]').value;

            // Elemente für die Anzeige und Auswahl der Zonen referenzieren
            const infoDiv = document.getElementById('zoneInfo' + userId);
            const selectDiv = document.getElementById('zoneSelect' + userId);

            // Umschalten je nach Rollenwert
            if (this.value === 'zoneadmin') {
                infoDiv.classList.add('d-none');
                selectDiv.classList.remove('d-none');
            } else {
                infoDiv.classList.remove('d-none');
                selectDiv.classList.add('d-none');
            }
        });
    });
});
