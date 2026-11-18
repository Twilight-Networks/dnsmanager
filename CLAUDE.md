# CLAUDE.md – DNS Manager

Projekt `Twilight-Networks/dnsmanager`: PHP ohne Framework, MariaDB, BIND; Weboberfläche unter `web/`, Agent unter `agent/`.
Kommunikation, Kommentare, Docblocks und Logtexte auf Deutsch.

Diese Datei enthält nur dauerhafte Arbeitsregeln. Architektur, Invarianten, Entscheidungen und Projektstand stehen in `docs/`
und in Git und werden hier nicht wiederholt. Umgebungsbezogene Angaben (lokale Pfade, Zugänge und Rollen der Testsysteme,
persönliche Freigaben) gehören in die nicht versionierte `CLAUDE.local.md` (Abschnitt 6).

## 1. Maßgebliche Dokumente

- `docs/ARCHITECTURE_CORE.md`: Core-/UI-Trennung, Regeln für Core, Adapter und Berechtigungen, verbindliche
  Lock-Reihenfolge (Abschnitt 1), offene Befunde (Abschnitt 6).
- `docs/ARCHITECTURE_HISTORY.md`: Änderungshistorie, Discard und Restore – verbindliche Invarianten, Meilensteine,
  Entscheidungsregister, Teststrategie.
- `docs/UI_GUIDELINES.md`, `docs/UI_ROLE_MATRIX.md`: Oberfläche und Rollen.
- `docs/AGENT_TLS.md`: Agent-TLS-Betrieb.
- Aktuelle Fakten (Branches, Commits, PR-Stand, Systemzustand) immer gegen Git, GitHub und die Testsysteme prüfen, nicht
  aus Dokumenten oder früheren Übergaben übernehmen. Bei Widersprüchen gelten die ausdrücklichen aktuellen Anweisungen
  des Maintainers.

## 2. Auftrag und Freigabegrenzen

- Innerhalb des freigegebenen PR- bzw. Aufgabenumfangs selbstständig arbeiten: analysieren, implementieren, testen,
  korrigieren, Feature-Branch committen und pushen, Draft-PR erstellen oder aktualisieren.
- Merge nach `main` nur nach ausdrücklicher Freigabe durch den Maintainer (Squash-Merge). Kein direkter Push und kein
  Force-Push auf `main`.
- Neue Architekturentscheidungen oder wesentliche Verhaltensänderungen: zuerst ein Plan mit nummerierten Entscheidungen
  (E1, E2, …), Freigabe abwarten. Nicht ungefragt über den vereinbarten Umfang hinaus refactoren.
- Sicherheitsbefunde (S…) und Verhaltensänderungen dokumentieren; Abweichungen nie stillschweigend einführen.
- Nach externem Review nur die vereinbarten Befunde korrigieren und die passenden Regressionstests erneut ausführen.
- Datenbankschema, Sicherheitsmodell, Agent, MCP-Anbindung und neue Oberflächen nur im Rahmen eines ausdrücklich
  freigegebenen Vorhabens ändern.
- Keine Geheimnisse (Schlüssel, Passwörter, Tokens, Datenbankzugänge, Passwort-Hashes) in Commits, Logs, PRs, Prompts
  oder Review-Artefakten.

## 3. Git und GitHub

- Branches von aktuellem `origin/main` mit Präfix `refactor/`, `security/`, `fix/` oder `docs/`; PRs nach `main` als Draft.
- Vor Änderungen `git status`, Branch, `HEAD` und `origin/main` prüfen. Ein Checkout kann von mehreren Sitzungen genutzt
  werden: kein Branchwechsel und kein Aufräumen fremder Arbeitsstände ohne Rücksprache; eigene Arbeit bevorzugt in einem
  separaten Worktree.
- Vor jedem Commit: exakte Dateiliste, `git status --short`, `git diff --cached --name-only`,
  `git -c core.whitespace=cr-at-eol diff --cached --check` und `git diff`. Nur beabsichtigte Dateien stagen, kein
  `git add .`, keine ignorierten Dateien erzwingen.
- Nach dem Commit `git log -1 --format="%H%n%T%n%s"` mit dem vorherigen `git write-tree` abgleichen und den gepushten
  SHA auf GitHub verifizieren.
- Tests auf Testsystemen nur mit einem festen Commit-SHA; Commit und Tree dort vor Testbeginn prüfen.
- Zeilenenden: Es gibt keine `.gitattributes`. Im Index gilt LF, ausgenommen die CRLF-Dateien
  `web/actions/user_password.php`, `web/common.php`, `web/inc/logging.php` und `web/pages/users.php`. Zeilenenden
  bestehender Dateien erhalten, neue Dateien als LF anlegen; geänderte Dateien mit `git ls-files --eol` prüfen.

## 4. Architektur-Merkliste

Verbindlich ist `docs/ARCHITECTURE_CORE.md`, Abschnitt 1 (mit den Erweiterungen aus `docs/ARCHITECTURE_HISTORY.md`).
Kurzfassung zur Orientierung:

- Fachlogik nur im Core `web/inc/app/`; Web-Actions, Seiten, API und Skripte sind dünne Adapter, unbekannte
  Service-Codes werden nie verworfen.
- Berechtigung nach Datenbankstand: lesend in derselben SQL-Anweisung, schreibend in der Transaktion unter Sperre.
- Bestehende Web-Verträge (HTTP-Status, Body, Texte, Logs, Redirects, CSRF, Formulare) bleiben unverändert, sofern
  keine Abweichung freigegeben und getestet ist. Neue Sprachschlüssel immer in `web/lang/de.php`, `en.php` und `es.php`.

## 5. Tests und Abnahme

- Je PR lokale, isolierte Regressionstests; Integrationstests auf den Testsystemen, soweit für den Umfang sinnvoll.
  Datenbank-Suiten unter REPEATABLE READ und READ COMMITTED.
- Web-Golden gegen den Basis-Commit (`tests/ui`): HTTP-Status, Location, Body, Toasts, Logs und DB-Zustand. Jede
  beabsichtigte Abweichung einzeln mit Fall-ID dokumentieren.
- Nebenläufigkeit, wo der PR entsprechende Logik berührt: tatsächlichen Lock-Wait nachweisen, Legacy-Gegenprobe,
  Mutationsprobe, Stresslauf mit Invarianten.
- Fehlerinjektion: vollständiges Rollback, keine offenen Transaktionen oder Sperren.
- BIND/Agent: Rollen und Endpunkte prüfen, kontrolliert verteilen, DNS-Antworten, Serials und Logs verifizieren und den
  Ausgangszustand wiederherstellen. Vor schwer umkehrbaren Änderungen Sicherung und Rückweg festlegen.
- Abschlussbericht je PR: Basis- und Head-Commit, Tree, Dateiliste, Testumgebung und Versionen, Ergebnisse, bewusste
  Verhaltensänderungen, Restrisiken, Cleanup, Link zum Draft-PR; ein vollständig prüfbarer Diff für das externe Review.

## 6. Lokale Dateien, Worktrees und temporäre Ergebnisse

- `CLAUDE.local.md` im Wurzelverzeichnis eines Checkouts ergänzt diese Datei um lokale Angaben. Sie ist über `.gitignore`
  ausgeschlossen, wird nie committet und enthält keine Geheimnisse (Arbeitsverzeichnisse können cloud-synchronisiert sein).
- Jeder Git-Worktree ist ein eigenes Verzeichnis; die `CLAUDE.local.md` eines anderen Checkouts wird dort nicht geladen.
  Bei Bedarf im Worktree eine eigene `CLAUDE.local.md` anlegen, bevorzugt als Import einer gemeinsamen Datei außerhalb
  des Repositorys (z. B. `@~/.claude/…`). Vor dem Entfernen eines Worktrees dessen lokale Dateien prüfen.
- Temporäre Skripte, Fixtures, Logs, Diffs und Testberichte in der Temp-Umgebung des jeweiligen Systems ablegen, nie im
  Repository; keine automatisch erzeugten Ausgabeordner im Repository. Bewusst versionierte Tests sind ausgenommen.
