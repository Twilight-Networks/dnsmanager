/**
 * Datei: zones.js
 * Zweck: JavaScript-Funktionen zur Steuerung dynamischer Elemente auf der Seite zones.php.
 *
 * Beschreibung:
 * Diese Datei enthält clientseitige Funktionen zur Interaktion mit der Zonenübersicht.
 * Aktuell implementiert: Steuerung der Sichtbarkeit des Diagnosebereichs bei Zonenfehlern.
 * Die Datei ist modular aufgebaut und für zukünftige Erweiterungen vorgesehen.
 *
 * Abhängigkeiten:
 * - HTML-Elemente auf zones.php mit definierten IDs und Bootstrap-Klassen.
 *
 * Sicherheit:
 * - Es findet keine externe Kommunikation oder dynamische Codeausführung statt.
 */

/**
 * Schaltet die Anzeige des Diagnosebereichs ein oder aus,
 * jedoch nur bei vorhandenem Fehler- oder Warnungsstatus.
 *
 * @returns {void}
 */
function toggleZoneDiagnostics() {
    const section = document.getElementById('zoneDiagnosticsBlock');
    const card = section?.closest('.card');
    const alert = card?.querySelector('.alert');

    if (alert && alert.classList.contains('alert-success')) {
        // Bei grünem Zustand (OK) keine Umschaltung vornehmen
        return;
    }

    if (section) {
        section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }
}
