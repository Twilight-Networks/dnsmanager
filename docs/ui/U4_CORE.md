# U4-Core – Einzige serverseitige DNS-Zusammensetzung, Parser-Roundtrip, DynDNS-Kennzeichen

Stand: 14.11.2026. Umsetzung der Entscheidungen U4-E1 bis E4 mit den verbindlichen Präzisierungen vom 14.11.2026.

**Umfang:** ausschließlich U4-Core. Kein U4-UI, keine Schemaänderung, keine Änderung an Berechtigungen, Schutzregeln,
`RecordService`, Löschen oder `bulk_ids`. Die bisherigen Formulare und Formularskripte bleiben unverändert
(Übergangsphase).

## 1. Untersuchte tatsächliche Speichersemantik (main `4b4aafa`)

**Gemessen** mit der Typ-Matrix `tests/ui/records`: 54 Fälle × mit/ohne JS. Es zählt der persistierte Datensatz, nicht die
Zwischenwerte der Formulare. Die JS-Daten stammen aus den echten Formularskripten.

| Typ | Anlage mit JS | Anlage ohne JS | Bearbeitung |
|---|---|---|---|
| A/AAAA, LOC, SPF | wie eingegeben | gleich | gleich |
| CNAME/NS/PTR | JS ergänzt `.` bzw. `.<Zone>.`, auch an absolute Ziele (`web.other.test.u0.test.`) | unverändert relativ (`web`, `ns.other.test`) | wie eingegeben |
| MX | JS-Ziel absolut, Modus `mx_target_mode` | Builder nutzt `fqdn_mode` statt `mx_target_mode` (`mx1.`) | Parser entfernt den Punkt, Builder hängt `.` an: **relatives Ziel wird umgeschrieben** (`20 mail` → `20 mail.`) |
| SRV | Name `_dienst._proto` (JS) | **Protokoll geht verloren** (`sip`, `_xmpp`) | ohne JS geht das Protokoll verloren; relatives Ziel wird umgeschrieben (`xmpp` → `xmpp.`) |
| TXT | wie eingegeben | **schlägt fehl** (U0-B1: verstecktes `is_dkim=1` → als DKIM geprüft) | ohne JS: schlägt fehl (U0-B1) |
| DKIM | JS → TXT, `"v=DKIM1; k=…[; h=…][; t=…];" "p=<255>" …` | nur per Datei-Upload (Felder ohne `name`); Builder-Format mit `s=email` als **eine** Zeichenkette | mit JS: setzt immer neu zusammen. **Eine TTL-Änderung schreibt einen alten Record um** (`s=email` entfällt). Ohne JS werden DKIM-Felder ignoriert. |
| URI | **schlägt fehl** (nichts wird zusammengesetzt) | schlägt fehl | mit JS: zusammengesetzt; ohne JS: URI-Felder ignoriert |
| NAPTR | Builder aus den Feldern | gleich | Präfixänderung wird ignoriert |
| CAA | Präfix → Name (JS) | Name-Feld | **Präfixänderung wird immer ignoriert** |

**Weitere Core-Befunde:**
- **B2 (bestätigt):** Eine einzelne TXT-Zeichenkette über 255 Bytes lehnt `named-checkzone` 9.20 mit Syntaxfehler ab; in 255er-Blöcken wird sie geladen.
- **B4 (neu):** Die DKIM-Prüfung `\bp=([A-Za-z0-9+/=]{32,})\b` schnitt ein abschließendes Base64-Padding `=` ab.
  - Folge: Jeder Schlüssel mit Padding wurde als ungültig abgelehnt, z. B. jeder Ed25519- und jeder 4096-Bit-RSA-Schlüssel.
  - Bestehende Ed25519-Records ließen sich nicht einmal in der TTL ändern.
  - Der Parser hatte denselben Fehler (Schlüssel im Bearbeitungsformular ohne `=`).

## 2. Umsetzung

| Datei | Änderung |
|---|---|
| `inc/app/records/RecordContentBuilder.php` | Maßgebliche Zusammensetzung mit Kontext (`mode` create/update, `zoneName`, `existing`); Rückgabe zusätzlich `validationType` und `isDkim`. Ohne Kontext unverändert das bisherige Verhalten (Altaufrufer). |
| `inc/app/records/RecordContentParser.php` | DKIM tag-basiert: Padding von `p=` bleibt erhalten; `k`/`h`/`t` nur als eigene Tags (nicht aus dem Schlüssel oder aus `s=email`) |
| `inc/validators.php` | DKIM-Schlüssel bis zum ersten Nicht-Base64-Zeichen (B4); Base64-Roundtrip-Prüfung unverändert |
| `inc/app/records/RecordReadService.php` | `getZoneRecordView()`: neues Kennzeichen `isDyndns` (E3) |
| `actions/record_add.php`, `actions/record_update.php` | Builder mit Kontext. Zone bzw. gespeicherter Record kommen aus den Read-Services mit Zonenberechtigung in derselben Abfrage. Prüftyp und DKIM-Kennzeichen liefert der Builder. Autoritative Prüfungen unverändert im `RecordService`. |
| `inc/web/record_messages.php` | Texte der neuen Builder-Codes |

**Regeln des Builders:**
- **Bearbeitung ohne fachliche Änderung:** Stimmen alle fachlichen Formularfelder mit der Zerlegung des gespeicherten
  Records überein (Parser), bleiben gespeicherter Name und Inhalt **exakt** erhalten. Kein stilles Umschreiben bei
  TTL-Änderungen, auch nicht bei Altformen (DKIM mit `s=email`, relative Ziele, absolute CAA-Namen).
- **Ziele:**
  - Absolut (abschließender Punkt) bleibt absolut.
  - Relativ wird je Auswahl mit `.` bzw. `.<Zone>.` ergänzt: CNAME/NS/PTR bei der Anlage, MX mit `mx_target_mode`.
  - Von den bisherigen Skripten bereits zusammengesetzte Werte werden **nicht ein zweites Mal** ergänzt.
  - SRV-Ziele unverändert wie bisher.
- **DKIM:**
  - Anlage aus Selector, Subdomain, Schlüssel, `k` (rsa|ed25519), optional `h` und `t`.
  - Format wie bisher mit JS, ohne `s=email`, Schlüssel in TXT-Zeichenketten von höchstens 255 Bytes.
  - Parameter werden serverseitig geprüft: Selector/Subdomain als Namensbestandteile, `k`, `h` (z. B. `sha1:sha256`), `t` (Buchstaben), Schlüssel nur Base64.
  - Ein bereits als DKIM-TXT zusammengesetzter Inhalt (bisherige Skripte) wird unverändert übernommen.
  - Bei der Bearbeitung wird nur bei geänderten DKIM-Feldern neu zusammengesetzt.
- **`is_dkim`** wirkt nur bei Typ DKIM oder einem TXT-Inhalt mit `"v=DKIM1"`; ein gewöhnlicher TXT wird als TXT geprüft (U0-B1).
- **SRV/URI:** Name `_dienst._proto`, sofern nicht bereits so angegeben. URI-Inhalt aus den URI-Feldern.
- **CAA/NAPTR:** Bei der Bearbeitung wird ein geändertes Präfix übernommen; ein unverändertes Präfix lässt den Namen unverändert.

**DynDNS-Kennzeichen `isDyndns`:**
- **Gleiche Kriterien wie der DynDNS-Schutz** (`dyndnsCheckRecordChange`):
  - genau ein Account mit kanonisch gleichem Hostnamen;
  - kein loser Treffer über die Datenbank-Collation;
  - kein Systemrecord;
  - `dyndnsAttributeAddressRecord()` = MANAGED mit genau diesem Record.
- Mehrdeutige oder nur reservierte Namen werden nicht gekennzeichnet.
- Accounts werden nur mit der Zonenberechtigung in derselben Abfrage gelesen, ohne Accounts anderer Zonen. Ausgegeben wird nur das Kennzeichen (kein Hostname, keine IP).
- System-, Glue- und Schutzkennzeichen bleiben unabhängig davon erhalten.

## 3. Bewusste Abweichungen (Typ-Matrix, Fall-IDs)

| ID | Abweichung | Fälle (`Fall/Variante`) |
|---|---|---|
| U4C-A1 | Ohne JS werden CNAME/NS/PTR bei der Anlage wie mit JS absolut gespeichert (`web.other.test.`, `web.u0.test.`, `host.u0.test.`); vorher blieben sie relativ (BIND: relativ zur Zone) | `add_cname_dot/nojs`, `add_cname_zone/nojs`, `add_ns/nojs`, `add_ptr/nojs` |
| U4C-A2 | MX ohne JS beachtet den Zielmodus wie mit JS (`mx1.u0.test.` statt `mx1.`) | `add_mx_zone/nojs` |
| U4C-A3 | U0-B1 behoben: TXT ohne JS wird gespeichert (vorher drei DKIM-Fehler). **Golden** `record/add_admin_txt_ohne_js`, freigegeben in `exceptions-u4core.json` | `add_txt/nojs`, `ttl_txt_spf/nojs`, `ttl_txt/nojs` |
| U4C-A4 | DKIM-Schlüssel mit Base64-Padding werden angenommen (B4; Ed25519, RSA 4096) | `add_dkim_ed/js`, `add_dkim_4096/js`, `ttl_dkim_ed/nojs`, `ttl_dkim_ed/js` |
| U4C-A5 | SRV ohne JS: Name aus Dienst und Protokoll (`_sip._udp`); vorher ging das Protokoll verloren | `add_srv_zone/nojs`, `add_srv_abs/nojs`, `ttl_srv/nojs`, `chg_srv_port/nojs`, `chg_srv_proto/nojs` |
| U4C-A6 | URI-Anlage funktioniert (vorher nicht möglich) | `add_uri/nojs`, `add_uri/js` |
| U4C-A7 | Verlustfreie Bearbeitung: reine TTL-Änderungen schreiben nicht mehr um (relatives MX-/SRV-Ziel; alter DKIM-Record mit `s=email` auch mit JS) | `ttl_mx_rel/nojs`, `ttl_mx_rel/js`, `ttl_srv_rel/nojs`, `ttl_srv_rel/js`, `ttl_dkim_old/js` |
| U4C-A8 | Geändertes CAA-Präfix wird übernommen (vorher immer ignoriert) | `chg_caa_prefix/nojs`, `chg_caa_prefix/js` |
| U4C-A9 | Ohne JS wirken Änderungen an URI- und DKIM-Feldern (wie mit JS; vorher ignoriert). Bei geänderten DKIM-Feldern wird im aktuellen Format neu zusammengesetzt, sodass `s=email` eines Altrecords entfällt (wie bisher mit JS). | `chg_uri/nojs`, `chg_dkim_key/nojs`, `chg_dkim_hash/nojs` |

**Alle übrigen 79 Fall-Varianten sind unverändert.** Das sind gespeicherter Datensatz, Status, Weiterleitung und Toasts.

## 4. Nachweise

**Umgebung:** ns1, PHP 8.5.4, MariaDB 11.8.6, BIND 9.20.24. Isolierte Testkopie über `tests/ui`; installierte Anwendung unberührt.

| Prüfung | Ergebnis |
|---|---|
| Typ-Matrix (Übergangskombination: Formulare vom U4-Core-Stand, unveränderte Skripte) gegen `main` | **79 identisch, 29 dokumentiert (U4C-A1 bis A9), 0 undokumentiert** |
| Gegenprobe: JS-Daten aus den auf `main` gerenderten Formularen gegen U4-Core | 80 identisch, 28 dokumentiert. Nur `ttl_dkim_ed/js` tritt erwartungsgemäß nicht auf: Das alte Formular enthielt den Schlüssel bereits ohne Padding. |
| Builder/Parser/BIND (`unit.php`) | **38/38**:<br>• TXT-Aufteilung 0/1/254/255/256/510/511/765/766 Bytes;<br>• DKIM RSA 1024/2048/4096, Ed25519, 252/256 Zeichen;<br>• 13 Negativfälle (u. a. Injektion in `h`/`t`, Anführungszeichen im Schlüssel/Selector);<br>• verlustfreier Roundtrip aller 30 Fixture-Records;<br>• `named-checkzone` lädt alle erzeugten DKIM-Zeilen, Gegenprobe 256-Byte-Zeichenkette abgelehnt |
| DynDNS-Kennzeichen (`dyndns_flag.php`) | **17/17 unter REPEATABLE READ, 17/17 unter READ COMMITTED**: 13 Szenarien, je A/AAAA-Record identisch mit dem Zustand des DynDNS-Schutzes; zoneadmin; keine Account-Daten; Vertrag |
| Golden gegen U0-Basis mit `exceptions-u4core.json` | **Exit 0**: 72 identisch, 37 informativ, 9 freigegeben (8 × U3-A3, 1 × U4C-A3), 0 Vertragsabweichungen |
| Statisch S01–S05 | 5/5 |

**Reproduzierbar:** `UIG_JS_POSTS=…/js_posts_u4core.json bash run.sh matrix <SHA>`, `bash run.sh compare <SHA> baseline/golden-1e37515.json baseline/exceptions-u4core.json`.

## 5. Übergangsphase und Restrisiken

- **Noch vorhandene JS-Handler:** Bei der Anlage setzen sie CNAME/NS/PTR/MX weiterhin selbst zusammen. Dabei hängen sie bei absolutem Ziel mit Zonenmodus doppelt an (`web.other.test.u0.test.`; `add_cname_abszone/js`, `add_mx_abszone/js` unverändert). Der Builder ergänzt nicht ein zweites Mal. Die Ursache entfällt in U4-UI, wenn die Skripte nicht mehr zusammensetzen.
- **DKIM ohne JS:** Die Anlage ist weiterhin nur per Datei-Upload möglich, weil die Felder im Anlageformular keinen `name` haben. Die benannten Felder kommen mit U4-UI.
- **Unbekannte DKIM-Tags** (z. B. `s=email`, `n=`) entfallen nur bei einer ausdrücklichen Änderung der DKIM-Felder. Mit U4-UI macht das Live-DIFF dies sichtbar.
- `record_update.php` liest den gespeicherten Record zusätzlich über `getZoneRecordView()` (eine Leseabfrage mehr). Autoritativ bleibt `RecordService` mit Sperren.
- `isDyndns` beschreibt den Zustand zum Lesezeitpunkt. Die Schutzentscheidung trifft weiterhin `dyndnsCheckRecordChange()` unter der Zonensperre.
