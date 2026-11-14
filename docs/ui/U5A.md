# U5a – Dashboard, Zonenliste und Zoneneinstellungen im A+-Design

Stand: 14.11.2026. Umsetzung des freigegebenen U5-Plans (U5-E1 bis E12), Paket **U5a**, mit den verbindlichen
Präzisierungen vom 14.11.2026. Basis: `main` `7e4e178` (nach U4-UI, PR #85).

**Umfang:** ausschließlich Dashboard, Zonenliste und Zoneneinstellungen. Keine Änderungen an Core, Actions, Schema,
Services oder Sicherheitsmodell. U5b (Server, DynDNS, Benutzer, Systemstatus, Update) folgt auf Basis des gemergten
U5a-Stands.

## 1. Umsetzung

| Datei | Änderung |
|---|---|
| `pages/dashboard.php` | Neuaufbau mit U3-Bausteinen, siehe Abschnitt 2 |
| `pages/zones.php` | Eine Tabelle mit Gruppen Forward/Reverse, rechter Editor (`edit_id`/`add_new`), No-JS-Typübernahme bei der Anlage (POST mit `apply_type=1`, CSRF-geprüft, ohne Schreiboperation) |
| `templates/zone_editor_form.php` (neu) | Formular für Anlage und Bearbeitung; ersetzt `zone_add_form.php` und `zone_edit_form.php`. Ziele, Feldnamen, CSRF und `edit_version` unverändert. |
| `inc/web/zone_view.php` (neu) | Web-Hilfen:<br>• vollständige Zonenliste über die bestehende Pagination von `listZones()`;<br>• Anzeigestatus je Zone über `ZoneReadService::zoneDisplayStatus()`;<br>• Kennzeichen für Status, Veröffentlichung und Server. |
| `assets/js/zone_editor.js` (neu) | Ersetzt `zone_add_form.js`, `zone_edit_form.js` und `zones.js` |
| `assets/css/components.css` | Stile für Dashboard und Zonen auf den A+-Tokens |
| `inc/js_lang_keys.php`, `lang/{de,en,es}.php` | Zuordnung für `zone_editor.js`; 27 neue Schlüssel je Sprache, darunter **U0-B3 `zone_prefix_placeholder`** |

## 2. Verhalten

**Dashboard (nur bestehende Read-Services):**
- **admin:**
  - Systemstatus mit unveränderter Auswertung (SystemCheckService, DiagnosticReadService).
  - Globale Kennzahlen Zonen, Einträge und Benutzer (`SystemStatusReadService::getDashboardCounts`).
  - **Ausstehende Veröffentlichungen** (`pendingPublish`) und **Zonen mit Prüfproblemen** (Anzeigestatus Fehler/Warnung) als getrennte Kennzahlen.
  - Tabelle „Zonen mit Handlungsbedarf“ (Prüfproblem oder ausstehend).
- **zoneadmin:**
  - ausschließlich die berechtigten Zonen (ZoneReadService, vollständige Liste);
  - Kennzahlen „Meine Zonen“, Einträge (Summe `recordCount`), ausstehend, Prüfprobleme;
  - Tabelle „Meine Zonen“;
  - kein Systemstatus und keine Benutzerzahl (wie bisher).
- Keine neuen Service-Methoden und keine eigenen Statusregeln.

**Zonenliste:**
- Eine gemeinsame Tabelle mit Gruppen „Forward Lookup Zonen“ und „Reverse Lookup Zonen“.
- Spalten: Name (Link zu den Einträgen, bei Reverse mit Präfixlänge), Status, Einträge (`recordCount`), TTL, DNS-Server (Master/Slave, inaktiv), Beschreibung.
- Aktionen wie bisher: Einträge, Bearbeiten, Löschen mit Bestätigung (nur admin); Anlage nur admin.
- **Status:** ausschließlich `zoneDisplayStatus()` (Fehler, Warnung, Ausstehend, Gültig). Hat eine Zone ein Diagnoseproblem und ist zugleich eine Veröffentlichung ausstehend, wird „Ausstehend“ zusätzlich gezeigt. Beide Angaben stammen aus dem Core, sodass Diagnosefehler und ausstehende Veröffentlichung unterscheidbar bleiben.
- **Zonenprüfung:** Gesamtbewertung und Problemeinträge aus `getZoneStatusOverview()` als aufklappbare Karte. Ohne JavaScript ist sie ebenfalls aufklappbar; das bisherige Klickskript entfällt.

**Zoneneditor (rechts, U3/U4-Muster):**
- **Bearbeitung:**
  - alle bisherigen Felder, `edit_version` (S21), CSRF und Ziel `actions/zone_update.php`;
  - SOA-NS bei Forward-Zonen schreibgeschützt und dem Master folgend (wie bisher);
  - Löschen in der Fußzeile (nur admin).
- **Serverzuweisung:**
  - Nur admin bekommt Eingabefelder (`server_ids[]`, `master_server_id`).
  - zoneadmin sieht die vorhandene Zuweisung nur als Anzeige mit dem Hinweis „nur Administrator“. Der ZoneService ignoriert Serverangaben eines zoneadmin weiterhin (A06).
- **Anlage (admin):**
  - alle bisherigen Felder, Ziel `actions/zone_add.php`;
  - Präfixlänge, SOA-Domain und angezeigter Primary NS werden je Typ ein- bzw. ausgeblendet, aber wie bisher immer gesendet; der ZoneService wertet sie nur für Reverse-Zonen aus.
  - **Ohne JavaScript:** „Typ übernehmen“ sendet per POST an `pages/zones.php?add_new=1`. Die Seite rendert den Editor mit allen eingegebenen Werten neu, ohne Schreiboperation; eine leere Präfixlänge erhält die bisherige Vorgabe 24 bzw. 56.
- **Master-Prüfung im Browser:** nur Bedienhilfe, jetzt als Hinweis im Formular statt `alert()`. Maßgeblich bleibt der ZoneService. Ohne Server-Eingabefelder (zoneadmin) gibt es keine Prüfung.
- Keine Vorschau und kein DIFF für Zoneneinstellungen.

## 3. Bewusste Abweichungen

### Golden gegen U0-Basis (`tests/ui/baseline/exceptions-u5a.json`)
Alle Zonen-Actions (`zone/*`) sind unverändert: Status, Weiterleitung, Toasts, Logs und Datenbank. Abweichungen gibt es nur in 6 Seitenverträgen:

| ID | Seite | Abweichung |
|---|---|---|
| U5A-V1 | `admin/dashboard`, `zoneadmin/dashboard` | zusätzliche Links zu Einträgen bzw. Zoneneinstellungen in den Tabellen |
| U5A-V2 | `admin/zones_add` | Das Anlageformular ist unverändert (gleiche Felder, Ziel, Kodierung). Der Abbrechen-Link ist relativ (`pages/zones.php` statt `/dnsmanager-ui/pages/zones.php`). |
| U5A-V3 | `admin/zones_edit1`, `admin/zones_edit2`, `zoneadmin/zones_edit1` | Die Zeile der bearbeiteten Zone behält ihre Links (Einträge, Bearbeiten); vorher Speichern/Abbrechen in der Zeile |
| U5A-V4 | `zoneadmin/zones_edit1` | Bearbeitungsformular ohne `server_ids[]`/`master_server_id`; Serverzuweisung nur als Anzeige |
| U5A-L1 | `admin/zones_add` | `log`/`problems`: Die Warnung U0-B3 (`Undefined array key "zone_prefix_placeholder"`) entfällt |

### Weitere bewusste Änderungen
- **U5A-B1:** Bei der Anlage leert ein Typwechsel nach „Forward“ die Präfixlänge nicht mehr; der Wert bleibt erhalten und wird für Forward-Zonen vom ZoneService ignoriert.
- **U5A-B2:** Der Hinweis der Master-Prüfung erscheint im Formular statt als `alert()`.
- **U5A-B3:** Die Zonenprüfungs-Karte ist ein `details`-Element (auch ohne JavaScript aufklappbar).

## 4. Nachweise

**Umgebung:** ns1, PHP 8.5.4, MariaDB 11.8.6, BIND 9.20.24. Isolierte Testkopie (`tests/ui`); installierte Anwendung unberührt.

**Getesteter Stand:** `ebfe7a3a8b1c7a76521b9b9e194c7b752ec76bbf` · Tree `5250845c25728bc667309ab7a12f8f00cbb66cd5` (Anwendung und Harness). Anwendungscode identisch seit `1150ade`.

| Prüfung | Ergebnis |
|---|---|
| **U5a** (`tests/ui/u5a.php`) | **20/20**, siehe unten |
| **Golden** gegen U0-Basis mit `exceptions-u5a.json` | **Exit 0**: 72 identisch, 23 informativ, 23 freigegeben, **0 Vertragsabweichungen** |
| Regression U4 (Typ-Matrix gegen `8447e35`) | 104 identisch, 12 dokumentiert, 0 undokumentiert |
| Regression U4-UI (`u4ui.php`) | 40/40 |
| Builder/Parser/BIND · DynDNS-Kennzeichen | 73/73 · 17/17 unter REPEATABLE READ und READ COMMITTED |
| Statisch S01–S05 | 5/5 (131 PHP-Dateien; Sprachschlüssel vollständig, U0-B3 behoben) |

**`u5a.php` im Einzelnen:**
- **R01/R02 – Rollenmatrix:** 6 Seiten × anonym/admin/zoneadmin.
  - Status jeweils korrekt.
  - Anlage und Löschen nur admin; fremde Zonen ohne Formular.
  - Serverzuweisung nur für admin als Eingabe; `edit_version` und CSRF vorhanden.
  - Das Anlageformular enthält alle 16 bisherigen Felder.
- **T01–T04 – No-JS-Typübernahme:**
  - ohne CSRF 403;
  - mit CSRF 200, Datenbank unverändert, keine Toasts, keine PHP-Meldungen;
  - **alle Werte erhalten** (Name, Typ, TTL, SOA-Werte, Server und Master, DynDNS, Beschreibung mit Sonderzeichen);
  - Reverse-Felder serverseitig eingeblendet.
- **A01–A06 – No-JS-Anlage und -Bearbeitung über die ausgelieferten Formulare:**
  - Forward-Anlage; Reverse-Anlage nach Typübernahme.
  - admin-Bearbeitung von TTL und Serverzuweisung.
  - zoneadmin-Bearbeitung von TTL und Beschreibung; die Serverzuweisung bleibt unverändert, auch bei manipuliert gesendeten Serverfeldern.
- **S01/S02 – S21:** Ein Formular mit veraltetem `edit_version` wird abgelehnt („Die Zone wurde zwischenzeitlich geändert …“), nichts geändert. Geprüft für admin sowie für zoneadmin nach einer Änderung durch admin.
- **D01–D04 – Dashboard:**
  - admin: Kennzahlen gleich den Datenbankwerten.
  - Szenario Fehler / Warnung + ausstehend / nur ausstehend → 2 ausstehend, 2 Prüfprobleme (1 Fehler, 1 Warnung).
  - Statuskennzeichen je Zone gleich `zoneDisplayStatus()`.
  - zoneadmin: nur die eigene Zone (Einträge 25 statt global 30), keine fremden Zonen, kein Systemstatus.
- **G – mehr als 500 Zonen:**
  - Zonenliste und Dashboard für admin mit 603 und für zoneadmin mit 551 Zonen.
  - Alle berechtigten Zonen erreichbar, keine fremden.
  - Ausstehend-Zahl gleich Datenbank; rund 75 ms je Seite.

**Browser (Testkopie, CSP Report-Only, Stand `db28512`; Anwendung wie getestet):**
- **Mit JS:**
  - Typwechsel behält Werte, setzt Suffix und Vorgabe der Präfixlänge;
  - SOA-Mail aus dem Zonennamen; Primary NS aus dem Master;
  - Prüfhinweis im Formular; Löschbestätigung über das bestehende Modal;
  - zoneadmin-Editor wird nicht blockiert.
- **Ohne JS:** Anlage mit Typübernahme behält alle Eingaben (siehe T03).
- **CSP:**
  - **0 Verstöße** bei Dashboard, Zonenliste, Bearbeitung, Anlage mit allen Typwechseln und zoneadmin-Editor.
  - Positivkontrolle: ein absichtlicher Inline-Style und ein Inline-Skript wurden gemeldet.
  - Keine PHP-Meldungen.

**Screenshots** (`docs/ui/screenshots/u5a/`, 36; je hell, dunkel und ohne JS):
- Dashboard admin und zoneadmin;
- Zonenliste admin und zoneadmin;
- Bearbeitung Forward, Reverse und zoneadmin;
- Anlage Forward und Reverse;
- mobil (Dashboard, Zonenliste, Editor).

**Aufräumen ns1:** keine Test-DBs, kein `/tmp/dnsmgr-uig`, keine Ports, Publish-Sperre frei, Checkout der installierten Anwendung unverändert `7cdd9a8`.

## 5. Restrisiken und offene Punkte

- Der DynDNS-Restbefund aus U4 (mehrdeutig reservierte Records) bleibt für die Produktivabnahme offen.
- Mobile Darstellung: Edge headless rendert mindestens 492 px Breite; die Aufnahmen „mobil“ entsprechen dem Bootstrap-Bereich unter 576 px.
- Die Golden-Basis ist weiterhin U0 (`golden-1e37515.json`); die freigegebenen Abweichungen der Pakete U3 bis U5a sind in `exceptions-u5a.json` zusammengefasst.
