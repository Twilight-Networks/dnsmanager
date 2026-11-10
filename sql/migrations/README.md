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

# 2. Migration mit Sicherung (vollständiger mysqldump, Rechte 600 in einem 700-Verzeichnis)
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

- **Nur Code zurück** (empfohlen): Der alte Code arbeitet mit dem erweiterten Schema. Die Spalten bleiben stehen; ein späteres erneutes Code-Update nutzt sie weiter. Achtung: Unter altem Code gelten deaktivierte Konten wieder als aktiv, weil der alte Code `active` nicht kennt.
- **Schema zurück** (nur nach dem Code-Rollback):

  ```sql
  ALTER TABLE users DROP COLUMN session_epoch, DROP COLUMN active;
  ```

  Dabei gehen Deaktivierungen und Generationen verloren.
- **Vollständige Wiederherstellung** aus der Sicherung (`mysql dnsmanager < <sicherung>.sql`): nur, wenn seit der Migration keine anderen Änderungen erhalten bleiben müssen.
