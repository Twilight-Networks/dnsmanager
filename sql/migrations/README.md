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

Der alte Code bleibt mit dem erweiterten Schema voll lauffähig: Er liest die neuen Spalten nicht und legt Benutzer über die Standardwerte aktiv an. Der neue Code mit altem Schema antwortet bei Login und geschützten Seiten mit 503.

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
2. **Widerrufene Sessions könnten wieder gültig werden.** Der neue Code widerruft Sessions bei Deaktivierung und Passwortänderung erst bei ihrer nächsten Anfrage. Eine Session-Datei, die seitdem nicht mehr benutzt wurde, enthält noch Benutzer-ID, Name und Rolle. Der alte Code würde sie wieder annehmen, weil er die Generation nicht prüft.

Es gibt deshalb keine automatische Änderung von Konten. Vorrang hat ein **Fehlerbehebungs-Update (Roll-forward)** auf dem neuen Code. Ist ein Rückfall dennoch nötig, gilt der folgende Ablauf. Alter Code darf erst wieder erreichbar sein, wenn die Schritte 1–5 abgeschlossen und geprüft sind.

1. **Dienst vom Netz nehmen:** Webserver der Benutzeroberfläche anhalten bzw. sperren, z. B. `systemctl stop apache2`.
   - Hinweis: Damit pausieren auch die DynDNS-API und die Agent-Endpunkte dieses Webservers.
2. **Kontosperren erfassen** (nur lesend):

   ```sql
   SELECT id, username, role FROM users WHERE active = 0 ORDER BY id;
   ```

   Ergebnis und Sicherung (`mysqldump`) ablegen, Rechte 600.
3. **Wirkung der Sperren ohne die Spalte `active` sicherstellen.** Das ist nur nötig, wenn Schritt 2 Konten liefert. Für jedes dort aufgeführte Konto entscheidet die verantwortliche Person ausdrücklich und dokumentiert:
   - **Löschen** über den bisherigen Weg (alter Code bzw. `DELETE`), oder
   - **Anmeldung unmöglich machen**, indem das Passwort durch einen nicht verifizierbaren Wert ersetzt wird:

     ```sql
     UPDATE users SET password_hash = '!gesperrt-s27' WHERE id IN (<ids aus Schritt 2>) AND active = 0;
     ```

     `password_verify()` schlägt damit immer fehl. Der ursprüngliche Hash bleibt in der Sicherung aus Schritt 2. Bei einem späteren Roll-forward ist das Konto über einen Passwort-Reset durch einen Admin wiederherstellbar.
   - Der letzte aktive Admin ist nie betroffen; nur Konten mit `active = 0` werden behandelt.
4. **Alle bestehenden Sessions beenden:** den Session-Speicher bei angehaltenem Webserver leeren, z. B. `find /var/lib/php/sessions -name 'sess_*' -delete`. Alle Benutzer melden sich danach neu an.
5. **Code zurücksetzen**, ausschließlich per festem SHA:

   ```bash
   git -C /usr/share/dnsmanager checkout -q --detach <alter-SHA>
   ```

   Commit und Tree prüfen.
6. **Prüfen vor Freigabe:**
   - Login eines aktiven Kontos funktioniert.
   - Ein in Schritt 3 behandeltes Konto kann sich nicht anmelden.
   - Es gibt keine Session-Dateien aus der Zeit vor Schritt 4.
   - Erst danach den Webserver wieder starten.

**Schema zurück** (optional, nur nach den Schritten 1–6):

```sql
ALTER TABLE users DROP COLUMN session_epoch, DROP COLUMN active;
```

Danach sind Deaktivierungen und Generationen verloren. Ein erneutes Update führt die Migration erneut aus; alle Konten sind dann zunächst aktiv.

**Vollständige Wiederherstellung** aus der Sicherung vor der Migration (`mariadb dnsmanager < <sicherung>.sql`): nur, wenn seit der Migration keine anderen Änderungen erhalten bleiben müssen. Die Schritte 1, 4 und 6 gelten auch hier: Sessions beenden und vor der Freigabe prüfen.

Solange `active = 0` bei keinem Konto vorkommt und keine Passwortänderung nach der Migration erfolgte, reduziert sich der Ablauf auf die Schritte 1, 4, 5 und 6. Die Sessions werden trotzdem beendet, weil der alte Code die Generation nicht kennt.
