# DNS Manager – UI-Leitfaden

Stand: 14.11.2026 (U0). Verbindliche Regeln für die UI-Modernisierung (Pakete U1–U6) auf Basis des freigegebenen Konzepts
(Entscheidungen E1–E10) und der Präzisierungen aus dem Review. Ausgangslage, Screenshots und Befunde:
[`docs/ui/U0_BESTANDSAUFNAHME.md`](ui/U0_BESTANDSAUFNAHME.md); Zugriffsrechte: [`docs/UI_ROLE_MATRIX.md`](UI_ROLE_MATRIX.md);
Regressionsbasis: [`tests/ui/README.md`](../tests/ui/README.md).

## 1. Unverrückbare Grenzen

1. **Core-Services und ihre Schnittstellen bleiben unverändert.**
   - Seiten lesen ausschließlich über die vorhandenen Read-Services, Änderungen laufen ausschließlich über die vorhandenen Actions.
   - Braucht eine Seite zusätzliche Daten, wird das als eigener, freizugebender Core-PR vorgelegt.
2. **Web-Verträge bleiben stabil:**
   - Endpunkte, Methoden, Feldnamen, CSRF-Token;
   - Weiterleitungen, Toast-Texte, Logzeilen, HTTP-Status.

   Nachweis je PR mit der Golden-Basis (`tests/ui`). Jede Abweichung braucht eine Fall-ID, eine Begründung und eine Freigabe (Ausnahmedatei).
3. **Autorisierung bleibt vollständig bei den bestehenden Mechanismen:**
   - Session-Revalidierung, `requireRole()`, `ZoneAccess` und die Rollenprüfung der Core-Services unter Sperre.
   - Die Oberfläche blendet Bedienelemente nur aus Gründen der Darstellung aus oder ein. Sie ist **nie** Sicherheitsgrenze; jede Action prüft weiterhin selbst.
4. Keine externen Ressourcen: kein CDN, keine Web-Fonts von Dritten, kein Tracking. Alle Assets lokal.
5. Kein MCP, keine Schemaänderung, keine Konfigurationsänderung auf ns1/ns2 und kein Deployment ohne gesonderte Freigabe.

## 2. Technik (E1)

- Serverseitig gerendertes PHP, Bootstrap 5.3 (lokal, `web/assets/bootstrap`), Vanilla-JavaScript.
- Kein SPA-Framework, kein Template-Framework, keine Build-Kette.
- Seitenbezogene Skripte und Styles nur über `AssetRegistry`; keine Skripte in Templates.
- Bootstrap-Version und -Dateien nur gemeinsam aktualisieren. Lizenzhinweise in `LICENSES/` pflegen.

## 3. Progressive Enhancement – Rückfall ohne JavaScript (Präzisierung 1)

Die Anwendung muss **ohne JavaScript grundsätzlich bedienbar** bleiben.

- **Jedes Modal und jedes Offcanvas hat einen serverseitigen Rückfall:** einen normalen Link bzw. ein normales Formular, das ohne JS dieselbe Seite mit Parameter aufruft (heute z. B. `?add_new=1`, `?edit_id=`) und das Formular serverseitig rendert.
  - JS öffnet stattdessen das Modal.
  - Ziel-Action und Felder sind in beiden Fällen identisch.
- **Formulare senden ohne JS fachlich korrekte Daten.** JS darf Felder ein- oder ausblenden, vorbelegen oder umformatieren, aber kein Feld voraussetzen, das ohne JS falsch belegt ist.
  - Gegenbeispiel heute: verstecktes `is_dkim=1` im Record-Formular, Befund U0-B1.
- **Löschbestätigung:** Ohne JS bleibt ein ausdrücklicher Bestätigungsschritt erhalten (eigene Bestätigungsansicht oder Formularfeld). Ein Modal ist nur die Komfortvariante.
- **Clientseitige Prüfungen** sind Komfort. Die serverseitige Validierung der Core-Services bleibt maßgeblich und liefert die angezeigten Fehlermeldungen.
- **Nachweis:** Die Golden-Basis arbeitet ohne JavaScript (Browser-Semantik). Die Sichtprüfung erfolgt zusätzlich mit abgeschaltetem JavaScript.

## 4. Content-Security-Policy (E5, Präzisierung 2)

**Zielrichtlinie:** ohne `unsafe-inline`, ohne `unsafe-eval`.

```
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self';
connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'
```

**Regeln:**
- keine Inline-Skripte, keine Inline-Ereignishandler (`onclick=` …), keine `style=`-Attribute, keine `javascript:`-URLs;
- kein `eval`/`new Function`/String-`setTimeout`.
- **Seitendaten für JS** stehen in `<script type="application/json" id="…">` und werden mit `JSON.parse` gelesen. Das wird nicht ausgeführt und ist erlaubt. Dazu gehören auch die Sprachtexte, nur seitenbezogen und nicht mehr das komplette `$LANG`.
- **Dynamische Inline-Styles von Bootstrap/Popper:** Popper (Dropdowns, Tooltips, Popovers) setzt Positionen per CSSOM (`element.style`). Das unterliegt nach CSP nicht `style-src`; erst ein `style`-Attribut im Markup oder ein `<style>`-Element würde blockiert.
  - **In U2 wird das konkret geprüft:** jede verwendete Bootstrap-Komponente (Modal, Offcanvas, Dropdown, Tooltip, Toast, Collapse) unter `Report-Only` mit Verstoß-Protokoll.
  - Komponenten, die dennoch Verstöße erzeugen, werden ersetzt oder nicht verwendet. Eine Lockerung der Richtlinie gibt es nicht.
- **Report-Only muss Verstöße nachvollziehbar sichtbar machen.**
  - Zuerst `Content-Security-Policy-Report-Only` mit `report-uri` auf einen eigenen, CSRF-freien, nur protokollierenden Endpunkt der Anwendung (kein Fremddienst).
  - Je Verstoß eine Logzeile mit Zeitpunkt, Seite (ohne Query-Werte), Direktive, blockierter Quelle (gekürzt) und Quelldatei/Zeile. Eine Benutzer-ID entfällt: Der Endpunkt arbeitet freigabegemäß ohne Anwendungssession (U2, R1).
  - Ratenbegrenzung und Größenlimit gegen Log-Flutung.
  - Auswertung mit Fall-IDs im PR. Erst nach einem Lauf ohne Verstöße folgt die Durchsetzung (U6).
- Die bestehenden Header (`nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`) bleiben.

## 5. Layout, Responsivität, Farbmodus, Symbole (E3, E4, E6, E10)

- `<meta name="viewport" content="width=device-width, initial-scale=1">` auf allen Seiten, auch auf der Anmeldeseite.
- **Seitenleiste:** auf großen Bildschirmen fest; unterhalb der Tablet-Breite als Offcanvas mit Menü-Schaltfläche.
  - Ohne JS bleibt die Navigation erreichbar, z. B. als ausklappbare Liste oberhalb des Inhalts.
- **Tabellen:** auf schmalen Bildschirmen horizontal scrollbar (`.table-responsive`) oder als Kartenansicht. Kein abgeschnittener Inhalt.
- **Farbmodus:** hell/dunkel über Bootstrap-Farbmodi (`data-bs-theme`).
  - Standard ist die Systemvorgabe (`prefers-color-scheme`). Ein Umschalter speichert die Wahl nur im Browser (`localStorage`), ohne Serveränderung.
  - Ohne JS gilt die Systemvorgabe.
- **Symbole:** Bootstrap Icons, lokal als SVG-Sprite (MIT-Lizenz). Symbole nie als einziger Bedeutungsträger, immer mit sichtbarem Text oder `aria-label`. Keine Emojis als Bedienelemente.
- **Branding:** Twilight-Networks-Logo und Farbwelt bleiben; Farben als CSS-Variablen (Bootstrap-Variablen überschreiben), keine verstreuten Einzelwerte.

## 6. Bausteine und Muster (E2, E9)

- **Wiederverwendbare Template-Bausteine** in `web/templates/` statt kopierter Markup-Blöcke:
  - Seitenkopf mit Titel und Primäraktion;
  - Datentabelle mit Leerzustand;
  - Formularfeld (Label mit `for`, Hilfetext, Fehlermeldung);
  - Bestätigungsdialog mit Rückfall;
  - Statusanzeige.
- **Bearbeitungsmuster (E2):**
  - Kleine Formulare (Benutzer, Server, DynDNS, Passwort) als Modal.
  - Große Formulare (Zone, Record einschließlich DKIM/SRV/CAA/NAPTR/URI) in einem einheitlichen Bereich bzw. Offcanvas statt in der Tabellenzeile.
  - Rückfall jeweils nach Abschnitt 3.
- **Meldungen:** Der Toast-Vertrag der Actions bleibt (Session-Toasts Erfolg/Warnung/Fehler). Die Darstellung darf sich ändern, die Texte nicht ohne Freigabe.
- **Fehlerseiten:** 403/404/405 dürfen ein einheitliches Layout erhalten. HTTP-Status und Zugriffsentscheidung bleiben unverändert.
- **Ausgabe:**
  - Jede dynamische Ausgabe mit `htmlspecialchars` (bzw. einem schlanken Helfer);
  - keine HTML-Erzeugung per `echo`-Zeichenkette in Seiten, sondern Template-Markup.
  - Werte, die `null` sein können, vor der Ausgabe in Zeichenketten überführen (Befund U0-B2).

## 7. Berechtigungsdarstellung (E9, Präzisierung 4)

- Die Anzeige von Bedienelementen nach Rolle ist **reine Präsentationslogik**.
- Grundlage ist der bereits revalidierte Akteur des Requests, über einen kleinen Anzeige-Helfer statt verstreuter Abfragen von `$_SESSION['role']`.
- Es entsteht keine eigene Rechte- oder Regelbasis in der Oberfläche. Die Autorisierung bleibt bei `requireRole()`, `ZoneAccess`, der Session-Revalidierung und den Core-Services.
- Die Oberfläche soll **keine Bedienelemente anzeigen, deren Action für die Rolle stets abgelehnt wird**. Gegenbeispiel heute: Befund U0-B4. Eine Anzeige ersetzt aber nie die Prüfung der Action.

## 8. Listen (E7, Präzisierung 3)

- Suche, Filter und Sortierung **clientseitig** über die bereits vollständig gelieferten Daten. Ohne JS bleibt die vollständige Liste sichtbar.
- Keine Pagination-Infrastruktur ohne nachgewiesenen Bedarf (Messung der Datenmengen). Serverseitige Paginierung erst nach gesonderter Freigabe und als Core-PR.

## 9. Barrierefreiheit

- Jedes Formularfeld hat ein sichtbares `<label for>` bzw. `aria-label`; Pflichtfelder und Fehler sind auch ohne Farbe erkennbar.
- Tastaturbedienung vollständig, sichtbarer Fokus, logische Reihenfolge; Modals mit Fokusfalle und Rückgabe des Fokus.
- Kontrast nach WCAG 2.1 AA in beiden Farbmodi; Landmarken (`header`, `nav`, `main`); `lang`-Attribut der aktiven Sprache.

## 10. Mehrsprachigkeit

- Alle sichtbaren Texte über `$LANG`; neue Schlüssel immer in `de`, `en` und `es` zugleich.
- Ein Prüfskript (ab U5) stellt identische Schlüsselmengen sicher; fehlende Schlüssel (F2) werden ergänzt.

## 11. Definition of Done je UI-PR

1. Golden-Vergleich gegen die Basis `tests/ui/baseline/golden-1e37515.json`: keine Vertragsabweichung außer freigegebenen, mit Fall-ID begründeten Ausnahmen.
2. Keine neuen PHP-Meldungen (Feld `problems`); bestehende werden in ihrem Paket behoben.
3. Sichtprüfung durch Thomas: Desktop und schmale Ansicht, hell und dunkel, mit und ohne JavaScript.
4. Ab U2: keine CSP-Verstöße im Report-Only-Protokoll für die geänderten Seiten.
5. Screenshots des neuen Stands, Abschlussbericht wie bisher (Basis-/Head-Commit, Tree, Dateiliste, Tests, Abweichungen).
