/**
 * Datei: assets/js/components.js
 * Zweck: Verhalten der UI-Bausteine (U3) als Verbesserung; ohne JavaScript bleibt alles bedienbar.
 *
 * - Editor-Rahmen [data-dm-editor]: Fokus beim Öffnen, Fokus bleibt im Dialog (Tab), Escape schließt über den
 *   Schließen-Link (data-dm-close-url). Öffnen/Schließen selbst erfolgen über normale Links (D2).
 * - Löschbestätigung details.dm-confirm (U0-B9): Klick auf die Schaltfläche sendet das Formular an das bestehende
 *   Bestätigungs-Modal (global.js, form.confirm-delete) statt die No-JS-Rückfrage aufzuklappen.
 * - Auswahl und Massenaktionen (data-dm-select*): Anzahl in der Leiste, IDs kommagetrennt in [data-dm-bulk-ids]
 *   (bestehender Vertrag bulk_ids, D7).
 * - Nur addEventListener, keine Inline-Handler (CSP, U2).
 */
document.addEventListener('DOMContentLoaded', function () {

    // === Editor-Rahmen ===
    document.querySelectorAll('[data-dm-editor]').forEach(function (editor) {
        const closeUrl = editor.getAttribute('data-dm-close-url');
        const focusable = function () {
            return Array.from(editor.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (el) { return el.offsetParent !== null || el === document.activeElement; });
        };
        const body = editor.querySelector('.dm-editor-body');
        const first = body ? body.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])') : null;
        if (first && !first.hasAttribute('readonly')) {
            first.focus({preventScroll: true});
        } else {
            editor.setAttribute('tabindex', '-1');
            editor.focus({preventScroll: true});
        }
        editor.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && closeUrl) {
                e.preventDefault();
                window.location.assign(closeUrl);
                return;
            }
            if (e.key === 'Tab') {
                const items = focusable();
                if (items.length === 0) {
                    return;
                }
                const firstItem = items[0];
                const lastItem = items[items.length - 1];
                if (e.shiftKey && document.activeElement === firstItem) {
                    e.preventDefault();
                    lastItem.focus();
                } else if (!e.shiftKey && document.activeElement === lastItem) {
                    e.preventDefault();
                    firstItem.focus();
                }
            }
        });
    });

    // === Löschbestätigung: mit JavaScript direkt das Bestätigungs-Modal ===
    document.querySelectorAll('details.dm-confirm > summary').forEach(function (summary) {
        summary.addEventListener('click', function (e) {
            const form = summary.closest('form');
            if (!form) {
                return;
            }
            e.preventDefault();
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(summary.parentElement.querySelector('.dm-confirm-submit') || undefined);
            } else {
                form.dispatchEvent(new Event('submit', {cancelable: true}));
            }
        });
    });

    // === Auswahl und Massenaktionen ===
    const groups = new Set();
    document.querySelectorAll('[data-dm-select], [data-dm-select-all], [data-dm-bulkbar]').forEach(function (el) {
        groups.add(el.getAttribute('data-dm-select') || el.getAttribute('data-dm-select-all') || el.getAttribute('data-dm-bulkbar'));
    });
    groups.forEach(function (group) {
        const rows = function () {
            return Array.from(document.querySelectorAll('input.dm-select-row')).filter(function (cb) {
                return cb.getAttribute('data-dm-select') === group;
            });
        };
        const all = Array.from(document.querySelectorAll('input.dm-select-all')).filter(function (cb) {
            return cb.getAttribute('data-dm-select-all') === group;
        });
        const bars = Array.from(document.querySelectorAll('[data-dm-bulkbar]')).filter(function (bar) {
            return bar.getAttribute('data-dm-bulkbar') === group;
        });
        const update = function () {
            const list = rows();
            const selected = list.filter(function (cb) { return cb.checked; });
            list.forEach(function (cb) {
                const tr = cb.closest('tr');
                if (tr) {
                    tr.classList.toggle('dm-selected', cb.checked);
                }
            });
            all.forEach(function (cb) {
                cb.checked = selected.length > 0 && selected.length === list.length;
                cb.indeterminate = selected.length > 0 && selected.length < list.length;
            });
            bars.forEach(function (bar) {
                bar.hidden = selected.length === 0;
                const count = bar.querySelector('.dm-bulk-count');
                if (count) {
                    count.textContent = String(bar.getAttribute('data-dm-count-format') || '%d').replace('%d', String(selected.length));
                }
                bar.querySelectorAll('[data-dm-bulk-ids]').forEach(function (input) {
                    input.value = selected.map(function (cb) { return cb.value; }).join(',');
                });
            });
        };
        rows().forEach(function (cb) { cb.addEventListener('change', update); });
        all.forEach(function (cb) {
            cb.addEventListener('change', function () {
                rows().forEach(function (row) { row.checked = cb.checked; });
                update();
            });
        });
        update();
    });
});
