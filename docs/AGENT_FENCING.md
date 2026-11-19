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
| `<zone_data_dir>/.dnsmanager-fence.fault` | Fehlermarke (Write-ahead); solange vorhanden, lehnt `enforce` alle Schreibvorgänge ab |
| `scripts/agent_fence-cli.php` | lokale Verwaltung von H (nicht per Apache erreichbar) |

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

Rückweg: `fencing_mode` entfernen (= `off`). H bleibt liegen und wird in `off` weder gelesen noch geschrieben. Eine
etwaige Fehlermarke sperrt dagegen auch in `off` alle Schreibvorgänge (E97) und muss nach Abschnitt 4 aufgehoben werden.

## 4. Fehlermarke aufheben

Eine Marke entsteht vor jeder Erhöhung von H und bleibt nur nach einem Abbruch (Absturz, Persistenz- oder
Dateisystemfehler) liegen. Sie sperrt `zone_sync`, `conf_sync` und `zone_cleanup` in **jedem** Modus (auch `off`) mit
`fence_fault`; `system/status` zeigt sie als `fencing.state = fault`.

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

`conf_sync` und `zone_cleanup` brechen mit `listing_incomplete` ohne Änderung und ohne Reload ab, wenn ein bisheriges
`include` aus `zones.conf` in der neuen Verzeichnisauflistung fehlt (E98). Ursache ist entweder ein Lesefehler des
Dateisystems oder eine `include`-Zeile auf eine nicht mehr vorhandene `.conf`.

1. Dateisystem prüfen (`dmesg`); bei Lesefehlern zuerst diese beheben.
2. Sonst die betroffene Zeile ermitteln: jede `include`-Zeile der `zones.conf` muss auf eine vorhandene Datei zeigen.
3. Sicherung von `zones.conf` (Rechte erhalten), Zeile entfernen oder die `.conf` wiederherstellen, `named-checkconf`
   prüfen; danach erneut veröffentlichen.

## 7. Datenbank-Restore

Ein zurückgespieltes Backup kann die Lauf-ID-Folge unter H senken; die Agenten lehnen dann mit `stale_fence` ab
(fail-closed). Abhilfe ab AS4 per Core-Abgleich (H aller Agenten lesen, `publish_runs.AUTO_INCREMENT` darüber setzen).
Bis dahin manuell: H aller Agenten per CLI `status` lesen und `ALTER TABLE publish_runs AUTO_INCREMENT = <max(H) + 1>`
unter dem Publish-Lock. Agenten senken H nie.
