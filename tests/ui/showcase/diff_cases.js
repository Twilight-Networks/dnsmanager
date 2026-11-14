/**
 * Datei: tests/ui/showcase/diff_cases.js
 * Zweck: Vergleicht für jeden Fall von diff_cases.php die JavaScript-DIFF-Logik (diff.js) mit der serverseitigen
 *        Fassung (ui_diff_compare, ui_diff) und prüft das Einklappen langer Werte. Ergebnis in #dmDiffResults.
 *        Serverfassung wird beim Parsen erfasst, verglichen wird nach dem Laden (nach diff.js); nur Testwerkzeug.
 */
const dmLineInfo = function (el) {
    const mark = el.querySelector('mark');
    return {
        kind: el.className.replace('dm-diff-line ', ''),
        text: el.querySelector('.dm-diff-text').textContent,
        mark: mark ? mark.textContent : null,
        len: el.querySelector('.dm-diff-len').textContent,
    };
};
// Serverseitige Fassung sofort beim Parsen erfassen (dieses Skript steht hinter den Fällen), bevor diff.js sie
// beim DOMContentLoaded durch die JavaScript-Darstellung ersetzt
const dmServerLines = {};
document.querySelectorAll('[data-case]').forEach(function (el) {
    dmServerLines[el.getAttribute('data-case')] = Array.from(el.querySelectorAll('.dm-diff-line')).map(dmLineInfo);
});

window.addEventListener('load', function () {
    const out = document.getElementById('dmDiffResults');
    const data = JSON.parse(document.getElementById('dmDiffCases').textContent);
    const results = [];
    const ok = function (cond, id, msg) { results.push({ok: !!cond, id: id, msg: msg}); };
    const lineInfo = dmLineInfo;

    data.cases.forEach(function (c) {
        // 1. Vergleichsregel identisch
        if (c.compare) {
            const js = DmDiff.compare(c.old, c.new);
            ok(JSON.stringify(js) === JSON.stringify(c.compare), c.id + ' / Regel', JSON.stringify(js) + (JSON.stringify(js) === JSON.stringify(c.compare) ? '' : ' ≠ PHP ' + JSON.stringify(c.compare)));
        }
        // 2. Unverkürzte JavaScript-Zeilen = serverseitig gerenderte Zeilen (Art, Text, Hervorhebung, Zeichenzahl)
        const built = DmDiff.build([[c.old, c.new]], data.texts, false);
        const serverLines = dmServerLines[c.id] || [];
        const jsLines = built.lines.map(lineInfo);
        ok(serverLines.length > 0 && JSON.stringify(serverLines) === JSON.stringify(jsLines), c.id + ' / Darstellung',
            jsLines.map(function (l) { return l.kind + (l.mark !== null ? '[' + l.mark + ']' : ''); }).join(' '));
        // 3. Werte unverändert (kein HTML-Interpretieren, keine Verluste)
        const texts = jsLines.map(function (l) { return l.text; });
        const expect = [];
        if (c.old !== null && c.new !== null && c.old === c.new) { expect.push(c.old); } else {
            if (c.old !== null) { expect.push(c.old); }
            if (c.new !== null) { expect.push(c.new); }
        }
        ok(JSON.stringify(texts) === JSON.stringify(expect), c.id + ' / Inhalt', 'Texte unverändert');
    });

    // 4. Einklappen (eingeklappte Darstellung auf der Seite, mit JavaScript)
    const longCase = document.querySelector('[data-case^="D15"] [data-dm-diff]');
    const addLine = longCase.querySelector('.dm-diff-add');
    const visible = Array.from(addLine.querySelector('.dm-diff-text').childNodes).filter(function (n) { return !n.hidden; })
        .map(function (n) { return n.textContent; }).join('');
    const c15 = data.cases.find(function (c) { return c.id.indexOf('D15') === 0; });
    ok(visible.indexOf('XYZ') >= 0 && visible.indexOf('…') >= 0 && visible.length < c15.new.length, 'F01 Einklappen',
        'Änderung sichtbar, Mitte eingeklappt (' + visible.length + ' statt ' + Array.from(c15.new).length + ' Zeichen sichtbar)');
    const toggle = addLine.querySelector('.dm-diff-toggle');
    toggle.click();
    const expanded = Array.from(addLine.querySelector('.dm-diff-text').childNodes).filter(function (n) { return !n.hidden && n.nodeName !== 'BUTTON'; })
        .map(function (n) { return n.textContent; }).join('');
    ok(expanded === c15.new && toggle.getAttribute('aria-expanded') === 'true', 'F02 vollständig anzeigen', 'vollständiger Wert nach Klick, aria-expanded=true');
    toggle.click();
    ok(toggle.getAttribute('aria-expanded') === 'false', 'F03 wieder einklappen', 'aria-expanded=false');
    const sameLong = document.querySelector('[data-case^="D16"] [data-dm-diff] .dm-diff-same');
    ok(sameLong && sameLong.querySelector('.dm-diff-toggle') !== null, 'F04 langer unveränderter Wert', 'eingeklappt mit Umschalter');
    const shortCase = document.querySelector('[data-case^="D04"] [data-dm-diff]');
    ok(shortCase.querySelector('.dm-diff-toggle') === null, 'F05 kurzer Wert', 'nicht eingeklappt');
    const xss = document.querySelector('[data-case^="D17"] [data-dm-diff]');
    ok(xss.querySelectorAll('script').length === 1 && xss.querySelector('script').classList.contains('dm-diff-data'), 'F06 HTML im Wert', 'nur der JSON-Datenblock, kein ausführbares Element');

    const pass = results.filter(function (r) { return r.ok; }).length;
    const fail = results.length - pass;
    out.setAttribute('data-pass', String(pass));
    out.setAttribute('data-fail', String(fail));
    const body = out.querySelector('.dm-card-body');
    body.textContent = '';
    const head = document.createElement('div');
    head.className = 'fw-bold mb-2';
    head.textContent = 'DIFF-Prüfung: ' + pass + '/' + results.length + ' bestanden';
    body.appendChild(head);
    const list = document.createElement('ul');
    list.className = 'mb-0 small dm-mono';
    results.forEach(function (r) {
        const li = document.createElement('li');
        li.textContent = (r.ok ? 'PASS ' : 'FAIL ') + r.id + ' – ' + r.msg;
        list.appendChild(li);
    });
    body.appendChild(list);
});
