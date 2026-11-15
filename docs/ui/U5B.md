# U5b – Server, DynDNS, Benutzer, Systemstatus und Update im A+-Design

Stand: 14.11.2026. Umsetzung des freigegebenen U5-Plans, Paket **U5b**, auf Basis des gemergten U5a-Stands
(`main` `d2f364b`, PR #86).

**Umfang:** Server, DynDNS, Benutzer, Systemstatus und Update. Keine Änderungen an Core, Actions, Services, Schema
oder Sicherheitsmodell.

## 1. Umsetzung

| Datei | Änderung |
|---|---|
| `pages/servers.php` | Tabelle mit Diagnosestatus (`getServerStatusOverview`), DNS-/API-Adressen, lokal/aktiv; rechter Editor; **BIND-Reload je Server als POST-Formular mit Inline-Bestätigung** statt Modal |
| `pages/dyndns.php` | Tabelle; rechter Editor für Anlage/Bearbeitung; **Passwortänderung als Editor-Modus `pw_id`** statt Modal; Schnittstellenhinweis als aufklappbare Karte |
| `pages/users.php` (CRLF erhalten) | Tabelle mit Rolle, Status und Zonen; Editor für Anlage/Bearbeitung **nur admin (U0-B4)**; **Passwort als Editor-Modus `pw_id`** (eigenes Konto mit Wiederholung); **Deaktivieren mit Inline-Bestätigung** statt Modal |
| `pages/system_health.php` | Prüfungen als Zeilen mit Status-Kennzeichen, Details als `<details>` (ohne Skript); Aktualisieren-POST unverändert |
| `pages/update.php` | Ergebnis als Hinweis (aktuell / Update verfügbar / Fehler); Abruf und Auswertung unverändert |
| `templates/server_editor_form.php`, `dyndns_editor_form.php`, `user_editor_form.php` (neu) | ersetzen die je zwei bisherigen Formular-Templates; Ziele, Feldnamen und CSRF unverändert. Die Element-IDs der Serverformulare bleiben, sodass `server_add_form.js` und `server_edit_form.js` unverändert arbeiten. |
| `inc/web/ui_components.php`, `assets/js/components.js` | neuer Baustein `ui_confirm_action_button()`: Inline-Bestätigung (`<details>`) mit und ohne JavaScript gleich; `components.js` greift dort nicht ein |
| `assets/js/users_editor.js` (neu), `servers.js` | `users_editor.js` ersetzt `user_add_form.js`/`user_edit_form.js`; `servers.js` ohne Modal und ohne Klickskript der Statuskarte; `system_health.js` entfernt |
| `assets/css/components.css`, `lang/{de,en,es}.php` | Stile; 19 Schlüssel je Sprache |

## 2. Rollen, Schutzregeln und Session-Verhalten (unverändert)

- **Server, DynDNS, Systemstatus, Update:** nur admin (`requireRole`, 403 für zoneadmin).
- **Benutzer:**
  - Verwaltung (anlegen, bearbeiten, (de)aktivieren, löschen) nur admin.
  - zoneadmin sieht ausschließlich das eigene Konto und kann nur dessen Passwort ändern.
  - **U0-B4 behoben:** zoneadmin erhält auf `?edit_id=` kein Bearbeitungsformular mehr; die Action war ohnehin admin-only.
- **Eigene Passwortänderung:**
  - mit Passwortwiederholung;
  - danach wie bisher Abmeldung, Weiterleitung zur Anmeldung und Neuanmeldung (Action unverändert).
- **Letzter aktiver Administrator und eigenes Konto:**
  - Deaktivieren wird nicht angeboten für das eigene Konto und den letzten aktiven Admin.
  - Löschen wird nicht angeboten für den letzten aktiven Admin.
  - Beides wie bisher; maßgeblich bleibt der UserService.
- **Öffnen ohne Zustandsänderung:** Editoren (`add_new`, `edit_id`, `pw_id`) und Bestätigungen ändern beim Öffnen nichts. Erst die Bestätigung sendet den unveränderten POST mit CSRF.
- **Ohne JavaScript bedienbar:** Passwortänderungen (Benutzer, DynDNS), Deaktivierung, Aktivierung, BIND-Reload, Anlage und Bearbeitung.
  - Nur JavaScript-Komfort bleiben „Token generieren“ und „anzeigen“ (Hinweis im Formular; der Token kann eingegeben werden), die Synchronisation der API-IP und das Umschalten der Zonenauswahl nach Rolle.
- **Löschen:** weiterhin über das bestehende Bestätigungs-Modal (mit JS) bzw. die `<details>`-Rückfrage (ohne JS).

## 3. Bewusste Abweichungen (Golden gegen U0, `tests/ui/baseline/exceptions-u5b.json`)

Alle Actions sind unverändert: Status, Weiterleitung, Toasts, Logs und Datenbank aller `server/*`, `dyndns/*` und `user/*`-Fälle. Abweichungen gibt es nur in 12 Seitenverträgen:

| ID | Seiten | Abweichung |
|---|---|---|
| U5B-V1 | `admin/users*`, `zoneadmin/users*`, `admin/dyndns*` | Passwortformulare stehen nicht mehr als (versteckte) Modals auf jeder Seite, sondern im Editor-Modus `pw_id`. Felder unverändert: `id`, `password` und beim eigenen Konto zusätzlich `password_repeat`. |
| U5B-V2 | `admin/servers*` | BIND-Reload: je Server ein POST-Formular (`id`, CSRF) statt eines Modal-Formulars plus Schaltflächen-Formularen ohne Ziel |
| U5B-V3 | `admin/*_edit*` | Die Zeile des bearbeiteten Datensatzes behält ihre Aktionen; Löschen zusätzlich in der Editor-Fußzeile |
| U5B-V4 | `zoneadmin/users_edit2` | U0-B4: kein `user_update`-Formular für zoneadmin |

**Weitere bewusste Änderungen:**
- **U5B-B1:** Deaktivieren und BIND-Reload bestätigen mit und ohne JavaScript inline (`<details>`) statt per Modal.
- **U5B-B2:** Ohne JavaScript bleibt die Zonenauswahl im Benutzereditor sichtbar (Hinweis: nur für Zone-Admins wirksam). Mit JavaScript wird sie wie bisher bei Rolle admin aus- bzw. abgeschaltet.

## 4. Nachweise

**Umgebung:** ns1, PHP 8.5.4, MariaDB 11.8.6, BIND 9.20.24; isolierte Testkopie (`tests/ui`).

**Getesteter Stand:** `4d3e7205fa1060d0487d31a362ca0e537bb459d5` · Tree `9190837186c0ff4adb2bcb6cfdfdff94e1d894de` (Anwendung und Harness). Anwendungscode identisch seit `7e244d4`.

| Prüfung | Ergebnis |
|---|---|
| **U5b** (`tests/ui/u5b.php`) | **21/21**, siehe unten |
| **Golden** gegen U0-Basis mit `exceptions-u5b.json` | **Exit 0**: 72 identisch, 11 informativ, 35 freigegeben, **0 Vertragsabweichungen** |
| Regression U5a / U4-UI | 20/20 · 40/40 |
| Regression U4 (Typ-Matrix gegen `8447e35`) | 104 identisch, 12 dokumentiert, 0 undokumentiert |
| Builder/Parser/BIND · DynDNS-Kennzeichen | 73/73 · 17/17 unter REPEATABLE READ und READ COMMITTED |
| Statisch S01–S05 | 5/5 |

**`u5b.php` im Einzelnen:**
- **R01 – Rollenmatrix:** 14 Seiten (inkl. `add_new`, `edit_id`, `pw_id`) × anonym/admin/zoneadmin.
  - admin-Seiten liefern 403 für zoneadmin.
  - Benutzerverwaltungsformulare nur für admin.
  - Passwortformular nur für sichtbare Konten; zoneadmin sieht keine fremden Konten.
- **O01 – Öffnen ohne Zustandsänderung:** 10 Editoren bzw. Bestätigungen geöffnet, Datenbank und Sitzung unverändert.
- **P01–P06 – Passwort ohne JavaScript:**
  - admin für ein anderes Konto (Felder `id`, `password`); Anmeldung mit dem neuen Passwort möglich.
  - zoneadmin für das eigene Konto: mit Wiederholung, Weiterleitung zur Anmeldung, alte Sitzung beendet, Neuanmeldung.
  - Eigenes Konto mit abweichender Wiederholung: abgelehnt.
  - zoneadmin bekommt kein Formular für fremde Konten; eine manipulierte Anfrage wird abgelehnt.
  - DynDNS-Passwort über den Editor.
- **D01–D06 – Deaktivieren und Aktivieren ohne JavaScript, Schutzregeln:**
  - Deaktivieren mit Inline-Bestätigung; nicht für das eigene Konto.
  - Letzter aktiver Admin: weder Deaktivieren noch Löschen angeboten.
  - Manipuliertes Deaktivieren vom Service abgelehnt.
  - Mit zwei aktiven Admins ist der andere Admin deaktivierbar.
- **B01/B02 – BIND-Reload ohne JavaScript:** je Server ein POST-Formular (`csrf_token`, `id`), kein Modal; Ausführung über das ausgelieferte Formular erfolgreich (Agent-Platzhalter der Testkopie).
- **E01–E05 – Editoren:**
  - Server bearbeiten: leerer Token bleibt unverändert, der Token wird nie ausgegeben.
  - Server anlegen, DynDNS bearbeiten.
  - Benutzer bearbeiten (Zonen) und anlegen.

**Browser (Testkopie, CSP Report-Only, Stand `b2d7f65`; Anwendung wie getestet):**
- **Inline-Bestätigungen:** Deaktivieren und BIND-Reload öffnen die Rückfrage ohne Modal; Löschen weiterhin über das bestehende Modal.
- **Serverformulare:** Token generieren und anzeigen, „= API-IP“ und „lokaler Server“ (API-IP 127.0.0.1) funktionieren wie bisher.
- **Benutzereditor:** Zonenoptionen bei Rolle admin deaktiviert (Anlage) bzw. ausgeblendet (Bearbeitung).
- **Systemstatus:** Details aufklappbar.
- **CSP:** 0 Verstöße in allen Abläufen; Positivkontrolle (Inline-Style, Inline-Skript) gemeldet; keine PHP-Meldungen.

**Screenshots** (`docs/ui/screenshots/u5b/`, 51; je hell, dunkel und ohne JS):
- Server (Liste, Bearbeitung, Anlage);
- DynDNS (Liste, Bearbeitung, Passwort);
- Benutzer admin (Liste, Bearbeitung, Anlage, eigenes Passwort) und zoneadmin (Liste, eigenes Passwort);
- Systemstatus, Update;
- mobil (Server, Benutzer, Systemstatus).

**Aufräumen ns1:** keine Test-DBs, kein `/tmp/dnsmgr-uig`, keine Ports, Publish-Sperre frei, Checkout unverändert `7cdd9a8`.

## 5. Restrisiken und offene Punkte

- DynDNS-Restbefund aus U4 weiterhin offen für die Produktivabnahme.
- Für U6 vorgemerkt: ergänzender Layoutcheck bei 375 px (Edge headless rendert ab 492 px).
- Ohne JavaScript bleiben „Token generieren“ und „anzeigen“ sowie die API-IP-Synchronisation nicht verfügbar; der Token ist direkt eingebbar.

## 6. Review-Korrektur U5B-R1 – Bestätigungsfläche in responsiven Tabellen

**Befund (bestätigt, Stand `ccf3cc3`):**
- Beim letzten Server lag der Bestätigungsbutton des BIND-Reloads unterhalb des Tabellencontainers (Desktop 1440×900: Button 386–432 px, `.table-responsive` endet bei 377 px).
- Der Grund: Die Rückfrage war absolut positioniert, und `.table-responsive` hat `overflow: auto`. Die Tabelle erhielt dadurch eine innere Scrollleiste.
- Ein Klick auf die Buttonposition traf nicht den Button (`elementFromPoint` → Seitenhintergrund).

**Korrektur (`d3a7a6a`, nur `assets/css/components.css`):**
- Die geöffnete Inline-Bestätigung (`.dm-confirm-action`) liegt im normalen Layoutfluss unter der Schaltfläche (`position: static`). Sie vergrößert die Tabellenzeile, ragt nicht über den Tabellenrand und kann nicht abgeschnitten werden.
- Darstellung im A+-Stil mit Warnfarbe.
- POST-/CSRF-Vertrag, Markup und Löschbestätigung unverändert.

**Gezielter Browsertest (isolierte Testkopie `d3a7a6a`, CSP Report-Only):** je Fall die Rückfrage am Tabellenende öffnen, dann den Button prüfen und auslösen.

| Fall | Rückfrage ohne POST | Button im Container | Treffer an Buttonposition | Klick löst POST aus |
|---|---|---|---|---|
| BIND-Reload letzter Server · JS · 1440×900 | 0 POST | ja (keine innere Scrollleiste) | Button | ja („BIND Reload auf ns2.u0.test erfolgreich“) |
| Deaktivieren letzte Zeile (u0zone) · JS · 1440×900 | 0 POST | ja | Button | ja („Konto deaktiviert …“) |
| BIND-Reload · JS · 375×812 | 0 POST | ja, keine Seiten-Scrollleiste | Button | ja |
| Deaktivieren · JS · 375×812 | 0 POST | ja | Button | ja |
| BIND-Reload · ohne JS · 1440×900 | 0 POST | ja | Button | ja |
| Deaktivieren · ohne JS · 1440×900 | 0 POST | ja | Button | ja |
| BIND-Reload · ohne JS · 375×812 | 0 POST | ja | Button | ja, **echter Mausklick** |
| Deaktivieren · ohne JS · 375×812 | 0 POST | ja | Button | ja, **echter Mausklick** |

- Nach jedem Deaktivieren wurde das Konto wieder aktiviert; der Endzustand entspricht der Fixture.
- CSP: 0 Verstöße, keine PHP-Meldungen.

**Hinweise zur Messung:**
- „Ohne JS“ = Server der Testkopie ohne `<script>`-Elemente (0 Anwendungsskripte); die Messung lief über das Werkzeug des Browsers.
- Ohne JS mobil scrollt `scrollIntoView` in diesem Layout nicht zuverlässig. Deshalb wurde dort über die Fensterposition gescrollt und echt geklickt.

**Regression auf `d3a7a6a`:**
- U5b 21/21, U5a 20/20, U4-UI 40/40;
- Typ-Matrix 104 identisch, 12 dokumentiert, 0 undokumentiert;
- BIND 73/73; DynDNS 17/17 unter RR und RC;
- Golden Exit 0 (0 Vertragsabweichungen);
- Statik 5/5.
