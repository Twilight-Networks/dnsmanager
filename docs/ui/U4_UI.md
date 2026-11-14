# U4-UI – Record-Verwaltung im A+-Design mit serverseitiger Vorschau und Live-DIFF

Stand: 14.11.2026. Umsetzung des freigegebenen Integrationsplans E1–E10 mit den verbindlichen Präzisierungen vom
14.11.2026. Basis: `main` `8447e35` (nach U4-Core, PR #84).

**Umfang:** Record-Verwaltung (`pages/records.php`) mit Zonenauswahl, Herkunfts- und Klassifizierungskennzeichen,
rechtem Editor, serverseitiger Vorschau und Live-DIFF. Zonenliste und Zoneneinstellungen bleiben für U5.

**Einzige Änderung außerhalb des UI-Bereichs (E3, freigegeben):** reine Funktion `zone_file_record_line()` in
`inc/deploy/bind_file_generator.php`. Keine Schemaänderung; keine Änderung an RecordService, Builder, Parser,
Read-Services, Berechtigungen, Schutzregeln, Löschen, `bulk_ids` oder Publish.

## 1. Umsetzung

| Datei | Änderung |
|---|---|
| `inc/deploy/bind_file_generator.php` | **E3:** `zone_file_record_line(array $r, $zoneTtl)` extrahiert, ohne Datei- oder Datenbankzugriff. Format bytegenau übernommen: Tabulatoren, Typ in Großbuchstaben, TTL des Records bzw. der Zone, Inhalt unverändert. `generate_zone_file()` ruft sie je Record auf; sonst unverändert. |
| `inc/web/record_editor.php` (neu) | Gemeinsame Web-Hilfen für Speichern und Vorschau:<br>• DKIM-Upload und TTL-Auflösung wie bisher in den Actions;<br>• `recordEditorPreview()`, ausschließlich lesend;<br>• Vorschaudarstellung über die U3-DIFF-Bausteine;<br>• Herkunft und Klassifizierung (Darstellung);<br>• vollständige Zonenliste über die vorhandene Pagination. |
| `actions/record_preview.php` (neu) | JSON-Endpunkt der Live-Vorschau:<br>• nur POST (sonst 405, `Allow: POST`), CSRF, Rollen admin und zoneadmin;<br>• fremde oder unbekannte Zonen bzw. Records: einheitlich 403 `{"status":"denied"}`;<br>• Sitzung wird ohne Änderung früh geschlossen. |
| `actions/record_add.php`, `record_update.php` | Nutzen `recordEditorApplyDkimUpload()` bzw. `recordEditorTtlCreate()`/`recordEditorTtlUpdate()`, sodass Vorschau und Speichern dieselbe Aufbereitung verwenden. Toasts, Logtexte, Redirects und Service-Aufrufe unverändert. |
| `pages/records.php` | Neu aufgebaut im A+-Muster, siehe Abschnitt 2. |
| `templates/record_editor_form.php` (neu) | Einheitliches Formular für Anlage und Bearbeitung (E5); ersetzt `record_add_form.php` und `record_edit_form.php`. |
| `assets/js/record_editor.js` (neu) | Typwechsel, entprellte Live-Vorschau mit Folgenummern, DIFF über `DmDiff`. Ersetzt `record_add_form.js`, `record_edit_form.js`, `records.js` und `dkim_helpers.js`. |
| `assets/css/components.css` | Stile der Record-Verwaltung auf den A+-Tokens (hell/dunkel); erster Editor-Abschnitt ohne Trennlinie. |
| `inc/js_lang_keys.php`, `lang/{de,en,es}.php` | Schlüssel der entfernten Skripte entfernt; 34 neue Sprachschlüssel in allen drei Sprachen. Texte werden serverseitig als JSON übergeben, `record_editor.js` ruft kein `lang()` auf. |

## 2. Verhalten

**E1 – Zonenauswahl:**
- Nur berechtigte Zonen aus `ZoneReadService::listZones()`, jeweils mit `recordCount`.
- Alle Seiten der vorhandenen Pagination werden gelesen (je 500, bis `hasMore` false ist). Damit bleibt auch bei mehr als 500 Zonen jede berechtigte Zone erreichbar.
- Keine neue Core-Funktion.
- Ohne JavaScript über „Öffnen“, mit JavaScript sofort beim Wechsel.

**E2 – Einheitliche Vorschau:**
- `recordEditorPreview()` dient dem Endpunkt und dem No-JS-POST auf `records.php` (`preview=1`, CSRF-geprüft, nur passend zu Zone und Editor der URL).
- Verwendet werden dieselben Rohfelder, derselbe Builder-Kontext (`create`/`update`, Zone, gespeicherter Record aus dem Read-Service), dieselbe Typprüfung (`validateDnsRecord` mit dem Prüftyp des Builders), dieselbe TTL-Auflösung und dieselbe Zonendateizeile (E3).
- Zusätzlich sichtbar, weil die Zonendatei sonst nicht erzeugt bzw. die Änderung abgelehnt würde:
  - Steuerzeichen;
  - bekannte Schutzregeln (Glue: Name und Typ; geschützter NS: Name und Inhalt; DynDNS: Name, Typ und IP).
- Hinweis im Editor: Die Vorschau garantiert keine erfolgreiche Speicherung. Maßgeblich bleibt der RecordService (u. a. Duplikate, DynDNS-Reservierungen, Zonendateiprüfung).

**E4 – Live-DIFF:**
- Die Altzeile stammt immer aus dem gespeicherten Record; übermittelte „Altwerte“ werden ignoriert (V15).
- Entprellung 300 ms. Jede Anfrage trägt eine Folgenummer; die laufende Anfrage wird abgebrochen.
- Nur die Antwort der neuesten Anfrage wird dargestellt. Während der Aktualisierung ist der Bereich als veraltet gekennzeichnet (`aria-busy`).
- Bei ungültiger oder unvollständiger Eingabe gibt es keinen DIFF, nur die Fehlerliste.
- Neuanlage zeigt nur die neue Zeile.
- Lange DKIM-Werte werden mit JavaScript gefaltet (U3: der Änderungsbereich bleibt sichtbar); ohne JavaScript vollständig serverseitig.

**E5 – Formular:**
- Alle Builder-Felder sind benannt, auch DKIM (`dkim_selector`, `dkim_key_type`, `dkim_hash_algos`, `dkim_flags`, `dkim_key`).
- Je Typ ein Feldbereich (`<fieldset data-dm-type>`); Bereiche anderer Typen sind deaktiviert und werden nicht gesendet.
- Bei der Anlage schaltet das Skript beim Typwechsel um; ohne JavaScript übernimmt „Vorschau“ den Typ und behält alle Eingaben.
- Bearbeitung: nur der Bereich des gespeicherten Typs; gesperrte Felder sind schreibgeschützt.
- Keine Zusammensetzung im Browser. Das verborgene `is_dkim` der Anlage entfällt (Ursache von U0-B1).
- Ziele und Feldnamen der Actions bleiben unverändert.

**E6/E7 – Schutz, Herkunft, Klassifizierung:**
- Herkunft:
  - **System** bzw. **System · Glue** (`server_id` gesetzt), **Manuell** bzw. **Glue**;
  - Schild-Symbol bei Schutz (Glue, geschützter NS);
  - **DynDNS** nur bei `isDyndns`.
- Klassifizierung als zusätzliche Kennzeichen nach der TXT-Semantik (zusammengesetzte Zeichenketten):
  - **SPF:** Typ SPF oder Wert beginnt mit `v=spf1` (gefolgt von Leerzeichen oder Ende);
  - **DMARC:** Name `_dmarc[.sub]` und Wert beginnt mit `v=DMARC1`;
  - **DKIM:** Kennzeichen des Read-Service und DKIM-Inhalt.
- Löschen und Auswahl gibt es nur, wo der RecordService es zulässt: nicht bei Glue, geschütztem NS oder DynDNS-verwalteten Records (dort wäre jedes Löschen abgelehnt worden).
- Hinweise im Editor: Glue „Name und Typ geschützt“; NS und DynDNS „nur die TTL ist änderbar“.

**E8 – Altbefunde im U4-Pfad:**
- U0-B1: TXT ohne JS speicherbar; das verborgene `is_dkim` entfällt.
- U0-B2: Diagnose ohne Meldungstext erzeugt keine Deprecation mehr.
- U2-B2: Das nicht initialisierte Popover ist durch einen sichtbaren Hilfetext ersetzt.
- U2-B4: Die Debug-Ausgaben sind mit den alten Skripten entfernt.
- U2-B3: Mit der Review-Korrektur U4U-R2 behoben (ausdrücklich freigegeben), siehe Abschnitt 6.

## 3. Bewusste Abweichungen

### Typ-Matrix gegen `main` `8447e35` (`tests/ui/records/matrix_deviations_u4ui.json`)
| ID | Abweichung | Fälle |
|---|---|---|
| U4U-A1 | Die doppelte JS-Zusammensetzung entfällt: ein absolutes Ziel mit Zonenmodus bleibt bei CNAME/MX-Anlage mit JS unverändert (`web.other.test.` statt `web.other.test.u0.test.`). Ohne JS war das schon so. | `add_cname_abszone/js`, `add_mx_abszone/js` |
| U4U-A2 | DKIM-Anlage ohne JS funktioniert (Felder benannt); vorher Fehler „Record konnte nicht verarbeitet werden“. Inhalt identisch mit dem Weg mit JS. | `add_dkim_{rsa,ed,252,256,4096}/nojs` |
| U4U-A3 | DKIM-Anlage mit JS und TTL „Auto“ speichert 3600 s statt 300 s. Das alte Skript stellte den Typ vor dem Absenden auf TXT um; jetzt wird DKIM gesendet, und „Auto“ folgt `ttl_defaults.php` (`DKIM => 3600`), wie bisher schon beim Upload und jetzt auch ohne JS. Inhalt unverändert. **Bitte im Review bestätigen.** | `add_dkim_{rsa,ed,252,256,4096}/js` |

### Golden gegen U0-Basis (`tests/ui/baseline/exceptions-u4ui.json`)
Nur die 8 Records-Seiten (admin: `records_add`, `records_edit3`, `records_z1`–`z3`; zoneadmin: `records_add`, `records_edit3`, `records_z1`). Freigegebene Teile: `contract`, `log`, `problems`.

| ID | Abweichung |
|---|---|
| U4U-V1 | Neues GET-Formular der Zonenauswahl (`pages/records.php`, `zone_id`) auf allen Records-Seiten |
| U4U-V2 | Anlageformular: DKIM-Felder benannt; das verborgene `is_dkim` entfällt. Alle übrigen Felder, Ziel und Kodierung unverändert. |
| U4U-V3 | Bearbeitungsformular enthält nur die Felder des gespeicherten Typs (z. B. A: `id`, `zone_id`, `type`, `name`, `content`, `ttl`, `csrf_token`) |
| U4U-V4 | Kein Löschformular für DynDNS-verwaltete Records (RecordService lehnt ab). Im Editor zusätzlich ein Löschformular in der Fußzeile, sofern löschbar. |
| U4U-V5 | Die Zeile des bearbeiteten Records behält ihren „Bearbeiten“-Link (vorher Speichern/Abbrechen in der Zeile). |
| U4U-L1 | `log`/`problems`: Die Deprecation U0-B2 (`htmlspecialchars(null)`) entfällt. |

Alle Record-Actions (`record/*`) sind im Golden unverändert. Identisch bleiben dort Status, Weiterleitung, Toasts, Logs und Datenbank.

### Weitere bewusste Verhaltensänderungen (ohne Matrix-/Golden-Abdeckung)
- **U4U-B1:** Eine gespeicherte TTL außerhalb der Auswahlliste bleibt im Bearbeitungsformular als eigener Wert wählbar. Vorher wurde „Auto“ vorausgewählt, und Speichern setzte die TTL still um.
- **U4U-B2:** Die Diagnosekarte erscheint nur, wenn Diagnosen vorliegen.

## 4. Nachweise

**Umgebung:** ns1, PHP 8.5.4, MariaDB 11.8.6, BIND 9.20.24. Isolierte Testkopie über `tests/ui` (eigene Datenbanken, eigener DB-Benutzer); installierte Anwendung unberührt.

**Getesteter Anwendungsstand:** `1c8a678c835c9ac6870fee6ffd01d9da9820d32e` · Tree `cafd893f61e20ed34e74cc4981895b6a55944b50` (nach U4U-R1/R2); Harness identisch. Die Zahlen unten stammen aus dem Lauf auf diesem Stand; die Browser-, CSP- und Screenshot-Nachweise vor R1/R2 von `c511349`.

| Prüfung | Ergebnis |
|---|---|
| **Typ-Matrix** (58 Fälle × mit/ohne JS, neue Formulare und `record_editor.js`) gegen `main` `8447e35` | **104 identisch, 12 dokumentiert (U4U-A1 bis A3), 0 undokumentiert** |
| **Golden** gegen U0-Basis mit `exceptions-u4ui.json` | **Exit 0**: 72 identisch, 29 informativ, 17 freigegeben, **0 Vertragsabweichungen**; alle Record-Actions unverändert |
| **U4-UI** (`u4ui.php`) | **42/42**, siehe Aufschlüsselung unten und Abschnitt 6 (D01–D08) |
| Builder/Parser/BIND | 73/73 (unverändert seit U4-Core) |
| DynDNS-Kennzeichen | 17/17 unter REPEATABLE READ, 17/17 unter READ COMMITTED |
| Statisch S01–S05 | 5/5 (131 PHP-Dateien; keine Inline-Handler, Styles oder Skripte; Sprachschlüssel vollständig) |

**Aufschlüsselung `u4ui.php`:**
- **V01–V18 (Vorschau-Endpunkt):**
  - GET/PUT 405 mit `Allow: POST`; ohne bzw. mit falschem CSRF 403; anonym 302.
  - Antwortvertrag und Folgenummer; ungültige IPv4 ohne DIFF.
  - zoneadmin: fremde Zone, unbekannte Zone, fremder Record in eigener bzw. fremder Zone, unbekannter Record jeweils 403 mit **identischer** Antwort; eigener Record ok.
  - Altzeile aus der Datenbank trotz übermittelter Altwerte.
  - Glue-Umbenennung und DynDNS-IP-Änderung ungültig, DynDNS nur TTL gültig.
- **W01–W04 (keine Schreibzugriffe):** 59 Vorschauen (alle JS-Fälle und No-JS-POST) ändern Datenbank, Zonen-Änderungskennzeichen, Serial, Sitzung und Toasts nicht; keine Logzeilen und keine PHP-Meldungen.
- **Z01 (Vorschau ↔ Zonendatei, bytegenau):** Für alle **116** Fall-Varianten Vorschau → Speichern über die Action → `generate_zone_file()`. Jede vorhergesagte Zeile steht **exakt** in der erzeugten Zonendatei; bei Bearbeitung auch die bisherige Zeile vorher.
- **Z02/Z03:**
  - 8 ungültige Eingaben: Vorschau ungültig ohne DIFF, Speichern ändert nichts. Dabei MX-Priorität, SRV-Ziel, DKIM-Schlüssel, DKIM-`h`-Injektion, NAPTR, Glue-Umbenennung und geschützter NS.
  - Duplikat: Vorschau gültig, Speichern vom RecordService abgelehnt (Grenze der Vorschau, im Editor vermerkt).
- **R01:** Für alle 30 Records ergibt das unveränderte Bearbeitungsformular die Zeile der Zonendatei und „keine Änderung“.
- **N01–N05 (No-JS-Vorschau):**
  - ohne CSRF 403;
  - gleiche Zeile wie der Endpunkt;
  - alle Eingaben und der Feldbereich bleiben erhalten;
  - Fehlerliste ohne DIFF;
  - zoneadmin bei fremder Zone 403.
- **G (Zonenauswahl):**
  - admin: 603 Zonen, alle erreichbar.
  - zoneadmin mit 550 zusätzlichen Zuweisungen: 551 Zonen, keine fremden.
  - jeweils rund 75 ms.
- **E3:** `generate_zone_file()` von `8447e35` und vom neuen Stand liefern für alle Zonen **bytegleiche** Dateien, `named-checkzone` ok. Darin enthalten ein DKIM-Record RSA 4096 (774 Zeichen) mit TTL-Rückfall auf die Zone.

**Browser (Testkopie, Stand `c511349`; Formulare und Skript seit `5685f9a` unverändert):**
- **Live-Vorschau:**
  - SRV-Port-Änderung als DIFF.
  - MX mit ungültiger Priorität: Fehler ohne DIFF; nach Korrektur nur die neue Zeile.
  - DKIM 4096 gefaltet mit Umschalter.
  - Gesendet werden nur die Felder des aktiven Typs.
- **Schnelle Eingaben und veraltete Antworten:**
  - Vier Eingaben innerhalb der Entprellung ergeben eine Anfrage.
  - Drei Anfragen mit künstlich umgekehrter Antwortreihenfolge (3, 2, 1, Abbruch ignoriert): angezeigt bleibt die neueste Eingabe.
  - Während der Aktualisierung `aria-busy="true"`.
- **No-JS (Server ohne Skripte):** Typwechsel über „Vorschau“ behält die Eingaben; serverseitiger DIFF.
- **CSP Report-Only** (nur Testkopie):
  - 0 Verstöße bei Liste, Bearbeitung, Anlage mit allen Typwechseln, Live-Vorschau, Auswahl/Massenaktionsleiste und Löschbestätigung (Modal).
  - Positivkontrolle: ein absichtlicher Inline-Style und ein Inline-Skript wurden gemeldet (`style-src-attr`, `script-src-elem`).
- **Massenlöschung:**
  - Auswahl von 2 Records füllt `bulk_ids` mit `102,5`.
  - Die Bestätigung öffnet das bestehende Modal.
  - Geschützte Records haben keine Auswahl.
- **375 px:** kein horizontales Scrollen der Seite (Tabelle scrollt im Container), Editor einspaltig.

**Screenshots** (`docs/ui/screenshots/u4/`, 33):
- je hell, dunkel und ohne JS;
- Liste (admin, zoneadmin, Reverse-Zone);
- Bearbeitung SRV, DKIM, DynDNS und Glue;
- Anlage MX und DKIM;
- mobil (Liste, Editor).

**Aufräumen ns1:** keine Test-DBs, kein `/tmp/dnsmgr-uig`, keine Ports, Publish-Sperre frei, Checkout der installierten Anwendung unverändert `7cdd9a8`.

## 5. Restrisiken und offene Punkte

- **DynDNS „mehrdeutig“ (für die Produktivabnahme offen):** Im Review als vorbestehende Einschränkung akzeptiert; eine Erweiterung der DynDNS-Core-Architektur ist nicht Teil dieses PR (ggf. separater Read-PR). Für A/AAAA-Records an reservierten, aber nicht eindeutig zugeordneten Namen lehnt der RecordService jede Änderung ab. Ohne zusätzliche Core-Information kann die Oberfläche das nicht vorab erkennen: Diese Records zeigen „Bearbeiten“/„Löschen“, das Speichern wird mit der bisherigen Meldung abgelehnt.
- **Vorschau nicht autoritativ:** Duplikate, DynDNS-Reservierungen beim Anlegen und `named-checkzone` prüft weiterhin erst der RecordService. Das ist im Editor ausdrücklich vermerkt; Z03 belegt es am Duplikat.
- **Bearbeitung ohne Zielmodus:** wie bisher; relative Ziele werden mit „.“ ergänzt, absolute bleiben.
- **JS-Erfassung:** `js_posts_u4ui.json` stammt von `5685f9a`. Formulare und Skript sind bis zum endgültigen Stand unverändert (danach nur CSS-Klassen der Tabelle, ein Kommentar und Tests).

## 6. Review-Korrekturen U4U-R1 und U4U-R2

**U4U-R1 – Veraltete Vorschau während der Entprellung (`record_editor.js`):**
- Bisher wurde eine laufende Vorschau erst beim Start der nächsten Anfrage ungültig. Eine alte Antwort, die nach einer neuen Eingabe, aber vor Ablauf der Entprellung eintraf, aktualisierte den DIFF und beendete den Pending-Status.
- Jetzt erhöht bereits jedes Eingabeereignis die Folgenummer und bricht eine laufende Anfrage ab. Die Anfrage nach der Entprellung verwendet diese Nummer. Ältere Antworten werden verworfen und verändern weder DIFF noch Pending-Status.
- **Test `tests/ui/records/preview_race.js`** (wiederholbar im Serve-Modus unter `/__ui/`, `fetch` verzögert, Abbruch ignoriert):
  - T1 Entprellung: vier schnelle Eingaben → eine Anfrage, letzte Eingabe angezeigt.
  - **T2 (neu):** Die alte Antwort trifft nach der neuen Eingabe und vor der neuen Anfrage ein (z. B. `req1@1711 res1@1782 req2@2050`). Der DIFF bleibt unverändert, `aria-busy` bleibt `true`; danach zeigt der DIFF die neue Eingabe.
  - T3 vertauschte Antwortreihenfolge (`res3,res2,res1`) → neueste Eingabe angezeigt (bisheriger Test, erhalten).
- **Ergebnis:** Auf `1c8a678` bestehen T1, T2 und T3 in 4 von 4 Läufen.
- **Gegenprobe** mit dem bisherigen Head `9c2b669`: **T2 schlägt fehl**. Die alte Antwort überschrieb den DIFF, `aria-busy` wurde `false`; T1 und T3 bestehen dort. Der Test erkennt den Befund also.
- CSP Report-Only während der Läufe: 0 Verstöße, keine PHP-Meldungen.

**U4U-R2 – PHP-Warnung beim DKIM-Upload (U2-B3, `inc/dkim_helpers.php`, ausdrücklich freigegeben):**
- **Ursache:** `parseDKIMFile()` gab `'domain' => $domain` zurück, ohne `$domain` zu bestimmen. Eine OpenDKIM-Datei (`selector._domainkey IN TXT ( … )`) enthält keine Domain; der Wert war daher immer `null`, begleitet von einer PHP-Warnung.
- **Korrektur:** `$domain = null;` vor der Rückgabe, mit Kommentar. Rückgabevertrag unverändert (Schlüssel `selector`, `domain` = null, `key`, optionale Parameter). Keine weiteren Änderungen an den DKIM-Hilfsfunktionen.
- **Nachweis mit echter Datei** (`opendkim-genkey -s u4dkim -d u0.test -b 2048`, privater Schlüssel sofort gelöscht; `opendkim-tools` dafür auf der Test-VM ns1 installiert, kein Dienst):

| ID | Ergebnis |
|---|---|
| D01 | Echte DKIM-Datei erzeugt (RSA 2048, 501 Bytes) |
| D02 | Vorschau mit Datei-Upload gültig (`u4dkim._domainkey 3600 IN TXT "v=DKIM1; k=rsa;" "p=MII…"`), keine Schreibzugriffe |
| D03 | Vorschau ohne PHP-Warnung |
| D04 | Speichern mit Datei-Upload legt den Record an (Erfolgs-Toast) |
| D05 | Vorschau = gespeicherter Record = Zeile der erzeugten Zonendatei (bytegenau) |
| D06 | Speichern ohne PHP-Warnung |
| D07 | 5 ungültige Uploads (zu groß, `.pem`, ohne Endung, PHP-Code, nicht lesbar): Vorschau ungültig mit der bisherigen Meldung, Speichern abgelehnt (bisheriger Toast), nichts gespeichert |
| D08 | `parseDKIMFile()` Basis `8447e35` ↔ neu: identische Rückgabe; die Warnung „Undefined variable $domain“ entsteht nur in der Basis |

**Harness:**
- `cgi()` lässt zusätzliche Umgebungswerte die Vorgaben überschreiben (`CONTENT_TYPE` für Multipart-Uploads); bisher nutzte kein Aufrufer diesen Parameter.
- `run.sh serve` stellt `preview_race.js` unter `/__ui/` bereit.
