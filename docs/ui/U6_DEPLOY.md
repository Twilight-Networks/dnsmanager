# U6 – Deployment- und Rollback-Runbook ns1 (UI-Modernisierung U1–U6a)

Stand: 15.11.2026. Gilt für das Update der ns1-Testinstallation vom installierten Stand `7cdd9a8` auf den freigegebenen `main`-Stand mit U6a.

**Freigaben (je ausdrücklich durch Thomas):**

| | Freigabe |
|---|---|
| **G1** | dieses Runbook |
| **G2** | Start des Deployments (Abschnitte 2–7) |
| **G3** | Wechsel von `report-only` auf `enforce` (Abschnitt 8) |
| **GR** | jede Daten-/DNS-Rückführung (Abschnitt 9.5) |

**Geltungsbereich:**
- Nur ns1 (192.168.53.122) erhält neuen Code.
- **ns2 (192.168.53.125) wird nicht verändert.** Dort wird nur gelesen (Serials, Zonendateien, Journal).
- Die DNS-Verteilung zu ns2 wird funktional geprüft (Abschnitt 7.3).

## 0. Ausgangslage und Variablen

| | |
|---|---|
| Installierter Stand | `/usr/share/dnsmanager`, detached `7cdd9a83bd673cb787d812a087c8d89947ba18be` |
| Web | `/var/www/html/dnsmanager-ui` → `/usr/share/dnsmanager/web/`, Apache 2.4 mit mod_php 8.5, OPcache an, PrivateTmp=yes |
| Konfiguration | `/etc/dnsmanager/ui_config.php` (640 root:www-data; Symlink `web/config/ui_config.php`), **ohne `CSP_MODE`** |
| Datenbank | MariaDB 11.8, DB `dnsmanager` |
| BIND | ns1: `/etc/bind/zones` (www-data), `rndc reload` per sudo. ns2: Agent schreibt Zonendateien, BIND lädt neu. |
| Zonen | `example.test` (DynDNS an), `zone.example.test`, `53.168.192.in-addr.arpa`, `…ip6.arpa`. Master ns1, Slave ns2 über Agent. |
| Cron | `/etc/cron.d/dnsmanager_monitoring` (alle 5 min Monitoring, täglich Log-Bereinigung) |

**Unterschied `7cdd9a8` → neuer Stand (geprüft):**
- Keine Änderungen unter `sql/`, `agent/`, `scripts/`, `cron/`, `sudoers.d/`, `apache/`, `fail2ban/`.
- Keine Migration, kein Agent-Update auf ns2, keine Änderung an sudo oder Apache.
- Neu ist ausschließlich Web-Code (UI U1–U6a, Record-Core U4) sowie die optionale Einstellung `CSP_MODE`.

```bash
OLD=7cdd9a83bd673cb787d812a087c8d89947ba18be
NEW=<freigegebener main-SHA>          # 40 Zeichen, aus der Freigabe G2
NEW_TREE=<zugehöriger Tree>
B=/root/u6-deploy-$(date +%Y%m%d-%H%M)
R=/usr/share/dnsmanager
```

**Abbruchregel:** Weicht ein Prüfergebnis in Abschnitt 1–6 von der Erwartung ab, wird abgebrochen. Ist der Code bereits gewechselt, folgt Abschnitt 9.3; vorher ist nichts zurückzunehmen.

## 1. Ausgangsprüfung (nur lesend)

```bash
git -C $R rev-parse HEAD; git -C $R status --short          # = $OLD, keine Ausgabe
systemctl is-active apache2 mariadb named                    # 3 × active
named-checkconf && echo checkconf-ok
mariadb -N dnsmanager -e "SELECT IS_FREE_LOCK('dnsmanager_publish_all'), IS_FREE_LOCK('dnsmanager_monitoring'), (SELECT COUNT(*) FROM zones WHERE changed=1)"
                                                             # 1 1 0 (nichts unveröffentlicht)
ls -d /tmp/dnsmgr-uig 2>/dev/null; mariadb -N -e "SHOW DATABASES LIKE 'dnsmgr\_%'"   # beides leer
for z in example.test zone.example.test 53.168.192.in-addr.arpa; do
  echo "$z ns1=$(dig +short @127.0.0.1 $z SOA | cut -d' ' -f3) ns2=$(dig +short @192.168.53.125 $z SOA | cut -d' ' -f3)"; done
                                                             # je ns1 = ns2
journalctl --since "-15 min" --no-pager | grep -E "Monitoring-Run" | tail -2     # zuletzt erfolgreich
git -C $R fetch -q origin && git -C $R cat-file -e "$NEW^{commit}" && git -C $R rev-parse "$NEW^{tree}"   # = $NEW_TREE
git -C $R diff --stat $OLD $NEW -- sql agent scripts cron sudoers.d apache fail2ban   # keine Ausgabe
```

## 2. Sicherung

```bash
mkdir -m 700 $B && cd $B
git -C $R rev-parse HEAD > head-vorher.txt
mariadb-dump --single-transaction --routines --triggers --events dnsmanager > db-vorher.sql
mariadb -N dnsmanager -e "CHECKSUM TABLE users, user_zones, zones, zone_servers, servers, records, dyndns_accounts" > db-checksums-vorher.txt
tar -cpzf etc-dnsmanager.tgz -C / etc/dnsmanager        # mit Eigentümer und Rechten
tar -cpzf etc-bind.tgz -C / etc/bind
sha256sum /etc/bind/zones/db.* > zonen-ns1-vorher.sha256
for z in example.test zone.example.test 53.168.192.in-addr.arpa; do echo "$z $(dig +short @127.0.0.1 $z SOA)"; done > serials-vorher.txt
sha256sum /etc/dnsmanager/ui_config.php > config-sha-vorher.txt
ls -la /etc/dnsmanager > config-rechte-vorher.txt
chmod 600 $B/*
```

Auf ns2 nur lesend, Ergebnis im Protokoll festhalten: `sha256sum /etc/bind/zones/db.*` und SOA-Serials. Der Proxmox-Snapshot ist von innen nicht feststellbar; ob einer vorliegt, klärt Thomas vor G2.

**Sicherung prüfen (Restore-Probe in eine Wegwerf-DB):**

```bash
mariadb -e "CREATE DATABASE dnsmgr_u6_restorecheck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb dnsmgr_u6_restorecheck < $B/db-vorher.sql
mariadb -N dnsmgr_u6_restorecheck -e "CHECKSUM TABLE users, user_zones, zones, zone_servers, servers, records, dyndns_accounts" \
  | sed 's/dnsmgr_u6_restorecheck/dnsmanager/' | diff - $B/db-checksums-vorher.txt && echo restore-probe-ok
mariadb -e "DROP DATABASE dnsmgr_u6_restorecheck"
```

## 3. CSP-Modus **vor** dem Checkout setzen und prüfen (U6-P1)

Der neue Code setzt ohne Einstellung `enforce` durch. Deshalb wird vor dem ersten Checkout `report-only` gesetzt und mit dem neuen Code geprüft. Der alte Code (`7cdd9a8`) ignoriert die Konstanten.

```bash
install -d -m 700 -o www-data -g www-data /var/lib/dnsmanager/csp     # Ratenstatus des Report-Endpunkts
grep -n "CSP_" /etc/dnsmanager/ui_config.php                           # vorher: keine Ausgabe
sed -i "s#^?>\$#define('CSP_MODE', 'report-only');   // U6: Erstbetrieb, Wechsel auf enforce erst nach Freigabe G3\ndefine('CSP_REPORT_STATE_DIR', '/var/lib/dnsmanager/csp');\n?>#" /etc/dnsmanager/ui_config.php
grep -n "CSP_" /etc/dnsmanager/ui_config.php                           # genau die zwei neuen Zeilen vor ?>
stat -c '%a %U:%G' /etc/dnsmanager/ui_config.php                       # 640 root:www-data (unverändert)
php -l /etc/dnsmanager/ui_config.php
sudo -u www-data php -r 'require "/etc/dnsmanager/ui_config.php"; echo CSP_MODE, "\n";'   # report-only (für www-data lesbar)
# Wirksamkeit mit dem NEUEN Code prüfen, ohne ihn zu installieren (nur csp.php in ein eigenes Unterverzeichnis)
mkdir -m 700 $B/newcode && git -C $R archive $NEW web/inc/web/csp.php | tar -x -C $B/newcode
php -r 'require "/etc/dnsmanager/ui_config.php"; require $argv[1]; echo csp_mode(), "\n", csp_header_line(), "\n";' $B/newcode/web/inc/web/csp.php
```

**Erwartung der letzten Zeile:**
- `report-only`
- `Content-Security-Policy-Report-Only: default-src 'self'; … frame-ancestors 'none'; report-uri /dnsmanager-ui/csp_report.php`

**Nur wenn beide Werte exakt so erscheinen, geht es weiter.** Sonst `/etc/dnsmanager/ui_config.php` nach 9.4 zurücksetzen und abbrechen.

## 4. Code-Update

```bash
git -C $R checkout -q --detach $NEW
git -C $R rev-parse HEAD "HEAD^{tree}"            # = $NEW, $NEW_TREE
git -C $R status --short                          # keine Ausgabe
ls -l $R/web/config/ui_config.php                 # Symlink auf /etc/dnsmanager/ui_config.php unverändert
systemctl reload apache2                          # OPcache/mod_php: neuer Code sofort wirksam
```

## 5. Technische Prüfung

```bash
curl -skI https://127.0.0.1/dnsmanager-ui/login.php | grep -iE "^(HTTP|content-security|x-content|x-frame|referrer)"
#   HTTP 200; Content-Security-Policy-Report-Only exakt wie in Abschnitt 3; KEIN "Content-Security-Policy:";
#   nosniff, DENY, same-origin vorhanden
curl -sk -o /dev/null -w "%{http_code}\n" https://127.0.0.1/dnsmanager-ui/csp_report.php        # 405 (Endpunkt aktiv)
curl -sk -o /dev/null -w "%{http_code}\n" https://127.0.0.1/dnsmanager-ui/pages/zones.php       # 302 (Login)
named-checkconf && echo checkconf-ok
journalctl --since "-10 min" --no-pager | grep -E "PHP (Fatal|Warning|Parse)|event=php_error"   # keine Ausgabe
tail -n 50 /var/log/apache2/error.log                                                            # keine neuen Fehler
# nächsten Monitoring-Lauf abwarten (≤ 5 min):
journalctl --since "-6 min" --no-pager | grep -E "Monitoring-Run"                                # "erfolgreich beendet"
```

## 6. Wiederanlauf

Zweck: Neustart ohne Zustandsverlust, Sitzungen bleiben gültig.

```bash
systemctl restart apache2 && systemctl is-active apache2
systemctl restart named && systemctl is-active named && rndc status | head -3
for z in example.test zone.example.test 53.168.192.in-addr.arpa; do echo "$z $(dig +short @127.0.0.1 $z SOA | cut -d' ' -f3)"; done   # = serials-vorher
```

Danach Abschnitt 5 kurz wiederholen (Header, Login 200). MariaDB wird nicht neu gestartet; der Dienst ist durch das Update nicht betroffen.

## 7. Funktionsabnahme (Thomas, Browser; Report-Only aktiv)

Während der gesamten Abnahme CSP-Meldungen mitlesen:

```bash
journalctl -f --no-pager | grep --line-buffered "event=csp_"
```

Erwartet werden 0 Verstöße. Jede Meldung wird vor G3 bewertet.

### 7.1 Oberfläche
- Anmeldung, Dashboard, Zonen (Liste, Editor), Records (Liste, Editor mit Live-Vorschau/DIFF, Typwechsel), Server, DynDNS, Benutzer (eigenes Passwort nicht ändern), Systemstatus, Update-Seite: Darstellung hell und dunkel, keine Fehlermeldungen.
- **Mobil (≤ 375 px, Browser-Entwicklerwerkzeuge):** Menü öffnet sich auf allen Seiten, auch auf der Update-Seite (U6-B1). Tabellen scrollen im Container.
- Rollen: zoneadmin sieht nur eigene Zonen, Server/Benutzerverwaltung → 403-Seite. Die Testkonten `dyndnstest`/`n1race` bleiben unverändert.

### 7.2 Daten ändern (eigene Abnahmedaten, werden in 7.5 entfernt)
- In `zone.example.test` einen TXT-Record `u6-abnahme` mit Inhalt `"u6 <Datum>"` anlegen.
- Erwartung: Toast „erfolgreich“, Zone als ausstehend markiert.

### 7.3 Veröffentlichung und Verteilung nach ns2
- „Veröffentlichen“ auslösen.
- Erwartung: Erfolgsmeldung, Zone nicht mehr ausstehend.

```bash
dig +short @127.0.0.1      zone.example.test SOA | cut -d' ' -f3    # Serial erhöht gegenüber serials-vorher
dig +short @192.168.53.125 zone.example.test SOA | cut -d' ' -f3    # = ns1
dig +short @127.0.0.1      u6-abnahme.zone.example.test TXT          # "u6 <Datum>"
dig +short @192.168.53.125 u6-abnahme.zone.example.test TXT          # "u6 <Datum>"
grep -c u6-abnahme /etc/bind/zones/db.zone.example.test             # 1
journalctl --since "-10 min" --no-pager | grep -iE "publish|rndc|agent" | tail -5   # Erfolg, keine Fehler
```

Auf ns2 nur lesend: `journalctl --since "-10 min" | grep -i dnsmanager-agent` zeigt den Empfang; `named-checkzone zone.example.test /etc/bind/zones/db.zone.example.test` meldet ok.

### 7.4 DynDNS (temporäres eigenes Konto)
Der Hostname `dyn` ist auf ns1 durch zwei Testkonten mehrdeutig (siehe [U6B_DYNDNS.md](U6B_DYNDNS.md)). Deshalb:
1. In der DynDNS-Verwaltung ein Konto `u6abn` mit Hostname `u6abn` in `example.test` anlegen (eigenes Testpasswort, nicht protokollieren).
2. Aufrufen: `curl -sk -u u6abn https://127.0.0.1/dnsmanager-ui/api/v1/dyndns/update.php?myip=192.0.2.66` (Passwort interaktiv).
3. Erwartung: `good 192.0.2.66`, Record als „DynDNS“ gekennzeichnet (nur TTL änderbar).
4. `dig +short @127.0.0.1 u6abn.example.test A` und dasselbe `@192.168.53.125` liefern `192.0.2.66`; Serials ns1 = ns2.
5. Ein zweiter Aufruf mit derselben IP liefert `nochg`.

### 7.5 Aufräumen der Abnahmedaten
- Record `u6-abnahme` löschen. Konto `u6abn` löschen; das entfernt seine Records, Meldung „… und zugehörige A/AAAA-Records gelöscht“.
- Danach „Veröffentlichen“.
- Erwartung: `dig` auf ns1 und ns2 liefert für beide Namen nichts mehr; Serials ns1 = ns2.
- Datenbestand prüfen:

```bash
mariadb -N dnsmanager -e "CHECKSUM TABLE users, user_zones, zones, zone_servers, servers, records, dyndns_accounts" > $B/db-checksums-nachher.txt
diff $B/db-checksums-vorher.txt $B/db-checksums-nachher.txt
mariadb -N dnsmanager -e "SELECT COUNT(*) FROM records WHERE name IN ('u6-abnahme','u6abn'); SELECT COUNT(*) FROM dyndns_accounts WHERE username='u6abn'"   # 0, 0
```

Erwartet: Nur `zones` weicht ab (Serial von `zone.example.test` und ggf. `example.test` erhöht). `users` bleibt gleich (Anmeldungen ändern die Tabelle nicht; eine eigene Passwortänderung wäre eine zu benennende Abweichung). `records` und `dyndns_accounts` sind nach dem Aufräumen gleich; `AUTO_INCREMENT` geht nicht in `CHECKSUM TABLE` ein.

### 7.6 Abschluss G2
- Protokoll: `$NEW`/`$NEW_TREE`, Ergebnisse der Abschnitte 1–7 und Anzahl der CSP-Meldungen.
- Die Sicherung `$B` bleibt erhalten.
- Betrieb in `report-only` bis zur Freigabe G3.

## 8. Wechsel auf `enforce` (nur nach Freigabe G3)

**Voraussetzung:** Report-Only-Phase ohne unbewertete CSP-Meldungen:

```bash
journalctl --since "<Beginn G2>" --no-pager | grep -c "event=csp_violation"
```

```bash
cp /etc/dnsmanager/ui_config.php $B/ui_config-vor-g3.php && chmod 600 $B/ui_config-vor-g3.php
sed -i "s#^define('CSP_MODE', 'report-only');.*#define('CSP_MODE', 'enforce');   // U6: nach Freigabe G3#" /etc/dnsmanager/ui_config.php
grep -n "CSP_MODE" /etc/dnsmanager/ui_config.php; stat -c '%a %U:%G' /etc/dnsmanager/ui_config.php   # enforce; 640 root:www-data
curl -skI https://127.0.0.1/dnsmanager-ui/login.php | grep -i "^content-security"
#   genau "Content-Security-Policy: default-src 'self'; … frame-ancestors 'none'" (ohne report-uri), kein Report-Only
curl -sk -o /dev/null -w "%{http_code}\n" https://127.0.0.1/dnsmanager-ui/csp_report.php        # 404 (Endpunkt aus)
```

Danach Abschnitt 7.1 in Kurzform wiederholen. Die Konfiguration wird ohne Reload sofort wirksam (wird je Anfrage gelesen).

## 9. Rollback

### 9.1 Entscheidung

| Symptom | Maßnahme |
|---|---|
| Darstellung/Funktion durch CSP gestört (nach G3) | 9.2: CSP-Sofortmaßnahme, kein Code-Rollback |
| Fehler im neuen Code (500, Fehlfunktion), Daten in Ordnung | 9.3: Code-Rollback |
| Konfiguration beschädigt bzw. nicht lesbar | 9.4: Konfigurations-Rollback |
| Daten fehlerhaft verändert, DNS falsch verteilt | 9.5: nur nach Bewertung und Freigabe GR |

U6 enthält **keine Schemaänderung**. Ein Code-Rollback erfordert daher nie eine Datenbank-Rückführung.

### 9.2 CSP-Sofortmaßnahme (nur Konfiguration)

```bash
sed -i "s#^define('CSP_MODE', '[a-z-]*');.*#define('CSP_MODE', 'off');   // U6: Notschalter#" /etc/dnsmanager/ui_config.php
curl -skI https://127.0.0.1/dnsmanager-ui/login.php | grep -ic "^content-security"    # 0
```

`'report-only'` statt `'off'` ist vorzuziehen, wenn nur die Durchsetzung stört, die Meldungen aber gebraucht werden.

### 9.3 Code-Rollback

```bash
git -C $R checkout -q --detach $OLD
git -C $R rev-parse HEAD; git -C $R status --short     # = $OLD, keine Ausgabe
systemctl reload apache2
```

- Danach Abschnitt 5 (ohne CSP-Erwartungen; `7cdd9a8` sendet keinen CSP-Header) und 7.3 (Veröffentlichung) prüfen.
- Die zwei CSP-Zeilen in der Konfiguration ignoriert der alte Code; sie bleiben für einen erneuten Versuch stehen oder werden nach 9.4 entfernt.
- Daten, die mit dem neuen Code angelegt wurden, bleiben gültig (gleiches Schema).

### 9.4 Konfigurations-Rollback

Nie mit `cp -p` aus der 0600-Sicherung zurückkopieren, Rechte ausdrücklich setzen:

```bash
mkdir -m 700 $B/restore && tar -xzf $B/etc-dnsmanager.tgz -C $B/restore
cp $B/restore/etc/dnsmanager/ui_config.php /etc/dnsmanager/ui_config.php
chown root:www-data /etc/dnsmanager/ui_config.php && chmod 640 /etc/dnsmanager/ui_config.php
sha256sum -c $B/config-sha-vorher.txt
sudo -u www-data php -r 'require "/etc/dnsmanager/ui_config.php"; echo "lesbar\n";'
```

### 9.5 Daten- und DNS-Rückführung (nur nach Bewertung, Freigabe GR)

Ein DB-Restore wird **nie automatisch** ausgeführt. Er würde alle zwischenzeitlichen Änderungen verwerfen: Records, Zonen, Benutzer, DynDNS-Updates über die API, Monitoring-Diagnosen.

**1. Aktuellen Stand sichern, bevor irgendetwas zurückgeführt wird:**

```bash
mariadb-dump --single-transaction --routines --triggers --events dnsmanager > $B/db-vor-rueckfuehrung.sql; chmod 600 $B/db-vor-rueckfuehrung.sql
```

**2. Zwischenzeitliche Änderungen ermitteln:**
- Prüfsummen je Tabelle mit `db-checksums-vorher.txt` vergleichen.
- Für abweichende Tabellen die Zeilen vergleichen, z. B. `records`:

```bash
mariadb -e "CREATE DATABASE dnsmgr_u6_alt; CREATE DATABASE dnsmgr_u6_neu"
mariadb dnsmgr_u6_alt < $B/db-vorher.sql
mariadb dnsmgr_u6_neu < $B/db-vor-rueckfuehrung.sql
mariadb -e "SELECT 'neu' k, n.id, n.zone_id, n.name, n.type, n.content FROM dnsmgr_u6_neu.records n LEFT JOIN dnsmgr_u6_alt.records o USING (id) WHERE o.id IS NULL
  UNION ALL SELECT 'geloescht', o.id, o.zone_id, o.name, o.type, o.content FROM dnsmgr_u6_alt.records o LEFT JOIN dnsmgr_u6_neu.records n USING (id) WHERE n.id IS NULL
  UNION ALL SELECT 'geaendert', n.id, n.zone_id, n.name, n.type, n.content FROM dnsmgr_u6_neu.records n JOIN dnsmgr_u6_alt.records o USING (id)
    WHERE NOT (n.name <=> o.name AND n.type <=> o.type AND n.content <=> o.content AND n.ttl <=> o.ttl)"
# entsprechend für zones, dyndns_accounts, users (ohne Hash-Spalten ausgeben)
```

- Zusätzlich das Journal seit Beginn G2 auswerten (`journalctl | grep DNSMANAGER`: Anlage/Änderung/Löschung, DynDNS-Updates).

**3. Bewerten und entscheiden:**
- **Bevorzugt:** fehlerhafte Einzeländerungen gezielt über die Oberfläche bzw. den Service korrigieren.
- **Vollständiger Restore von `db-vorher.sql`** nur, wenn die Zwischenänderungen bekannt sind und bewusst verworfen oder danach erneut erfasst werden.

**4. DNS nach jeder Datenkorrektur aus der Datenbank neu verteilen:**
- „Veröffentlichen“, dann Serials ns1 = ns2 und `dig`-Stichproben (wie 7.3).
- **Zonendateien nie direkt auf ns2 kopieren.** `etc-bind.tgz` nur im Notfall auf ns1 zurückspielen und danach sofort aus der DB veröffentlichen.

**5. Temporäre Vergleichsdatenbanken danach löschen:** `mariadb -e "DROP DATABASE dnsmgr_u6_alt; DROP DATABASE dnsmgr_u6_neu"`.

## 10. Bekannte Einschränkungen (für die Abnahme)

| ID | Einschränkung |
|---|---|
| U6-H1 | `GET logout.php` antwortet mit 405 ohne Sicherheitsheader (vorbestehend, ohne Inhalt) |
| U6-H2 | Breite Tabellen scrollen mobil im Container; die Aktionsspalte ist durch Wischen erreichbar |
| DY-0 | Mehrdeutig reservierte DynDNS-Records erscheinen wie manuelle Records; Ändern/Löschen wird erst beim Absenden abgelehnt (U6B_DYNDNS.md) |
| DY-1 | Im mehrdeutigen Zustand liefert die DynDNS-API `dnserr`. Auf ns1 betrifft das `dyndnstest`/`n1race` (gemeinsamer Hostname `dyn`, Testzustand). |
| DY-2 | Löschmeldung eines DynDNS-Accounts kann „zugehörige Records gelöscht“ melden, obwohl ein nicht zuordenbarer Record bleibt |
| U6-T1 | Die Zwischenablage-Schaltfläche (DynDNS-URL) braucht HTTPS und eine Browser-Berechtigung; im Testbrowser war die Berechtigung nicht erteilt |

Akzeptanz von DY-0 bis DY-2 für die Produktivabnahme: offen (Entscheidung Thomas, Optionen in U6B_DYNDNS.md).

## Anhang A – Prüfung des Runbooks (15.11.2026, ohne Deployment)

Auf ns1 wurden die kritischen Befehle **an Kopien bzw. in Wegwerf-Datenbanken** ausgeführt, mit dem Code von `main` `cdad774` (Harness-Checkout):

| Abschnitt | Ergebnis |
|---|---|
| 3 CSP-Zeilen (Kopie von `ui_config.php`) | Genau 2 neue Zeilen vor `?>`, Syntax ok. Mit dem neuen `csp.php`: `report-only` und exakt der Report-Only-Header mit `report-uri /dnsmanager-ui/csp_report.php`. |
| 8 Wechsel auf `enforce` (Kopie) | `enforce`, exakt `Content-Security-Policy: <Zielrichtlinie>` ohne `report-uri` |
| 9.2 Notschalter (Kopie) | `off`, kein Header (`null`) |
| 2 Sicherung und Restore-Probe | Dump 20 KB; Prüfsummen der Wegwerf-DB = Original (`restore-probe-ok`); Wegwerf-DB gelöscht |
| 9.5 Vergleichsabfrage | erkennt einen neuen und einen geänderten Record korrekt (künstliche Änderung nur in der Kopie) |
| 1 Ausgangsprüfung | Locks frei, keine unveröffentlichten Zonen. Der neue Commit ist im ns1-Checkout noch nicht geholt; `git fetch` in Abschnitt 1 holt ihn. |

Danach unverändert: Konfiguration (SHA-256), Checkout `7cdd9a8`, DB-Prüfsummen. Keine Test-DBs übrig. ns2 wurde nur lesend abgefragt (SOA-Serials ns1 = ns2 für alle Zonen).
