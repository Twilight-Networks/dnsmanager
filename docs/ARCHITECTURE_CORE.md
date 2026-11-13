# DNS Manager – Core-/UI-Trennung: Architektur-Inventar und Restarbeiten

Stand: 13.11.2026, Basis `main` = `c0965d2` (nach Paket 5d). Dieses Dokument ist die zentrale Übersicht für den Meilenstein
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
| Records | ✅ **mit Paket 3** | `RecordService` (anlegen, ändern, löschen inkl. AutoPTR, DynDNS-Schutz), `RecordReadService` (Liste, Verwaltungsansicht mit Schutzkennzeichen), `RecordContentBuilder`, `RecordContentParser` | `RecordService` nutzt `glue_check.php`-Lesefunktionen |
| Publish (alle Zonen) | ✅ / 🔧 | `PublishService` (Advisory-Lock, Tri-State `bind_dirty`/`changed`), `PublishBackend` | Generator/Distributor hinter `LegacyBindPublishBackend` (siehe 3.4) |
| **Server** | ✅ **mit Paket 1** | `ServerService` (anlegen, ändern inkl. Topologie-Lock, NS/Glue-Neuaufbau und `bind_dirty`, löschen, BIND-Reload), `ServerReadService` (Liste, Bearbeitungsdaten, Serverdiagnosen), `ServerControlBackend` + `LegacyAgentServerControlBackend` | – (NS/Glue-Neuaufbau seit 4a atomar über `BindZoneBuildBackend`) |
| DynDNS-Konten | ✅ **mit Paket 2** | `DyndnsAccountService`, `DyndnsReadService` (auf Basis `dyndns_guard.php`) | – |
| DynDNS-Update-API | ✅ **mit Paket 2/4b** | `DyndnsUpdateService` (Commit vor Publish, Retry ausstehender Publishes), Veröffentlichung über `PublishService::publishZone()` | – |
| Monitoring/Diagnose | ✅ **mit 5b** | `MonitoringService` (Lauf mit Laufsperre, Speichern, Orphan-Bereinigung, Benachrichtigung, Log-Retention), `MonitoringBackend` + `AgentMonitoringBackend`, `MonitoringNotifier` + `PhpMailerNotifier` | CLI-Skripte und Systemstatus-Seite als Adapter (`inc/monitoring_runner.php`) |
| Dashboard/Layout/System-Health/Update-Prüfung | ✅ **mit 5a/5b/5d** | `SystemStatusReadService`, `DiagnosticReadService`, `SystemCheckService` (Systemprüfungen ohne Sprachtexte), `UpdateCheckService` + `CurlUpdateFeed` | – |
| Authentifizierung | ✅ **mit 5c** | `AuthService` (`authenticate`: Zugangsdaten und Kontostatus; `validateSession`: Revalidierung inkl. `session_epoch` und Schema) | `inc/auth.php` und `inc/web/session_guard.php` als Web-Adapter (Session, Revoke, Redirect, 503, Logs) |

## 3. Verbleibende Kopplungen (Inventar)

### 3.1 Server (Paket 1 – erledigt, PR #55, `main` `4860f05`)

Erledigt:
- `actions/server_add.php`, `server_update.php`, `server_delete.php`, `bind_reload.php` sind dünne Adapter.
- `pages/servers.php` liest über `ServerReadService`.

Ersetzt wurden:
- direkte SQL-Anweisungen und Transaktionen in allen vier Actions;
- die eigene `GET_LOCK`-Kopie in `server_update.php` (jetzt `TopologyLock`);
- `SELECT *` auf `servers` in `bind_reload.php` und `pages/servers.php`;
- der Agent-Aufruf in `bind_reload.php`;
- `getDiagnosticResults()` in `pages/servers.php`.

Erledigt mit Paket 4a: Der NS-/Glue-Neuaufbau (`BindZoneBuildBackend`) läuft bei Änderung von Name/DNS-IP in der Transaktion der Serveränderung unter Topologie-Lock und Zonensperre (siehe 3.4).

**Einschränkung „nur ein lokaler Server“ beim Anlegen:** Die Prüfung wird in der Transaktion mit `SELECT … WHERE is_local = 1 FOR UPDATE` wiederholt. Sie ist **nicht nachgewiesen race-sicher**:
- Es gibt keinen UNIQUE-Schutz und keinen Index auf `is_local`.
- Unter READ COMMITTED gibt es keine Gap-Locks; zwei gleichzeitige Anlagen ohne bereits vorhandenen lokalen Server können beide gelingen.
- Unter REPEATABLE READ verhindern die Next-Key-Locks des Tabellenscans das voraussichtlich; ein Nachweis liegt nicht vor.

Die Wiederholung verkleinert das Zeitfenster, schließt es aber nicht garantiert. Eine echte Absicherung (Schema-Constraint) wäre eine gesonderte Entscheidung.

### 3.2 DynDNS (Paket 2 – erledigt, PR #56, `main` `426fd30`)

**Erledigt:**
- **Kontenverwaltung:** `DyndnsAccountService` (`createAccount`, `updateAccount`, `changePassword`, `deleteAccount`) mit unveränderter Fachlogik aus den vier Actions: Regeln, Reihenfolge, Hash-Verfahren, Record-Behandlung, Rebuild in derselben Transaktion über `ZoneBuildBackend::validateAndFlag()`. Die Actions sind dünne Adapter; Toasts, Logtexte und Redirects sind unverändert (Hilfe `inc/web/dyndns_messages.php`).
- **Lesen:** `DyndnsReadService` (`listAccounts` ohne `password_hash`, `listZonesForCreate`, `listZonesForEdit`); `pages/dyndns.php` und beide Formulare lesen darüber.
- **Update-API:** `DyndnsUpdateService` (authentifizieren, sperren, planen, anwenden, validieren, publizieren). Der Endpunkt `api/v1/dyndns/update.php` liest nur noch HTTP-Basic-Daten und Parameter, schreibt die bisherigen Logzeilen und gibt das DynDNS-Antwortprotokoll mit den bisherigen HTTP-Status/-Headern aus.
- **Einzel-Publish:** seit Paket 4b über `PublishService::publishZone()` (siehe 3.4); `DyndnsPublishBackend`, `LegacyDyndnsPublishBackend` und `publish_single.php` sind entfernt.
- **Berechtigung** nach DB-Stand (aktiver Admin): in der Transaktion unter `users … LOCK IN SHARE MODE` vor `dyndnsLockZones()`; lesend über `ActorAccess`.
- **Kein Protokoll, keine Sprachschlüssel im Core:**
  - `DyndnsUpdateService` liefert strukturierte Ergebnisse (`rejected` mit `credentialsInvalid`, `done` mit `results` je Adressfamilie: `type`, `ip`, `changed`). Erst `api/v1/dyndns/update.php` übersetzt sie in `good`/`nochg`/`badauth`/`dnserr`.
  - `DyndnsAccountService` meldet fachliche Codes (`reason`): die `DYNDNS_*`-Codes aus `dyndns_guard.php` sowie `DYNDNS_ZONE_NOT_FOUND`, `DYNDNS_ACCOUNT_NOT_FOUND`, `DYNDNS_CONCURRENT_CHANGE`, `ZONE_REBUILD_FAILED`/`_WARNING`, `DYNDNS_SHARED_HOSTNAME_KEPT`, `DYNDNS_RECORDS_KEPT_AMBIGUOUS`, `DYNDNS_NO_IP`. Die Zuordnung zu `$LANG` erfolgt ausschließlich in `inc/web/dyndns_messages.php`.

**Bewusst unverändert** (Befunde, keine Verhaltensänderung in Paket 2):
- S30: keine Mindestlänge beim Anlegen/Ändern;
- S31: Timing-Unterschied in der Authentifizierung;
- S32: Publish vor dem Commit ohne Advisory-Lock (**mit Paket 4b behoben**, siehe 3.4);
- Passwortänderung ohne Existenzprüfung des Accounts (F3);
- Rollenprüfung in `pages/dyndns.php` erst nach dem Layout (S-Befund der Seite, siehe Inventar Abschnitt 6).

**Offen:** `DyndnsActionError`, `DyndnsApiReject` und `dyndnsLangKey()` in `dyndns_guard.php` werden nicht mehr verwendet (Bereinigung in Paket 6).

### 3.3 Records und Zonen-Reste (Paket 3 – erledigt, PR #57, `main` `b41a34d`)

**Erledigt:**
- `pages/records.php` liest die Zone über `ZoneReadService::getZone()`, die Records über die neue Methode `RecordReadService::getZoneRecordView()` und die Diagnosen über `ZoneReadService::listZoneDiagnostics()`, gefiltert auf die Zone und `zone_status`.
  - `getZoneRecordView()` liefert die vollständige Liste ohne Seitenbegrenzung, mit den fachlichen Kennzeichen `isDkim`, `isGlue` und `isProtectedNs`.
  - Berechtigung nach `ZoneAccess` in derselben Abfrage, für die Records und für die Servernamen.
  - Entfallen sind die eigene Rechte-SQL nach Session-Rolle, `SELECT *`, die Serverabfrage je NS-Zeile und `getDiagnosticResults()`.
- `templates/record_edit_form.php`: kein `global $pdo`, keine DB-Zugriffe mehr. Die Schutzkennzeichen kommen aus dem Read-Service; die Zerlegung von MX/SRV/DKIM/NAPTR/URI/CAA übernimmt `RecordContentParser` (Core, rein funktional).
- `actions/record_update.php`: Die direkte Abfrage `SELECT name FROM zones` ist entfallen. Der Zonenname für die Logzeile bei Builder-Fehlern kommt aus `ZoneReadService::getZone()`.
- `buildRecordContent()` ist ersetzt durch `RecordContentBuilder::build()` (Core) mit fachlichen Codes `RECORD_BUILD_*`. Die bisherigen Texte liefert `inc/web/record_messages.php`; `inc/record_content_builder.php` ist entfernt.
- `inc/glue_check.php`: neue reine Funktion `isProtectedNsRecordForServers()`; `isProtectedNsRecord()` (PDO, weiter vom `RecordService` genutzt) delegiert darauf.
- `inc/helpers.php`: Die Abgrenzung Web/Legacy-Infrastruktur ist im Dateikopf dokumentiert; das ungenutzte `get_zone_by_id()` ist entfernt.

**Bewusst unverändert / offen:**
- `RecordService` liest weiterhin über `getAllZoneRecords()`/`getZoneName()` (`glue_check.php`) und `isProtectedNsRecord()`.
- Die Rebuilds übergeben die Actions seit 4a als `BindZoneBuildBackend::validateAndFlag` an den `RecordService` (vorher Callback auf `rebuild_zone_and_flag_if_valid()`).
- `getAutoTTL()` (`inc/ttl_defaults.php`) bleibt Web-/Formularhilfe.
- In `helpers.php` ist `validatePassword()` ungenutzt (Bereinigung in Paket 6); die `rebuild_*`-Funktionen sind mit 4a entfernt.
- Die DKIM-Datei-Prüfung in `record_add.php` (Upload, Dateiendung, verbotene Zeichenfolgen) ist Transportlogik und bleibt im Adapter.

### 3.4 BIND-/Publish-Infrastruktur und Legacy-Adapter (Paket 4 – erledigt: 4a PR #58, 4b PR #59, `main` `43e4823`)

**Erledigt mit 4a:**
- **Keine globale Verbindung, kein Web-Bootstrap mehr:**
  - Generator (`generate_zone_file`, `generate_zone_conf_file`), Distributoren (`distribute_zone_file`, `distribute_zone_conf_file`, `distribute_zone_cleanup`) und `publish_single_zone()` erhalten Verbindung, Agent-Client und Arbeitsverzeichnis explizit.
  - `publish_single.php` lädt kein `common.php` mehr (mit 4b vollständig entfernt).
  - `LegacyBindPublishBackend` und `LegacyDyndnsPublishBackend` verlangen kein `$GLOBALS['pdo']` mehr.
- **Kein versteckter Publish-Pfad:** `distribute_zone_file()` erzeugt keine Zonendatei mehr selbst (bisher im Modus `publish` mit Serial-Erhöhung); ohne erzeugte Datei liefert es einen Fehler.
- **Einheitlicher Agent-Zugriff:** `BindAgentClient` für `zone_sync`, `conf_sync`, `zone_cleanup` und `system/control` (bisheriger `apiPostJson()`). Zeitgrenzen und Antwortauswertung bleiben unverändert. `inc/api_client.php` ist entfernt.
- **Temporäre Dateien (S34):**
  - `BindWorkDir` legt je Vorgang ein eigenes Verzeichnis `<sys_temp>/dnsmanager-work-<32 Hex>` mit 0700 an. Es wird auf allen kontrollierten Wegen entfernt (finally bzw. Destruktor des Publish-Backends).
  - Restverzeichnisse nach hartem Abbruch entfernt `BindWorkDir::create()`: nur dieses Präfix, nur derselbe Eigentümer, älter als 24 h.
  - Entfallen sind `/tmp/named-checkzone-error.log` und `/tmp/dnsmanager_conf_{ip}`.
  - Ungültige Zonendateien werden in jedem Modus gelöscht.
- **Zonennamen (S33):** `zone_name_is_safe()` prüft den Zonennamen vor Dateinamen, `named-checkzone`, `zone "…"` der Konfiguration und Konfigurationspfad. Zulässig ist nur der Zeichenvorrat der Eingabeprüfung.
- **NS-/Glue-Neuaufbau webunabhängig und atomar:**
  - `BindZoneBuildBackend` ersetzt `LegacyZoneBuildBackend` und die `rebuild_*`-Funktionen aus `helpers.php`.
  - Eine einzige Betriebsart: nur innerhalb der Transaktion des Aufrufers, sonst `LogicException`. Commit und Rollback verantwortet der Aufrufer.
  - Die Records-Actions übergeben das Backend statt des `helpers.php`-Callbacks.
  - `ServerService` führt den Neuaufbau bei Änderung von Name/DNS-IP in derselben Transaktion durch: Topologie-Lock → users (S) → servers (X) → zones (X, aufsteigend) → Neuaufbau → Commit. Publish All (gleicher Lock) sieht damit nie einen Zwischenstand.

**Erledigt mit 4b (S32):**
- **Reihenfolge DynDNS:** Transaktion (Prüfung, Schreiben, Zonenprüfung, `changed = 1`) → **Commit** → `PublishService::publishZone()`. Ein Verteilungsfehler rollt die festgeschriebene Änderung nicht zurück.
- **Gemeinsame Zonenlogik:** `processZone()` (Claim 1|2 → 2, Generierung mit Serial-Commit vor der Verteilung, Zone- und Conf-Verteilung, Einordnung) und die Abschlussregeln (`resetPublishedZone` nur 2 → 0, `markZonePending` → 1, `restoreClaimedZone` 2 → 1) nutzen Publish All und Publish Zone gemeinsam.
- **Lock:** derselbe Advisory-Lock wie Publish All, Wartezeit beim Einzelpublish höchstens 5 s; belegt → Zone bleibt ausstehend.
- **Statuswerte:** Eine während des Publish neu gesetzte Änderung (changed = 1) wird nie als veröffentlicht markiert. War die Zone zwischen Commit und Aufruf bereits veröffentlicht (changed = 0, z. B. durch Publish All), erfolgt keine erneute Veröffentlichung (`already_published`, Erfolg).
- **Antworten:** vollständig veröffentlicht → `good <ip>`; zurückgestellt, teilweise oder nicht verteilt → `dnserr` (Änderung bleibt festgeschrieben). Ein Update mit unveränderten IPs wiederholt einen ausstehenden Publish der Zone (F1: bei Erfolg `good <ip>`; F2: die vollständige Zone einschließlich zwischenzeitlicher Admin-Änderungen). Ohne IP-Parameter `nochg` ohne Wiederholung (F3).
- `bind_dirty` und Orphan-Cleanup bleiben beim Einzelpublish unberührt. Generator-Modus `dyndns`, `publish_single.php`, `LegacyDyndnsPublishBackend` und `DyndnsPublishBackend` sind entfernt.

**Offen:**
- Optional (Agent): Sperr- und Temporärdateien des Agenten in `/tmp` (S34, Agent-Teil); Serial-Prüfung im Agenten als spätere zusätzliche Absicherung.
- Der Netzwerkzugriff im Monitoring bleibt in Paket 5.

### 3.5 Monitoring, Dashboard, Authentifizierung und übrige Kopplungen (Paket 5 – 5a erledigt, PR #60; 5b erledigt, PR #61; 5c erledigt, PR #62; 5d erledigt, PR #63, `main` `c0965d2` – Paket 5 abgeschlossen)

**Gliederung Paket 5 (Reihenfolge):** 5a Status- und Diagnose-Lesezugriffe → 5b Monitoring und Systemprüfungen → 5c Authentifizierung → 5d übrige Kopplungen (Logging-Helfer, `pages/update.php`), danach Paket 6.

**Erledigt mit 5a:**
- `templates/layout.php`: Publish-Status über `SystemStatusReadService::getPublishStatus()`, Fehleranzeige über `DiagnosticReadService::getOverview()[summary]`; keine direkte SQL-Abfrage mehr.
- `pages/dashboard.php`: Kennzahlen über `SystemStatusReadService::getDashboardCounts()`, Diagnose-Auswertung über `DiagnosticReadService`, Zonenliste des zoneadmin über `ZoneReadService::listZones()` (vollständig, nach Name sortiert); keine direkte SQL-Abfrage mehr.
- `pages/system_health.php`: Diagnosen über `DiagnosticReadService` (Darstellungsstruktur über `inc/web/diagnostics_view.php`).
- `getDiagnosticResults()` ist entfernt (S38): Diagnosen werden nur noch berechtigt gelesen (Server/Zonen-Read-Services, Bedingung in derselben Abfrage).

**Erledigt mit 5b:**
- **`MonitoringService`** (`inc/app/monitoring`) ersetzt die Fachlogik aus `monitoring_run-cli.php`, `inc/monitoring.php` und `inc/monitoring_mailer.php`:
  - Laufsperre `dnsmanager_monitoring` (Cron überspringt einen laufenden Lauf, die manuelle Aktualisierung wartet bis zu 60 s);
  - Netzwerkprüfungen vor der Transaktion, Speichern je Server/Zone in einer kurzen Transaktion;
  - `saveDiagnostic` unter `FOR UPDATE`, gleichzeitige Neuanlage (Unique-Schlüssel) wird zur Aktualisierung;
  - Orphan-Bereinigung mit einer `DELETE … NOT EXISTS`-Anweisung statt N+1;
  - Benachrichtigung über die Schnittstelle `MonitoringNotifier` (Auswahl, Texte, Reihenfolge, `notified = 1` erst nach Versand und Retry im nächsten Lauf **unverändert**);
  - Log-Retention (`purgeLog`, `parseRetention`).
- **Agent-Prüfungen** über `AgentMonitoringBackend` (gemeinsamer `BindAgentClient`, neu mit GET); Prüf- und Auswertungslogik unverändert. **Mailversand** über `PhpMailerNotifier`.
- **CLI-Skripte** sind Adapter: `cliGuard()` vor Konfiguration und Datenbank, keine Fachlogik; die Logzeilen schreibt `inc/monitoring_runner.php`, gemeinsam für Cron und Systemstatus-Seite.
- **S37:** Die Systemstatus-Seite ruft den Monitoring-Lauf über denselben Service im Webprozess auf statt per `exec`; weiterhin synchron, mit gleichen Toasts.
- **Systemprüfungen** im `SystemCheckService` (Konfigurationscodes statt `$LANG`, PHP-Version/Module, Dateirechte); die Texte liefert `inc/web/system_check_messages.php`.
- Entfernt: `inc/monitoring.php`, `inc/monitoring_mailer.php`, `inc/diagnostics.php` (inkl. Include in `helpers.php`).

**Erledigt mit 5c (PR #62):**
- **`AuthService`** (`inc/app/auth`), ohne Session, HTTP, Logging und Sprachtexte:
  - `authenticate($username, $password)`: dieselbe Abfrage, `password_verify` gegen den gespeicherten Hash, nur aktive Konten (S27); Codes `auth.login.success` (ID, Rolle, Sitzungsgeneration), `auth.login.inactive` (Passwort korrekt, Konto deaktiviert), `auth.login.failed`.
  - `validateSession($userId, $username, $role, $sessionEpoch)`: Regeln und Reihenfolge der bisherigen Revalidierung (S3, S26/S27) – Benutzer-ID → Schema (`SchemaCheck`) → DB-Abfrage → `user_missing`, `username_changed`, `role_changed`, `role_invalid`, `account_inactive`, `epoch_missing`, `epoch_changed`; Codes `auth.session.valid`, `auth.session.revoked` (Grund), `auth.session.schema_incompatible`, `auth.session.check_failed` (Fehlerklasse/-code ohne SQL-Details).
- **`inc/auth.php`** (`login_user`) ist Web-Adapter: Session-Regeneration, Session-Variablen, `event=login_rejected_inactive` und `logFailedLogin` wie bisher; Rückgabe weiterhin `bool`, `login.php` unverändert.
- **`inc/web/session_guard.php`** ist Web-Adapter: Abbildung auf `session_guard_revoke` (Log `event=session_revoked`, neue anonyme Session, Redirect), `event=schema_incompatible`/`event=session_revalidation_failed` mit HTTP 503; unbekannter Code → 503 (Fail-Closed).
- Unverändert: `login.php` (CSRF, Hinweise, Redirect), `logout.php`, Cookies, Inaktivitäts-Timeout (`inc/session.php`), Rollen- und Berechtigungssemantik, `session_epoch`-Vergabe und sofortiger Widerruf (UserService). S31 und S35 bleiben dokumentiert und unverändert.

**Erledigt mit 5d (PR #63):**
- **Logging:** `inc/logging.php` hat keinen Session- oder Request-Bezug mehr.
  - Neu: `logAccessDeniedEvent($context, $username, $ipAddress, $reason)` und `logLoginFailureEvent($username, $ipAddress)` erhalten Benutzer und Quell-IP ausdrücklich; Logformat, Level und Facility (`LOG_AUTH`) unverändert.
  - Die bisherigen Helfer `logAccessDenied()`/`logFailedLogin()` sind Web-Adapter in `inc/web/security_log.php` (lesen `$_SESSION['username']` bzw. `$_SERVER['REMOTE_ADDR']`, Fallback `unknown`); Name, Parameter und alle Aufrufstellen bleiben gleich. Geladen über `common.php` (und `inc/auth.php`).
- **Update-Prüfung:** `UpdateCheckService` (`inc/app/update`) wertet die Antwort aus und vergleicht die Versionen (Regeln unverändert); der Abruf läuft über die Schnittstelle `UpdateFeed` mit dem Adapter `CurlUpdateFeed` (`inc/deploy`, URL und cURL-Optionen unverändert). `pages/update.php` übersetzt nur noch die Codes in die bisherigen Texte und stellt dar. S36 bleibt unverändert und separat.
- **Geprüft, ohne weitere 5d-Kopplung:** `inc/app` und `inc/deploy` enthalten keine Zugriffe auf `$_SESSION`/`$_SERVER`/`$_GET`/`$_POST`, `$LANG`, Header, Toasts oder Session-Funktionen. Verbleibende Lesezugriffe auf Session/Request in Actions, Seiten, `requireRole`, `session_guard` und `login_user` sind Web-Adapter (🌐, siehe 3.6). Die übrigen offenen Punkte gehören zu Paket 6 (Bereinigung: `validatePassword()`, `DyndnsActionError`/`DyndnsApiReject`/`dyndnsLangKey()`) bzw. sind Sicherheitsbefunde (S34 Agent-Teil, S35, S36, S37 `db.php`) ohne Kopplungsbezug.

### 3.6 Bleibt legitim im Web (🌐)

- `common.php`, `inc/session.php`, `inc/auth.php` (Session-Aufbau), `inc/web/session_guard.php` (Revoke/Redirect/503), `login.php`, `logout.php`;
- `inc/web/web_adapter.php`, `inc/web/security_log.php`, die Toast-Funktionen, `requireRole` als Web-Gate, `csrf_input`;
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
- **Schnittstellen:** `ZoneBuildBackend`, `PublishBackend`, `ServerControlBackend`, `MonitoringBackend`, `MonitoringNotifier`, `UpdateFeed` mit ihren Adaptern.
- **Logging** (transportunabhängig): `appLog`, `logAccessDeniedEvent`, `logLoginFailureEvent` (`inc/logging.php`).

## 5. Verbindliche Reihenfolge der Arbeitspakete

| Paket | Inhalt | Status |
|---|---|---|
| **1 Server** | `ServerService` (CRUD, BIND-Reload), `ServerReadService` (Liste, Bearbeitungsdaten, Serverdiagnosen), Actions und Seite als Adapter | **erledigt** (PR #55) |
| **2 DynDNS** | `DyndnsAccountService` (anlegen, ändern, Passwort, löschen), `DyndnsReadService` (Liste ohne Hash, zulässige Zonen für beide Formulare), `DyndnsUpdateService` (API: authentifizieren, sperren, planen, anwenden, publizieren; Antwortprotokoll bleibt im Endpunkt). Die DynDNS-eigenen Record-Mutationen werden im Service gebündelt, mit den Sperrfunktionen aus `dyndns_guard`. Das Einzel-Publish wird über eine Schnittstelle mit **unverändertem** Verhalten angebunden. | **erledigt** (PR #56) |
| **3 Records/Zonen-Reste** | `pages/records.php` auf `RecordReadService`/`ZoneReadService` (inkl. Schutzflags, Diagnosen gefiltert); Rest-SQL in `record_update.php`; Logik aus `record_edit_form.php` in den Read-Service; `buildRecordContent` mit Fehlercodes | **erledigt** (PR #57) |
| **4 BIND/Publish-Infrastruktur** | Generator und Distributoren mit expliziter PDO (ohne `$GLOBALS`); einheitlicher Agent-Client; NS/Glue-Neuaufbau und Rebuild aus `helpers.php` in den Core (unter Zonensperre); `publish_single` in den Service-Pfad; `distribute_zone_file` ohne versteckte Generierung | **erledigt** (4a PR #58, 4b PR #59) |
| **5 Monitoring/Dashboard/Auth/Rest** | `MonitoringService` + Benachrichtigungs-Adapter (CLI und System-Health rufen den Service, kein `exec`); `DiagnosticsReadService` und `StatusReadService` (Layout, Dashboard, System-Health); `AuthService::verifyCredentials()` mit `login_user` als Session-Adapter; `logAccessDenied`/`logFailedLogin` mit expliziten Parametern; Update-Seite | **5a erledigt** (PR #60), **5b erledigt** (PR #61); **5c erledigt** (PR #62); **5d erledigt** (PR #63) |
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
| S30 | Keine serverseitige Mindestlänge für DynDNS-Passwörter beim Anlegen und Ändern | `DyndnsAccountService::createAccount`, `::updateAccount` (vorher `actions/dyndns_add.php`, `dyndns_update.php`) |
| S31 | Benutzer-Enumeration über die Antwortzeit: kein Dummy-`password_verify` bei unbekanntem Benutzer | `AuthService::authenticate` (vorher `inc/auth.php`, mit 5c unverändert übernommen), `DyndnsUpdateService` (vorher `api/v1/dyndns/update.php`) |
| S32 | DynDNS-Einzelpublish verteilt vor dem Commit unter Zeilensperre, ohne Publish-Advisory-Lock; `changed = 0` (nur bei vollständigem Erfolg) ohne Rücksicht auf `changed = 2`; gemeinsame `/tmp/dnsmanager_conf_{ip}`-Pfade mit Publish All. **Bewertung (12.11.2026): mittel/niedrig, nicht unmittelbar kritisch** – keine Rechteausweitung, keine Offenlegung, keine Injektion; Auslösung nur mit gültigen DynDNS-Zugangsdaten. Auswirkungen: (1) bei Fehler nach erfolgreicher Verteilung (Rollback) Abweichung DB ↔ BIND inkl. zurückgesetzter SOA-Serial, bis zum nächsten Publish; (2) Zonenzeile bis zu ~30 s je Server gesperrt (Verfügbarkeit); (3) gleichzeitiger Lauf mit Publish All kann `/tmp/dnsmanager_conf_{ip}` gegenseitig leeren → Fehler bzw. erneuter Publish nötig, kein falscher Inhalt (Dateiname je Zone). Behebung in Paket 4. **Behoben mit 4b** (Commit vor Publish, `publishZone()` unter Advisory-Lock, Tri-State). | `inc/deploy/publish_single.php`, `bind_file_distributor.php` |
| S33 | Zonenname geht ohne zusätzliche Prüfung in die BIND-`.conf` und in den Dateinamen (keine Abwehr wie S17) | `bind_file_generator.php` (`writeZoneConfFile`) – **behoben mit 4a** (`zone_name_is_safe`) |
| S34 | Feste bzw. vorhersagbare `/tmp`-Pfade (`named-checkzone-error.log`, `dnsmanager_conf_{ip}`, Agent-Lock) | Generator, Distributor, Agent – **Generator/Distributor behoben mit 4a** (`BindWorkDir`); Agent-Teil offen |
| S35 | Logout per GET ohne CSRF; Login überspringt die CSRF-Prüfung bei fehlendem Token. Ergänzung 5c (t63): Der Login-Zweig ist praktisch nicht erreichbar, weil `inc/session.php` jeder neuen Session ein Token gibt; ein POST ohne Session endet mit 403. | `logout.php`, `login.php` |
| S36 | `pages/update.php`: keine Rollenprüfung; `href` aus fremder JSON-Antwort ohne Schemaprüfung | `pages/update.php`, `UpdateCheckService` (mit 5d unverändert übernommen) |
| S37 | `system_health.php` startet das Monitoring per `exec` im Webprozess; `db.php` gibt den DB-Fehlertext per `die()` aus | `pages/system_health.php`, `inc/db.php` – **`exec` behoben mit 5b** (Monitoring im Webprozess über `MonitoringService`); `db.php`-Fehlertext offen |
| S38 | `getDiagnosticResults()` ohne Berechtigungsbezug; `records.php` filtert erst in PHP | `inc/diagnostics.php`, `pages/records.php` – **behoben** (`records.php` mit Paket 3, übrige Seiten mit 5a; Funktion entfernt) |
| F1 | Die Änderungserkennung in der Serveränderung vergleicht DB-Zeichenketten mit Integern (`'1' !== 1`). Daher erscheint „Keine Änderungen“ praktisch nie. Bei der Entkopplung bewusst unverändert übernommen. | `ServerService::updateServer` (vorher `server_update.php`) |
| F2 | Sprachschlüssel `topology_locked`, `publish_all_locked` und `zone_prefix_placeholder` fehlen; die Actions nutzen Fallback-Texte | `web/lang/*.php` |
| F3 | DynDNS-Passwortänderung prüft nicht, ob der Account existiert (Erfolgsmeldung auch bei unbekannter ID); bei der Entkopplung bewusst unverändert übernommen | `DyndnsAccountService::changePassword` (vorher `dyndns_password.php`) |
| F4 | Login mit abweichender Groß-/Kleinschreibung des Benutzernamens gelingt (Kollation `utf8mb4_unicode_ci`); die Session speichert die eingegebene Schreibweise, daher widerruft die nächste Anfrage die Session (`username_changed`). Kein Rechtegewinn; mit 5c unverändert übernommen. | `AuthService::authenticate`, `login_user` |
| F5 | Update-Seite: Der Fehlerrahmen beginnt immer mit „Verbindung zum Update-Server fehlgeschlagen“ (`update_error`), auch bei ungültiger Antwort; bei Verbindungsfehlern erscheint der Text doppelt (`update_error` + `update_connection_failed`). Nur Darstellung; mit 5d unverändert übernommen. | `pages/update.php` |

## 7. Änderungsprotokoll dieses Dokuments

| Datum | Paket | Änderung |
|---|---|---|
| 12.11.2026 | – | Erstes vollständiges Inventar, Basis `71eaa4e` |
| 12.11.2026 | 1 | Serververwaltung entkoppelt (PR #55, gemergt als `4860f05`) |
| 12.11.2026 | 1 | Einschränkung „nur ein lokaler Server“ (nicht nachgewiesen race-sicher) dokumentiert |
| 12.11.2026 | 2 | DynDNS-Kontenverwaltung, Lesezugriffe und Update-API entkoppelt (PR #56, gemergt als `426fd30`); S32 bewertet |
| 12.11.2026 | 2 | Review-Korrektur PR #56: DynDNS-Protokoll und Sprachschlüssel aus dem Core entfernt; `/tmp`-Zonendateien (Paket 4) und Fundort S30 ergänzt |
| 12.11.2026 | 3 | Records-Seite, Bearbeitungsformular, `record_update.php` und Content-Builder entkoppelt (PR #57, gemergt als `b41a34d`); `helpers.php` abgegrenzt; Abweichung D1 akzeptiert |
| 12.11.2026 | 4a | BIND-/Publish-Infrastruktur entkoppelt (PR #58, gemergt als `5f7bd6a`; D4–D7 akzeptiert): explizite Abhängigkeiten, `BindAgentClient`, `BindWorkDir` (S34), `zone_name_is_safe` (S33), `BindZoneBuildBackend`, ServerService-NS/Glue atomar |
| 12.11.2026 | 4b | S32: DynDNS-Einzelpublish nach dem Commit über `PublishService::publishZone()` mit gemeinsamer Zonenlogik, Lock und Tri-State; Retry ausstehender Publishes; Review-Korrektur gelöschte Zone (PR #59, gemergt als `43e4823`; D8–D11 akzeptiert) |
| 12.11.2026 | 5a | Status- und Diagnose-Lesezugriffe (Dashboard, Layout, System-Health) über `SystemStatusReadService`/`DiagnosticReadService`; `getDiagnosticResults()` entfernt (S38) (PR #60, gemergt als `afb6c0c`; D12 akzeptiert) |
| 12.11.2026 | 5b | Monitoring und Systemprüfungen: `MonitoringService` mit Laufsperre, Agent-/Mail-Adapter, CLI als Adapter, S37 (`exec`) behoben, `SystemCheckService`; Review-Korrektur Runner-Fehlerbehandlung (PR #61, gemergt als `aabe398`; D13–D16 akzeptiert) |
| 12.11.2026 | 5c | Authentifizierung: `AuthService` (Zugangsdaten/Kontostatus, Session-Revalidierung inkl. `session_epoch`); `login_user` und `revalidate_session` als Web-Adapter; Sicherheitsverhalten unverändert; Testbefunde S35 (Ergänzung) und F4 (PR #62, gemergt als `71fec49`) |
| 13.11.2026 | 5d | Restkopplungen: Logging-Helfer mit expliziten Parametern (Web-Adapter `inc/web/security_log.php`), Update-Prüfung als `UpdateCheckService` mit `UpdateFeed`/`CurlUpdateFeed`; S36 unverändert; Befund F5 (PR #63, gemergt als `c0965d2`) |
