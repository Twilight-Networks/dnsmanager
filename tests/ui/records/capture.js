/**
 * Datei: tests/ui/records/capture.js
 * Zweck: Erfasst für die Fälle der Record-Typ-Matrix die POST-Daten, die die bestehenden Formularskripte mit JS
 *        tatsächlich senden (U4-Core).
 *        Ablauf je Fall (die Anwendung verbietet Frames per X-Frame-Options, daher echte Navigation):
 *        1. Seite des Falls in der Testkopie öffnen (Serve-Modus, admin, UIG_RECORDS_MATRIX=1);
 *        2. dieses Skript laden und `await dmCaptureHere('<Fall-ID>')` ausführen: Bedienschritte im echten Formular
 *           (Wert setzen, input/change auslösen), danach eigener submit-Listener (läuft nach den vorhandenen Handlern),
 *           Formulardaten lesen, Absenden verhindern; alert() wird protokolliert; hat ein Handler das Absenden
 *           verhindert, gilt der Fall als „durch JS blockiert“;
 *        3. Ergebnisse sammeln sich in localStorage['dmPosts']; am Ende `dmCaptureResult()` als js_posts.json speichern.
 */
async function dmCaptureHere(id) {
    const cases = await (await fetch('/dnsmanager-ui/__ui/cases.json', {cache: 'no-store'})).json();
    const c = cases[id];
    const store = JSON.parse(localStorage.getItem('dmPosts') || '{}');
    const result = {};
    const alerts = [];
    window.alert = (msg) => alerts.push(String(msg));
    const form = document.getElementById(c.form);
    if (!form) {
        store[id] = {error: 'Formular nicht gefunden', url: location.href};
        localStorage.setItem('dmPosts', JSON.stringify(store));
        return store[id];
    }
    for (const [sel, value] of c.ops) {
        const el = form.querySelector(sel) || document.querySelector(sel);
        if (!el) { (result.missing = result.missing || []).push(sel); continue; }
        el.value = value;
        el.dispatchEvent(new Event('input', {bubbles: true}));
        el.dispatchEvent(new Event('change', {bubbles: true}));
    }
    let captured = null;
    form.addEventListener('submit', (e) => {
        const pairs = [];
        for (const [n, v] of new FormData(form).entries()) {
            pairs.push([n, typeof v === 'string' ? v : '']);
        }
        captured = {pairs, blocked: e.defaultPrevented ? (alerts.join(' | ') || 'preventDefault') : null};
        e.preventDefault();
    });
    form.requestSubmit();
    await new Promise((r) => setTimeout(r, 50));
    store[id] = Object.assign(result, captured || {error: 'kein submit'}, alerts.length ? {alerts} : {});
    localStorage.setItem('dmPosts', JSON.stringify(store));
    return store[id].error ? 'FEHLER ' + store[id].error : (store[id].blocked ? 'BLOCKIERT ' + store[id].blocked : 'ok ' + store[id].pairs.length);
}

function dmCaptureResult() {
    return {captured: new Date().toISOString(), posts: JSON.parse(localStorage.getItem('dmPosts') || '{}')};
}
