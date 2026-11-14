# UI-Modernisierung U3 – Bausteine und Editor-Rahmen

Stand: 14.11.2026. Umsetzung von U3 gemäß A+-Integrationsplan, Abschnitte 1 und 2:
- wiederverwendbare UI-Bausteine;
- Editor-Rahmen (rechtes Offcanvas) und DIFF-Darstellung als allgemeine Bausteine, noch ohne Record-Logik;
- dazu die für U3 vorgesehenen Befunde U0-B9 (Löschen ohne JS ohne Bestätigung), U1-B1 (Toasts ohne JS unsichtbar) und U0-B6 (403 ohne Layout).

**Grenzen:**
- Designvorlage A+ verbindlich.
- Fachlogik, Berechtigungen, Formulare und Web-Verträge unverändert.
- Keine Änderung an Core, Actions, API, Agent oder Schema.
- Die Fachseiten werden erst in U4/U5 auf die Bausteine umgestellt; der Editor-Rahmen ist in U3 noch auf keiner Fachseite eingesetzt.

## 1. Bausteine

`web/inc/web/ui_components.php` enthält reine Darstellungsfunktionen.
- Alle Texte werden kodiert; Parameter mit Suffix `Html` sind vom Aufrufer verantwortetes Markup.
- Keine Datenbank, Session oder Berechtigung.
- Stile in `web/assets/css/components.css` (Präfix `dm-`, nur Design-Tokens `--dm-*`, hell und dunkel), Verhalten in `web/assets/js/components.js` und `web/assets/js/diff.js`.

| Baustein | Funktion | Verhalten ohne JavaScript |
|---|---|---|
| Seitenkopf | `ui_page_header()` | vollständig |
| Kennzahl-Karten | `ui_kpi()`, `ui_kpi_grid()` (4/2/1 Spalten je Breite) | vollständig |
| Status-Chips | `ui_chip()` (ok, warn, err, info, accent, neutral) | vollständig |
| Hinweisband | `ui_notice()` (ok, warn, err, info; Rolle `status`/`alert`) | vollständig |
| Datenkarte | `ui_card_open()` / `ui_card_close()` (auch ohne Innenabstand für Tabellen) | vollständig |
| Kompakte Tabelle | Klassen `dm-table`, `dm-group-row`, `dm-current`, `dm-col-select`, `dm-col-actions` | vollständig; die Auswahlspalte ist ausgeblendet |
| Auswahl und Massenaktionsleiste | `ui_select_all()`, `ui_select_row()` (Kästchen ohne `name`), `ui_bulk_bar()`. Die IDs werden kommagetrennt in `[data-dm-bulk-ids]` geschrieben (bestehender Vertrag `bulk_ids`, D7). | Leiste und Auswahl verborgen; Einzellöschen bleibt |
| Leerzustand | `ui_empty_state()` | vollständig |
| Löschbestätigung (U0-B9) | `ui_confirm_delete_button()` innerhalb des bestehenden Formulars | `<details>`: erster Klick öffnet „Wirklich löschen? / Endgültig löschen“, erst der zweite sendet. Mit JS öffnet der erste Klick das bestehende Bestätigungs-Modal. |
| Editor-Rahmen | `ui_editor_open()` / `ui_editor_close()` / `ui_editor_section()` | Wird von der Seite nur bei Editor-URL gerendert und ist dann bereits geöffnet (D2). Schließen, Abbrechen und Klick auf die Abdunkelung sind Links. Speichern ist die Schaltfläche des Seitenformulars über `form=`, Ziel und Felder bleiben unverändert. |
| DIFF-Darstellung | `ui_diff()`, `ui_diff_compare()` | immer vollständig; Zeichenzahl je Zeile, Hervorhebung des geänderten Bereichs |

**Editor mit JavaScript:**
- Fokus auf das erste Feld.
- Tab bleibt im Dialog.
- Escape schließt über den Schließen-Link.
- Die Seite dahinter scrollt nicht (`body:has(.dm-editor)`, ohne JS).
- Rolle `dialog`, `aria-modal`, Überschrift als `aria-labelledby`.
- Bei schmalen Bildschirmen volle Breite.

**DIFF-Regel:**
- Gemeinsamer Anfang und gemeinsames Ende werden zeichenweise nach Unicode-Codepunkten verglichen; der Rest dazwischen ist die Änderung.
- PHP (`ui_diff_compare`) und JavaScript (`DmDiff.compare`) wenden dieselbe Regel an.
- Mit JavaScript werden unveränderte Abschnitte ab 120 Zeichen eingeklappt; Zeilenanfang und Änderung bleiben sichtbar. Ein Umschalter je Zeile („vollständig anzeigen“) klappt alles auf.
- Kein `innerHTML` mit Daten.
- `aria-live="off"`, Zusammenfassung als Text („Geänderte Zeilen: 1“).
- Der Bezug zu Records (Vorschau-Endpunkt, Zeilenformat der Zonendatei) folgt in U4 (D3/D4/D8).

**Symbole:** 9 weitere unverändert aus `bootstrap-icons@1.11.3`: `check-circle`, `exclamation-triangle`, `x-circle`, `info-circle`, `inbox`, `x-lg`, `shield-lock`, `arrow-left`, `trash`.

**Sprachschlüssel:** 15 neue in de, en und es (Bestätigung, 403-Seite, Auswahl, DIFF, Hinweis „Speichern veröffentlicht noch nicht“).

## 2. Geänderte Dateien

| Datei | Änderung |
|---|---|
| `web/inc/web/ui_components.php` (neu) | Bausteine (siehe 1) |
| `web/assets/css/components.css` (neu) | Stile der Bausteine; Toasts ohne JS sichtbar (U1-B1); Fehlerseite |
| `web/assets/js/components.js` (neu) | Editor, Bestätigung über das Modal, Auswahl/Massenaktionen |
| `web/assets/js/diff.js` (neu) | DIFF im Browser (gleiche Regel wie PHP, Einklappen) |
| `web/templates/access_denied.php` (neu) | Fehlerseite „Zugriff verweigert“ im Layout |
| `web/inc/helpers.php` | `requireRole()`: bei unzureichender Rolle für Seitenaufrufe per GET (`/pages/`) die Fehlerseite im Layout. Status 403, Log und Zugriffsschutz unverändert; Actions und andere Anfragen behalten die Textantwort. |
| `web/common.php` | lädt `ui_components.php` (CRLF erhalten) |
| `web/templates/layout.php`, `layout_footer.php` | binden `components.css` bzw. `components.js` ein |
| `web/pages/{dyndns,records,servers,users,zones}.php` | 6 Löschformulare: Schaltfläche durch `ui_confirm_delete_button()` ersetzt. Formular, Felder, Ziel und Klasse `confirm-delete` unverändert. Die Massenlöschung der Records ist unverändert (U4). |
| `web/lang/de.php`, `en.php`, `es.php` | 15 Schlüssel |
| `web/assets/icons/bootstrap-icons-sprite.svg` | 9 Symbole |
| `tests/ui/showcase/*` (neu), `serve_router.php`, `run.sh`, `screenshots.ps1`, `README.md` | Bausteinübersicht und DIFF-Prüfseite unter `/__ui/` im Modus `serve` (nur Testkopie), Aufnahmen `u3-*` |
| `tests/ui/baseline/exceptions-u3.json` (neu) | freigegebene Vertragsabweichungen (siehe 3) |

## 3. Bewusste Verhaltensänderungen

| ID | Beschreibung |
|---|---|
| U3-A1 (U0-B9) | Löschen ohne JavaScript verlangt einen zweiten Klick („Endgültig löschen“). Mit JavaScript bleibt es beim Bestätigungs-Modal. Gesendete Felder und Ziel sind identisch. |
| U3-A2 (U1-B1) | Toasts sind ohne JavaScript sichtbar. Sie bleiben stehen, weil das Schließen JavaScript braucht; die Schließen-Schaltfläche ist dann ausgeblendet. |
| U3-A3 (U0-B6) | Seitenaufrufe per GET mit unzureichender Rolle zeigen statt reinem Text die Fehlerseite im Layout (Navigation, Rückweg zum Dashboard). Status 403, Log und Datenbank sind unverändert.<br>Betroffene Golden-Fälle, freigegeben in `exceptions-u3.json`, Schlüssel `contract` (Navigation, Veröffentlichen, Abmelden wie auf jeder Layout-Seite): `page/zoneadmin/{dyndns,dyndns_add,dyndns_edit1,servers,servers_add,servers_edit2,system_health,update}`.<br>Die 403-Antworten der Record-Seite fremder Zonen und alle Action-403 sind unverändert. |

## 4. Nachweise

**Umgebung:** ns1, PHP 8.5.4, MariaDB 11.8.6. Isolierte Testkopie des festen Commits über `tests/ui`, im Modus `serve` mit CSP Report-Only (U2); installierte Anwendung unberührt.

| Prüfung | Ergebnis |
|---|---|
| Statisch S01–S05 (130 PHP-Dateien) | 5/5: keine Inline-Handler, `style`, Inline-Skripte; Sprachschlüssel vollständig |
| Golden gegen U0-Basis mit `exceptions-u3.json` | Exit 0: 73 identisch, 37 informativ, **8 freigegeben (U3-A3), 0 Vertragsabweichungen**; Publish-Sperre frei |
| Golden gegen den Webstand von `main` mit Ausnahmen | Exit 0: 76 identisch, 34 informativ, 8 freigegeben, 0 Vertragsabweichungen |
| DIFF-Prüfseite (`__ui/diff_cases.php`) | **55/55**: Regel PHP = JS für 17 Fälle (identisch, nur neu/alt, Anfang/Mitte/Ende, Einfügung/Löschung, Wiederholung, leer, Umlaut/Emoji, kombinierende Zeichen, Sonderzeichen, Tabulatoren, lange Werte, HTML im Wert); Darstellung und Inhalt je Fall gleich; Einklappen, „vollständig anzeigen“, kein ausführbares Element |
| CSP Report-Only während aller Interaktivtests | 0 Verstöße, 0 PHP-Meldungen |

**Interaktiv** (eingebauter Browser):
- **Editor:**
  - 560 px rechts, Abdunkelung über die ganze Fläche;
  - Fokus im ersten Feld, Tab- und Umschalt+Tab-Falle, Escape schließt über den Link;
  - Seite dahinter ohne Scrollen;
  - Speichern über `form=`;
  - bei 375 px volle Breite, Fuß sichtbar, kein horizontales Scrollen.
- **Auswahl:** 2 von 3 Zeilen → Leiste „2 ausgewählt“, `bulk_ids` = `11,13`, Kopf-Kästchen unbestimmt; „alle“ → `11,12,13`; abwählen → Leiste verborgen, Feld leer.
- **Bestätigung mit JS:** Klick öffnet das Modal (Bausteinübersicht und Zonenseite); Abbrechen sendet nichts; Bestätigen sendet das unveränderte Formular.
- **Ohne JS (Port 1861x):**
  - Rückfrage zuerst verborgen, nach dem ersten Klick sichtbar, der zweite sendet.
  - Echter Ablauf in der isolierten Test-DB: Fixture-Benutzer `u0admin2` gelöscht, Erfolgs-Toast sichtbar.
  - DIFF vollständig, ohne Umschalter; Auswahlspalte und Massenleiste verborgen.
- **403:** zoneadmin auf `servers.php` → Status 403, Fehlerseite im Layout mit zoneadmin-Navigation und Rückweg.

**Aufnahmen:** [`docs/ui/screenshots/u3/`](screenshots/u3/), 14 Bilder, Edge headless. Bausteinübersicht, Editor, mobil (492 px) und 403-Seite, jeweils dunkel und hell, dazu ohne JS (dunkel).

**Gefunden und behoben während der Prüfung:**
- Eine Zeile mit eingeklapptem Anfang und Ende hatte zwei Umschalter (Fall F02); jetzt einer je Zeile.
- Die geschlossene `<details>`-Rückfrage hätte durch `display:flex` sichtbar sein können; jetzt ausdrücklich verborgen.
- Beim Einklappen bleibt der Zeilenanfang (Name, TTL, Typ) sichtbar.

**Aufräumen ns1:** keine Test-DBs, kein `/tmp/dnsmgr-uig`, keine Ports; Publish-Sperre frei; Checkout unverändert (`7cdd9a8`).

## 5. Offen für die folgenden Pakete

- **U4:**
  - Records und Zonen im Editor-Muster;
  - Vorschau-Endpunkt `actions/record_preview.php` (D3) mit No-JS-Vorschau (D4);
  - Live-DIFF für alle Typen und Konsistenzprüfung Vorschau ↔ Zonendatei (D8);
  - Massenlöschung über `ui_bulk_bar()`;
  - U0-B1, U0-B2, U2-B2 (Popover), U2-B3, U2-B4.
- **U5:** Dashboard mit Kennzahlen (inkl. `recordCount`, D5), übrige Seiten im Editor-Muster, Rückfälle der Passwort-, Deaktivierungs- und Reload-Dialoge (U0-B8), U0-B3, U0-B4.
- **U6:** Barrierefreiheit gesamt, Durchsetzung der CSP, mobile Feinarbeit.
