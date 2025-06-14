/**
 * Datei: assets/js/dyndns.js
 * Zweck: Dynamische UI-Logik für die DynDNS-Verwaltungsseite
 *
 * Beschreibung:
 * Dieses Skript stellt Hilfsfunktionen für die Benutzeroberfläche rund um DynDNS bereit.
 * Derzeit ermöglicht es das komfortable Kopieren der DynDNS-Update-URL in die Zwischenablage
 * mit visueller Rückmeldung. Weitere Funktionen können in Zukunft ergänzt werden.
 *
 * Funktionen:
 * - Kopieren des Inhalts eines Eingabefelds (z. B. DynDNS-URL) per Klick
 * - Temporäre Rückmeldung im Eingabefeld („✔️ Kopiert!“)
 * - Fallback mit Fehlermeldung bei Problemen
 *
 * Anforderungen:
 * - Ein HTML-Eingabefeld mit der ID „dyndnsUrl“ muss vorhanden sein
 * - Die Seite muss über HTTPS laufen (für clipboard-Zugriff)
 */

/**
 * Kopiert die DynDNS-Update-URL aus dem Eingabefeld in die Zwischenablage.
 * Ersetzt den Text kurzzeitig durch „✔️ Kopiert!“ als visuelle Bestätigung.
 */
function copyDynDnsUrl() {
    const input = document.getElementById("dyndnsUrl");
    navigator.clipboard.writeText(input.value)
        .then(() => {
            const oldText = input.value;
            input.value = "✔️ " + lang('clipboard_success');
            setTimeout(() => { input.value = oldText; }, 1000);
        })
        .catch(() => {
            alert(lang('clipboard_failed'));
        });
}
