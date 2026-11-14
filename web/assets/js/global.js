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
 * Funktion: lang('key') → Gibt den übersetzten Text aus den Sprachdaten der Seite zurück.
 * Optionales Fallback möglich, wenn Schlüssel nicht vorhanden ist.
 *
 * Quelle (U2, CSP): <script type="application/json" id="app-lang"> aus layout_footer.php mit den Schlüsseln der
 * eingebundenen Skripte (inc/js_lang_keys.php). Der Inhalt wird nicht ausgeführt, sondern einmalig per JSON.parse gelesen.
 *
 * Beispiele:
 * - lang('save') → "Speichern"
 * - lang('for_example') + ' example.com' → "z. B. example.com"
 *
 * @param {string} key
 * @param {string|null} fallback
 * @returns {string}
 */
let appLangData = null;

function lang(key, fallback = null) {
    if (appLangData === null) {
        appLangData = {};
        const el = document.getElementById('app-lang');
        if (el) {
            try {
                const parsed = JSON.parse(el.textContent);
                if (parsed && typeof parsed === 'object') {
                    appLangData = parsed;
                }
            } catch (e) {
                appLangData = {};
            }
        }
    }
    if (Object.prototype.hasOwnProperty.call(appLangData, key)) {
        return appLangData[key];
    }
    return fallback !== null ? fallback : key;
}
