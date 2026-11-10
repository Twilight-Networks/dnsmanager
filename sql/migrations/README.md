# Datenbankmigrationen

Migrationen für bestehende Installationen. Neuinstallationen verwenden `sql/dnsmanager_schema.sql`, das den jeweils aktuellen Stand bereits enthält.

Der Webcode nimmt **niemals** selbst Schemaänderungen vor. Fehlt eine vorausgesetzte Spalte oder weicht sie ab, verweigern Login und geschützte Seiten den Dienst mit HTTP 503 (Fail-Closed, `SchemaCheck`) und protokollieren `event=schema_incompatible`.

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
