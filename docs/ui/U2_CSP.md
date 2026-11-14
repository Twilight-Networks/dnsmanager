# UI-Modernisierung U2 – CSP-Vorbereitung

Stand: 14.11.2026. Ziel gemäß [`docs/UI_GUIDELINES.md`](../UI_GUIDELINES.md), Abschnitt 4:
- keine Inline-Skripte, Inline-Ereignishandler, `style`-Attribute oder `javascript:`-URLs;
- Sprachtexte für JavaScript nur seitenbezogen als nicht ausführbarer JSON-Datenblock.

**Abgrenzung:**
- Eine CSP wird in U2 **nicht gesendet**; die Durchsetzung bleibt U6 vorbehalten.
- Report-Only folgt erst nach gesonderter Freigabe der Testkonfiguration (Abschnitt 5).
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
| U2-B1 (vorbestehend) | Die PHP-Warnung `zone_prefix_placeholder` (U0-B3) tritt weiterhin auf; vorgesehen für U5. |

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

## 5. Offen: Report-Only-Lauf (Freigabe ausstehend)

Laut Leitfaden 4 folgt nach dieser Bereinigung ein Lauf mit `Content-Security-Policy-Report-Only` und eigenem Protokoll-Endpunkt. Der Lauf erfolgt erst nach Freigabe der Testkonfiguration durch Thomas. Vorschlag siehe PR-Beschreibung.
