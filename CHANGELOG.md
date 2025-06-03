# Changelog

Alle Änderungen am Projekt werden in diesem Dokument nach semantischer Versionierung (semver.org) festgehalten.

## [1.1.0] – 2025-05-31

### Added
- Neues Mailer-Modul für das Monitoring:
  - Versand von E-Mail-Benachrichtigungen bei Statuswechseln (`ok` ↔ `error`, `ok` ↔ `warning`, etc.)
  - Dynamische Betreffzeile: z. B. `[DNSManager] Zone: example.com Status: error`
  - HTML-E-Mail-Ausgabe mit UTF-8 und formatiertem `<pre>`-Block für bessere Lesbarkeit
  - Konfigurierbar über bestehende `ui_config.php`:
    - `MAILER_ENABLED`, `MAILER_USE_SMTP`, `MAILER_SMTP_HOST`, usw.
  - Unterstützt SMTP (via PHPMailer) oder fallback auf `mail()`
  - Einbindung erfolgt im CLI-Skript `monitoring_run-cli.php` über `sendDiagnosticAlerts()`

- Schaltfläche **„Status jetzt aktualisieren“** im Bereich *Systemstatus* (`system_health.php`)
  - Führt `monitoring_run-cli.php` synchron aus und aktualisiert die Datenbank sofort
  - Ergebnis wird direkt im Webinterface angezeigt
  - Bei Fehlern (z. B. im Mailversand) erscheint eine Toast-Meldung im Frontend

### Changed
- Datenbankschema erweitert:
  - Neue Spalte `notified BOOLEAN NOT NULL DEFAULT 0` in Tabelle `diagnostic_log`
  - Wird verwendet, um zu erkennen, ob für einen Statuswechsel bereits eine Benachrichtigung erfolgt ist

### Fixed
- Cronjob-Ausgabe für `www-data` führte zu Mailfehlern (`sendmail: Recipient address rejected`)
  - Lösung: Standardausgaben der CLI-Skripte werden im Cronjob nach `/dev/null` umgeleitet

### Migration Notes
Nach dem Schema-Update **müssen alle bisherigen Einträge als "benachrichtigt" markiert werden**, um Massenversand zu verhindern:

```sql
ALTER TABLE diagnostic_log ADD COLUMN notified BOOLEAN NOT NULL DEFAULT 0;
UPDATE diagnostic_log SET notified = 1;
```

<br>
<br>

## [1.2.0] – 2025-06-01

### Added
- **DynDNS-Unterstützung im gesamten System integriert:**
  - Neue Datenbanktabelle `dyndns_accounts`:
    - Felder: `username`, `password_hash`, `zone_id`, `hostname`, `current_ipv4`, `current_ipv6`, `last_update`, `created_at`, `updated_at`
    - Fremdschlüssel auf `zones(id)` mit `ON DELETE CASCADE`
    - Index auf `(zone_id, hostname)` für schnelle Updates
  - `zones`-Tabelle erweitert um `allow_dyndns` (BOOLEAN)
    - Nur Zonen mit gesetzter Option stehen für DynDNS zur Verfügung
  - Neues Webinterface `dyndns.php` zur Verwaltung:
    - Anlage, Bearbeitung, Löschung von DynDNS-Accounts
    - Passwörter ändern über Modal
    - Formularlogik vollständig analog zu `servers.php` (Bearbeitungszeile + Inline-Form)
  - DynDNS-API-Endpunkt unter `/web/api/v1/dyndns/update.php`:
    - Authentifizierung per Benutzername/Passwort (kein Token)
    - Unterstützung für IPv4/IPv6 (einzeln oder kombiniert)
    - Bei erfolgreichem Update: Rückmeldung mit `good`, `nochg` oder `dnserr`
    - Automatische Erstellung oder Aktualisierung von A-/AAAA-Records
  - Verbesserte Änderungslogik bei Benutzer-, DynDNS- Zonen- und Record-Updates:
    - Änderungen werden nur gespeichert, wenn sich tatsächlich Werte geändert haben
    - Bei unveränderten Formularen erfolgt eine Rückmeldung: „Keine Änderungen vorgenommen“

### Changed
- Erweiterung des globalen Schemas (`schema.sql`):
  - Neue Tabelle `dyndns_accounts` (siehe oben)
  - Spalte `allow_dyndns TINYINT(1) NOT NULL DEFAULT 0` in `zones` eingefügt
- Admins erhalten in `zones.php` und `dyndns.php` neue Steuer- und Filterfunktionen für DynDNS-Accounts
- Fehlerausgaben bei Passwortänderung zeigen jetzt auch den Benutzernamen statt nur die Account-ID

### Migration Notes
Nach dem Update müssen Schema-Änderungen durchgeführt werden:

```sql
ALTER TABLE zones ADD COLUMN allow_dyndns TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE dyndns_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    zone_id INT NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    current_ipv4 VARCHAR(45) DEFAULT NULL,
    current_ipv6 VARCHAR(45) DEFAULT NULL,
    last_update DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dyndns_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    INDEX idx_zone_hostname (zone_id, hostname)
);
```
