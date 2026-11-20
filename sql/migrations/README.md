# Datenbankmigrationen

Migrationen für bestehende Installationen. Neuinstallationen verwenden `sql/dnsmanager_schema.sql`, das den jeweils aktuellen Stand bereits enthält.

Der Webcode nimmt **niemals** selbst Schemaänderungen vor. Fehlt eine vorausgesetzte Spalte oder Tabelle oder weicht sie ab, verweigern Login und geschützte Seiten den Dienst mit HTTP 503 (Fail-Closed, `SchemaCheck`) und protokollieren `event=schema_incompatible`.

## 001 – Kontostatus und Sitzungsgeneration (S26/S27)

| Datei | Zweck |
|---|---|
| `001_users_active_session_epoch.sql` | wiederholbares SQL (`ADD COLUMN IF NOT EXISTS`, eine atomare `ALTER TABLE`-Anweisung) |
| `../../scripts/migrate_001_users_active_session_epoch.sh` | Preflight, Sicherung, Ausführung, Nachprüfung |

Neue Spalten in `users`:

- `active TINYINT(1) NOT NULL DEFAULT 1`: 0 = Konto deaktiviert. Keine Anmeldung, bestehende Sessions enden, Application Services lehnen den Akteur ab. Name, Rolle und Zonenzuweisungen bleiben erhalten.
- `session_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0`: Sitzungsgeneration. Steigt bei Passwortänderung, Rollenänderung, Umbenennung sowie Deaktivierung und Reaktivierung. Sessions mit fehlender oder abweichender Generation werden widerrufen.

### Verbindliche Reihenfolge

1. **Sicherung und Migration** (Code noch alt).
2. **Prüfung** `--check` → Exitcode 0.
3. **Code** ausrollen.

Der alte Code bleibt mit dem erweiterten Schema lauffähig: Er liest die neuen Spalten nicht und legt Benutzer über die Standardwerte aktiv an. Der neue Code mit altem Schema antwortet bei Login und geschützten Seiten mit 503.

**Nach dem Code-Update** sind alle bestehenden Sessions ungültig, weil sie keine Generation enthalten. Jeder Benutzer meldet sich einmal neu an.

### Ablauf

```bash
# 1. Preflight (ändert nichts)
bash scripts/migrate_001_users_active_session_epoch.sh --db dnsmanager --check
#    Exit 1 = Migration erforderlich, 0 = bereits vorhanden, 2 = abweichende Struktur (manuell klären)

# 2. Migration mit Sicherung (vollständiger mysqldump, Rechte 600 in einem 700-Verzeichnis).
#    --no-backup ist nur für isolierte Testdatenbanken (dnsmgr_tNN_*) zulässig und wird für jede andere
#    Datenbank vor jedem Datenbankzugriff abgelehnt (Exit 2).
bash scripts/migrate_001_users_active_session_epoch.sh --db dnsmanager --apply --backup-dir /root/dnsmanager-backup

# 3. Kontrolle
bash scripts/migrate_001_users_active_session_epoch.sh --db dnsmanager --check
```

- **Preflight:** Das Skript prüft MariaDB, Datenbank, Tabelle `users` und den Zustand beider Spalten. Ist eine Spalte vorhanden, aber abweichend definiert (Typ, NULL, Standardwert), bricht es **ohne Änderung** ab (Exit 2).
- **Nachprüfung:** exakte Spaltenstruktur und unveränderte Anzahl der Benutzer.

### Wiederanlauf

- Die Migration ist wiederholbar. Sind beide Spalten korrekt vorhanden, meldet `--apply` „Nichts zu tun“ (Exit 0).
- Die `ALTER TABLE`-Anweisung legt beide Spalten gemeinsam an: entweder beide oder keine. Nach einem Abbruch das Skript erneut ausführen.
- Wurde eine Spalte außerhalb des Skripts falsch angelegt (Exit 2), zuerst klären und manuell korrigieren; das Skript ändert vorhandene Spalten bewusst nicht.

### Rollback

**Grundsatz: Ein Code-Rollback ist nach dieser Migration nicht automatisch sicher.**

Der alte Code (vor S26/S27) kennt weder `users.active` noch `users.session_epoch`. Ein Rückfall hätte deshalb zwei Folgen:

1. **Deaktivierte Konten wären wieder voll nutzbar.** Anmeldung und alle Rechte würden nur noch über Rolle und Zonenzuweisung geprüft.
2. **Widerrufene Sessions könnten wieder gültig werden.** Der neue Code widerruft Sessions bei Deaktivierung und Passwortänderung erst bei ihrer nächsten Anfrage. Eine seitdem unbenutzte Session-Datei enthält noch Benutzer-ID, Name und Rolle. Der alte Code würde sie wieder annehmen, weil er die Generation nicht prüft.

Vorrang hat ein **Fehlerbehebungs-Update (Roll-forward)** auf dem neuen Code. Ist ein Rückfall dennoch nötig, gilt der folgende Ablauf. Er ändert keine Konten automatisch; jede Kontoänderung ist eine ausdrückliche, dokumentierte Einzelentscheidung. Der alte Code darf erst wieder **allgemein erreichbar** sein, wenn Schritt 7 vollständig erfolgreich war.

#### 1. Bestandsaufnahme (nur lesend, vor jeder Änderung)

**a) Session-Speicher der Web-SAPI ermitteln.** Den Pfad nicht annehmen, sondern aus der tatsächlich verwendeten Konfiguration bestimmen:

```bash
V=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
apache2ctl -M 2>/dev/null | grep -iE 'php|proxy_fcgi'     # mod_php (SAPI apache2) oder PHP-FPM?
# mod_php: wirksamer Wert der apache2-php.ini, inklusive einkompiliertem Standard, falls dort nur auskommentiert
php -c /etc/php/$V/apache2/php.ini -r 'echo ini_get("session.save_handler"), " [", ini_get("session.save_path"), "]", PHP_EOL;'
# Überschreibungen mit Vorrang vor der php.ini (Apache-, FPM-Pool-, Verzeichnis-Konfiguration, Anwendung)
grep -RniE 'session\.(save_path|save_handler)' /etc/apache2/ /etc/php/$V/apache2/conf.d/ /etc/php/$V/fpm/ 2>/dev/null
grep -RniE 'session\.save_path|session_save_path' <installationsverzeichnis>/web 2>/dev/null
systemctl show apache2 -p PrivateTmp
```

Bei PHP-FPM gilt die Pool-Konfiguration (`php_admin_value[session.save_path]`) bzw. `/etc/php/$V/fpm/php.ini`.

Auswertung:

- `save_handler` ungleich `files` (z. B. redis, memcached): **keine Dateien löschen**. Die Bereinigung hängt dann vom Handler ab und ist vor dem Rückfall gesondert festzulegen.
- `save_path` mit Präfix `N;` oder `N;MODE;`: Verzeichnis ist der letzte Teil; Session-Dateien liegen N Ebenen tiefer.
- `save_path` leer: PHP verwendet das temporäre Systemverzeichnis. Bei `PrivateTmp=yes` ist das ein privates `/tmp` des Apache-Dienstes (`/tmp/systemd-private-*-apache2.service-*/tmp`), das beim Anhalten des Dienstes verworfen wird.
- Ergebnis (Handler, Verzeichnis, Ebenen) protokollieren.

**b) Aufrufkanäle erfassen**, die während des Rückfalls gesperrt sein müssen:

- Weboberfläche;
- DynDNS-API und Agent-Endpunkte, falls sie über denselben Webserver laufen. Sie prüfen keine Benutzerkonten, laufen aber mit dem alten Code und werden mit dem Webserver gesperrt;
- alle CLI- oder Automatisierungsaufrufe, die Application Services mit Benutzer-Akteuren nutzen (derzeit keine; künftige MCP- oder CLI-Zugänge sind anzuhalten).

Cron-Jobs ohne Benutzerbezug (Monitoring, Log- und Session-Bereinigung) werten keine Konten aus und können weiterlaufen. Die Listener des Webservers mit `ss -ltnp | grep -E ':80 |:443 '` erfassen.

**c) Kontosperren erfassen** und mit einer Sicherung (`mysqldump`, Rechte 600) ablegen:

```sql
SELECT id, username, role FROM users WHERE active = 0 ORDER BY id;
```

#### 2. Dienst vom Netz nehmen

Webserver anhalten (`systemctl stop apache2`) und die erfassten CLI- oder Automatisierungszugänge anhalten.

#### 3. Wirkung der Sperren ohne `active` sicherstellen

Nur nötig, wenn Schritt 1c Konten liefert. Für jedes dort aufgeführte Konto entscheidet die verantwortliche Person ausdrücklich und dokumentiert:

- **Löschen** über den bisherigen Weg, oder
- **Anmeldung unmöglich machen** durch ein nicht verifizierbares Passwort:

  ```sql
  UPDATE users SET password_hash = '!gesperrt-s27' WHERE id IN (<ids aus Schritt 1c>) AND active = 0;
  ```

  `password_verify()` schlägt damit immer fehl. Der ursprüngliche Hash bleibt in der Sicherung aus Schritt 1c. Bei einem späteren Roll-forward ist das Konto über einen Passwort-Reset durch einen Admin wiederherstellbar.

Der letzte aktive Admin ist nie betroffen; behandelt werden nur Konten mit `active = 0`.

#### 4. Alle Sessions beenden (Webserver angehalten)

Nur im ermittelten Verzeichnis und nur Session-Dateien (Handler `files`; `<T>` = Ebenen N + 1):

```bash
SP=<ermitteltes Verzeichnis>
find "$SP" -mindepth 1 -maxdepth <T> -type f -name 'sess_*' | wc -l     # Anzahl vorher
find "$SP" -mindepth 1 -maxdepth <T> -type f -name 'sess_*' -delete
find "$SP" -mindepth 1 -maxdepth <T> -type f -name 'sess_*' | wc -l     # muss 0 sein
date +%s > /root/rollback-session-purge.ts                              # Zeitpunkt der Bereinigung
```

Bei leerem `save_path` und `PrivateTmp=yes` prüfen, dass kein Verzeichnis `/tmp/systemd-private-*-apache2.service-*` mehr existiert. Alle Benutzer melden sich danach neu an.

#### 5. Code zurücksetzen (ausschließlich per festem SHA)

```bash
git -C <installationsverzeichnis> checkout -q --detach <alter-SHA>
git -C <installationsverzeichnis> rev-parse HEAD HEAD^{tree}
```

#### 6. Externen Zugriff sperren, dann kontrolliert starten

Vor dem Start des Webservers alle eingehenden Verbindungen auf 80/443 außer über die Loopback-Schnittstelle sperren. Den vorhandenen Firewall-Zustand vorher prüfen (`ufw status`, `nft list ruleset`) und eine bestehende Firewall entsprechend ergänzen. Beispiel mit nftables (eigene Tabelle, gilt für IPv4 und IPv6):

```bash
nft add table inet dnsmgr_rollback
nft 'add chain inet dnsmgr_rollback input { type filter hook input priority -10 ; policy accept ; }'
nft add rule inet dnsmgr_rollback input iifname != "lo" tcp dport '{ 80, 443 }' drop
systemctl start apache2
```

Verbindungen des Servers an seine eigenen Adressen laufen unter Linux über `lo` und sind damit lokal weiter möglich. Ist ein Listener nur an der externen Adresse gebunden, wird er lokal über diese Adresse angesprochen (z. B. `curl --resolve <name>:443:<adresse>`).

**Nachweis der Sperre** von einem anderen Rechner aus: `curl --max-time 5 https://<server>/` muss scheitern.

#### 7. Lokale Sicherheitsprüfung (Webserver nur lokal erreichbar)

Vom Server selbst, z. B. `curl -sk https://127.0.0.1/<ui-pfad>/login.php`:

- Anmeldung mit einem aktiven Admin-Konto (Zugangsdaten der durchführenden Person) gelingt.
- Für jedes Konto aus Schritt 3: Datensatz gelöscht bzw. `password_hash = '!gesperrt-s27'`; ein Anmeldeversuch schlägt fehl.
- Im Session-Speicher gibt es keine Session-Datei, die älter ist als der Zeitstempel aus Schritt 4.
- Optional: Ein vor dem Rückfall gesicherter Session-Cookie wird abgewiesen.
- Die übrigen Kanäle funktionieren lokal (z. B. Agent-Endpunkt).

Erst wenn alle Prüfungen erfolgreich waren: Sperre entfernen (`nft delete table inet dnsmgr_rollback`), externe Erreichbarkeit prüfen und angehaltene Zugänge wieder starten. Schlägt eine Prüfung fehl, Webserver anhalten, Sperre bestehen lassen und den Fehler klären.

#### Schema zurück (optional, nur nach Schritt 7)

```sql
ALTER TABLE users DROP COLUMN session_epoch, DROP COLUMN active;
```

Danach sind Deaktivierungen und Generationen verloren. Ein erneutes Update führt die Migration erneut aus; alle Konten sind dann zunächst aktiv.

**Vollständige Wiederherstellung** aus der Sicherung vor der Migration: nur, wenn seit der Migration keine anderen Änderungen erhalten bleiben müssen. Auch dann gelten die Schritte 1, 2 und 4–7.

Liefert Schritt 1c keine Konten, entfällt Schritt 3. Die Sessions werden trotzdem beendet (Schritt 4), weil der alte Code die Generation nicht kennt.

## 002 – Grundtabellen der Änderungshistorie (AP1/H2a)

| Datei | Zweck |
|---|---|
| `002_history_foundation.sql` | wiederholbares SQL (`CREATE TABLE IF NOT EXISTS` in Abhängigkeitsreihenfolge) |
| `../../scripts/migrate_002_history_foundation.sh` | Preflight, Sicherung, Ausführung, Nachprüfung |
| `../../web/scripts/schema_history-cli.php` | Strukturerwartung aus `SchemaCheck` für das Skript (ohne Datenbankzugriff) |
| `../../web/scripts/history_baseline-cli.php` | Baseline-Initialisierung bestehender Zonen (nach der Migration) |

Neue Tabellen (Architektur: [`docs/ARCHITECTURE_HISTORY.md`](../../docs/ARCHITECTURE_HISTORY.md)); bestehende Tabellen werden nicht verändert:

- `history_change_sets`: Klammer je Fachtransaktion (Operation, Akteurart, Akteur, Kanal, Korrelation).
- `zone_snapshots`: inhaltsadressierte, kanonische Zonen-Snapshots (Vertrag `zs1`, SHA-256 als Primärschlüssel).
- `zone_revisions`: unveränderliche Revisionen je Zone, eindeutig über `(zone_id, revision_no)`.
- `zone_history_state`: Head je Zone (`tracked` / `baseline_unverified`).

Keine Fremdschlüssel auf Fachtabellen (die Historie gelöschter Zonen bleibt erhalten), Fremdschlüssel innerhalb der
Historie ohne Cascade, keine Serial-Spalten (E15/E16 offen).

### Verbindliche Reihenfolge

1. **Sicherung und Migration** (Code noch alt).
2. **Prüfung** `--check` → Exitcode 0.
3. **Code** ausrollen (per festem SHA).
4. **Baseline** `history_baseline-cli.php --dry-run`, danach `--apply`; Kontrolle mit `--check`.

Der alte Code bleibt mit dem erweiterten Schema lauffähig: Er liest und schreibt die neuen Tabellen nicht. Der neue Code
mit altem Schema antwortet bei Login und geschützten Seiten mit 503 (`SchemaCheck`, fail-closed; Log
`event=schema_incompatible`). Sessions bleiben gültig. Die Baseline (Schritt 4) ist keine Voraussetzung für den Betrieb
von H2a: Der Code nutzt die Historie zur Laufzeit noch nicht.

### Ablauf

```bash
# 1. Preflight (ändert nichts)
bash scripts/migrate_002_history_foundation.sh --db dnsmanager --check
#    Exit 1 = Migration erforderlich, 0 = vollständig vorhanden, 2 = abweichende Struktur (manuell klären)

# 2. Migration mit Sicherung (vollständiger mysqldump, Rechte 600 in einem 700-Verzeichnis).
#    --no-backup ist nur für isolierte Testdatenbanken (dnsmgr_tNN_*) zulässig.
bash scripts/migrate_002_history_foundation.sh --db dnsmanager --apply --backup-dir /root/dnsmanager-backup

# 3. Kontrolle
bash scripts/migrate_002_history_foundation.sh --db dnsmanager --check

# 4. Nach dem Code-Update: Baseline (Konfiguration und Rechte der Anwendung, z. B. als www-data oder root)
php web/scripts/history_baseline-cli.php --dry-run
php web/scripts/history_baseline-cli.php --apply
php web/scripts/history_baseline-cli.php --check     # Exit 0 = alle Zonen haben einen History-Head
```

- **Preflight:** MariaDB ab 10.6 (atomares DDL), Datenbank und Fachtabellen vorhanden, `zones.id` vom Typ `int(11)`.
  Danach werden **alle vier** History-Tabellen vollständig gegen die Erwartung aus `web/inc/app/SchemaCheck.php`
  geprüft (Tabellenart, Engine, Spalten mit Typ, NULL, Standardwert, Zeichensatz und Sortierung, Indizes,
  Fremdschlüssel mit Regeln, CHECK-Bedingungen mit Name und normalisiertem Ausdruck) – **bevor** eine DDL-Anweisung ausgeführt wird. Weicht eine
  vorhandene Tabelle ab, bricht das Skript **ohne Änderung** ab (Exit 2) und nennt die erste Abweichung.
- **Nachprüfung:** alle vier Tabellen exakt wie erwartet; Zeilenzahl bereits vorhandener History-Tabellen unverändert.
  Die Prüfsummen der Fachtabellen (`CHECKSUM TABLE … EXTENDED`) werden vorher und nachher verglichen und nur
  **informativ** ausgegeben: Die Migration schreibt keine Fachtabellen; eine Abweichung im laufenden Betrieb stammt von
  einem unabhängigen Schreibvorgang (Oberfläche, DynDNS, Publish) und wird nicht der Migration zugerechnet.
- **Baseline:** je Zone ohne Head eine Revision `baseline` mit Status `baseline_unverified`, unter dem Advisory-Lock
  `dnsmanager_publish_all` (Try-Lock; belegt ⇒ Abbruch ohne Änderung, Exit 2) und je Zone in einer eigenen Transaktion
  unter der Zonensperre. Es wird **keine** frühere Historie konstruiert und **kein** Veröffentlichungsstand angenommen:
  Ein ausstehender Stand (`zones.changed ≠ 0`) bleibt ausstehend. Fachdaten, Serials, `changed`, `bind_dirty` und
  Agenten bleiben unverändert.

### Wiederanlauf

- Die Migration ist wiederholbar. Sind alle vier Tabellen korrekt vorhanden, meldet `--apply` „Nichts zu tun“ (Exit 0).
- **Technischer Abbruch während der DDL** (Exit 3): Jede `CREATE TABLE`-Anweisung ist für sich atomar; bereits
  angelegte Tabellen bleiben bestehen. Das Skript erneut ausführen: Der Preflight prüft die vorhandenen Tabellen
  vollständig und legt nur die fehlenden an (deterministisch, Ausgabe „Wiederanlauf: …“).
- Wurde eine Tabelle außerhalb des Skripts abweichend angelegt (Exit 2), zuerst klären; das Skript ändert vorhandene
  Tabellen bewusst nicht.
- Baseline: Ein Abbruch rollt die laufende Zone vollständig zurück; abgeschlossene Zonen bleiben. Ein erneuter Lauf
  überspringt Zonen mit Head und setzt fort.

### Rückweg

**Ein Code-Rollback löscht keine History-Daten.** Das neue Schema bleibt bei einer Rückkehr zum bisherigen
Anwendungscode bestehen: Der alte Code liest und schreibt die History-Tabellen nicht, es gibt keine Fremdschlüssel auf
Fachtabellen, Sessions bleiben gültig.

1. Code per festem SHA zurücksetzen (`git checkout -q --detach <alter-SHA>`, danach `rev-parse HEAD HEAD^{tree}`).
2. Funktionsprüfung (Login, Zonen, Publish).

Fachänderungen, die der alte Code nach dem Rollback vornimmt, werden nicht historisiert. Mit der späteren
Recorder-Integration (H2b) erkennt die Vorher-Prüfung (R-H3) solche Abweichungen als Revision `drift`.

**Entfernen der Tabellen** ist eine **datenverlustbehaftete Sondermaßnahme**: nur nach ausdrücklicher Freigabe, nur
nach vorheriger vollständiger Sicherung, regulär nur für isolierte Testdatenbanken. Reihenfolge wegen der
Fremdschlüssel:

```sql
-- Nur nach Freigabe und Sicherung (mysqldump der Datenbank inkl. History-Tabellen)
DROP TABLE zone_history_state;
DROP TABLE zone_revisions;
DROP TABLE zone_snapshots;
DROP TABLE history_change_sets;
```

Danach verweigert ein Code ab H2a den Dienst (503), bis die Migration erneut ausgeführt wurde.

## 003 – Zonenübergreifende Abhängigkeiten der Historie (AP1/H2b)

| Datei | Zweck |
|---|---|
| `003_history_dependencies.sql` | wiederholbares SQL (`CREATE TABLE IF NOT EXISTS`, atomar) |
| `../../scripts/migrate_003_history_dependencies.sh` | Preflight, Sicherung, Ausführung, Nachprüfung (Muster und Exitcodes wie 002) |

Neue Tabelle `history_dependencies` (E37): Ein Record einer Änderungsmenge (AutoPTR in der Reverse-Zone, T2) hängt von
einem Record einer früheren Änderungsmenge ab (Forward-Record, T1). Schlüssel ist der Inhalt `(name, type, content)`
wie gespeichert, nie die Record-ID. Fremdschlüssel nur auf `history_change_sets` und `zone_revisions (zone_id,
revision_no)`, alle `RESTRICT`; keine auf Fachtabellen. Bestehende Tabellen werden nicht verändert.

### Verbindliche Reihenfolge

1. **Sicherung und Migration 003** (Code noch alt; Migration 002 muss vorhanden und unverändert sein).
2. **Prüfung** `--check` → Exitcode 0.
3. **Code** ausrollen (per festem SHA). Ab diesem Stand schreiben alle DNS-relevanten Fachänderungen Historie (H2b).
4. **Abgleich** `history_baseline-cli.php --check` (Zonen ohne Head; der Recorder legt sie bei Bedarf selbst an) und
   `--reconcile-dry-run` / `--reconcile` (Heads von Zonen, die zwischen Baseline und Code-Update gelöscht wurden).

Der alte Code bleibt mit dem erweiterten Schema lauffähig (er liest und schreibt die Tabelle nicht). Der neue Code mit
altem Schema antwortet bei Login und geschützten Seiten mit 503 (`SchemaCheck`, fail-closed). Änderungen, die zwischen
H2a-Baseline und Code-Update mit altem Code vorgenommen wurden, erkennt der Recorder bei der nächsten Fachänderung der
Zone als Revision `drift` (Akteur `system`); sie werden nie dem nächsten Benutzer zugeschrieben.

### Ablauf

```bash
bash scripts/migrate_003_history_dependencies.sh --db dnsmanager --check
#    Exit 1 = Migration erforderlich, 0 = vorhanden, 2 = Migration 002 fehlt/abweichend oder Tabelle abweichend
bash scripts/migrate_003_history_dependencies.sh --db dnsmanager --apply --backup-dir /root/dnsmanager-backup
bash scripts/migrate_003_history_dependencies.sh --db dnsmanager --check

# nach dem Code-Update
php web/scripts/history_baseline-cli.php --check
php web/scripts/history_baseline-cli.php --reconcile-dry-run
php web/scripts/history_baseline-cli.php --reconcile
```

- **Preflight:** wie 002; zusätzlich müssen die vier Grundtabellen aus 002 exakt der Erwartung entsprechen
  (Voraussetzung der Fremdschlüssel). Eine vorhandene, abweichende `history_dependencies` ⇒ Exit 2 ohne Änderung.
- **Nachprüfung:** alle fünf History-Tabellen exakt wie erwartet; Zeilenzahl der Grundtabellen unverändert.
- **Wiederanlauf:** Die Anweisung ist atomar; nach einem Abbruch (Exit 3) das Skript erneut ausführen.
- **Rückweg:** Code-Rollback auf H2a ohne Löschen der Tabelle (E27); der H2a-Code nutzt sie nicht. Nach einem erneuten
  Code-Update erkennt der Recorder zwischenzeitliche Änderungen als Drift, neue Zonen erhalten eine Lazy-Baseline,
  gelöschte Zonen kennzeichnet `--reconcile`. `DROP TABLE` nur als freigegebene, datenverlustbehaftete Sondermaßnahme
  mit Sicherung.

## 004 – Fachliche Audit-Ereignisse (AP1/H2c)

| Datei | Zweck |
|---|---|
| `004_audit_events.sql` | wiederholbares SQL (`CREATE TABLE IF NOT EXISTS`, atomar) |
| `../../scripts/migrate_004_audit_events.sh` | Preflight, Sicherung, Ausführung, Nachprüfung (Muster und Exitcodes wie 003) |

Neue Tabelle `audit_events` (E45): fachliche Verwaltungsvorgänge (Zonenmetadaten, Server inkl. BIND-Reload,
DynDNS-Konten, Benutzer und Zuweisungen) je Änderungsmenge mit fortlaufender `seq` (NOT NULL, eindeutig je
Änderungsmenge). Inhalte nur über die Whitelist der `AuditPolicy`; keine Passwörter, Hashes, Tokens,
DynDNS-Kontonamen oder Client-IPs. CHECK-Bedingungen NULL-sicher (MariaDB nimmt UNKNOWN als erfüllt an).
Fremdschlüssel nur auf `history_change_sets` (`RESTRICT`); keine auf Fachtabellen. Bestehende Tabellen werden nicht
verändert. Migration 003 ist keine Voraussetzung (nur 002), wird aber in der Reihenfolge 002 → 003 → 004 erwartet.

### Verbindliche Reihenfolge

1. **Sicherung und Migration 004** (Code noch alt; Migration 002 muss vorhanden und unverändert sein).
2. **Prüfung** `--check` → Exitcode 0.
3. **Code** ausrollen (per festem SHA). Ab diesem Stand schreiben alle Verwaltungsvorgänge Audit-Ereignisse (H2c).

Der alte Code bleibt mit dem erweiterten Schema lauffähig (er liest und schreibt die Tabelle nicht). Der neue Code mit
altem Schema antwortet bei Login und geschützten Seiten mit 503 (`SchemaCheck`, fail-closed).

### Ablauf

```bash
bash scripts/migrate_004_audit_events.sh --db dnsmanager --check
#    Exit 1 = Migration erforderlich, 0 = vorhanden, 2 = Migration 002 fehlt/abweichend oder Tabelle abweichend
bash scripts/migrate_004_audit_events.sh --db dnsmanager --apply --backup-dir /root/dnsmanager-backup
bash scripts/migrate_004_audit_events.sh --db dnsmanager --check
```

- **Preflight:** wie 003; die vier Grundtabellen aus 002 müssen exakt der Erwartung entsprechen. Eine vorhandene,
  abweichende `audit_events` (auch ein gleichnamiger CHECK mit anderem Ausdruck) ⇒ Exit 2 ohne Änderung.
- **Wiederanlauf:** Die Anweisung ist atomar; nach einem Abbruch (Exit 3) das Skript erneut ausführen.
- **Rückweg:** Code-Rollback auf H2b ohne Löschen der Tabelle (E27); der H2b-Code nutzt sie nicht. `DROP TABLE` nur als
  freigegebene, datenverlustbehaftete Sondermaßnahme mit Sicherung.

## 005 – Publish-Journal (AP1/H2d)

| Datei | Zweck |
|---|---|
| `005_publish_journal.sql` | wiederholbares SQL (`CREATE TABLE IF NOT EXISTS`, je Tabelle atomar) |
| `../../scripts/migrate_005_publish_journal.sh` | Preflight, Sicherung, Ausführung, Nachprüfung (Muster und Exitcodes wie 004) |

Neue Tabellen (E55/E56): `publish_runs` (Lauf unter dem Publish-Lock mit Akteurskontext, künftiger Fencing-Token),
`zone_publish_attempts` (erzeugter Zonenstand: Revision, `content_hash`, `render_hash`, `file_hash`, Serial),
`zone_publish_targets` (Ergebnis je Zielserver für `zone_sync` und `conf_sync`), `publish_cleanup_results` (Ergebnis des
Orphan-Cleanups je Server, nur Zähler). CHECK-Bedingungen NULL-sicher; Fremdschlüssel nur auf `publish_runs`,
`zone_publish_attempts` und `zone_revisions` (`RESTRICT`), keine auf Fachtabellen; append-only. Keine Agent-Felder vor AS.
Bestehende Tabellen werden nicht verändert; `deployments` bleibt unberührt. Voraussetzung: Migration 002 (erwartete
Reihenfolge 002 → 003 → 004 → 005).

### Verbindliche Reihenfolge

1. **Sicherung und Migration 005** (Code noch alt; Migration 002 muss vorhanden und unverändert sein).
2. **Prüfung** `--check` → Exitcode 0.
3. **Code** ausrollen (per festem SHA). Ab diesem Stand registriert jeder Publish-Lauf das Journal (H2d).

Der alte Code bleibt mit dem erweiterten Schema lauffähig (er liest und schreibt die Tabellen nicht). Der neue Code mit
altem Schema antwortet bei Login und geschützten Seiten mit 503 (`SchemaCheck`, fail-closed); Publish und DynDNS-Publish
enden ohne Seiteneffekt mit dem Status `exception` (Laufregistrierung J1 scheitert).

### Ablauf

```bash
bash scripts/migrate_005_publish_journal.sh --db dnsmanager --check
#    Exit 1 = Migration erforderlich, 0 = vorhanden, 2 = Migration 002 fehlt/abweichend oder Tabelle abweichend
bash scripts/migrate_005_publish_journal.sh --db dnsmanager --apply --backup-dir /root/dnsmanager-backup
bash scripts/migrate_005_publish_journal.sh --db dnsmanager --check
```

- **Preflight:** die vier Grundtabellen aus 002 exakt wie erwartet; jede vorhandene Journaltabelle vollständig wie
  erwartet (auch ein gleichnamiger CHECK mit anderem Ausdruck oder eine andere FK-Regel ⇒ Exit 2 ohne Änderung).
- **Wiederanlauf:** Jede Anweisung ist atomar; nach einem Abbruch (Exit 3) sind einzelne Tabellen vollständig angelegt,
  das erneut ausgeführte Skript ergänzt die fehlenden.
- **Rückweg:** Code-Rollback auf H2c ohne Löschen der Tabellen (E27); der H2c-Code nutzt sie nicht. `DROP TABLE` nur als
  freigegebene, datenverlustbehaftete Sondermaßnahme mit Sicherung.

## 006 – Serial ohne Vorzeichen und ausgelieferter Stand je Zone und Server (AS3)

| Datei | Zweck |
|---|---|
| `006a_zone_server_verification.sql` | Schritt a: `CREATE TABLE IF NOT EXISTS zone_server_verification` (atomar) |
| `006b_zones_soa_serial_unsigned.sql` | Schritt b: `ALTER TABLE zones MODIFY soa_serial INT UNSIGNED NOT NULL DEFAULT 2025010101, ALGORITHM=COPY, LOCK=SHARED` (atomar) |
| `../../scripts/migrate_006_zone_serial_verification.sh` | Preflight, Sicherung, geschützte Sitzung unter dem Publish-Lock, Nachprüfung |

Die SQL-Dateien werden **nur über das Skript** ausgeführt (Lock, Sitzungsparameter und Prüfungen gehören dazu).
E79: `zones.soa_serial` wird `INT UNSIGNED` (Wertebereich 0 … 4294967295, RFC 1982). E87: neue, retentionsfeste
Tabelle `zone_server_verification` (letzte Beobachtung und letzter positiver Nachweis je Zone und Server, ohne
Fremdschlüssel; Datenvertrag in `docs/ARCHITECTURE_HISTORY.md`, Abschnitt 5h). Keine anderen Tabellen werden verändert.
Voraussetzung: Migrationen 002–005 exakt wie erwartet (Reihenfolge 002 → 003 → 004 → 005 → 006).

`generate_next_serial()`, Generator, Publish und Historie bleiben unverändert (Serial-Strategie AS5). Bis AS5 endet eine
Veröffentlichung mit Serial 4294967295 wie bisher bei 2147483647 mit dem Status `error` (Wertebereich der Spalte;
Rollback, keine Datei, keine Serial verbraucht). Die Tabelle wird bis AS4 weder gelesen noch geschrieben.

### Verbindliche Reihenfolge und Rollout-Voraussetzungen

1. **Ruhiges Zeitfenster** wählen: keine Veröffentlichung, keine Verwaltungsänderungen, keine DynDNS-Updates erwartet.
   Der Publish-Lock `dnsmanager_publish_all` schließt nur Publish-Läufe aus, **nicht** andere Fachtransaktionen
   (Zonen-, Record-, Server- und DynDNS-Änderungen). Diese halten während ihrer Transaktion eine Metadatensperre auf
   `zones`; das `ALTER` wartet darauf höchstens `lock_wait_timeout` = 10 s und bricht dann mit Exit 3 ab (Wiederanlauf
   möglich). Während der Tabellenkopie (`LOCK=SHARED`) bleiben Lesezugriffe möglich, schreibende Zugriffe auf `zones`
   warten, bis die Kopie abgeschlossen ist (Dauer proportional zur Zonenzahl).
2. **Sicherung und Migration 006** (Code noch alt).
3. **Prüfung** `--check` → Exitcode 0.
4. **Code** ausrollen (per festem SHA).

Der alte Code bleibt mit dem neuen Schema lauffähig (sein `SchemaCheck` prüft weder die neue Tabelle noch den Typ der
Spalte; er schreibt Serials über 2147483647 korrekt; nachgewiesen in `tests/history/as3_test.php`, Gruppe K). Der neue
Code mit altem Schema antwortet bei Login und geschützten Seiten mit 503 (`SchemaCheck`, fail-closed:
„zones.soa_serial: Typ int(11) statt int(10) unsigned“, „zone_server_verification fehlt“).

### Ablauf

```bash
bash scripts/migrate_006_zone_serial_verification.sh --db dnsmanager --check
#    Exit 1 = Migration erforderlich, 0 = vollständig, 2 = 002–005 fehlen/abweichend, Struktur abweichend oder negative Serial
bash scripts/migrate_006_zone_serial_verification.sh --db dnsmanager --apply --backup-dir /root/dnsmanager-backup
bash scripts/migrate_006_zone_serial_verification.sh --db dnsmanager --check
```

- **Preflight ohne Lock:** 002–005 exakt wie erwartet; `zone_server_verification` fehlt oder entspricht exakt der
  Erwartung (Spalten, Indizes, CHECKs mit normalisiertem Ausdruck; jede Abweichung ⇒ Exit 2); `zones.soa_serial` ist
  `int(11) NOT NULL DEFAULT 2025010101` (alt) oder `int(10) unsigned NOT NULL DEFAULT 2025010101` (neu), sonst Exit 2;
  keine negativen Serials (sonst Exit 2, manuell klären). Daraus der Plan: nur die fehlenden Schritte a und/oder b.
  Vollständig migriert ⇒ „Nichts zu tun“, keine DDL, keine geschützte Sitzung.
- **Geschützte Sitzung (eine Verbindung):** `sql_mode` strikt und `lock_wait_timeout = 10` auf genau der Verbindung,
  die die DDL ausführt; `GET_LOCK('dnsmanager_publish_all', 0)` mit Bestätigung (Rückgabe 1 und
  `IS_USED_LOCK() = CONNECTION_ID()`), belegter Lock ⇒ Exit 2 ohne DDL; Preflight unter dem Lock (geplanter
  Ausgangszustand unverändert, keine negativen Werte; sonst Exit 2 ohne DDL); Kennwerte vorher (Zeilenzahl, Summe,
  Minimum, Maximum, XOR-Prüfsumme über `id:soa_serial`); Lock-Besitz vor und nach jeder DDL bestätigt (verloren ⇒
  Abbruch, Exit 3); Ergebnis jedes Schritts in derselben Sitzung geprüft; die SQL-Dateien werden in die Sitzung
  eingebettet, damit jeder DDL-Fehler die Sitzung sofort beendet; Kennwerte nachher; `RELEASE_LOCK` (Rückgabe 1 geprüft). Endet die Sitzung vorzeitig, gibt der
  Server den Lock mit dem Verbindungsende frei. Fremdschlüssel- und CHECK-Prüfungen werden nie abgeschaltet.
- **Nachprüfung:** vollständige Struktur über `SchemaCheck`; Kennwerte vorher = nachher (sonst Exit 3).
- **Teilzustände und Wiederanlauf:** Jede DDL-Anweisung ist atomar. Mögliche Zustände: nichts geändert; Tabelle
  angelegt, Spalte noch mit Vorzeichen (Abbruch in Schritt b, z. B. Metadatensperre); Spalte umgestellt, Tabelle fehlt
  (nur nach manuellem Eingriff). Das Skript meldet bei Exit 3 den vorgefundenen Zustand; erneut ausgeführt führt es
  nur den fehlenden Schritt aus. Ein gleichzeitiges Anlegen oder Löschen einer Zone zwischen Kennwert-Erhebung und
  `ALTER` führt zu einem vorsichtigen Exit 3 (Kennwerte ungleich) bei vollständiger Migration; `--check` zeigt dann 0.
- **Rückweg:** Code-Rollback auf den Stand vor AS3 ohne Schemaänderung (alter Code ist mit dem neuen Schema lauffähig).
  Ein Rückbau des Schemas ist nur bei leerer `zone_server_verification` und `MAX(soa_serial) ≤ 2147483647` möglich und
  nur als freigegebene Sondermaßnahme mit Sicherung, Publish-Lock und ruhigem Zeitfenster:

```sql
SELECT GET_LOCK('dnsmanager_publish_all', 0);
SELECT COUNT(*) FROM zone_server_verification;                 -- muss 0 sein
SELECT MAX(soa_serial) FROM zones;                             -- muss ≤ 2147483647 sein
SET SESSION sql_mode = 'STRICT_ALL_TABLES', SESSION lock_wait_timeout = 10;
ALTER TABLE zones MODIFY soa_serial INT NOT NULL DEFAULT 2025010101, ALGORITHM=COPY, LOCK=SHARED;
DROP TABLE zone_server_verification;
SELECT RELEASE_LOCK('dnsmanager_publish_all');
```

  Bei größeren Serials scheitert das `ALTER` im strikten Modus ohne Änderung (nachgewiesen in Q16).
