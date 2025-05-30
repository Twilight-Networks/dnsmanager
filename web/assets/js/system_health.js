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
            row.style.display = (row.style.display === 'none') ? '' : 'none';
        }
    }

    // Globale Funktionen für HTML-Attribute zugänglich machen
    window.toggleConfigIssues = () => toggleRow('config-issues-row');
    window.toggleFileIssues = () => toggleRow('file-issues-row');
    window.toggleConfCheck = () => toggleRow('confcheck-row');
    window.toggleZoneCheck = () => toggleRow('zonecheck-row');
    window.toggleRemoteCheck = () => toggleRow('remotecheck-row');
});
