# DNS Manager – Core-/UI-Trennung: Architektur-Inventar und Restarbeiten

Stand: 12.11.2026, Basis `main` = `71eaa4e`. Dieses Dokument ist die zentrale Übersicht für den Meilenstein
„vollständige Trennung der Fachlogik von der Weboberfläche“. Es wird mit jedem Arbeitspaket aktualisiert.

**Legende:**
- ✅ entkoppelt
- 🟡 teilweise
- ❌ nicht entkoppelt
- 🔧 Infrastruktur-Adapter (zulässig)
- 🌐 bleibt im Web (Transport/Session/Darstellung)

## 1. Zielbild und Regeln

- **Core** = `web/inc/app/`.
  - `final class`-Services mit PDO per Konstruktor, Ergebnis `ServiceResult`/`ServiceMessage` (stabile Codes `bereich.operation.ergebnis`, Rohdaten im Kontext), Akteur als `Actor`.
  - Kein `$_SESSION`/`$_SERVER`, kein HTTP, keine Redirects, Toasts, Sprachschlüssel oder Logs.
- **Berechtigung im Core** nach Datenbankstand:
  - schreibend in der Transaktion unter Sperre;
  - lesend in derselben SQL-Anweisung (`ZoneAccess`, `ActorAccess`, gesperrte `users`-Zeilen).
  - Die Rolle im `Actor` kann nur ablehnen.
- **Infrastruktur** (BIND-Dateien, Agent-HTTP, Shell, Mail) wird über Schnittstellen angebunden (`ZoneBuildBackend`, `PublishBackend`, `ServerControlBackend`); Legacy-Funktionen über Adapter in `web/inc/deploy/`.
- **Web-Actions** sind dünne Adapter:
  - CSRF und Session-Gate;
  - Eingaben übersetzen;
  - Service aufrufen;
  - Codes auf die bisherigen Toasts, Logs, Redirects und HTTP-Status abbilden;
  - unbekannte Codes nie verwerfen.
- **Seiten** lesen ausschließlich über Read-Services (explizite Spalten, keine Hashes/Tokens außer ausdrücklich begründet).
- **Lock-Reihenfolge** (verbindlich, unverändert übernehmen):
  - Topologie-/Publish-Advisory-Lock `dnsmanager_publish_all` (Try-Lock) → Transaktion → `users` (S) → `user_zones` (S) → `zones` (X, aufsteigend) → `zone_servers`/`records`/`dyndns_accounts`.
  - Serverpfade: Topologie-Lock → Transaktion → `users` (S) → `servers` (X).
- **Ziel:** Web-UI und ein späterer MCP-Adapter rufen dieselben Services auf, ohne Geschäftslogik zu duplizieren.

## 2. Stand je Fachbereich

| Bereich | Status | Core-Bausteine | Verbleibende Kopplung |
|---|---|---|---|
| Benutzer | ✅ | `UserService` (anlegen, ändern, Passwort, löschen, aktivieren/deaktivieren), `UserReadService`, Sitzungsgeneration (S26/S27) | – |
| Zonen | ✅ | `ZoneService` (anlegen, ändern inkl. Serverzuweisung, löschen; `TopologyLock`; Bearbeitungsstand S21), `ZoneReadService` (Liste, Detail, zuweisbare Server, Zonendiagnosen), `ZoneAccess` | `pages/zones.php` nur Statusaggregation/Darstellung (🌐) |
| Records | 🟡 | `RecordService` (anlegen, ändern, löschen inkl. AutoPTR, DynDNS-Schutz), `RecordReadService` | siehe 3.3 |
| Publish (alle Zonen) | ✅ / 🔧 | `PublishService` (Advisory-Lock, Tri-State `bind_dirty`/`changed`), `PublishBackend` | Legacy-Generator/Distributor hinter `LegacyBindPublishBackend` (siehe 3.4) |
| **Server** | ✅ **mit Paket 1** | `ServerService` (anlegen, ändern inkl. Topologie-Lock, NS/Glue-Neuaufbau und `bind_dirty`, löschen, BIND-Reload), `ServerReadService` (Liste, Bearbeitungsdaten, Serverdiagnosen), `ServerControlBackend` + `LegacyAgentServerControlBackend` | NS/Glue-Neuaufbau weiter über `LegacyZoneBuildBackend` → `helpers.php` (Paket 4) |
| DynDNS-Konten | ❌ | – (`dyndns_guard.php`: wiederverwendbare Funktionen) | siehe 3.2 |
| DynDNS-Update-API | ❌ | – | siehe 3.2 |
| Monitoring/Diagnose | ❌ | – | siehe 3.5 |
| Dashboard/Layout/System-Health | ❌ | – | siehe 3.5 |
| Authentifizierung | ❌ | Revalidierung (S3) DB-gestützt, aber Login an Session gebunden | siehe 3.5 |

## 3. Verbleibende Kopplungen (Inventar)

### 3.1 Server (Paket 1 – in diesem Stand erledigt)

Erledigt:
- `actions/server_add.php`, `server_update.php`, `server_delete.php`, `bind_reload.php` sind dünne Adapter.
- `pages/servers.php` liest über `ServerReadService`.

Ersetzt wurden:
- direkte SQL-Anweisungen und Transaktionen in allen vier Actions;
- die eigene `GET_LOCK`-Kopie in `server_update.php` (jetzt `TopologyLock`);
- `SELECT *` auf `servers` in `bind_reload.php` und `pages/servers.php`;
- der Agent-Aufruf in `bind_reload.php`;
- `getDiagnosticResults()` in `pages/servers.php`.

Noch offen, bewusst in Paket 4: `rebuild_ns_and_glue_for_zone_and_flag_if_valid()` (in `helpers.php`) schreibt Systemrecords ohne Zonensperre. Es wird weiterhin über `ZoneBuildBackend` aufgerufen.

### 3.2 DynDNS (Paket 2)

**Kontenverwaltung:**
- `actions/dyndns_add.php`, `dyndns_update.php`, `dyndns_delete.php`, `dyndns_password.php` enthalten Transaktionen, `zones FOR UPDATE` (über `dyndnsLockZones`), `dyndns_accounts FOR UPDATE`, Konflikt- und Record-Logik, direkte `INSERT`/`DELETE` auf `records`, Passwort-Hashing und Rebuild (`rebuild_zone_and_flag_if_valid`).
- Autorisierung über die Session-Rolle; S3-Revalidierung ist aktiv, aber es gibt keinen `Actor`.

**Lesen:**
- `pages/dyndns.php`: `SELECT d.*` einschließlich `password_hash`; die Rolle wird erst nach `layout.php` geprüft.
- `templates/dyndns_add_form.php` und `dyndns_edit_form.php` lesen die Zonen jeweils mit eigener SQL-Abfrage.

**Update-API:**
- `api/v1/dyndns/update.php` übernimmt Authentifizierung (Basic), Sperren, Planung, bedingtes `UPDATE`/`INSERT` auf `records`, `UPDATE dyndns_accounts`, Rebuild und `publish_single_zone()` vor dem Commit.
- Es gibt keinen Publish-Advisory-Lock, und `changed = 0` wird ohne Rücksicht auf `changed = 2` gesetzt.

**Wiederverwendbar aus `inc/dyndns_guard.php`:**
- reine Funktionen: Namens-, Zonen- und IP-Regeln sowie die Planung (`dyndnsPlanApiRecordChanges`);
- DB-Funktionen für Sperren und Konflikte (`dyndnsLockZones`, `dyndnsFindHostnameConflicts`, `dyndnsClassifyAccountRecords` u. a.);
- `RecordService` nutzt sie bereits.
- Zu ersetzen: `DyndnsActionError` (Toast-HTML) und `DyndnsApiReject` (Protokollantwort) durch ServiceMessage-Codes.

### 3.3 Records und Zonen-Reste (Paket 3)

- `pages/records.php`:
  - eigene Rechte-SQL (`SELECT * FROM zones`, `user_zones`-Join) nach Session-Rolle;
  - `SELECT * FROM records`;
  - `isProtectedNsRecord` je Zeile;
  - ungefiltertes `getDiagnosticResults()`.
  - **`RecordReadService::listRecords()` existiert, wird aber nicht genutzt.**
- `actions/record_update.php`: eine direkte Abfrage `SELECT name FROM zones` vor der Rechteprüfung (nur für den Logtext).
- `templates/record_edit_form.php`: `global $pdo`, Glue-/NS-Schutzprüfung mit DB-Zugriff, Parsing von MX/SRV/DKIM/URI/CAA/NAPTR.
- `inc/record_content_builder.php` (`buildRecordContent`): fest kodierte deutsche Fehlertexte statt Codes.
- `inc/helpers.php`:
  - mischt Web-Funktionen (`requireRole`, `csrf_input`, `isActive`, `safe_return_path`) mit Fachlogik (`rebuild_*`);
  - `get_zone_by_id()` ist ungenutzt.

### 3.4 BIND-/Publish-Infrastruktur und Legacy-Adapter (Paket 4)

- `inc/deploy/bind_file_generator.php`:
  - `$GLOBALS['pdo']`/`global $pdo`;
  - eigene Transaktion mit `zones FOR UPDATE` und Serial-Fortschreibung;
  - `shell_exec('named-checkzone …')` bei gesperrter Zeile;
  - feste `/tmp`-Pfade;
  - `generate_zone_conf_file()` mit `global $pdo`.
- `inc/deploy/bind_file_distributor.php`:
  - `global $pdo`, eigene Server- und Token-Abfragen, eigenes curl-Setup;
  - `distribute_zone_file()` ohne Pfad erzeugt selbst eine Zonendatei (versteckter Publish-Pfad).
- `inc/deploy/publish_single.php`:
  - lädt `common.php` (Web-Bootstrap);
  - DynDNS-Einzelpublish ohne Advisory-Lock;
  - `UPDATE zones SET changed = 0` ohne Bedingung.
- `inc/deploy/LegacyZoneBuildBackend.php` hängt an `helpers.php` (siehe 3.3); `LegacyBindPublishBackend` verlangt `$pdo === $GLOBALS['pdo']`.
- `inc/api_client.php` (generisch, 🔧) und die curl-Varianten in Distributor und Monitoring sind uneinheitlich.

### 3.5 Monitoring, Dashboard, Authentifizierung und übrige Kopplungen (Paket 5)

**Monitoring:**
- `inc/monitoring.php`: Agent-Checks per curl; `saveDiagnostic()` (Select, dann Insert/Update ohne Sperre); Orphan-Cleanup mit N+1-Abfragen.
- `scripts/monitoring_run-cli.php`: `SELECT *` auf `servers`, Transaktion um curl-Aufrufe, Bootstrap vor `cliGuard()`, kein Lauf-Lock.
- `inc/monitoring_mailer.php`: Mailversand und `notified = 1` gemischt.
- `scripts/monitoring_log_cleanup-cli.php`: Retention-Löschung (🔧/CLI).

**Diagnose-Lesezugriffe:**
- `inc/diagnostics.php`: `getDiagnosticResults()` ohne Berechtigungsbezug; `check_config_validity` nutzt `$LANG`; Dateirechte-Scan.

**Seiten und Layout:**
- `pages/dashboard.php`: Zähler (users/zones/records) direkt; zoneadmin-Zonen über `user_zones` mit `$_SESSION['user_id']` statt `ZoneReadService`; vollständige Diagnosen bei jedem Aufruf.
- `templates/layout.php`: liest `bind_dirty`, die Zahl `changed <> 0` und `getDiagnosticResults()` direkt.
- `pages/system_health.php`: `exec("php monitoring_run-cli.php")` synchron im Webprozess; `getDiagnosticResults()`.
- `pages/update.php`: ausgehender Update-Check per curl ohne Rollenprüfung.

**Authentifizierung:**
- `inc/auth.php` (`login_user`): Credential-Prüfung und Session-Aufbau sind vermischt; es gibt keine wiederverwendbare Prüffunktion.
- `inc/web/session_guard.php` (Revalidierung): DB-Prüfung könnte über `ActorAccess` laufen; Revoke und Redirect bleiben 🌐.

**Logging:**
- `inc/logging.php`: `appLog` ist transportunabhängig; `logAccessDenied`/`logFailedLogin` lesen `$_SESSION`/`$_SERVER`.

### 3.6 Bleibt legitim im Web (🌐)

- `common.php`, `inc/session.php`, `inc/web/session_guard.php` (Revoke/Redirect), `login.php`, `logout.php`;
- `inc/web/web_adapter.php`, die Toast-Funktionen, `requireRole` als Web-Gate, `csrf_input`;
- Templates, Layout-Ausgabe, `asset_registry`;
- `inc/db.php`, `helpers_cli.php` (`cliGuard`);
- alle Action-Adapter.

### 3.7 Agent (eigene Komponente auf ns1/ns2, 🔧)

Endpunkte, auf die sich die Web-App verlässt:
- `zones/zone_sync`, `conf_sync`, `zone_cleanup`, `zone_check`, `conf_check`;
- `system/status`, `system/control` (`reload-bind`).

Authentifizierung per Bearer-Token. Der Agent ist nicht Teil der Core-Trennung; das Web bindet ihn über Adapter an.

## 4. Wiederverwendbare Bausteine

- **Basis:** `Actor`, `ActorAccess`, `ZoneAccess`, `TopologyLock`, `ServiceResult`/`ServiceMessage`, `SchemaCheck`, `AppBootstrap`.
- **Validierung** (rein): `validators.php` – `validateDnsRecord`, `validateZoneInput`, `isValidFqdn`, `isValidSoaName`, `validateDyndnsHostname`, `normalizeDyndnsHostname`; `isDuplicateRecord` liest die DB.
- **DynDNS:** `dyndns_guard.php` (siehe 3.2).
- **Records:** `glue_check.php` (`isGlueRecord`, `isNsRecordReferencedByGlue` rein; `isProtectedNsRecord` liest), `ttl_defaults.php`, `soa_serial.php`, `dkim_helpers.php`, `AutoPtr`.
- **Schnittstellen:** `ZoneBuildBackend`, `PublishBackend`, `ServerControlBackend` mit ihren Legacy-Adaptern.

## 5. Verbindliche Reihenfolge der Arbeitspakete

| Paket | Inhalt | Status |
|---|---|---|
| **1 Server** | `ServerService` (CRUD, BIND-Reload), `ServerReadService` (Liste, Bearbeitungsdaten, Serverdiagnosen), Actions und Seite als Adapter | **Draft-PR** |
| **2 DynDNS** | `DyndnsAccountService` (anlegen, ändern, Passwort, löschen), `DyndnsReadService` (Liste ohne Hash, zulässige Zonen für beide Formulare), `DyndnsUpdateService` (API: authentifizieren, sperren, planen, anwenden, publizieren; Antwortprotokoll bleibt im Endpunkt). Die DynDNS-eigenen Record-Mutationen werden im Service gebündelt, mit den Sperrfunktionen aus `dyndns_guard`. Das Einzel-Publish wird über eine Schnittstelle mit **unverändertem** Verhalten angebunden. | offen |
| **3 Records/Zonen-Reste** | `pages/records.php` auf `RecordReadService`/`ZoneReadService` (inkl. Schutzflags, Diagnosen gefiltert); Rest-SQL in `record_update.php`; Logik aus `record_edit_form.php` in den Read-Service; `buildRecordContent` mit Fehlercodes | offen |
| **4 BIND/Publish-Infrastruktur** | Generator und Distributoren mit expliziter PDO (ohne `$GLOBALS`); einheitlicher Agent-Client; NS/Glue-Neuaufbau und Rebuild aus `helpers.php` in den Core (unter Zonensperre); `publish_single` in den Service-Pfad; `distribute_zone_file` ohne versteckte Generierung | offen |
| **5 Monitoring/Dashboard/Auth/Rest** | `MonitoringService` + Benachrichtigungs-Adapter (CLI und System-Health rufen den Service, kein `exec`); `DiagnosticsReadService` und `StatusReadService` (Layout, Dashboard, System-Health); `AuthService::verifyCredentials()` mit `login_user` als Session-Adapter; `logAccessDenied`/`logFailedLogin` mit expliziten Parametern; Update-Seite | offen |
| **6 Abschlussprüfung** | Automatischer Nachweis: keine SQL-, Transaktions-, Agent- oder Shell-Aufrufe in `actions/`, `pages/`, `templates/`, `api/`, `scripts/` außer Bootstrap; jede Fachoperation über einen Service erreichbar; Inventar ohne ❌/🟡 | offen |

**Begründete Anpassungen gegenüber der vorgegebenen Reihenfolge:**
1. **Paket 1 enthält die Serverdiagnosen**, die `pages/servers.php` anzeigt. Die allgemeinen Diagnose-Lesezugriffe für Dashboard, Layout und Records bleiben in Paket 3 bzw. 5.
2. **Paket 2 ändert die Publish-Semantik des DynDNS-Pfads nicht.** Gemeint sind: Publish vor dem Commit, kein Advisory-Lock, `changed = 0`. Diese Punkte sind Sicherheits- und Konsistenzbefunde mit Verhaltensänderung (siehe 6) und werden in Paket 4 bzw. als eigene Sicherheitsausnahme entschieden. So bleibt Paket 2 eine reine Entkopplung.
3. **Der NS/Glue-Neuaufbau aus `helpers.php` wandert nach Paket 4**, nicht nach Paket 1. Er wird von Zonen- und Serverpfaden gemeinsam genutzt und braucht die Zonensperre. Das ist Infrastruktur-Umbau, keine Serverlogik.

## 6. Befunde außerhalb der Architekturarbeit (dokumentiert, nicht behoben)

Die Nummern setzen die bestehende S-Reihe fort. Sicherheitsrelevante Punkte werden nur nach gesonderter Freigabe als Ausnahme vorgezogen.

| Nr. | Befund | Ort |
|---|---|---|
| S29 | Das Bearbeitungsformular gibt den gespeicherten API-Token im HTML aus (`value` des Passwortfelds). Bei der Entkopplung bewusst beibehalten (`ServerReadService::getServerForEdit`). | `templates/server_edit_form.php` |
| S30 | Keine serverseitige Mindestlänge für DynDNS-Passwörter beim Anlegen und Ändern | `actions/dyndns_add.php`, `dyndns_update.php` |
| S31 | Benutzer-Enumeration über die Antwortzeit: kein Dummy-`password_verify` bei unbekanntem Benutzer | `inc/auth.php`, `api/v1/dyndns/update.php` |
| S32 | DynDNS-Einzelpublish verteilt vor dem Commit unter Zeilensperre, ohne Publish-Advisory-Lock; `changed = 0` ohne Rücksicht auf `changed = 2`; gemeinsame `/tmp/dnsmanager_conf_{ip}`-Pfade mit Publish All | `inc/deploy/publish_single.php`, `bind_file_distributor.php` |
| S33 | Zonenname geht ohne zusätzliche Prüfung in die BIND-`.conf` und in den Dateinamen (keine Abwehr wie S17) | `bind_file_generator.php` (`writeZoneConfFile`) |
| S34 | Feste bzw. vorhersagbare `/tmp`-Pfade (`named-checkzone-error.log`, `dnsmanager_conf_{ip}`, Agent-Lock) | Generator, Distributor, Agent |
| S35 | Logout per GET ohne CSRF; Login überspringt die CSRF-Prüfung bei fehlendem Token | `logout.php`, `login.php` |
| S36 | `pages/update.php`: keine Rollenprüfung; `href` aus fremder JSON-Antwort ohne Schemaprüfung | `pages/update.php` |
| S37 | `system_health.php` startet das Monitoring per `exec` im Webprozess; `db.php` gibt den DB-Fehlertext per `die()` aus | `pages/system_health.php`, `inc/db.php` |
| S38 | `getDiagnosticResults()` ohne Berechtigungsbezug; `records.php` filtert erst in PHP | `inc/diagnostics.php`, `pages/records.php` |
| F1 | Die Änderungserkennung in der Serveränderung vergleicht DB-Zeichenketten mit Integern (`'1' !== 1`). Daher erscheint „Keine Änderungen“ praktisch nie. Bei der Entkopplung bewusst unverändert übernommen. | `ServerService::updateServer` (vorher `server_update.php`) |
| F2 | Sprachschlüssel `topology_locked`, `publish_all_locked` und `zone_prefix_placeholder` fehlen; die Actions nutzen Fallback-Texte | `web/lang/*.php` |

## 7. Änderungsprotokoll dieses Dokuments

| Datum | Paket | Änderung |
|---|---|---|
| 12.11.2026 | – | Erstes vollständiges Inventar, Basis `71eaa4e` |
| 12.11.2026 | 1 | Serververwaltung entkoppelt (Draft-PR) |
