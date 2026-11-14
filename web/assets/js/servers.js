/**
 * Datei: servers.js
 * Zweck: Hilfsfunktionen der Serververwaltung (servers.php).
 * Beschreibung:
 * - Schaltfläche „Generieren“: neuer API-Token (256 Bit, hexadezimal). server_add_form.js bzw.
 *   server_edit_form.js können window.generateApiKey ersetzen; der Aufruf erfolgt zum Klickzeitpunkt über window.
 * - U5b: Diagnosekarte (<details>) und BIND-Reload (Inline-Bestätigung) funktionieren ohne Skript; das bisherige
 *   Klickskript der Statuskarte und das Reload-Modal entfallen.
 * - CSP (U2): keine Inline-Handler.
 */

// === Button: "Neuen API-Token generieren" ===
function generateApiKey() {
    const array = new Uint8Array(32); // 256 Bit
    crypto.getRandomValues(array);
    const hex = Array.from(array).map(b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('api_token').value = hex;
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-generate-api-key').forEach(button => {
        button.addEventListener('click', () => window.generateApiKey());
    });
});
