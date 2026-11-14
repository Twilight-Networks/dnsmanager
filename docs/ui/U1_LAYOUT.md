# UI-Modernisierung U1 – Layout-Gerüst

Stand: 14.11.2026. Umsetzung gemäß Freigabe auf Basis von `main` `5796221` und
[`docs/UI_GUIDELINES.md`](../UI_GUIDELINES.md).

**Umfang:**
- Viewport und responsives Grundlayout;
- Navigation mit Desktop-Seitenleiste und mobiler Alternative;
- lokale Bootstrap Icons;
- heller/dunkler Farbmodus mit Systemvorgabe;
- einheitliches Login-Layout.

Seiteninhalte und Fachfunktionen sind unverändert.

## 1. Änderungen

| Datei | Änderung |
|---|---|
| `web/templates/layout.php` | **Kopf:** `viewport`, `theme.js` (blockierend im Kopf), `theme-auto.css`, `<html class="no-js">`.<br>**Seitenleiste** als Bootstrap `offcanvas-lg`: ab lg fest wie bisher; darunter Kopfleiste mit Menü-Schaltfläche und Offcanvas.<br>**Navigation:** Symbole statt Emojis; Skip-Link „Zum Inhalt springen“; `<main id="main">`; Farbmodus-Umschalter (nur mit JS).<br>**Unverändert:** Publish- und Logout-Formulare (Ziel, Felder, CSRF) und alle Linkziele. |
| `web/templates/layout_footer.php` | schließt `<main>` statt `<div class="content">` |
| `web/login.php` | `viewport`, Farbmodus, gemeinsame Stylesheets, `lang` der aktiven Sprache statt fest `de`, Klassen statt Inline-Styles, `<label for>`/`id` und `autocomplete`, Schaltfläche in der CI-Farbe. Formular (Methode, Ziel, Felder, CSRF) und Hinweistexte unverändert. |
| `web/assets/css/custom.css` | **Farbvariablen** (`--dm-*`) für hell, dunkel und dunkel ohne JS (Media Query) statt fester Farben; die Werte des hellen Modus entsprechen den bisherigen.<br>**Layout:** responsiv, Offcanvas-Anpassungen, Darstellung ohne JS (`.no-js`), Anmeldeseite.<br>**Dunkelmodus:** angepasste `.table-light`-Köpfe. |
| `web/assets/css/theme-auto.css` (neu) | Dark-Regeln von Bootstrap 5.3.3 für `html:not([data-bs-theme])` unter `prefers-color-scheme: dark`. Das ist die Systemvorgabe ohne JavaScript, erzeugt aus `bootstrap.min.css`. |
| `web/assets/js/theme.js` (neu) | Tauscht `no-js` gegen `js`. Setzt `data-bs-theme` aus gespeicherter Wahl (`localStorage`) oder Systemvorgabe und folgt Änderungen der Systemvorgabe. Umschalter über `data-theme-set`, mit `aria-pressed`. Extern, ohne Inline-Code und ohne `eval`. |
| `web/assets/icons/bootstrap-icons-sprite.svg` (neu) | Reduziertes SVG-Sprite (14 Symbole, 9 KB) aus `bootstrap-icons@1.11.3`. Das npm-Paket wurde gegen seine sha512-Prüfsumme geprüft; die Symbole sind unverändert übernommen. |
| `LICENSES/bootstrap-icons.LICENSE.txt` (neu) | MIT-Lizenz von Bootstrap Icons |
| `web/inc/helpers.php` | Darstellungshelfer `ui_icon()`: Symbol mit `aria-hidden`, die Bedeutung trägt immer sichtbarer Text bzw. `aria-label` |
| `web/lang/de.php`, `en.php`, `es.php` | 7 neue Schlüssel: `menu_open`, `main_navigation`, `skip_to_content`, `theme_label`, `theme_light`, `theme_dark`, `theme_auto` |
| `tests/ui/*` | Screenshot-Werkzeug:<br>- Server ohne JavaScript (Router entfernt `<script>`);<br>- Optionen `-ColorScheme dark`, `-NoJs`, `-Only`;<br>- mobil mit 492 CSS-Pixeln (Mindestbreite von Edge headless). |

Nicht geändert:
- Core-Services, Actions, API, Skripte, Schema, Agent;
- Seiteninhalte (`web/pages/*`, Formular-Templates);
- Toasts und Konfiguration.

## 2. Verhalten ohne JavaScript

- **Navigation:** Auf schmalen Bildschirmen steht die Seitenleiste als normaler Block über dem Inhalt; die Menü-Schaltfläche ist ausgeblendet. Ab lg bleibt sie unverändert fest.
- **Farbmodus:** Es gilt die Systemvorgabe über `theme-auto.css` und die `--dm-*`-Variablen; der Umschalter ist ausgeblendet.
- **Formulare:** Alle Formulare und Links funktionieren unverändert. Nachweis: Die Golden-Basis sendet ohne JavaScript.

## 3. Nachweise

**Golden-Vergleich gegen die U0-Basis** `tests/ui/baseline/golden-1e37515.json` (ns1, PHP 8.5.4, MariaDB 11.8.6; Anwendung und Harness `381d1df`):

| | |
|---|---|
| Ergebnis | **Exit 0 – keine Vertragsabweichung**: Status, Weiterleitungen, Header, Toasts, Logs, Datenbankzustand, Seitenvertrag (Formulare mit Kodierung, Linkziele) und PHP-Meldungen identisch |
| identisch | 81 Fälle: alle Actions, Login/Logout, API, alle Weiterleitungen für anonym, alle 403-Antworten, `logout` per GET |
| nur informativ abweichend | 37 Seitenfälle mit Layout: admin 22, zoneadmin 14, anonyme Login-Seite 1 |
| PHP-Meldungen | 15, wie in der Basis (vorbestehend U0-B2/U0-B3), keine neuen |

**Interaktive Prüfung** (eingebauter Browser, 375 × 812 px, Testkopie über SSH-Tunnel):
- Menü-Schaltfläche öffnet das Offcanvas. Die aktive Seite ist markiert; Publish, Benutzer, Umschalter und Abmelden sind erreichbar.
- Umschalter „Dunkel“ setzt `data-bs-theme=dark`, speichert `dark` und setzt `aria-pressed` korrekt. „Systemvorgabe“ entfernt die Speicherung.
- Keine horizontale Verbreiterung der Seite (`scrollWidth` = 375); breite Tabellen scrollen innerhalb des Inhalts.
- Ohne JavaScript: `<html class="no-js">`, keine Skripte, Navigation statisch sichtbar, Menü-Schaltfläche und Umschalter ausgeblendet.

## 4. Screenshots

Ablage: [`docs/ui/screenshots/u1/`](screenshots/u1/), 45 Aufnahmen, 3,1 MB, Anwendung `3dc3f2d`.
- Web-Code identisch mit dem Head.
- Der Fix der Skript-Entfernung im Screenshot-Router (`serve_router.php`) wurde während des Laufs in die Testkopie eingespielt und ist inhaltsgleich committet.
- Edge 157 headless; Desktop 1440 px, mobil 492 px.

| Satz | Aufnahmen |
|---|---|
| hell, alle Seiten wie U0 | 26 (gleiche Namen wie `screenshots/u0/` – direkter Vergleich) |
| dunkel (Suffix `-dark`) | Login (Desktop/mobil), Dashboard (Desktop/mobil), Zonen, Records (Desktop/mobil), Record-Anlage, Server, Benutzer, Systemstatus, zoneadmin-Zonen |
| ohne JavaScript (Suffix `-nojs`) | Login mobil, Dashboard (Desktop/mobil), Zonen mobil, Records mobil, Zonen-Anlage; Dashboard mobil zusätzlich dunkel (`-dark-nojs`) |

Beispiele: [Records hell](screenshots/u1/admin-records.png) ·
[Records dunkel](screenshots/u1/admin-records-dark.png) ·
[Records mobil](screenshots/u1/admin-records-mobile.png) ·
[Dashboard mobil ohne JS, dunkel](screenshots/u1/admin-dashboard-mobile-dark-nojs.png) ·
[Login mobil dunkel](screenshots/u1/anon-login-mobile-dark.png).

## 5. Befunde aus U1

| ID | Befund | Paket |
|---|---|---|
| U1-B1 | **Toasts ohne JavaScript unsichtbar** (vorbestehend): Die Session-Toasts werden ohne Klasse `show` ausgegeben und erst von `global.js` eingeblendet. Ohne JavaScript sieht man keine Rückmeldung nach Aktionen. Die Action selbst wirkt; Toast-Inhalt und Session sind unverändert. | U3 (Meldungs-Baustein mit serverseitiger Darstellung) |
| U1-B2 | Werkzeug: Edge headless rendert mindestens 492 CSS-Pixel breit (schmaler wird nur das Bild beschnitten) und erzeugt mit abgeschaltetem JavaScript keine Screenshots. Gelöst über Breite 492 und den No-JS-Server. Die U0-Mobilaufnahmen sind dadurch auf 390 px beschnitten, zeigen den damaligen Zustand (feste Seitenleiste) aber korrekt. | – |

Die U0-Befunde B1–B9 sind von U1 nicht berührt und bleiben ihren Paketen zugeordnet. U0-B5 (nicht responsiv) ist
mit U1 behoben; U0-B6 (403 ohne Layout) bleibt für U3.
