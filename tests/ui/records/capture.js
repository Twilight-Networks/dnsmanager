/**
 * Datei: tests/ui/records/capture.js
 * Zweck: Erfasst für die Fälle der Record-Typ-Matrix die POST-Daten, die die bestehenden Formularskripte mit JS
 *        tatsächlich senden (U4-Core). Lädt je Fall die echte Seite der Testkopie in einem iframe (gleicher Ursprung),
 *        führt die Bedienschritte aus (Wert setzen, input/change auslösen), registriert danach einen eigenen
 *        submit-Listener (läuft nach den vorhandenen Handlern), liest die Formulardaten und verhindert das Absenden.
 *        alert() der Formularskripte wird protokolliert; hat ein Handler das Absenden verhindert, gilt der Fall als
 *        „durch JS blockiert“.
 * Aufruf: im eingebauten Browser auf einer Seite der Testkopie (Serve-Modus, admin) mit
 *         `await dmCapture(<Ausgabe von matrix.php --cases-json>)`; das Ergebnis als js_posts.json speichern.
 */
async function dmCapture(cases) {
    const posts = {};
    const load = (url) => new Promise((resolve) => {
        const f = document.createElement('iframe');
        f.style.cssText = 'position:fixed;left:-3000px;top:0;width:1400px;height:900px';
        f.addEventListener('load', () => resolve(f), {once: true});
        f.src = '/dnsmanager-ui/' + url;
        document.body.appendChild(f);
    });
    for (const [id, c] of Object.entries(cases)) {
        const frame = await load(c.page);
        const w = frame.contentWindow;
        const d = frame.contentDocument;
        await new Promise((r) => setTimeout(r, 150));
        const alerts = [];
        w.alert = (msg) => alerts.push(String(msg));
        const form = d.getElementById(c.form);
        if (!form) { posts[id] = {error: 'Formular nicht gefunden'}; frame.remove(); continue; }
        for (const [sel, value] of c.ops) {
            const el = form.querySelector(sel) || d.querySelector(sel);
            if (!el) { (posts[id] = posts[id] || {}).missing = (posts[id]?.missing || []).concat(sel); continue; }
            el.value = value;
            el.dispatchEvent(new w.Event('input', {bubbles: true}));
            el.dispatchEvent(new w.Event('change', {bubbles: true}));
        }
        let captured = null;
        form.addEventListener('submit', (e) => {
            const pairs = [];
            for (const [n, v] of new w.FormData(form).entries()) {
                pairs.push([n, typeof v === 'string' ? v : '']);
            }
            captured = {pairs, blocked: e.defaultPrevented ? (alerts.join(' | ') || 'preventDefault') : null};
            e.preventDefault();
        });
        form.requestSubmit();
        await new Promise((r) => setTimeout(r, 50));
        posts[id] = Object.assign(posts[id] || {}, captured || {error: 'kein submit'}, alerts.length ? {alerts} : {});
        frame.remove();
    }
    return posts;
}
