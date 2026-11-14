# UI-Modernisierung U1 – Design-Fundament A+

Stand: 14.11.2026. Umsetzung der freigegebenen Designvorlage **A+** (Variante A als Grundlage, ergänzt um Elemente aus B)
gemäß Integrationsplan, Entscheidung D1: PR #80 wird zu „U1 – Design-Fundament A+“ überarbeitet.

**Umfang:**
- Design-Tokens für hell und dunkel;
- Seitenleiste, Kopfleisten und Original-Branding;
- Grundstile der verwendeten Bootstrap-Komponenten;
- Login-Seite.

**Funktion und Inhalte der Fachseiten sind unverändert.** Editor-Muster, Live-DIFF, Bausteine und Seitenumbauten folgen
in U3–U5. Regeln: [`docs/UI_GUIDELINES.md`](../UI_GUIDELINES.md).

## 1. Branding

| Datei (unverändert, `web/assets/branding/`) | Einsatz |
|---|---|
| `TWLnet_Logo_neg.png` | Seitenleiste und Anmeldung im **Dunkelmodus** |
| `TWLnet_Logo_CMYK.png` | Seitenleiste und Anmeldung im **Hellmodus** |
| `TWLnet_Logo_Quadrat_neg.png` | mobile Kopfleiste, dunkel |
| `TWLnet_Logo_Quadrat_CMYK.png` | mobile Kopfleiste, hell |

**Dateien:**
- Kopien der Originale aus `Twlnet\Twlnet-Logos\Logo\`, per SHA-256 identisch.
- Seitenverhältnis bleibt erhalten: Es wird nur Breite bzw. Höhe gesetzt.
- Es gibt keine Hinterlegung, Umfärbung oder Nachzeichnung.

**Umschaltung je Farbmodus:**
- Beide Varianten sind im Markup vorhanden; CSS blendet je Farbmodus eine aus (`.logo-on-light` / `.logo-on-dark`).
- Mit JS folgt die Anzeige `data-bs-theme`, ohne JS der Systemvorgabe.
- Interaktiv geprüft: dunkel → `_neg`, hell → `_CMYK`.

**Weitere Merkmale:**
- Markenakzent **`#3FA535`**, aus den Logos verifiziert.
- Das Logo in der Seitenleiste verlinkt wie bisher die Zeile „by Twilight-Networks“ auf `https://www.twilight-networks.com`. Das Linkziel ist unverändert.

## 2. Änderungen

| Datei | Änderung |
|---|---|
| `web/assets/css/custom.css` | Neu aufgebaut als Design-System A+:<br>(1) Tokens hell, dunkel und dunkel ohne JS, abgebildet auf die Bootstrap-Variablen;<br>(2) Grundtypografie, Logo-Umschaltung;<br>(3) Gerüst: Seitenleiste, Navigationsgruppen, Veröffentlichungskasten, Benutzerbereich, Kopfleisten, Arbeitsbereich, No-JS-Darstellung;<br>(4) Grundstile für Schaltflächen, Formulare, Karten, Tabellen (auch `table-light`/`table-warning`), Abzeichen, Hinweise, Modals, Toasts, `text-bg-light`, native Checkboxen;<br>(5) Anmeldeseite;<br>(6) seitenbezogene Breitenklassen unverändert übernommen. |
| `web/templates/layout.php` | **Seitenleiste:** Original-Logo, Produktzeile mit Version, Navigationsgruppen DNS / Infrastruktur / Verwaltung; „Auf Updates prüfen“ ist in die Gruppe Verwaltung gewandert, Ziel unverändert. Zähler ausstehender Zonen an „Zonen“, Fehlerkennzeichen an „Systemstatus“.<br>**Veröffentlichungskasten:** bestehendes Publish-Formular.<br>**Benutzerbereich:** Avatar-Kürzel, Name, Rolle, bestehendes Logout-Formular; Farbmodus-Umschalter.<br>**Desktop-Kopfleiste:** Pfadangabe, auf der Record-Seite mit Rücklink „Zonen“ (vorhandenes Ziel).<br>**Mobile Kopfleiste:** Quadrat-Logo. |
| `web/templates/layout_footer.php` | schließt zusätzlich den Arbeitsbereich (`.app-main`) |
| `web/login.php` | Logo-Paar statt des bisherigen Bildes, Produktzeile, Überschrift „DNS Manager“ nur für Screenreader. Formular und Hinweistexte unverändert. |
| `web/lang/de.php`, `en.php`, `es.php` | 7 neue Schlüssel: `nav_group_dns`, `nav_group_infrastructure`, `nav_group_admin`, `publish_pending`, `publish_pending_zones`, `publish_pending_hint`, `breadcrumb` |
| `web/assets/icons/bootstrap-icons-sprite.svg` | 3 weitere Symbole aus `bootstrap-icons@1.11.3`: `grid-1x2`, `activity`, `exclamation-lg` |
| `web/assets/branding/TWLnet_Logo_*.png` (neu) | vier Original-Logos (siehe 1) |

Aus der früheren U1-Fassung bleiben unverändert:
- Viewport, Offcanvas-Navigation auf schmalen Bildschirmen mit No-JS-Darstellung als Block;
- `theme.js` (Farbmodus, `no-js`/`js`), `theme-auto.css`;
- `ui_icon()`.

**Bewusst nicht in U1:** eine Statusanzeige in der Kopfleiste. Ein erster Entwurf wertete nur die Diagnose-Übersicht aus und widersprach damit dem Dashboard, das weitere Systemprüfungen einbezieht. Die Anzeige folgt in U5 mit derselben Quelle wie Dashboard und Systemstatus-Seite.

## 3. Nachweise

**Golden-Vergleich gegen die U0-Basis** `tests/ui/baseline/golden-1e37515.json`:
- ns1, PHP 8.5.4, MariaDB 11.8.6;
- Anwendung und Harness `f3953ec`, Ergebnis sha256 `3c1e6878…c0bc10e`.

| | |
|---|---|
| Ergebnis | **Exit 0 – keine Vertragsabweichung**. Status, Weiterleitungen, Header, Toasts, Logs, Datenbankzustand, Seitenvertrag (alle Formulare mit Kodierung und Feldern sowie **alle Linkziele**) und PHP-Meldungen sind identisch. |
| identisch | 81 Fälle: alle Actions, Login/Logout, API, Weiterleitungen, 403-Antworten |
| nur informativ abweichend | 37 Layout-Seiten (admin 22, zoneadmin 14, Anmeldung 1) |
| PHP-Meldungen | 15, wie in der Basis (vorbestehend U0-B2/U0-B3), keine neuen |

**Visuelle Prüfung:** 45 Aufnahmen in [`docs/ui/screenshots/u1/`](screenshots/u1/) (3,2 MB), Edge 157 headless, Desktop 1440 px, mobil 492 px.
- **dunkel:** alle 26 Seitenansichten (Suffix `-dark`);
- **hell:** 13 Kernseiten;
- **ohne JavaScript, dunkel:** 6 Ansichten (Suffix `-dark-nojs`).

**Interaktiv** (eingebauter Browser über SSH-Tunnel): Farbmodus-Umschalter setzt `data-bs-theme`, `aria-pressed` und die Speicherung; „Systemvorgabe“ entfernt sie. Die Logos wechseln korrekt zwischen `_neg` und `_CMYK`, auch in der mobilen Kopfleiste.

**Mobiles Offcanvas-Menü** interaktiv geprüft (eingebauter Browser, 375 × 812 px, Head `e53d089`, dunkel, Administrator):
- Öffnen über „Menü“: Menü fährt ein, Hintergrund wird abgedunkelt.
- Navigation über „Zonen“: Seitenwechsel Dashboard → `zones.php`, Menü danach geschlossen, „Zonen“ als aktiv markiert.
- Erneutes Öffnen und Schließen über die Schaltfläche „Schließen“: Menü ausgeblendet, Abdunkelung entfernt, Seite wieder scrollbar, Fokus zurück auf „Menü“.
- Keine JavaScript-Fehler in der Konsole.

Die No-JS-Darstellung als Block belegen die Aufnahmen.

## 4. Offene Punkte für die folgenden Pakete

- **U1-B1** (vorbestehend): Toasts ohne JavaScript unsichtbar → U3.
- Verbleibende Emojis in den Seiteninhalten, z. B. Statuszeichen und Symbole in Tabellen → mit den Bausteinen bzw. Seitenumbauten in U3–U5.
- Dashboard nach Designvorlage (Kennzahlen, Server, Zonen, Warnungen, ausstehende Veröffentlichungen; Record-Anzahl je Zone nach dem gesonderten Core-Lese-PR, D5) → U5.
- Record-Verwaltung mit Editor und Live-DIFF (D3/D4/D8) → U4.
