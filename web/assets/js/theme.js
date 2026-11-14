/**
 * Datei: assets/js/theme.js
 * Zweck: Farbmodus und JS-Erkennung (U1). Wird im <head> blockierend geladen, damit die Seite ohne Aufflackern
 *        im richtigen Modus erscheint.
 *
 * - Ersetzt am <html>-Element die Klasse "no-js" durch "js" (CSS blendet damit z. B. die mobile Menü-Schaltfläche
 *   und den Farbmodus-Umschalter erst mit JavaScript ein; ohne JS bleibt die Navigation direkt sichtbar).
 * - Setzt data-bs-theme (light|dark): gespeicherte Wahl (localStorage "dnsmgr-theme": light|dark|auto) oder
 *   Systemvorgabe (prefers-color-scheme). Ohne JavaScript gilt die Systemvorgabe über css/theme-auto.css.
 * - Umschalter: Schaltflächen mit data-theme-set="light|dark|auto"; aria-pressed zeigt die aktive Wahl.
 * Die Wahl wird nur im Browser gespeichert; keine Serveränderung. Kein Inline-Code, kein eval (CSP-tauglich).
 */
(function () {
    'use strict';
    var root = document.documentElement;
    var KEY = 'dnsmgr-theme';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    root.classList.remove('no-js');
    root.classList.add('js');

    function stored() {
        try {
            var v = window.localStorage.getItem(KEY);
            return v === 'light' || v === 'dark' ? v : 'auto';
        } catch (e) {
            return 'auto';
        }
    }

    function apply(pref) {
        var theme = pref === 'light' || pref === 'dark' ? pref : (media && media.matches ? 'dark' : 'light');
        root.setAttribute('data-bs-theme', theme);
        root.setAttribute('data-theme-pref', pref);
        var buttons = document.querySelectorAll('[data-theme-set]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', buttons[i].getAttribute('data-theme-set') === pref ? 'true' : 'false');
        }
    }

    apply(stored());

    if (media) {
        var onChange = function () { if (stored() === 'auto') { apply('auto'); } };
        if (media.addEventListener) { media.addEventListener('change', onChange); } else if (media.addListener) { media.addListener(onChange); }
    }

    document.addEventListener('DOMContentLoaded', function () {
        apply(stored());
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-theme-set]') : null;
            if (!btn) { return; }
            var pref = btn.getAttribute('data-theme-set');
            try {
                if (pref === 'auto') { window.localStorage.removeItem(KEY); } else { window.localStorage.setItem(KEY, pref); }
            } catch (err) { /* Speicherung nicht möglich: nur für diese Seite anwenden */ }
            apply(pref === 'light' || pref === 'dark' ? pref : 'auto');
        });
    });
}());
