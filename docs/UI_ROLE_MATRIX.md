# DNS Manager – Rollenmatrix der Weboberfläche (Ist-Zustand U0)

Stand: 14.11.2026. Erzeugt mit `tests/ui/matrix.php` aus der versionierten Golden-Basis
`tests/ui/baseline/golden-1e37515.json` (Anwendung `main` `1e37515`, Harness `21ca9e3`, ns1, PHP 8.5.4, MariaDB 11.8.6).
Rollen: **anonym** (nicht angemeldet), **admin**, **zoneadmin** (Zone `u0.test` zugewiesen; `other.u0.test` und die
Reverse-Zone nicht). Fixture und Normalisierung: [`tests/ui/README.md`](../tests/ui/README.md).

Diese Matrix beschreibt das **beobachtete Verhalten**. Maßgeblich für die Autorisierung bleiben die bestehenden
Mechanismen: Session-Revalidierung, `requireRole()`, `ZoneAccess` und Rollenprüfung der Core-Services. Die
UI-Modernisierung ändert keine Zeile dieser Matrix ohne freigegebene, mit Fall-ID dokumentierte Ausnahme.

## Lesehilfe

- `302 → Login`: Weiterleitung zur Anmeldung (nicht angemeldet bzw. Session widerrufen).
- `403`: Zugriff verweigert. Bei Seiten und Actions mit `requireRole()` ist das eine **reine Textantwort** ohne Layout
  („Zugriff verweigert: Unzureichende Berechtigungen.“) mit `access_denied`-Log. Bei zonenbezogenen Actions lautet
  der Text „Zugriff verweigert.“; ohne gültiges CSRF-Token „Ungültiger CSRF-Token.“.
- `405`: `logout.php` per GET (nur POST erlaubt).
- ⚠: PHP-Meldung (siehe Befunde U0-B2, U0-B3).

**Zusätzlich zur Tabelle (Seitenvertrag in der Basis):**
- zoneadmin erhält auf `zones.php?add_new=1` und `users.php?add_new=1` **keine** Anlageformulare (HTTP 200 mit Liste).
- Auf `zones.php?edit_id=2` (nicht zugewiesene Zone) erhält zoneadmin kein Bearbeitungsformular.
- Auf `users.php` sieht zoneadmin nur den eigenen Datensatz mit Passwortänderung.
- Abweichend davon wird auf `users.php?edit_id=<eigene ID>` das Formular `user_update` ausgeliefert, obwohl die Action admin-only ist (Befund U0-B4).

### Seiten (GET)

| Seite | Anfrage | anonym | admin | zoneadmin |
|---|---|---|---|---|
| dashboard | `pages/dashboard.php` | 302 → Login | 200 | 200 |
| dyndns | `pages/dyndns.php` | 302 → Login | 200 | 403 |
| dyndns_add | `pages/dyndns.php?add_new=1` | 302 → Login | 200 | 403 |
| dyndns_edit1 | `pages/dyndns.php?edit_id=1` | 302 → Login | 200 | 403 |
| login | `login.php` | 200 | 200 | 200 |
| logout_get | `logout.php` | 405 | 405 | 405 |
| records_add | `pages/records.php?zone_id=1&add_new=1` | 302 → Login | 200 ⚠ | 200 ⚠ |
| records_edit3 | `pages/records.php?zone_id=1&edit_id=3` | 302 → Login | 200 ⚠ | 200 ⚠ |
| records_z1 | `pages/records.php?zone_id=1` | 302 → Login | 200 ⚠ | 200 ⚠ |
| records_z2 | `pages/records.php?zone_id=2` | 302 → Login | 200 ⚠ | 403 |
| records_z3 | `pages/records.php?zone_id=3` | 302 → Login | 200 ⚠ | 403 |
| servers | `pages/servers.php` | 302 → Login | 200 | 403 |
| servers_add | `pages/servers.php?add_new=1` | 302 → Login | 200 | 403 |
| servers_edit2 | `pages/servers.php?edit_id=2` | 302 → Login | 200 | 403 |
| system_health | `pages/system_health.php` | 302 → Login | 200 | 403 |
| update | `pages/update.php` | 302 → Login | 200 | 403 |
| users | `pages/users.php` | 302 → Login | 200 | 200 |
| users_add | `pages/users.php?add_new=1` | 302 → Login | 200 | 200 |
| users_edit2 | `pages/users.php?edit_id=2` | 302 → Login | 200 | 200 |
| zones | `pages/zones.php` | 302 → Login | 200 | 200 |
| zones_add | `pages/zones.php?add_new=1` | 302 → Login | 200 ⚠ | 200 |
| zones_edit1 | `pages/zones.php?edit_id=1` | 302 → Login | 200 | 200 |
| zones_edit2 | `pages/zones.php?edit_id=2` | 302 → Login | 200 | 200 |

### Actions, Login/Logout und DynDNS-API

| Fall | Rolle | Anfrage | Ergebnis | Meldung |
|---|---|---|---|---|
| api/dyndns_update_badauth | dyndns | `GET api/v1/dyndns/update.php?myip=192.0.2.98 (Basic Auth u0dyn)` | 200 | badauth |
| api/dyndns_update_ok | dyndns | `GET api/v1/dyndns/update.php?myip=192.0.2.98 (Basic Auth u0dyn)` | 200 | good 192.0.2.98 |
| auth/login_case_variant | anon | `POST login.php als „U0ADMIN“` | 302 → dashboard.php |  |
| auth/login_inactive | anon | `POST login.php als „u0off“` | 200 | ❌ Login fehlgeschlagen: Benutzername oder Passwort falsch. |
| auth/login_ok | anon | `POST login.php als „u0admin“` | 302 → dashboard.php |  |
| auth/login_unknown | anon | `POST login.php als „u0unbekannt“` | 200 | ❌ Login fehlgeschlagen: Benutzername oder Passwort falsch. |
| auth/login_without_csrf | anon | `POST login.php als „u0admin“` | 403 |  |
| auth/login_wrong_password | anon | `POST login.php als „u0admin“` | 200 | ❌ Login fehlgeschlagen: Benutzername oder Passwort falsch. |
| auth/logout_post | admin | `POST logout.php (direkt)` | 302 → Login |  |
| dyndns/add_admin | admin | `POST actions/dyndns_add.php (Formular von pages/dyndns.php?add_new=1)` | 302 → dyndns.php | success: DynDNS-Account erfolgreich erstellt. |
| dyndns/add_zoneadmin_denied | zoneadmin | `POST actions/dyndns_add.php (direkt)` | 403 | Zugriff verweigert: Unzureichende Berechtigungen. |
| dyndns/delete_admin | admin | `POST actions/dyndns_delete.php (direkt)` | 302 → dyndns.php | success: DynDNS-Account und zugehörige A/AAAA-Records gelöscht. |
| dyndns/password_admin | admin | `POST actions/dyndns_password.php (direkt)` | 302 → dyndns.php | success: Passwort erfolgreich geändert. |
| dyndns/password_unknown_id | admin | `POST actions/dyndns_password.php (direkt)` | 302 → dyndns.php | error: DynDNS-Account nicht gefunden. |
| dyndns/update_admin | admin | `POST actions/dyndns_update.php (Formular von pages/dyndns.php?edit_id=1)` | 302 → dyndns.php | success: DynDNS-Account aktualisiert. |
| publish/all_admin | admin | `POST actions/publish_all.php (Formular von pages/zones.php)` | 302 → zones.php | success: Alle Zonen erfolgreich veröffentlicht. |
| publish/all_zoneadmin | zoneadmin | `POST actions/publish_all.php (Formular von pages/zones.php)` | 302 → zones.php | success: Alle Zonen erfolgreich veröffentlicht. |
| record/add_admin_a | admin | `POST actions/record_add.php (Formular von pages/records.php?zone_id=1&add_new=1)` | 302 → records.php | success: Record A neu erfolgreich in u0.test hinzugefügt. |
| record/add_admin_invalid | admin | `POST actions/record_add.php (Formular von pages/records.php?zone_id=1&add_new=1)` | 302 → records.php | error: Ungültige IPv4-Adresse. |
| record/add_admin_txt | admin | `POST actions/record_add.php (Formular von pages/records.php?zone_id=1&add_new=1)` | 302 → records.php | success: Record TXT info erfolgreich in u0.test hinzugefügt. |
| record/add_admin_txt_ohne_js | admin | `POST actions/record_add.php (Formular von pages/records.php?zone_id=1&add_new=1)` | 302 → records.php | error: DKIM-Eintrag muss mit v=DKIM1 beginnen. / error: DKIM-Eintrag muss k=rsa oder k=ed25519 enthalten. / error: DKIM-Eintrag enthält kei… |
| record/add_without_csrf | admin | `POST actions/record_add.php (direkt)` | 403 | Ungültiger CSRF-Token. |
| record/add_zoneadmin_foreign | zoneadmin | `POST actions/record_add.php (direkt)` | 403 | Zugriff verweigert. |
| record/add_zoneadmin_own | zoneadmin | `POST actions/record_add.php (Formular von pages/records.php?zone_id=1&add_new=1)` | 302 → records.php | success: Record A zone erfolgreich in u0.test hinzugefügt. |
| record/delete_admin | admin | `POST actions/record_delete.php (direkt)` | 302 → records.php | success: Record(s) erfolgreich gelöscht. |
| record/delete_bulk_admin | admin | `POST actions/record_delete.php (direkt)` | 302 → records.php | success: Record(s) erfolgreich gelöscht. |
| record/delete_zoneadmin_foreign | zoneadmin | `POST actions/record_delete.php (direkt)` | 302 → records.php | error: Beim Löschen der Records ist ein Fehler aufgetreten. |
| record/update_admin | admin | `POST actions/record_update.php (Formular von pages/records.php?zone_id=1&edit_id=3)` | 302 → records.php | success: Record A www erfolgreich in u0.test aktualisiert. |
| server/add_admin | admin | `POST actions/server_add.php (Formular von pages/servers.php?add_new=1)` | 302 → servers.php | success: Server ns3.u0.test erfolgreich hinzugefügt. |
| server/add_zoneadmin_denied | zoneadmin | `POST actions/server_add.php (direkt)` | 403 | Zugriff verweigert: Unzureichende Berechtigungen. |
| server/bind_reload_admin | admin | `POST actions/bind_reload.php (direkt)` | 302 → servers.php | success: BIND Reload auf ns1.u0.test erfolgreich: ui-golden ok |
| server/delete_admin | admin | `POST actions/server_delete.php (direkt)` | 302 → servers.php | error: Der Server ist noch mindestens einer Zone zugewiesen und kann daher nicht gelöscht werden. |
| server/update_admin_change | admin | `POST actions/server_update.php (Formular von pages/servers.php?edit_id=2)` | 302 → servers.php | success: Server ns2.u0.test erfolgreich aktualisiert. |
| server/update_admin_unchanged | admin | `POST actions/server_update.php (Formular von pages/servers.php?edit_id=2)` | 302 → servers.php | success: Keine Änderungen vorgenommen. |
| user/add_admin | admin | `POST actions/user_add.php (Formular von pages/users.php?add_new=1)` | 302 → users.php | success: Benutzer u0neu erfolgreich angelegt. |
| user/add_zoneadmin_denied | zoneadmin | `POST actions/user_add.php (direkt)` | 403 | Zugriff verweigert: Unzureichende Berechtigungen. |
| user/delete_admin | admin | `POST actions/user_delete.php (direkt)` | 302 → users.php | success: Benutzer erfolgreich gelöscht. |
| user/password_admin_other | admin | `POST actions/user_password.php (direkt)` | 302 → users.php | success: Das Passwort wurde erfolgreich aktualisiert. |
| user/password_zoneadmin_other | zoneadmin | `POST actions/user_password.php (direkt)` | 302 → users.php | error: Du darfst nur dein eigenes Passwort ändern. |
| user/password_zoneadmin_own | zoneadmin | `POST actions/user_password.php (direkt)` | 302 → Login |  |
| user/set_inactive_admin | admin | `POST actions/user_set_active.php (direkt)` | 302 → users.php | success: Konto deaktiviert. Bestehende Sitzungen wurden beendet. |
| user/update_admin | admin | `POST actions/user_update.php (Formular von pages/users.php?edit_id=2)` | 302 → users.php | success: Benutzer u0zone erfolgreich aktualisiert. |
| zone/add_admin | admin | `POST actions/zone_add.php (Formular von pages/zones.php?add_new=1)` | 302 → zones.php | success: Zone neu.u0.test erfolgreich angelegt. |
| zone/add_zoneadmin_denied | zoneadmin | `POST actions/zone_add.php (direkt)` | 403 | Zugriff verweigert: Unzureichende Berechtigungen. |
| zone/delete_admin | admin | `POST actions/zone_delete.php (direkt)` | 302 → zones.php | success: Zone other.u0.test wurde gelöscht. |
| zone/delete_zoneadmin_denied | zoneadmin | `POST actions/zone_delete.php (direkt)` | 403 | Zugriff verweigert: Unzureichende Berechtigungen. |
| zone/update_admin | admin | `POST actions/zone_update.php (Formular von pages/zones.php?edit_id=1)` | 302 → zones.php | success: Zone u0.test erfolgreich aktualisiert. |
| zone/update_zoneadmin_foreign | zoneadmin | `POST actions/zone_update.php (direkt)` | 403 | Zugriff verweigert. |
| zone/update_zoneadmin_own | zoneadmin | `POST actions/zone_update.php (Formular von pages/zones.php?edit_id=1)` | 302 → zones.php | success: Zone u0.test erfolgreich aktualisiert. |

⚠ = PHP-Meldung im Log oder in der Antwort (siehe Ergebnisdatei, Feld `problems`).

## Befunde mit Rollenbezug

| ID | Befund | Wirkung | Paket |
|---|---|---|---|
| U0-B4 | zoneadmin bekommt auf `users.php?edit_id=<eigene ID>` das Formular `user_update` angezeigt; die Action ist admin-only und antwortet mit 403 | nur Darstellung, Autorisierung greift | U5 |
| U0-B6 | 403-Antworten von `requireRole()` sind reine Textseiten ohne Layout und Navigation | nur Darstellung; Status und Entscheidung bleiben | U1/U3 |
| U0-B7 | zoneadmin löscht Record einer fremden Zone: 302 mit allgemeiner Fehlermeldung statt 403 (Zugriff korrekt verweigert, `access_denied`-Log, keine Änderung) | bestehender Web-Vertrag, unverändert beibehalten | – |
