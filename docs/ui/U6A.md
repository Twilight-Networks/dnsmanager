# UI-Modernisierung U6a – CSP-Durchsetzung und 375-px-Abnahme

Stand: 15.11.2026. Umsetzung des freigegebenen U6-Plans (E1–E10) mit den Präzisierungen U6-P1 und U6-P2.

**Abgrenzung:**
- Keine Änderung an Core, Actions, Services, Schema oder Sicherheitsmodell außerhalb des CSP-Headers.
- Die Zielrichtlinie ist seit U2 unverändert.
- Kein Deployment. DynDNS-Bewertung und Deployment-Runbook folgen in U6b (eigener Dokumentations-PR).

| | |
|---|---|
| Basis (`main`, nach #87) | `22acbaaa7572376e97a2937726b7578e241f636a` · Tree `60edcc6d9a440553ddbb187c0c00c53d20f63fc5` |
| Getesteter Stand | `7ac7cbb54835fc6413aa3285a5c3118cbdb046e7` · Tree `a56303dafe1cd7a45afb51ce0c8bc0ece71a91a8` (Anwendung, Harness, Screenshots). Danach nur dieser Bericht. |
| Umgebung | ns1, PHP 8.5.4, MariaDB 11.8.6; isolierte Testkopie über `tests/ui/run.sh` (`/tmp/dnsmgr-uig`, DB `dnsmgr_uig_*`). Die installierte Anwendung bleibt unverändert auf `7cdd9a8`. |

## 1. CSP-Modus (E1–E3, U6-P1)

| Datei | Änderung |
|---|---|
| `web/inc/web/csp.php` | `csp_mode()` mit Vorrang: ein definiertes `CSP_MODE` gilt immer; nur ohne `CSP_MODE` wirkt `CSP_REPORT_ONLY === true` als Kompatibilitätsfall `'report-only'`; ohne beide gilt `'enforce'`. Ein ungültiger Wert wird als `'enforce'` behandelt (Fail-Closed). `csp_header_line()` liefert die Headerzeile oder `null`. |
| `web/common.php` | Sendet genau einen Header je Modus:<br>• `enforce`: `Content-Security-Policy: <Zielrichtlinie>`, ohne `report-uri` (E2);<br>• `report-only`: `Content-Security-Policy-Report-Only: <Zielrichtlinie>; report-uri <BASE_URL>/csp_report.php`;<br>• `off`: kein CSP-Header.<br>Die drei bestehenden Sicherheitsheader bleiben unverändert. |
| `web/csp_report.php` | Läuft nicht über `common.php`; aktiv nur im Modus `'report-only'`, sonst 404 ohne Logzeile; sendet selbst keinen CSP-Header. Nur der Kommentar wurde angepasst, die Logik nutzt weiter `csp_report_only_enabled()`. |
| `web/config/ui_config-sample.php` | Dokumentiert `CSP_MODE` (auskommentiert, Standard `enforce`) und den Kompatibilitätsfall. |

**U6-S1 (Sicherheitsänderung, bewusst):**
- Ohne Einstellung setzt die Anwendung die Zielrichtlinie jetzt durch.
- Eine Installation ohne `CSP_MODE` (z. B. `/etc/dnsmanager/ui_config.php` auf ns1) liefe nach einem Update sofort mit `enforce`.
- Laut U6-P1 setzt das Runbook (U6b) beim ersten Produktivdeployment daher vor dem Code-Update ausdrücklich `CSP_MODE = 'report-only'`; erst nach der Live-Abnahme folgt der Wechsel auf `'enforce'`.

## 2. Tests und Golden (U6-P2)

| Datei | Inhalt |
|---|---|
| `tests/ui/u6.php` (neu, über `run.sh matrix`) | **V01**: Zielrichtlinie gleich dem Literal aus U2/Leitfaden 4.<br>**M01–M08**, je Konfigurationsvariante: exakt ein CSP-Header des erwarteten Modus bzw. keiner, für normale Seite, Login, Redirect (Seite und Action), 403 (Seite und Action) und DynDNS-API. Die drei Sicherheitsheader sind immer vorhanden.<br>**K01–K08**: Report-Endpunkt; nur bei `report-only` 405/204 mit genau einer Logzeile, sonst 404 ohne Logzeile; nie ein CSP-Header.<br>**S01–S03**: Status und Weiterleitung in allen Varianten identisch, kein Zustandswechsel, Konfiguration der Testkopie wiederhergestellt. |
| `tests/ui/compare.php` | Neuer Ausnahmeschlüssel `headers+`: Er gibt ausschließlich das Hinzukommen der unter `_headers_added` exakt genannten Headerzeile frei (jede genau einmal). Alle übrigen Header müssen der Basis entsprechen. |
| `tests/ui/baseline/exceptions-u6.json` (neu) | U5b-Ausnahmen unverändert, dazu `headers+` je Fall (115). Keine neuen Ausnahmen für Seitenverträge oder Actions. |
| `tests/ui/lib.php` | Die Header-Erfassung kennt zusätzlich `Content-Security-Policy-Report-Only`. |
| `tests/ui/run.sh` | `UIG_CSP_MODE=enforce\|report-only\|off` nur im Modus `serve` und nur in der Testkopie; U6-Hook in `matrix`. |
| `tests/ui/layout375.ps1` (neu) | 375-px-Prüfung über das DevTools-Protokoll (Abschnitt 4). |

Varianten M01–M08:
- ohne Einstellung → `enforce`;
- `CSP_MODE` gesetzt: `enforce`, `report-only`, `off`;
- nur `CSP_REPORT_ONLY` → `report-only`;
- `off` + `CSP_REPORT_ONLY` → `off`;
- `enforce` + `CSP_REPORT_ONLY` → `enforce`;
- ungültig `'Enforce'` + `CSP_REPORT_ONLY` → `enforce`.

**U6-V1 (Golden):** In 115 von 118 Fällen kommt genau die Zeile `content-security-policy: <Zielrichtlinie>` hinzu. Die übrigen 3 Fälle sind identisch: `page/{admin,anon,zoneadmin}/logout_get`. `logout.php` antwortet auf GET mit 405, bevor `common.php` geladen wird, und sendet daher wie bisher keine Sicherheitsheader (vorbestehend, siehe 6).

## 3. Korrektur U6-B1 (vorbestehender Fehler)

- **Befund:** `pages/update.php` band `templates/layout_footer.php` nicht ein (schon in `7cdd9a8`). Damit fehlte Bootstrap-JS: Unter 992 px öffnete das Mobilmenü mit JavaScript nicht, und die Seite war nur noch über das Logo verlassbar. Außerdem fehlten die schließenden Elemente.
- **Korrektur:** `update.php` bindet den Footer wie jede Layout-Seite ein (eine Zeile, keine Logik).
- **Golden:** Bei `page/admin/update` ändern sich nur `headers+` und der informative Seiteninhalt; Seitenvertrag (Formulare, Links), Status und Logs sind unverändert. Bei `page/zoneadmin/update` (403) gilt unverändert die U3-Ausnahme.

Weitere CSS-Korrekturen waren nach der 375-px-Prüfung nicht nötig.

## 4. 375-px-Abnahme (E4)

`layout375.ps1` misst mit Edge headless über das DevTools-Protokoll bei 375 × 812 (mobil): 30 Seitenzustände je Variante.
- **Seiten:** Anmeldung, Dashboard, Zonen mit Anlage und Bearbeitung, Records (Forward, Reverse, Anlage, Bearbeitung), Server, DynDNS, Benutzer (jeweils mit Anlage, Bearbeitung und Passwortmodus), Systemstatus, Update;
- **zoneadmin:** Dashboard, Zonen, Zoneneditor, Records, Record-Editor, Benutzer, eigenes Passwort, 403-Seite.

| Prüfung je Seite | Kriterium |
|---|---|
| Seitenbreite | `innerWidth` = 375, `scrollWidth` ≤ 375 (kein seitliches Scrollen) |
| Überstand | kein sichtbares Element ragt außerhalb eines eigenen Scroll-/Clip-Containers über den Rand |
| Bedienelemente | Treffertest: nach `scrollIntoView` liegt an der Mitte jedes sichtbaren Links, Buttons, Feldes oder Summary das Element selbst. Bei offenem Editor (mobil ein Dialog über die ganze Breite, `aria-modal`) werden nur die Editor-Elemente geprüft, die Seite dahinter ist beabsichtigt verdeckt. |
| Bestätigungen | Jede `details.dm-confirm` clientseitig geöffnet (ohne Klick, ohne POST): Bestätigungsbutton sichtbar, im Viewport und laut Treffertest klickbar; danach geschlossen |
| Mobilmenü (mit JS) | Offcanvas öffnet sich, alle Links per Treffertest klickbar, Menü schließt sich wieder |
| CSP | `securitypolicyviolation`-Ereignisse, registriert vom Protokoll (nicht Teil der Seite) |

| Variante | Ergebnis |
|---|---|
| hell, mit JavaScript | **30/30** ohne Befund (Menü auf 14 Seiten ohne offenen Editor geöffnet und geprüft, auch `update.php`) |
| hell, ohne JavaScript (Ports 1861x) | **30/30**; die Navigation steht ohne JS als normaler Block über dem Inhalt und ist im Treffertest enthalten |
| dunkel, mit JavaScript | **30/30** |
| CSP-Verstöße (alle 90 Messungen) | **0** |

**Vorher:** Der erste Lauf meldete auf `update.php` mit JavaScript, dass das Menü nicht öffnet (U6-B1, behoben). Weitere Meldungen kamen nur aus der Messung selbst: der nur bei Fokus sichtbare Skip-Link und die verdeckte Seite hinter dem Editor-Dialog. Die Messung wurde dafür präzisiert.

**Visuelle Durchsicht** aller Aufnahmen:
- Karten, Hinweise, Editoren und Formulare umbrechen sauber.
- Breite Tabellen scrollen wie vorgesehen im eigenen Container. Die Aktionsspalte liegt dann rechts außerhalb des sichtbaren Bereichs und ist durch Wischen erreichbar; der Treffertest belegt die Erreichbarkeit.

**Aufnahmen** (`docs/ui/screenshots/u6/`, 52):
- `u6-<seite>-375.png` für alle 30 Zustände;
- je 11 als `-375-dark` und `-375-nojs`.
- Bei offenem Editor wird der Viewport aufgenommen, sonst die ganze Seite.

## 5. CSP unter Durchsetzung (E5, E7)

**Positivkontrolle (U6-P2):**
- Nur in der Testkopie wurde in `pages/dashboard.php` vorübergehend eingefügt: `<div id="u6-csp-probe" style="outline: 3px solid rgb(255, 0, 0)">`.

| Modus | Ergebnis |
|---|---|
| Standard (`enforce`) | **blockiert**: berechnete `outline-style` = `none`; Verstoß `style-src-attr inline` (`pages/dashboard.php:119`) |
| Gegenprobe `CSP_MODE = 'off'` (nur Testkopie) | kein CSP-Header; der Stil wird angewandt (`outline-style` = `solid`), kein Verstoß |

Danach Seite und Konfiguration wiederhergestellt: identisch mit dem Commit, Rechte 640 root:www-data, Header wieder `enforce`.

**Interaktive Abläufe unter `enforce`** (eingebauter Browser, 1440 × 900, admin):

| Seite | Ergebnis |
|---|---|
| Records | • Live-Vorschau mit DIFF (fetch auf `record_preview.php`) aktualisiert;<br>• Typwechsel MX, SRV, DKIM, A blendet die passenden Felder ein;<br>• Löschbestätigung als Modal öffnet und schließt. |
| Theme | Umschaltung hell/dunkel/System wirkt. |
| Server | • Token-Erzeugung liefert 64 Hex-Zeichen;<br>• „= API-IP“ übernimmt die DNS-IP schreibgeschützt und folgt Änderungen;<br>• die Checkboxen schließen sich gegenseitig aus. |
| Benutzer | Rollenwechsel sperrt bzw. gibt die Zonenoptionen frei. |
| Zonen | Präfixfeld nur bei `reverse_ipv4`/`reverse_ipv6`. |
| DynDNS | Kopierschaltfläche gebunden, Handler läuft. Der Browserbereich verweigert grundsätzlich die Zwischenablage (`NotAllowedError`); dadurch erscheint die bisherige Fehlermeldung. Das ist eine Grenze der Testumgebung, keine CSP-Wirkung. |
| Formular-POST mit Redirect | Deaktivieren von `u0admin2` über die Inline-Bestätigung → Toast „Konto deaktiviert …“, Reaktivieren → „Konto reaktiviert.“ (nur Fixture der Testkopie). Endzustand wie Fixture. |

Keine Konsolenfehler und keine `Refused`-Meldungen.

## 6. Nachweise

| Prüfung (auf `7ac7cbb`) | Ergebnis |
|---|---|
| **U6** `u6.php` | **20/20** |
| **Golden** gegen U0-Basis mit `exceptions-u6.json` | **Exit 0**: 3 identisch, 115 freigegeben (`headers+`, U5b-Ausnahmen), **0 Vertragsabweichungen** |
| U5b · U5a · U4-UI | 21/21 · 20/20 · 40/40 (Rollen, No-JS, Schutzregeln, Vorschau) |
| Typ-Matrix gegen `baseline-main-8447e35` | 104 identisch, 12 dokumentiert, 0 undokumentiert |
| Builder/Parser/BIND | 73/73 |
| DynDNS-Kennzeichen | 17/17 unter REPEATABLE READ und READ COMMITTED |
| Statisch S01–S05 | 5/5 |
| 375 px | 30/30 hell · 30/30 ohne JS · 30/30 dunkel; 0 CSP-Verstöße |
| PHP-Meldungen in den Serve-Läufen | 0 |

**Aufräumen ns1:**
- keine Test-DBs, kein `/tmp/dnsmgr-uig`, keine Ports, Publish-Sperre frei;
- Harness-Checkout auf einem festen SHA;
- installierte Anwendung unverändert `7cdd9a8`, Konfiguration unberührt.

## 7. Bewusste Abweichungen und Befunde

| ID | Beschreibung |
|---|---|
| U6-S1 | CSP standardmäßig durchgesetzt (Abschnitt 1); erstes Produktivdeployment mit `report-only` (U6-P1, Runbook U6b) |
| U6-V1 | Golden: zusätzlicher Header `Content-Security-Policy` mit exakt der Zielrichtlinie, sonst keine Headeränderung |
| U6-B1 | `update.php` schließt das Layout ab; Mobilmenü mit JS funktioniert wieder |
| U6-H1 (vorbestehend, unverändert) | `GET logout.php` antwortet mit 405 vor `common.php`, ohne Sicherheitsheader. Ohne Inhalt; nicht im Umfang geändert. |
| U6-H2 (vorbestehend, Hinweis) | Breite Tabellen erfordern mobil seitliches Wischen bis zur Aktionsspalte (Design der responsiven Tabellen seit U3) |

## 8. Restrisiken

- Die CSP-Prüfung deckt alle A+-Seiten, ihre Editoren und die bekannten Interaktionen ab. Seltene Pfade, z. B. Fehlermeldungen externer Agents mit eigenem Markup, sind nur durch die statische Prüfung S02/S03 abgesichert. Deshalb gilt für die erste Live-Phase `report-only` (U6-P1).
- Die Zwischenablagefunktion konnte im Browserbereich nicht positiv bestätigt werden (Berechtigung der Testumgebung); die Bindung ist unter CSP nachgewiesen.
- Der DynDNS-Restbefund (U4) wird in U6b untersucht; seine Akzeptanz ist offen.
