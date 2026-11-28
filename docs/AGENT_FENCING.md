# Agent-Fencing: Betrieb (Meilenstein AS)

Stand: 20.11.2026 · AS1 (Agent), AS2a (Zustandsprüfung, Abschnitt 8), AS2b (Nachverifikation, Abschnitt 8a). Architektur und
Entscheidungen: [`ARCHITECTURE_HISTORY.md`](ARCHITECTURE_HISTORY.md), Abschnitte 11, 5e, 5f und 5g. Der Core sendet das Fencing-Token erst ab AS4; bis dahin sind auf installierten Agenten nur die
Modi `off` und `observe` zulässig.

## 1. Dateien und Konfiguration

| Ort | Inhalt |
|---|---|
| `api_config.php` `fencing_mode` | **Pflichtangabe** (E118): `off`, `observe` oder `enforce`. Fehlt der Schlüssel oder ist der Wert unbekannt, lehnen `zone_sync`, `conf_sync`, `zone_cleanup` und `system/status` jeden Aufruf mit `fence_misconfigured` (503) ab – kein stiller Rückfall auf `off` |
| `api_config.php` `fence_domain` | UUID der Installation (Kleinbuchstaben); Pflicht für `observe`/`enforce` |
| `<zone_data_dir>/.dnsmanager-fence` | Höchststand H: `dnsmanager-fence v1 domain=<uuid> high=<n>` |
| `<zone_data_dir>/.dnsmanager-fence.fault` | Fehlermarke (Write-ahead); solange sie vorhanden oder ihre Existenz nicht prüfbar ist, lehnen alle drei schreibenden Endpunkte (`zone_sync`, `conf_sync`, `zone_cleanup`) in jedem Modus (`off`, `observe`, `enforce`) jeden Aufruf ab (`fence_fault`, E97/E99) |
| `scripts/agent_fence-cli.php` | lokale Verwaltung von H (nicht per Apache erreichbar) |

**Voraussetzung:** PHP-Erweiterung `posix` für die Apache-PHP-Instanz des Agenten und für die CLI (Ubuntu:
`php8.x-common`; Prüfung `php -m | grep -x posix`, für Apache `ls /etc/php/*/apache2/conf.d/ | grep posix`). Ohne sie
lehnt der Agent in jedem Modus alle schreibenden Aufrufe mit `agent_requirement_missing` (503) ab, `system/status` meldet
`fencing.state = requirement_missing`, und die CLI verweigert (E100). Es gibt keinen Rückfall.

`api_config.php` liegt auf ns1/ns2 unter `/etc/dnsmanager/` mit `0640 root:www-data`; bei Änderungen Rechte erhalten
und Lesbarkeit für `www-data` prüfen. Die Dateien im Zonenverzeichnis gehören `www-data`.

## 2. CLI

Immer als Eigentümer des Zonenverzeichnisses (`www-data`); das Werkzeug verweigert andere Benutzer und arbeitet unter
demselben `flock` wie die Endpunkte.

```bash
sudo -u www-data php /usr/share/dnsmanager/scripts/agent_fence-cli.php status
sudo -u www-data php /usr/share/dnsmanager/scripts/agent_fence-cli.php init --domain=<uuid> --high=<n>
sudo -u www-data php /usr/share/dnsmanager/scripts/agent_fence-cli.php raise --high=<n>
sudo -u www-data php /usr/share/dnsmanager/scripts/agent_fence-cli.php clear-fault --expect-high=<n>|missing
```

Exit-Codes: 0 Erfolg, 1 Aufruffehler, 2 abgelehnt (Vorbedingung), 3 Persistenzfehler, 4 Lock nicht erhalten. H wird nie
gesenkt; `init` nur ohne vorhandenen H und ohne Marke.

## 3. Einführung je Agent

1. Domäne festlegen: eine UUID je Installation (nicht je Agent), z. B. `cat /proc/sys/kernel/random/uuid`; ab AS4 liefert
   der Core sie aus der Datenbank. Alle Agenten einer Installation erhalten dieselbe Domäne.
2. Sicherung von `api_config.php` (Rechte notieren), danach `fence_domain` und `fencing_mode = 'observe'` eintragen.
3. H initialisieren mit einem Wert ≥ der höchsten vergebenen Lauf-ID der Installation
   (`SELECT AUTO_INCREMENT - 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'dnsmanager' AND TABLE_NAME = 'publish_runs'`).
4. `system/status` bzw. `status` der CLI prüfen (`state = ok`).
5. `enforce` erst nach AS4 und nur stufenweise (zuerst ns2).

Rückweg: `fencing_mode` ausdrücklich auf `'off'` setzen (nicht entfernen – ein fehlender Schlüssel sperrt den Agenten,
E118). Die H-Datei bleibt liegen. In `off` lesen und schreiben die drei
schreibenden Endpunkte (`zone_sync`, `conf_sync`, `zone_cleanup`) H nicht; `system/status` liest H auch in `off`, aber
nur zur diagnostischen Anzeige, wenn eine Fehlermarke gemeldet wird (ARCHITECTURE_HISTORY §5e, E97). Unabhängig vom
Modus bleibt die Fehlermarke wirksam: Eine vorhandene oder nicht prüfbare Marke sperrt auch in `off` alle drei
schreibenden Endpunkte (E97, E99) und muss nach Abschnitt 4 aufgehoben werden; ebenso sperrt eine fehlende
PHP-Erweiterung `posix` in jedem Modus (E100, Abschnitt 1).

## 4. Fehlermarke aufheben

Eine Marke entsteht vor jeder Erhöhung von H und bleibt nur nach einem Abbruch (Absturz, Persistenz- oder
Dateisystemfehler) liegen. Sie sperrt `zone_sync`, `conf_sync` und `zone_cleanup` in **jedem** Modus (auch `off`) mit
`fence_fault`; `system/status` zeigt sie als `fencing.state = fault`. Ist ihre Existenz nicht prüfbar (E/A-Fehler beim
Prüfen), gilt sie ebenfalls als vorhanden (E99).

1. Ursache klären (Dateisystem, `dmesg`, freier Platz, Schreibschutz). Solange das Dateisystem fehlerhaft ist, nicht
   aufheben.
2. `status` der CLI: `high` und `high_state` lesen. H ist entweder der alte oder der neue Wert, nie ein Zwischenzustand.
3. Optional H anheben, wenn bekannt ist, dass ein höheres Token bereits angenommen wurde (nach Aufheben mit `raise`).
4. `clear-fault --expect-high=<gelesener Wert>` (bzw. `missing`, wenn keine H-Datei existiert; danach `init`).

Ist H ungültig (`high_state = invalid`), verweigert `clear-fault` das Aufheben; dann H-Datei sichern, prüfen und manuell
korrigieren – nie auf einen Wert unter einem bereits angenommenen Token.

## 5. Schutzgrenze der Fencing-Domäne (E74)

Der Agent nimmt nur Anfragen und Höchststände seiner lokal konfigurierten Domäne an. Erkannt werden damit
Test-Datenbanken und andere Installationen mit eigener Domäne sowie falsch zugeordnete Agenten. **Nicht** erkannt wird ein
vollständiger Installationsklon, der Datenbank, Web-Konfiguration, Agent-Konfiguration und H übernimmt und dieselben
Agenten anspricht. Dagegen helfen nur organisatorische Maßnahmen: neue Domäne und neue Agent-Token für jede Kopie,
`api_allowed_ips` auf den Agenten (nur die Quelladresse der eigenen Oberfläche) und keine Weitergabe der
Agent-Konfiguration.

## 6. Verwaiste Include-Zeile (`listing_incomplete`)

**Lage.** `conf_sync` und `zone_cleanup` brechen mit `listing_incomplete` ohne Änderung und ohne Reload ab, wenn ein
bisheriges `include` der `zones.conf` in der neuen Verzeichnisauflistung fehlt (E98). Ursache ist entweder ein
Lesefehler des Dateisystems oder eine `include`-Zeile auf eine nicht mehr vorhandene `<zone>.conf` („verwaiste Zeile“).
Solange eine verwaiste Zeile besteht, ist die BIND-Konfiguration laut `named-checkconf` ungültig; deshalb lehnt der
Agent auch `zone_sync` ab (bestehender Preflight) und lädt BIND nicht neu. **BIND bedient währenddessen unverändert den
zuletzt geladenen Stand – einschließlich der betroffenen Zone.** Eine Zone geht nur verloren, wenn BIND in diesem Zustand
von Hand neu geladen oder neu gestartet wird, oder wenn die Zeile einer noch benötigten Zone entfernt und danach neu
geladen wird. Deshalb gilt:

- **Kein** `rndc reload`, kein Neustart von BIND und kein Bearbeiten weiterer Dateien, bis Schritt 5 erfolgreich ist.
- Alle Änderungen im Zonenverzeichnis nur unter der Agent-Sperre (`flock`) und als `www-data`.

**1. Ursache klären.** `dmesg` bzw. `journalctl -k` auf E/A-Fehler prüfen. Liegen Lesefehler vor: zuerst das
Dateisystem in Ordnung bringen und danach erneut veröffentlichen – **keine** Datei bearbeiten. Nur wenn keine Lesefehler
vorliegen, weiter mit 2.

**2. Verwaiste Zeilen ermitteln** (auf dem Agenten):

```bash
sudo -u www-data sh -c 'sed -n "s/^include \"\(.*\)\";\$/\1/p" /etc/bind/zones/zones.conf | while read -r f; do [ -e "$f" ] || echo "fehlt: $f"; done'
```

Jede Ausgabezeile nennt eine fehlende `<zone>.conf`; der Zonenname ist der Dateiname ohne `.conf`.

**3. Für jede fehlende Datei entscheiden, ob die Zone auf diesem Server noch benötigt wird.** Auf ns1:

```bash
mariadb -N dnsmanager -e "SELECT z.name FROM zones z JOIN zone_servers zs ON zs.zone_id = z.id JOIN servers s ON s.id = zs.server_id WHERE z.name = '<zone>' AND s.api_ip = '<api_ip des Agenten>' AND s.active = 1"
```

Eine Ausgabe bedeutet: Die Zone ist diesem Server aktiv zugewiesen und **wird benötigt** (weiter mit 4a). Keine Ausgabe
bedeutet: nicht mehr benötigt (weiter mit 4b). Zur Kontrolle auf dem Agenten den ausgelieferten Stand notieren:
`dig +norec @127.0.0.1 <zone> SOA` (wird für Schritt 5 gebraucht).

**4a. Zone wird benötigt – Zeile nicht entfernen, Datei wiederherstellen lassen.** Die Zone über den Core erneut
veröffentlichen (eine reguläre Änderung an der Zone mit anschließendem Publish bzw. Publish All, solange die Zone
ausstehend ist). `conf_sync` dieser Zone schreibt die fehlende `<zone>.conf` neu; damit ist die Auflistung vollständig,
die Konfiguration gültig, und der Reload lädt den aktuellen Stand. Im selben Lauf kann `zone_sync` dieser Zone noch am
Preflight scheitern (die Datei fehlte zu diesem Zeitpunkt noch); die Zone bleibt dann ausstehend und wird mit dem
nächsten Lauf vollständig verteilt. Andere Zonen dieses Agenten bleiben bis dahin ebenfalls ausstehend.

Nur wenn eine Veröffentlichung der Zone nicht möglich ist: die Datei als Überbrückung aus der Vorlage des Generators
anlegen (Primärzone; Voraussetzung: `/etc/bind/zones/db.<zone>` existiert). Die nächste reguläre Veröffentlichung
ersetzt sie durch den Inhalt des Generators.

```bash
sudo -u www-data flock /etc/bind/zones/.dnsmanager-agent.lock sh -c 'Z=<zone>; test -f "/etc/bind/zones/db.$Z" && test ! -e "/etc/bind/zones/conf/$Z.conf" && printf "zone \"%s\" {\n    type master;\n    file \"/etc/bind/zones/db.%s\";\n    \n};" "$Z" "$Z" > "/etc/bind/zones/conf/$Z.conf" && sync'
```

**4b. Zone wird nicht mehr benötigt – Zeile entfernen.** Sicherung und Entfernen unter der Agent-Sperre; die Sicherung
bleibt im Zonenverzeichnis (sie endet nicht auf `.conf` und wird weder eingebunden noch vom Cleanup berührt):

```bash
sudo -u www-data flock /etc/bind/zones/.dnsmanager-agent.lock sh -c 'cd /etc/bind/zones && P="/etc/bind/zones/conf/<zone>.conf" && test ! -e "$P" && test "$(grep -cxF "include \"$P\";" zones.conf)" = 1 && cp -p zones.conf "zones.conf.vor-bereinigung-$(date +%Y%m%d%H%M%S)" && grep -vxF "include \"$P\";" zones.conf > zones.conf.neu && mv zones.conf.neu zones.conf && sync'
```

Die Zone wird beim nächsten Reload aus BIND entladen – das ist gewollt. Eine etwa verbliebene `db.<zone>` kann danach
von Hand entfernt werden (der Cleanup erkennt sie ohne `.conf` nicht mehr).

**5. Prüfen.**
1. `sudo -u www-data /usr/bin/named-checkconf /etc/bind/named.conf; echo $?` liefert 0, und Schritt 2 gibt nichts aus.
2. Erneut veröffentlichen; die Verteilung an diesen Agenten gelingt ohne `listing_incomplete`.
3. `dig +norec @127.0.0.1 <zone> SOA` aller benötigten Zonen: weiterhin ausgeliefert, Serial gleich DB-Serial.
4. Die Sicherungen `zones.conf.vor-bereinigung-*` nach erfolgreicher Prüfung entfernen.

Nachgewiesen auf ns2 (Abschnitt 5e von `ARCHITECTURE_HISTORY.md`): Fall 4b mit einer verwaisten Zeile einer nicht
existierenden Zone, Fall 4a mit fehlender `.conf` der benötigten Zone `zone.example.test` (Wiederherstellung durch
Veröffentlichung und über die Vorlage); die Zone wurde in keinem Schritt entladen.

## 7. Datenbank-Restore

Ein zurückgespieltes Backup kann die Lauf-ID-Folge unter H senken; die Agenten lehnen dann mit `stale_fence` ab
(fail-closed). Seit AS4b prüft der Core vor jedem Lauf die von Agenten in `observe`/`enforce` gemeldeten Höchststände:
Ist die nächste Lauf-ID ≤ H eines Agenten, startet kein Lauf (Ergebnis `exception`, „Abgleich nach Datenbank-Restore
erforderlich“). Abgleich ausschließlich manuell über die Admin-CLI der Weboberfläche (als `www-data`; `raise-runs`
benötigt das Recht `ALTER` auf `publish_runs`):

```bash
sudo -u www-data php /usr/share/dnsmanager/web/scripts/fence_admin-cli.php status
sudo -u www-data php /usr/share/dnsmanager/web/scripts/fence_admin-cli.php raise-runs --above=<max(H)>
```

`raise-runs` hebt die nächste Lauf-ID nur an (nie senken) und läuft unter dem Publish-Lock. Fehlt dem
Anwendungsbenutzer das Recht `ALTER`, endet der Befehl mit Exit 3 ohne Änderung; dann als Datenbank-Administrator
`ALTER TABLE publish_runs AUTO_INCREMENT = <max(H) + 1>` unter dem Publish-Lock ausführen und mit `status` prüfen. Agenten in `off`, ältere
Agenten und nicht erreichbare Agenten melden keinen Höchststand und blockieren nicht; `status` listet sie. Agenten senken
H nie. Seit AS5 gehört zum Restore zusätzlich der Serial-Abgleich und die Publish-Sperre (Abschnitt 10).

## 8. Zustandsprüfung vor der Übernahme (AS2a)

Architektur: [`ARCHITECTURE_HISTORY.md`](ARCHITECTURE_HISTORY.md) Abschnitt 5f (E101–E117). Nur in den Modi
`observe` und `enforce`; im Modus `off` ist der Agent unverändert.

**Voraussetzungen**

| Voraussetzung | Prüfung |
|---|---|
| `dig` (`bind9-dnsutils`), `setsid` (`util-linux`), `sudo` unter `/usr/bin` | `command -v dig setsid sudo` |
| 127.0.0.1 darf alle verwalteten Zonen abfragen (`allow-query`, E17) | `dig +norec @127.0.0.1 <zone> SOA` liefert `aa` |
| Kontrollzone (`l3_control_zone`, Standard `localhost`): autoritative Zone ohne eigene `allow-query`, nicht vom DNS-Manager verwaltet (z. B. `include "/etc/bind/named.conf.default-zones";`) | `system/status` → `verification.control_zone = ok` |
| unterstützter Konfigurationskontext: keine Views, kein `allow-new-zones`, keine Catalog-Zonen, keine DLZ, keine NZD/NZF-Dateien im `directory` bzw. `new-zones-directory` | `system/status` → `verification.config = ok` |

Ist eine Voraussetzung nicht erfüllt, lehnt `zone_sync` jede Übernahme mit `served_state_unknown` (503) ab, auch für die
erste Zone eines Servers; `system/status` nennt den Grund (`verification.config_reason`, `verification.control_zone`).
Das ist beabsichtigt (fail-closed), keine Störung des Agenten.

**Ablehnungen** (Bestand unverändert, kein Reload, `effect = none`; Rohwerte unter `serial_check`)

| Code | HTTP | Bedeutung | Behebung |
|---|---|---|---|
| `stale_serial` | 409 | neue Serial nach RFC 1982 nicht größer als Datei bzw. ausgelieferte Serial | höhere Serial wählen (Core, E78/AS5) |
| `serial_conflict` | 409 | gleiche Serial mit anderem Inhalt (bzw. ohne vorhandene Datei) | höhere Serial |
| `serial_undefined` | 409 | Abstand genau 2³¹ | höhere Serial |
| `zone_state_invalid` | 409 | vorhandene Zonendatei lesbar, aber ungültig | Reparatur über die Admin-Operation des Core (AS4) mit `repair` |
| `repair_mismatch` | 409 | Reparaturkennzeichen passt nicht zum vorgefundenen Zustand bzw. ist nicht zulässig | Zustand neu ermitteln |
| `zone_state_unreadable` | 503 | vorhandene Datei nicht lesbar, nicht prüfbar, Symlink oder keine reguläre Datei | lokal prüfen |
| `served_state_unknown` | 503 | Konfiguration oder ausgelieferter Zustand nicht eindeutig (Grund in `serial_check`) | siehe unten |
| `check_timeout`, `lock_timeout` | 503 | Frist abgelaufen vor jeder Änderung | Ursache (Last, hängender Prozess) prüfen |
| `agent_requirement_missing` / `fence_misconfigured` (`system/status`) | 503 | `observe`/`enforce` ohne PHP-Erweiterung `posix` bzw. unbekannter `fencing_mode`; Statusprüfung nicht ausgeführt (kein externer Prozess) | `posix` installieren bzw. `fencing_mode` korrigieren |
| `status_timeout` (nur `system/status`) | 503 | `pgrep`, `rndc status`, `dig` oder der Konfigurationsnachweis nicht innerhalb der Frist (`bind.timeouts`) | Steuerkanal (`rndc`), DNS auf 127.0.0.1 und `named-checkconf` prüfen |

In `observe` werden nur `stale_serial`, `serial_conflict` und `serial_undefined` nicht abgelehnt (`would_reject`).

**`served_state_unknown` beheben** (lokal, als root; der Agent darf `rndc zonestatus` nicht ausführen):

1. `serial_check.config_reason` bzw. `served_reason` lesen.
2. SERVFAIL (`configured_not_served`): `rndc zonestatus <zone>`; „zone not loaded“ ⇒ Zonendatei herstellen bzw.
   Berechtigungen prüfen (`journalctl -u named`), oder nicht benötigte Konfiguration entfernen; danach `rndc reload`.
3. NZD/NZF-Dateien (`nzd_present`): nur, wenn `allow-new-zones` nicht gesetzt ist und die dynamischen Zonen nicht
   benötigt werden: named stoppen, Dateien sichern und entfernen, named starten.
4. Zonenzahl (`zone_count_mismatch`): Konfiguration wurde ohne Reload geändert ⇒ `named-checkconf` und `rndc reload`.
5. Pfad/Typ/Optionen (`zone_file_mismatch`, `zone_type_unsupported`, `zone_options_unsupported`): Die Zone ist nicht in der
   vom DNS-Manager erzeugten Form konfiguriert (`type master; file "<zone_data_dir>/db.<zone>";`) ⇒ fremde Konfiguration
   bereinigen.

**Reload und Wirkung** – Antworten in `observe`/`enforce` enthalten `effect` (`none`, `unchanged`, `applied`, `partial`,
`unknown`). Nach einem Reload-Versuch gibt es keinen Rollback: `reload_failed`/`reload_timeout` (500) bedeuten „Datei
bzw. Konfiguration übernommen, Ladezustand unbekannt“ (`bind_state = unknown`); der nächste erfolgreiche Reload lädt den
neueren Stand. `rollback` erscheint nur, wenn ein Rollback tatsächlich versucht wurde (`ok`/`failed`), sonst
`not_attempted`.

## 8a. Nachverifikation nach dem Reload (AS2b)

In `observe`/`enforce` prüft der Agent nach einem erfolgreichen Reload unter der Agent-Sperre, was BIND tatsächlich
ausliefert (Polling: erste Runde sofort, danach 50 ms bis höchstens 500 ms Abstand, höchstens 10 s und nie über das
Arbeitsende − 1 s hinaus). Die Angaben sind rein informativ: HTTP-Status, `message`, `effect` und `rollback` bleiben
unverändert. Seit AS4b speichert der Core sie als Rohwerte im Journal und als Beobachtung in `zone_server_verification`
(`docs/ARCHITECTURE_HISTORY.md`, Abschnitt 5j); die operative Einordnung ändern sie nicht.

| Endpunkt | Feld | Werte | Bedeutung |
|---|---|---|---|
| `conf_sync` | `verification.state` | `verified` | Zone in der Form des Generators konfiguriert (K = ja), verwaltete Datei gültig, BIND liefert autoritativ die Serial dieser Datei (`file_serial` = `served_serial`, `file_sha256` der geprüften Datei). Belegt Serial-Gleichheit, nicht Inhaltsgleichheit. |
| | | `serial_differs` | am Fensterende liefert BIND eine andere Serial als die Datei (Rohwerte `file_serial`, `served_serial`) – z. B. Datei für `named` nicht lesbar oder ungültig geladen |
| | | `unknown` | kein Nachweis; `reason` nennt den Grund (`file_*`, `config_*`, `last_round_unknown` mit `last_reason`, `reload_*`, `budget_exhausted`) |
| `zone_cleanup` | `zones[].served` | `absent` | Reload im selben Aufruf erfolgreich, Zone nicht mehr konfiguriert (K = nein) und nachweislich nicht mehr autoritativ (`served_reason`: `parent_negative`, `parent_referral` oder `refused_control_ok`) |
| | | `still_served` | BIND liefert die Zone am Ende der Prüfung weiter autoritativ aus (z. B. Reload gescheitert oder Zone anderweitig konfiguriert); seit AS4b zusätzlich `served_serial` aus der entscheidenden Beobachtung (additiv, sonst `null`) |
| | | `unknown` | kein Nachweis (z. B. `zone_name_unresolved`, `control_zone_missing`, `last_round_unknown:…`, `budget_exhausted`) |
| `zone_cleanup` | `verification` | `rounds`, `elapsed_ms`, `window_ms`, `control_queries`, `name_proof` | Ablaufangaben; `control_queries` = tatsächlich gestartete DNS-Abfragen der Kontrollzone (auch mit Timeout); `name_proof.state` = `ok`, `skipped` (weniger als 20 s Restzeit), `undeterminable` oder `discarded` |

Betriebshinweise:
- Die Zonennamen verwaister Dateien stammen ausschließlich aus der BIND-Konfiguration vor dem Cleanup (verwaltete Datei
  `<zone_data_dir>/db.<name>`). Zonen mit Großbuchstaben oder abschließendem Punkt im Namen sind dort nicht eindeutig
  zuzuordnen und bleiben `unknown` (Folgepaket E128).
- `unknown` mit `control_zone_missing` bzw. `control_zone_own_acl`: Kontrollzone nach Abschnitt 8 einrichten.
- Ein `unknown` ist kein Fehler des Cleanups; Dateien und `zones.conf` sind wie in `zones[].conf`/`file` angegeben
  geändert. Der nächste Cleanup prüft erneut; das Monitoring prüft nur zugewiesene Zonen (`zone_verify`, Abschnitt 8b).
- Während BIND eine große Zone lädt (gemessen 300 000 RR ≈ 1,6 s), antwortet `rndc status` erst nach dem Laden und
  einzelne DNS-Abfragen auch an andere Zonen können ausbleiben (Befund B-M1-1); die Nachverifikation wartet dies ab.

## 8b. Lesende Beobachtung `zones/zone_verify` (AS4b, E109)

Für das Monitoring des Core führt `POST /api/v1/zones/zone_verify.php` (`{"zone_name": "<zone>"}`) **genau eine Runde**
der Nachverifikation aus Abschnitt 8a aus – dieselben Bausteine, kein Polling, kein Reload, keine Änderung an Dateien,
Konfiguration oder H.

| Lage | Antwort |
|---|---|
| `fencing_mode` `off` | 409 `verification_disabled` |
| `fencing_mode` fehlt oder ist ungültig | 503 `fence_misconfigured` (E118) |
| ohne `posix` | 503 `agent_requirement_missing` |
| ungültiger Zonenname | 400 `zone_name_invalid` |
| Agent-Sperre nicht innerhalb der Frist (gemeinsame Sperre `LOCK_SH`, keine Beobachtung während einer Änderung) | 503 `lock_timeout` |
| sonst | 200, `verification` wie in `conf_sync` (`verified`/`serial_differs`/`unknown` mit Rohwerten) |

`system/status` meldet in `observe`/`enforce` `capabilities.zone_verify = 1`; nur solche Agenten fragt das Monitoring ab.
Ältere Agenten (404) und Agenten in `off` werden nicht abgefragt. `verification.zone` muss die angefragte Zone nennen;
eine Antwort für eine andere Zone, ohne Zone oder mit ungültigem bzw. unvollständigem Objekt (Grundschema `state`,
`reason`, `zone`, `file_serial`, `file_sha256`, `served_serial` nicht vollständig, bei `unknown` ohne gültigen Grund)
verwirft der Core (kein
Eintrag, Zähler im Monitoring-Bericht). Das Monitoring speichert die Beobachtungen nur, wenn
während der Abfragen kein Publish-Lauf begonnen hat (Wasserstand, `ARCHITECTURE_HISTORY.md` Abschnitt 5j).

## 9. Upgrade: `fencing_mode` als Pflichtangabe (E118)

Ab AS2a ist `fencing_mode` eine Pflichtangabe. Bestehende Installationen, deren `api_config.php` den Schlüssel nicht
enthält und die zunächst im bisherigen Verhalten bleiben sollen, setzen ihn **vor** dem Update des Agent-Codes:

1. Sicherung: `cp -a /etc/dnsmanager/api_config.php /root/api_config.php.vor-e118` (Rechte notieren).
2. In `/etc/dnsmanager/api_config.php` im `return [...]`-Array ergänzen: `'fencing_mode' => 'off',`
3. Prüfen: `php -l /etc/dnsmanager/api_config.php`; Rechte `0640 root:www-data`; `sudo -u www-data php -r
   'var_dump((require "/etc/dnsmanager/api_config.php")["fencing_mode"]);'` liefert `string(3) "off"`.
4. Erst danach den Agent-Code aktualisieren. Kontrolle: `system/status` antwortet wie bisher (ohne `fencing`-Angaben), eine
   Veröffentlichung läuft wie bisher.

Wird der Code ohne diesen Schritt aktualisiert, lehnen alle vier Endpunkte jeden Aufruf mit `fence_misconfigured` (503) ab:
Veröffentlichung und Cleanup stehen, das Monitoring meldet den Server als fehlerhaft. Behebung: Schritt 2 nachholen; ein
Neustart ist nicht nötig (die Konfiguration wird je Anfrage gelesen).

## 10. Publish-Sperre, Datenbank-Restore und Code-Rückfall (AS5, E152)

Die Sperre ist **global** (alle Zonen); eine zonenspezifische Sperre gibt es nicht. Sie greift für Code ab AS4b
(`156f934`) einschließlich AS5 und unterbindet Publish All, Publish Zone, den DynDNS-Publish und
`docs/agent-tls/s42-publish-zone.php`: Fehlt `FENCE_DOMAIN` in `/etc/dnsmanager/ui_config.php`, bricht jeder Lauf unter
dem Publish-Lock vor J6/J1 ab (`exception`, kein Journal-Eintrag, kein Agent-Aufruf, Flags unverändert). DynDNS-Änderungen
werden weiter festgeschrieben und bleiben ausstehend (`publish_pending`). Die Admin-CLIs `fence_admin-cli.php` und
`serial_admin-cli.php` setzen die Domäne der Web-Konfiguration nicht voraus und bleiben nutzbar.

**Sperre setzen**
1. Sicherung: `cp -a /etc/dnsmanager/ui_config.php /root/dnsmanager-backup/ui_config.php.vor-sperre` (Verzeichnis 700).
2. In `ui_config.php` die Zeile `define('FENCE_DOMAIN', …)` auskommentieren; Rechte danach ausdrücklich `0640 root:www-data`
   setzen und Lesbarkeit prüfen: `sudo -u www-data php -l /etc/dnsmanager/ui_config.php`.
3. **Web-SAPI und OPcache auffrischen:** Die Oberfläche liest `ui_config.php` im tatsächlich genutzten Web-PHP, nicht im
   CLI. Mit OPcache und `opcache.validate_timestamps=0` (oder großem `opcache.revalidate_freq`) bleibt dort die alte
   Fassung mit `FENCE_DOMAIN` wirksam, obwohl die CLI die Sperre schon meldet. Deshalb den OPcache des Web-PHP
   ausdrücklich verwerfen:
   - `mod_php` (Apache, z. B. ns1/ns2: `apache2ctl -M` zeigt `php_module`): `systemctl reload apache2` (graceful;
     beendet die Kindprozesse und damit deren OPcache) – bei Zweifel `systemctl restart apache2`.
   - `php-fpm` (`apache2ctl -M` zeigt `proxy_fcgi_module`, Dienst `php<Version>-fpm`): `systemctl reload php<Version>-fpm`.
   - Andere SAPI: den dortigen PHP-Prozessverbund neu starten. Ein `opcache_reset()` aus der CLI wirkt **nicht** auf den
     Web-OPcache.
4. Nachweis aus der CLI: `sudo -u www-data php /usr/share/dnsmanager/web/scripts/serial_admin-cli.php status | head -1`
   meldet „Publish-Sperre: AKTIV – Fencing: Domäne in der Web-Konfiguration nicht gesetzt (FENCE_DOMAIN)“. Die CLI
   beweist nur die Datei, nicht den Zustand des Web-PHP.
5. **Nachweis aus dem Webkontext** (maßgeblich): `mariadb -N -e "SELECT MAX(id) FROM publish_runs"` notieren, dann in
   der Oberfläche als Administrator **Publish All** auslösen. Erwartet: Fehlertoast „Veröffentlichung fehlgeschlagen.“
   mit dem Text `Fencing: Domäne in der Web-Konfiguration nicht gesetzt (FENCE_DOMAIN)` und eine gleichlautende
   Fehlerzeile im Anwendungslog; `MAX(id)` von `publish_runs` unverändert, kein neuer Eintrag in
   `zone_serial_references`, Ausstehend-Markierungen unverändert, kein Zugriff im Agent-Log. Der Versuch ist gefahrlos:
   Der Lauf bricht unter dem Publish-Lock vor J6/J1 ab. Startet stattdessen ein Lauf (neuer `publish_runs`-Eintrag),
   ist die Sperre im Web-PHP **nicht** wirksam: Schritt 3 wiederholen und den Lauf als normalen Publish behandeln
   (Ergebnis prüfen). Bei mehreren Webknoten oder getrennter DynDNS-API je Knoten bzw. vhost wiederholen.
6. **Laufende Veröffentlichungen:** `mariadb -N -e "SELECT IS_USED_LOCK('dnsmanager_publish_all')"` muss NULL liefern.
   Sonst höchstens 10 Minuten warten (ein Lauf endet regulär); hängt er, die gemeldete Verbindung mit `KILL <id>` beenden
   (Lock-Verlust ⇒ kein weiterer Agent-Aufruf, E83) und danach **mindestens 35 s** warten (bereits gesendete Anfragen:
   cURL-Frist 30 s). Erst dann gilt die Sperre als wirksam.

**Datenbank-Restore**
1. Sperre setzen (oben) **vor** dem Restore. Während des Restores zusätzlich jede Fachänderung unterbinden: Webserver
   der Oberfläche und der DynDNS-API anhalten (`systemctl stop apache2`; auf ns1 betrifft das auch den lokalen Agenten)
   und den Monitoring-Cron aussetzen. Danach Restore einspielen.
2. Webserver wieder starten (Sperre bleibt aktiv), Fencing-Abgleich nach Abschnitt 7 (`raise-runs`).
3. Serial-Abgleich: für Agenten in `observe`/`enforce` eine gespeicherte Monitoring-Runde abwarten; für Agenten in `off`
   je Zone und Zielserver Datei- und ausgelieferte Serial erheben (`dig +norec @127.0.0.1 <zone> SOA`,
   `named-checkzone <zone> <datei>` auf dem Server) und mit
   `serial_admin-cli.php record --zone-id=… --zone=… --server-id=… --server=… --file=<n>|--served=<n> --evidence=…`
   erfassen (je Bestandteil ein Aufruf).
4. `serial_admin-cli.php status` muss Exit 0 liefern (keine Sperre, jede Zone auflösbar). Erst dann `FENCE_DOMAIN`
   zurückspielen (Rechte wie oben), den Web-OPcache wie unter „Sperre setzen“, Schritt 3, auffrischen, mit
   `fence_admin-cli.php status` die Gleichheit der Domänen prüfen und einen ersten Publish kontrolliert ausführen.

**Code-Rückfall auf AS4b (`156f934`)**
- Technisch ist der AS4b-Code auf Schema 008 lauffähig (die Tabelle bleibt und wird ignoriert). Sein Publish-Betrieb ist
  damit **nicht** freigegeben: Sperre vor der Installation setzen und bestehen lassen, bis die sichere Wiederaufnahme
  ausdrücklich freigegeben ist.
- Vorher (noch mit AS5) `serial_admin-cli.php status` sichern: Zonen, deren nächste Serial nach AS5 von der Altregel
  (`generate_next_serial(DB-Serial)`) abweicht oder die gesperrt sind, sind nach dem Rückfall gefährdet. Der AS4b-Code
  kennt keine zonenbezogene Sperre: Solange eine solche Zone nicht bereinigt ist, bleibt der gesamte Publish-Betrieb
  global gesperrt.
- Nach der Rückkehr zu AS5 erkennt der Core Läufe ohne Laufmarke mit `as2`-zone_sync und nicht gespiegelte Beobachtungen
  als Lücke (Sperre bis zur Erfassung beider Bestandteile).
