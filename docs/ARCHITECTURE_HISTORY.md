# DNS Manager – Änderungshistorie, Versionsstände, Discard und Restore: Architektur

Stand: 20.11.2026 · Architektur AP1/H1 (Basis `6e8133c`) · umgesetzt bis Meilenstein H2d (Basis `14a4018`) · AS0 geprüft, AS1 (Agent-Fencing) umgesetzt, AS2a (Serial-Regel, Zustandsprüfung) umgesetzt, AS2b (Nachverifikation, Nachweis der Entfernung) umgesetzt, AS3 (Migration 006) umgesetzt, AS4a (Migration 007, Kompatibilitätsbrücke) umgesetzt

## 0. Status und Geltung

- **Dieses Dokument beschreibt eine freigegebene Architekturentscheidung.** Umgesetzt sind die Meilensteine **H2a**
  (Abschnitt 5a: History-Grundtabellen mit Migration 002, `SchemaCheck`-Erweiterung,
  `ZoneStateSnapshot`/`ZoneSnapshotDiff`/`ZoneSnapshotReader`, Baseline-Initialisierung) und **H2b** (Abschnitt 5b:
  `ZoneHistoryRecorder` in allen DNS-relevanten Schreibpfaden, Drift, Lazy-Baseline, Zonenlöschung,
  `history_dependencies` mit Migration 003) und **H2c** (Abschnitt 5c: `audit_events` mit Migration 004,
  `AuditPolicy`/`AuditRecorder` in allen Verwaltungspfaden) und **H2d** (Abschnitt 5d: Publish-Journal mit Migration 005,
  `PublishLedger`, Hash-Verträge, strukturierte Zielergebnisse) sowie aus Meilenstein AS das Arbeitspaket **AS1**
  (Abschnitt 5e: Agent-Fencing mit Höchststand, Write-ahead-Fehlermarke, Fencing-Domäne, Modi off/observe/enforce,
  lokale CLI, dauerhafte Dateioperationen) und **AS2a** (Abschnitt 5f: RFC-1982-Serial-Regel, Konfigurations- und
  DNS-Zustandsprüfung vor der Übernahme, Fristen, Wirkungsangabe `effect`, Klassifizierung im Core) und **AS2b**
  (Abschnitt 5g: Nachverifikation nach dem Reload in `conf_sync`, Nachweis der Entfernung im Cleanup, Polling mit
  Zeitbudget). Alle übrigen hier beschriebenen Tabellen, Klassen, Agent-Erweiterungen und Abläufe existieren im Code noch
  nicht; insbesondere sendet der Core noch kein Fencing-Token und wertet die Nachverifikation noch nicht aus (AS4).
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
| `zone_server_verification` | geladener Stand (L3, nach AS) | umgesetzt mit AS3, endgültige Struktur Abschnitt 5h |

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
Fremdschlüssel, CHECK-Bedingungen mit normalisiertem Ausdruck) und ist fail-closed für Login und Session-Revalidierung. Dieselbe
Erwartung nutzt das Migrationsskript; es gibt nur eine Definition.

**Migration (E26, E27, E28)** – siehe `sql/migrations/README.md`: vollständiger Struktur-Preflight aller vorhandenen
History-Tabellen vor der ersten DDL (Abweichung ⇒ Exit 2 ohne Änderung); technischer Abbruch während der DDL ⇒ Exit 3,
angelegte Tabellen bleiben, Wiederanlauf deterministisch. Ein Code-Rollback löscht keine History-Daten; `DROP TABLE` nur
als freigegebene, datenverlustbehaftete Sondermaßnahme mit Sicherung. Reihenfolge: Migration → Code → Baseline (die
Initialisierungs-CLI ist Teil des Codes; H2a nutzt die Historie zur Laufzeit nicht).

## 5b. Festlegungen H2b (umgesetzt)

**Bausteine (E29, E30)** – `web/inc/app/history/`:
- `ZoneHistoryRecorder`, eine Instanz je Fachtransaktion. Ablauf im Service: `ensureHeads()` **vor** der Transaktion
  → Transaktion mit Berechtigungs- und **allen** Zonensperren → `open()` → `beginZones()` bzw. `zoneCreated()` →
  Fachänderung (`zoneDeleting()` vor `DELETE FROM zones`) → `finish()` unmittelbar vor `commit()` → `close()` im
  `finally`.
- `HistoryContext`: Akteurart (`user`, `dyndns_account`, `system`), Benutzer- bzw. Konto-ID, Kanal (`Actor::$channel`,
  DynDNS-API `api`, CLI `cli`), Operation und Korrelation (UUIDv4 je Service-Aufruf). `actor_label` liest der Recorder
  in der Transaktion aus der dort bereits S-gesperrten `users`-Zeile (Name zum Zeitpunkt, nicht aus der Session).
  Kontext und Recorder erzeugen die Services selbst; Konstruktoren und Web-Adapter bleiben unverändert. Abweichend vom
  Plan gibt es keine Test-Factory: Fehlerinjektion erfolgt über Trigger in isolierten Testdatenbanken.
- `HistoryWriter` (gemeinsame Schreibbausteine, auch für die CLI-Baseline) und `ZoneSnapshotStore` (E33).

**Schreibpfade und Operationen (E31):**

| Pfad | Operation | Revisionen |
|---|---|---|
| `ZoneService::createZone` | `zone.create` | Revision 1 `create`, `tracking = tracked` |
| `ZoneService::updateZone` | `zone.update` | `change` bei geändertem DNS-Inhalt (auch Serverzuweisung) |
| `ZoneService::deleteZone` | `zone.delete` | `delete` mit dem Inhalt vor dem DELETE, `deleted_at` |
| `RecordService::createRecord` / `updateRecord` / `deleteRecords` | `record.create` / `record.update` / `record.delete` | `change` je Zone; `deleteRecords` mehrzonig |
| `RecordService::createAutoPtr` (T2) | `record.auto_ptr` | `change` der Reverse-Zone, Korrelation von T1, `history_dependencies` |
| `ServerService::updateServer` (Name/DNS-IP) | `server.update` | `change` je zugewiesener Zone, eine Änderungsmenge |
| `DyndnsAccountService::updateAccount` / `deleteAccount` | `dyndns_account.update` / `dyndns_account.delete` | `change`; bei Zonenwechsel in beiden Zonen |
| `DyndnsUpdateService::update` | `dyndns.api_update` | `change`; Akteur `dyndns_account` (nur Konto-ID), Kanal `api` |

Ohne Revision, weil der DNS-Inhalt nach `zs1` unverändert bleibt: Beschreibung und `allow_dyndns` von Zonen,
DynDNS-Kontoanlage und Passwortänderung, Serveranlage, Serveränderungen nur an `active`, `api_ip`, Token oder
`is_local`, Benutzer- und Rechteverwaltung, Publish und Generator (`soa_serial`, `changed`, `bind_dirty`). Die
Verwaltungsvorgänge erfasst H2c als Audit-Ereignisse (Abschnitt 5c); Publish und Generator gehören zum
Publish-Journal (H2d) und erzeugen keine Audit-Ereignisse (E43). `deleteServer` ist nur ohne Zuweisung zulässig; ein Cascade auf
`records.server_id` träfe nur Altdaten, die die Invariante verletzen, und erschiene als Drift.

**Änderungsmengen und Revisionen (E31, E32):** Änderungsmengen entstehen erst mit der ersten Revision. Je
Fachtransaktion höchstens eine Änderungsmenge des Akteurs und eine `history.drift` (Akteur `system`), beide mit
derselben Korrelation; die Lazy-Baseline (`history.baseline_lazy`) läuft in eigener Transaktion. Je Zone in dieser
Reihenfolge: `drift` (Vorher-Hash ≠ Head) → `change` (Nachher-Hash ≠ Head) bzw. `delete`. `diff_summary` =
`ZoneSnapshotDiff::summary()` gegenüber der Vorgängerrevision (Anlage gegen leer, Löschung `NULL`);
`source_revision_no` bleibt `NULL`. `tracking` bestehender Heads ändert H2b nicht.

**Sperr- und Lesereihenfolge (E34, Präzisierung von R-H2 und R-H9):**
1. Advisory-Lock (wo bisher) → Transaktion → `users` (S) → `user_zones` (S bzw. X bei Zonenlöschung) → **alle**
   betroffenen `zones` (X, aufsteigend) → `zone_history_state` (X, aufsteigend, nur vorhandene Zeilen, Satzsperre) →
   Vorher-Snapshot (`zone_servers`/`records` S) → Fachtabellen → `finish()` (History-INSERTs, Head-UPDATE) → Commit.
2. **Kein nicht sperrendes Lesen vor dem Ende von `beginZones()`**; `beginZones()` selbst liest ausschließlich
   sperrend. Nachweis auf MariaDB 11.8 (`innodb_snapshot_isolation = 1`): Ein sperrendes Lesen, ein Schreiben oder eine
   Fremdschlüsselprüfung auf eine Zeile, die nach dem Read-View der Transaktion bestätigt wurde, ergibt Fehler 1020,
   und der Server setzt dann die **gesamte** Transaktion zurück (`PDO::inTransaction()` bleibt dennoch `true`). Ein
   Read-View in `beginZones()` würde z. B. das nachfolgende `dyndns_accounts … FOR UPDATE` gegen eine parallele
   Passwortänderung (ohne Zonensperre) scheitern lassen (Test N03, Mutationsprobe M03).
3. `ServerService::updateServer` ermittelt die zugewiesenen Zonen deshalb **vor** der Transaktion unter dem
   Topologie-Lock (alle Schreiber von `zone_servers` halten ihn; das Ergebnis ist bis zum Commit stabil). Bisher legte
   dieses einfache Lesen den Read-View vor den Zonensperren fest (Test N02, Mutationsprobe M04). Alle übrigen Pfade
   erfüllen die Reihenfolge unverändert.
4. READ COMMITTED: Read-View je Anweisung, keine Gap-Sperren, kein 1020; die Reihenfolge gilt einheitlich.

**Zonen ohne Head (E35, Präzisierung von R-H2):** Fehlende Heads legt `ensureHeads()` vor der Fachtransaktion an –
je Zone in einer eigenen, kurzen Transaktion nach dem Muster der H2a-Baseline: `zones` X → Snapshot sperrend lesen →
Head **nicht sperrend** prüfen (der Read-View entsteht erst nach der Zonensperre; Heads werden nur unter ihr angelegt)
→ Baseline → Commit. Es entsteht keine Gap-Sperre; zwei Zonen ohne Head blockieren sich unter keiner Isolationsstufe
(Test N04). Gegenprobe: `FOR UPDATE` auf die fehlende Zeile führt unter REPEATABLE READ zum Deadlock 1213, unter
READ COMMITTED nicht (N04b, Mutationsprobe M10). In der Fachtransaktion sperrt `beginZones()` nur vorhandene Heads;
fehlt ein Head dort (nur bei einer Zonenanlage durch Code ohne Historie zwischen Vorprüfung und Transaktion), folgen
`RuntimeException` und vollständiger Rollback, ohne auf eine Gap-Sperre zu warten (D06). Die Zonenanlage legt ihren
Head ohne vorheriges Lesen per INSERT an. Die Lazy-Baseline ist ein Systemvorgang und bleibt bestehen, auch wenn die
anschließende Fachänderung abgelehnt wird oder scheitert (D04).

**Snapshot-Ablage (E33):** Existenzprüfung nicht sperrend (Snapshots sind unveränderlich), sonst einfaches INSERT – keine
Gap-Sperre im gemeinsamen Hash-Raum. Duplicate-Key-Pfad: Nach jedem Fehler wird `@@in_transaction` geprüft. Bei 1062
mit bestehender Transaktion wird der vorhandene Eintrag sperrend gelesen und byte-genau verglichen (identisch ⇒ Erfolg,
abweichend ⇒ `RuntimeException`). Bei 1020 (REPEATABLE READ mit Snapshot-Isolation, Eintrag jünger als der Read-View)
hat der Server die Transaktion bereits zurückgesetzt; der Store bricht ohne weitere Anweisung ab (nie Weiterarbeit im
Autocommit). Fachlich ist dieser Fall nicht erreichbar: Gleicher Inhalt bedeutet gleichen Zonennamen, also dieselbe
Zonensperre, und der Read-View entsteht erst nach ihr; ein solcher Eintrag ist stets sichtbar (S03c). Tests S01–S05.

**Abgleich gelöschter Zonen (E36):** `history_baseline-cli.php --reconcile-dry-run|--reconcile` unter dem
Advisory-Lock; je Zone eigene Transaktion: Head `FOR UPDATE` (vorhandene Zeile) → Zone weiterhin nicht vorhanden →
Revision `delete` mit dem letzten bekannten Hash (`history.reconcile_deleted`, Akteur `system`, Kanal `cli`) →
`deleted_at`. Wiederholbar.

**Abhängigkeiten (E37, E38):** Migration 003 legt `history_dependencies` an (Record-Schlüssel `(name, type, content)`
beider Seiten, Änderungsmenge und Revision beider Seiten, `kind = auto_ptr`; Fremdschlüssel nur auf
`history_change_sets` und `zone_revisions (zone_id, revision_no)`, alle `RESTRICT`). Ein Eintrag entsteht nur bei
`ptrOutcome = created` in T2, nicht bei `exists`, `skipped`, `failed` oder einem Prozessende zwischen T1 und T2.

**Wächter (E39):** `BindZoneBuildBackend::validateAndFlag()` und `rebuildNsGlueAndFlag()` verlangen eine offene,
nicht abgeschlossene Erfassung derselben Verbindung, die die Zone erfasst. Die Bindung an die laufende Transaktion wird
in der Datenbank nachgewiesen: `open()` legt einen Savepoint an, der Wächter prüft ihn per `RELEASE SAVEPOINT` /
`SAVEPOINT`. Savepoints enden mit Commit oder Rollback; eine frühere Klammer autorisiert damit keine spätere
Transaktion, auch ohne `close()` (G02, G03). Zusätzlich: Freigabe durch `finish()` und `close()` (im `finally` jedes
Pfads), Register je Verbindung (`WeakReference`), eine neue Klammer entwertet die vorherige. Ergänzend statische Prüfung
aller Schreibstellen (`tests/history/static_rh8_test.php`).

**Fehlerverhalten (E40):** Jeder Fehler der Historie ist eine Exception in der Fachtransaktion und erscheint als
bisheriger Fehlercode des Pfads (`zone.*.failed`, `record.*.db_save_failed`, `record.delete.failed`,
`record.create.ptr_db_failed`, `server.update.failed`, `dyndns.*.failed`, `dyndns.api.exception`). Keine neuen Codes,
Sprachschlüssel, HTTP-Status oder Redirects; im Fehlerfall enthält das bisherige `detail` einen Text „History: …“ ohne
Geheimnisse. Bekannte Grenze: Ein Fehler 1062 aus der Historie würde in `DyndnsAccountService` wie bisher jeder
Duplicate-Key-Fehler als `duplicate_username` gemeldet; der Recorder erzeugt 1062 nur bei inkonsistenten Daten
(Wiederverwendung einer Zonen-ID nach einem Datenbank-Restore).

**Web-Golden (E41):** `tests/ui` leert die History-Tabellen je Fall (sofern im Schema vorhanden); der Vergleich bleibt
auf die Fachtabellen beschränkt. Die History-Erwartungen je Pfad prüft `tests/history/recorder_test.php`
(Abweichung vom Plan: keine History-Zusammenfassung je Golden-Fall).

**Betrieb (E42):** Mutierende Pfad-, Drift-, AutoPTR-, Rollback- und Nebenläufigkeitstests ausschließlich in isolierten
Datenbanken. Auf ns1 Migration 003, Code per SHA, `--check` und `--reconcile`; Testzonen in der installierten
Anwendung nur mit festgelegtem Rückbau, ohne Publish All. Historieneinträge gelöschter Testzonen bleiben erhalten (E8).
ns2 unverändert.

## 5c. Festlegungen H2c (umgesetzt)

**Umfang (E43)** – Audit-Ereignisse entstehen nur für committete Änderungen gegenüber dem **gesperrten** Stand; `unchanged`,
Ablehnungen (`access_denied`, `invalid_*`, `not_found`, `last_admin` …) und Fehler erzeugen keinen Eintrag. Abgelehnte
Zugriffe bleiben wie bisher ausschließlich im Syslog.

| Pfad | Operation (Änderungsmenge) | Ereignis |
|---|---|---|
| `ZoneService::createZone` / `updateZone` / `deleteZone` | `zone.create` / `zone.update` / `zone.delete` | `zone`: `description`, `allow_dyndns`; Löschung zusätzlich Kaskadenzähler (E53). Reine DNS-Änderungen ohne Ereignis |
| `ServerService::createServer` / `updateServer` / `deleteServer` | `server.create` / `server.update` / `server.delete` | `server`: `name`, `dns_ip4`, `dns_ip6`, `api_ip`, `is_local`, `active`; `api_token` nur als Kennzeichen. Ein impliziter Wechsel des bisherigen lokalen Servers ist ein eigenes Ereignis derselben Änderungsmenge |
| `ServerService::reloadBind` | `server.reload` | `server`, Aktion `reload`, nur `result` (`ok`/`failed`), nicht transaktional (E51) |
| `DyndnsAccountService::createAccount` / `updateAccount` / `changePassword` / `deleteAccount` | `dyndns_account.create` / `.update` / `.password` / `.delete` | `dyndns_account`: `hostname`, `zone_id`; `username` und `password` nur als Kennzeichen; Label = Hostname |
| `UserService::createUser` / `updateUser` / `deleteUser` / `changePassword` / `setActive` | `user.create` / `.update` / `.delete` / `.password` / `.disable` / `.enable` | `user`: `username`, `role`, `active`, `zones` (hinzugefügt/entfernt; „alle Zonen“ als `mode = all` mit tatsächlicher Anzahl, Q4); `password` nur als Kennzeichen |

Ohne Audit-Ereignis: Record-Pfade und AutoPTR (reiner DNS-Inhalt, Revision), DynDNS-API (`current_ipv4/6` sind
Client-IPs; DNS-Wirkung als Revision), Publish und Generator (H2d), Login, Session-Revalidierung, Monitoring,
Baseline-/Reconcile-CLI (eigene Systemänderungsmengen).

**Abgrenzung (E44)** – Revisionen bilden den DNS-Inhalt nach `zs1` ab, Audit-Ereignisse Verwaltungsfelder außerhalb
davon und Entitäten ohne Snapshot. DNS-Inhalt wird nicht im Audit wiederholt (z. B. Serverumbenennung: Name im
Ereignis, NS/Glue nur in den Revisionen).

**Tabelle `audit_events` (E45)** – maßgeblich `sql/migrations/004_audit_events.sql` und `SchemaCheck`: `id`;
`change_set_id` (FK → `history_change_sets`, `RESTRICT`); `seq` SMALLINT UNSIGNED **NOT NULL** ≥ 1 mit UNIQUE
`(change_set_id, seq)`; `entity_type` ENUM(zone, server, dyndns_account, user); `entity_id` ≥ 1 (ohne FK);
`entity_label` NOT NULL, nicht leer; `zone_id` (Zone: **NOT NULL und = `entity_id`**, DynDNS-Konto: NOT NULL,
Server/Benutzer: NULL); `action` ENUM(create, update, delete, reload; `reload` nur für Server); `changes` JSON-Objekt,
nicht leer, höchstens 16384 Bytes. MariaDB weist eine Zeile nur bei FALSE zurück, UNKNOWN gilt als erfüllt; alle
CHECK-Bedingungen sind deshalb NULL-sicher formuliert (explizite `IS [NOT] NULL` vor Vergleichen). Keine FKs auf
Fachtabellen, keine Kaskaden, append-only (E8). Die Zeit ergibt sich aus `history_change_sets.created_at`.

**Migration (E46)** – `scripts/migrate_004_audit_events.sh` nach dem Muster von 003 (Preflight aller Grundtabellen und
von `audit_events` vor der DDL ⇒ Exit 2; Abbruch während der DDL ⇒ Exit 3, Wiederanlauf; Sicherung). Reihenfolge
Migration → Code; alter Code läuft mit dem neuen Schema, neuer Code mit altem Schema ist fail-closed (503).

**`AuditRecorder` (E47)** – eine Instanz je Fachtransaktion: `open()` nach der Akteurssperre liest den Akteursnamen
sperrend (**Stand zu Beginn des Vorgangs**, auch bei Selbstlöschung und Selbstumbenennung; ein Lesen erst beim Commit
würde die Selbstlöschung scheitern lassen); `record()` puffert und prüft nur (keine Datenbankanweisung, kein
Read-View); `finish()` unmittelbar vor dem Commit; `close()` im `finally`. Bindung an die Transaktion über einen eigenen
Savepoint (Muster E39); bei einer Mischoperation gilt die Bindung des `ZoneHistoryRecorder` (dessen spätere
Savepoint-Prüfungen würden einen jüngeren Savepoint auflösen). Der `ZoneHistoryRecorder` erhält nur die lesenden
Ergänzungen `isFinished()` und `assertCurrentTransaction()`; seine Semantik bleibt unverändert.

**`AuditPolicy` (E48)** – verbindliche Feld-Whitelist je Entitätstyp mit Feldklassen (`name`, `ip`, `bool`, `id`,
`role`, `text`, `zones`, `secret`, `count`, `result`); unbekannte, verbotene oder falsch typisierte Felder sind ein
Programmfehler (`LogicException` ⇒ Rollback). Geheimnisse gelangen nie als Wert in den Recorder, nur als Kennzeichen
`set`/`changed`/`rotated`/`cleared`. Bezeichner ohne Leerraum, Steuerzeichen, `$`, Anführungszeichen und Backslash
(schließt Hashes aus), höchstens 255 Zeichen. Freitext (Zonenbeschreibung) ist nicht automatisch geheimnisfrei: höchstens
500 Zeichen (`…_truncated`), bei Geheimnismuster (bcrypt/argon2/crypt-Hash, privater Schlüssel, Token-ähnliche
Zeichenfolge ≥ 32 Zeichen) vollständig geschwärzt (Wert `null`, `…_redacted`). Kein Agent-Antworttext. Ausgabe
deterministisch in Whitelist-Reihenfolge; Infrastruktur-IP-Adressen der Server sind zulässig (Q1), Client-IPs nie.

**Änderungsmengen und Korrelation (E49)** – Mischoperationen (Revision und Ereignis) verwenden **exakt dieselbe**
Änderungsmenge (gleicher `HistoryContext`, gleiche Korrelation); reine Verwaltungsänderungen legen genau eine neue an.
Drift bleibt eine eigene Systemänderungsmenge derselben Korrelation. Ereignisse einer Änderungsmenge tragen `seq` 1..n.

**Konsistenz paralleler Serveränderungen (E50)** – `updateServer` bestimmt DNS-Relevanz (Name/DNS-IP) und Änderung von
`active` zunächst ungesperrt (Lock-Entscheidung, Zonenliste) und wiederholt beides nach `servers … FOR UPDATE`. Ergibt
erst der gesperrte Stand eine DNS-relevante Änderung (ohne Topologie-Lock, Zonenliste, Rebuild und Revision) oder eine
Änderung von `active` ohne gehaltenen Topologie-Lock, endet der Vorgang ohne Schreibzugriff mit dem bestehenden Code
`server.update.topology_locked` (wie bei belegtem Lock; erneut versuchen). Die Reaktivierung (`bind_dirty`) richtet sich
nach dem gesperrten Stand. Der bisherige lokale Server wird vor dem bestehenden `UPDATE … SET is_local = 0` per
`SELECT … FOR UPDATE` bestimmt (dieselben Zeilen sperrt das UPDATE ohnehin). Alle Vorher-Werte stammen aus gesperrten
Zeilen (R-A5); ebenso bei Server- und Benutzerlöschung.

**BIND-Reload (E51)** – nach dem Agentenaufruf eigene kurze Transaktion (`users` S für den Namen; fehlt der Akteur
inzwischen, bleibt das Label leer) mit Änderungsmenge `server.reload` und Ergebnis. Scheitert der Nachtrag, bleibt das
Ergebnis unverändert; die Meldung trägt zusätzlich `audit = failed` und `auditDetail`, der Adapter schreibt eine
Protokollzeile (Q2 b); Toast, Redirect und HTTP-Status sind unverändert. Bekannte Grenze: Endet der Prozess zwischen
Reload und Nachtrag, fehlt das Ereignis (Syslog bleibt).

**Fehlerverhalten (E52)** – wie E40: Jeder Audit-Fehler ist eine Exception in der Fachtransaktion und erscheint als
bisheriger Fehlercode des Pfads (`*.failed`) mit `detail`; keine neuen Codes, Sprachschlüssel, HTTP-Status oder
Redirects. Ein 1062 aus `audit_events` würde in `DyndnsAccountService` als `duplicate_username` erscheinen; er entsteht
nur bei einem Programmfehler (doppeltes `seq`).

**Kaskade der Zonenlöschung (E53)** – Zähler `dyndns_accounts_removed` (sperrend nach der Zonensperre gezählt, Reihenfolge
`zones → dyndns_accounts` wie `DyndnsAccountService`; das kaskadierende DELETE sperrt dieselben Zeilen) und
`user_assignments_removed` (aus der bestehenden `user_zones`-Sperre); keine Kontonamen.

**Tests (E54)** – `tests/history/audit_test.php` (RR und RC), `migration004_test.sh`, statischer Nachweis R05–R09 in
`static_rh8_test.php`, Mutationsproben MA01–MA13; Einzelheiten `tests/history/README.md`.

## 5d. Festlegungen H2d (umgesetzt)

**Umfang (E55)** – Migration 005 legt nur `publish_runs`, `zone_publish_attempts`, `zone_publish_targets` und
`publish_cleanup_results` an. Nicht enthalten: `zone_server_verification` und Agent-Verifikationsfelder (Agent-Serial,
Agent-Dateihash, Reload-Ergebnis; Q6 – erst mit AS). Keine Agent- oder Protokolländerung, kein Eingriff in
`generate_next_serial()` oder `zones.soa_serial` (E15/E16 bleiben offen). `deployments` bleibt unberührt.

**Tabellen (E56)** – maßgeblich `sql/migrations/005_publish_journal.sql` und `SchemaCheck` (NULL-sichere CHECKs wie E45,
Fremdschlüssel nur innerhalb von Historie und Journal, `RESTRICT`, append-only):

| Tabelle | Festlegung |
|---|---|
| `publish_runs` | `id` BIGINT UNSIGNED (künftiger Fencing-Token); `kind` all/zone mit `requested_zone_id`; `full_rebuild`; `connection_id`; `history_watermark` (E70); Akteurskontext `actor_type`, `actor_user_id`, `actor_dyndns_account_id`, `channel`, `correlation_id` (Q2, CHECK wie `history_change_sets`); `started_at`; Lebenszyklus `status` running → finished (mit `result` = Service-Status) bzw. abandoned, `finished_at` |
| `zone_publish_attempts` | `run_id`; `zone_id`, `zone_name`; `revision_no` und `revision_basis` head/head_mismatch/no_head (FK `(zone_id, revision_no, content_hash)` → `zone_revisions`, bei NULL nicht erzwungen); `content_hash` (zs1), `render_hash` (rh1), `file_hash`, Vertragskennungen; `soa_serial` INT UNSIGNED; `target_count`; `generated_at`; Lebenszyklus `outcome` published/partial/failed bzw. abandoned; UNIQUE `(run_id, zone_id)` |
| `zone_publish_targets` | PK `(attempt_id, server_id)`; `server_name` zum Zeitpunkt; `agent_protocol` = legacy; je Kanal `zone_sync`/`conf_sync` sending → ok/failed/rejected/unknown bzw. abandoned mit Zeitpunkt, HTTP-Status, Fehlercode und bereinigtem Fehlertext (≤ 500 Zeichen); `conf_hash` |
| `publish_cleanup_results` | PK `(run_id, server_id)`; `server_name`; `agent_protocol`; `status` sending → ok/partial/failed/rejected/unknown bzw. abandoned; nur Zähler (`valid_zone_count`, `removed_conf_count`, `removed_zone_file_count`, Q5), HTTP-Status, Fehlercode/-text |

**Ledger (E57)** – `PublishLedger` ist der einzige Schreiber der Journaltabellen (statisch R10/R11). Autocommit-Rolle:
`abandonStale` (J6), `registerRun` (J1), `recordZoneTarget`/`recordConfTarget`/`finishAttempt`/`registerCleanup`/
`recordCleanup`/`finishRun` (J4) – jede weist eine offene Transaktion ab und verlangt den Publish-Lock dieser Verbindung
(J6/J1). Transaktionale Rolle: ausschließlich `registerAttempt` (J2), nur in der offenen Generator-Transaktion, nur mit
einem offenen Lauf-Token desselben Ledgers (`PublishRun`, nur unter dem Lock ausgegeben), nur solange dieselbe
Verbindung Publish-Lock und Laufsperre hält, nur lesende Anweisungen und INSERTs. Die Generator-Transaktion ist eine
Publish-, keine Fachtransaktion; Fachservices erhalten nie ein Token und kennen das Journal nicht (R-H9).

**Laufbeginn (E58, Q7)** – Lock → J6 → `bind_dirty` und Wasserstand lesen (unter dem Lock stabil: alle Schreiber von
`bind_dirty` und `zone_servers` halten ihn) → J1 → laufeigene Sperre → erst dann Übernahme von `bind_dirty`/`changed`.
Scheitert J6 oder J1: bestehender Status `exception` („Publish-Journal: …“), keine Flag-Änderung, kein Backend-Aufruf.
Der übernommene Full-Rebuild-Zustand muss der Registrierung entsprechen (sonst Exception mit bestehendem
Wiederherstellungspfad). Scheitert nach der Registrierung die Bindung an das Backend (`bindJournal`), schließt
`openJournal()` den Lauf mit `exception` ab und gibt seine laufeigene Sperre frei, bevor der Abbruch wie bei J1 erfolgt;
auf derselben Verbindung bleibt keine Laufsperre zurück (Review S1, Tests S01–S06, Mutationsprobe MJ15).

**Versuch atomar mit der Serial (E59)** – `generate_zone_file()` ruft im Modus `publish` nach `named-checkzone` und vor dem
Serial-Update den Journal-Hook auf (statisch R13). Der Ledger liest in derselben Transaktion nach der Zonensperre
Zuweisung, Zielserver (`zone_servers ⋈ servers WHERE active = 1`) und Head nicht sperrend (unter RR derselbe Read-View wie
die gerenderten Zeilen) und legt Versuch und Zielzeilen (beide Kanäle `sending`) an. Fehler ⇒ Rollback, Datei gelöscht,
keine Serial, kein Agentaufruf, Zone 2 → 1 (bestehender Pfad `generation_failed`). Serial verbraucht ⇔ Versuch registriert;
eine erzeugte Datei ohne Versuch bricht den Lauf vor der Verteilung ab.

**Hash-Verträge (E60)** – `content_hash` = zs1 aus **genau den gerenderten Zeilen** (nie aus `head_hash`); `file_hash` =
SHA-256 der geschriebenen und gesendeten Bytes (vor dem Senden geprüft, Abweichung ⇒ keine Übertragung,
`file_hash_mismatch`); `render_hash` (rh1) = SHA-256 der Ausgabe von `zone_file_render()` mit Platzhalter `@SERIAL@`,
gleichwertig: in der Datei das einzige Vorkommen von `"\n" + 12 Leerzeichen + Serial + "   ; Serial\n"` maskiert
(`PublishHashContract`, eingefrorener Vektor mit `sha256sum`); `conf_hash` = SHA-256 der je Server gesendeten
Konfiguration. `zone_file_render()` ist eine reine, byte-identische Extraktion aus dem Generator (Nachweis gegen den
Basis-Commit, B01).

**Revisionsbezug (E61)** – `revision_basis = head` nur bei nicht gelöschtem Head mit `head_hash = content_hash`; sonst
`head_mismatch` bzw. `no_head` ohne Revision. Publish schreibt keine Historie. Ein späterer Head ändert den Versuch nie
(„Revision N mit Datei X verteilt“, J3/J4a).

**Zielmenge (E62)** – Zone- und Conf-Verteilung beliefern ausschließlich die registrierte Zielmenge (unter dem Lock
identisch mit der aktuellen). Ein registriertes, nicht mehr aktiv zugewiesenes Ziel (nur bei Invariantenbruch,
z. B. Lock-Verlust) wird nicht beliefert und als `failed`/`target_changed` eingetragen. Null Ziele ⇒ Versuch `failed`
(heute fataler Verteilungsfehler, Serial bereits verbraucht).

**Einordnung (E63, Q3)** – `PublishTargetClassifier`, je Endpunkt getestet: `ok` = HTTP 200 mit Agent-JSON `success`;
`rejected` = Agent-JSON `error` mit HTTP ≠ 200 ohne `rollback = failed` (Bestand unverändert); `failed` = sicher nicht
zugestellt (TLS-Konfiguration, Namensauflösung, Verbindungsaufbau, TLS-Handshake, lokaler Fehler vor dem Senden);
`unknown` = Zustellung möglich, Ergebnis unbestimmt (Zeitüberschreitung auch beim Verbindungsaufbau, Abbruch, Antwort
ohne Agent-JSON, `rollback = failed`); Cleanup zusätzlich `partial` (Fehler nach einer Änderung: entfernte Dateien
oder – im Praxistest auf ns2 festgestellt – `zones.conf` neu geschrieben und neu geladen ohne entfernte Datei, erkennbar
am Schlüssel `rndc` der Fehlerantwort; nie `rejected`). Fehlertexte ohne
Steuerzeichen, tokenähnliche Folgen geschwärzt, ≤ 500 Zeichen. Die Einordnung ist reines Journalwissen: die operative
Auswertung (HTTP 200 = Erfolg), `changed`/`bind_dirty`, Meldungen und Web-Verträge bleiben unverändert (J8).

**Operatives Ergebnis ≠ Bestätigung (E65, Präzisierung 4 der Freigabe)** – `outcome` des Versuchs spiegelt den operativen
Ausgang von `processZone()` (published/partial/failed); eine Exception lässt ihn offen. `published` bedeutet nur, dass
alle Agenten operativ mit HTTP 200 geantwortet haben; es ist **keine** L2-/L3-Bestätigung. Vor AS sind alle
Zielbestätigungen `legacy` (`agent_protocol`): ohne Agent-Serial, Agent-Dateihash oder Reload-Nachweis, die das Journal
auch nicht ableitet. Ein Ziel mit `unknown`, ohne Bestätigung oder mit einer veralteten Bestätigung (E70) gilt nie als
synchron, unabhängig vom Gesamtstatus des Versuchs (Test O03: operativ veröffentlicht, Ziel `unknown`).

**J4 (E64, Q4)** – Das Backend meldet jede Agentenantwort unmittelbar nach ihrem Eingang (`PublishJournal`). Schreibfehler
ändern das operative Ergebnis nicht; sie erscheinen im Bericht unter `journal` (`writeFailures`, `errors`) und je Fehler
in einer Logzeile „[publish] Journal: …“. Der Vorgang bleibt offen (J5 `unknown`, J6 `abandoned`).

**Cleanup (E66)** – Vor dem ersten `zone_cleanup` werden alle aktiven Server mit einer Anweisung als `sending`
registriert; scheitert das, wird kein Cleanup ausgeführt (bestehender Weg: Cleanup-Warnung, `bind_dirty = 1`).

**Unveränderlichkeit (E67, Q8)** – nur bedingte Übergänge aus dem offenen Zustand; kein UPDATE berührt
Registrierungsdaten; kein DELETE; kein DB-Trigger. Nachweis statisch (R12–R12d) und dynamisch (J07–J13).

**J5 und Datenbankneustart (E68, E73, Präzisierung 3 der Freigabe)** – Nach der Registrierung hält die Laufverbindung
zusätzlich die **laufeigene Advisory-Sperre** `dnsmanager_run_<12 Hex des Datenbanknamens>_<Lauf-ID>`. Lebendig ist ein
Lauf ausschließlich, wenn er `running` ist und dieselbe Verbindung (`connection_id`) Publish-Lock **und** Laufsperre hält
(`PublishJournalReader`). Advisory-Sperren enden mit der Verbindung und mit jedem Neustart des Datenbankservers; eine
nach einem Neustart wiederverwendete Verbindungs-ID hält die Laufsperre nie, weil nur der registrierte Lauf sie bezieht.
Ist ein Lauf nicht eindeutig lebendig, gilt sein offener Zustand konservativ als `unknown`; J6 markiert ihn später als
`abandoned` und verweigert die Markierung, solange irgendeine Verbindung seine Laufsperre hält. Kein Heartbeat.
Nachweise: Verbindungsabbruch per `KILL` (L01), Prozessende per SIGKILL (L02), wiederverwendete Verbindungs-ID mit
Positivkontrolle und fremder Laufsperre (L03), J6 gegen gehaltene Laufsperre (L04), Zustand ohne Sperren wie nach einem
Neustart (L05). Ein echter Neustart von MariaDB auf ns1 wurde nicht ausgeführt (installierte Testinstanz); er entspricht
dem geprüften Zustand „alle Advisory-Sperren frei“.

**Laufabschluss und Wiederanlauf (E69)** – J6 in einer eigenen kurzen Transaktion (Ziele → Cleanup → Versuche → Läufe),
wiederholbar. Der Lauf wird mit dem Service-Status abgeschlossen; danach Freigabe von Laufsperre und Publish-Lock.

**Reaktivierung ohne Journal-Schreibzugriff aus der Fachtransaktion (E70, Präzisierung von Abschnitt 9)** – Abschnitt 9
(„Aktivierung setzt alle Zonen des Servers … auf unknown“) wird **abgeleitet**, nicht geschrieben (R-H9): Die
Reaktivierung ist ein Audit-Ereignis `server`/`update` mit `active` false → true (H2c) und hält den Publish-Lock; jeder
Lauf speichert `history_watermark` = höchste Änderungsmengen-ID bei seiner Registrierung unter dem Lock. Eine Bestätigung
ist für einen Server veraltet (`unknown`), wenn eine Reaktivierung dieses Servers eine größere Änderungsmengen-ID hat als
der Wasserstand ihres Laufs (ohne Uhrzeit; `PublishJournalReader`, Tests R01–R03). Die Anzeige folgt mit H3.

**Abgrenzung AS (E71)** – `publish_runs.id` wird vor dem ersten Agentaufruf dauerhaft vergeben (F5-fähig), aber nicht an
Agenten gesendet. Keine Fencing-Regeln, keine Protokolländerung, keine L3-Prüfung, kein Abbruch der Verteilung bei
Lock-Verlust (§ 11 bleibt Restrisiko bis AS).

**Akteurskontext (Präzisierung 2 der Freigabe)** – Publish All: Akteur `user` mit Kanal des Actors (`web`), neue
Korrelation. DynDNS: derselbe `HistoryContext` wie die Fachänderung (Konto-ID, Kanal `api`, dieselbe Korrelation); bei
einer reinen Wiederholung ohne Änderung entsteht keine Änderungsmenge, der Lauf trägt nur die Korrelation des Aufrufs.
`publishZone($zoneId, $lockWaitSeconds = 5, ?HistoryContext $context = null)` bleibt kompatibel; ohne Kontext
Systemakteur, Kanal `system`.

**Web-Verträge (E72)** – Im Normalfall identisch (Web-Golden gegen den Basis-Commit). Abweichungen nur unter
Fehlerinjektion: J1/J6 ⇒ `exception`; J2 ⇒ `generation_failed`; Cleanup-Registrierung ⇒ Cleanup-Warnung;
Datei-Hash-Abweichung bzw. nicht mehr zugewiesenes Ziel ⇒ Verteilungsfehler; J4-Fehler ⇒ zusätzliche Logzeile. Die
Berichte enthalten additiv `runId` und `journal`; die Verteilfunktionen additiv `targets`, `BindAgentClient` additiv
`curlErrno`/`tlsConfigError`.

**Testumgebung (Präzisierung 1 der Freigabe, Q1)** – Vollständige Szenarien (Erfolg, Teilerfolg, Abbruch,
Cleanup-Fehler, Full Rebuild, Zeitüberschreitung, Kill) laufen gegen eine **isolierte Zweiserver-Umgebung**: isolierte
Datenbank `dnsmgr_t21_*` und zwei Testagenten auf 127.0.0.4/127.0.0.5 (`tests/history/journal_agent.py`), die den
**echten Agent-Code** des geprüften Commits mit Sandbox-Konfiguration ausführen (Zonen, Konfiguration, `zones.conf`,
`named.conf` und rndc-Platzhalter unter `/tmp`). `/etc/bind/zones` wird vorher/nachher byte-gleich nachgewiesen (Z01).
Auf ns1/ns2 kein Publish All des installierten Bestands, solange dessen Wirkung auf die ausstehende Zone `example.test`
nicht gesondert freigegeben ist; keine manuellen Statuskorrekturen. Reale Ende-zu-Ende-Läufe gegen die installierten
Agenten sind damit als ausstehend dokumentiert (PR-Bericht).

**Praxistest ns1/ns2 (18.11.2026, nach Aufhebung von Q1 b durch den Maintainer)** – gegen die installierten Agenten,
über die Core-Services der installierten Anwendung: (E1) Publish All mit dem ausstehenden Stand von `example.test`;
(E2) Testzone anlegen und Publish Zone; (E3) Ablehnung durch den ns2-Agenten (`named-checkzone`-Fehler) ⇒ `rejected`,
operativ `partial`, Bestand auf ns2 unverändert; (E4) Prüfung auf ns2 länger als das Client-Timeout ⇒ `unknown`/`timeout`,
ns2 übernimmt die Datei danach nachweislich (sha256 = `file_hash`); (E5) SIGKILL des Publish-Prozesses während der
Verteilung ⇒ Lauf nicht lebendig, offene Ziele `unknown`, nächster Lauf J6 `abandoned` und Neuveröffentlichung;
(E6/E7) Zonenlöschung, Full Rebuild mit nicht löschbarer verwaister Datei auf ns2 ⇒ Cleanup-Warnung und `bind_dirty = 1`,
danach Full Rebuild mit vollständigem Cleanup. Endzustand: alle Zonen DB = Datei = geladen auf ns1 und ns2, keine offenen
Vorgänge, keine Rückstände. Befund: Der Cleanup-Fehler nach Reload wurde zunächst als `rejected` eingeordnet; korrigiert
auf `partial` (Abschnitt E63 oben, Test C20b, Mutationsprobe MJ16) und auf ns2 erneut bestätigt.

**Tests (E73)** – `tests/history`: `journal_pure_test.php` (rh1-Vektor, Einordnung, Bereinigung, Lese-Regeln),
`journal_test.php` (RR/RC: K Constraints, P Pfade/Hashes/Akteurskontext, O Endpunkte, F Fehlerinjektion, J Ledger/J4a,
L Lebendigkeit, N Nebenläufigkeit, R Reaktivierung, X Stress, B Byte-Gleichheit, Z Schutz), `migration005_test.sh`,
statisch R10–R14, Mutationsproben MJ01–MJ15; Web-Golden mit Reset der Journaltabellen. Die einzige informative
Web-Golden-Abweichung (`page/admin/system_health`) besteht ausschließlich aus den Dateirechte-Zeilen der sechs neuen
Dateien unter `web/inc/app/publish/` (Rechteprüfung der Testkopie) und dem entsprechend erhöhten Fehlerzähler; ohne sie
ist der normalisierte Seiteninhalt byte-identisch zur Basis (Review S2).

## 5e. Festlegungen AS0/AS1 (AS1 umgesetzt)

**AS0 – technische Vorprüfung (19.11.2026, Sandbox auf ns2, installierter Bestand unverändert):**
- PHP 8.5 synchronisiert Verzeichnisse mit `fopen($dir, 'r')` + `fsync()` (strace: `openat(…, O_RDONLY)`, `fsync`).
  `opendir()`-Handles sind ungeeignet. uutils-`sync <pfad>` ruft nur das globale `sync()` auf und ist **kein** Ersatz (E89).
- Absturzsimulation mit dm-flakey (`drop_writes`): Nur die vollständige Folge (Datei-`fsync`, `rename`,
  Verzeichnis-`fsync`) erhält den neuen Wert. Ohne Verzeichnis-`fsync` bzw. ohne `fsync` bleibt der alte Wert.
- Nach einem `fsync`-Fehler (dm-flakey `error_writes`) zeigt der Seitencache den neuen Wert, die Platte nicht; PHP
  meldet den Fehler nur über den Rückgabewert (E90).
- `rndc reload` kehrt vor dem Laden der Zonen zurück (≈ 25 ms; Zone mit 300 000 RR nach 1,65 s geladen), rc 0 auch bei
  ungültiger oder fehlender Zonendatei. BIND lädt rückwärts gehende und gleiche Serials (I-A1 nur im Agenten
  durchsetzbar). `dig` liefert rc 0 auch bei `REFUSED`; belastbar ist nur Status + `aa` + Owner (E91).
  `named-checkzone`: rc 0/1, fehlende, unlesbare und ungültige Dateien nur am Text unterscheidbar (E94). Diese
  Punkte setzt AS2 um.

**Höchststand H (F1–F4)** – `<zone_data_dir>/.dnsmanager-fence`, eine Zeile
`dnsmanager-fence v1 domain=<uuid> high=<n>` (Ganzzahl 1 … 2⁶³−1, ohne führende Null, LF). Jede andere Form, ein
Symlink oder ein Nicht-Dateieintrag ist ungültig. H gilt für `zone_sync`, `conf_sync` und `zone_cleanup` gemeinsam;
Annahme ⇔ t ≥ H, danach H := t (Gleichheit ohne Schreibvorgang). Kein API-Weg setzt H oder senkt ihn.
`system/control reload` bleibt ohne Fencing unter demselben `flock` (E75).

**Write-ahead-Fehlermarke (E90, Präzisierung 1 der AS1-Freigabe)** – `<zone_data_dir>/.dnsmanager-fence.fault`.
Fortschreiben von H unter dem `flock`:
1. Marke exklusiv anlegen, schreiben, `fsync`; Verzeichnis-`fsync`;
2. neuer Wert in Temp-Datei, `fsync`, `rename` auf H, Verzeichnis-`fsync`;
3. erst danach Marke entfernen, Verzeichnis-`fsync`.

Jeder Fehler bricht ab; eine angelegte Marke bleibt bestehen. Jede vorhandene Marke sperrt **in jedem Modus**
(`off`, `observe`, `enforce`; E97) alle drei schreibenden Endpunkte (`fence_fault`, HTTP 503, keine Änderung, kein
Reload), bis ein Administrator sie lokal per CLI nach Prüfung von H aufhebt.
Gleichwertigkeitsargument: Jeder Zeitraum, in dem Seitencache und Platte bei H auseinanderfallen können (ab dem ersten
Schreibvorgang an H bis zu dessen bestätigtem Verzeichnis-`fsync`), liegt innerhalb eines Zeitraums, in dem die Marke
dauerhaft existiert; sie wird schon vor diesem Zeitraum dauerhaft geschrieben, nicht erst nach einem erkannten Fehler.
Nach einem Absturz oder `fsync`-Fehler bleibt der Agent daher gesperrt, statt einem nur im Cache erhöhten H zu
vertrauen. Scheitert erst der Verzeichnis-`fsync` nach dem Entfernen der Marke, ist H bereits dauerhaft; die Marke kann
nach einem Absturz wieder erscheinen (sicherer Richtung). Die Anfrage wird in diesem Fall dennoch ohne Änderung
abgelehnt (`fence_persist_failed`).

**Fencing-Domäne (E74, Präzisierung 3)** – Der Agent prüft selbst gegen seine lokal konfigurierte Domäne
(`fence_domain`, UUID): Anfrage-Domäne und gespeicherte Domäne von H müssen ihr entsprechen; fehlt sie oder ist sie
ungültig, ist der Agent fehlkonfiguriert (`fence_misconfigured`). Die Core-Prüfung zwischen Datenbank und
Web-Konfiguration (AS4) ist eine zusätzliche, keine ersetzende Prüfung.

| Szenario | Erkannt durch |
|---|---|
| Test-Datenbank bzw. neu angelegte Installation (eigene Domäne) spricht fremde Agenten an | Agent: `fence_domain_mismatch` |
| Agent einer anderen Installation zugeordnet (falsche `fence_domain` oder H einer anderen Domäne) | Agent: `fence_domain_mismatch` / `fence_misconfigured` |
| Datenbank geklont, Web-Konfiguration nicht (AS4) | Core: Domäne in DB ≠ Web-Konfiguration |
| Datenbank-Restore derselben Installation (Lauf-IDs fallen) | Agent: `stale_fence`; Abgleich nach E81 (AS4) |
| **vollständiger Installationsklon** (Datenbank, Web-Konfiguration, Agent-Konfiguration und H) | **nicht** durch das Fencing; nur organisatorisch sowie durch `api_allowed_ips` (Quell-IP) und eigene Agent-Token je Installation |

**Modi (E82, Klarstellung E97 nach Review AS1, geändert durch E118)** – Die Modi steuern ausschließlich die
**Fencing-Prüfung**, nie die Persistenz- und Integritätssicherung. `fencing_mode` ist eine Pflichtangabe (E118, ersetzt
„`fencing_mode` fehlt ⇒ `off`“): Fehlt der Schlüssel oder ist der Wert unbekannt, brechen `zone_sync`, `conf_sync`,
`zone_cleanup` und `system/status` vor jeder Prüfung, jedem externen Prozess und jeder Änderung mit
`fence_misconfigured` (503) ab. Ausdrücklich `off`: keine Fencing-Prüfung, Fencing-Felder werden
ignoriert, die schreibenden Endpunkte lesen und schreiben H nicht; Antworten sind unverändert, solange `posix` vorhanden
ist und keine Fehlermarke vorliegt. `observe`: Prüfung und
Fortschreiben von H wie `enforce`, Fencing-Gründe (Token, Domäne, Höchststand) werden nicht abgelehnt; jede Antwort nach
der Prüfung enthält zusätzlich `fence` (`mode`, `decision` accept/would_reject/reject, `code`, `token`, `high_before`,
`high_after`), Gründe zusätzlich im PHP-Log `[fence]`. `enforce`: fail-closed. Fehlender Schlüssel oder unbekannter
Wert ⇒ `fence_misconfigured` (fail-closed, E118). **In jedem Modus** gilt (E97): eine vorhandene Fehlermarke sperrt
(`fence_fault`); in den Modi, die H verwenden (`observe`, `enforce`), brechen außerdem ein nicht lesbarer oder ungültiger
Höchststand (`fence_invalid`) und ein gescheitertes dauerhaftes Fortschreiben von H (`fence_persist_failed`) die Anfrage
ohne Änderung ab – das sind Integritätsfehler des gespeicherten Zustands, keine Fencing-Gründe; `fsync`-Fehler der Bestandsdateien führen modusunabhängig in die Fehlerpfade
der Endpunkte (E95). Ohne PHP-Erweiterung `posix` lehnt der Agent in jedem Modus jeden schreibenden Aufruf ab, noch
vor der Fehlermarke (`agent_requirement_missing`, E100). `system/status` meldet beides auch in `off` (siehe unten).

**Endpunkte (F3)** – Prüfung unmittelbar nach dem Erwerb des `flock` und vor jeder Änderung; Anfragefelder
`fence_token` (JSON-Zahl oder Dezimalzeichenkette) und `fence_domain`. Ablehnungen lassen den Bestand unverändert (kein
Reload; die vor dem Lock geschriebene Temp-Datei von `zone_sync` wird entfernt). Codes:

| Code | HTTP | Bedeutung |
|---|---|---|
| `fence_misconfigured` | 503 | Modus unbekannt, `fence_domain` fehlt oder ungültig |
| `agent_requirement_missing` | 503 | PHP-Erweiterung `posix` fehlt (E100); in jedem Modus, vor allen anderen Prüfungen |
| `fence_fault` | 503 | Fehlermarke vorhanden oder ihre Existenz nicht prüfbar (E97, E99); in jedem Modus |
| `fence_token_missing` / `fence_token_invalid` | 400 | Token oder Domäne fehlt bzw. ungültig |
| `fence_domain_mismatch` | 409 | Anfrage- oder gespeicherte Domäne ≠ konfigurierte Domäne |
| `fence_uninitialized` | 409 | H fehlt (Initialisierung nur per CLI) |
| `fence_invalid` | 503 | H unlesbar oder ungültig |
| `stale_fence` | 409 | t < H |
| `fence_persist_failed` | 503 | H nicht dauerhaft schreibbar (Bericht nennt den Schritt) |
| `listing_incomplete` | 500 | `conf_sync`/`zone_cleanup`: Verzeichnisauflistung nicht zweifelsfrei vollständig (E98); bisherige Konfiguration erhalten, kein Reload |
| `persist_unconfirmed` | 500 | nur `zone_sync`: Zonendatei ersetzt, Verzeichnis-`fsync` gescheitert; kein Reload, `rollback = failed` |

Die heutige Einordnung (E63) ordnet alle Fencing-Codes als `rejected` (Bestand unverändert) und `persist_unconfirmed`
wegen `rollback = failed` als `unknown` ein; eigene Codes im Journal folgen mit AS4.

**Dauerhaftigkeit des Bestands (E84, E95)** – In allen Modi, auch `off`: Zonen-Temp-Datei und alle atomar ersetzten
Dateien (`<zone>.conf`, `zones.conf`) werden vor dem `rename` mit `fsync` geschrieben; die betroffenen Verzeichnisse
werden vor dem Reload synchronisiert (`conf_sync` mit Rollback bei Fehler, `zone_cleanup` als unvollständiger Cleanup mit
`rndc`). E95: Ausdrücklich `off` ist im Normalbetrieb und in allen bisherigen Fehlerpfaden beobachtbar identisch zum
bisherigen Agenten (Golden G01, E118: der bisherige Agent ohne Schlüssel bzw. mit `off`; ein fehlender Schlüssel ist
seit E118 eine Fehlkonfiguration, Golden G02); abweichend ausschließlich in Fehler- bzw. Inkonsistenzfällen, und zwar abschließend:
gescheitertes `fsync` (E95; der bisherige Agent meldete dort einen nicht dauerhaften Erfolg), Lesefehler des
Konfigurationsverzeichnisses (E96), vorhandene oder nicht prüfbare Fehlermarke (E97, E99), unvollständige Auflistung,
verkürzt gelesene `zones.conf` und verwaiste Include-Zeile (E98), nicht prüfbare Existenz einer Sicherungs- oder
Bestandsdatei (E99), fehlende PHP-Erweiterung `posix` (E100).

**Befund S43 (behoben mit AS1)** – Die Fehlerinjektion zeigte, dass `glob()` bei einem Lesefehler des
Konfigurationsverzeichnisses eine leere Liste liefert: `conf_sync` hätte `zones.conf` ohne Includes geschrieben und
geladen (Antwort 200), `zone_cleanup` „keine verwaisten Zonen“ gemeldet. `GLOB_ERR` hilft unter PHP 8.5 nicht (auch
damit leere Liste, nachgewiesen); Behebung: `listConfFiles()` – Liste weiter per `glob()` (Reihenfolge und Inhalt wie
bisher), Lesefehler über `scandir()` und Abgleich beider Ergebnisse. Einzelheiten `ARCHITECTURE_CORE.md`, Abschnitt 6.
Die Suite prüft nach jedem Erfolg, dass `zones.conf` genau die vorhandenen `.conf`-Dateien einbindet (Mutationsproben
M16–M18).

**Nicht prüfbare Existenz (E99, Nachtrag Review AS1)** – `file_exists()`/`is_file()` liefern bei jedem `stat`-Fehler
„nicht vorhanden“; auf ns2 nachgewiesen: vorhandene Fehlermarke, `stat`/`access` mit EIO ⇒ `file_exists() = false`, die
Marke wäre in jedem Modus übergangen worden. `pathState()` (`posix_access()` mit `errno`) wertet nur ENOENT als „nicht
vorhanden“; jeder andere Fehler ist „nicht prüfbar“ und fail-closed: Fehlermarke gilt als vorhanden (`fence_fault`, alle
Modi), H als ungültig (`fence_invalid`, `observe`/`enforce`), die Sicherung von `<zone>.conf` bzw. `zones.conf` bricht ohne
Änderung ab, und im Rollback sowie beim Entfernen verwaister Dateien gilt eine Datei nie als entfernt, solange das nicht
nachgewiesen ist. Nachweise: Gruppe X (X1–X6), Mutationsprobe M25. Grenze: Ohne PHP-Erweiterung `posix` (auf ns1/ns2
vorhanden, Bestandteil von Ubuntu `php-common`) gibt es keinen Rückfall (E100): `pathState()` meldet „nicht prüfbar“,
`agentFenceCheck()` lehnt in jedem Modus jeden schreibenden Aufruf mit `agent_requirement_missing` (503) ab, noch vor der
Fehlermarke; `system/status` meldet `requirement_missing` auch in `off`; die CLI verweigert. Nachweis: X7, M26/M27.

**Vollständigkeit der Auflistung (E98, Nachtrag Review AS1)** – Nachgewiesen auf ns2: Ein `getdents64`-Fehler mitten im
Durchlauf verkürzt `scandir()` und `glob()` stillschweigend (z. B. 820 statt 2003 bzw. 818 statt 2001 Einträge); trifft
er beide Durchläufe gleich, besteht der Abgleich von S43 (Gruppe L1a/L2a: beide Listen gleich verkürzt). Ebenso liefert
`file_get_contents()` bei einem `read`-Fehler eine leere Zeichenkette statt `false` – die Sicherung der bisherigen
`zones.conf` wäre leer, ein Rollback hätte eine leere `zones.conf` geschrieben. Zusätzliche, unabhängige
fail-closed-Absicherung:
1. Die bisherige `zones.conf` (und die bisherige `<zone>.conf`) wird nur verwendet, wenn sie nachweislich vollständig
   gelesen wurde (`readFileComplete()`: gelesene Länge = `fstat`-Größe desselben Deskriptors); sonst Abbruch ohne
   Änderung. Ebenso wird der Höchststand H gelesen (`fenceReadHigh()`, zusätzlich Obergrenze `AGENT_FENCE_MAX_BYTES`):
   Ein verkürzter Read gilt als ungültiger Höchststand (`fence_invalid`), auch wenn das gelesene Präfix syntaktisch
   gültig ist (Review PR #103; Nachweis X8, M28).
2. Vollständigkeitsnachweis: Jedes bisherige `include` muss in der neuen `zones.conf` wieder enthalten sein
   (`conf_sync` fügt nur hinzu bzw. ersetzt und entfernt nie; zusätzlich muss die eigene Datei enthalten sein);
   `zone_cleanup` darf nur die von ihm selbst als verwaist erkannten Dateien weglassen. Fehlt ein Include oder ist ein
   Pfad nicht auflösbar, gilt die Auflistung als nicht zweifelsfrei vollständig: `listing_incomplete`, bisherige
   Konfiguration bleibt (Rollback der eigenen `.conf`), kein Reload; bei `zone_cleanup` auch kein No-op-Erfolg.

Folge für Inkonsistenzen: Eine `include`-Zeile auf eine nicht (mehr) vorhandene Datei („verwaiste Include-Zeile“) ist
nicht von einer unvollständigen Auflistung unterscheidbar und sperrt `conf_sync` und `zone_cleanup` dieses Agenten
fail-closed (`zone_sync` scheitert dann ohnehin am bestehenden `named-checkconf`-Preflight); BIND bedient währenddessen
den zuletzt geladenen Stand. Wiederherstellung nach `AGENT_FENCING.md`, Abschnitt 6: benötigte Zone durch erneute
Veröffentlichung (`conf_sync` dieser Zone schreibt die fehlende Datei, die Auflistung ist danach vollständig) bzw. über die
Vorlage, nicht benötigte Zone durch Entfernen der Zeile unter der Agent-Sperre.
Bisher wurde eine solche Zeile beim nächsten `conf_sync` stillschweigend verworfen. Der Agent erzeugt solche Zeilen
nicht selbst (`zone_cleanup` schreibt `zones.conf` vor dem Entfernen der Dateien). Nachweise: Gruppe L (L1–L8;
L1b/L2b mit neutralisiertem Abgleich: der Nachweis greift allein; L7/L8: nicht eingebundene `.conf`-Dateien und nur ein
Durchlauf verkürzt – hier greift allein der Abgleich von S43), Mutationsproben M16–M21. Beide Schichten sind damit
jeweils einzeln nachgewiesen.

**`system/status` (I-A5, Teil AS1)** – zusätzlich `capabilities` (`fencing`, `durable_writes`) und `fencing` (`mode`,
`domain`, `state`, `high`, `fault`): in `observe`/`enforce` immer; in `off` nur, wenn Schreibvorgänge gesperrt sind –
bei vorhandener oder nicht prüfbarer Fehlermarke (`state = fault`) oder fehlender PHP-Erweiterung `posix`
(`state = requirement_missing`); sonst ist die Antwort in `off` unverändert. `state` in dieser Rangfolge:
`requirement_missing` (`high` und `fault` dann `null`), `fault`, `misconfigured`, `uninitialized`, `invalid`,
`domain_mismatch`, `ok`. Zur Anzeige liest `system/status` H, auch in `off`, wenn eine Fehlermarke gemeldet wird; die
schreibenden Endpunkte lesen H in `off` nie. HTTP-Status und BIND-Auswertung unverändert.

**Lokale CLI (E81)** – `scripts/agent_fence-cli.php` (außerhalb des per Apache erreichbaren Agent-Baums), nur als
Eigentümer des Zonenverzeichnisses, unter demselben `flock`: `status`; `init --domain --high` (nur ohne H und ohne
Marke, Domäne = konfigurierte Domäne); `raise --high` (nur N > H, nie senken); `clear-fault --expect-high=N|missing`
(nur bei passendem H: erneuter `fsync` von H und Verzeichnis, Temp-Reste entfernen, Marke entfernen). Betrieb:
[`AGENT_FENCING.md`](AGENT_FENCING.md).

**Timeout (E77, Präzisierung 2; Umsetzung AS2/AS4)** – Eine Zeitüberschreitung (Agent-Aufruf oder L3-Abfrage) ist
immer `unknown`, nie `not_loaded` oder `rejected`. Die Zone bleibt unbestätigt und ausstehend, bis eine tatsächliche
Verifikation vorliegt.

**Grenzen von AS1** – Der Core sendet noch kein Token (AS4); vorher ist `enforce` auf installierten Agenten nicht
einsetzbar (jede Verteilung würde mit `fence_token_missing` abgelehnt), zulässig sind `off` und `observe`. Keine
Serial-Regel und keine L3-Prüfung (AS2), keine Schemaänderung (AS3).

**Stufentest auf dem installierten ns2 (19.11.2026, Code `d7f3e95` bzw. zuvor `48ffd6b`)** – Sicherung unter
`/root/as1-stage-20261119`; Verteilung über den installierten Core auf ns1 (`PublishService::publishZone()`,
`zone.example.test`): (1) `off`: Ziele `ok/ok`, Datei auf ns2 byte-gleich mit `file_hash`, geladene Serial auf ns1 und
ns2 gleich, `system/status` unverändert; (2) `observe` mit eigener Domäne und H = höchste Lauf-ID (CLI `init`):
Verteilung unverändert erfolgreich, Bericht `would_reject`/`fence_token_missing` im Apache-Log (der Core sendet noch
kein Token), Monitoring-Cron `ok`; (3) kurzes `enforce`-Fenster nur mit Ablehnungsproben (ohne Token 400, veraltetes
Token 409 `stale_fence`, fremde Domäne 409): Zonendatei byte-gleich, kein BIND-Reload, H unverändert; (4) `observe` und
`off` auf dem Endstand erneut erfolgreich, H in `off` nachweislich weder gelesen noch geschrieben. Rückbau: Code
`7cdd9a8`, Konfiguration byte-gleich (`0640 root:www-data`), H-Datei entfernt; einzige Abweichung unter `/etc/bind` ist
`db.zone.example.test` mit regulär fortgeschriebener Serial (`2026111904`); alle Zonen DB = Datei = geladen auf ns1 und
ns2.

**Stufentests Review AS1 auf dem installierten ns2 (19.11.2026)** – Verteilung jeweils über den installierten Core auf
ns1 (`zone.example.test`), Rückbau auf `7cdd9a8`. (1) E97, Code `8bfb52b`: `off` mit Fehlermarke ⇒ ns2 lehnt `zone_sync`
und `conf_sync` mit 503 `fence_fault` ab, Datei unverändert, Zone bleibt ausstehend; nach `clear-fault` reguläre
Verteilung. (2) E98-Wiederherstellungsweg, Code `214a0e3`, Befehle unmittelbar aus `AGENT_FENCING.md` Abschnitt 6:
Fall 4b (verwaiste Zeile einer nicht existierenden Zone): ns2 lehnt ab (422 Preflight, 500), alle Zonen weiter mit
unveränderter Serial ausgeliefert, Schritte 2/3 erkennen und klassifizieren die Zeile, Bereinigung nach 4b, danach
reguläre Verteilung; Fall 4a (fehlende `.conf` der benötigten Zone): erste Veröffentlichung teilweise (`zone_sync` 422,
`conf_sync` stellt die Datei wieder her), zweite vollständig; Vorlage erzeugt eine mit dem Generator byte-gleiche Datei.
Die Zone wurde in keinem Schritt entladen. Endzustand: alle Zonen DB = geladen auf ns1 und ns2.

**Tests (AS1)** – `tests/agent/as1_test.py`, Einzelheiten `tests/agent/README.md`: F Funktion, S strace-Reihenfolge,
I Fehlerinjektion je Syscall (strace `inject`), C Absturz vor jedem Schritt plus Stromausfall (dm-flakey,
`noauto_da_alloc,commit=300`: dauerhaft ist nur, was `fsync` festschreibt), G `off` gegen den bisherigen Agenten, L Vollständigkeit der Auflistung, X nicht prüfbare Existenz, fehlendes `ext-posix` und verkürzter Read von H, M Mutationsproben M01–M28; I und C für alle drei
Modi (E97), F13–F15 Fehlermarke, Persistenzfehler und ungültiges H je Modus und Endpunkt. M04 (Verzeichnis-`fsync` nach
dem `rename` von H entfernt) ist auf ext4 äquivalent: Der Verzeichnis-`fsync` nach dem Entfernen der Marke schreibt das
Journal in Reihenfolge fest und macht das `rename` mit dauerhaft; der Schritt bleibt als dateisystemunabhängige
Absicherung.

## 5f. Festlegungen AS2 (AS2a umgesetzt)

**Umfang und Abgrenzung (E111)** – AS2a: RFC-1982-Arithmetik, gemeinsamer DNS-Zustandsbaustein, Konfigurationsnachweis
K, Vorher-Prüfung nach E103, Reparaturkennzeichen, Exit-Codes von `named-checkzone` und `rndc`, Fristen, Wirkungsangabe
`effect` und die Klassifizierung im Core (E113). AS2b (erst nach Abnahme von AS2a): Polling und Nachverifikation nach
`conf_sync`, Nachweis der Entfernung im Cleanup (E92), ausschließlich mit den Bausteinen aus AS2a. E109 (`zone_verify`)
folgt mit AS4, E110 (RFC-1982-Klasse im Core) mit AS5. Keine Schemaänderung (AS3), keine Core-Integration (AS4).

**Verantwortung Core / Agent** – Der Agent liefert technische Fakten und setzt nur definierte Sicherheitsinvarianten
durch: Fencing F1–F5 (AS1), Monotonie I-A1 gegenüber der lokalen Referenz (Datei und ausgelieferte Serial), kein
Überschreiben unbestimmbarer oder unlesbarer Zustände (E80), Dauerhaftigkeit. Der Core entscheidet fachlich: Wahl der
Serial (E78, AS5), Veröffentlichen, Wiederholung, Discard und Restore, Bewertung eines Ergebnisses (E77, AS4) und die
Freigabe einer Reparatur (Admin-Operation, AS4). Jede Ablehnung enthält die Rohwerte (`serial_check`: Serial der neuen
Datei, Dateizustand mit Serial und sha256, Konfigurationszustand mit Grund, ausgelieferter Zustand mit Serial und
Antwortcode, Vergleichsergebnisse); der Core rechnet die Annahmeregel nie nach.

**Modi (E101)** – Die AS2a-Prüfungen und -Angaben gelten nur in `observe` und `enforce`. In `off` sind Antwortformat,
HTTP-Status, Reload-Verhalten, Fehler- und Timeoutpfade bei **ausdrücklich** gesetztem `off` unverändert (E95, E118,
Golden G01–G13): keine Prozessfristen, keine
Zustandsprüfung, keine neuen Felder, `persist_unconfirmed` weiterhin mit `rollback = failed`. In `observe` werden
Serial-Gründe (`stale_serial`, `serial_conflict`, `serial_undefined`) nur gemeldet (`serial_check.decision =
would_reject`, Übernahme erfolgt); Zustandsfehler (`zone_state_unreadable`, `zone_state_invalid` ohne passendes
Kennzeichen, `repair_mismatch`, `served_state_unknown`) werden in beiden Modi abgelehnt. Ohne PHP-Erweiterung `posix`
lehnt `zone_sync` wie bisher mit `agent_requirement_missing` ab, noch vor der ersten Prüfung (E100).

**RFC 1982 (E102)** – `serialParse()`: JSON-Ganzzahl 0 … 4294967295 oder Dezimalzeichenkette ohne Vorzeichen und ohne
führende Null, sonst ungültig. `serialCompare(a, b)` (Lage von a gegenüber b): `equal`; `newer` für (a − b) mod 2³² ∈
[1, 2³¹ − 1]; `older` für [2³¹ + 1, 2³² − 1]; `undefined` für genau 2³¹ – nie als älter oder neuer gewertet; ungültige
Werte ⇒ Exception. Normativ ist zusätzlich die Vektordatei `tests/rfc1982_vectors.json` (`rfc1982-v1`); AS5 (E110) muss
dieselben Vektoren bestehen. Die Serial einer Zonendatei liefert `named-checkzone` mit Exit-Code 0 und genau einer Zeile
`zone <name>/IN: loaded serial <N>` (E94).

**Konfigurationsnachweis K (E115)** – `named-checkconf -l` (Zonenliste `name class view type`) und `named-checkconf -px`
(Struktur; `-x` maskiert Schlüssel, `-p` ohne `-x` wird nie verwendet; Ausgaben werden weder gespeichert noch
protokolliert), beide als www-data mit Exit-Code 0 innerhalb der Frist. K ist für alle Zonen **unbestimmbar**, wenn eine
View außer `_default`, eine `view`-, `dlz`- oder `in-view`-Anweisung, `catalog-zones` oder `allow-new-zones yes`
vorkommt, wenn `directory` nicht eindeutig auflösbar ist, wenn im `directory` oder einem (auch relativen)
`new-zones-directory` eine Datei `*.nzd`, `*.nzf` oder `*.nzd-lock` liegt oder das Verzeichnis nicht lesbar ist
(Messung: eine liegen gebliebene NZD-Datei wird nach erneutem Setzen von `allow-new-zones yes` allein durch einen Reload
wieder aktiv), oder wenn N − A aus `rndc status` (`number of zones: N (A automatic)`; die Hint-Zone zählt mit) nicht
der Zeilenzahl von `-l` entspricht. Die Zonenzahl ist nur ein **schwacher Widerspruchsindikator**: Übereinstimmung ist nie
ein Nachweis (gemessen: Konfiguration ohne Reload geändert, 9 = 9 bei unterschiedlichen Zonen). Je Zone: **K = ja** bei
genau einer Zeile `IN _default master|primary`, genau einer Zonenanweisung nur mit `type` und `file` (Format des
Generators) und einem eindeutig aufgelösten Dateipfad (absolut bzw. relativ zu `directory`; Bestandteile `""`, `.` und
`..` unzulässig), der exakt der verwalteten Datei `zone_data_dir/db.<safeName>` entspricht (zusätzlich `realpath` des
Verzeichnisses). Gemessen: Zeigt `file` auf eine andere Datei mit gleicher Serial und anderem Inhalt, liefert BIND diese
aus – ohne die Pfadprüfung wäre eine reine Serial-Prüfung irreführend. **K = nein**, wenn keine Zeile für die Zone
existiert. Sonst unbestimmbar mit Grund (`zone_type_unsupported`, `zone_options_unsupported`, `zone_file_mismatch`, …).
K beschreibt die Konfiguration auf der Platte; deshalb muss die DNS-Antwort D immer zu K passen.

**Ausgelieferter Zustand D (E105)** – je Zone eine Abfrage `dig +norec +time=1 +tries=1 +nocookie +noall +comments
+answer +authority @127.0.0.1 <zone> SOA`, strenger Parser (genau ein Header mit Opcode QUERY, Flags mit `qr`, QUERY = 1,
Abschnittszahlen gleich den gelesenen Datensätzen, Klasse IN; der Exit-Code von `dig` unterscheidet nur Timeout und
Verbindungsfehler, E91). **served(s)**: NOERROR, `aa`, genau ein Antwortdatensatz SOA mit Owner = Zone. **not_auth** nur
bei K = nein: mit konfigurierter Elternzone P (nächster Vorfahr aus `-l`) muss die Antwort aus P stammen – NXDOMAIN bzw.
NODATA mit `aa` und SOA von P oder ein Referral unterhalb von P; REFUSED ⇒ unknown (Eltern-ACL). Ohne Elternzone: REFUSED
und erfolgreiche Kontrollabfrage (E116). **unknown**: alles andere, insbesondere SERVFAIL (kein Nachweis „nicht
geladen“; `rndc zonestatus` ist ohne sudoers-Erweiterung nicht verfügbar), Timeout, Verbindungsfehler, Parse-Fehler und
jede Antwort, die K widerspricht.

**Kontrollzone (E116)** – Konfiguration `l3_control_zone` (Standard `localhost`). Anforderungen: genau eine Zeile
`IN _default master|primary`, keine eigene `allow-query`/`allow-query-on` (dieselbe globale ACL wie ein nicht
konfigurierter Name; gemessen: eine Zone mit eigener ACL `any` antwortet auch bei globalem Ausschluss von 127.0.0.1), nicht
vom DNS-Manager verwaltet, Antwort NOERROR mit `aa`. `system/status` meldet in `observe`/`enforce`
`verification.control_zone` (`ok`, `missing`, `own_acl`, `managed`, `not_served`, `unverifiable`) und
`verification.config`. Fehlt sie, ist REFUSED ohne Elternzone `unknown` – sichtbar, auch für die erste Zone eines Servers
(Installationsanforderung in README und `AGENT_FENCING.md`).

**Vorher-Prüfung (E103)** – in `zone_sync` unter dem Agent-`flock`, nach dem Fencing und vor jeder Änderung; erste
zutreffende Zeile gilt. N = Serial der neuen Datei; `repair = {expect_file: sha256, expect_served: <serial> | "not_auth"}`
muss exakt passen (Kennzeichen aus einer Admin-Operation des Core, AS4).

| # | Datei | K | D | Aktion | Ablehnung (HTTP) | `repair` |
|---|---|---|---|---|---|---|
| 1 | unlesbar, nicht prüfbar, Symlink, keine reguläre Datei, Prüfung ohne Ergebnis | beliebig | – | – | `zone_state_unreadable` (503) | nein |
| 2 | sonst | unbestimmbar | – | – | `served_state_unknown` (503) | nein |
| 3 | sonst | ja/nein | unknown (auch SERVFAIL) | – | `served_state_unknown` (503) | nein |
| 4 | sonst | nein | served | – (Widerspruch) | `served_state_unknown` (503) | nein |
| 5 | sonst | ja | nicht served | – (Widerspruch) | `served_state_unknown` (503) | nein |
| 6 | fehlt | nein | not_auth | Neuinstallation | – | – |
| 7 | gültig (f) | nein | not_auth | N `newer` f; N = f mit identischen Bytes idempotent | `stale_serial`/`serial_conflict`/`serial_undefined` (409) | nein |
| 8 | ungültig, lesbar (h) | nein | not_auth | nur mit `repair` (h, `not_auth`) | `zone_state_invalid` bzw. `repair_mismatch` (409) | ja |
| 9 | fehlt | ja | served(s) | N `newer` s | wie #7 (`equal` ⇒ `serial_conflict`, keine Datei zum Vergleich) | nein |
| 10 | gültig (f) | ja | served(s) | N `newer` f und s; N = f = s mit identischen Bytes idempotent | wie #7 | nein |
| 11 | ungültig, lesbar (h) | ja | served(s) | nur mit `repair` (h, s) und N `newer` s | wie #8; `stale_serial` | ja |

Ein Reparaturkennzeichen in den Zeilen 6, 7, 9 und 10 wird abgelehnt (`repair_mismatch`); es hebt nie Fencing,
Unlesbarkeit, Unbestimmbarkeit oder die Monotonie gegenüber einer ausgelieferten Serial auf. Idempotent heißt: keine
Übernahme (`effect = unchanged`), Reload wie bisher. Gemessen (Fall B): Nach dem Löschen der Datei liefert BIND die Zone
weiter aus (auch nach einem Reload, „not loaded due to errors“), und eine ältere Datei wird danach ohne Einwand geladen
(„zone serial (50/100) has gone backwards“, DNS liefert Serial und Inhalt der älteren Datei) – deshalb Zeile 9.
**Monotonie ohne lokale Referenz** (Zeilen 6 und 8): Der Agent belegt nur, dass lokal weder Konfiguration noch eine
ausgelieferte Zone existiert, und verhindert über Fencing verspätete Läufe; die Serial einer ungültigen Datei verwendet er
nicht. Die Serial-Verantwortung liegt dann allein beim Core (E78: höher als DB-Serial und alle bestätigten, verifizierten
und gemeldeten Serials unter diesem Zonennamen); ein Fehler des Core ist für den Agenten nicht erkennbar.

**Reload, Wirkung und Rollback (E104)** – `effect` beschreibt ausschließlich die nachgewiesene lokale Datei- bzw.
Konfigurationswirkung: `none` (Bestand unverändert, kein Reload), `unchanged` (Anfrage erfüllt, Bestand unverändert),
`applied`, `partial`, `unknown` (nicht feststellbar, ob eine Änderung eingetreten ist). Ein erfolgreicher Reload (Exit-Code
0, E93) ist kein Nachweis, dass BIND die neue Serial geladen hat. `rollback` bezeichnet nur einen tatsächlich versuchten
Rollback (`ok`/`failed`); `not_attempted` bzw. ein fehlendes Feld: keiner. **Vorwärtsstrategie:** Nach einem
Reload-Versuch gibt es keinen Rollback (ein abgebrochener `rndc` kann den Reload ausgelöst haben; eine zurückgelegte ältere
Datei würde beim nächsten Reload einen Downgrade bewirken). `zone_sync`: Ablehnung bzw. Frist vor dem `rename` ⇒
`effect = none`; gescheiterter Verzeichnis-`fsync` ⇒ 500 `persist_unconfirmed`, `rollback = not_attempted`,
`effect = unknown`; Reload mit Exit-Code ≠ 0 bzw. abgebrochen ⇒ 500 `reload_failed`/`reload_timeout`,
`effect = applied`, `bind_state = unknown`, die Datei bleibt; Erfolg ⇒ 200 mit `reload` (Status, Exit-Code) und
`installed` (Serial, sha256). `conf_sync`: der bestehende Rollback vor dem Reload bleibt (gelungen ⇒ `effect = none`,
gescheitert ⇒ `effect = unknown`); danach wie `zone_sync`. `zone_cleanup`: Ergebnis je Zone (`zones`: `conf`/`file` =
`removed`/`absent`/`kept`/`unknown`) und Vorrangregel für den Gesamtwert: nachgewiesene Änderung (`zones.conf` ersetzt
oder eine Datei entfernt) mit Rest (`kept`/`unknown`) ⇒ `partial`; ohne Rest ⇒ `applied`; keine nachgewiesene Änderung,
aber `unknown` ⇒ `unknown`; sonst `none`; ohne Verwaiste ⇒ `unchanged`. Ein eingetretener Teilzustand ist nie `none`.

**Restore** – Restore ist eine fachliche Operation des Core (E85, Abschnitt 13): Ein historischer Inhalt wird als neue
Revision gespeichert und regulär mit einer neuen, höheren Serial (E78) veröffentlicht. Eine frühere DNS-Serial wird nie
zurückgeschrieben. Für den Agenten ist ein Restore eine gewöhnliche Vorwärtsänderung; der Verzicht auf einen
Agent-Rollback schränkt H5 nicht ein.

**Fristen (E117)** – Arbeitsende TW = Anfragebeginn + 24 s (harte Grenze 25 s, Core-Timeout 30 s). Jeder externe Prozess
läuft ohne Shell in einer eigenen Prozessgruppe (`setsid`) mit min(Obergrenze, TW − jetzt): `named-checkzone` 8 s,
`named-checkconf` 3 s, `rndc status` 3 s, `rndc reload` 5 s, `dig` 1,5 s; reicht die Restzeit nicht, wird er nicht
gestartet. Bei Ablauf SIGTERM an die Gruppe (sudo mit `use_pty` leitet ihn an `rndc` weiter), nach 0,5 s SIGKILL; danach
keine verwaisten Prozesse (gemessen: blockierter Steuerkanal, Abbruch nach etwa 5 s, kein `rndc` und kein `sudo` mehr). Die
Agent-Sperre wird nicht blockierend mit Wiederholung angefordert; **vor jedem Sperrversuch** wird geprüft, ob noch mehr
als die Reserve (8 s) bis TW verbleibt – sonst `lock_timeout` (503, `effect = none`), auch wenn die Sperre frei wäre
(Review AS2a). Nach einer möglicherweise wirksamen Änderung erzeugt eine Frist nie `effect = none` und nie einen
angeblichen Rollback. **`system/status`** führt in `observe`/`enforce` auch `pgrep` (2 s), `rndc status` (3 s) und `dig`
(`+time=1 +tries=1`, 1,5 s) über den Prozess-Runner aus (Review AS2a); ein nicht abgeschlossener Prozess (auch im
Konfigurationsnachweis) ergibt 503 mit `code = status_timeout` und `bind.timeouts`. Die DNS-Testabfrage gilt nur bei
Exit-Code 0 und einer Antwortzeile ohne `;` als erfolgreich (gemessen: `dig +short` schreibt die Timeout-Meldung auf
stdout). Nur der ausdrücklich konfigurierte Modus `off` verwendet den bisherigen Ablauf; `observe`/`enforce` ohne
PHP-Erweiterung `posix` antworten mit 503 `agent_requirement_missing`, ein fehlender oder unbekannter `fencing_mode`
(E118) mit 503 `fence_misconfigured` – jeweils früh und ohne externen Prozess, mit dem `fencing`-Zustand. In
`off` ist `system/status` unverändert (Golden G11, ohne `posix` G12). **Außerhalb des AS2a-Umfangs** bestehen weiterhin
unbeschränkte Prozessaufrufe in `system/control` (`reload-bind`), `zones/conf_check` und `zones/zone_check` sowie im
Modus `off` aller Endpunkte; die DNS-Testabfrage von `system/status` in `off` wertet eine `dig`-Fehlermeldung als
Erfolg. Behebung nur nach gesonderter Entscheidung.

**Klassifizierung (E113)** – `PublishTargetClassifier` ordnet Antworten mit `effect` nach HTTP-Status × Agent-Status ×
`effect` ein: 200/success mit `applied`/`unchanged` ⇒ `ok`; 200/success mit `partial` ⇒ Cleanup `partial`, sonst
`unknown`; 200/success mit `none`/`unknown` ⇒ `unknown` (Widerspruch); Fehler mit `none` ⇒ `rejected`; Fehler mit
`unchanged` ⇒ `unknown`; Fehler mit `applied`/`partial` ⇒ Cleanup `partial`, sonst `unknown`; Fehler mit `unknown` ⇒
`unknown`; unbekannter Wert oder 200/error bzw. ≠ 200/success ⇒ `unknown`. Ohne `effect` (Agenten in `off`) gilt E63
unverändert. Die operative Auswertung (HTTP 200 = Erfolg, `changed`/`bind_dirty`, Meldungen) bleibt unverändert (J8);
auch `ok` ist kein Nachweis des geladenen Stands.

**Tests (AS2a)** – `tests/agent/as2a_pure_test.php` (R RFC-1982-Vektoren, Z `named-checkzone`, P dig-Parser mit
Ausgaben von BIND 9.20, K `-px`-Parser und Pfadauflösung, A Vorrangregel, KL Klassifizierer einschließlich unveränderter
Einordnung ohne `effect`) und `tests/agent/as2a_test.py` gegen die installierte BIND-Instanz (E Matrix nach E103, K
Konfigurationsnachweis, D ausgelieferter Zustand, O `observe`, C Reload/Effekt/Rollback und Cleanup-Teilzustand, T
Fristen, G `off` gegen den Basis-Commit einschließlich Fehler- und Timeoutpfaden, M Mutationsproben, Z Rückbau).
Einzelheiten `tests/agent/README.md`. Die AS1-Regression läuft unverändert; da ihre Datei-Sandbox keinen passenden BIND
hat, ersetzt sie ausschließlich `agentPreTransferGate()` in ihrer Sandbox-Kopie des Agenten (`tests/agent/as2a_as1_regression.sh`);
der Produktionscode enthält keinen Schalter, der die Prüfung abschaltet.

## 5g. Festlegungen AS2b (umgesetzt)

**Umfang (E111)** – Polling und Nachverifikation nach dem Reload in `conf_sync`, Nachweis der Entfernung im Cleanup
(E92), rundenbezogener Speicher der Kontrollzone, zusätzliche Antwortfelder. Ausschließlich mit den AS2a-Bausteinen
(`dnsQuery`/strenger Parser, `dnsConfigProof`/`dnsZoneConfigState`, `dnsServedState`, `dnsZoneFileState`, Prozessfristen);
neuer Baustein `agent/inc/dns_verify.php`. Unverändert: `zone_sync` (E108), `system/status`, Core, `web/`, Schema, Modus
`off` (E101). E109 (`zone_verify`) und E110 (RFC-1982-Klasse im Core) bleiben zurückgestellt.

**Messung M1 (20.11.2026, ns2, installierter `named` 9.20.24)** – 240 Läufe (Hinzufügen/Entfernen ohne und mit
Elternzone, Zone mit 300 000 RR, Reload ohne Änderung; je zwei Messmodi): `rndc reload` kehrt nach 32–40 ms zurück; die
Zonenzahl aus `rndc status` war in allen Läufen bei der ersten Beobachtung korrekt, K nach dem Reload nie unbestimmbar;
kleine Zonen sind in der ersten Runde ausgeliefert bzw. entfernt (D nach ≈ 0,05–0,12 s), eine Zone mit 300 000 RR nach
1,53–1,70 s (davor SERVFAIL bzw. DNS-Timeout); während des Ladens blockiert `rndc status` bis 1,59 s und Abfragen an
unbeteiligte Zonen fielen vereinzelt aus (Befund **B-M1-1**). Keine Rückfälle, Rückbau nachgewiesen. Rohdaten unter
`/root/as2b-m1-20261120-135113` auf ns2.

**Polling und Zeitbudget (E106, E119, E120)** – unter der Agent-Sperre (die Sperre wird erst nach der Nachverifikation
freigegeben). Erste Runde unmittelbar nach dem Reload, danach Wartezeiten 50, 100, 200, 400 ms, dann 500 ms; Fensterende
min(Reload-Ende + 10 s, TW − 1 s); keine Runde beginnt und keine Wartezeit endet nach dem Fensterende. Die Verifikation
läuft mit dieser Abschnittsfrist (`agentWithSectionDeadline()` in `proc.php`): Jeder externe Prozess erhält höchstens
die Restzeit des Abschnitts und wird nicht gestartet, wenn sie nicht für die Mindestlaufzeit reicht; TW bleibt die
absolute Grenze, ohne Abschnitt ist `proc.php` unverändert. **Vorabnachweis im Cleanup** (für E123): nur mit mindestens
20 s Restzeit; er läuft mit der Abschnittsfrist TW − 10 s (Preflight 3 s + Reload 5 s + Antwortreserve 1 s + Nachlauf
eines abgebrochenen Prozesses 1 s), sodass die AS2a-Schritte danach ihr Budget behalten; nach dem Nachweis wird die
Restzeit erneut geprüft (mindestens 9 s, sonst Ergebnis verworfen). Reicht die Zeit nicht: `unknown`/`budget_exhausted`,
kein Ersatznachweis. Mit den heutigen Obergrenzen (9 s für den Nachweis) begrenzt bereits die Eintrittsschwelle; die
Abschnittsfrist ist eine zweite, unabhängige Sicherung (rein getestet, Gruppe S).

**Prüfsequenz und Endergebnis (R1–R4)** – R1: Ein Ergebnis entsteht nur aus einem Tupel derselben Runde (Reload dieses
Aufrufs, K aus einem nach dem Reload erzeugten Nachweis, Datei F einmal unter der Sperre, D nach K dieser Runde). R2: Keine
DNS-Beobachtung und kein Kontrollzonen-Ergebnis wird in eine spätere Runde übernommen; K und F werden nur bei
vorübergehendem Grund erneut ermittelt (`zone_count_mismatch`, `rndc_status_unparsable`, `*_timeout`, `*_not_started`,
`*_error` bzw. abgebrochenes `named-checkzone`). R3: `verified` und `absent` beenden die Prüfung der Zone (Konfiguration
und Datei bleiben unter der Sperre unverändert; `system/control reload` nutzt dieselbe Sperre). R4: `serial_differs` und
`still_served` nur aus der letzten vollständigen Runde; ist sie unbekannt ⇒ `unknown` (`last_round_unknown`); eine
unvollständige Runde (Schritt wegen der Frist nicht gestartet) ersetzt die letzte vollständige nicht; ohne vollständige
Runde ⇒ `unknown` (`budget_exhausted`).

**`conf_sync` (E107, E121)** – Zone = `zone_name` der Anfrage (normalisiert); geprüft wird ausschließlich diese Zone.

| F (verwaltete Datei) | K | D | Ergebnis |
|---|---|---|---|
| Prüfung abgebrochen | – | – | Runde unvollständig |
| fehlt / ungültig / unlesbar | – | – | `unknown` (`file_*`), fest |
| gültig (f) | vorübergehend unbestimmbar | – | weiter |
| gültig | strukturell unbestimmbar / nein | – | `unknown` (`config_*`), fest |
| gültig | ja | served(s), s = f | **`verified`**, fest |
| gültig | ja | served(s), s ≠ f | weiter; letzte Runde ⇒ `serial_differs` |
| gültig | ja | SERVFAIL, Timeout, sonst | weiter; letzte Runde ⇒ `unknown` |

Nach gescheitertem Reload keine DNS-Abfrage (`unknown`, `reload_<status>`, E122). Geprüft wird gegen die verwaltete
Datei, nicht gegen den Publish-Versuch (auch nach abgelehntem `zone_sync`); `file_sha256` erlaubt dem Core ab AS4 die
Zuordnung. Antwortfeld `verification`: `state`, `reason` (ggf. `last_reason`), `zone`, `file_serial`, `file_sha256`,
`served_serial`, `served_rcode`, `served_reason`, `config_state`, `config_reason`, `rounds`, `elapsed_ms`, `window_ms`,
`observations` (vollständige Runden, Anzahl `serial_differs`/`unknown`, zuletzt ausgelieferte Serial). Kein Feld
`cmp`/`serialCompare` (Freigabe AS2b).

**Cleanup (E92, E122–E124)** – Zonennamen nur aus dem Vorabnachweis: Kandidat ist jede Zone mit K = ja, deren
verwaltete Datei genau `<zone_data_dir>/db.<base>` ist; nur bei genau einem Kandidaten (sonst `zone_name_unresolved`
bzw. `zone_name_ambiguous`, keine Ableitung aus dem Dateinamen). Geprüft werden alle Verwaisten (auch mit verbliebener
Datei, da `zones.conf` sie nicht mehr einbindet).

| Reload | K (nach Reload) | D (gleiche Runde) | Ergebnis |
|---|---|---|---|
| ≠ ok | – | autoritative SOA (eine Runde, nur SOA-Abfrage) | `still_served` |
| ≠ ok | – | sonst | `unknown`, nie `absent` |
| ok | nein | not_auth (Elternzone bzw. REFUSED mit Kontrollzone `ok` derselben Runde) | **`absent`**, fest |
| ok | ja / strukturell unbestimmbar | served | weiter; letzte Runde ⇒ `still_served` (R4) |
| ok | ja / strukturell unbestimmbar | sonst | `unknown`, fest (`absent` in diesem Aufruf ausgeschlossen) |
| ok | nein | `control_zone_missing`/`own_acl`/`managed`, `refused_with_parent` | `unknown`, fest |
| ok | nein / vorübergehend | served | weiter; letzte Runde ⇒ `still_served` |
| ok | nein / vorübergehend | sonst | weiter; letzte Runde ⇒ `unknown` |

Fest werden damit nur `absent` (R3) und `unknown`; `still_served` entsteht – außer nach gescheitertem Reload (E122, genau
eine Runde) – ausschließlich aus der letzten vollständigen Runde (R4, Review PR #105, Befund R1).
Zusätzlich je Zone `zones[].name`, `served`, `served_reason`; `verification` mit `rounds`, `elapsed_ms`, `window_ms`,
`control_queries` (tatsächlich gestartete dig-Prozesse der Kontrollabfrage, auch mit Timeout oder fehlerhafter Antwort;
Grundlage ist `dnsQuery()['started']` aus dem Prozessstatus, im Abfragepfad durchgereicht als
`dnsControlZoneState()['queried']`, die übrigen Rückgaben von `dnsControlZoneState()` bleiben unverändert; nicht
gezählt werden `missing`, `own_acl`, `managed` und `unverifiable` ohne Abfrage sowie nicht gestartete bzw. nicht startbare
Prozesse `dig_not_started`/`dig_error` – Review PR #105, Befund R2) und `name_proof` (`state`, `reason`, `ms`,
`remaining_ms`). **Kontrollzonen-Speicher (E124):**
wiederverwendet nur innerhalb derselben Runde, nur für denselben Konfigurationsnachweis (Vergleich in
`dnsServedState()`), nur auf dem Pfad „keine Elternzone, REFUSED ohne `aa`, leere Abschnitte“ und unter der Agent-Sperre;
ohne Speicher (`zone_sync`) unverändert je Aufruf.

**Antwortvertrag (E125)** – nur zusätzliche Felder; HTTP-Status, `status`, `message`, `effect`, `rollback`, `code` und
die Klassifizierung im Core (E113) bleiben unverändert. PHP-Log `[verify]` nur ohne positiven Nachweis.

**Grenzen** – `verified` belegt Serial-, nicht Inhaltsgleichheit (E107). Die Herkunft einer Zonenanweisung ist aus
`named-checkconf -px` keiner Datei zuzuordnen: Steht dieselbe Zone mit der verwalteten Datei auch anderswo in der
Konfiguration, kann `verified` dennoch zutreffen (Fakt über die verwaltete Datei). Zonennamen mit Großbuchstaben oder
abschließendem Punkt sind in AS2a/AS2b nicht verifizierbar (`unknown`; Folgepaket E128). Ende-zu-Ende-Reserve: Der Agent
startet nach TW keinen Prozess mehr; ob Verbindungsaufbau, TLS und Upload zusammen mit 24 s Arbeitszeit sicher unter dem
cURL-Timeout des Core (30 s) bleiben, ist nicht garantiert und wird im Stufentest gemessen (Risiko, keine Änderung).

**Stufentest über den installierten Core (20.11.2026, ns2 auf `ceef51f` in `observe`, Core ns1 auf `6bb229d`)** –
Werkzeug `tests/agent/as2b_stage.php` (PublishService wie die Web-Adapter; Einzelaufrufe mit den cURL-Einstellungen des
Core und Teilzeiten). Direktes `conf_sync`: 218–220 ms gesamt (Verbindung 0,4 ms, TLS ≈ 11 ms), `verified` in Runde 1
(143 ms); bisheriger Agent (`off`): 65–98 ms. Cleanup einer fremden Zone: `absent` in Runde 1, eine Kontrollabfrage,
230 ms. Publish Zone 0,73 s; Publish All mit Full Rebuild (6 Zonen) 4,38 s (bisher in `off` 2,69 s; die Differenz
enthält auch die AS2a-Prüfungen in `observe`). Nahe am Timeout (Agent-Sperre 15,3 s gehalten, DNS blockiert): 22,6 s
gesamt, Fenster auf 7,9 s gekürzt, 7,4 s Reserve zum cURL-Timeout, Einordnung `ok`. Publish All mit blockiertem DNS auf
ns2: 69,4 s (je Zone `zone_sync` fail-closed abgelehnt nach AS2a und `conf_sync` mit 10,16 s Nachverifikation ⇒ alle
Zonen `partial`, `bind_dirty = 1`); ein gleichzeitiger Publish Zone mit 5 s Sperr-Wartezeit (DynDNS-Pfad) endete nach
5,0 s mit `locked`, die Zone blieb ausstehend; der nächste reguläre Lauf (4,39 s) stellte den konsistenten Zustand her.
Rückbau: ns2 auf `6bb229d`, Konfiguration byte-gleich (`0640 root:www-data`, `off`), H entfernt; einzige Abweichung
unter `/etc/bind` sind die regulär fortgeschriebenen Serials (Datenbank = Datei = DNS auf ns1 und ns2).

**Tests (AS2b)** – `tests/agent/as2b_pure_test.php` (B Rundenfolge, W Fensterende, P Polling-Steuerung mit simulierter
Uhr, DL/DR Entscheidungsmatrizen, F R3/R4, N Zonennamen; mit Ersatzprogrammen statt BIND: C Kontrollzonen-Speicher, S
Abschnittsfrist, L Abläufe mit Antwortfolgen, BU Eintrittsschwelle und größter Verbrauch des Vorabnachweises),
`tests/agent/as2b_test.py` gegen die installierte BIND-Instanz (V, N, R, B, M, Z) und `tests/agent/as2b_as1_regression.sh`.
Einzelheiten `tests/agent/README.md`.

## 5h. Festlegungen AS3 (umgesetzt)

**Umfang** – reines Schema-Paket (Migration 006): `zones.soa_serial` → `INT UNSIGNED` (E79) und die Tabelle
`zone_server_verification` (E87), dazu `SchemaCheck`, Neuinstallationsschema, Migrationsskript, Tests und Dokumentation.
Unverändert: `generate_next_serial()` und die Serial-Strategie (AS5), Generator, Publish-Ablauf und -Journal, Historie
(Snapshots enthalten keine Serial, Heads und Revisionen ändern sich durch Serial-Änderungen nicht), Agent,
Core-Verifikation (AS4), Web-Adapter und Oberfläche. Die neue Tabelle wird bis AS4 weder gelesen noch geschrieben.
Grenze bis AS5: Eine Veröffentlichung mit Serial 4294967295 endet mit `error` (Rollback, keine Datei, keine Serial
verbraucht; die nächste Serial 4294967296 weist bereits `named-checkzone` zurück, die Spalte würde sie ebenfalls
ablehnen); bisher galt dasselbe bei 2147483647 (Wertebereich der Spalte).

**Spalte (E79)** – `soa_serial INT UNSIGNED NOT NULL DEFAULT 2025010101`; `SchemaCheck` erwartet
`int(10) unsigned`/`NO`/`2025010101`. PDO liefert Werte über 2³¹−1 unverfälscht (PHP mit 64-Bit-Ganzzahlen).

**Tabelle `zone_server_verification` (E87)** – Primärschlüssel `(zone_id, server_id)`, je Paar höchstens eine Zeile;
Indizes auf `zone_name` und `server_id`. **Keine Fremdschlüssel** (retentionsfest: Zeilen überdauern das Löschen von
Zone oder Server; `zone_name` und `server_name` halten den Namen zum Zeitpunkt der Beobachtung fest). Zwei Teile:

| Teil | Spalten | Bedeutung |
|---|---|---|
| letzte Beobachtung | `last_state` (`verified`, `serial_differs`, `still_served`, `absent`, `unknown`), `last_reason` (Grundcode `[a-z0-9_:]{1,64}` oder NULL), `last_method` (`conf_sync`, `cleanup`, `zone_verify`, `monitoring`), `last_served_serial`, `last_file_serial`, `last_file_hash`, `last_run_id`, `last_attempt_id`, `last_observed_at` | wird bei jeder Beobachtung vollständig ersetzt |
| letzter positiver Nachweis | `verified_serial`, `verified_file_hash`, `verified_at`, `verified_run_id` | nur bei `verified` gesetzt; bleibt bei späteren negativen oder unbestimmten Beobachtungen erhalten |

Serials sind `INT UNSIGNED`, Hashes `CHAR(64)` aus `[0-9a-f]`, Lauf- und Versuchsnummern `BIGINT UNSIGNED` (wie
`publish_runs.id`/`zone_publish_attempts.id`, ohne Fremdschlüssel), Zeitpunkte `DATETIME(6)`.

**CHECK-Bedingungen und NULL-Semantik** – MariaDB wertet eine CHECK-Bedingung mit Ergebnis UNKNOWN (NULL) als erfüllt.
Jede Bedingung ist deshalb so formuliert, dass jeder Zweig mit `IS NULL`/`IS NOT NULL` abgesichert ist und für jede
Belegung TRUE oder FALSE ergibt; Vergleiche stehen nur hinter `IS NOT NULL`-Wächtern.

| CHECK | Regel |
|---|---|
| `chk_zsv_target` | `zone_id ≥ 1`, `server_id ≥ 1`, Namen nicht leer |
| `chk_zsv_reason` | `last_reason` NULL oder Grundcode |
| `chk_zsv_hashes` | beide Hashes NULL oder 64 Kleinbuchstaben-Hex |
| `chk_zsv_last_state` | `verified`: ausgelieferte = Datei-Serial, Dateihash gesetzt; `serial_differs`: beide Serials gesetzt und verschieden; `still_served`: ausgelieferte Serial gesetzt; `absent`: keine ausgelieferte Serial; `unknown`: keine Bedingung |
| `chk_zsv_verified_bundle` | `verified_serial`, `verified_file_hash` und `verified_at` gemeinsam gesetzt oder gemeinsam NULL; `verified_run_id` darf NULL sein (Nachweis ohne Lauf, z. B. künftiges Monitoring), ist ohne Nachweis aber NULL |
| `chk_zsv_verified_order` | `verified_at ≤ last_observed_at` |
| `chk_zsv_verified_current` | bei `last_state = verified` entspricht der positive Nachweis genau der letzten Beobachtung (Serial, Dateihash, Zeitpunkt) |

**Datenvertrag für AS4** – Schreibregeln (Upsert, Reihenfolge, Sperren, Quelle je Methode) legt AS4 fest. Verbindlich
aus AS3: Eine Beobachtung ersetzt nur den Teil „letzte Beobachtung“; der positive Nachweis wird nur durch eine neue
Beobachtung `verified` ersetzt und nie unbeabsichtigt entfernt (ein teilweises Löschen weisen die CHECKs ab). `verified`
belegt Serial-Übereinstimmung, nicht Inhaltsgleichheit (E107). Retention (Meilenstein R) muss die letzten positiven
Nachweise erhalten.

**Migration 006** – zwei atomare Schritte (a: Tabelle, b: Spalte), ausgeführt nur über
`scripts/migrate_006_zone_serial_verification.sh` in genau einer Datenbanksitzung: strikter `sql_mode` und
`lock_wait_timeout = 10` auf der DDL-Verbindung, `GET_LOCK('dnsmanager_publish_all', 0)` mit Besitznachweis vor und nach
jeder DDL, Preflight unter dem Lock, nur fehlende Schritte, Zeilenzahl und Serial-Kennwerte vorher = nachher; keine
Abschaltung von Fremdschlüssel- oder CHECK-Prüfungen. Exitcodes, Teilzustände, Wiederanlauf, Rollout-Voraussetzungen
(ruhiges Zeitfenster; der Publish-Lock schließt andere Fachtransaktionen nicht aus, deren Metadatensperre begrenzt
`lock_wait_timeout`) und der eingeschränkte Rückweg: `sql/migrations/README.md`, Abschnitt 006.

**Tests (AS3)** – `tests/history/migration006_test.sh` (Q01–Q16: fehlende Vorgänger, Grundablauf mit Sitzungsparametern
und Lock auf derselben Verbindung, keine DDL bei vollständigem Schema, beide Teilzustände, abweichende Spalte und
Tabelle, negative Serial, belegter Lock, Lock-Besitz während des Wartens auf die Metadatensperre, Abbruch nach
`lock_wait_timeout` mit Wiederanlauf, Zustandsänderung und Lock-Verlust in Mutationskopien, Neuinstallation = Migration,
frühere Skripte, Rückweg) und `tests/history/as3_test.php` unter RR und RC (C Datenvertrag, S 32-Bit-Serials mit dem
echten Generator, O altes Schema, K alter Code mit neuem Schema, T statische Unverändertheit).

## 5i. Festlegungen AS4a (umgesetzt)

**Umfang:** reines Schema- und Kompatibilitätspaket. Es umfasst Migration 007, die Kompatibilitätsbrücke im
`SchemaCheck`, das Neuinstallationsschema, das Migrationsskript, Tests und Doku. Unverändert bleiben Publish,
Journal-Schreibpfade, Monitoring, Agent, Web-Adapter und Oberfläche. Die neuen Spalten werden erst ab AS4b geschrieben.
Planungsgrundlage und Entscheidungen O1–O10 sowie K1–K3: Freigabe vom 21.11.2026.

**Journal-Ergebnisfelder (J4a, Migration 007a/007b):**
- Je Kanal speichert `zone_publish_targets` das tatsächlich verwendete Protokoll (`zone_protocol`/`conf_protocol`:
  `legacy` ohne `effect`, `as2` mit `effect`), `effect`, Reload-Ergebnis und Fencing-Entscheidung mit Code. Für
  `zone_sync` kommt der installierte Stand hinzu (`zone_installed_serial`/`zone_installed_hash`, siehe unten), für
  `conf_sync` das L3-Ergebnis (`conf_verify_state` verified/serial_differs/unknown, Grund, Datei-Serial, Datei-Hash,
  ausgelieferte Serial).
- `publish_cleanup_results` speichert Protokoll, `effect`, Fencing und **nur Zähler** des Entfernungsnachweises
  (absent, still_served, unknown, nicht eindeutig zuordenbar; Q5).
- Die Felder werden einmal zusammen mit dem Endzustand des Kanals geschrieben, nie danach (J4a).
- `agent_protocol` bleibt unverändert. Es bezeichnet **nur den Registrierungszustand** (J2) und sagt nichts über die
  spätere Agent-Antwort (K3).

**Fencing-Domäne (E74, O2, 007c):** `system_status.fence_domain` (UUID in Kleinbuchstaben, NULL = nicht
initialisiert). AS4b initialisiert sie per Admin-CLI und prüft sie **vor J6/J1** gegen die Web-Konfiguration (K2).

**CHECKs (K1):**
- MariaDB wertet UNKNOWN als erfüllt. Deshalb steht jeder Vergleich einer NULL-fähigen Spalte in seinem AND-Zweig
  hinter `<spalte> IS NOT NULL`, und jede Belegung ergibt TRUE oder FALSE.
- `chk_zpt_zone_agent`/`chk_zpt_conf_agent`/`chk_pcr_agent`: Protokoll nur mit Antwort (`ok`/`rejected`/`unknown`);
  Legacy ohne AS2-Felder; `as2` mit `effect`; ohne Protokoll keine AS2-Felder.
- `chk_zpt_zone_installed` (Review PR #107, R1): Serial und Hash gemeinsam, nur mit nachgewiesener Dateiübernahme
  (`zone_protocol = as2`, `zone_effect` applied/unchanged) und Reload-Bericht des Agenten. Zulässig bei
  `zone_sync = ok` mit `zone_reload = ok` (HTTP 200) und bei `zone_sync = unknown` mit `zone_reload`
  failed/timeout/not_started: `zone_sync` liefert `installed` auch bei gescheitertem Reload (HTTP 500), nach E113
  `unknown`. Der installierte Stand ist ein **technischer Rohwert** der Antwort, nie selbst eine L2- oder
  L3-Bestätigung. Ohne Agentenantwort (Transport-Timeout), bei `effect` none/partial/unknown, bei `rejected`/`failed`
  und bei widersprüchlicher Kombination (z. B. `unknown` mit Reload `ok`) werden keine Werte gespeichert. Die
  operative Einordnung und die Flags bleiben unverändert (E113, J8).
- `chk_*_fence`: Code nur mit Entscheidung.
- `chk_zpt_conf_verify`: `verified` nur mit gleichen Serials, Datei-Hash und `conf_sync = ok`; `serial_differs` mit
  beiden, verschiedenen Serials; ohne Zustand keine Werte.
- `chk_pcr_served`: Zähler gemeinsam und nur bei `as2`.
- `chk_ss_fence_domain`: UUID-Form.
- Negativtests decken widersprüchliche NULL-Kombinationen ab, z. B. `zone_protocol` NULL bei gesetztem `zone_effect`
  (`tests/history/as4a_test.php`, Gruppe C). Zur Gegenprobe akzeptiert die NULL-unsichere Form diese Kombination
  (K06).

**Kompatibilitätsbrücke:**
- `SchemaCheck::HISTORY_TABLES_007` enthält die exakte Signatur der zwei Journaltabellen nach 007.
- `evaluateHistory()` akzeptiert für genau diese Tabellen je Tabelle die bisherige **oder** diese Signatur, auch
  gemischt (Teilzustände). Alle übrigen Tabellen und jede andere Struktur bleiben strikt; `HISTORY_TABLES` und
  `REQUIRED` sind unverändert.
- Die Skripte 002–006 bewerten über denselben `SchemaCheck`.
- Der AS3-Code ist nach 007 nicht mehr zulässig (fail-closed).
- AS4b ersetzt die Einträge durch die 007-Signatur, entfernt die Brücke und nimmt `system_status.fence_domain` in
  `REQUIRED` auf.
- Rollout und Rückfall: `sql/migrations/README.md`, Abschnitt 007.

**Ordnungs- und Schreibvertrag für AS4b** (festgelegt, nicht Teil von AS4a):
- `zone_server_verification` bleibt unverändert (O7).
- `last_run_id` ist „Lauf, auf den sich die Beobachtung bezieht“: für Publish der erzeugende Lauf, für Monitoring der
  zuletzt abgeschlossene Lauf vor Beginn der Beobachtung (Wasserstand).
- Bei gleichem Schlüssel gilt die ausdrückliche Rangfolge (K3):
  - Publish `conf_sync` < Publish `cleanup` desselben Laufs < Monitoring mit gleichem Wasserstand;
  - Monitoring nach Monitoring desselben Wasserstands gilt als später;
  - keine alphabetische und keine ENUM-Reihenfolge, nie die Serial.

**Tests (AS4a):**
- `tests/history/migration007_test.sh` (R01–R14): fehlende Vorgänger, Grundablauf mit Sitzungsparametern, Lock und
  Kennwerten, keine DDL bei vollständigem Schema, **alle Teilzustände** mit nur den fehlenden Schritten, abweichende
  Strukturen (auch die NULL-unsichere CHECK-Form), belegter Lock, Lock-Besitz während des Wartens, Abbruch nach
  `lock_wait_timeout` mit Wiederanlauf, Zustandsänderung und Lock-Verlust in Mutationskopien, Neuinstallation =
  Migration, frühere Skripte vor und nach 007, Notfall-Rückbau.
- `tests/history/as4a_test.php` unter RR und RC: G Brücke, C CHECK-Vertrag, K Code × Schema, V unveränderter
  Journalablauf vor/nach 007, S statische Nachweise.

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

**Audit-Ereignisse (H2c, E47–E53):**

| ID | Regel |
|---|---|
| R-A1 | Audit-Ereignisse entstehen in derselben Transaktion wie die Fachänderung (Rollback ⇒ kein Ereignis; Fehler der Erfassung ⇒ Rollback der Fachänderung). |
| R-A2 | Nur committete Änderungen; Ablehnungen, `unchanged` und Fehler erzeugen keine Datenbankeinträge (Syslog wie bisher). |
| R-A3 | Inhalte ausschließlich über die Feld-Whitelist der `AuditPolicy` (Typprüfung, deterministisch, Längengrenzen); Abweichung ⇒ Rollback. |
| R-A4 | Keine Passwörter, Passwort-Hashes, Tokens, DynDNS-Kontonamen, Client-IPs oder Agent-Antworttexte; Geheimnisse nur als Kennzeichen; Freitext gekürzt bzw. geschwärzt. |
| R-A5 | Vorher-Werte nur aus in der Transaktion gesperrten Zeilen. |
| R-A6 | Je Fachtransaktion genau eine Änderungsmenge des Akteurs für Revisionen und Ereignisse; `seq` lückenlos 1..n. |
| R-A7 | Nicht transaktionale Vorgänge (BIND-Reload) danach in eigener kurzer Transaktion; Ergebnis des Vorgangs unverändert. |
| R-A8 | Lock-Reihenfolge wie R-H9, ergänzt um: … → Historien-INSERTs → `audit_events`-INSERTs → Commit. Bis `finish()` nur sperrendes Lesen des Akteursnamens (kein Read-View). |

**Präzisierung H2b (E34, E35):** R-H2 gilt für **vorhandene** Heads: Sie werden nach **allen** Zonensperren der
Transaktion mit `FOR UPDATE` (Satzsperre) gesperrt, und bis zum Ende von `beginZones()` findet kein nicht sperrendes
Lesen statt. Ein **fehlender** Head wird nicht in der Fachtransaktion gesperrt oder angelegt, sondern vorher in eigener
Transaktion ohne Gap-Sperre (Lazy-Baseline); fehlt er dennoch in der Fachtransaktion, wird sie zurückgerollt. Die
Zonenanlage legt ihren Head ohne vorheriges Lesen an. R-H9 lautet damit: … → zones (X, alle, aufsteigend) →
zone_history_state (X, vorhandene, aufsteigend) → Vorher-Snapshot (S) → Fachtabellen → Historien-INSERTs. Einzelheiten
und Nachweise: Abschnitt 5b.

## 7. Audit-Ereignisse

- **Umgesetzt mit H2c (Abschnitt 5c, R-A1–R-A8).** Discard und Restore folgen mit H4/H5.
- Getrennt von Revisionen; beide verweisen auf dieselbe Änderungsmenge. Eine Operation kann Audit-Ereignis und
  Revision erzeugen.
- Ursprünglich vorgesehener Umfang (in H2c festgelegt, siehe 5c; Publish gehört zum Journal H2d): Zonen-Metadaten (Beschreibung, `allow_dyndns` – setzen heute
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

**Topologie:** Aktivierung setzt alle Zonen des Servers auf `unknown` (zusätzlich zum bestehenden `bind_dirty = 1`) –
abgeleitet über das Audit-Ereignis der Reaktivierung und den Wasserstand der Läufe, ohne Journal-Schreibzugriff aus der
Fachtransaktion (E70, Abschnitt 5d); Zuweisungsänderungen sind Inhaltsänderungen (neue Revision); Deaktivierung entfernt den
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
  **[offen – E15/E16, Meilenstein AS]**: Umstellung von `generate_next_serial()` (heute numerischer Vergleich; AS5).
  Der Spaltentyp `zones.soa_serial` ist seit AS3 `INT UNSIGNED` (E79, Abschnitt 5h).
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
| F1 | **Ein** Fencing-Höchststand H je Agent für alle schreibenden Endpunkte: `zone_sync`, `conf_sync`, `zone_cleanup` (`system/control reload` ohne Fencing, E75). |
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

**Umsetzung:** AS2a (Serial-Regel I-A1, Zustandsprüfung, Fristen, `effect`) siehe Abschnitt 5f; AS2b (L3 nach dem Reload: Nachverifikation und Nachweis der Entfernung im Agenten, E17/E76) siehe Abschnitt 5g. AS1 (Agent) siehe Abschnitt 5e und [`AGENT_FENCING.md`](AGENT_FENCING.md); Präzisierungen der Freigabe:
Write-ahead-Fehlermarke (E90), Timeout ⇒ `unknown` (E77), Domänenprüfung im Agenten mit dokumentierter Schutzgrenze
gegenüber vollständigen Installationsklonen (E74).

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
4. Fehlt ein Head (z. B. Code ohne Initialisierung), legt der Recorder lazy eine Baseline `baseline_unverified` an
   (H2b: vor der Fachtransaktion, E35). Lief nach der Migration wieder alter Code, erkennt die Vorher-Prüfung (R-H3)
   die ungetrackten Änderungen; Heads zwischenzeitlich gelöschter Zonen kennzeichnet `--reconcile` (E36).

## 16. Berechtigung und Datenschutz

- Lesen über `ZoneAccess` in derselben SQL-Anweisung; Nichtvorhandensein und fehlende Berechtigung identisch.
- **E10:** zoneadmins sehen Akteursnamen für Änderungen innerhalb ihrer berechtigten Zonen, keine Informationen aus
  fremden Zonen (auch nicht über zonenübergreifende Änderungsmengen oder Abhängigkeiten). Historie gelöschter Zonen nur
  für admin.
- Keine Passworthashes, Agent-Token oder DynDNS-Benutzernamen (nur Konto-ID) in Snapshots, Audit oder Journal; keine
  Client-IP; Agent-Fehlertexte gekürzt und bereinigt. Suchtest auf Hash-/Token-Muster ist Abnahmebestandteil.
- **Leserechte der Audit-Ereignisse (Vorgabe für H3, Q7):** Ereignisse der Typen `server`, `user` und `dyndns_account`
  nur für admin; zoneadmin sieht nur `zone`-Ereignisse seiner berechtigten Zonen (Prüfung über `ZoneAccess` in derselben
  Anweisung, E10), nie Ereignisse anderer Typen derselben Änderungsmenge. Ereignisse gelöschter Zonen nur für admin.
  H2c enthält keinen Lesepfad.

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
| **H2b** | `ZoneHistoryRecorder` in allen Schreibpfaden, Drift, Zonenlöschung, `history_dependencies` – **umgesetzt** (Abschnitt 5b, Migration 003, Tests `tests/history`) | R-H1–R-H9 nachgewiesen; Rollback ohne Rückstände; REPEATABLE READ (mit Snapshot-Isolation) und READ COMMITTED; Web-Golden identisch |
| **H2c** | `audit_events` für Operationen ohne DNS-Wirkung – **umgesetzt** (Abschnitt 5c, Migration 004, Tests `tests/history`) | keine Geheimnisse (Suchtest); atomar mit Fachänderung; RR und RC; Web-Golden identisch |
| **H2d** | Publish-Journal J1–J8, Hashes, strukturierte Zielergebnisse, `unknown` bei Reaktivierung – **umgesetzt** (Abschnitt 5d, Migration 005, Tests `tests/history`) | Fehlerinjektion (Registrierung, Ergebniseintrag, Prozessende); vollständige Publish-Regression auf ns1/ns2 (ok, partial, Abbruch, Cleanup-Fehler, Full Rebuild); `changed`/`bind_dirty` unverändert |
| **AS** | Fencing F1–F5, I-A1–I-A5, L3 nach E17, Serial-Wahl (E15/E16); Arbeitspakete AS0 (Vorprüfung, geprüft), AS1 (Agent-Fencing, umgesetzt, Abschnitt 5e), AS2 (Serial-Regel, L3), AS3 (Migration 006, umgesetzt, Abschnitt 5h), AS4 (Core), AS5 (Serial-Strategie), AS6 (Einführung), AS7 (Doku) | Lock-Verlust mit zwei Verbindungen; veraltete Konfiguration/Cleanup; Neuanlage gleichen Namens; Agent-Neustart; Absturzsicherheit von H (F4) und fail-closed bei Persistenzfehlern (Fehlerinjektion je Datei- und Verzeichnisoperation); gestufte Einführung |
| **H3** | Historienoberfläche: Core-Read-Services (`ZoneHistoryReadService`, `ZoneSyncReadService`) **und** Weboberfläche mit Historienliste, Filtern, Detailansichten, Vorher-/Nachher-Vergleich, Berechtigungen und Paginierung | Berechtigung in derselben Anweisung, E10, Pagination, keine Geheimnisse; Oberfläche nach `docs/UI_GUIDELINES.md` und `docs/UI_ROLE_MATRIX.md`, Web-Golden für bestehende Seiten unverändert |
| **H4** | abgeleitete Zustände, `ZoneDiscardService` | alle Sperrgründe; Race-Tests (Lock-Wait, Gegenprobe, Mutationsprobe); Discard nur aus `pending_clean` |
| **H4b** | Gruppen-Discard | Abhängigkeitshülle, Vorschau, Berechtigung für alle Zonen |
| **H5** | `ZoneRestoreService`, Wiederherstellen und neu verteilen | Serial monoton; DynDNS-/Systemrecords geschützt; Revalidierung; Rollback |

| **R** | Retention (eigener Meilenstein nach H5, nicht Teil von AS): drei unabhängig konfigurierbare Fristen für DNS-Revisionen mit Snapshots, Audit-Historie und Publish-Journal; Konfiguration und administrative Bereinigung ausschließlich für Admins über einen Core-Service | erhalten bleiben aktuelle Heads und nötige Snapshots, die letzten verifizierten Agent-Zustände, alle für die Serial-Strategie (E78) nötigen Höchststände und die monotone Fencing-Token-Folge (`publish_runs` nie per `TRUNCATE`); Web, MCP und CLI nur als Adapter |

Reihenfolge: H2a → H2b → H2c (parallel zu H2b möglich) → H2d → AS → H3 → H4 → H4b → H5 → R.

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
| E4 | Berechtigung Discard/Restore | entschieden 19.11.2026: Admin alle Zonen; zoneadmin Discard und normales Restore nur bei Schreibrecht; Gruppen-Discard nur bei Schreibrecht für alle Zonen; Wiederherstellen und neu verteilen bei `partial`/`unknown` nur Admin; Prüfung im Core (Präzisierung E85) |
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
| E15/E16 | Serial-Strategie und Migration der Spalte `zones.soa_serial` | entschieden 19.11.2026 als E78/E79 (Umsetzung AS5/AS3) |
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
| E29 | Recorder in zwei Phasen: `beginZones()` nur sperrend lesend, `finish()` schreibt unmittelbar vor dem Commit; `close()` im `finally` | freigegeben (H2b) |
| E30 | `HistoryContext` (Akteur, Kanal, Operation, Korrelation); Label aus der gesperrten `users`-Zeile; Services erzeugen Kontext und Recorder, Konstruktoren unverändert | freigegeben (H2b) |
| E31 | Änderungsmengen erst mit der ersten Revision; Akteur und `history.drift` je Transaktion, gleiche Korrelation | freigegeben (H2b) |
| E32 | Revisionsregeln `create`/`change`/`delete`/`drift`, `diff_summary`, `tracking` unverändert | freigegeben (H2b) |
| E33 | `ZoneSnapshotStore`: nicht sperrende Existenzprüfung; Duplicate-Key-Pfad mit Payload-Vergleich, nach 1020 fail-closed ohne Weiterarbeit | freigegeben (H2b, mit Präzisierung) |
| E34 | Sperr- und Lesereihenfolge (Abschnitt 5b); Zonenliste der Serveränderung vor der Transaktion | freigegeben (H2b) |
| E35 | Lazy-Baseline vor der Fachtransaktion ohne Gap-Sperre; fehlender Head in der Fachtransaktion ⇒ Rollback; Präzisierung R-H2 | freigegeben (H2b, mit Präzisierung) |
| E36 | Abgleich gelöschter Zonen per CLI (`--reconcile`) | freigegeben (H2b) |
| E37 | Migration 003 `history_dependencies` | freigegeben (H2b) |
| E38 | AutoPTR: eigene Änderungsmenge mit Korrelation; Abhängigkeit nur bei `created` | freigegeben (H2b) |
| E39 | Wächter im `ZoneBuildBackend`, Bindung an Verbindung und Transaktion per Savepoint, explizite Freigabe; statische Prüfung | freigegeben (H2b, mit Präzisierung) |
| E40 | Fehler der Historie als bisherige Fehlercodes, keine neuen Web-Verträge | freigegeben (H2b) |
| E41 | Web-Golden mit Reset der History-Tabellen; History-Erwartungen in der Pfadsuite | freigegeben (H2b) |
| E42 | Tests in isolierten Datenbanken; ns1 mit festgelegtem Rückbau, ohne Publish All; Historie append-only (E8); ns2 unverändert | freigegeben (H2b, mit Präzisierung) |
| E43 | Audit-Umfang: Zonenmetadaten, Server (inkl. Reload), DynDNS-Konten, Benutzer und Zuweisungen; nicht: Records, DynDNS-API, Publish, Login, Monitoring | freigegeben (H2c) |
| E44 | Abgrenzung Revision (DNS-Inhalt `zs1`) und Audit (Verwaltungsfelder); kein doppelter DNS-Inhalt | freigegeben (H2c) |
| E45 | Tabelle `audit_events` mit NULL-sicheren CHECKs, `seq` NOT NULL und UNIQUE je Änderungsmenge | freigegeben (H2c, mit Präzisierung) |
| E46 | Migration 004 mit Skript, `SchemaCheck` fail-closed | freigegeben (H2c) |
| E47 | `AuditRecorder`: Name beim Öffnen, Pufferung, `finish()` vor Commit, Savepoint-Bindung bzw. die des `ZoneHistoryRecorder` | freigegeben (H2c, mit Präzisierung) |
| E48 | `AuditPolicy`: Whitelist, Typprüfung, Kennzeichen, Längengrenzen, Schwärzung von Freitext; Infrastruktur-IPs zulässig (Q1) | freigegeben (H2c, mit Präzisierung) |
| E49 | Gemeinsame Änderungsmenge bei Mischoperationen, genau eine neue bei reinen Verwaltungsänderungen | freigegeben (H2c, mit Präzisierung) |
| E50 | Vorher-Werte und DNS-Relevanz von Serveränderungen aus gesperrtem Stand; kontrollierter Abbruch `topology_locked` | freigegeben (H2c, mit Präzisierung) |
| E51 | BIND-Reload: Nachtrag in eigener Transaktion, Ergebnis unverändert, Kennzeichen `audit = failed` und Protokollzeile (Q2 b) | freigegeben (H2c) |
| E52 | Audit-Fehler als bisherige Fehlercodes, keine neuen Web-Verträge | freigegeben (H2c) |
| E53 | Kaskadenzähler der Zonenlöschung (sperrend gezählt) | freigegeben (H2c, Q3) |
| E54 | Invarianten R-A1–R-A8; Tests RR/RC, Fehlerinjektion, Mutationsproben, Web-Golden; ns1 ohne echten BIND-Reload als Pflicht (Q6) | freigegeben (H2c) |
| E55 | Migration 005 nur mit den vier Journaltabellen; keine Agent-Verifikationsfelder vor AS (Q6) | freigegeben (H2d) |
| E56 | Tabellen des Journals mit NULL-sicheren CHECKs, Revisionsbezug per FK auf `zone_revisions`, Akteurskontext (Q2) | freigegeben (H2d) |
| E57 | `PublishLedger` mit Autocommit-Rolle und genau einer transaktionalen Methode (`registerAttempt`, Lauf-Token, Sperrennachweis) | freigegeben (H2d) |
| E58 | J6 und J1 unter dem Lock vor jedem Seiteneffekt; Fehler als bestehender Status `exception` (Q7) | freigegeben (H2d) |
| E59 | Versuch und Zielzeilen in der Generator-Transaktion vor dem Serial-Commit | freigegeben (H2d) |
| E60 | Hash-Verträge zs1/rh1/file/conf; `zone_file_render()` als byte-identische Extraktion | freigegeben (H2d) |
| E61 | Revisionsbezug nur bei identischem Head-Inhalt (head/head_mismatch/no_head) | freigegeben (H2d) |
| E62 | Verteilung an die registrierte Zielmenge, Datei nur mit registriertem `file_hash` | freigegeben (H2d) |
| E63 | Einordnung ok/rejected/failed/unknown (Cleanup partial), Timeout ⇒ unknown (Q3); operative Auswertung unverändert | freigegeben (H2d) |
| E64 | J4 je Antwort; Schreibfehler nur im Bericht und als Logzeile (Q4) | freigegeben (H2d) |
| E65 | Versuchsergebnis = operativer Ausgang, keine L2/L3-Bestätigung; vor AS nur `legacy` | freigegeben (H2d, Präzisierung 4) |
| E66 | Cleanup-Vorregistrierung; Fehler ⇒ kein Cleanup (bestehender Wiederholungsweg); nur Zähler (Q5) | freigegeben (H2d) |
| E67 | J4a über bedingte Übergänge, statisch und dynamisch nachgewiesen; kein Trigger (Q8) | freigegeben (H2d) |
| E68 | Lese-Regel J5: nicht nachweisbar lebendig ⇒ unknown | freigegeben (H2d, Präzisierung 3) |
| E69 | J6 in eigener kurzer Transaktion, nie für einen Lauf mit gehaltener Laufsperre | freigegeben (H2d) |
| E70 | Reaktivierung über Audit-Ereignis und Wasserstand statt Journal-Schreibzugriff aus der Fachtransaktion | freigegeben (H2d) |
| E71 | Lauf-ID als künftiger Fencing-Token, keine AS-Vorwegnahme | freigegeben (H2d) |
| E72 | Web-Verträge unverändert; Abweichungen nur unter Fehlerinjektion | freigegeben (H2d) |
| E73 | Laufeigene Advisory-Sperre als Identitätsnachweis (Neustart, Verbindungs-ID-Wiederverwendung); Testumgebung isoliert (Q1) | umgesetzt (H2d, Präzisierungen 1 und 3) – zur Bestätigung im Review |
| E74 | Eigene Fencing-Domäne je Installation; der Agent prüft Anfrage- und gespeicherte Domäne gegen seine lokal konfigurierte Domäne; Core-Prüfung DB ↔ Web-Konfiguration zusätzlich (AS4); Schutzgrenze: vollständiger Installationsklon nicht erkennbar (Abschnitt 5e) | entschieden; Agent-Teil umgesetzt (AS1) |
| E75 | `system/control reload` ohne Fencing, unter demselben `flock` | entschieden (AS1) |
| E76 | L3 im Agenten nach dem Reload mit begrenztem Polling, zusätzlich periodisch im Monitoring | entschieden; Polling umgesetzt (AS2b: nur `conf_sync` und `zone_cleanup`, Fenster 10 s, gemeinsames Budget je Cleanup), Monitoring in AS4 |
| E77 | Reload-Fehler oder `not_loaded` ⇒ Ziel nicht veröffentlicht, Zone bleibt ausstehend; **Timeout ⇒ immer `unknown`** (nie `not_loaded`/`rejected`), Zone unbestätigt und ausstehend bis zur tatsächlichen Verifikation | entschieden, Umsetzung AS2/AS4 |
| E78 | Serial-Wahl nach RFC 1982 vollständig (Abstand 2³¹ undefiniert ⇒ nicht größer), Referenzmenge aus DB-Serial, bestätigten, zuletzt verifizierten und gemeldeten Serials je Zone und Server; keine numerische Sortierung; sonst Sperre `serial_unresolvable` | entschieden (= E15), Umsetzung AS5 |
| E79 | `zones.soa_serial` → `INT UNSIGNED` | umgesetzt (AS3, Abschnitt 5h) |
| E80 | Agent: fehlende Zonendatei ⇒ Installation unter gültigem Fencing; lesbar, aber ungültig ⇒ kontrollierter Reparaturpfad (nur mit ausdrücklichem Kennzeichen aus einer Admin-Core-Operation); nicht lesbar ⇒ fail-closed; kein Überschreiben unbekannter Zustände | umgesetzt (AS2a, präzisiert durch E103: fehlende Datei nur bei nachweislich nicht konfigurierter und nicht ausgelieferter Zone bzw. gegenüber der ausgelieferten Serial) |
| E81 | Agent senkt H nie; kein API-Reset; lokale CLI nur `init` (ohne H) und `raise`; nach DB-Restore kontrollierter Abgleich durch den Core (AS4) | entschieden; CLI umgesetzt (AS1) |
| E82 | Modi `off` → `observe` → `enforce`, unbekannter Wert fail-closed; Einführung zuerst auf ns2. **Geändert durch E118:** `off` nur noch ausdrücklich, kein Standardwert | entschieden; umgesetzt (AS1); geändert (AS2a, E118) |
| E83 | Core prüft vor jedem Agentaufruf Publish-Lock und Laufsperre, sonst Abbruch | entschieden, Umsetzung AS4 |
| E84 | `fsync` auch für Zonen- und Konfigurationsdateien | entschieden; umgesetzt (AS1) |
| E85 | Discard unveröffentlichter Änderungen (Admin; zoneadmin nur berechtigte Zonen; ohne L3, sofern seit P kein möglicherweise verteilter Versuch existiert) und Restore veröffentlichter oder möglicherweise verteilter Revisionen (Admin alle, zoneadmin berechtigte Zonen; `partial`/`unknown` nur Admin) sind getrennte Core-Vorgänge; Restore veröffentlicht nie selbst und löst nie Publish All aus (ersetzt die Empfehlung zu E4) | entschieden, Umsetzung H4/H5 |
| E86 | Absturztests per dm-flakey | entschieden; umgesetzt (AS1) |
| E87 | `zone_server_verification` als retentionsfeste Tabelle des letzten Stands | umgesetzt (AS3, Abschnitt 5h; Schreibregeln AS4b, Ordnungsvertrag Abschnitt 5i) |
| E88 | AS-PRs nacheinander | entschieden |
| E89 | Verzeichnis-`fsync` nativ in PHP (`fopen($dir, 'r')`), kein `sync`-Ersatz | entschieden (AS0); umgesetzt (AS1) |
| E90 | Write-ahead-Fehlermarke: dauerhaft vor jeder Änderung von H, Entfernen erst nach vollständiger Persistenz; Aufheben nur lokal per CLI | entschieden (Präzisierung AS1); umgesetzt |
| E91 | L3-Auswertung über Status, `aa` und Owner, nie über den Exit-Code von `dig` | umgesetzt (AS2a, E105) |
| E92 | Nachweis der Entfernung (AS2b): `still_served` ⇔ autoritative SOA der Zone; `absent` ⇔ Reload im selben Aufruf mit Exit-Code 0, K = nein (E115) und D = not_auth (E105: Antwort aus der konfigurierten Elternzone bzw. REFUSED mit erfolgreicher Kontrollabfrage, E116); sonst `unknown`. REFUSED allein ist kein Nachweis (gemessen: `allow-query` einer geladenen Zone liefert REFUSED wie eine entfernte) | umgesetzt (AS2b, Abschnitt 5g) |
| E93 | Globales `rndc reload`; dessen Exit-Code nur für Befehlskanal und Konfiguration | entschieden (AS0) |
| E94 | `named-checkzone` mit Exit-Code; Existenz und Lesbarkeit vorher in PHP | umgesetzt (AS2a, `observe`/`enforce`; `off` unverändert) |
| E95 | `fsync` in allen Modi. Ausdrücklich `off` (seit E118; vorher auch ein fehlender Schlüssel) ist im Normalbetrieb und in allen bisherigen Fehlerpfaden beobachtbar identisch zum bisherigen Agenten (Golden G01); abweichend ausschließlich in Fehler- bzw. Inkonsistenzfällen, und zwar abschließend: gescheitertes `fsync` (E95), Lesefehler des Konfigurationsverzeichnisses (E96), vorhandene oder nicht prüfbare Fehlermarke (E97, E99), unvollständige Auflistung, verkürzt gelesene `zones.conf` und verwaiste Include-Zeile (E98), nicht prüfbare Existenz einer Sicherungs- oder Bestandsdatei (E99), fehlende PHP-Erweiterung `posix` (E100) | umgesetzt (AS1) – akzeptiert |
| E96 | Befund S43: `listConfFiles()` (glob-Liste, Lesefehlererkennung über `scandir()` und Abgleich) in `conf_sync` und `zone_cleanup`; Lesefehler des Konfigurationsverzeichnisses führen in die bestehenden Fehlerpfade statt zu einer leeren Liste (Verhaltensänderung nur im Fehlerfall); ergänzt durch E98 (gleichartige Verkürzung beider Durchläufe), einzeln nachgewiesen durch L7/L8 | umgesetzt (AS1) – akzeptiert |
| E97 | Klarstellung E90/E95 (Review AS1): Modi steuern nur die Fencing-Prüfung; Fehlermarke sperrt alle drei schreibenden Endpunkte in jedem Modus (auch `off`); Integritätsfehler von H (unlesbar/ungültig/nicht prüfbar, Persistenzfehler) brechen auch in `observe` ab; in `off` lesen und schreiben die schreibenden Endpunkte H nicht, geprüft wird nur die Fehlermarke (`system/status` liest H in `off` nur zur Anzeige bei gemeldeter Fehlermarke); `fsync`-Fehler modusunabhängig; `system/status` meldet die Marke auch in `off` | umgesetzt (AS1, Nachtrag) |
| E98 | Vollständigkeit der Auflistung (Review AS1, S43-Ergänzung): bisherige `zones.conf` nur vollständig gelesen (Länge = `fstat`), jedes bisherige Include muss erhalten bleiben (Cleanup: außer selbst erkannten Verwaisten), sonst `listing_incomplete` ohne Änderung und Reload. Verwaiste Include-Zeilen sperren `conf_sync` und `zone_cleanup` fail-closed (`zone_sync` scheitert dann bereits am bestehenden `named-checkconf`-Preflight), bis sie nach `AGENT_FENCING.md` Abschnitt 6 bereinigt sind: benötigte Zone durch erneute Veröffentlichung (Selbstheilung über `conf_sync` dieser Zone) bzw. Vorlage, nicht benötigte Zone durch Entfernen der Zeile unter der Agent-Sperre; BIND bedient währenddessen den zuletzt geladenen Stand | umgesetzt (AS1, Nachtrag) – akzeptiert |
| E99 | Nicht prüfbare Existenz (Review AS1, Nachtrag): `file_exists()` liefert bei jedem `stat`-Fehler „nicht vorhanden“ (nachgewiesen). `pathState()` unterscheidet über `posix_access()` und `errno` vorhanden / nicht vorhanden (nur ENOENT) / nicht prüfbar; „nicht prüfbar“ gilt fail-closed: Fehlermarke ⇒ vorhanden (alle Modi), H ⇒ `fence_invalid`, Sicherung von `<zone>.conf`/`zones.conf` ⇒ Abbruch ohne Änderung, Rollback und Entfernen verwaister Dateien ⇒ nie als entfernt gewertet; ohne `posix` kein Rückfall (E100) | umgesetzt (AS1, Nachtrag) – akzeptiert |
| E100 | `ext-posix` ist verbindliche Agent-Voraussetzung (Abnahme AS1): ohne sie lehnt `agentFenceCheck()` in jedem Modus jeden schreibenden Aufruf mit `agent_requirement_missing` (503) ab, `pathState()` liefert „nicht prüfbar“ (kein Rückfall auf `file_exists()`), `system/status` meldet `requirement_missing` auch in `off`, die CLI verweigert; README und `AGENT_FENCING.md` nennen die Voraussetzung | umgesetzt (AS1, Abnahme) |
| E101 | Modi: AS2-Prüfungen und -Angaben nur in `observe`/`enforce`; `off` unverändert (E95); in `observe` Serial-Gründe `would_reject`, Zustandsfehler abgelehnt | umgesetzt (AS2a) |
| E102 | RFC 1982 mit vier Ergebnissen `newer`/`older`/`equal`/`undefined` (Abstand 2³¹ nie älter oder neuer), strenges Parsen, Vektordatei `tests/rfc1982_vectors.json` als normative Ergänzung | umgesetzt (AS2a) |
| E103 | Vorher-Prüfung in `zone_sync` nach der Matrix in Abschnitt 5f (Datei × K × D), Reparaturkennzeichen nur für ungültige lesbare Dateien (Zeilen 8, 11), nie über SERVFAIL | umgesetzt (AS2a) |
| E104 | `effect` = nachgewiesene lokale Wirkung; `rollback` nur bei versuchtem Rollback; Vorwärtsstrategie ohne Rollback nach einem Reload-Versuch; Cleanup-Ergebnis je Zone mit Vorrangregel | umgesetzt (AS2a) |
| E105 | Ausgelieferter Zustand über `dig` mit strengem Parser; served / not_auth (Elternzone bzw. REFUSED mit Kontrollabfrage) / unknown (SERVFAIL, Timeout, Widerspruch zu K) | umgesetzt (AS2a) |
| E106 | Polling nur in `conf_sync` (10 s) und `zone_cleanup` (10 s gemeinsam), nacheinander, höchstens eine Kontrollabfrage je Runde | umgesetzt (AS2b) |
| E107 | `verified` = autoritative SOA mit der Serial der verwalteten Datei bei K = ja; belegt Serial-Übereinstimmung, nicht Inhaltsgleichheit; ändert nie den HTTP-Status | umgesetzt (AS2b) |
| E108 | `zone_sync` ohne Polling; Nachverifikation einer neuen Zone über `conf_sync` | umgesetzt (AS2b) |
| E109 | Lesender Endpunkt `zone_verify` | verschoben nach AS4 |
| E110 | RFC-1982-Klasse im Core | verschoben nach AS5 (muss die Vektoren aus E102 bestehen) |
| E111 | Aufteilung AS2a (Grundlage, Schutz) / AS2b (Polling, Nachverifikation, E92); AS2b nur mit AS2a-Bausteinen | entschieden |
| E112 | Tests gegen die installierten BIND-Instanzen und Agenten auf ns1/ns2; keine zusätzliche BIND-Testumgebung; Fixtures ohne eigenen DNS-Server zulässig (`CLAUDE.md` §5) | entschieden |
| E113 | Klassifizierung nach HTTP × Agent-Status × `effect` (Tabelle Abschnitt 5f); ohne `effect` unverändert E63; operative Auswertung unverändert | umgesetzt (AS2a) |
| E114 | Fehlerinjektion mit dem Agent-Code des Commits (`php -S`, `strace`) gegen das echte `/etc/bind` und den installierten `named` | umgesetzt (AS2a-Tests) |
| E115 | Konfigurationsnachweis K über `named-checkconf -l` und `-px` (nie `-p` ohne `-x`); nicht unterstützte Kontexte (Views, `allow-new-zones`, NZD/NZF in `directory` und `new-zones-directory`, Catalog-Zonen, DLZ, `in-view`) und abweichende Zonenzahl ⇒ unbestimmbar; Zonenzahl nur Widerspruchsindikator; verwalteter Dateipfad eindeutig | umgesetzt (AS2a) |
| E116 | Kontrollzone (`l3_control_zone`, Standard `localhost`) als Betriebsvoraussetzung, Anzeige in `system/status` | umgesetzt (AS2a) |
| E117 | Absolute Frist je Agent-Aufruf (TW = Beginn + 24 s), Obergrenzen je Prozess, eigene Prozessgruppe, SIGTERM/SIGKILL ohne verwaiste Prozesse, Sperre mit Frist (Reserve vor jedem Sperrversuch geprüft); gilt in `observe`/`enforce` für `zone_sync`, `conf_sync`, `zone_cleanup` und `system/status` (`status_timeout`) | umgesetzt (AS2a, Review-Korrektur) |
| E118 | `fencing_mode` ist Pflichtangabe: nur ein ausdrücklich gesetztes `off` erlaubt den bisherigen Ablauf; ein fehlender Schlüssel oder ein unbekannter Wert ⇒ `zone_sync`, `conf_sync`, `zone_cleanup` und `system/status` brechen früh mit 503 `fence_misconfigured` ab (Vorrang wie bisher: `agent_requirement_missing`, `fence_fault`), ohne externen Prozess und ohne Änderung; kein stiller Rückfall. Ändert E82 und E95. Upgrade: bestehende Installationen setzen **vor** dem Update `'fencing_mode' => 'off'` (README, `AGENT_FENCING.md` Abschnitt 9) | entschieden (Maintainer, Option B), umgesetzt (AS2a) |
| E119 | Polling und Nachverifikation unter der Agent-Sperre; Freigabe erst danach | umgesetzt (AS2b) |
| E120 | Fenster min(Reload-Ende + 10 s, TW − 1 s); Runden sofort, dann 50 ms verdoppelt bis 500 ms; Abschnittsfrist für jeden Prozess der Verifikation; Vorabnachweis im Cleanup nur ab 20 s Restzeit mit Abschnittsfrist TW − 10 s und erneuter Prüfung (≥ 9 s) danach, sonst `budget_exhausted`; Sperrreserve (8 s) und Prozessobergrenzen unverändert; Ende-zu-Ende-Reserve zum cURL-Timeout als Risiko dokumentiert | umgesetzt (AS2b; 20 s nach Freigabe statt vorgeschlagener 18 s) |
| E121 | `conf_sync`: Matrix Abschnitt 5g mit R1–R4; K und F nur bei vorübergehendem Grund erneut (M1: Zonenzahl nach dem Reload sofort korrekt); `file_sha256` und Rohwerte; kein Feld `cmp` | umgesetzt (AS2b) |
| E122 | Gescheiterter Reload: `conf_sync` ohne DNS-Abfrage; Cleanup eine Runde nur mit SOA-Abfrage (`still_served` möglich, `absent` nie, keine Kontrollabfrage) | umgesetzt (AS2b) |
| E123 | Zonennamen im Cleanup nur aus eindeutigem Konfigurationsnachweis vor der Änderung; keine Ableitung aus dem Dateinamen | umgesetzt (AS2b) |
| E124 | Cleanup-Matrix Abschnitt 5g; `absent` endgültig (R3); Kontrollzonen-Speicher nur in derselben Runde und für denselben Nachweis | umgesetzt (AS2b) |
| E125 | Antwortvertrag nur additiv; HTTP-Status, `message`, `effect`, Klassifizierung unverändert; Core wertet erst ab AS4 aus | umgesetzt (AS2b) |
| E126 | Tests: rein, Ersatzprogramme ohne BIND, Live gegen den installierten `named` auf ns2, Mutationsproben, AS1-/AS2a-Regression, `off`-Vergleich, Stufentest über den installierten Core | umgesetzt (AS2b) |
| E127 | Messung M1 (Verzögerung von Zonenzahl, K und D nach dem Reload) vor der Implementierung | durchgeführt 20.11.2026 (Abschnitt 5g) |
| E128 | Folgepaket: AS2a-`zone_sync` (und AS2b) lehnen Zonennamen mit Großbuchstaben bzw. abschließendem Punkt fail-closed ab bzw. verifizieren sie nicht (normalisierter Name ↔ verwalteter Pfad) | offen, eigenes Paket vor AS6; nicht Teil von AS2b |
| B-M1-1 | Befund: Während BIND eine große Zone lädt, können Abfragen an unbeteiligte Zonen ausbleiben; AS2a-`zone_sync` kann dann nach E103 Zeile 3 mit `served_state_unknown` ablehnen (fail-closed, Teilverteilung) | dokumentiert; bei der Planung von AS4 ausdrücklich zu berücksichtigen, sonst vor AS6 gesondert beheben |

**Weitere offene Implementierungsdetails:** genaue Tabellen-/Spaltennamen und Codes;
Einbeziehung `system/control reload` in das Fencing; Aufteilung des
Generators für `render_hash`; Entfernung der ungenutzten Tabelle `deployments` (gesonderte Entscheidung).

## 21. Änderungsprotokoll

| Datum | Änderung |
|---|---|
| 21.11.2026 | Review PR #107, R1: `chk_zpt_zone_installed` erlaubt den vom Agenten gemeldeten installierten Stand auch bei `zone_sync = unknown` nach gescheitertem Reload (as2, effect applied/unchanged, Reload failed/timeout/not_started), weiterhin nur gemeinsam und nie als L2-/L3-Bestätigung; Tests C06b–d, C29b, C30–C30h |
| 21.11.2026 | AS4a umgesetzt: Abschnitt 5i (Migration 007 mit Agent-Ergebnisfeldern in `zone_publish_targets`/`publish_cleanup_results` und `system_status.fence_domain`, NULL-sichere CHECKs K1, Kompatibilitätsbrücke `HISTORY_TABLES_007`, `agent_protocol` als Registrierungszustand K3, Reihenfolge Domänenprüfung vor J6/J1 K2, Ordnungsvertrag für AS4b); Publish, Journal-Schreibpfade, Monitoring, Agent und Oberfläche unverändert |
| 20.11.2026 | AS3 umgesetzt: Abschnitt 5h (Migration 006 mit `zones.soa_serial` als `INT UNSIGNED` und Tabelle `zone_server_verification`, Datenvertrag und NULL-sichere CHECKs, Migration unter dem Publish-Lock in einer Sitzung), E79 und E87 umgesetzt; Abschnitte 5 und 10 nachgeführt; Serial-Strategie, Publish, Historie, Agent und Oberfläche unverändert |
| 20.11.2026 | Review PR #105, Nachtrag R2: `control_queries` zählt nur tatsächlich gestartete dig-Prozesse der Kontrollabfrage (`dnsQuery()['started']`, `dnsControlZoneState()['queried']`), nicht `dig_not_started`/`dig_error`; Zustände und Nachweislogik unverändert; Tests C09–C13, Mutation MV19 |
| 20.11.2026 | Review PR #105: R1 – Cleanup meldet `still_served` auch bei K = ja bzw. strukturell unbestimmbarem K nur aus der letzten vollständigen Runde (Abschnitt 5g an R4 und die freigegebene Matrix angeglichen); R2 – `control_queries` zählt nur tatsächlich ausgeführte DNS-Abfragen der Kontrollzone; Tests F08/F09, DR04–DR04c, C06–C10, Mutationen MV17/MV18 |
| 20.11.2026 | AS2b umgesetzt: Abschnitt 5g (Polling unter der Agent-Sperre, Nachverifikation in `conf_sync`, Nachweis der Entfernung im Cleanup, Vorabnachweis der Zonennamen, Zeitbudget mit Abschnittsfrist, Kontrollzonen-Speicher je Runde, R1–R4), Messung M1, E92/E106–E108 umgesetzt, E119–E127, Folgepaket E128, Befund B-M1-1 |
| 19.11.2026 | E118 (Option B): `fencing_mode` ist Pflichtangabe; fehlender Schlüssel ⇒ `fence_misconfigured` in `zone_sync`, `conf_sync`, `zone_cleanup` und `system/status`; E82 und E95 entsprechend geändert; Upgrade-Hinweis |
| 19.11.2026 | Review AS2a (letzte Korrektur): `system/status` fail-closed ohne externen Prozess bei fehlendem `posix` in `observe`/`enforce` (`agent_requirement_missing`) und bei unbekanntem `fencing_mode` (`fence_misconfigured`); nur `off` verwendet den bisherigen Ablauf |
| 19.11.2026 | Review AS2a: `system/status` mit Prozessfristen in `observe`/`enforce` (`status_timeout`, DNS-Testabfrage über Exit-Code und Antwortzeile), Reserveprüfung vor jedem Sperrversuch; Befund außerhalb des Umfangs (unbeschränkte Aufrufe in `system/control`, `conf_check`, `zone_check` und im Modus `off`) dokumentiert |
| 19.11.2026 | AS2a umgesetzt: Abschnitt 5f (Verantwortung Core/Agent, RFC 1982 mit Vektordatei, Konfigurationsnachweis K, ausgelieferter Zustand D, Kontrollzone, Vorher-Prüfung E103, `effect`/`rollback`, Vorwärtsstrategie, Restore-Abgrenzung, Fristen, Klassifizierung); E92 neu gefasst; E101–E117; Messungen auf ns1/ns2 (BIND 9.20.24) |
| 18.11.2026 | Erstfassung nach Abschluss AP1/H1 (Bestandsaufnahme, Review-Nachträge, lesende Inspektion ns1/ns2, Entscheidungen E8, E10, E17; zu E15/E16 nur die 32-Bit-Anforderung, Strategie und Spaltenmigration offen für AS) |
| 18.11.2026 | Review PR #97: H3 umfasst ausdrücklich auch die Weboberfläche; E15/E16 als offen gekennzeichnet; F3/F4 um Absturzsicherheit und fail-closed bei Persistenzfehlern ergänzt; Unveränderlichkeit und Lebenszyklus der Journaldaten (J4, L1) präzisiert |
| 18.11.2026 | H2a umgesetzt: Abschnitt 5a (endgültige Grundtabellen, Vertrag `zs1`, Diff, Leser, Baseline, `SchemaCheck`, Migration), Entscheidungen E18–E28, Reihenfolge Migration → Code → Initialisierung (Abschnitt 15) |
| 18.11.2026 | Review PR #99: S1 – `SchemaCheck` und Migrationsskript prüfen CHECK-Bedingungen einschließlich normalisiertem Ausdruck (Abschnitt 5a); S2 – Testressourcen mit Lauf-Kennung und Eigentumsregister (`tests/history/owned.sh`) |
| 19.11.2026 | Review PR #103: H wird nur nach nachweislich vollständigem Read akzeptiert (`readFileComplete()` mit Obergrenze `AGENT_FENCE_MAX_BYTES`; X8, M28); `AGENT_FENCING.md` Abschnitt 1 (Fehlermarke sperrt in jedem Modus) und Testaufruf `FSICLXGM` korrigiert |
| 19.11.2026 | Abnahme AS1, Bereinigung nach Preflight: Abschnitt 5e (Modi, Codetabelle mit `agent_requirement_missing`, Dauerhaftigkeit/E95, `system/status`) und Register E95/E97 an das umgesetzte Verhalten angeglichen; nur Dokumentation und Kommentare |
| 19.11.2026 | Abnahme AS1: E100 (`ext-posix` verbindlich, kein Rückfall, Prüfung bei jedem schreibenden Aufruf; X7, M26/M27) |
| 19.11.2026 | Abnahmevorbereitung AS1: E99 (nicht prüfbare Existenz fail-closed, Gruppe X, M25), E95 abschließend präzisiert, E98 um Wiederherstellungsweg ergänzt (`AGENT_FENCING.md` Abschnitt 6, auf ns2 nachgewiesen), Register numerisch geordnet |
| 19.11.2026 | Review AS1: E97 (Fehlermarke und Persistenzfehler in jedem Modus, `off` deaktiviert nur die Fencing-Prüfung) und E98 (Vollständigkeitsnachweis der Verzeichnisauflistung, vollständiges Lesen von `zones.conf`); Restgrenze von S43 geschlossen; Gruppe L, Mutationen M19–M24 |
| 19.11.2026 | AS0 (technische Vorprüfung) und AS1 umgesetzt: Abschnitt 5e (Agent-Fencing, Write-ahead-Fehlermarke, Domäne und Schutzgrenze, Modi, CLI, dauerhafte Dateioperationen), Entscheidungen E74–E96 einschließlich E4 und E15/E16, Befund S43, Retention als Meilenstein R (Abschnitt 18), Betrieb in `AGENT_FENCING.md` |
| 18.11.2026 | H2d umgesetzt: Abschnitt 5d (Publish-Journal, Migration 005), Präzisierung von Abschnitt 9 (Reaktivierung abgeleitet, E70), Entscheidungen E55–E73 mit den Präzisierungen der Freigabe (isolierte Testumgebung, Akteurskontext, J5/Neustart über laufeigene Sperre, operatives Ergebnis ≠ Bestätigung) |
| 18.11.2026 | H2c umgesetzt: Abschnitt 5c (Audit-Ereignisse, Migration 004), Regeln R-A1–R-A8 (Abschnitt 6), Leserechte für H3 (Abschnitt 16), Entscheidungen E43–E54 mit den Präzisierungen aus dem Plan-Review (NULL-sichere CHECKs und `seq`, Serverstand unter Sperre, gemeinsame Änderungsmenge, strikte Policy); Korrektur in 5b (Publish gehört zu H2d) |
| 18.11.2026 | H2b umgesetzt: Abschnitt 5b, Präzisierung von R-H2/R-H9 (Abschnitt 6), Entscheidungen E29–E42 mit den Präzisierungen aus dem Plan-Review (E33 Duplicate-Key-Pfad, E35 fehlende Heads ohne Gap-Sperre, E39 Bindung des Wächters an Verbindung und Transaktion, E42 Schutz von ns1), Migration 003 |
