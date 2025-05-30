/**
 * Datei: records.js
 * Zweck: Verwaltung der Mehrfachauswahl und Massenlöschung von DNS-Records in records.php.
 * Beschreibung:
 * - Steuert das Verhalten der Checkboxen für einzelne und alle Zeilen.
 * - Zeigt bei Auswahl mindestens eines Eintrags den "Einträge löschen"-Button an.
 * - Befüllt ein verstecktes Feld mit den ausgewählten Record-IDs zur Übergabe an den Server.
 */

document.addEventListener('DOMContentLoaded', function () {
    const checkboxes = document.querySelectorAll('.select-row'); // Einzelne Zeilen
    const selectAllBoxes = document.querySelectorAll('.select-all'); // "Alle auswählen"-Checkboxen
    const deleteForm = document.getElementById('bulkDeleteForm'); // Formular zur Massenlöschung
    const deleteBtn = document.getElementById('bulkDeleteBtn');   // Roter Button mit Zähler
    const hiddenInput = deleteForm.querySelector('input[name="bulk_ids"]'); // Verstecktes ID-Feld

    /**
     * Ermittelt alle selektierten Records und aktualisiert Button & Hidden Field.
     */
    function updateSelection() {
        const selected = Array.from(checkboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.dataset.id);

        if (selected.length > 0) {
            deleteForm.classList.remove('d-none');
            deleteBtn.textContent = `${selected.length} Einträge löschen`;
            hiddenInput.value = selected.join(',');
        } else {
            deleteForm.classList.add('d-none');
            hiddenInput.value = '';
        }
    }

    // Checkbox „Alle auswählen“ pro Tabelle
    selectAllBoxes.forEach(selectAll => {
        selectAll.addEventListener('change', function () {
            const tableId = this.dataset.tableId;
            document.querySelectorAll('.select-row[data-table-id="' + tableId + '"]').forEach(cb => {
                cb.checked = this.checked;
            });
            updateSelection();
        });
    });

    // Einzelne Checkboxen
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelection);
    });
});
