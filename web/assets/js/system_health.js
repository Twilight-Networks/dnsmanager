/**
 * Datei: system_health.js
 * Zweck: Steuerung der ein- und ausklappbaren Detailansichten im Systemstatus.
 *
 * Beschreibung:
 * - Ermöglicht das Umschalten (toggle) von:
 *   - Konfigurationsfehlern
 *   - Dateiberechtigungsproblemen
 *   - Zonendateiprüfung (named-checkzone)
 *
 * - Wird in system_health.php verwendet, um Detailzeilen gezielt anzuzeigen oder zu verbergen.
 */

document.addEventListener('DOMContentLoaded', function () {
    /**
     * Klappt ein <tr>-Element mit der gegebenen ID ein oder aus.
     * @param {string} rowId - ID des Tabellenzeilen-Elements
     */
    function toggleRow(rowId) {
        const row = document.getElementById(rowId);
        if (row) {
            row.classList.toggle('app-hidden');
        }
    }

    // CSP (U2): Zeilen mit data-toggle-row="<ID der Detailzeile>" statt Inline-Handlern
    document.querySelectorAll('[data-toggle-row]').forEach(row => {
        row.addEventListener('click', () => toggleRow(row.dataset.toggleRow));
    });
});
