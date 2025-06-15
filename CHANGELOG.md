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

<br>
<br>

## [1.2.1] – 2025-06-03

### Fixed
- **Benutzer bearbeiten**: Änderungen wurden nicht gespeichert
  - Ursache: fehlende `id="editForm_<id>"` im Formular (`user_edit_form.php`)
  - Folge: Klick auf „Speichern“ hatte keine Wirkung
  - Lösung: Korrektes `form`-Ziel gesetzt

<br>
<br>

## [1.2.2] – 2025-06-05

### Added
- **Login-Layout**: Einheitliche Schriftart jetzt auch auf der Login-Seite eingebunden
  - `fonts.css` wird im Login-Template manuell mit Cache-Busting (`filemtime()`) geladen
  - Vermeidet Session-Abhängigkeit und sorgt für konsistente Typografie im gesamten Interface

### Changed
- **DynDNS-Update-Logik erweitert**
  - Bei Änderung von `hostname` oder `zone` wird jetzt automatisch:
    - der alte A-/AAAA-Record gelöscht
    - ein neuer Record mit der gespeicherten IP-Adresse erzeugt (sofern vorhanden)
    - die betroffenen Zonen per `rebuild_zone_and_flag_if_valid()` neu aufgebaut
  - Kein Rebuild mehr, wenn weder IPv4 noch IPv6 hinterlegt ist
  - Rückmeldung im UI differenziert je nach Rebuild-Status
  - Führt zu höherer Konsistenz zwischen DynDNS-Datenbank und DNS-Zonen
  - **API-Endpunkt `/api/v1/dyndns/update.php` überarbeitet:**
    - Authentifizierung nun ausschließlich über Benutzername & Passwort
    - Hostname und Zone werden vollständig aus dem Account geladen
    - `hostname`-Parameter ist nicht mehr erforderlich (wird ignoriert)

### Fixed
- **Fail2Ban Jail**: Falscher `logpath` im Jail `dnsmanager.local` korrigiert
  - Ursprünglich: `/var/log/syslog`
  - Neu: `logpath = /var/log/auth.log`
  - Folge: Jail war ggf. wirkungslos, da keine Logdateien überwacht wurden

- **Benutzerverwaltung**: Bearbeiten und Anlegen von Benutzern konnte zu Fehlern führen
  - Beim Bearbeiten: PHP-Fehler durch versehentlich überschriebenes `$zones`-Array
  - Beim Anlegen: Dropdown für Zonen blieb leer bei Auswahl von `zoneadmin`
  - Ursache: uneinheitliche Nutzung von `$zones` vs. `$allZones` im Template-Kontext
  - Beides wurde vereinheitlicht durch globale Verwendung von `$zones`
  - Resultat: Stabile Anzeige & Funktion der Zonenlisten bei Benutzerverwaltung

### Security
- **CLI-Zugriffsschutz** für interne Skripte `monitoring_run-cli.php` und `monitoring_log_cleanup.php` implementiert
  - Direkter Aufruf über den Browser (HTTP) ist nun vollständig blockiert (HTTP 403)
  - Skripte reagieren nur noch auf legitime Ausführung über die Kommandozeile (z. B. per Cron)

<br>
<br>

## [1.2.3] – 2025-06-14

### Added
- **Mehrsprachigkeit (i18n) vollständig integriert**
  - Alle UI-Texte wurden aus dem Quellcode extrahiert und durch sprachabhängige `$LANG`-Einträge ersetzt
  - Neue Sprachdateien `lang/de.php`, `lang/en.php` und `lang/es.php`
  - Vollständige Übersetzung für Deutsch, Englisch und Spanisch
  - Dynamische Inhalte (z. B. mit `sprintf()`, Validierungstexte, Platzhalter etc.) werden korrekt lokalisiert
  - Sprachumschaltung erfolgt über `ui_config.php` (`LANGUAGE = 'de'|'en'|'es'`)
  - Alternativ kann in der `ui_config.php` `DEFAULT_LANGUAGE = 'auto'` gesetzt werden – die Sprache richtet sich dann automatisch nach der Browsereinstellung
  - Einheitliche Struktur für bestehende und künftige Lokalisierungen etabliert
  - Backend-Logik, Toast-Meldungen und Formulare unterstützen jetzt mehrere Sprachen

### Changed
- **Zone-Bearbeitung: Umgang mit inaktiven Servern überarbeitet**
  - Inaktive Server werden beim Bearbeiten von Zonen nun nicht mehr versehentlich entfernt
  - Hintergrund: HTML `<input disabled>` verhindert Übertragung im POST – zuvor wurden inaktive Slaves dadurch aus der Zuweisung gelöscht
  - Neue Logik:
    - Bestehende inaktive Slave-Server bleiben erhalten, solange sie nicht entfernt werden
    - Inaktive Server können nicht als Master-Server gesetzt werden (führt zu `toastError`)
  - Vergleichslogik (`serverListChanged`) erkennt korrekt, ob sich tatsächlich etwas verändert hat
  - Keine unnötigen Rebuilds oder DB-Updates mehr, wenn nichts geändert wurde
  - Insert-Logik wurde auf `effective_new_server_ids` umgestellt (inkl. inaktiver Slaves)

### Fixed
- **Zone-Update: Falsches Entfernen von Servern verhindert**
  - Beim sofortigen Speichern einer Zone ohne Änderungen wurden vormals zugewiesene inaktive Server versehentlich entfernt
  - Ursache: HTML-Formular übermittelt deaktivierte Einträge nicht
  - Behoben durch Ergänzung inaktiver Slaves auf Basis des vorherigen DB-Zustands vor dem Vergleich

- **Master-Validierung**: Inaktive Server können nicht mehr als Master gesetzt werden
  - Zuvor fehlte eine explizite Prüfung – dies konnte zu inkonsistenten Zuständen führen
  - Neue Prüfung verhindert das Speichern und zeigt eine aussagekräftige `toastError`-Meldung

<br>
<br>

## [1.2.4] – 2025-06-15

### Fixed
- **Darstellung: Spalte "Beschreibung" bei Reverse-Zonen wieder sichtbar**
  - In der Tabelle der Reverse Lookup Zonen wurde die Beschreibungsspalte fälschlicherweise nicht korrekt angezeigt
  - Die Spaltenstruktur wurde überarbeitet, sodass alle Felder sauber und konsistent dargestellt werden

### Changed
- **UI-Layout: Tabellenstruktur im gesamten Pages-Bereich responsive überarbeitet**
  - Alle Tabellen unter `pages/` (z. B. `zones.php`, `users.php`, `dyndns.php`, `servers.php`) sind nun vollständig von `<div class="table-responsive">` umschlossen
  - Dadurch korrektes Verhalten auf schmalen Displays (z. B. Mobilgeräte)
  - Konsistente Spaltenausrichtung und Layoutstruktur zwischen Forward- und Reverse-Zonenansicht
