# History-Tests (AP1/H2a, H2b, H2c)

Regressionstests der History-Grundlagen: Migration 002, `SchemaCheck`, kanonische Zonen-Snapshots (Vertrag `zs1`),
Diff und Baseline-Initialisierung. Architektur: [`docs/ARCHITECTURE_HISTORY.md`](../../docs/ARCHITECTURE_HISTORY.md).

## Lauf

Auf dem Testsystem ns1 als root, immer mit festen Commits:

```bash
git clone -q https://github.com/Twilight-Networks/dnsmanager.git /root/dnsmgr-h2a-harness
git -C /root/dnsmgr-h2a-harness checkout -q --detach <App-SHA>
bash /root/dnsmgr-h2a-harness/tests/history/run.sh <App-SHA> <Basis-SHA>
```

`<Basis-SHA>` ist der Stand vor Migration 002 (dessen `sql/dnsmanager_schema.sql` ist das Ausgangsschema der
Migrationstests). `run.sh` klont selbst und prüft mit `git archive` des App-Commits. Die installierte Anwendung, ihre
Datenbank und `/etc/dnsmanager` werden nicht berührt. Der Advisory-Lock `dnsmanager_publish_all` ist serverweit; die
Tests belegen ihn nur kurz, ein belegter Lock beim Start bricht den Lauf ab.

**Eigentum der Testressourcen (`owned.sh`):** Jeder Lauf hat eine Lauf-Kennung `H2A_RUN` (8 Hex-Zeichen, zufällig oder
vorgegeben). Datenbanken heißen `dnsmgr_t21_r<RUN>_<suffix>`, der DB-Benutzer `h2a<RUN>`. Eine Ressource gehört dem Lauf
nur, wenn er sie selbst angelegt hat (`CREATE DATABASE`/`CREATE USER` ohne `IF NOT EXISTS`, danach Eintrag ins Register).
Aufgeräumt werden ausschließlich registrierte Ressourcen; fremde Datenbanken und Benutzer bleiben erhalten, auch wenn sie
während des Laufs entstehen oder das Präfix des Laufs tragen. Nachweis: `cleanup_test.sh`.

```bash
bash /root/dnsmgr-h2a-harness/tests/history/cleanup_test.sh <App-SHA> <Basis-SHA>
```

## Suiten

| Datei | Inhalt |
|---|---|
| `pure_test.php` | Vertrag `zs1` (eingefrorener Vektor, zusätzlich unabhängig mit `sha256sum` geprüft), Whitelist, Typstrenge, NULL/""/0, Duplikate, Schreibweise, UTF-8, `fromPayload` (nur kanonisch), Diff (Multimenge, Inhaltsschlüssel, Determinismus, Zusammenfassung ohne Inhalte), Strukturbewertung von `SchemaCheck` |
| `migration_test.sh` | `--check`/`--apply`, Wiederholbarkeit, Sicherung, `--no-backup` nur für `dnsmgr_tNN_*`, Preflight **vor** der ersten DDL (Abweichung ⇒ Exit 2 ohne Änderung), technischer Abbruch mitten in der DDL (Fehlerinjektion) mit deterministischem Wiederanlauf, Erkennung von Mutationen (Spalte, Index, FK-Regel, CHECK entfernt, CHECK-Ausdruck bei gleichem Namen geändert, Sortierung, ENUM, NULL, Standardwert, Sicht) durch Skript **und** `SchemaCheck`, Neuinstallation = Migration (Struktursignatur), Fachtabellen unverändert (Inhalts-Prüfsumme) |
| `db_test.php` | Baseline unter REPEATABLE READ (`innodb_snapshot_isolation = ON`) und READ COMMITTED: Ergebnisstruktur, Hash = Payload = unabhängig gelesener DB-Stand, Fachdaten/Flags/Serials unverändert, Prüflauf, Wiederholbarkeit, neue Zone, Advisory-Lock, fail-closed Schema, Fehlerinjektion je INSERT (Trigger) mit vollständigem Rollback, Teilabbruch mit Wiederanlauf, Revisionen ohne Head, **tatsächlicher Lock-Wait** gegen eine laufende Fachtransaktion mit **Mutationsprobe** (Leser ohne Sperren wird erkannt), gelöschte Zone während des Laufs, Suche nach Geheimnissen |
| `cleanup_test.sh` | Eigentumsnachweis (S2): vollständiger `run.sh`-Lauf im Hintergrund; währenddessen fremde Datenbanken (beliebig, anderer Lauf, **gleiches Laufpräfix**) und ein fremder Benutzer angelegt; danach alle fremden Ressourcen erhalten, alle eigenen entfernt |
| `migration003_test.sh` | H2b: Migration 003 (`history_dependencies`): ohne Migration 002 Abbruch, `--check`/`--apply` mit Sicherung, Wiederholbarkeit, abweichende Tabelle bzw. Grundtabelle ⇒ Exit 2 ohne DDL, technischer Abbruch mit Wiederanlauf, Neuinstallation = Migration |
| `static_rh8_test.php` | H2b, R-H8 statisch, und H2c (R05–R09): festgeschriebene Menge der auditpflichtigen Verwaltungs-Schreibstellen mit open/record/finish (nach History, vor Commit)/close und gemeinsamem Kontext; Reload-Nachtrag nach dem Agentenaufruf. H2b: festgeschriebene Menge der Schreibstellen; je Stelle `open`, `beginZones`/`zoneCreated`, `zoneDeleting` (Löschung), `finish` vor `commit`, `close`; Laufzeitwächter vorhanden; History-Tabellen nur unter `web/inc/app/history` geschrieben |
| `recorder_test.php` | H2b unter RR (Snapshot-Isolation) und RC, Fixture `fixture_h2b.sql`: alle elf Schreibpfade mit exakten Änderungsmengen und Revisionen (P), Berechtigung (A), Drift, Lazy-Baseline, Abgleich gelöschter Zonen, fehlender Head (D), Wächter und Transaktionsbindung (G), Snapshot-Store mit Duplicate-Key-Pfad und Nebenläufigkeit (S), Fehlerinjektion je History-Schreibvorgang (F), Lock-Wait-Fälle und Zonen ohne Head mit Gegenprobe (N), Stresslauf mit Invarianten und Suche nach Geheimnissen (X) |
| `mutation_test.sh` | H2c: dreizehn weitere Mutationsproben MA01–MA13 (Ereignis fehlt, Schwärzung, ungesperrte Vorher-Werte, E50-Prüfung, getrennte Änderungsmenge, Name nach Selbstlöschung, Savepoint-Bindung, lokaler Server, NULL-unsichere CHECK, nicht eindeutige `seq`, Kaskadenzähler, Reload-Kennzeichen, Kennzeichenprüfung). H2b: zwölf Mutationsproben (Drift, Commit-Reihenfolge, Head-Sperre, Read-View vor Zonensperren, fehlende Erfassung, Revisionsnummer, AutoPTR-Abhängigkeit, Savepoint-Bindung, Duplicate-Key-Pfad, Gap-Sperre, Wächter, `deleted_at`) – jede muss erkannt werden |
| `migration004_test.sh` | H2c: Migration 004 (`audit_events`): ohne Migration 002 Abbruch, `--check`/`--apply` mit Sicherung, Wiederholbarkeit, Erkennung abweichender Struktur (Spalte, gleichnamiger CHECK ohne `IS NOT NULL` mit Gegenprobe der NULL-Semantik, nicht eindeutiger `seq`-Index, `seq` NULL-fähig), abweichende Grundtabelle ⇒ Exit 2 ohne DDL, technischer Abbruch mit Wiederanlauf, Neuinstallation = Migration 002 + 003 + 004 |
| `audit_test.php` | H2c unter RR (Snapshot-Isolation) und RC: `AuditPolicy` rein (Q), SQL-Constraints negativ (K), alle auditierten Pfade mit exakten Ereignissen und gemeinsamer bzw. genau einer Änderungsmenge, Selbstumbenennung und Selbstlöschung (P), Berechtigung (A), Schwärzung und Geheimnissuche (R), Fehlerinjektion je Pfad und Reload-Nachtrag (F), Bindung und Reihenfolge (G), Serverstand unter Sperre mit Lock-Wait, konkurrierender Wechsel des lokalen Servers, Kaskadenzähler (N), Stresslauf (X) |
| `cli_test.sh` | `history_baseline-cli.php` mit einem Datenbankbenutzer nur mit DML-Rechten (wie der Anwendungsbenutzer): Exitcodes, Ausgabe, Logzeilen, Lock, fail-closed ohne Migration; `schema_history-cli.php` |

Hilfsdateien: `owned.sh` (Lauf-Kennung und Eigentumsregister), `lib.php` (Prüfhilfen), `fixture.sql` (Testdaten mit
erkennbaren Platzhalter-Geheimnissen), `schemacheck.php` und `baseline_worker.php` (Gegenprobe bzw. eigener Prozess für Nebenläufigkeit).
H2b: `fixture_h2b.sql` (gültige Zonen für `named-checkzone`), `h2b_lib.php` (Datenbank, Services, Abfragen, Invarianten),
`h2b_worker.php` (eigener Prozess für Sperr- und Stressfälle), `h2b_fake_publish.php` (Publish-Backend ohne Agenten).
`run.sh` führt alle Suiten aus; `H2B_SKIP_MUTATION=1` überspringt die Mutationsproben, `H2B_ONLY=P,D,…` beschränkt
`recorder_test.php` auf Abschnitte, `H2C_ONLY=Q,P,…` ebenso `audit_test.php`.
