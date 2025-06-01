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
