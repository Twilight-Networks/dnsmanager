# Agent-Fencing: Betrieb (Meilenstein AS)

Stand: 19.11.2026 · AS1 (Agent). Architektur und Entscheidungen: [`ARCHITECTURE_HISTORY.md`](ARCHITECTURE_HISTORY.md),
Abschnitte 11 und 5e. Der Core sendet das Fencing-Token erst ab AS4; bis dahin sind auf installierten Agenten nur die
Modi `off` und `observe` zulässig.

## 1. Dateien und Konfiguration

| Ort | Inhalt |
|---|---|
| `api_config.php` `fencing_mode` | `off` (Standard, wenn nicht gesetzt), `observe`, `enforce`; ein unbekannter Wert sperrt alle Schreibvorgänge |
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

Rückweg: `fencing_mode` entfernen (= `off`). Die H-Datei bleibt liegen. In `off` lesen und schreiben die drei
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
(fail-closed). Abhilfe ab AS4 per Core-Abgleich (H aller Agenten lesen, `publish_runs.AUTO_INCREMENT` darüber setzen).
Bis dahin manuell: H aller Agenten per CLI `status` lesen und `ALTER TABLE publish_runs AUTO_INCREMENT = <max(H) + 1>`
unter dem Publish-Lock. Agenten senken H nie.
