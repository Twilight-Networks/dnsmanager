/**
 * Datei: assets/js/diff.js
 * Zweck: DIFF-Darstellung im Browser (U3, allgemeiner Baustein, noch ohne Record-Logik).
 *
 * - DmDiff.compare(): identische Regel wie ui_diff_compare() in inc/web/ui_components.php (gemeinsamer Anfang und
 *   gemeinsames Ende, zeichenweise nach Unicode-Codepunkten). Gleichheit prüft tests/ui/diff_cases.php.
 * - DmDiff.render(): baut den DIFF-Bereich aus Zeilenpaaren [gespeichert|null, neu|null]. Unveränderte Teile langer
 *   Werte werden in der Mitte eingeklappt („vollständig anzeigen“); der Änderungsbereich bleibt immer sichtbar.
 * - Progressive Verbesserung: [data-dm-diff] enthält die serverseitig vollständig gerenderte Fassung und die Daten
 *   (<script type="application/json" class="dm-diff-data">). Ohne JavaScript bleibt die vollständige Fassung.
 * - Nur DOM-Methoden (textContent, createElement); kein innerHTML mit Daten, kein eval (CSP, U2).
 */
const DmDiff = (function () {
    /** Unveränderte Abschnitte ab dieser Länge werden eingeklappt; sichtbar bleiben je KEEP Zeichen am Rand */
    const FOLD_MIN = 120;
    const KEEP = 40;

    function chars(text) {
        return Array.from(text);
    }

    function compare(oldText, newText) {
        const a = chars(oldText);
        const b = chars(newText);
        const la = a.length;
        const lb = b.length;
        let p = 0;
        while (p < la && p < lb && a[p] === b[p]) {
            p++;
        }
        let s = 0;
        while (s < la - p && s < lb - p && a[la - 1 - s] === b[lb - 1 - s]) {
            s++;
        }
        return {prefix: p, suffixOld: s, suffixNew: s, lenOld: la, lenNew: lb};
    }

    function format(template, n) {
        return String(template || '%d').replace('%d', String(n));
    }

    /** Unveränderten Abschnitt anhängen, bei Überlänge eingeklappt (Anfang … Ende) mit Umschalter */
    function appendPlain(parent, text, texts, fold, position) {
        const c = chars(text);
        if (!fold || c.length < FOLD_MIN) {
            parent.appendChild(document.createTextNode(text));
            return;
        }
        // Am Zeilenanfang nur das Ende zeigen, am Zeilenende nur den Anfang, dazwischen beide Ränder
        const head = position === 'start' ? '' : c.slice(0, KEEP).join('');
        const tail = position === 'end' ? '' : c.slice(c.length - KEEP).join('');
        const hidden = c.length - head.length - tail.length;
        const shortSpan = document.createElement('span');
        shortSpan.appendChild(document.createTextNode(head));
        const fold_ = document.createElement('span');
        fold_.className = 'dm-diff-fold';
        fold_.textContent = '… ' + format(texts.chars, hidden) + ' …';
        shortSpan.appendChild(fold_);
        shortSpan.appendChild(document.createTextNode(tail));
        const fullSpan = document.createElement('span');
        fullSpan.textContent = text;
        fullSpan.hidden = true;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dm-diff-toggle';
        btn.textContent = texts.showFull || '…';
        btn.setAttribute('aria-expanded', 'false');
        btn.addEventListener('click', function () {
            const expand = fullSpan.hidden;
            fullSpan.hidden = !expand;
            shortSpan.hidden = expand;
            btn.textContent = expand ? (texts.showLess || texts.showFull || '…') : (texts.showFull || '…');
            btn.setAttribute('aria-expanded', expand ? 'true' : 'false');
        });
        parent.appendChild(shortSpan);
        parent.appendChild(fullSpan);
        parent.appendChild(btn);
    }

    function line(kind, text, from, to, texts, fold) {
        const c = chars(text);
        const row = document.createElement('div');
        row.className = 'dm-diff-line dm-diff-' + kind;
        const mark = document.createElement('span');
        mark.className = 'dm-diff-mark';
        mark.setAttribute('aria-hidden', 'true');
        mark.textContent = kind === 'del' ? '−' : (kind === 'add' ? '+' : ' ');
        const body = document.createElement('span');
        body.className = 'dm-diff-text';
        const before = c.slice(0, from).join('');
        const changed = c.slice(from, to).join('');
        const after = c.slice(to).join('');
        if (to > from) {
            appendPlain(body, before, texts, fold, 'start');
            const m = document.createElement('mark');
            m.className = 'dm-diff-chg';
            m.textContent = changed;
            body.appendChild(m);
            appendPlain(body, after, texts, fold, 'end');
        } else {
            appendPlain(body, text, texts, fold, 'middle');
        }
        const len = document.createElement('span');
        len.className = 'dm-diff-len';
        len.textContent = format(texts.chars, c.length);
        row.appendChild(mark);
        row.appendChild(body);
        row.appendChild(len);
        return row;
    }

    /**
     * Zeilen für Paare [gespeichert|null, neu|null] erzeugen.
     * @returns {{lines: HTMLElement[], changed: number}}
     */
    function build(pairs, texts, fold) {
        const lines = [];
        let changed = 0;
        pairs.forEach(function (pair) {
            const oldText = pair[0];
            const newText = pair[1];
            if (oldText !== null && newText !== null && oldText === newText) {
                lines.push(line('same', oldText, 0, 0, texts, fold));
                return;
            }
            changed++;
            if (oldText !== null && newText !== null) {
                const c = compare(oldText, newText);
                lines.push(line('del', oldText, c.prefix, c.lenOld - c.suffixOld, texts, fold));
                lines.push(line('add', newText, c.prefix, c.lenNew - c.suffixNew, texts, fold));
            } else if (oldText !== null) {
                lines.push(line('del', oldText, 0, 0, texts, fold));
            } else if (newText !== null) {
                lines.push(line('add', newText, 0, 0, texts, fold));
            }
        });
        return {lines: lines, changed: changed};
    }

    /** DIFF-Bereich (Element mit .dm-diff-summary und .dm-diff-lines) neu aufbauen */
    function render(container, pairs, texts, options) {
        const fold = !options || options.fold !== false;
        const result = build(pairs, texts || {}, fold);
        const summary = container.querySelector('.dm-diff-summary');
        const target = container.querySelector('.dm-diff-lines');
        if (summary) {
            summary.textContent = result.changed === 0 ? (texts.summaryNone || '') : format(texts.summaryChanged, result.changed);
        }
        if (target) {
            target.replaceChildren.apply(target, result.lines);
        }
        return result;
    }

    /** Serverseitig gerenderte DIFF-Bereiche mit JavaScript-Darstellung (Einklappen) versehen */
    function enhanceAll(root) {
        (root || document).querySelectorAll('[data-dm-diff]').forEach(function (el) {
            const dataEl = el.querySelector('script.dm-diff-data');
            if (!dataEl) {
                return;
            }
            try {
                const data = JSON.parse(dataEl.textContent);
                render(el, data.pairs || [], data.texts || {}, {fold: true});
            } catch (e) {
                // Ungültige Daten: serverseitige Fassung bleibt stehen
            }
        });
    }

    return {compare: compare, build: build, render: render, enhanceAll: enhanceAll, FOLD_MIN: FOLD_MIN, KEEP: KEEP};
})();

document.addEventListener('DOMContentLoaded', function () {
    DmDiff.enhanceAll(document);
});
