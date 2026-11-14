/**
 * Datei: servers.js
 * Zweck: Clientseitige Logik für die Serververwaltung
 * Eingesetzt in: servers.php
 *
 * Funktionen:
 * - Automatische Übernahme der DNS-IP in die API-IP, wenn Checkbox aktiviert ist
 * - Generierung eines zufälligen API-Tokens (256 Bit)
 * - Ein-/Ausblenden des Formulars zum Hinzufügen eines neuen Servers
 */

// === Button: "Neuen API-Token generieren" ===
function generateApiKey() {
    const array = new Uint8Array(32); // 256 Bit
    crypto.getRandomValues(array);
    const hex = Array.from(array).map(b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('api_token').value = hex;
}

// === Formular anzeigen (neuen Server hinzufügen) ===
function showAddForm() {
    document.getElementById('addFormContainer').style.display = 'block';
}

// === Formular ausblenden ===
function hideAddForm() {
    document.getElementById('addFormContainer').style.display = 'none';
}

// === Schaltet die Anzeige des Diagnosebereichs ein oder aus ===
function toggleServerDiagnostics() {
    const section = document.getElementById('serverDiagnosticsBlock');
    if (section) {
        section.classList.toggle('app-hidden');
    }
}

// === Ereignis: Wenn sich die DNS-IP ändert und Checkbox aktiv ist, API-IP automatisch mitsetzen ===
document.addEventListener('DOMContentLoaded', function () {
    // CSP (U2): Statuskarte und Token-Schaltflächen ohne Inline-Handler binden
    const statusCard = document.querySelector('[data-server-diagnostics-toggle]');
    if (statusCard) {
        statusCard.addEventListener('click', toggleServerDiagnostics);
    }
    // Aufruf über window zum Klickzeitpunkt wie zuvor beim Inline-Handler (server_add_form.js kann die Funktion ersetzen)
    document.querySelectorAll('.js-generate-api-key').forEach(button => {
        button.addEventListener('click', () => window.generateApiKey());
    });

    // BIND-Reload: Bootstrap-Modal initialisieren
    document.querySelectorAll('.btn-bind-reload').forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            const serverId = this.dataset.serverId;
            const modal = new bootstrap.Modal(document.getElementById('reloadConfirmModal'));
            document.getElementById('reload_server_id').value = serverId;
            modal.show();
        });
    });
});

