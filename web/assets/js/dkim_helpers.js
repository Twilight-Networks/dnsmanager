/**
 * Datei: dkim_helpers.js
 * Zweck: Clientseitige Verarbeitung einer hochgeladenen OpenDKIM-Konfigurationsdatei.
 * - Extrahiert Selector, Domain und Public Key.
 * - Füllt die zugehörigen Formularfelder automatisch aus.
 */

// Diese JavaScript-Funktionen sind für den Umgang mit einer hochgeladenen DKIM-Datei verantwortlich.

/**
 * Extrahiert den Selector aus dem DKIM-Konfigurationsinhalt.
 *
 * @param {string} content Der Inhalt der DKIM-Konfigurationsdatei.
 * @return {string} Der extrahierte DKIM-Selector.
 */
function extractSelector(content) {
    const cleanContent = content.replace(/\s+/g, ' ').trim(); // Entferne unnötige Leerzeichen
    const match = cleanContent.match(/([^\s]+?)\._domainkey/);
    return match ? match[1] : ''; // Gibt den gefundenen Selector zurück oder einen leeren String.
}

/**
 * Extrahiert den Public Key aus dem DKIM-Konfigurationsinhalt.
 *
 * @param {string} content Der Inhalt der DKIM-Konfigurationsdatei.
 * @return {string} Der extrahierte Public Key.
 */
function extractPublicKey(content) {
    const matches = [...content.matchAll(/"([^"]+)"/g)].map(m => m[1]);
    if (matches.length === 0) return '';

    const joined = matches.join('');

    const match = joined.match(/p=([A-Za-z0-9+/=]+)/);
    return match ? match[1].trim() : '';
}

/**
 * Verarbeitet den DKIM-Datei-Upload und extrahiert die DKIM-Daten (Selector, Domain, Public Key).
 *
 * @param {HTMLInputElement} input Das Datei-Eingabefeld.
 */
function handleDKIMFileUpload(input) {
    const file = input.files[0];
    if (!file) return; // Keine Datei ausgewählt

    const reader = new FileReader();
    reader.onload = function(e) {
        const content = e.target.result;
        console.log("Dateiinhalt: ", content); // Debugging: Ausgabe des gesamten Dateiinhalts

        // Extrahieren der DKIM-Daten
        const selector = extractSelector(content);
        const key = extractPublicKey(content);

        // Felder im Formular mit den extrahierten Werten ausfüllen
        document.getElementById('dkim_selector').value = selector;
        document.getElementById('dkim_key').value = key; // Public Key setzen

        // Optional: Key Type (k=)
        const keyTypeMatch = content.match(/\bk=(rsa|ed25519)/i);
        if (keyTypeMatch && document.getElementById('dkim_key_type')) {
            document.getElementById('dkim_key_type').value = keyTypeMatch[1].toLowerCase();
        }

        // Optional: Hash-Algorithmen (h=)
        const hashMatch = content.match(/\bh=([a-z0-9:+\-]+)/i);
        if (hashMatch && document.getElementById('dkim_hash_algos')) {
            document.getElementById('dkim_hash_algos').value = hashMatch[1];
        }

        // Optional: Flags (t=)
        const flagsMatch = content.match(/\bt=([a-z]+)/i);
        if (flagsMatch && document.getElementById('dkim_flags')) {
            document.getElementById('dkim_flags').value = flagsMatch[1];
        }
    };
    reader.readAsText(file); // Dateiinhalt lesen
}
