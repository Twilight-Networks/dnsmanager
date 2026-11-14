/**
 * Datei: assets/js/record_editor.js
 * Zweck: Progressive Verbesserung der Record-Verwaltung (U4-UI) in pages/records.php.
 *
 * - Zonenauswahl: Wechsel öffnet die Zone sofort (ohne JavaScript über die Schaltfläche „Öffnen“).
 * - Anlage: Typwechsel blendet den Feldbereich des Typs ein und deaktiviert alle anderen (deaktivierte Felder werden
 *   nicht gesendet). Kein Zusammensetzen von Name oder Inhalt im Browser – das erledigt ausschließlich der Server.
 * - Live-Vorschau: Eingabeänderungen werden entprellt (VORSCHAU_MS) an actions/record_preview.php gesendet
 *   (FormData des Formulars inkl. CSRF). Schon jedes Eingabeereignis erhöht die Folgenummer und bricht eine laufende
 *   Anfrage ab; nur die Antwort zur neuesten Eingabe wird dargestellt (überholte Antworten werden verworfen, auch
 *   wenn sie noch während der Entprellung eintreffen).
 *   Während der Aktualisierung ist der Bereich als veraltet gekennzeichnet (aria-busy).
 * - Darstellung: DIFF über DmDiff.render() (U3), Fehler als Liste; ungültige Eingaben ergeben keinen DIFF.
 * - Nur DOM-Methoden (textContent, cloneNode); keine Inline-Handler, kein innerHTML mit Daten (CSP, U2).
 */
document.addEventListener('DOMContentLoaded', function () {
    const VORSCHAU_MS = 300;

    // Zonenauswahl
    document.querySelectorAll('[data-dm-zone-select]').forEach(function (form) {
        const select = form.querySelector('select');
        const open = form.querySelector('[data-dm-zone-open]');
        if (open) {
            open.hidden = true;
        }
        if (select) {
            select.addEventListener('change', function () {
                form.submit();
            });
        }
    });

    const form = document.querySelector('form[data-dm-record-form]');
    const preview = document.querySelector('[data-dm-record-preview]');
    if (!form || !preview) {
        return;
    }

    let texts = {};
    try {
        const el = document.getElementById('dmRecordEditorTexts');
        texts = el ? JSON.parse(el.textContent) : {};
    } catch (e) {
        texts = {};
    }

    // Ohne JavaScript dient „Vorschau“ als Absenden an die Seite; mit JavaScript aktualisiert sich die Vorschau selbst
    document.querySelectorAll('[data-dm-preview-button]').forEach(function (btn) {
        btn.hidden = true;
    });

    // Typwechsel bei der Anlage
    const typeSelect = form.querySelector('[data-dm-type-select]');
    function applyType() {
        if (!typeSelect) {
            return;
        }
        form.querySelectorAll('fieldset[data-dm-type]').forEach(function (fs) {
            const active = fs.getAttribute('data-dm-type') === typeSelect.value;
            fs.hidden = !active;
            fs.disabled = !active;
        });
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', applyType);
        applyType();
    }

    const status = preview.querySelector('[data-dm-preview-status]');
    const diffEl = preview.querySelector('[data-dm-diff]');
    const url = form.getAttribute('data-dm-preview-url');
    let seq = 0;
    let controller = null;
    let timer = null;

    function clear(el) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
    }

    function notice(title, items) {
        const tpl = document.getElementById('dmPreviewNoticeTpl');
        if (tpl && tpl.content.firstElementChild) {
            const n = tpl.content.firstElementChild.cloneNode(true);
            const strong = n.querySelector('strong');
            if (strong) {
                strong.textContent = title;
            }
            status.appendChild(n);
        } else {
            const p = document.createElement('p');
            p.textContent = title;
            status.appendChild(p);
        }
        if (items && items.length) {
            const ul = document.createElement('ul');
            ul.className = 'dm-record-preview-errors';
            items.forEach(function (text) {
                const li = document.createElement('li');
                li.textContent = String(text);
                ul.appendChild(li);
            });
            status.appendChild(ul);
        }
    }

    function hint(text) {
        const tpl = document.getElementById('dmPreviewHintTpl');
        const p = tpl && tpl.content.firstElementChild ? tpl.content.firstElementChild.cloneNode(true) : document.createElement('p');
        const span = p.querySelector('span') || p;
        span.textContent = String(text);
        status.appendChild(p);
    }

    function render(data) {
        clear(status);
        if (data && data.status === 'ok') {
            if (data.unchanged) {
                const p = document.createElement('p');
                p.className = 'dm-record-preview-same';
                p.textContent = texts.unchanged || '';
                status.appendChild(p);
            }
            (data.hints || []).forEach(hint);
            if (diffEl) {
                diffEl.hidden = false;
                DmDiff.render(diffEl, [[data.oldLine, data.newLine]], texts, {fold: true});
            }
            return;
        }
        if (diffEl) {
            diffEl.hidden = true;
        }
        if (data && data.status === 'invalid') {
            notice(texts.invalid || '', data.errors || []);
        } else if (data && data.status === 'denied') {
            notice(texts.denied || '', []);
        } else {
            notice(texts.failed || '', []);
        }
    }

    function setPending(pending) {
        preview.classList.toggle('dm-preview-pending', pending);
        preview.setAttribute('aria-busy', pending ? 'true' : 'false');
    }

    function request() {
        timer = null;
        // Folgenummer wurde bereits beim Eingabeereignis erhöht (schedule); diese Anfrage gehört zur neuesten Eingabe
        const mySeq = seq;
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        const body = new FormData(form);
        body.delete('preview');
        body.append('preview_seq', String(mySeq));
        setPending(true);
        fetch(url, {method: 'POST', body: body, credentials: 'same-origin', signal: controller ? controller.signal : undefined,
            headers: {'Accept': 'application/json'}})
            .then(function (res) {
                return res.json().catch(function () {
                    return {status: res.status === 403 ? 'denied' : 'failed'};
                });
            })
            .then(function (data) {
                // Nur die Antwort der neuesten Anfrage darstellen
                if (mySeq !== seq || (data && data.seq !== undefined && data.seq !== null && data.seq !== mySeq)) {
                    return;
                }
                setPending(false);
                render(data);
            })
            .catch(function (err) {
                if (mySeq !== seq || (err && err.name === 'AbortError')) {
                    return;
                }
                setPending(false);
                render(null);
            });
    }

    function schedule() {
        // U4U-R1: Jedes Eingabeereignis macht alle bisherigen Anfragen sofort ungültig (neue Folgenummer, laufende
        // Anfrage abbrechen). Eine ältere Antwort, die während der Entprellung eintrifft, aktualisiert daher weder den
        // DIFF noch beendet sie den Pending-Status. Die Anfrage selbst folgt erst nach der Entprellung.
        seq += 1;
        if (controller) {
            controller.abort();
            controller = null;
        }
        setPending(true);
        if (timer !== null) {
            clearTimeout(timer);
        }
        timer = setTimeout(request, VORSCHAU_MS);
    }

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
});
