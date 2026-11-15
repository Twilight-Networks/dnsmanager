# U6b – DynDNS-Restbefund: Reproduktion, Quantifizierung, Bewertung

Stand: 15.11.2026.

**Grundlage:**
- `main` `cdad77433f37cde104eb570ca559e4794fac08e3` (Tree `a55767d`).
- Reproduktion in der isolierten Testkopie auf ns1 (`tests/ui/run.sh serve`).
- Quantifizierung read-only auf der ns1-Testinstallation (DB `dnsmanager`).

**Abgrenzung:**
- Keine Code-, Schema- oder Datenänderung.
- Die Akzeptanz für die Produktivabnahme ist **offen** und liegt bei Thomas.

## 1. Befund (aus U4, unverändert)

Ein A/AAAA-Record an einem für DynDNS reservierten Namen ist entweder:
- **eindeutig verwaltet** (`MANAGED`): Er trägt das Kennzeichen „DynDNS“, nur die TTL ist änderbar, Löschen ist ausgeblendet. Oder er ist
- **mehrdeutig** (`AMBIGUOUS`): Der RecordService lehnt jede Änderung und das Löschen ab (fail-closed).

Die Oberfläche erhält aus `RecordReadService::isDyndns` nur das Kennzeichen „eindeutig verwaltet“. Mehrdeutige Records sehen deshalb wie normale manuelle Records aus: mit „Bearbeiten“ und „Löschen“, ohne Sperrhinweis. Erst das Speichern bzw. Löschen wird mit einer Meldung abgelehnt.

Mehrdeutig ist ein Record nach `dyndnsAttributeAddressRecord()` und `dyndnsFindOwnerReservations()`, wenn eines davon zutrifft:
- mehrere Accounts teilen sich den Hostnamen (auch nur über die Collation, „loser Treffer“);
- mehrere A- bzw. AAAA-Records liegen am Hostnamen;
- ein Systemrecord (`server_id` gesetzt) liegt am Hostnamen;
- abweichende Schreibweise über ASCII-Groß-/Kleinschreibung hinaus, z. B. ein absoluter Name `dyn.u0.test.`;
- die gespeicherte Account-IP fehlt oder weicht vom Record-Inhalt ab.

## 2. Reproduktion (Testkopie, Fixture der Golden-Basis)

Je Fall wurde die Fixture zurückgesetzt und der Zustand per SQL in der Test-DB hergestellt. Geprüft wurden:
- Liste (Kennzeichen, Löschformular) und Editor (Sperrhinweis);
- TTL-Änderung über das ausgelieferte Editorformular;
- Löschen per POST;
- DynDNS-API-Update des Accounts;
- Auflösung über die DynDNS-Verwaltung.

| Fall | Anzeige | TTL ändern | Löschen | DynDNS-API | Auflösung |
|---|---|---|---|---|---|
| M0 eindeutig (Basis) | Kennzeichen „DynDNS“, kein Löschen, Sperrhinweis | erlaubt | abgelehnt (`MANAGED_RECORD`) | `good`, Record aktualisiert | – |
| A1 Account-IP weicht ab | wie manuell: Bearbeiten + Löschen, kein Hinweis | abgelehnt (`AMBIGUOUS_RECORD`) | abgelehnt | **`dnserr`** | Account löschen → Record bleibt, danach normal bearbeitbar |
| A2 Account ohne gespeicherte IP | wie manuell | abgelehnt | abgelehnt | **`dnserr`** | wie A1 |
| A3 zwei A-Records am Hostnamen | wie manuell | abgelehnt | abgelehnt | **`dnserr`** | wie A1 |
| A4a nur Groß-/Kleinschreibung (`DYN`) | eindeutig (zulässig) | erlaubt | abgelehnt (`MANAGED_RECORD`) | `good` | – |
| A4b absoluter Name `dyn.u0.test.` | wie manuell | abgelehnt | abgelehnt | **`dnserr`** | wie A1 |
| A5 zweiter Account mit gleichem Hostnamen | wie manuell | abgelehnt | abgelehnt | **`dnserr`** | beide Accounts löschen → Record bleibt, normal bearbeitbar |
| A6 Systemrecord am Hostnamen | wie manuell | abgelehnt | abgelehnt | **`dnserr`** | wie A1 |

Für alle mehrdeutigen Fälle gilt dasselbe:
- **Meldung:** „Der A-/AAAA-Record … lässt sich keinem DynDNS-Account eindeutig zuordnen … Bitte zuerst die DynDNS-Zuweisung(en) in der DynDNS-Verwaltung entfernen.“
- **Log:** `Update blockiert (DynDNS AMBIGUOUS_RECORD)` bzw. `Löschversuch … blockiert: AMBIGUOUS_RECORD`.
- **Daten:** Sie bleiben in jedem Fall unverändert (Rollback).

**Zusätzliche Beobachtungen:**
- **DY-1 (Tragweite):** Im mehrdeutigen Zustand schlägt auch die **DynDNS-API** des betroffenen Accounts fehl (`dnserr`). Der Account aktualisiert seine Adresse dann nicht mehr. Das Problem betrifft also die DynDNS-Funktion selbst, nicht nur die Oberfläche.
- **DY-2 (Meldungstext, vorbestehend):** In A4b und A6 meldet das Löschen des Accounts „DynDNS-Account und zugehörige A/AAAA-Records gelöscht.“, obwohl der nicht zuordenbare Record erhalten bleibt.
  - Ursache: `accountOnly` in `DyndnsAccountService::deleteAccount()` berücksichtigt nur geteilte Hostnamen und „erhaltene“ Records, nicht den Fall, dass gar nichts gelöscht wurde.
  - Es entsteht kein Datenfehler, nur eine missverständliche Erfolgsmeldung. Behebung wäre eine Core-Änderung und ist nicht Teil von U6.

## 3. Quantifizierung auf der ns1-Testinstallation (read-only)

**Methode:**
- Sitzung und Transaktion `READ ONLY`, Klassifizierung mit den Funktionen aus `main`.
- Prüfsummen von `records`, `dyndns_accounts` und `zones` vorher und nachher gleich.
- Gegenprobe auf der Fixture: 1 × MANAGED (A), 1 × NONE (AAAA).

| | |
|---|---|
| Datenbestand | 4 Zonen, 2 DynDNS-Accounts, 23 Records (A 6, AAAA 2) |
| DynDNS-fähige Zonen | nur `example.test` |
| Accounts | `dyndnstest` (#1) und `n1race` (#2), **beide Hostname `dyn` in `example.test`**. Es sind die geschützten Testkonten laut CLAUDE.md, der Zustand stammt aus den Race-Tests vom 16.10.2026. |
| Records am Hostnamen | `dyn` A (#102) und `dyn` AAAA (#103), manuell |
| Ergebnis Account × Typ | **4 × AMBIGUOUS**, 0 × MANAGED, 0 × NONE; Grund jeweils „geteilter Hostname“ |

**Folgen auf ns1:**
- Die Records #102/#103 sind in der Oberfläche als manuell dargestellt, lassen sich aber weder ändern noch löschen.
- Nach Fall A5 der Reproduktion liefern DynDNS-Updates beider Konten `dnserr`. Die Installation wurde nicht aufgerufen, um nichts zu verändern.
- Die Konten bleiben ohne ausdrückliche Freigabe unverändert.

## 4. Bewertung

| Kriterium | Einschätzung |
|---|---|
| Datenintegrität | **gewahrt**: Der Service lehnt fail-closed ab, mit vollständigem Rollback und Log. Ein Record kann nicht versehentlich verändert oder entzogen werden. |
| Sicherheit | kein Risiko: Die Prüfung liegt im RecordService, unabhängig von der Oberfläche |
| Bedienbarkeit | eingeschränkt: Die Anzeige verspricht Aktionen, die dann scheitern. Die Meldung nennt den Ausweg (DynDNS-Zuweisung entfernen). |
| Funktion | **DY-1:** Ein betroffener DynDNS-Account aktualisiert nicht mehr (`dnserr`), bis der Zustand aufgelöst ist |
| Häufigkeit | Entsteht nur durch Sonderfälle: geteilte Hostnamen, manuelle Records zusätzlich zum DynDNS-Record, Systemrecords am Hostnamen, abweichende Account-IP. Im Normalbetrieb (ein Account, ein Hostname, Updates über die API) tritt er nicht auf. Auf ns1 nur durch den bewussten Testzustand. |

**Handlungsoptionen (Entscheidung bei Thomas):**

| Option | Inhalt | Aufwand/Risiko |
|---|---|---|
| O1 | Für die Produktivabnahme als dokumentierte Einschränkung akzeptieren; Betriebshinweis im Runbook. Erkennung über die Prüfabfrage (Abschnitt 3); Auflösung über die DynDNS-Verwaltung. | kein Code |
| O2 | Eigener Read-PR: `RecordReadService` liefert zusätzlich „DynDNS-mehrdeutig“ (ohne Hostname/IP). Die Oberfläche blendet dann Löschen aus und zeigt einen Sperrhinweis mit Ausweg. | Core-Read-Erweiterung, eigene Freigabe; keine Verhaltensänderung der Mutationen |
| O3 | Zusätzlich DY-2 (Meldungstext) und ein Hinweis in der DynDNS-Verwaltung bei mehrdeutigen Accounts | Core-Änderung, eigene Freigabe |

**Empfehlung:** O1 für die Produktivabnahme, O2 als nachfolgender kleiner PR. Ob der Testzustand `dyndnstest`/`n1race` auf ns1 erhalten bleibt, entscheidet Thomas. Für die DynDNS-Abnahme nutzt das Runbook ein temporäres eigenes Konto (Abschnitt 7.4).
