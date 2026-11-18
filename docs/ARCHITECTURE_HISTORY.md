# DNS Manager – Änderungshistorie, Versionsstände, Discard und Restore: Architektur

Stand: 18.11.2026 · Architektur AP1/H1 (Basis `6e8133c`) · umgesetzt bis Meilenstein H2a (Basis `98cd0d5`)

## 0. Status und Geltung

- **Dieses Dokument beschreibt eine freigegebene Architekturentscheidung.** Umgesetzt ist bisher nur Meilenstein
  **H2a** (Abschnitt 5a): die vier History-Grundtabellen mit Migration 002, `SchemaCheck`-Erweiterung,
  `ZoneStateSnapshot`/`ZoneSnapshotDiff`/`ZoneSnapshotReader` und die Baseline-Initialisierung. Alle übrigen hier
  beschriebenen Tabellen, Klassen, Agent-Erweiterungen und Abläufe existieren im Code noch nicht; insbesondere schreibt
  kein Fachpfad Historie (H2b) und es gibt kein Publish-Journal (H2d).
- Freigabe: AP1/H1 am 18.11.2026 durch Thomas – Variante C (Hybridmodell aus Audit-Historie, unveränderlichen
  Zonenrevisionen mit Snapshots und separatem Publish-Journal) einschließlich der im H1-Review vereinbarten Korrekturen
  (Abschnitte 8–13) und der Entscheidungen E8, E10 und E17. Zu E15/E16 ist nur die Anforderung festgelegt, 32-Bit-Serials
  ohne Vorzeichen zu unterstützen; Serial-Strategie und Migration der bestehenden Spalte `zones.soa_serial` werden im
  Meilenstein AS entschieden.
- **Verbindlich** sind Begriffe, Zustandsdefinitionen, Invarianten (I-…, F-…, R-…) und die Meilensteinfolge.
  Tabellen- und Spaltennamen, Schnittstellensignaturen und Codes sind **Entwurf** und werden im jeweiligen Meilenstein
  festgelegt. Ausdrücklich offene Punkte sind mit **[offen]** gekennzeichnet und in Abschnitt 20 gesammelt.
- Jede Abweichung von einer Invariante in einem späteren Meilenstein erfordert eine dokumentierte, freigegebene
  Änderung dieses Dokuments.
- Bezug: Core-Architektur und Lock-Regeln in [`ARCHITECTURE_CORE.md`](ARCHITECTURE_CORE.md); Agent-TLS in
  [`AGENT_TLS.md`](AGENT_TLS.md).

## 1. Ziele und Nicht-Ziele

**Ziele**
- Lückenlose, atomar mit der Fachänderung gespeicherte Historie aller fachlich relevanten Änderungen.
- Unveränderliche Versionsstände (Revisionen) des DNS-Inhalts je Zone.
- Nachvollziehbarer Veröffentlichungsstand je Zone und je Zielserver, ohne dass ein veralteter Publish-Erfolg einen
  neueren Stand als veröffentlicht ausweist.
- Discard: Verwerfen des gesamten, nachweislich unveröffentlichten Stands einer Zone ohne erneutes Publish.
- Restore: Wiederherstellen eines historischen Inhalts als neue Revision mit anschließender Veröffentlichung.

**Nicht-Ziele**
- Vollständiges Event-Sourcing (Zustand nur aus Ereignissen rekonstruiert).
- Änderung bestehender Web-Verträge, Publish-Semantik (`changed`/`bind_dirty`) oder Lock-Reihenfolge außerhalb der
  hier beschriebenen Erweiterungen.
- Automatische Löschung historischer Daten (E8; Retention ist eine spätere Erweiterung).
- Wiederherstellung gelöschter Zonen (E11).

## 2. Ausgangslage (Code-Stand `6e8133c`, Analyse H1)

- Es gibt weder Historie noch persistierten Veröffentlichungsstand. Der Publish-Zustand besteht aus
  `zones.changed` und `system_status.bind_dirty` (0/1/2, `PublishService.php`). Die Tabelle `deployments` ist im
  Schema vorhanden, wird aber nirgends verwendet.
- Jede DNS-relevante Mutation läuft in einer Transaktion unter `zones … FOR UPDATE` und endet mit
  `ZoneBuildBackend::validateAndFlag()` bzw. `rebuildNsGlueAndFlag()` (`changed = 1`); Ausnahme `deleteZone`
  (`bind_dirty = 1`). Schreibstellen: `ZoneService`, `RecordService` (inkl. AutoPTR in eigener Transaktion T2),
  `ServerService::updateServer`, `DyndnsAccountService::updateAccount/deleteAccount`, `DyndnsUpdateService::update`,
  `BindZoneBuildBackend`.
- Der Generator liest die Zone in eigener Transaktion unter Zonensperre und schreibt die SOA-Serial **vor** der
  Verteilung fest (`bind_file_generator.php`). Die Verteilung läuft im Autocommit je Server; Ergebnisse je Server
  werden nur als Fehlertexte gemeldet.
- Agent: `zone_sync` übernimmt die Datei nach `named-checkzone` und `named-checkconf`-Preflight atomar per `rename()`
  und führt `rndc reload` aus; das Reload-Ergebnis wird **nicht** ausgewertet, die Antwort ist immer `success`.
  Weder Serial noch Ordnungsmerkmal werden übertragen oder geprüft.
- Systemrecords (`records.server_id IS NOT NULL`) werden bei jedem NS-/Glue-Neuaufbau gelöscht und neu angelegt
  (instabile IDs). Zonenlöschung entfernt `records`, `zone_servers`, `user_zones`, `dyndns_accounts` per FK-Cascade.

## 3. Begriffe

| Begriff | Definition |
|---|---|
| **Änderungsmenge** (change set) | Eine committete Fachtransaktion mit Akteur, Kanal und Operation. Kann mehrere Zonen betreffen (z. B. Serverumbenennung, DynDNS-Zonenwechsel). |
| **Revision** | Unveränderlicher, vollständiger DNS-relevanter Zustand einer Zone nach dem Commit einer Änderungsmenge, die diesen Zustand geändert hat. Identität `(zone_id, revision_no)`, `revision_no` je Zone monoton. |
| **Snapshot** | Kanonische, vollständige Darstellung des DNS-Inhalts einer Revision; inhaltsadressiert über `content_hash`. |
| **DNS-Inhalt** | Zonenfelder `name`, `type`, `ttl`, `prefix_length`, `soa_ns`, `soa_mail`, `soa_refresh`, `soa_retry`, `soa_expire`, `soa_minimum`; alle Records (`name`, `type`, `content`, `ttl`, Systemkennzeichen/Serverbezug); Serverzuweisung (`server_id`, `is_master`). **Nicht** enthalten: `soa_serial`, `changed`, `created_at`, Beschreibung, `allow_dyndns`. |
| **Audit-Ereignis** | Fachlich relevante, committete Änderung mit oder ohne DNS-Wirkung (Entität, Aktion, geschwärzte Feldänderungen). |
| **Publish-Lauf** | Ein Aufruf von Publish All bzw. Publish Zone unter dem Advisory-Lock; seine ID ist der Fencing-Token. |
| **Publish-Versuch** | Generierung einer Zone innerhalb eines Laufs (Revision, Hashes, Serial) samt Ergebnissen je Zielserver. |
| **Zielserver** | Aktuell aktiver und der Zone zugewiesener Server. |
| `content_hash` | Hash des Snapshots (fachliche Gleichheit, Historie). |
| `render_hash` | Hash der vom Generator erzeugten Zonendatei mit maskierter SOA-Serial. |
| `file_hash` | Hash der erzeugten Zonendatei einschließlich Serial (Byte-Gleichheit). |
| `conf_hash` | Hash der je Server erzeugten Zonenkonfiguration. |

Alle Hashes sind SHA-256 über einen versionierten, kanonischen Vertrag (Muster `ZoneEditVersion`). Eine Änderung der
Kanonisierung erfordert eine neue Vertragskennung.

## 4. Architekturentscheidung

**Variante C (Hybrid), freigegeben.** Je Zone und Änderungsmenge entsteht eine Revision mit vollständigem,
inhaltsadressiertem Snapshot und berechnetem Diff; fachliche Ereignisse ohne DNS-Wirkung werden als Audit-Ereignisse
gespeichert; ein separates Publish-Journal hält Läufe, Versuche und Ergebnisse je Zielserver fest.

Verworfen:
- **A – Audit-Log mit Snapshots ohne Deduplizierung/Journal:** löst Veröffentlichungsstand und Discard nicht.
- **B – Ereignisbasierte Rekonstruktion:** Erfassung an jeder Schreibstelle inkl. Cascades und Neuaufbau nötig;
  eine Lücke verfälscht alle Folgezustände; Restore/Discard nur per Replay.

Begründung: generische Erfassung unabhängig vom Aufrufpfad, Discard/Restore ohne Replay, überprüfbare Verbindung von
Revision und Veröffentlichung über Hashes. Erhobene Datenmengen auf der Testumgebung (18.11.2026): 6 Zonen mit 2–14
Records, höchstens etwa 1 KB je Zone – vollständige Snapshots sind unkritisch.

## 5. Datenmodell (Entwurf)

Grundsätze:
- Historientabellen haben **keine FK-Cascade auf `zones`**; die Historie gelöschter Zonen bleibt erhalten.
  Zonen-IDs werden nicht wiederverwendet (persistentes `AUTO_INCREMENT`, MariaDB 11.8).
- Keine Geheimnisse: Snapshots und Audit-Ereignisse werden ausschließlich über Spalten-Whitelists gebildet.
- Serial-Spalten in neuen Tabellen nehmen den vollen 32-Bit-Bereich ohne Vorzeichen auf (`INT UNSIGNED`);
  Fencing-Token/Lauf-IDs `BIGINT UNSIGNED` (Vorgabe für H2a, Detail E15/E16).
- Keine automatische Löschung (E8).

| Tabelle | Zweck | Wichtige Spalten / Schlüssel |
|---|---|---|
| `history_change_sets` | Klammer je Fachtransaktion | id, created_at (µs), operation, actor_type (user / dyndns_account / system), actor_user_id, actor_label (Benutzername zum Zeitpunkt), actor_dyndns_account_id, channel, correlation_id; IDX(created_at), IDX(actor_user_id, created_at) |
| `zone_snapshots` | inhaltsadressierte Snapshots | PK hash, contract, payload (kanonisches JSON), record_count |
| `zone_revisions` | Revisionen | id, zone_id, zone_name, revision_no, change_set_id, kind (baseline / create / change / discard / restore / delete / drift), snapshot_hash, content_hash, source_revision_no, diff_summary; UNIQUE(zone_id, revision_no) |
| `zone_history_state` | Head je Zone | PK zone_id, head_revision_no, head_hash, tracking (tracked / baseline_unverified), deleted_at; nur unter `zones X` geändert |
| `history_dependencies` | zonenübergreifende Abhängigkeiten | (change_set_id, zone_id, record_key) → (dep_change_set_id, dep_zone_id, dep_record_key), kind (auto_ptr, …) |
| `audit_events` | fachliche Ereignisse | id, change_set_id, entity_type, entity_id, entity_label, action, changes (JSON, geschwärzt) |
| `publish_runs` | Läufe (= Fencing-Token) | id, kind (all / zone), full_rebuild, connection_id, started_at, finished_at, status |
| `zone_publish_attempts` | Versuche | id, run_id, zone_id, revision_no, content_hash, render_hash, file_hash, soa_serial, generated_at, outcome (NULL = offen) |
| `zone_publish_targets` | Ergebnis je Ziel | PK(attempt_id, server_id), server_name, zone_sync / conf_sync (sending / ok / failed / rejected), conf_hash, agent_serial, agent_file_hash, reload_ok, error_code, error_detail (gekürzt, bereinigt) |
| `publish_cleanup_results` | Cleanup je Server | run_id, server_id, status |
| `zone_server_verification` | geladener Stand (L3, nach AS) | zone_id, server_id, observed_serial, observed_at, method |

`record_key` ist der Inhaltsschlüssel `(name, type, content)`, nie die Record-ID.

## 5a. Festlegungen H2a (umgesetzt)

**Umfang (E18):** Migration 002 legt nur `history_change_sets`, `zone_snapshots`, `zone_revisions` und
`zone_history_state` an. `history_dependencies` folgt mit H2b, `audit_events` mit H2c, das Publish-Journal mit H2d,
`zone_server_verification` mit AS – jeweils mit eigener Migration. Keine Serial-Spalten (E15/E16 bleiben offen).

**Tabellen (E19, E20)** – maßgeblich sind `sql/migrations/002_history_foundation.sql` und die Strukturerwartung in
`web/inc/app/SchemaCheck.php`:

| Tabelle | Festlegung |
|---|---|
| `history_change_sets` | `id` BIGINT UNSIGNED; `created_at` DATETIME(6); `operation` VARCHAR(64) ascii; `actor_type` ENUM(user, dyndns_account, system); `actor_user_id`, `actor_dyndns_account_id` INT NULL; `actor_label` VARCHAR(64) NULL; `channel` VARCHAR(16) ascii; `correlation_id` CHAR(36) NULL; CHECK Akteurart ↔ Akteurfelder (DynDNS-Konto ohne Label, §16); IDX `created_at`, `(actor_user_id, created_at)`, `correlation_id` |
| `zone_snapshots` | PK `content_hash` CHAR(64) ascii_bin (= `content_hash` aus Abschnitt 3; eine separate `snapshot_hash`-Spalte entfällt); `contract`; `payload` LONGTEXT utf8mb4_bin mit CHECK `JSON_VALID`; `record_count` INT UNSIGNED; CHECK Hashformat |
| `zone_revisions` | `id` BIGINT UNSIGNED; `zone_id` INT (Typ von `zones.id`, ohne FK); `zone_name`; `revision_no` INT UNSIGNED ≥ 1; FK `change_set_id`, FK `content_hash` → `zone_snapshots`; `kind` ENUM(baseline, create, change, discard, restore, delete, drift); `source_revision_no`; `diff_summary` (JSON, nur Feldnamen und Zähler); UNIQUE `(zone_id, revision_no)` |
| `zone_history_state` | PK `zone_id`; `head_revision_no`, `head_hash`; `tracking` ENUM(tracked, baseline_unverified); `deleted_at`; FK `(zone_id, head_revision_no, head_hash)` → `zone_revisions (zone_id, revision_no, content_hash)`: Head und Hash sind damit konsistent erzwungen |

Fremdschlüssel nur innerhalb der Historie, alle `RESTRICT`; keine Fremdschlüssel auf Fachtabellen.

**Snapshot-Vertrag `zs1` (E21)** – `ZoneStateSnapshot`, rein:
- Kanonisches JSON mit fester Schlüsselreihenfolge: `contract`; `zone` (`name`, `type`, `ttl`, `prefix_length`,
  `soa_ns`, `soa_mail`, `soa_refresh`, `soa_retry`, `soa_expire`, `soa_minimum`); `servers` als Paare
  `[server_id, is_master]` aufsteigend; `records` als Tupel `[name, type, content, ttl, server_id]`.
- `json_encode` mit `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, ohne Leerraum; `content_hash` = SHA-256 (hex,
  klein) über die Payload-Bytes.
- Records als Multimenge (Duplikate bleiben), Sortierung nach Bytes über `name`, `type`, `content`, dann `ttl` und
  `server_id` (NULL zuerst). Werte wie gespeichert (kein Trimmen, keine Änderung der Schreibweise); NULL, "" und 0
  unterscheidbar; Ganzzahlen streng geprüft; ungültiges UTF-8 ist ein Fehler.
- Whitelist: nur die genannten Felder. Nicht enthalten sind Zonen-ID (Deduplizierung), `soa_serial`, `changed`,
  `created_at`, Beschreibung, `allow_dyndns`, Record-IDs, Servernamen und -adressen, DynDNS-Bezug.
- `fromPayload()` akzeptiert nur exakt kanonische Payloads. Der Vertrag ist über einen eingefrorenen Testvektor
  gesichert; jede Änderung erfordert eine neue Kennung.

**Diff (E22)** – `ZoneSnapshotDiff`, rein: Zonenfelder, Serverzuweisung (hinzugefügt, entfernt, Master geändert) und
Records über den Inhaltsschlüssel `(name, type, content)` als Multimenge: zuerst exakt gleiche Einträge paarweise
entfernt, verbleibende Einträge desselben Schlüssels in kanonischer Reihenfolge als `changed` (TTL/Serverbezug)
zugeordnet, Überschuss als `added`/`removed`. Deterministisch; `summary()` nur mit Feldnamen und Zählern.

**Leser (E23)** – `ZoneSnapshotReader::lockAndRead()`: nur in offener Transaktion; `zones … FOR UPDATE`, danach
Serverzuweisung und Records ausschließlich sperrend (`LOCK IN SHARE MODE`). Sperrende Lesevorgänge erzeugen keinen
Read-View und sehen den neuesten bestätigten Stand; das Ergebnis ist unter REPEATABLE READ mit
`innodb_snapshot_isolation = 1` und unter READ COMMITTED gleich, Fehler 1020 kann aus ihnen nicht entstehen.

**Baseline (E24)** – `HistoryBaselineService` und `web/scripts/history_baseline-cli.php` (`--check`, `--dry-run`,
`--apply`): Schema fail-closed → Advisory-Try-Lock `dnsmanager_publish_all` → je Zone (aufsteigend) eigene Transaktion:
`zones` X → Snapshot sperrend lesen → `zone_history_state` nicht sperrend lesen (nur unter der Zonensperre geändert;
keine Gap-Sperre auf fehlende Zeilen) → vorhandener Head: überspringen; Revisionen ohne Head: Abbruch → Snapshot
inhaltsadressiert speichern (vorhandener Payload muss byte-identisch sein) → Änderungsmenge `migration.baseline`
(Akteur `system`, Kanal `cli`, Lauf-UUID als `correlation_id`) → Revision 1 `baseline` → Head `baseline_unverified` →
Commit. Keine Schreibvorgänge an Fachtabellen, Serials, `changed`, `bind_dirty` oder Agenten; ein ausstehender Stand
bleibt ausstehend. Abbruch rollt die laufende Zone vollständig zurück; ein erneuter Lauf setzt fort.

**SchemaCheck (E25)** – prüft zusätzlich die vier Tabellen vollständig (Tabellenart, Engine, Spalten, Indizes,
Fremdschlüssel, Namen der CHECK-Bedingungen) und ist fail-closed für Login und Session-Revalidierung. Dieselbe
Erwartung nutzt das Migrationsskript; es gibt nur eine Definition.

**Migration (E26, E27, E28)** – siehe `sql/migrations/README.md`: vollständiger Struktur-Preflight aller vorhandenen
History-Tabellen vor der ersten DDL (Abweichung ⇒ Exit 2 ohne Änderung); technischer Abbruch während der DDL ⇒ Exit 3,
angelegte Tabellen bleiben, Wiederanlauf deterministisch. Ein Code-Rollback löscht keine History-Daten; `DROP TABLE` nur
als freigegebene, datenverlustbehaftete Sondermaßnahme mit Sicherung. Reihenfolge: Migration → Code → Baseline (die
Initialisierungs-CLI ist Teil des Codes; H2a nutzt die Historie zur Laufzeit nicht).

## 6. Atomare History-Erfassung

| ID | Regel |
|---|---|
| R-H1 | Revisionen und Audit-Ereignisse werden **in derselben Transaktion** wie die Fachänderung geschrieben. Rollback der Fachänderung ⇒ keine Historienzeilen; ein Fehler der Historienerfassung ⇒ Rollback der Fachänderung. |
| R-H2 | Erfassung je betroffener Zone **nach** der Zonensperre (`zones X`) und vor dem Commit; `zone_history_state` wird unmittelbar nach der Zonensperre und **vor dem ersten nicht sperrenden Lesen** der Transaktion gesperrt (MariaDB 11.8 mit `innodb_snapshot_isolation = 1`, Fehler 1020). |
| R-H3 | Vorher-Prüfung (E7): Nach der Sperre wird der Hash des aktuellen DB-Stands mit dem Head verglichen; Abweichung ⇒ zuerst Revision `drift` (Akteur `system`), damit fremde Änderungen nie dem nächsten Akteur zugeschrieben werden. |
| R-H4 | Nach der Mutation wird der Snapshot gelesen (explizite Spalten); gleicher `content_hash` wie Head ⇒ keine Revision. |
| R-H5 | Zonenlöschung: Revision `delete` mit Snapshot **vor** dem `DELETE`. |
| R-H6 | Mehrzonen-Transaktionen bilden eine Änderungsmenge mit je einer Revision pro Zone; Zonen in aufsteigender ID-Reihenfolge (wie bestehend). |
| R-H7 | AutoPTR (T2) erhält eine eigene Änderungsmenge mit `correlation_id` auf T1 und einen Eintrag in `history_dependencies` (Forward-Record ↔ PTR). |
| R-H8 | Jede Schreibstelle mit `validateAndFlag`, `rebuildNsGlueAndFlag` oder `DELETE FROM zones` hat einen Recorder-Aufruf; statisch und dynamisch geprüft (Muster Paket 6). |
| R-H9 | Lock-Reihenfolge (Ziel): Advisory-Lock (wo bisher) → Transaktion → users (S) → user_zones (S) → zones (X, aufsteigend) → zone_history_state (X) → Fachtabellen → Historien-INSERTs. Publish-Journaltabellen werden **nie** aus Fachtransaktionen geändert. |

## 7. Audit-Ereignisse

- Getrennt von Revisionen; beide verweisen auf dieselbe Änderungsmenge. Eine Operation kann Audit-Ereignis und
  Revision erzeugen.
- Erfasst werden u. a. (Umfang wird in H2c festgelegt): Zonen-Metadaten (Beschreibung, `allow_dyndns` – setzen heute
  kein `changed`), Server (Anlage, Änderung, Aktivierung/Deaktivierung, `api_ip`, Token-Rotation, Löschung, BIND-Reload),
  DynDNS-Konten (Anlage, Umbenennung, Passwortänderung, Löschung), Benutzer- und Rechteänderungen, Discard, Restore.
- Geheime Felder ausschließlich als Kennzeichen (`{"api_token":"rotated"}`, `{"password":"changed"}`), nie mit Wert.
  Keine Client-IP (E9).
- Atomar mit der Fachänderung; Operationen ohne Transaktion (Reload) danach im Autocommit mit Ergebnis.
- Abgelehnte Zugriffe bleiben im Syslog wie bisher; die Datenbank-Historie enthält nur committete Änderungen.

## 8. Publish-Journal

| ID | Regel |
|---|---|
| J1 | **Lauf registrieren vor jedem Seiteneffekt:** `publish_runs` im Autocommit unter dem Advisory-Lock, mit `connection_id`. Scheitert die Registrierung, endet der Lauf ohne Seiteneffekt (Flags wie heute). |
| J2 | **Versuch atomar mit der Serial:** In der Generator-Transaktion werden vor dem Serial-Commit `zone_publish_attempts` (Revision, Hashes, Serial, Lauf) und je Zielserver `zone_publish_targets` mit `sending` angelegt. Serial verbraucht ⇔ Versuch registriert. Scheitert die Registrierung: Rollback, keine Datei, kein Agent-Aufruf, Zone bleibt ausstehend (2 → 1). |
| J3 | Revision und Hashes werden im Generator unter der Zonensperre bestimmt; der Versuch meldet „Revision N mit Datei X verteilt“, nie „aktueller Stand verteilt“. |
| J4 | Ergebnisse nach jeder Agentenantwort eintragen. Diese Schreibvorgänge sind nicht sicherheitsrelevant; scheitern sie, bleibt der Vorgang offen. |
| J4a | **Unveränderlich** sind die Registrierungsdaten: Lauf (ID, Art, Full-Rebuild, `connection_id`, Start), Versuch (Lauf, Zone, Revision, `content_hash`, `render_hash`, `file_hash`, Serial, Generierungszeit) und Zielzeile (Versuch, Server, Servername zum Zeitpunkt). **Aktualisiert** werden ausschließlich die Lebenszyklusfelder – Lauf: Ende und Status; Versuch: `outcome`; Ziel: `zone_sync`/`conf_sync` und die Ergebnisangaben (`conf_hash`, Agent-Serial, Agent-Dateihash, Reload-Ergebnis, Fehlercode/-text) – und zwar nur einmal vom offenen in einen Endzustand (Ergebnis nach J4 oder `abandoned` nach J6). Ein Endzustand und die mit ihm gespeicherten Ergebnisse werden nie mehr geändert. |
| J5 | **Lese-Regel:** Zielzeile `sending` oder Versuch ohne `outcome` ⇒ Ziel `unknown`, sobald der zugehörige Lauf den Advisory-Lock nicht mehr hält (`IS_USED_LOCK` ≠ registrierte `connection_id`). Kein zusätzlicher Schreibvorgang nötig. |
| J6 | **Wiederanlauf:** Jeder neue Lauf und jedes Discard markiert offene Vorgänge beendeter Läufe unter dem Advisory-Lock als `abandoned` (nur Lesbarkeit). Scheitert die Markierung, bricht der neue Vorgang vor dem ersten Seiteneffekt ab. |
| J7 | Ordnung von Bestätigungen ausschließlich über Lauf-/Versuchs-IDs (DB-vergeben, monoton, Läufe serialisiert) – nie über die Serial. |
| J8 | `changed`/`bind_dirty` und ihre Abschlussregeln bleiben unverändert. |

## 9. Veröffentlichungsebenen und Zustände

| Ebene | Bedeutung |
|---|---|
| **L1 Versuche** | was erzeugt und gesendet wurde; Registrierungsdaten unveränderlich, Lebenszyklusfelder nur einmal vom offenen in einen Endzustand (J4a) |
| **L2 Agent-bestätigt** | letzter vom Agenten angenommener Stand je Server (Datei übernommen, Konfiguration geprüft); vor AS ohne Reload-Nachweis und als `legacy` gekennzeichnet |
| **L3 Verifiziert** | tatsächlich geladener Stand; nach AS über autoritative SOA-Abfrage an den lokalen BIND (E17) |
| **L4 Synchronisation** | abgeleitet aus aktueller Zielmenge, Head und L2 (optional L3); nur berechnet |

**Zustand je Zielserver (L4):** `in_sync` (vollständig synchron), `content_equal` (nur `render_hash` gleich),
`behind`/`differs`, `unknown`, `never_confirmed`. Zusätzlich je Server und Zone `residue`: Server ist kein Ziel mehr,
hält die Zone aber noch (Zuweisung entzogen und Cleanup noch nicht gelaufen; deaktivierter Server – der Cleanup
erfasst nur aktive Server). Rückstände sind Warnungen und beeinflussen den Zonenzustand nicht.

**Zustand je Zone:**

| Zustand | Bedingung |
|---|---|
| `synced` | alle Ziele vollständig synchron mit dem Head; `changed = 0` |
| `pending_clean` | Head ≠ veröffentlichter Stand; alle Ziele vollständig synchron mit **demselben** Stand P; kein offener oder unbekannter Vorgang |
| `partial` | Ziele bestätigen unterschiedliche Dateien, nur `content_equal`, oder ein Ziel ist fehlgeschlagen |
| `unknown` | offener/abgebrochener Vorgang, reaktivierter Server, nur `legacy`-Bestätigung, Ablehnung `stale_*` |
| `unverified` | Baseline ohne Ausgangsbestimmung (Abschnitt 15) |

**Topologie:** Aktivierung setzt alle Zonen des Servers in derselben Transaktion auf `unknown` (zusätzlich zum
bestehenden `bind_dirty = 1`); Zuweisungsänderungen sind Inhaltsänderungen (neue Revision); Deaktivierung entfernt den
Server aus der Zielmenge ohne Revision.

`changed ≠ 0` bleibt das operative Pending-Kennzeichen. **`Head > P` ist keine gültige Pending-Definition**:
Synchronität wird ausschließlich über Hashes und Bestätigungen bestimmt, nie über Revisionsnummern.

## 10. Gleichheit, SOA-Serial und RFC 1982

- **Vollständig synchron** = bestätigte bzw. verifizierte Datei byte-identisch (`file_hash`, inkl. Serial) mit der
  Referenzveröffentlichung, und alle Ziele haben denselben `file_hash`.
- **Inhaltsgleich** (`render_hash` gleich, Serial abweichend) gilt als `partial` (`serial_divergent`), nie als
  `synced`.
- `render_hash` ist nötig, weil die Zonendatei auch vom Generator-Code abhängt (z. B. RRset-TTL-Vereinheitlichung,
  PR #95): derselbe Snapshot kann je Codestand verschiedene Dateien ergeben.
- **SOA-Serial wird nie zurückgesetzt** (auch nicht bei Discard/Restore); sie gehört zur Veröffentlichung, nicht zur
  Revision.
- **RFC 1982** (32 Bit ohne Vorzeichen) gilt für die Serial-Regel des Agenten (I-A1) und die Wahl der nächsten Serial:
  s1 < s2 ⇔ (i1 < i2 ∧ i2 − i1 < 2³¹) ∨ (i1 > i2 ∧ i1 − i2 > 2³¹); Abstand genau 2³¹ ist undefiniert und gilt als
  „nicht größer“. Gleiche Serial nur bei byte-identischer Datei (idempotente Wiederholung).
- Die nächste Serial muss nach RFC 1982 größer sein als die DB-Serial und als jede zuletzt bestätigte Serial aller
  aktuellen Ziele (auch aus Vorgängen unter demselben Zonennamen); existiert kein solcher Wert, wird gesperrt.
  **[offen – E15/E16, Meilenstein AS]**: Umstellung von `generate_next_serial()` (heute numerischer Vergleich) und
  Spaltentyp `zones.soa_serial` (heute `INT` mit Vorzeichen).
- Testumgebung: kein AXFR (`allow-transfer { none; }`), keine externen Secondaries; Serial-Regeln dienen dort dem
  Agent-Schutz.

## 11. Agent-Sicherheit und Fencing (Meilenstein AS)

**Risiko:** Der Advisory-Lock gehört zur DB-Verbindung. Geht sie verloren (Abbruch, `KILL`, MariaDB-Neustart), verteilt
der PHP-Prozess die laufende Zone weiter (`distribute_zone_file()` greift nach dem Laden der Server nicht mehr auf die
DB zu; bis 30 s je Server). Ein zweiter Lauf kann inzwischen eine neuere Serial verteilen; eine verspätete ältere Datei,
Konfiguration oder ein Cleanup mit veralteter Zonenliste überschreibt bzw. entfernt dann neuere Stände.

**Getrennte Token je Zone und für Cleanup genügen nicht** (Gegenbeispiel: verspäteter Cleanup eines älteren Laufs
entfernt eine von einem neueren Lauf angelegte Zone, wenn der neuere Lauf keinen Cleanup ausgeführt hat).

| ID | Invariante |
|---|---|
| F1 | **Ein** Fencing-Höchststand H je Agent für alle schreibenden Endpunkte: `zone_sync`, `conf_sync`, `zone_cleanup` (**[offen]**: Einbeziehung `system/control reload`). |
| F2 | Annahme ⇔ Token t ≥ H; danach H := t. Gleichheit zulässig (ein Lauf sendet mehrere Anfragen nacheinander). |
| F3 | Prüfen, dauerhaft Fortschreiben und Ausführen unter dem exklusiven `flock` des Agenten; Prüfung von t **nach** Erwerb des `flock`. Reihenfolge: `flock` → H lesen → ablehnen oder H per Temp-Datei, `fsync` der Datei, `rename`, `fsync` des Verzeichnisses schreiben → erst danach ändern → Reload. Schlägt einer dieser Persistenzschritte fehl, wird die Anfrage ohne Änderung abgelehnt (fail-closed). |
| F4 | H liegt dauerhaft im Zonenverzeichnis des Agenten (neben `.dnsmanager-agent.lock`, ext4). **Absturzsicherheit:** Der persistierte Höchststand darf auch nach einem Systemabsturz oder Stromausfall nie auf einen Wert unterhalb eines bereits akzeptierten Tokens zurückfallen; eine Änderung wird erst ausgeführt, wenn H dauerhaft geschrieben ist. Fehlt H, ist H unlesbar oder ungültig, oder schlägt eine Datei- bzw. Verzeichnisoperation fehl ⇒ im Erzwingungsmodus alle Schreibvorgänge abgelehnt (`fence_uninitialized` bzw. Persistenzfehler); Initialisierung nur ausdrücklich durch Admin mit Wert ≥ höchster vergebener Lauf-ID. Dauerhaftigkeit der Datei- und Verzeichnisoperationen und fail-closed-Verhalten sind in AS nachzuweisen. |
| F5 | Token = `publish_runs.id`, dauerhaft vergeben vor dem ersten Agent-Aufruf (J1); dieselbe Folge für Publish All und Publish Zone. |
| I-A1 | Serial-Regel: Zonendatei nur ersetzen, wenn neue Serial nach RFC 1982 größer als die vorhandene; gleiche Serial nur bei byte-identischem Inhalt. |
| I-A3 | Antwort enthält übernommene Serial, `sha256` der installierten Datei und ausgewerteten Reload-Exit-Code; Reload-Fehler ist ein eigener Status. |
| I-A4 | Ablehnung lässt den Bestand unverändert, eigener Code (`stale_fence`, `stale_serial`, `fence_uninitialized`); der Core setzt das Ziel auf `unknown`, ohne automatische Wiederholungsschleife. |
| I-A5 | Neue Felder optional, Fähigkeitsmeldung über `system/status`; Bestätigungen von Agenten ohne Schutz sind `legacy` und erlauben kein Discard. |

**Restrisiko:** Zurückgespieltes DB-Backup kann `AUTO_INCREMENT` unter H senken ⇒ Agenten lehnen ab (fail-closed,
erkennbar); Abhilfe durch Anheben des `AUTO_INCREMENT`.

**L3 (E17):** geladene SOA-Serial per autoritativer DNS-Abfrage an den lokalen BIND (`allow-query` umfasst
127.0.0.1); keine Erweiterung der sudoers-Rechte (heute nur `rndc reload` und `rndc status`).
**[offen]**: ob die Abfrage im Agenten oder im Monitoring erfolgt.

**Einführung:** (1) Agent zeichnet nur auf, (2) Web sendet Token und Hashes, (3) Erzwingen, zuerst ns2.

## 12. Discard

**Definition:** Verwirft den gesamten ausstehenden Stand einer Zone und stellt den letzten vollständig
veröffentlichten Stand P wieder her – **ohne** erneutes Publish. Bloßes Zurücksetzen von `changed`/`bind_dirty` ist
unzulässig.

**Zulässig nur aus `pending_clean`.** Gesperrt (mit Grund) bei:
- `partial`, `unknown`, `unverified`; Bestätigungen nur `legacy` (vor AS ist Discard daher immer gesperrt);
- ausstehenden Revisionen aus Änderungsmengen der Art Server, Zuweisung oder DynDNS (Konto oder API);
- Abhängigkeiten nach Abschnitt 14;
- gelöschter Zone; nie veröffentlichter Zone (kein P);
- abweichendem erwartetem Head (`conflict`).

**Ablauf:** Advisory-Try-Lock → Wiederanlauf J6 → Transaktion: users (S) → user_zones (S) → zones (X) →
zone_history_state (X) → Prüfung erwarteter Head und Sperrgründe → Benutzer-Records und Zonenfelder (ohne Serial,
`soa_ns` abgeleitet) auf P → Systemrecords aus aktueller Zuweisung ableiten → Schutzregeln (Glue, NS, DynDNS) →
Prüf-Rendering ohne Flag → nur wenn `render_hash` = `render_hash` von P und alle Ziele mit P vollständig synchron:
`changed = 0`, Revision `discard` (`source_revision_no = P`), Audit → Commit. Andernfalls Abbruch ohne Änderung
(`not_discardable`) mit Verweis auf „Wiederherstellen und neu verteilen“.

**Plan-Methode:** liefert vorab `allowed`, Sperrgründe und betroffene Zonen, ohne zu schreiben.

**[offen – E4]**: Berechtigung (Empfehlung zunächst nur admin; alternativ Schreibrecht nach `ZoneAccess`).

## 13. Restore sowie „Wiederherstellen und neu verteilen“

- **Restore (H5):** setzt einen historischen Inhalt als neue Revision `restore`; Serial unverändert weiterlaufend;
  Ergebnis ausstehend (`changed = 1`), keine automatische Veröffentlichung. `plan()` (Vorschau und Prüfung) und
  `apply(expectedHead, revision)`.
- Wiederhergestellt werden Benutzer-Records und DNS-Zonenfelder. **Nicht**: Systemrecords (aus aktueller Zuweisung
  abgeleitet), `soa_ns` (vom Master abgeleitet), DynDNS-verwaltete Records (Konflikt ⇒ Sperre), Serverzuweisung
  (E11), Serial.
- Jeder wiederhergestellte Record wird erneut mit `validateDnsRecord` geprüft, zusätzlich `named-checkzone`.
  Diff nach Inhaltsschlüssel, damit unveränderte Records ihre IDs behalten.
- **Wiederherstellen und neu verteilen** (H5, admin): fachlich vom Discard getrennter Vorgang für `partial`/`unknown`;
  setzt eine Zielrevision (Standard: letzter vollständig veröffentlichter Stand) als Revision `restore`,
  `changed = 1`, danach ausdrücklicher Publish. Eigene Audit-Operation `zone.restore_republish`.

**Zustandsübergänge:**

| Von | Vorgang | Nach |
|---|---|---|
| `pending_clean` | Discard (Bedingungen erfüllt) | `synced` |
| `pending_clean` | Discard (Rendering weicht ab) | unverändert, gesperrt |
| `partial` / `unknown` / `unverified` | Discard | gesperrt |
| beliebig | Restore / Wiederherstellen und neu verteilen | Pending (`changed = 1`) |
| beliebig | Publish | `synced`, `partial` oder `unknown` |

## 14. Zonenübergreifende Abhängigkeiten

| Fall | Regel |
|---|---|
| Forward-Discard, zugehöriger AutoPTR existiert im aktuellen Head der Reverse-Zone | gesperrt (`linked_record_present`), mit Nennung von Zone und Record |
| Reverse-Discard entfernt einen AutoPTR, dessen Forward-Record im Head noch existiert | gesperrt (symmetrisch) |
| Mehrzonen-Änderungsmenge (Server, Zuweisung, DynDNS) | gesperrt |
| AutoPTR `exists` / `skipped` / `failed`, Prozessende zwischen T1 und T2 | keine Abhängigkeit |
| Manuell angelegter PTR zu neuem A-Record | keine erfasste Abhängigkeit (bekannte Grenze) |

Die Sperre richtet sich nach dem Inhalt im Head, nicht nach dem Publish-Zustand. **Gruppen-Discard** (Meilenstein
H4b): alle Zonen der Abhängigkeitshülle in einer Transaktion (aufsteigende Zonensperre), jede einzeln zulässig,
Vorschau aller betroffenen Zonen und Änderungen, Berechtigung für alle Zonen.

## 15. Migration und Baseline

1. Migration `sql/migrations/002_…` mit Skript nach dem Muster von 001 (Preflight, Sicherung, `--check`,
   wiederholbar). Reihenfolge: Migration → Initialisierung → Code. `SchemaCheck` wird um die neuen Tabellen erweitert
   (fail-closed). **Umgesetzt in H2a (Abschnitt 5a, E28):** Migration → Code → Initialisierung, weil die
   Initialisierungs-CLI Teil des Codes ist; gleichwertig, da H2a die Historie zur Laufzeit nicht nutzt. Vor dem
   Ausrollen von H2b muss die Initialisierung abgeschlossen sein (oder der Recorder legt die Baseline lazy an, Punkt 4).
2. Initialisierung per CLI unter dem Advisory-Lock: je Zone Revision `baseline` (`migration.baseline`, Akteur
   `system`), `tracking = baseline_unverified`, alle Ziele `never_confirmed`. **Keine** konstruierte frühere Historie,
   **kein** angenommener Veröffentlichungsstand.
3. Discard bleibt gesperrt, bis die Zone einmal `synced` war. Wege zur Ausgangsbestimmung:
   - **lazy (Standard):** erster regulärer Publish der Zone nach der Migration;
   - **Full Rebuild (optional, admin):** `bind_dirty = 1`, Publish All; erhöht alle Serials, je Zone und Server zwei
     vollständige `rndc reload`;
   - **Abgleich ohne Republish (nach AS):** Agent meldet Serial und Hash der vorhandenen Datei; Baseline wird mit
     dieser Serial gerendert und verglichen; bei Dateien eines älteren Generator-Stands Rückfall auf die anderen Wege.
4. Fehlt ein Head (z. B. Code ohne Initialisierung), legt der Recorder lazy eine Baseline `baseline_unverified` an.
   Lief nach der Migration wieder alter Code, erkennt die Vorher-Prüfung (R-H3) die ungetrackten Änderungen.

## 16. Berechtigung und Datenschutz

- Lesen über `ZoneAccess` in derselben SQL-Anweisung; Nichtvorhandensein und fehlende Berechtigung identisch.
- **E10:** zoneadmins sehen Akteursnamen für Änderungen innerhalb ihrer berechtigten Zonen, keine Informationen aus
  fremden Zonen (auch nicht über zonenübergreifende Änderungsmengen oder Abhängigkeiten). Historie gelöschter Zonen nur
  für admin.
- Keine Passworthashes, Agent-Token oder DynDNS-Benutzernamen (nur Konto-ID) in Snapshots, Audit oder Journal; keine
  Client-IP; Agent-Fehlertexte gekürzt und bereinigt. Suchtest auf Hash-/Token-Muster ist Abnahmebestandteil.

## 17. Core-Services (Zuständigkeiten, Entwurf)

| Baustein | Zuständigkeit |
|---|---|
| `ZoneStateSnapshot` (rein) | Kanonisierung, `contentHash()`, Diff nach Inhaltsschlüssel |
| `ZoneHistoryRecorder` | `beginZone()` (Head sperren, Vorher-Prüfung/Drift), `commitZone()`, `recordZoneDeletion()`, `linkDependency()`; nur in offener Transaktion bei gehaltener Zonensperre, sonst `LogicException` |
| `AuditRecorder` | `record()` mit Pflicht-Schwärzung über Feld-Whitelist |
| `PublishLedger` | Lauf-/Versuchsregistrierung (J1/J2), Ergebnisse (J4), Wiederanlauf (J6); Autocommit, Aufruf in offener Transaktion wird abgewiesen |
| `ZoneHistoryReadService` (H3) | Revisionen paginiert, Revision, Diff, Abhängigkeiten |
| `ZoneSyncReadService` (H3) | L1–L4, Zonen- und Zielzustände, Rückstände, Gründe |
| `ZoneDiscardService` (H4) | `plan()`, `discard(actor, zoneId, expectedHead)` |
| `ZoneRestoreService` (H5) | `plan()`, `apply()`, Wiederherstellen und neu verteilen |

Schnittstellenerweiterungen (additiv, bestehende Ergebnisfelder unverändert):
- `ZoneBuildBackend::validate(zoneId)` – Prüf-Rendering ohne Flag mit `renderHash`; `validateAndFlag()` bleibt.
- `PublishBackend::generateZone()` liefert zusätzlich `revisionNo`, `contentHash`, `renderHash`, `fileHash`, `serial`.
- `distributeZone()`, `distributeZoneConf()`, `cleanupOrphans()` liefern zusätzlich strukturierte Ergebnisse je
  `serverId`.
- Aufrufkontext (`HistoryContext`): Actor oder DynDNS-Konto-ID oder `system`, Operation, Kanal, Korrelation. Web-Adapter
  bleiben unverändert.

## 18. Meilensteine

| MS | Inhalt | Abnahmekriterien |
|---|---|---|
| **H1** | Bestandsaufnahme, Architektur (dieses Dokument) | Freigabe 18.11.2026; Merge dieses Dokuments |
| **H2a** | Schema, Migration 002 mit Skript, `SchemaCheck`, `ZoneStateSnapshot`, Baseline-CLI – **umgesetzt** (Abschnitt 5a, Tests `tests/history`) | Migration wiederholbar inkl. `--check`; alter Code mit neuem Schema lauffähig; Web-Golden identisch; keine Laufzeitänderung |
| **H2b** | `ZoneHistoryRecorder` in allen Schreibpfaden, Drift, Zonenlöschung, `history_dependencies` | R-H1–R-H9 nachgewiesen; Rollback ohne Rückstände; REPEATABLE READ (mit Snapshot-Isolation) und READ COMMITTED; Web-Golden identisch |
| **H2c** | `audit_events` für Operationen ohne DNS-Wirkung | keine Geheimnisse (Suchtest); atomar mit Fachänderung |
| **H2d** | Publish-Journal J1–J8, Hashes, strukturierte Zielergebnisse, `unknown` bei Reaktivierung | Fehlerinjektion (Registrierung, Ergebniseintrag, Prozessende); vollständige Publish-Regression auf ns1/ns2 (ok, partial, Abbruch, Cleanup-Fehler, Full Rebuild); `changed`/`bind_dirty` unverändert |
| **AS** | Fencing F1–F5, I-A1–I-A5, L3 nach E17, Serial-Wahl (E15/E16) | Lock-Verlust mit zwei Verbindungen; veraltete Konfiguration/Cleanup; Neuanlage gleichen Namens; Agent-Neustart; Absturzsicherheit von H (F4) und fail-closed bei Persistenzfehlern (Fehlerinjektion je Datei- und Verzeichnisoperation); gestufte Einführung |
| **H3** | Historienoberfläche: Core-Read-Services (`ZoneHistoryReadService`, `ZoneSyncReadService`) **und** Weboberfläche mit Historienliste, Filtern, Detailansichten, Vorher-/Nachher-Vergleich, Berechtigungen und Paginierung | Berechtigung in derselben Anweisung, E10, Pagination, keine Geheimnisse; Oberfläche nach `docs/UI_GUIDELINES.md` und `docs/UI_ROLE_MATRIX.md`, Web-Golden für bestehende Seiten unverändert |
| **H4** | abgeleitete Zustände, `ZoneDiscardService` | alle Sperrgründe; Race-Tests (Lock-Wait, Gegenprobe, Mutationsprobe); Discard nur aus `pending_clean` |
| **H4b** | Gruppen-Discard | Abhängigkeitshülle, Vorschau, Berechtigung für alle Zonen |
| **H5** | `ZoneRestoreService`, Wiederherstellen und neu verteilen | Serial monoton; DynDNS-/Systemrecords geschützt; Revalidierung; Rollback |

Reihenfolge: H2a → H2b → H2c (parallel zu H2b möglich) → H2d → AS → H3 → H4 → H4b → H5.

## 19. Testumgebung und Teststrategie

- **ns1** (192.168.53.122: Oberfläche, MariaDB, BIND, Agent) und **ns2** (192.168.53.125: BIND, Agent) sind dedizierte
  Entwicklungs- und Testmaschinen des Projekts, keine externen Produktivsysteme. Kontrollierte schreibende Tests,
  Migrationen und Fehlersimulationen sind im freigegebenen Auftragsrahmen zulässig; vorher Sicherung und Rückbau
  festlegen.
- Lesend erhobener Stand 18.11.2026: MariaDB 11.8.6, `transaction_isolation = REPEATABLE-READ`,
  `innodb_snapshot_isolation = 1`, `wait_timeout = 28800`; BIND 9.20.24, beide Server `type master`,
  `allow-transfer { none; }`; Zonendateien beider Server byte-identisch, Datei-, geladene und DB-Serial je Zone
  gleich; Agent-sudoers nur `rndc reload` und `rndc status`.
- Teststufen: rein (Kanonisierung, Hash-Verträge, Diff, Sperrgründe, RFC 1982) → isolierte Datenbanken unter beiden
  Isolationsstufen → Fehlerinjektion → deterministische Nebenläufigkeit mit nachgewiesenem Lock-Wait, Gegenprobe und
  Mutationsprobe → Web-Golden (`tests/ui`) → Integration auf ns1/ns2.

## 20. Entscheidungsregister

| ID | Entscheidung | Status |
|---|---|---|
| E1 | Variante C (Hybrid) | freigegeben (H1) |
| E2 | additive Erweiterung `PublishBackend`/`ZoneBuildBackend` | freigegeben (H1) |
| E3 | Baseline `unverified`; Ausgangsbestimmung lazy, Full Rebuild optional, Abgleich nach AS | freigegeben (H1) |
| E4 | Berechtigung Discard/Restore | **offen** (Empfehlung: zunächst nur admin) |
| E5 | Gruppen-Discard als eigener Meilenstein H4b | freigegeben (H1) |
| E6 | Discard nur mit AS-geschützten Bestätigungen (kein `legacy`) | freigegeben (H1) |
| E7 | Vorher-Prüfung in jeder Fachtransaktion | freigegeben (H1) |
| E8 | keine automatische Löschung; Retention später | entschieden 18.11.2026 |
| E9 | keine Client-IP in Historie, Audit und Journal | freigegeben (H1) |
| E10 | Akteursnamen für zoneadmin nur in berechtigten Zonen | entschieden 18.11.2026 |
| E11 | Restore ohne Serverzuweisung; keine Wiederherstellung gelöschter Zonen | freigegeben (H1) |
| E12 | Journal: Registrierung vor Seiteneffekt, Lese-Regel `unknown` (ersetzt „unrecorded“) | freigegeben (H1) |
| E13 | AS vor Freischaltung von Discard | freigegeben (H1) |
| E14 | Discard bei Server-, Zuweisungs- und DynDNS-Änderungen gesperrt | freigegeben (H1) |
| E15/E16 | Serial-Strategie und Migration der Spalte `zones.soa_serial` | **offen** (Meilenstein AS); festgelegt ist nur die Anforderung, 32-Bit-Serials ohne Vorzeichen zu unterstützen (H2a berücksichtigt sie) |
| E17 | L3 über autoritative SOA-Abfrage an den lokalen BIND, keine sudoers-Erweiterung | entschieden 18.11.2026 |
| E18 | Migration 002 nur mit den vier Grundtabellen; weitere Tabellen in den zugehörigen Meilensteinen | freigegeben (H2a) |
| E19 | Spaltentypen der Grundtabellen (Abschnitt 5a), keine FKs auf Fachtabellen | freigegeben (H2a) |
| E20 | `content_hash` als Primärschlüssel der Snapshots, keine separate `snapshot_hash`-Spalte | freigegeben (H2a) |
| E21 | Snapshot-Vertrag `zs1` | freigegeben (H2a) |
| E22 | Diff über Inhaltsschlüssel als Multimenge | freigegeben (H2a) |
| E23 | Snapshot-Leser mit ausschließlich sperrenden Lesevorgängen nach `zones` X | freigegeben (H2a) |
| E24 | Baseline-CLI: Try-Lock, je Zone eigene Transaktion, wiederholbar, ohne angenommenen Veröffentlichungsstand | freigegeben (H2a) |
| E25 | `SchemaCheck` prüft die Grundtabellen vollständig, fail-closed für Login | freigegeben (H2a) |
| E26 | Migrationsskript: vollständiger Struktur-Preflight vor der ersten DDL; technischer Abbruch mit deterministischem Wiederanlauf | freigegeben (H2a, mit Präzisierung) |
| E27 | Rückweg: Code-Rollback ohne Löschen der History-Daten; `DROP TABLE` nur als freigegebene Sondermaßnahme | freigegeben (H2a, mit Präzisierung) |
| E28 | ns1: Migration und Baseline nach isolierten Tests, mit Sicherung und Rückweg; ns2 unverändert | freigegeben (H2a) |

**Weitere offene Implementierungsdetails:** genaue Tabellen-/Spaltennamen und Codes; Umfang `audit_events` (H2c);
Einbeziehung `system/control reload` in das Fencing; Ort der L3-Abfrage (Agent oder Monitoring); Aufteilung des
Generators für `render_hash`; Entfernung der ungenutzten Tabelle `deployments` (gesonderte Entscheidung).

## 21. Änderungsprotokoll

| Datum | Änderung |
|---|---|
| 18.11.2026 | Erstfassung nach Abschluss AP1/H1 (Bestandsaufnahme, Review-Nachträge, lesende Inspektion ns1/ns2, Entscheidungen E8, E10, E17; zu E15/E16 nur die 32-Bit-Anforderung, Strategie und Spaltenmigration offen für AS) |
| 18.11.2026 | Review PR #97: H3 umfasst ausdrücklich auch die Weboberfläche; E15/E16 als offen gekennzeichnet; F3/F4 um Absturzsicherheit und fail-closed bei Persistenzfehlern ergänzt; Unveränderlichkeit und Lebenszyklus der Journaldaten (J4, L1) präzisiert |
| 18.11.2026 | H2a umgesetzt: Abschnitt 5a (endgültige Grundtabellen, Vertrag `zs1`, Diff, Leser, Baseline, `SchemaCheck`, Migration), Entscheidungen E18–E28, Reihenfolge Migration → Code → Initialisierung (Abschnitt 15) |
