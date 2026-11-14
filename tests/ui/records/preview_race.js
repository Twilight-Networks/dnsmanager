/**
 * Datei: tests/ui/records/preview_race.js
 * Zweck: Wiederholbarer Browsertest der Live-Vorschau (U4-UI, U4U-R1) im Serve-Modus (UIG_RECORDS_MATRIX=1, unter
 *        /__ui/preview_race.js). Auf pages/records.php?zone_id=1&edit_id=103 (SRV) laden und
 *        `await dmPreviewRaceTest()` ausführen. fetch wird nur für die Dauer eines Tests umhüllt: Antworten werden
 *        gezielt verzögert und ein Abbruch (AbortController) wird ignoriert, damit allein die Folgenummern-Logik in
 *        record_editor.js wirkt.
 *        T1 Entprellung: vier schnelle Eingaben → genau eine Anfrage, DIFF zeigt die letzte Eingabe.
 *        T2 (U4U-R1): alte Antwort trifft nach einer neuen Eingabe, aber vor Ablauf der 300-ms-Entprellung ein → DIFF
 *           unverändert, Pending-Status bleibt; danach zeigt der DIFF die neue Eingabe.
 *        T3 vertauschte Antwortreihenfolge (3, 2, 1) → angezeigt bleibt die neueste Eingabe.
 */
async function dmPreviewRaceTest() {
    const form = document.querySelector('form[data-dm-record-form]');
    const port = form.querySelector('[name=srv_port]');
    const pv = document.querySelector('[data-dm-record-preview]');
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const shown = () => Array.from(pv.querySelectorAll('.dm-diff-add .dm-diff-text, .dm-diff-same .dm-diff-text')).map((e) => e.textContent);
    const type = (v) => { port.value = v; port.dispatchEvent(new Event('input', {bubbles: true})); };
    const orig = window.fetch;
    const t0 = () => Math.round(performance.now());
    const wrap = (delays, log) => {
        let n = 0;
        window.fetch = function (url, opts) {
            const my = ++n;
            const o = Object.assign({}, opts);
            delete o.signal; // Abbruch ignorieren: nur die Folgenummer schützt vor überholten Antworten
            log.push({ev: 'req' + my, t: t0(), seq: opts.body.get('preview_seq'), port: opts.body.get('srv_port')});
            return orig.call(window, url, o).then((r) => new Promise((res) => setTimeout(() => { log.push({ev: 'res' + my, t: t0()}); res(r); }, delays[my - 1] ?? 0)));
        };
        return () => n;
    };
    const results = {};
    try {
        // T1: Entprellung
        let log = [];
        let count = wrap([0], log);
        for (const v of ['5', '50', '506', '5061']) { type(v); await wait(50); }
        await wait(900);
        results.T1 = {pass: count() === 1 && shown().some((l) => l.includes(' 5061 ')), requests: count(), shown: shown()};

        // T2: alte Antwort während der Entprellung der neuen Eingabe
        log = [];
        count = wrap([60, 0], log);
        const before = shown().join('|');
        type('6001');
        await wait(320);                 // Entprellung abgelaufen: Anfrage 1 (Port 6001) läuft, Antwort frühestens nach 60 ms
        type('6002');                    // neue Eingabe vor Eintreffen der alten Antwort
        const typed2 = t0();
        // warten, bis die alte Antwort eingetroffen ist (höchstens bis kurz vor Ablauf der neuen Entprellung)
        while (!log.find((e) => e.ev === 'res1') && t0() - typed2 < 270) { await wait(5); }
        await wait(30);                  // Antwortverarbeitung (json, Darstellung) abwarten, weiter vor Ablauf der Entprellung
        const res1 = log.find((e) => e.ev === 'res1');
        const midShown = shown().join('|');
        const midBusy = pv.getAttribute('aria-busy');
        const midReq2 = log.find((e) => e.ev === 'req2');
        await wait(700);
        const req2 = log.find((e) => e.ev === 'req2');
        results.T2 = {
            pass: !!res1 && res1.t > typed2 && !midReq2 && !!req2 && res1.t < req2.t && midShown === before && !midShown.includes(' 6001 ') && midBusy === 'true'
                && shown().some((l) => l.includes(' 6002 ')) && pv.getAttribute('aria-busy') === 'false',
            oldResponseAfterNewInput: !!res1 && res1.t > typed2, oldResponseBeforeNewRequest: !!res1 && !!req2 && res1.t < req2.t, diffUnchangedDuringDebounce: midShown === before,
            pendingDuringDebounce: midBusy, final: shown(), log,
        };

        // T3: vertauschte Antwortreihenfolge
        log = [];
        count = wrap([1500, 700, 50], log);
        type('7001'); await wait(400);
        type('7002'); await wait(400);
        type('7003'); await wait(2600);
        const order = log.filter((e) => e.ev.startsWith('res')).map((e) => e.ev).join(',');
        results.T3 = {pass: count() === 3 && order === 'res3,res2,res1' && shown().some((l) => l.includes(' 7003 ')) && pv.getAttribute('aria-busy') === 'false',
            order, final: shown()};
    } finally {
        window.fetch = orig;
    }
    results.pass = results.T1.pass && results.T2.pass && results.T3.pass;
    return results;
}
