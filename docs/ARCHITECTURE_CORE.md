# DNS Manager – Core-/UI-Trennung: Architektur-Inventar und Restarbeiten

Stand: 13.11.2026, Basis `main` = `2a077a2` (nach Paket 6). Dieses Dokument ist die zentrale Übersicht für den Meilenstein
„vollständige Trennung der Fachlogik von der Weboberfläche“. **Der Meilenstein ist abgeschlossen** (PR #64, Betriebsprüfung auf ns1 am 13.11.2026).

## 0. Ergebnis (Paket 6, Meilenstein abgeschlossen)

- **Die Core-/UI-Trennung ist abgeschlossen.** Mit Paket 6 sind die letzten Kopplungen K1–K3 (Statusauswertungen der Diagnosen in
  `pages/zones.php`, `pages/system_health.php`, `pages/servers.php`) in den Core verlagert und die ungenutzten Helfer B1–B3 entfernt (3.8).
  - Alle fachlichen Operationen sind über webunabhängige Services in `web/inc/app/` erreichbar (Abschnitt 2).
  - Actions, Seiten, Templates, API und Skripte enthalten keine SQL-, Transaktions-, Shell-, cURL- oder Agent-Aufrufe.
  - Core und Infrastruktur hängen nicht von Session, HTTP, `$LANG`, Web-Bootstrap oder `global`/`$GLOBALS` ab.
- **MCP:** Die Core-Schnittstellen sind für eine spätere MCP-Anbindung geeignet. Die Anbindung benötigt aber weiterhin einen
  **eigenen, sicheren Transport- und Autorisierungsadapter** (Abschnitt 7). Diese Arbeit ist nicht Teil des Meilensteins.
- **Sicherheits- und Funktionsbefunde** sind vollständig in Abschnitt 6 aufgeführt und von den Architekturarbeiten getrennt.

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
| Zonen | ✅ | `ZoneService` (anlegen, ändern inkl. Serverzuweisung, löschen; `TopologyLock`; Bearbeitungsstand S21), `ZoneReadService` (Liste, Detail, zuweisbare Server, Zonendiagnosen, Statusauswertung der Zonenübersicht), `ZoneAccess` | – (Statusauswertung seit Paket 6 im Core, K1) |
| Records | ✅ **mit Paket 3** | `RecordService` (anlegen, ändern, löschen inkl. AutoPTR, DynDNS-Schutz), `RecordReadService` (Liste, Verwaltungsansicht mit Schutzkennzeichen), `RecordContentBuilder`, `RecordContentParser` | `RecordService` nutzt `glue_check.php`-Lesefunktionen |
| Publish (alle Zonen) | ✅ / 🔧 | `PublishService` (Advisory-Lock, Tri-State `bind_dirty`/`changed`), `PublishBackend` | Generator/Distributor hinter `LegacyBindPublishBackend` (siehe 3.4) |
| **Server** | ✅ **mit Paket 1** | `ServerService` (anlegen, ändern inkl. Topologie-Lock, NS/Glue-Neuaufbau und `bind_dirty`, löschen, BIND-Reload), `ServerReadService` (Liste, Bearbeitungsdaten, Serverdiagnosen inkl. Gruppierung und Statusauswertung), `ServerControlBackend` + `LegacyAgentServerControlBackend` | – (Auswertung der Serverdiagnosen seit Paket 6 im Core, K3) |
| DynDNS-Konten | ✅ **mit Paket 2** | `DyndnsAccountService`, `DyndnsReadService` (auf Basis `dyndns_guard.php`) | – |
| DynDNS-Update-API | ✅ **mit Paket 2/4b** | `DyndnsUpdateService` (Commit vor Publish, Retry ausstehender Publishes), Veröffentlichung über `PublishService::publishZone()` | – |
| Monitoring/Diagnose | ✅ **mit 5b** | `MonitoringService` (Lauf mit Laufsperre, Speichern, Orphan-Bereinigung, Benachrichtigung, Log-Retention), `MonitoringBackend` + `AgentMonitoringBackend`, `MonitoringNotifier` + `PhpMailerNotifier` | CLI-Skripte und Systemstatus-Seite als Adapter (`inc/monitoring_runner.php`) |
| Dashboard/Layout/System-Health/Update-Prüfung | ✅ **mit 5a/5b/5d** | `SystemStatusReadService`, `DiagnosticReadService`, `SystemCheckService` (Systemprüfungen ohne Sprachtexte), `UpdateCheckService` + `CurlUpdateFeed` | – (Problemeinträge der Systemstatus-Seite seit Paket 6 im Core, K2) |
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
- Rollenprüfung in `pages/dyndns.php` erst nach dem Layout (S39, siehe Abschnitt 6).

**Bereinigung (Paket 6):** `DyndnsActionError` und `dyndnsLangKey()` waren ungenutzt und sind entfernt. **Korrektur:** `DyndnsApiReject` wird weiterhin verwendet (`dyndnsApplyApiRecordUpdate()`, abgefangen in `DyndnsUpdateService`) und bleibt; die frühere Einordnung als ungenutzt war falsch.

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
- `validatePassword()` aus `helpers.php` ist mit Paket 6 entfernt (ungenutzt); die `rebuild_*`-Funktionen sind mit 4a entfernt.
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
- `pages/system_health.php`: Diagnosen über `DiagnosticReadService` (die Darstellungsstruktur `inc/web/diagnostics_view.php` ist mit Paket 6, K2, entfallen).
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
- **Geprüft, ohne weitere 5d-Kopplung:** `inc/app` und `inc/deploy` enthalten keine Zugriffe auf `$_SESSION`/`$_SERVER`/`$_GET`/`$_POST`, `$LANG`, Header, Toasts oder Session-Funktionen. Verbleibende Lesezugriffe auf Session/Request in Actions, Seiten, `requireRole`, `session_guard` und `login_user` sind Web-Adapter (🌐, siehe 3.6). Die Bereinigung ungenutzter Helfer ist mit Paket 6 erledigt (siehe 3.8); die übrigen Punkte sind Sicherheitsbefunde (S34 Agent-Teil, S35, S36, S37 `db.php`) ohne Kopplungsbezug.

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

### 3.8 Paket 6 – Abschlussprüfung (erledigt, PR #64, `main` `2a077a2`)

**Vorgehen (prüfbar wiederholbar):**
- Suche in `actions/`, `pages/`, `templates/`, `api/`, `scripts/` und den Einstiegsdateien nach `->prepare|query|exec|beginTransaction|commit|rollBack(`, SQL-Schlüsselwörtern, `exec`/`shell_exec`/`proc_open`/`curl_init` und `global`/`$GLOBALS`.
- Suche in `inc/app/`, `inc/deploy/` und den dort eingebundenen Helfern nach `$_SESSION`/`$_SERVER`/`$_GET`/`$_POST`, `$LANG`, `header(`, Toasts, Session-Funktionen, `global`/`$GLOBALS` und Einbindung von `common.php`/`session.php`/`helpers.php`.
- Suche nach ungenutzten Funktionen, Klassen und öffentlichen Methoden über alle versionierten PHP-Dateien ohne `vendor/`.
- Abgleich der Diagnose-Auswertungen in Seiten (`status === …`, `not_found`).

**Ergebnis:**
1. **Fachliche Operationen:** alle über Services erreichbar (Abschnitt 2).
2. **Web-Schicht:** keine SQL-, Transaktions-, Shell-, cURL- oder Agent-Aufrufe. Zwei bewusst akzeptierte Bootstrap-Stellen: Die CLI-Skripte übernehmen die Verbindung aus `$GLOBALS['pdo']` (`inc/db.php`), `pages/records.php` nutzt `global $zone, $LANG` in einer lokalen Darstellungsfunktion.
3. **Core/Infrastruktur:** frei von Session, HTTP, `$LANG`, Web-Bootstrap und `global`/`$GLOBALS`. Unveränderliche Konfigurationskonstanten (z. B. `PASSWORD_MIN_LENGTH`, `MAILER_*`, `REMOTE_API_BASE`, `CURL_SSL_*`, `BIND_BASE_DIR`) werden direkt gelesen; jeder Aufrufkanal lädt sie über `AppBootstrap`/`ui_config.php`.
4. **Legacy-Helfer eindeutig zugeordnet:**
   - Core-Helfer: `validators.php` (rein), `dyndns_guard.php`, `glue_check.php`, `soa_serial.php`;
   - Infrastruktur (🔧): `inc/deploy/*`;
   - Web (🌐): `helpers.php`, `session.php`, `auth.php`, `ttl_defaults.php`, `dkim_helpers.php`, `asset_registry.php`, `inc/web/*`;
   - Bootstrap: `inc/db.php` (S37-Teil offen), CLI: `helpers_cli.php`;
   - gemeinsam: `logging.php`, `monitoring_runner.php`.
5. **MCP-Eignung:** siehe Abschnitt 7.
6. **Befunde:** vollständig in Abschnitt 6, getrennt nach erledigt/offen.

**Erledigt mit Paket 6:**
- **K1:** Statusauswertung der Zonenübersicht aus `pages/zones.php` in `ZoneReadService::getZoneStatusOverview()` (maßgeblicher Diagnose-Eintrag je Zone, Gesamtbewertung, Problemeinträge; gleiche Berechtigung und Reihenfolge wie `listZoneDiagnostics()`) und `ZoneReadService::zoneDisplayStatus()` (Anzeigestatus inkl. ausstehendem Publish). Regeln unverändert: erster Eintrag gilt, Ersetzen nur `ok` → nicht `ok` bzw. `warning` → `error`; `error`/`not_found` → Fehler, `warning` → Warnung, sonst bei ausstehendem Publish `changed`. Die Seite übersetzt nur noch in die bisherige Darstellung.
- **K2:** Die Systemstatus-Seite bewertet nicht mehr selbst. Die Kennzeichen (Fehler/Warnung je Konfiguration, Server, Zonen) kommen aus der vorhandenen `DiagnosticReadService::getOverview()['summary']`; die Problemeinträge liefert `getOverview()['problems']` getrennt nach `server` (Prüfart `server_status`), `conf` (`zone_conf_status`) und `zone` (`zone_status`). Regeln unverändert: `server` nur Server mit `server_status` ungleich `ok`; `conf` alle Server, deren `zone_conf_status` nicht `ok` ist – auch Server ohne diese Prüfart; `zone` alle Zonendiagnosen ungleich `ok` (andere Prüfart: Status null). `inc/web/diagnostics_view.php` ist entfallen.
- **K3:** Die Serverübersicht bewertet nicht mehr selbst: `ServerReadService::getServerStatusOverview()` liefert den Status je Server (`server_status`), Fehler-/Warnungskennzeichen und die Problemeinträge (je Server `server_status`, dann `zone_conf_status`; fehlende Prüfarten entfallen). Die Gruppierung je Server und Prüfart (`getServerDiagnosticChecks()`, ein späterer Eintrag derselben Prüfart überschreibt) gibt es nur noch einmal; `DiagnosticReadService` nutzt sie mit.
- **Darstellung:** Die Seiten `zones.php`, `system_health.php` und `servers.php` übersetzen nur noch den vom Core ermittelten Zustand in Symbole, HTML und Sprachtexte. Die je Seite unterschiedlichen, bisherigen Regeln (z. B. Zonendiagnose anderer Prüfart: in der Zonenübersicht kein Problemeintrag, im Systemstatus ein Eintrag; Serverbezeichnung ohne Namen `unbekannt` bzw. `(unbekannt)`) sind bewusst unverändert und nicht vereinheitlicht.
- **B1–B3:** entfernt `dyndnsLangKey()`, `DyndnsActionError` (`dyndns_guard.php`) und `validatePassword()` (`helpers.php`), jeweils nachweislich ungenutzt. `DyndnsApiReject` bleibt (verwendet).

**Bewusste Abweichung (nur Log):** D17 – Die Seiten schreiben weniger PHP-Hinweise ins Log. Bisher wurden Schlüssel und Meldung jedes Diagnoseeintrags ausgewertet, auch unauffälliger; dabei entstanden Warnungen „Undefined array key“ (fehlende Prüfart) und Deprecation-Hinweise `htmlspecialchars(null)` (Meldung NULL). Jetzt werden nur noch Problemeinträge formatiert. HTML, Status und Reihenfolge sind unverändert (t65 K07, t66 H01–H04).

**Bewusst belassen (keine Kopplung):**
- `RecordReadService::listRecords()` und `UserReadService::getUser()` haben derzeit keinen Web-Aufrufer; sie sind berechtigte, paginierte bzw. einzelne Lesezugriffe für weitere Kanäle (MCP) und werden bei deren Anbindung erneut getestet.
- `pages/records.php` ordnet Diagnose-Status nur Symbolen zu (Darstellung).

**Verbleibende Architektur-Restarbeiten:** keine.

## 4. Wiederverwendbare Bausteine

- **Basis:** `Actor`, `ActorAccess`, `ZoneAccess`, `TopologyLock`, `ServiceResult`/`ServiceMessage`, `SchemaCheck`, `AppBootstrap`.
- **Validierung** (rein): `validators.php` – `validateDnsRecord`, `validateZoneInput`, `isValidFqdn`, `isValidSoaName`, `validateDyndnsHostname`, `normalizeDyndnsHostname`; `isDuplicateRecord` liest die DB.
- **DynDNS:** `dyndns_guard.php` (siehe 3.2).
- **Records:** `glue_check.php` (`isGlueRecord`, `isNsRecordReferencedByGlue` rein; `isProtectedNsRecord` liest), `ttl_defaults.php`, `soa_serial.php`, `dkim_helpers.php`, `AutoPtr`.
- **Schnittstellen:** `ZoneBuildBackend`, `PublishBackend`, `ServerControlBackend`, `MonitoringBackend`, `MonitoringNotifier`, `UpdateFeed` mit ihren Adaptern.
- **Logging** (transportunabhängig): `appLog`, `logAccessDeniedEvent`, `logLoginFailureEvent` (`inc/logging.php`).
- **Diagnose-Auswertung (Paket 6):** `ZoneReadService::getZoneStatusOverview()`, `ZoneReadService::zoneDisplayStatus()`, `ServerReadService::getServerDiagnosticChecks()`, `ServerReadService::getServerStatusOverview()`, `DiagnosticReadService::getOverview()` (`summary`, `problems`).

## 5. Verbindliche Reihenfolge der Arbeitspakete

| Paket | Inhalt | Status |
|---|---|---|
| **1 Server** | `ServerService` (CRUD, BIND-Reload), `ServerReadService` (Liste, Bearbeitungsdaten, Serverdiagnosen), Actions und Seite als Adapter | **erledigt** (PR #55) |
| **2 DynDNS** | `DyndnsAccountService` (anlegen, ändern, Passwort, löschen), `DyndnsReadService` (Liste ohne Hash, zulässige Zonen für beide Formulare), `DyndnsUpdateService` (API: authentifizieren, sperren, planen, anwenden, publizieren; Antwortprotokoll bleibt im Endpunkt). Die DynDNS-eigenen Record-Mutationen werden im Service gebündelt, mit den Sperrfunktionen aus `dyndns_guard`. Das Einzel-Publish wird über eine Schnittstelle mit **unverändertem** Verhalten angebunden. | **erledigt** (PR #56) |
| **3 Records/Zonen-Reste** | `pages/records.php` auf `RecordReadService`/`ZoneReadService` (inkl. Schutzflags, Diagnosen gefiltert); Rest-SQL in `record_update.php`; Logik aus `record_edit_form.php` in den Read-Service; `buildRecordContent` mit Fehlercodes | **erledigt** (PR #57) |
| **4 BIND/Publish-Infrastruktur** | Generator und Distributoren mit expliziter PDO (ohne `$GLOBALS`); einheitlicher Agent-Client; NS/Glue-Neuaufbau und Rebuild aus `helpers.php` in den Core (unter Zonensperre); `publish_single` in den Service-Pfad; `distribute_zone_file` ohne versteckte Generierung | **erledigt** (4a PR #58, 4b PR #59) |
| **5 Monitoring/Dashboard/Auth/Rest** | `MonitoringService` + Benachrichtigungs-Adapter (CLI und System-Health rufen den Service, kein `exec`); `DiagnosticsReadService` und `StatusReadService` (Layout, Dashboard, System-Health); `AuthService::verifyCredentials()` mit `login_user` als Session-Adapter; `logAccessDenied`/`logFailedLogin` mit expliziten Parametern; Update-Seite | **5a erledigt** (PR #60), **5b erledigt** (PR #61); **5c erledigt** (PR #62); **5d erledigt** (PR #63) |
| **6 Abschlussprüfung** | Automatischer Nachweis: keine SQL-, Transaktions-, Agent- oder Shell-Aufrufe in `actions/`, `pages/`, `templates/`, `api/`, `scripts/` außer Bootstrap; jede Fachoperation über einen Service erreichbar; Inventar ohne ❌/🟡 | **erledigt** (PR #64; K1–K3, B1–B3, Inventar im Zielzustand) |

**Begründete Anpassungen gegenüber der vorgegebenen Reihenfolge:**
1. **Paket 1 enthält die Serverdiagnosen**, die `pages/servers.php` anzeigt. Die allgemeinen Diagnose-Lesezugriffe für Dashboard, Layout und Records bleiben in Paket 3 bzw. 5.
2. **Paket 2 ändert die Publish-Semantik des DynDNS-Pfads nicht.** Gemeint sind: Publish vor dem Commit, kein Advisory-Lock, `changed = 0`. Diese Punkte sind Sicherheits- und Konsistenzbefunde mit Verhaltensänderung (siehe 6) und werden in Paket 4 bzw. als eigene Sicherheitsausnahme entschieden. So bleibt Paket 2 eine reine Entkopplung.
3. **Der NS/Glue-Neuaufbau aus `helpers.php` wandert nach Paket 4**, nicht nach Paket 1. Er wird von Zonen- und Serverpfaden gemeinsam genutzt und braucht die Zonensperre. Das ist Infrastruktur-Umbau, keine Serverlogik.

## 6. Befunde außerhalb der Architekturarbeit

Vollständige Liste der dokumentierten Sicherheits- (S) und Funktionsbefunde (F) sowie der In-Flight-Risiken (I). Sie sind **keine
Architektur-Restarbeiten** und werden in einer gesonderten Sicherheits- bzw. Funktionsüberarbeitung entschieden. Sicherheitsrelevante Punkte
werden nur nach gesonderter Freigabe vorgezogen. Aus der früheren S-Reihe (bis S28, Projektübergabe) ist nur S28 offen; die übrigen sind mit früheren PRs behoben.

**Übersicht nach Status:**
- **Offen:** S34 (Agent-Teil), **S40** (teilweise, priorisiert), **S41** (zurückgestellt), I1, I2, F1–F8.
- **Teilweise behoben:** S34 (Generator/Distributor behoben, Agent offen), S40 (HTTP auf ns1 abgeschaltet; Secure-Cookie mit PR #67, `main` `ce3b245`; Zertifikat/HSTS offen).
- **Behoben:** S32 (4b), S33 (4a), S38 (Paket 3/5a); **mit Security-Paket SP1 (PR #65, `main` `bc8b607`):** S29, S30, S31; **mit SP2 (PR #66, `main` `4f37f08`):** S28, S35; **S42** (technisch abgeschlossen 13.11.2026: PR #68, #70; Umstellung ns1/ns2, siehe `docs/AGENT_TLS.md`); **mit SP3a (PR #73, `main` `a6339d9`):** S39; **mit SP3b (PR #74, `main` `2f7a9eb`):** S36 (Teil b, damit vollständig), S37 (Teil b, damit vollständig); zusätzlich Sicherheitsheader `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` für alle PHP-Antworten (noch keine CSP).

**Betriebsbefund Schutzmechanismen (Stand 13.11.2026, rein lesend erhoben, nichts geändert):**
- **Fail2Ban:** ns1 installiert (1.1.0), aber `disabled`/`inactive`; der installierte Filter `filter.d/dnsmanager.conf` ist veraltet (0 Treffer, siehe S41), ein `dnsmanager`-Jail ist **nicht** eingerichtet (nur `defaults-debian.conf` mit `banaction = nftables` und aktivem `sshd`-Jail bei Start). ns2: nicht installiert. Die Fail2Ban-Konfiguration des Repositorys (`fail2ban/`) ist damit auf keiner VM wirksam. Die Protokollkette wäre vorhanden: `LOG_TARGET = syslog`, rsyslog aktiv, Sicherheitsereignisse über `LOG_AUTH` in `/var/log/auth.log`.
- **Firewall:** auf ns1 und ns2 keine Regeln (iptables-Policy ACCEPT, kein nftables-Regelwerk, ufw inaktiv).
- **Erreichbarkeit:** ns1 – Apache auf `192.168.53.122:443` und `:80` sowie `127.0.0.1:443` (Oberfläche, DynDNS-API und Agent unter derselben Herkunft, `Require all granted`, keine IP-Einschränkung für Oberfläche und DynDNS-API); ns2 – Apache nur `192.168.53.125:443` und `127.0.0.1:443` (nur Agent). Beide im Netz `192.168.53.0/24`, Standard-Gateway `192.168.53.250`; ob die Dienste von außerhalb dieses Netzes erreichbar sind, ist von der VM aus nicht feststellbar. SSH auf allen Adressen, MariaDB nur lokal, BIND auf der LAN-Adresse.
- **Wirksamer Schutz für Login und DynDNS-API derzeit:** CSRF-Token (Login), bcrypt-Kosten 12 (≈ 240 ms je Prüfung, auch für unbekannte Benutzer seit SP1), Protokollierung von `login_failure`/`access_denied`; **keine** Ratenbegrenzung oder Kontosperre in der Anwendung und **kein aktives Fail2Ban**. Agent: Bearer-Token (`hash_equals`) plus IP-Freigabeliste (`api_allowed_ips`: ns1 nur `127.0.0.1`; ns2 `127.0.0.1` und `192.168.53.122`); über HTTP gesperrt.

**HTTPS-Zertifikat – Zielzustand und Weg (S40-E3, dokumentiert, nicht umgesetzt):**
- **Ist:** selbstsignierte *snakeoil*-Zertifikate (ns1 `CN=ns1`, ns2 `CN=ns2`, SAN jeweils nur Kurzname), Zugriff per IP-Adresse; Apache ohne `ServerName` (Warnungen AH00558/AH01909). Browser zeigen Zertifikatswarnungen; Agent-Aufrufe ohne TLS-Prüfung (S42).
- **Ziel:** fester Hostname je Dienst (z. B. `dnsmanager.<interne Domain>` für die Oberfläche/DynDNS-API, `ns1.<…>`/`ns2.<…>` für die Agenten), auflösbar über das interne DNS; Zertifikat einer vertrauenswürdigen CA mit passenden Subject Alternative Names (alle verwendeten Namen, optional die IP-Adresse als `IP:`-SAN).
- **Wege:** (a) interne CA (vorhandene Unternehmens-PKI oder eigene kleine CA), CA-Zertifikat auf Admin-Clients, ns1 (für Agent-Aufrufe) und DynDNS-Clients verteilen; (b) öffentliche CA per ACME **DNS-01** (setzt eine öffentlich delegierte Domain voraus; benötigt keinen Port 80 und ist mit der HTTP-Abschaltung vereinbar).
- **Umsetzungsschritte:** Namen festlegen und im DNS anlegen → Zertifikat ausstellen → `SSLCertificateFile`/`SSLCertificateKeyFile` und `ServerName` im HTTPS-VirtualHost → Clients auf den Namen umstellen → Agent-Prüfung aktivieren (`CURL_SSL_VERIFYPEER = true`, `CURL_SSL_VERIFYHOST = 2`, `AGENT_TLS_CA_FILE`; Ablauf in `docs/AGENT_TLS.md`) → erst danach HSTS auf dem Namen (Browser wenden HSTS nicht auf IP-Adressen an; mit nicht vertrauenswürdigem Zertifikat würde HSTS den Zugriff blockieren), zunächst mit kurzem `max-age`.
- **DynDNS-Clients:** Derzeit sind keine aktiven externen Clients erkennbar (`dyndnstest` zuletzt 18.10.2026 aus Tests auf ns1, `n1race` nie aktualisiert; keine Anfragen fremder IP-Adressen in den Logs). Ob Clients die Zertifikatsprüfung deaktivieren oder ein Zertifikat hinterlegt haben, lässt sich serverseitig nicht feststellen; künftige Clients müssen HTTPS mit Zertifikatsprüfung verwenden (mit dem heutigen selbstsignierten Zertifikat wäre das nur mit deaktivierter Prüfung oder hinterlegtem Zertifikat möglich).

**Fail2Ban – vorbereitete Konfiguration (S41-E1, isoliert getestet, nicht aktiviert):**
- Filter `dnsmanager-login.conf`: nur `event=login_failure` (Repository-Regex); `access_denied` wird weiter protokolliert, aber nicht gesperrt. Gegen das reale `auth.log`: 16 Treffer.
- Jail `dnsmanager-login`: `maxretry = 5`, `findtime = 600`, `bantime = 900`, `port = http,https`, `logpath = /var/log/auth.log`, jail-spezifisch `ignoreip = 127.0.0.1/8 ::1` (lokale Agent-/Monitoring-Aufrufe); keine Netz- oder Workstation-Ausnahme.
- `fail2ban-client -t` mit Produktionswerten erfolgreich; beim Start aktiv wären `dnsmanager-login` **und `sshd`** (Ubuntu-Standard `defaults-debian.conf`, nftables, `maxretry 5`, `findtime/bantime 10m`).
- Isolierte Instanz (eigener Socket, Aktion `dummy`, synthetisches Log, Dokumentations-IPs): Sperre bei der 5. Fehlanmeldung, nicht bei 4; Loopback und `access_denied` ohne Sperre; DynDNS-Anfragen ohne Zugangsdaten (`user=empty`) zählen mit; manuelles Entsperren funktioniert; keine nftables-Regel, Dienst weiter deaktiviert. Beobachtet: Test mit `findtime = 20` s – ein neuer Versuch 29 s nach vier Fehlversuchen wurde noch als vierter gezählt (Erklärung siehe nächster Punkt).
- **Klärung `findtime` (13.11.2026, Quellcode Fail2Ban 1.1.0 auf ns1 und isolierte Nachtests; korrigiert eine am selben Tag zunächst gegebene Erklärung):** kein Test- oder Zeitzonenfehler – Zeitstempel und effektive Konfiguration korrekt. Quellpfad (`/usr/lib/python3/dist-packages/fail2ban/server/`):
  - Zählen: `filter.py` `processLineAndAdd()` → `failmanager.py` `addFailure()` (Z. 72) → `ticket.py` `FailTicket.adjustTime(time, maxTime)` (Z. 255–264, Aufruf Z. 89). Liegt der erste gezählte Versuch eines noch vorhandenen Eintrags mehr als `findtime` zurück, wird der Zähler über die Rate geschätzt statt exakt gefenstert: `retry = round(retry / (t − firstTime) × findtime)`, `firstTime = t − findtime`.
  - Verwerfen: `failmanager.py` `cleanup()` (Z. 129) entfernt Einträge, deren **letzter** Versuch ≤ jetzt − `findtime` liegt. Aufgerufen nur über `filter.py` `performSvc()` (Z. 460–467, höchstens alle 5 s), und zwar periodisch in der Hauptschleife des Backends alle 10 Durchläufe (`filterpyinotify.py` Z. 361–362, `filterpoll.py` Z. 116–118, `filtersystemd.py` Z. 388–389; ca. alle 10 s), nach Sperren (`filter.py` Z. 458) und alle 100 verarbeiteten Zeilen (Z. 727–728).
  - Wirkung: Eine **ruhige Pause** länger als `findtime` (+ bis ca. 10 s Bereinigungsintervall) setzt den Zähler vollständig zurück – nachgewiesen isoliert mit Backend `auto` (wirksam: pyinotify, wie für den vorbereiteten Jail) und `polling`: `findtime = 20` s, 4 Fehlversuche, 35 s Pause → 0 zählende IPs, der nächste Versuch zählt 1. Nur in dem kurzen Intervall bis zur nächsten Bereinigung bzw. bei fortlaufend verteilten Versuchen greift die Ratenschätzung (29-s-Beobachtung: `round(4 / 29 × 20) = 3`, +1 = 4). Bei 5/600 entspricht das praktisch dem nominellen Fenster (Sperre ab etwa 5 Versuchen je 10 Minuten); **die zuvor genannte Folgerung „nach 15 Minuten Pause zählt der nächste Versuch als vierter“ war falsch.**
- **Wirkung einer Sperre:** nftables sperrt für die Quell-IP die Ports 80/443 von ns1 – gesamte Weboberfläche, DynDNS-API und Agent dieses Hosts; Monitoring/Publish nicht betroffen (Aufrufe des lokalen Agenten über Loopback, ns2 wird von ns1 aus angesprochen). Der `sshd`-Jail sperrt zusätzlich Port 22 bei SSH-Fehlversuchen.

**Security-Hardening (Pakete):** SP1 Zugangsdaten und Geheimnisse (S29, S30, S31) → SP2 Sitzung und Anfrageschutz (S28 inkl. SameSite, S35; erledigt, PR #66) → **S40/S41 priorisiert** (HTTPS-Erzwingung, Fail2Ban) → SP3 Zugriffskontrolle und Fehlerausgabe (S39, S36, S37b, optional Sicherheitsheader) → SP4 Agent und Nebenläufigkeit (S34b, I2, Entscheidung zu I1). I1/I2 sind nicht akzeptiert.

**Passwortpolitik:** Die technische Durchsetzung von `PASSWORD_MIN_LENGTH` für Benutzer- und DynDNS-Passwörter ist mit SP1 erledigt. Die **Beispielkonfiguration** `ui_config-sample.php` setzt den Wert seit SP3b auf **12** (gilt für neu gesetzte Passwörter; bestehende Passwörter bleiben gültig). Die **bestehende Konfiguration der Testinstallation** ns1 bleibt bewusst unverändert bei **4** (sicherheitstechnisch unzureichend); eine Anpassung dort sowie die Vereinheitlichung mit der clientseitigen Validierung (DynDNS-Anlageformular `minlength="6"`, nur Browserprüfung) werden gesondert entschieden.

| Nr. | Befund | Ort |
|---|---|---|
| S28 | Keine absolute Höchstdauer einer Sitzung. **Behoben mit SP2:** `SESSION_MAX_LIFETIME_SECONDS` (Standard 43200 s = 12 h) ab `login_time` (gesetzt beim Login, nie verlängert), geprüft vor jeder Fachoperation, unabhängig von Inaktivität und `session_epoch`; angemeldete Sessions ohne `login_time` gelten als abgelaufen; Info-Log `event=session_expired reason=max_lifetime\|login_time_missing`; bei jedem Ablauf frische anonyme Session-ID; Session-Cookie `SameSite=Lax`. Periodische ID-Erneuerung bewusst nicht eingeführt. | `inc/session.php`, `inc/auth.php` |
| S29 | Das Bearbeitungsformular gab den gespeicherten API-Token im HTML aus (`value` des Passwortfelds). **Behoben mit SP1:** kein Token in HTML, Formular oder Read-Service (`getServerForEdit` ohne Token); leeres Feld = Token unverändert (Spalte wird nicht geschrieben, Prüfung unter Zeilensperre), neuer Token ersetzt nach Validierung; Cache-Header ausdrücklich gesetzt (`session_cache_limiter('nocache')`, bisher nur über `php.ini`). | `templates/server_edit_form.php`, `ServerService::updateServer`, `ServerReadService::getServerForEdit` |
| S30 | Keine serverseitige Mindestlänge für DynDNS-Passwörter beim Anlegen und Ändern. **Behoben mit SP1:** `PASSWORD_MIN_LENGTH` bei Anlage und neu gesetztem Passwort (`dyndns.create.too_short`, `dyndns.update.too_short`); leer bei Änderung = unverändert. | `DyndnsAccountService::createAccount`, `::updateAccount` |
| S31 | Benutzer-Enumeration über die Antwortzeit (kein `password_verify` bei unbekanntem Benutzer). **Behoben mit SP1:** Prüfung gegen festen Dummy-Hash (bcrypt, Kosten 12, `DummyPasswordHash`); gemessen auf ns1: unbekannt ≈ bekannt-falsch ≈ 240 ms (vorher < 1 ms gegenüber 240 ms). Keine vollständige Timing-Gleichheit zugesagt. | `AuthService::authenticate`, `DyndnsUpdateService` |
| S32 | DynDNS-Einzelpublish verteilt vor dem Commit unter Zeilensperre, ohne Publish-Advisory-Lock; `changed = 0` (nur bei vollständigem Erfolg) ohne Rücksicht auf `changed = 2`; gemeinsame `/tmp/dnsmanager_conf_{ip}`-Pfade mit Publish All. **Bewertung (12.11.2026): mittel/niedrig, nicht unmittelbar kritisch** – keine Rechteausweitung, keine Offenlegung, keine Injektion; Auslösung nur mit gültigen DynDNS-Zugangsdaten. Auswirkungen: (1) bei Fehler nach erfolgreicher Verteilung (Rollback) Abweichung DB ↔ BIND inkl. zurückgesetzter SOA-Serial, bis zum nächsten Publish; (2) Zonenzeile bis zu ~30 s je Server gesperrt (Verfügbarkeit); (3) gleichzeitiger Lauf mit Publish All kann `/tmp/dnsmanager_conf_{ip}` gegenseitig leeren → Fehler bzw. erneuter Publish nötig, kein falscher Inhalt (Dateiname je Zone). Behebung in Paket 4. **Behoben mit 4b** (Commit vor Publish, `publishZone()` unter Advisory-Lock, Tri-State). | `inc/deploy/publish_single.php`, `bind_file_distributor.php` |
| S33 | Zonenname geht ohne zusätzliche Prüfung in die BIND-`.conf` und in den Dateinamen (keine Abwehr wie S17) | `bind_file_generator.php` (`writeZoneConfFile`) – **behoben mit 4a** (`zone_name_is_safe`) |
| S34 | Feste bzw. vorhersagbare `/tmp`-Pfade (`named-checkzone-error.log`, `dnsmanager_conf_{ip}`, Agent-Lock) | **Teil a (Generator/Distributor): behoben mit 4a** (`BindWorkDir`). **Teil b (Agent-Lock und -Temporärdateien): offen.** |
| S35 | Logout per GET ohne CSRF; Login überspringt die CSRF-Prüfung bei fehlendem Token (praktisch unerreichbar). **Behoben mit SP2:** Logout nur per POST mit gültigem CSRF-Token (GET → 405 mit `Allow: POST`, ohne Session-Zugriff; fehlendes/falsches Token bei Anmeldung → 403 ohne Abmeldung; ohne Anmeldung → Login); Sidebar-Formular statt Link; jeder Login-POST benötigt ein gültiges Token. | `logout.php`, `login.php`, `templates/layout.php` |
| S36 | `pages/update.php`: keine Rollenprüfung; `href` aus fremder JSON-Antwort ohne Schemaprüfung. **Teil a (Rollenprüfung) behoben mit SP3a:** nur für Admins (vor dem Abruf der Quelle), Seitenleisten-Link nur für Admins. **Teil b (Antwortprüfung) behoben mit SP3b:** `UpdateCheckService` akzeptiert `version` nur als gültige Zeichenkette (sonst ungültige Antwort), `release_date` nur als kurze Zeichenkette (sonst „unbekannt“), Links nur als absolute HTTPS-URL ohne Zugangsdaten (sonst entfällt der Link); vorher führten Felder vom falschen Typ zu PHP-Fatal-Errors (HTTP 500). | `pages/update.php`, `UpdateCheckService` (mit 5d unverändert übernommen) |
| S37 | `system_health.php` startet das Monitoring per `exec` im Webprozess; `db.php` gibt den DB-Fehlertext per `die()` aus | **Teil a (`exec` in `pages/system_health.php`): behoben mit 5b** (Monitoring im Webprozess über `MonitoringService`). **Teil b behoben mit SP3b:** `inc/db.php` antwortet generisch („Datenbankverbindung fehlgeschlagen.“, Web HTTP 503, CLI Exit-Code 1 statt 0), Details serverseitig nur als feste Meldung mit Fehlercode per `error_log` (kein Exception-Text); ebenso Sprachdatei- und `BASE_URL`-Fehler ohne Pfad bzw. Konfigurationshinweis (HTTP 500). |
| S38 | `getDiagnosticResults()` ohne Berechtigungsbezug; `records.php` filtert erst in PHP | `inc/diagnostics.php`, `pages/records.php` – **behoben** (`records.php` mit Paket 3, übrige Seiten mit 5a; Funktion entfernt) |
| S39 | `pages/dyndns.php` und `pages/servers.php` prüfen die Admin-Rolle erst nach dem Einbinden des Layouts und ohne `access_denied`-Protokoll (die Daten werden erst danach und berechtigt über die Read-Services geladen). **Behoben mit SP3a:** `requireRole(['admin'])` vor dem Layout (HTTP 403, `access_denied`-Log); ebenso die vier DynDNS-Actions (`dyndns_password.php` bisher Toast und Redirect, jetzt 403). | `pages/dyndns.php`, `pages/servers.php` |
| S40 | Die Oberfläche (inkl. DynDNS-API) war auf ns1 auch über **unverschlüsseltes HTTP (Port 80)** erreichbar (Ubuntu-Standard-VirtualHost `000-default.conf`, `DocumentRoot /var/www/html` mit den Symlinks `dnsmanager-ui`/`dnsmanager-agent`; nur der Agent hatte `SSLRequireSSL`). **Teil A – erledigt am 13.11.2026 (S40-E1/A2, Betriebsänderung auf ns1, nicht im Repository):** Preflight ohne Port-80-Nutzer (kein ACME, keine HTTP-Aufrufe in Cron/Code/Konfiguration, keine externen DynDNS-Clients in den Logs, 3 min ohne SYN auf Port 80); `000-default` deaktiviert, `Listen 192.168.53.122:80` in `ports.conf` auskommentiert; Sicherung und Rollback unter `/root/s40-backup/20261113-133414/`; Port 80 von Workstation und lokal geschlossen (Verbindung abgelehnt), HTTPS-Funktionen, DynDNS-API, Agent und Monitoring unverändert. **Teil B – erledigt (PR #67, `main` `ce3b245`):** `session.cookie_secure` verbindlich; auf ns1 geprüft (Cookie `secure; HttpOnly; SameSite=Lax`). **Teil C – offen:** vertrauenswürdiges Zertifikat für festen Hostnamen, danach ggf. HSTS (siehe „HTTPS-Zertifikat“ unten); Installationsvorlagen (`apache/`) sollten HTTPS-only vorsehen. | Apache ns1, `inc/session.php` |
| S41 | **Fail2Ban-Schutz nicht wirksam:** ns1 – Fail2Ban installiert, aber `disabled`/`inactive`, kein `dnsmanager`-Jail; die installierte Filterdatei (Stand 15.10.2026) passt nicht zum heutigen Logformat (ohne `time="…"`) und erkennt **0** Ereignisse, der Filter des Repositorys erkennt sie korrekt (`fail2ban-regex`: 20 Treffer in 20 000 Zeilen, Zeitbasis Syslog-Zeitstempel mit Zone). ns2 – nicht installiert. Risiko bei Aktivierung mit Repository-Werten (3 Treffer in 10 min): Sperre legitimer Admins (am 12.11. mehrere Fehlanmeldungen der Admin-Workstation binnen Minuten), zoneadmin bei `access_denied`, DynDNS-Clients mit vorangestellter Anfrage ohne Zugangsdaten (`login_failure user=empty`). **Offen**; Konfiguration vorbereitet und isoliert getestet (siehe „Fail2Ban – vorbereitete Konfiguration“), produktive Aktivierung nicht freigegeben. | ns1 `/etc/fail2ban`, `fail2ban/` (Repository) |
| S42 | Die Anwendung ruft die Agenten mit **abgeschalteter TLS-Prüfung** auf (`CURL_SSL_VERIFYPEER = false`, `CURL_SSL_VERIFYHOST = 0` auf ns1 und in `ui_config-sample.php`). **Analyse (13.11.2026, lesend):** alle Agent-Aufrufe (Publish All/Zone, DynDNS-Publish, BIND-Reload, Monitoring) laufen über `BindAgentClient` (`https://{api_ip}/dnsmanager-agent/api/v1/…`); Adressen ns1 `127.0.0.1`, ns2 `192.168.53.125`; Agenten mit selbstsignierten *snakeoil*-Zertifikaten (SAN nur `DNS:ns1` bzw. `DNS:ns2`); auf ns1 liegt das eigene snakeoil-Zertifikat im System-Zertifikatsverzeichnis und wird von libcurl standardmäßig (`CAPATH=/etc/ssl/certs`) als vertrauenswürdig behandelt. **Entscheidungen:** S42-E1 interne CA mit IP-SANs (Adressierung und Datenbank unverändert), S42-E2 eigene Agent-CA (5 J., Server 1 J., Erneuerung ≥ 30 Tage vorher, CA-Schlüssel nicht auf ns1/ns2), S42-E3 Phase 1 Code-Option, S42-E4 Abnahmereihenfolge. **Phase 1 – erledigt (PR #68, `main` `b17d946`; ns1 am 13.11.2026 aktualisiert, `ui_config.php` unverändert, `AGENT_TLS_CA_FILE` nicht gesetzt, Monitoring-Cron und beide Agenten ohne Befund):** `BindAgentClient` mit optionalem `AGENT_TLS_CA_FILE` – bei eingeschalteter Prüfung ausschließliches Vertrauen in diese Datei (`CURLOPT_CAINFO`, `CURLOPT_CAPATH = null`), Fail-Closed bei fehlender/unlesbarer/ungültiger CA-Datei oder `VERIFYHOST ≠ 2`, ohne Einstellung unverändert; isoliert auf ns1 nachgewiesen (t70, 34 Fälle plus Mutationsproben). Betriebsanleitung: `docs/AGENT_TLS.md`. **Phase 2 – vorbereitet (PR #69, `main` `83fe41f`), Durchführung offen:** CA-Schlüsselverwahrung, Ausstellung, Umstellung ns1/ns2, Ablaufüberwachung; S42-E5 freigegeben (Agent-Zertifikat nur auf IP-gebundenen Agent-VirtualHosts, Oberfläche unverändert, Agent-CA nicht auf Arbeitsplätzen), E6 freigegeben mit Ergänzung (nachprüfbarer Alarm vor Aktivierung; Alarmweg E6-A/B offen), E7 grundsätzlich (konkrete `nameConstraints`, PHP-cURL-Negativtest), E8 festgelegt (Ausstellung auf dem vorhandenen Windows-PC mit Git-OpenSSL 3.5.9, Schlüssel verschlüsselt auf zwei Offline-Datenträgern, Passphrase im Passwortmanager; Ablauf W0–W7); Testzone `zone.example.test` für E4 fachlich akzeptiert (nur TXT `s42-tls-test`; Publish erst in der freigegebenen Abnahme); E6: Variante A (Ablaufwarnung im bestehenden Systemstatus-Monitoring) entschieden und umgesetzt (PR #70, `main` `407c6ae`; Einzelzonen-Publish-Skript `docs/agent-tls/s42-publish-zone.php` für die Abnahme vorbereitet und isoliert getestet (t72; PR #71, `main` `9ee3789`); `AgentCertificateExpiryCheck`, `AGENT_TLS_EXPIRY_WARN_DAYS`; isolierte Tests t71; ns1 am 13.11.2026 aktualisiert, ohne Konfiguration nicht aktiv). Fehlerklassen, Risiken, Schrittfolge A–G mit Prüfskripten (`docs/agent-tls/`, Exit-Code-basiert) und Trockenläufen in `docs/AGENT_TLS.md`. **Technisch abgeschlossen (13.11.2026):** Agent-CA (RSA 3072, bis 13.11.2031, kritische `nameConstraints`, Fingerprint `2F:5E:A9:48:…:31:0F`) auf dem Windows-PC erstellt, Schlüssel verschlüsselt auf zwei Offline-Datenträgern; Serverzertifikate ns1 `127.0.0.1` und ns2 `192.168.53.125` (je eigener Schlüssel, bis 13.11.2027) über eigene IP-gebundene Apache-Sites `dnsmanager-agent-tls.conf`; Oberfläche unverändert. Seit 13.11.2026 19:46 auf ns1 `CURL_SSL_VERIFYPEER = true`, `CURL_SSL_VERIFYHOST = 2`, `AGENT_TLS_CA_FILE`; Ablaufwarnung aktiv (30 Tage). Abnahme D0–D7, E, F, G0–G6 bestanden (BIND-Reload, Einzelzonen-Publish `zone.example.test` mit TXT-Test und Rückbau, Monitoring 12/12 `ok`). Vorfälle: D2/D3-Nachprüfung von falscher Quelle (Rückweg, wiederholt); D7 Rechte von `ui_config.php` 15 min 0600 (Monitoring ausgefallen, behoben). Offen: Entfernen des CA-Schlüssels vom Ausstellungsrechner (W7). | `BindAgentClient`, `ui_config.php`, `docs/AGENT_TLS.md` |
| I1 | In-Flight-Risiko Publish-Momentaufnahme: Ein bereits laufender Publish verteilt den Stand seiner Momentaufnahme; währenddessen gesetzte Änderungen werden nicht als veröffentlicht markiert (Tri-State) und erst beim nächsten Publish verteilt. **Offen.** | `PublishService` |
| I2 | In-Flight-Risiko Revalidierungsfenster: Widerruf und Rechteentzug wirken ab der nächsten Anfrage; eine bereits revalidierte, laufende Anfrage läuft zu Ende (schreibende Services prüfen die Berechtigung zusätzlich unter Sperre gegen den Datenbankstand). **Offen.** | `inc/web/session_guard.php`, Services |
| F1 | Die Änderungserkennung in der Serveränderung vergleicht DB-Zeichenketten mit Integern (`'1' !== 1`). Daher erscheint „Keine Änderungen“ praktisch nie. Bei der Entkopplung bewusst unverändert übernommen. | `ServerService::updateServer` (vorher `server_update.php`) |
| F2 | Sprachschlüssel `topology_locked`, `publish_all_locked` und `zone_prefix_placeholder` fehlen; die Actions nutzen Fallback-Texte | `web/lang/*.php` |
| F3 | DynDNS-Passwortänderung prüft nicht, ob der Account existiert (Erfolgsmeldung auch bei unbekannter ID); bei der Entkopplung bewusst unverändert übernommen | `DyndnsAccountService::changePassword` (vorher `dyndns_password.php`) |
| F4 | Login mit abweichender Groß-/Kleinschreibung des Benutzernamens gelingt (Kollation `utf8mb4_unicode_ci`); die Session speichert die eingegebene Schreibweise, daher widerruft die nächste Anfrage die Session (`username_changed`). Kein Rechtegewinn; mit 5c unverändert übernommen. | `AuthService::authenticate`, `login_user` |
| F5 | Update-Seite: Der Fehlerrahmen beginnt immer mit „Verbindung zum Update-Server fehlgeschlagen“ (`update_error`), auch bei ungültiger Antwort; bei Verbindungsfehlern erscheint der Text doppelt (`update_error` + `update_connection_failed`). Nur Darstellung; mit 5d unverändert übernommen. | `pages/update.php` |
| F6 | Einschränkung „nur ein lokaler Server“ beim Anlegen nicht nachgewiesen race-sicher (kein UNIQUE-Schutz; unter READ COMMITTED keine Gap-Locks; Details 3.1). **Offen.** | `ServerService::createServer` |
| F7 | Die Sperrnamen (`GET_LOCK`: `dnsmanager_publish_all`, `dnsmanager_monitoring`, Topologie-Lock) gelten serverweit für den MariaDB-Server, nicht je Datenbank. Mehrere Installationen auf demselben Datenbankserver beeinflussen sich gegenseitig (beobachtet in der SP1-Testumgebung: ein Cron-Lauf der Testinstallation wurde übersprungen, während eine isolierte Testinstanz das Monitoring ausführte). **Offener Funktions-/Betriebsbefund**; bei Tests Auswirkungen auf den regulären Cron vermeiden. | `PublishService`, `MonitoringService`, `TopologyLock` |
| F8 | Das Dashboard fasst den Serverstatus (`server_status`) weder bei Fehlern noch bei Warnungen zusammen; sichtbar sind beide nur auf der Systemstatus- und der Serverseite. Bei S42-E6 bewusst unverändert gelassen (Vorgabe: Warnung im bestehenden Systemstatus). **Offen.** | `pages/dashboard.php`, `DiagnosticReadService` |

## 7. Eignung für eine spätere MCP-Anbindung

**Geeignet:**
- Services erhalten `PDO` und Infrastruktur-Adapter ausdrücklich (Konstruktor); kein Web-Bootstrap, keine globalen Zustände außer unveränderlicher Konfiguration.
- Schreibende Services und die Lese-Services für Zonen, Records, Server, Benutzer, DynDNS und Status erhalten einen `Actor` und prüfen die Berechtigung selbst gegen den Datenbankstand (schreibend unter Sperre, lesend in derselben Abfrage). Die Rolle im `Actor` kann nur ablehnen.
- Nicht vorhandene und nicht berechtigte Objekte werden gleich behandelt (`null` bzw. derselbe Code).
- Ergebnisse: schreibende Services liefern `ServiceResult` mit stabilen Codes und Rohdaten ohne Sprachtexte; Lese-Services liefern feste Arrays bzw. `null`; Listen sind paginiert (`listZones`, `listUsers`, `listRecords`).
- `Actor` hat bereits ein Kanalfeld (`channel`, heute `web`).

**Aufgaben eines MCP-Adapters (nicht Teil dieses Meilensteins):**
- **Eigener sicherer Transport und eigene Authentifizierung** (z. B. Token/mTLS); `AuthService` deckt nur die Passwortprüfung und die Prüfung gespeicherter Sitzungsdaten ab.
- **Akteur aus geprüfter Identität bilden** und bei jedem Aufruf den Datenbankstand prüfen lassen (analog `validateSession`, Sitzungsgeneration und Aktivstatus); keine dauerhaft vertrauten Rollen im Adapter.
- **Services ohne `Actor` selbst absichern:** `MonitoringService` (Lauf, Log-Retention), `SystemCheckService`, `UpdateCheckService` (S36) und `PublishService::publishZone()` (intern für DynDNS) sind heute nur über Web-Rollenprüfung bzw. Cron erreichbar. Ein MCP-Adapter muss sie auf aktive Admins beschränken oder gar nicht anbieten.
- **`DyndnsUpdateService`** authentifiziert über DynDNS-Zugangsdaten und ist kein Werkzeug für Administratoren.
- **Meldungstexte und Sicherheitsprotokolle** erzeugt der Adapter selbst aus den Codes; die Logging-Funktionen mit ausdrücklichen Parametern (`logAccessDeniedEvent`, `logLoginFailureEvent`) stehen dafür bereit. Unbekannte Codes nie verwerfen.
- **Nebenläufigkeit:** bestehende Sperren (Publish-/Topologie-Lock, Monitoring-Laufsperre) werden durch die Services gehalten; Zeitlimits und Wiederholungen legt der Adapter fest.

## 8. Änderungsprotokoll dieses Dokuments

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
| 13.11.2026 | 6 | Abschlussprüfung: Ergebnis (Abschnitt 0), 3.8 mit Prüfvorgehen und Zuordnung, K1 Zonenstatus in `ZoneReadService`, B1–B3 entfernt, Korrektur `DyndnsApiReject`, Befundliste vollständig (S28, S39, I1, I2, F6; Teilstatus S34/S37), Abschnitt 7 MCP-Eignung |
| 13.11.2026 | 6 | K2 (Systemstatus: `summary` und `problems` aus `DiagnosticReadService`) und K3 (`ServerReadService::getServerStatusOverview`) ausgelagert, `inc/web/diagnostics_view.php` entfallen, D17 (nur Log); Abschlussvermerk ohne Einschränkung (PR #64, gemergt als `2a077a2`; D17 akzeptiert) |
| 13.11.2026 | – | Meilenstein Core-/UI-Trennung abgeschlossen: ns1 auf `2a077a2`, Betriebsprüfung (Zonen, Server, Systemstatus, Dashboard, Login, Monitoring, Serials ns1/ns2) ohne Befund. Nächster Schritt: Security-Hardening Phase 1 (Bestandsaufnahme der Befunde aus Abschnitt 6) |
| 13.11.2026 | SP1 | Security-Paket 1: S29 (Agent-Token), S30 (DynDNS-Mindestlänge), S31 (Dummy-Hash) behoben (PR #65, gemergt als `bc8b607`; D18–D21 akzeptiert); Paketplan SP1–SP4; `PASSWORD_MIN_LENGTH` = 4 dokumentiert; Befund F7 (serverweite Sperrnamen) |
| 13.11.2026 | SP1 | ns1 auf `bc8b607`, Betriebsprüfung ohne Befund; Passwortpolitik (Wert 4) als unzureichend gekennzeichnet; F7 als offener Betriebsbefund; nächster Schritt: Planung SP2 (S28, S35) |
| 13.11.2026 | SP2 | S28 (absolute Höchstdauer 12 h, `login_time`, SameSite=Lax, frische ID bei Ablauf) und S35 (Logout nur POST mit CSRF, Login-CSRF ohne Ausnahme) behoben (Draft-PR); Betriebsbefund Fail2Ban/Firewall/Erreichbarkeit; neuer Befund S40 (Oberfläche über HTTP auf ns1) |
| 13.11.2026 | SP2 | SP2 gemergt (PR #66, `4f37f08`; D22–D27, einmalige Abmeldung akzeptiert), ns1 aktualisiert, Betriebsprüfung ohne Befund. S40 (Ursache, Endpunkte, Zertifikat) und neuer Befund S41 (Fail2Ban unwirksam, veralteter Filter) lesend untersucht; Abhilfeplanung vorgelegt, keine Systemänderung |
| 13.11.2026 | S40 | Teil A: HTTP auf ns1 abgeschaltet (A2, Preflight, Sicherung/Rollback, Funktionstests); Teil B: Secure-Cookie (Draft-PR); Teil C: Zertifikats-/HSTS-Weg dokumentiert. S41: Fail2Ban-Konfiguration vorbereitet und isoliert getestet (nicht aktiviert). Neuer Befund S42 (Agent-Aufrufe ohne TLS-Prüfung) |
| 13.11.2026 | S40/S41/S42 | PR #67 gemergt (`ce3b245`), ns1 aktualisiert und geprüft; S41: `findtime`-Beobachtung über Quellcode geklärt (Rate-Anpassung in `adjustTime`, Bereinigung nur nach Treffern); S42 lesend analysiert, Planung vorgelegt |
| 13.11.2026 | S41/S42 | S42 Phase 1: Code-Option `AGENT_TLS_CA_FILE` (ausschließliche Agent-CA, Fail-Closed), isolierte Tests t70, Betriebsanleitung `docs/AGENT_TLS.md`; S41: `findtime`-Erklärung mit Quellpfad korrigiert (periodische Bereinigung, Ratenschätzung nur bis zur nächsten Bereinigung) |
| 13.11.2026 | S42 | PR #68 gemergt (`b17d946`), ns1 aktualisiert (Sicherung `/root/p68-update/`), Konfiguration unverändert; reguläre Monitoring-Läufe 15:25/15:30 mit 12/12 Diagnosen `ok`, Agenten ns1/ns2 HTTP 200, keine PHP-/Apache-Fehler |
| 13.11.2026 | S42 | Phase 2 vorbereitet: Betriebsanleitung überarbeitet (Entscheidungsvorschläge E5–E8, Fehlerklassen Konfiguration/TLS, Risiken, Schrittfolge A–G, Rückweg, Erneuerung, Überwachungsskript); Befehle A–D und Überwachung per Trockenlauf mit Wegwerf-CA auf ns1 geprüft |
| 13.11.2026 | S42 | Phase-2-Vorbereitung nach Review: E5–E8 eingetragen, `nameConstraints` auf konkrete Namen eingeschränkt (Subtree-Semantik dokumentiert), Prüfskripte mit Exit-Code-Abbruch (`s42-precheck.sh`, `s42-vhost-check.sh`, `s42-nc-negative.sh`), Alarmweg-Optionen E6-A/B/C mit Nachweisverfahren, Testzonen-Vorschlag; Trockenlauf 2 |
| 13.11.2026 | S42 | PR #69 gemergt (`83fe41f`, nur Dokumentation/Prüfskripte, kein Deployment); E6-A entschieden, Testzone `zone.example.test` akzeptiert |
| 13.11.2026 | S42 | E6-A umgesetzt (Draft-PR): Ablaufwarnung für Agent-Zertifikat und Agent-CA im Serverstatus, Fehler nie verändert, ohne Konfiguration unverändert; Systemstatus zeigt Serverwarnungen als Warnung; isolierte Tests t71; neuer Befund F8 (Dashboard ohne Serverstatus) |
| 13.11.2026 | S42 | PR #70 gemergt (`407c6ae`), ns1 aktualisiert (Sicherung `/root/p70-update/`), Konfiguration unverändert (Prüfung aus, kein `AGENT_TLS_*`); Systemstatus „Alle Server in Ordnung“, Monitoring 16:25–17:00 regulär, 12/12 Diagnosen `ok`, keine PHP-Fehler |
| 13.11.2026 | S42 | Phase-2-Ausführung vorbereitet: einmaliges CLI-Skript für `PublishService::publishZone()` (nur Testzone, nur `www-data` per root-`sudo`, Zielprüfung auf Test-VM-Agenten, Publish-Sperre), Abnahmeschritte G0–G6; isolierter Test t72 und lesender Probelauf gegen die Produktion |
| 13.11.2026 | S42 | PR #71 gemergt (`9ee3789`, nur Dokumentation/Abnahmeskript, kein Deployment; Klarstellung: `SUDO_UID` ist nur Bedienschutz) |
| 13.11.2026 | S42 | E8: Ausstellungsrechner Windows-PC; OpenSSL-Umgebungen geprüft (Git `ucrt64` 3.5.9 geeignet, Trockenlauf mit Wegwerf-Material); Ablauf W0–W7 und N1 in `docs/AGENT_TLS.md` |
| 13.11.2026 | S42 | S42 technisch abgeschlossen: Phase 2 (W0–W6, N1, D0–D7, E, F, G0–G6) auf ns1/ns2 durchgeführt, Agent-TLS-Prüfung produktiv aktiv; Vorfälle dokumentiert; offen W7 |
| 13.11.2026 | SP3a | S39 und S36a: Rollenprüfung vor jeder Ausgabe (`servers.php`, `dyndns.php`, `update.php`, DynDNS-Actions) mit `requireRole()`/`access_denied`-Log; Update-Link nur für Admins; Web-Golden t73 (27/27) |
| 13.11.2026 | SP3a | PR #73 gemergt (`a6339d9`; enthält den S42-Abschluss-Doku-Commit); kein Deployment auf ns1 |
| 13.11.2026 | SP3b | S36b (Prüfung der Update-Antwort), S37b (generische Fehlerausgabe, HTTP 503/500, CLI-Exit 1), Sicherheitsheader, `PASSWORD_MIN_LENGTH` 12 in der Beispielkonfiguration; Web-Golden t74 (26/26) |
| 13.11.2026 | SP3b | PR #74 gemergt (`2f7a9eb`); kein Deployment auf ns1. SP4 freigegeben (E1–E4) |
