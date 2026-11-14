# UI-Modernisierung U2 – CSP-Vorbereitung

Stand: 14.11.2026. Ziel gemäß [`docs/UI_GUIDELINES.md`](../UI_GUIDELINES.md), Abschnitt 4:
- keine Inline-Skripte, Inline-Ereignishandler, `style`-Attribute oder `javascript:`-URLs;
- Sprachtexte für JavaScript nur seitenbezogen als nicht ausführbarer JSON-Datenblock.

**Abgrenzung:**
- Durchgesetzt wird noch keine CSP; das bleibt U6 vorbehalten.
- Report-Only nur bei ausdrücklich gesetzter Konfiguration `CSP_REPORT_ONLY === true` (Freigabe R1–R5, Abschnitt 5). Standard und installierte Systeme: kein CSP-Header, Endpunkt deaktiviert.
- Keine Änderung an Core, Actions, API, Agent oder Schema.

## 1. Ausgangslage (U0, `main` `858bd07`)

| Art | Anzahl | Fundstellen |
|---|---|---|
| Inline-Skript | 1 | `templates/layout_footer.php`: `window.LANG` mit dem kompletten `$LANG` (444 Schlüssel) auf jeder Seite |
| Inline-Ereignishandler | 15 | `pages/dyndns.php`, `pages/servers.php`, `pages/system_health.php` (5), `pages/zones.php`, `templates/record_add_form.php` (2), `templates/server_add_form.php` (3), `templates/server_edit_form.php`, `templates/user_add_form.php` |
| `style`-Attribute | 30 | Seiten und Templates, davon 3 in PHP-Zeichenketten in `pages/records.php` |

**Nicht betroffen:** `inc/deploy/PhpMailerNotifier.php`. Das ist HTML für E-Mail-Benachrichtigungen, keine Webseite, und liegt im Core.

## 2. Umsetzung

| Bereich | Änderung |
|---|---|
| Sprachdaten | `layout_footer.php` gibt `<script type="application/json" id="app-lang">` aus. Der Block enthält nur die Schlüssel der auf der Seite eingebundenen Skripte, laut neuer Zuordnung `inc/js_lang_keys.php` (5 Skripte, 38 Zuordnungen). Er wird vor den Skripten ausgegeben und mit `JSON_HEX_TAG\|AMP\|APOS\|QUOT` kodiert. `lang()` in `global.js` liest ihn einmalig per `JSON.parse`. Fehlende Schlüssel liefern wie bisher den Schlüsselnamen bzw. den Fallback. `window.LANG` entfällt. |
| Ereignishandler | Bindung per `addEventListener` in den vorhandenen Seitenskripten:<br>• Statuskarten: `data-zone-diagnostics-toggle`, `data-server-diagnostics-toggle`;<br>• ausklappbare Zeilen im Systemstatus: `data-toggle-row="<ID>"`;<br>• Token-Schaltflächen: Klasse `js-generate-api-key`, Aufruf von `window.generateApiKey()` zum Klickzeitpunkt wie beim Inline-Handler;<br>• Kopierschaltfläche DynDNS: `#copyDynDnsUrlBtn`;<br>• DKIM-Datei: `#dkim_file`. |
| Redundante Handler | Entfernt, weil die Skripte dieselben Listener bereits binden: Typauswahl im Record-Formular, Rollenauswahl im Benutzerformular, beide Checkboxen im Server-Anlageformular. |
| `style`-Attribute | CSS-Klassen in `custom.css`, Abschnitt 7 (`app-hidden`, `app-clickable`, `app-badge-inactive`, `app-mw-700`, `app-w-20`, `app-table-fixed`, `app-check-locked`, `app-pre-wrap`, `app-pre-line`) bzw. Bootstrap (`invisible`, `text-nowrap`). |
| Ein-/Ausblenden | Umschalter per `classList.toggle('app-hidden')`. Wo Skripte explizit `element.style.display` setzen, bleibt das unverändert: CSSOM ist CSP-konform, und `app-hidden` ist ohne `!important` überschreibbar. |

**Verhalten ohne JavaScript:** unverändert. Die Bereiche, die bisher per `style="display:none"` verborgen waren, sind jetzt per Klasse verborgen.

## 3. Bewusste Abweichungen und Befunde

| ID | Beschreibung |
|---|---|
| U2-A1 | **Behobener JS-Fehler:** Die Checkboxen „= API-IP“ im Server-Anlageformular hatten zusätzlich `onchange="toggleApiIpField()"`. Die Funktion ist nur innerhalb eines Closures definiert, daher warf jeder Klick einen `ReferenceError`; die Funktion selbst lief über den vorhandenen Listener. Mit dem Wegfall des Handlers entfällt der Fehler, die Funktion ist unverändert. |
| U2-A2 | Die Rollenauswahl im Benutzer-Anlageformular rief `toggleZoneOptions` bisher doppelt auf (Inline-Handler und Listener), jetzt einmal. Die Funktion ist idempotent, das Ergebnis identisch. |
| U2-A3 | Der Seitenquelltext enthält statt des vollständigen `$LANG` nur noch die benötigten Schlüssel: 0 bis 28 je Seite statt 444. Kein Skript liest Schlüssel außerhalb der Zuordnung (statische Prüfung S04). |
| U2-A4 | Die Logzeile eines CSP-Verstoßes enthält keine Benutzer-ID. Der Endpunkt lädt laut Freigabe R1 keine Anwendungssession; der Leitfaden ist entsprechend angepasst. |
| U2-A5 | Steuerzeichen in gemeldeten URLs ersetzt bereits `parse_url()` durch `_`. Übrige Felder bereinigt `csp_report_clean()` (Steuerzeichen → `?`, `"` und `\` maskiert). Die Logzeile bleibt in jedem Fall einzeilig. |
| U2-B1 (vorbestehend) | Die PHP-Warnung `zone_prefix_placeholder` (U0-B3) tritt weiterhin auf; vorgesehen für U5. |
| U2-B2 (vorbestehend) | Die Popover-Schaltfläche im DKIM-Bereich (`data-bs-toggle="popover"`) wird nirgends initialisiert und zeigt daher nichts; unabhängig von CSP. Vorschlag: mit den Bausteinen in U3/U4 lösen. |
| U2-B3 (vorbestehend) | `Undefined variable $domain` in `inc/dkim_helpers.php:33`, wenn nach einem Wechsel auf DKIM ein anderer Typ gespeichert wird. Das verborgene Feld `is_dkim` wird wie bisher mitgesendet. Zur Prüfung in U4 (DKIM, D6); hier keine Core-Änderung. |
| U2-B4 (vorbestehend) | Debug-Ausgaben per `console.log` in den Record-Skripten (Zonenname, Inhalt einer hochgeladenen DKIM-Datei). Zur Bereinigung in U4. |

## 4. Nachweise

**Umgebung:** ns1, PHP 8.5.4, MariaDB 11.8.6. Isolierte Testkopie des festen Commits über `tests/ui`; die installierte Anwendung bleibt unberührt.

| Prüfung | Ergebnis |
|---|---|
| Statisch S01–S05 (Syntax aller PHP-Dateien; keine Inline-Handler, `style`, `<style>`, `javascript:` oder Inline-Skripte; JS ohne `eval`/`Function`/String-Timer/`window.LANG`; `lang()`-Schlüssel = Zuordnung; Schlüssel in de/en/es) | **5/5**. Gegenprobe `main`: S02/S03 schlagen erwartungsgemäß fehl. |
| Golden gegen U0-Basis `golden-1e37515` | Exit 0: 81 identisch, 37 nur informativ, **0 Vertragsabweichungen** |
| Golden gegen den Webstand von `main` (D5-Lauf `21ee087`) | Exit 0: **87 identisch**, 31 nur informativ, 0 Vertragsabweichungen |
| Gerenderter Quelltext aller 22 Seiten je Rolle (admin, zoneadmin) | Keine Inline-Handler, `style`, `<style>`, `javascript:`, Inline-Skripte oder `window.LANG`. Sprachblock gültig (JSON), 0–28 Schlüssel. |
| Interaktiv (eingebauter Browser, admin) | Alle umgestellten Bindungen funktionieren (Einzelheiten unten). Keine JavaScript-Fehler der Anwendung. |

**Die 31 informativ abweichenden Seiten** sind genau die Layout-Seiten mit Zugriffsrecht; der Unterschied ist der neue Sprachblock. 403-Seiten, die Anmeldung und `update.php` (ohne Layout-Fuß) sind identisch.

**Interaktiv geprüft:**
- **Records:**
  - Typwechsel blendet den DKIM-Bereich ein und aus;
  - Infobox mit Übersetzung und Zeilenumbrüchen (`pre-line`);
  - Platzhalter übersetzt;
  - DKIM-Datei-Upload füllt Selector, Schlüssel und Typ;
  - Tabellen `table-layout: fixed`;
  - gesperrte Checkboxen gekennzeichnet.
- **Zonen:**
  - Statuskarte bei Warnung klickbar, Diagnosebereich auf und zu;
  - inaktiver Server mit Deckkraft 0,5;
  - Präfixfeld nur bei `reverse_ipv4`/`reverse_ipv6`.
  - Warnung und inaktiver Server wurden vorübergehend in der isolierten Test-DB gesetzt und danach zurückgesetzt.
- **Server:**
  - Statuskarte auf und zu;
  - Token-Erzeugung im Anlage- und Bearbeitungsformular (64 Hex-Zeichen);
  - Checkboxen „= API-IP“ übernehmen IPv4/IPv6, schließen sich gegenseitig aus, Schreibschutz korrekt.
- **Systemstatus:** drei ausklappbare Zeilen auf und zu.
- **DynDNS:** Kopierschaltfläche schreibt die URL, Rückmeldung übersetzt, Originalwert nach 1 s zurück.
- **Benutzer:** Rollenwechsel blendet die Zonenoptionen um.

## 5. Report-Only (Freigabe R1–R5 vom 14.11.2026)

### 5.1 Umsetzung

| Datei | Inhalt |
|---|---|
| `web/inc/web/csp.php` (neu) | Zielrichtlinie `CSP_TARGET_POLICY` exakt nach Leitfaden 4 (ohne `unsafe-inline`/`unsafe-eval`). `csp_report_only_enabled()` ist nur bei `CSP_REPORT_ONLY === true` wahr. Keine Abhängigkeit von Datenbank oder Session. |
| `web/common.php` | Sendet `Content-Security-Policy-Report-Only` (Zielrichtlinie plus `report-uri <BASE_URL>/csp_report.php`) nur bei aktiver Konfiguration, neben den unveränderten Headern `nosniff`, `DENY` und `same-origin`. |
| `web/csp_report.php` (neu) | Siehe unten. |
| `web/config/ui_config-sample.php` | Auskommentierter Eintrag `// define('CSP_REPORT_ONLY', true);` mit Erläuterung. |
| `tests/ui/run.sh`, `README.md` | `UIG_CSP_REPORT_ONLY=1` aktiviert die Einstellung ausschließlich in der generierten Konfiguration der Testkopie und nur im Modus `serve`; Golden-Läufe lehnen die Option ab. CSP-Logzeilen werden vor dem Aufräumen gesichert, der Ratenstatus entfernt. |

**Der Endpunkt `csp_report.php`** lädt nur `config/ui_config.php`, `inc/logging.php` und `inc/web/csp.php`, ohne Datenbank, Authentifizierung oder Session.

| Anfrage | Antwort |
|---|---|
| Report-Only nicht aktiv | 404, keine Logzeile |
| nicht POST | 405 |
| falscher Inhaltstyp (zulässig: `application/csp-report`, `application/json`, `application/reports+json`) | 415 |
| mehr als 8 KiB (`Content-Length` oder tatsächlich gelesen) | 413 |
| über der Ratengrenze | 429 |
| ungültiger Bericht | 400, keine Logzeile |
| gültiger Bericht | 204 |

**Ratenbegrenzung ohne Session:** höchstens 60 Anfragen je Quelladresse und 600 insgesamt je 10 Minuten. Der Zähler liegt in einer gesperrten Statusdatei im temporären Verzeichnis (Fail-Closed). Ungültige Anfragen zählen mit. Die erste Überschreitung je Fenster ergibt eine Logzeile `event=csp_report_rate_limited`.

**Validierung:**
- Direktive aus einer festen Liste.
- Seite und Quelle als http(s)-URL ohne Query, Fragment und Zugangsdaten.
- Blockierte Quelle zusätzlich als Browser-Schlüsselwort (`inline`, `eval`, …) bzw. nur Schema (`data`, `blob`).
- Zeile/Spalte 0 bis 10⁷; Disposition `report`/`enforce`.
- Höchstens 10 Einträge im Reporting-API-Format.
- `script-sample`, `original-policy`, `referrer`, Cookies und Rohinhalt werden nicht protokolliert.

**Logzeile:** `event=csp_violation page="…" directive="…" blocked="…" source="…" line=N column=N disposition="…"` über `appLog('warning', …)`.

### 5.2 Testumgebung

| | |
|---|---|
| Code und Harness | Commit `504c13c8ae57ec0edd9e1f74cbd67c3a09b25091`, Tree `e5afd2a…` |
| Umgebung | ns1, PHP 8.5.4, MariaDB 11.8.6, eingebauter Browser über SSH-Tunnel |
| Aktivierung | ausschließlich in der isolierten Testkopie: `UIG_CSP_REPORT_ONLY=1 run.sh serve`, generierte Konfiguration unter `/tmp/dnsmgr-uig`, Server nur auf 127.0.0.1 |
| Installierte Anwendung | unverändert: `/etc/dnsmanager/ui_config.php` ohne `CSP_REPORT_ONLY`, Checkout `7cdd9a8`; ns2 und Produktion unberührt |

### 5.3 Header und Endpunkt (22/22)

| Fall | Ergebnis |
|---|---|
| H-1 bis H-4 | Dashboard und Records (admin), Zonen (zoneadmin), Anmeldung (anonym): `Content-Security-Policy-Report-Only` ist exakt die Zielrichtlinie plus `report-uri`; kein durchsetzender CSP-Header; die drei bestehenden Header vorhanden |
| E01–E09 | GET → 405; falscher Inhaltstyp → 415; 9 KB mit `Content-Length` → 413; 9 KB chunked ohne Länge → 413; kein JSON, unbekannte Direktive, Seite ohne http(s), falsche Typen, Liste ohne `csp-violation` → 400 |
| E10 | abgelehnte Anfragen erzeugen keine Logzeile |
| E11 | Gültiger Bericht mit Angriffsmustern (Zugangsdaten, Query und Fragment in der URL, Token in `referrer`/`original-policy`/`script-sample`, Cookie-Header, Zeilenumbruch, Anführungszeichen und ANSI-Steuerzeichen in `source-file`) → 204 und genau die bereinigte Zeile.<br>`page="https://dns.test/dnsmanager-ui/pages/records.php" directive="style-src-attr" blocked="inline" source="https://dns.test/dnsmanager-ui/assets/js/x.js" line=42 column=7 disposition="report"`<br>Kein Geheimnis und keine eingeschleuste Zeile im Log. |
| E12 | Anführungszeichen und Backslash maskiert, Zeilenumbruch in der URL entschärft (U2-A5), `data:` nur als Schema, ungültige Zahlen → 0 |
| E13 | Reporting-API-Format: 2 von 3 Einträgen (fremder Typ ignoriert), ohne Query und Sample |
| E14–E16 | Nach der Grenze nur noch 429 (70 Anfragen: 51 × 204 nach 9 vorherigen, dann 19 × 429), genau eine Logzeile `rate_limited`; auch ungültige Anfragen und Anfragen mit wechselnder Session-ID werden begrenzt |
| E17 | Kopie ohne `CSP_REPORT_ONLY`: Endpunkt 404 ohne Logzeile, Anmeldeseite ohne CSP-Header, die drei bestehenden Header vorhanden |
| E18 | Endpunkt legt keine Session an, setzt kein Cookie; 204 mit `Cache-Control: no-store` |

### 5.4 Absichtliche Testverletzung (R4)

Ausschließlich in der Testkopie wurde in `pages/dashboard.php` vorübergehend eingefügt:
`<div id="u2-csp-probe" style="outline: 3px solid rgb(255, 0, 0)">`.

| Fall | Nachweis |
|---|---|
| R4-1 Browser meldet | Konsole: Verstoß gegen `style-src 'self'`; „The policy is report-only, so the violation has been logged but no further action has been taken.“ |
| R4-2 Endpunkt nimmt an | `POST /dnsmanager-ui/csp_report.php → 204` |
| R4-3 bereinigte Daten | Genau eine Logzeile: `event=csp_violation page="http://localhost:18601/dnsmanager-ui/pages/dashboard.php" directive="style-src-attr" blocked="inline" source="http://localhost:18601/dnsmanager-ui/pages/dashboard.php" line=141 column=66 disposition="report"`. Keine Session-ID, keine Hashes, keine weiteren Inhalte. |
| R4-4 nicht blockiert | Die Umrandung ist wirksam (`rgb(255, 0, 0) 3px solid`). |
| R4-5 Rückbau | Originaldatei zurückgespielt (SHA-256 = Git-Blob des Commits, 0 Probe-Treffer). Testkopie gegen den Commit verglichen: Abweichungen nur bei den bekannten Harness-Anpassungen (Konfiguration, lokale Update-Quelle). Protokoll und Ratenstatus zurückgesetzt. |

### 5.5 Report-Only-Lauf: Ergebnis **0 Verstöße**

| Fall | Umfang | Verstöße |
|---|---|---|
| L1 | admin: 22 Seitenaufrufe (alle Seiten, Bearbeiten/Anlegen, Systemstatus, Update, Anmeldung) | 0 |
| L2 | zoneadmin: 15 Seitenaufrufe inkl. 403-Seiten | 0 |
| L3 | anonym: Anmeldung, Weiterleitungen geschützter Seiten, `logout.php` (405) | 0 |
| K1 Modal | Passwort und Deaktivieren (Benutzer), Passwort (DynDNS), Löschbestätigung (Records, abgebrochen), BIND-Reload (Server, abgebrochen): öffnen und schließen | 0 |
| K2 Offcanvas | 375 px: öffnen und über die Schaltfläche schließen; öffnen und navigieren (Menü danach geschlossen) | 0 |
| K3 Toast | Fehler-Toast nach serverseitig abgelehntem Record (ungültige IPv4, isolierte Test-DB) | 0 |
| K4 Collapse | Infobox Record-Typen, Infobox DynDNS: auf und zu | 0 |
| K5 Popover | Schaltfläche geklickt, nicht initialisiert (U2-B2), kein Element | 0 |
| F1–F6 Funktionen | DKIM-Umschaltung und -Upload, Server-Statuskarte, Token-Erzeugung, „= API-IP“, Systemstatus-Zeilen, DynDNS-Kopieren, Präfixfeld, Rollenumschaltung, Farbmodus hell/dunkel/System | 0 |

**Gesichertes Protokoll:** `csp-504c13c-20261114-173147.log` mit 0 Verstößen und 0 Ratenbegrenzungen. Dropdown und Tooltip werden in der Anwendung nicht verwendet.

**PHP-Meldungen im Lauf:** nur vorbestehende (U0-B2 `records.php:225`, U0-B3, U2-B3).

### 5.6 Regression des Endstands

| Prüfung | Ergebnis |
|---|---|
| Statisch S01–S05 (`504c13c`, 128 PHP-Dateien) | 5/5 |
| Golden gegen U0-Basis | Exit 0: 81 identisch, 37 informativ, 0 Vertragsabweichungen |
| Golden gegen den Webstand von `main` | Exit 0: 87 identisch, 31 informativ, 0 Vertragsabweichungen |
| Golden gegen den ersten U2-Lauf (`82d3b37`) | 117 identisch. Einzige informative Abweichung `page/admin/system_health`: Die Dateirechteliste der Testkopie (`SystemCheckService::filePermissionIssues`, rekursiv) enthält die zwei neuen Dateien. |

Golden-Läufe senden keinen CSP-Header; der Headervertrag ist unverändert.

**Aufräumen ns1:**
- keine Test-DBs, kein `/tmp/dnsmgr-uig`, kein Ratenstatus, keine Ports;
- Publish-Sperre frei;
- installierte Konfiguration ohne `CSP_REPORT_ONLY`, Checkout `7cdd9a8`.
