# History-Tests (AP1/H2a)

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
Migrationstests). `run.sh` klont selbst, prüft mit `git archive` des App-Commits, verwendet nur Datenbanken
`dnsmgr_t21_*` und den Benutzer `h2acli` und räumt am Ende vollständig auf. Die installierte Anwendung, ihre Datenbank
und `/etc/dnsmanager` werden nicht berührt. Der Advisory-Lock `dnsmanager_publish_all` ist serverweit; die Tests belegen
ihn nur kurz, ein belegter Lock beim Start bricht den Lauf ab.

## Suiten

| Datei | Inhalt |
|---|---|
| `pure_test.php` | Vertrag `zs1` (eingefrorener Vektor, zusätzlich unabhängig mit `sha256sum` geprüft), Whitelist, Typstrenge, NULL/""/0, Duplikate, Schreibweise, UTF-8, `fromPayload` (nur kanonisch), Diff (Multimenge, Inhaltsschlüssel, Determinismus, Zusammenfassung ohne Inhalte), Strukturbewertung von `SchemaCheck` |
| `migration_test.sh` | `--check`/`--apply`, Wiederholbarkeit, Sicherung, `--no-backup` nur für `dnsmgr_tNN_*`, Preflight **vor** der ersten DDL (Abweichung ⇒ Exit 2 ohne Änderung), technischer Abbruch mitten in der DDL (Fehlerinjektion) mit deterministischem Wiederanlauf, Erkennung von Mutationen (Spalte, Index, FK-Regel, CHECK, Sortierung, ENUM, NULL, Standardwert, Sicht) durch Skript **und** `SchemaCheck`, Neuinstallation = Migration (Struktursignatur), Fachtabellen unverändert (Inhalts-Prüfsumme) |
| `db_test.php` | Baseline unter REPEATABLE READ (`innodb_snapshot_isolation = ON`) und READ COMMITTED: Ergebnisstruktur, Hash = Payload = unabhängig gelesener DB-Stand, Fachdaten/Flags/Serials unverändert, Prüflauf, Wiederholbarkeit, neue Zone, Advisory-Lock, fail-closed Schema, Fehlerinjektion je INSERT (Trigger) mit vollständigem Rollback, Teilabbruch mit Wiederanlauf, Revisionen ohne Head, **tatsächlicher Lock-Wait** gegen eine laufende Fachtransaktion mit **Mutationsprobe** (Leser ohne Sperren wird erkannt), gelöschte Zone während des Laufs, Suche nach Geheimnissen |
| `cli_test.sh` | `history_baseline-cli.php` mit einem Datenbankbenutzer nur mit DML-Rechten (wie der Anwendungsbenutzer): Exitcodes, Ausgabe, Logzeilen, Lock, fail-closed ohne Migration; `schema_history-cli.php` |

Hilfsdateien: `lib.php` (Prüfhilfen), `fixture.sql` (Testdaten mit erkennbaren Platzhalter-Geheimnissen),
`schemacheck.php` und `baseline_worker.php` (Gegenprobe bzw. eigener Prozess für Nebenläufigkeit).
