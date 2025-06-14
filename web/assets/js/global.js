/**
 * Datei: assets/js/global.js
 * Zweck:
 * - Zentrale JavaScript-Funktionen für Layout und Verhalten.
 * - Wird in layout_footer.php eingebunden.
 *
 * Enthält:
 * - Automatische Toast-Anzeige und -Ausblendung.
 * - Bestätigungs-Modal für alle <form class="confirm-delete">-Formulare.
 */

document.addEventListener('DOMContentLoaded', () => {

    // === Toasts automatisch anzeigen und nach 15 Sekunden ausblenden ===
    setTimeout(() => {
        document.querySelectorAll('.toast').forEach(el => {
            const toast = new bootstrap.Toast(el);
            toast.show();
            setTimeout(() => toast.hide(), 15000);
        });
    }, 100);

    // === Confirm-Delete-Modal global behandeln ===
    const modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
    let formToSubmit = null;

    document.querySelectorAll('form.confirm-delete').forEach(form => {
        form.addEventListener('submit', e => {
            e.preventDefault();
            formToSubmit = form;
            modal.show();
        });
    });

    const confirmBtn = document.getElementById('modalDeleteConfirmBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', () => {
            if (formToSubmit) {
                formToSubmit.submit();
                formToSubmit = null;
            }
        });
    }
});

/**
 * Sprach-Helper für JavaScript
 *
 * Funktion: t('key') → Gibt den übersetzten Text aus window.LANG zurück.
 * Optionales Fallback möglich, wenn Schlüssel nicht vorhanden ist.
 *
 * Beispiele:
 * - lang('save') → "Speichern"
 * - lang('for_example') + ' example.com' → "z. B. example.com"
 *
 * @param {string} key
 * @param {string|null} fallback
 * @returns {string}
 */
function lang(key, fallback = null) {
    if (typeof window.LANG === 'object' && key in window.LANG) {
        return window.LANG[key];
    }
    return fallback !== null ? fallback : key;
}
