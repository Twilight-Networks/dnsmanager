# Agent-TLS: interne CA, Zertifikate, Umstellung und Rückweg (S42)

Stand: 13.11.2026 · Befund S42 (siehe `docs/ARCHITECTURE_CORE.md`, Abschnitt 6).

**Status:**
- **Phase 1 – erledigt:** PR #68, `main` `b17d946`. Code-Option `AGENT_TLS_CA_FILE` in `BindAgentClient`, isoliert getestet (Abschnitt 12).
- **Phase 2 – vorbereitet:** E5 freigegeben, E6 freigegeben mit Ergänzung, E7/E8 grundsätzlich freigegeben. Die Durchführung wird gesondert freigegeben.
- Bis dahin gilt:
  - keine produktive CA erzeugen;
  - keine Zertifikate austauschen;
  - die TLS-Prüfung bleibt aus;
  - kein produktiver BIND-Reload oder Publish.
- S42 bleibt offen, bis Phase 2 produktiv abgenommen ist.

**Hilfsskripte** (`docs/agent-tls/`, keine Geheimnisse, Ergebnis über Exit-Code):

| Skript | Zweck | Ausführung |
|---|---|---|
| `s42-vhost-check.sh` | VirtualHost-Auswahl und Zertifikatsauslieferung (E5) | je Host |
| `s42-precheck.sh` | Vorprüfung vor der Aktivierung (CA-Datei, Kette, Rechte, Handshake, PHP-Stack, Oberfläche unverändert) | ns1 |
| `s42-nc-negative.sh` | Negativtest der `nameConstraints` mit dem PHP-cURL-Stack (E7) | ns1 |

## 1. Entscheidungen

| Nr. | Inhalt | Stand |
|---|---|---|
| S42-E1 | Interne CA mit IP-SANs. Adressierung (`servers.api_ip`) und Datenbankstruktur bleiben unverändert. | freigegeben |
| S42-E2 | Eigene Agent-CA:<br>– CA ca. 5 Jahre, Serverzertifikate 1 Jahr;<br>– Erneuerung ≥ 30 Tage vor Ablauf;<br>– je Server ein eigener Schlüssel;<br>– SANs = Agent-IPs plus passende DNS-Namen;<br>– CA-Schlüssel nicht auf ns1/ns2. | freigegeben |
| S42-E3 | Phase 1: optionale, ausschließliche CA-Vertrauensstellung im Code | erledigt (#68) |
| S42-E4 | Abnahme in dieser Reihenfolge:<br>1. lesende Agent-Aufrufe und der reguläre Monitoring-Cron;<br>2. BIND-Reload und Publish erst im Rahmen der gesondert freigegebenen Abnahme, Publish nur auf einer ausdrücklich freigegebenen Testzone. | freigegeben. Testzone: Vorschlag in Abschnitt 6 G, Freigabe offen. |
| S42-E5 | Getrennte, IP-gebundene Apache-VirtualHosts für die Agent-Zertifikate:<br>– ns1 `127.0.0.1:443`, ns2 `192.168.53.125:443`;<br>– die Oberfläche auf ns1 `192.168.53.122:443` bleibt unverändert;<br>– die Agent-CA wird **nicht** auf Admin-Arbeitsplätzen importiert.<br>Vor der Aktivierung sind die tatsächliche VirtualHost-Auswahl, die Zertifikatsauslieferung und der unveränderte Browserzugriff nachzuweisen (Schritt D4/D5). | freigegeben |
| S42-E6 | Die Ablaufüberwachung muss **vor** der TLS-Aktivierung nachweislich alarmieren. Eine bloße Logzeile reicht nicht. Keine neue externe Benachrichtigungsinfrastruktur ohne Rücksprache. | freigegeben mit Ergänzung. Alarmweg **E6-A** entschieden und umgesetzt (Abschnitt 9). |
| S42-E7 | CA mit kritischen `nameConstraints` auf genau die benötigten Agent-IPs und DNS-Namen (Abschnitt 5). Negativtest mit dem PHP-cURL-Stack vor produktiver Verwendung (Schritt D6). | grundsätzlich freigegeben |
| S42-E8 | CA-Schlüssel:<br>– verschlüsselt;<br>– zwei getrennte Offline-Sicherungen;<br>– Passphrase im Passwortmanager.<br>Vor der CA-Erstellung legt Thomas fest: Ausstellungsrechner, Offline-Speicherorte und Wiederherstellungszuständigkeit. | grundsätzlich freigegeben, Festlegungen offen |

## 2. Ist-Zustand (13.11.2026, `main` `b17d946`)

**Agent-Adressen** (`servers.api_ip`):
- ns1 ruft seinen eigenen Agenten über `127.0.0.1` auf.
- ns2 wird von ns1 über `192.168.53.125` aufgerufen.
- URL: `https://{api_ip}/dnsmanager-agent/api/v1/…`.

**Apache lauscht:**
- ns1 auf `127.0.0.1:443` und `192.168.53.122:443`;
- ns2 auf `127.0.0.1:443` und `192.168.53.125:443`.

Beide Hosts haben je einen VirtualHost `default-ssl.conf` (`*:443`, snakeoil, selbstsigniert, SAN nur `DNS:ns1`/`DNS:ns2`).

**Anwendung** (`/etc/dnsmanager/ui_config.php`, root:www-data 0640):
- `CURL_SSL_VERIFYPEER = false`, `CURL_SSL_VERIFYHOST = 0`;
- kein `AGENT_TLS_CA_FILE`;
- Mailer `MAILER_ENABLED = false` mit Platzhalterwerten.

**Agent-Aufrufe** laufen als `www-data` (Apache/PHP sowie Cron `monitoring_run-cli.php` alle 5 Minuten). `diagnostics.checked_at` wird in UTC geschrieben.

**Ohne Token** antworten beide Agenten mit HTTP 401 „Fehlender Authorization-Header“.

**Umgebung:**
- kein MTA auf ns1/ns2;
- kein `open_basedir`;
- NTP synchron;
- PHP-Erweiterungen `curl` und `openssl` vorhanden.

## 3. Verhalten der Code-Option

| Konfiguration | Verhalten |
|---|---|
| `AGENT_TLS_CA_FILE` nicht gesetzt | wie bisher |
| `CURL_SSL_VERIFYPEER = false` | wie bisher: keine Prüfung, die CA-Datei wird nicht verwendet |
| `VERIFYPEER = true`, `VERIFYHOST = 2`, gültige CA-Datei | ausschließlich die CA-Datei ist vertrauenswürdig (`CURLOPT_CAINFO`, `CURLOPT_CAPATH = null`). Prüfung von Kette, Laufzeit, Verwendungszweck, IP-/DNS-SAN und `nameConstraints` durch OpenSSL. |
| Prüfung an, CA-Konfiguration ungültig | **kein Verbindungsaufbau** (Fail-Closed, Abschnitt 4) |
| CA-Datei mit mehreren CAs | alle enthaltenen CAs sind vertrauenswürdig (CA-Wechsel) |

## 4. Fehlerklassen: Konfigurationsfehler vs. Zertifikatsfehler

Alle Fehler erscheinen als `curlError`. Die Aufrufer zeigen sie wie jeden Verbindungsfehler an:
- Monitoring: Diagnose `error`, Meldung „Verbindungsfehler: …“;
- BIND-Reload: Fehlermeldung;
- Publish: Fehler je Server.

Der Token ist nie Teil der Meldung.

**A – Konfigurationsfehler vor dem Verbindungsaufbau.** Es geht keine Anfrage an den Agenten, und der HTTP-Code ist 0.

| Meldung (Beginn) | Ursache | Behebung |
|---|---|---|
| `Agent-TLS-Konfiguration ungültig: CURL_SSL_VERIFYHOST muss bei AGENT_TLS_CA_FILE 2 sein` | `VERIFYHOST` ist 0 oder 1 | `define('CURL_SSL_VERIFYHOST', 2);` |
| `… CA-Datei <Pfad> nicht vorhanden oder nicht lesbar` | Pfad falsch, Datei fehlt, ist ein Verzeichnis, oder Rechte erlauben `www-data` kein Lesen | Datei/Rechte prüfen (root:www-data 0640) |
| `… CA-Datei <Pfad> enthält kein PEM-Zertifikat` | leer, DER statt PEM, falsche Datei | PEM-CA-Zertifikat ablegen |
| `error adding trust anchors from file: <Pfad>` (libcurl) | PEM-Rahmen vorhanden, Inhalt beschädigt; oder die Datei ist nach der Prüfung verschwunden | Datei neu ablegen |

**B – Zertifikatsfehler während der TLS-Verifikation.** Die Verbindung wird aufgebaut, OpenSSL lehnt das Serverzertifikat ab, und es geht keine HTTP-Anfrage an den Agenten.

| Meldung (Beispiel) | Bedeutung / typische Ursache |
|---|---|
| `SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)` | Aussteller nicht in der CA-Datei, z. B. noch snakeoil, falscher VirtualHost oder falsche CA-Datei |
| `… self-signed certificate (18)` | Server liefert ein selbstsigniertes Zertifikat (snakeoil, falscher VirtualHost) |
| `… certificate has expired (10)` / `… is not yet valid (9)` | Laufzeit: Erneuerung versäumt bzw. Uhrzeit falsch |
| `SSL: no alternative certificate subject name matches target ipv4 address '…'` | SAN passt nicht zur Agent-Adresse (`servers.api_ip` geändert, SAN falsch ausgestellt) |
| `… permitted subtree violation (47)` | ein SAN liegt außerhalb der `nameConstraints` der CA. Passt zugleich der SAN nicht zur Zieladresse, meldet libcurl gegebenenfalls zuerst den SAN-Fehler. |
| `… unsupported certificate purpose (26)` | `extendedKeyUsage=serverAuth` fehlt |

**C – Fehler nach bestandener TLS-Prüfung:** HTTP 401/403 (Token, IP-Freigabeliste), 5xx. Sie belegen, dass die TLS-Prüfung bestanden wurde.

## 5. Namensbeschränkungen der CA (E7)

**Festgelegte Liste** (kritisch; nur `permitted`):
```
nameConstraints = critical,permitted;IP:127.0.0.1/255.255.255.255,permitted;IP:192.168.53.125/255.255.255.255,permitted;DNS:ns1,permitted;DNS:ns2,permitted;DNS:ns1.example.test,permitted;DNS:ns2.example.test
```
Gegenüber dem ersten Vorschlag ist `DNS:.example.test` ersetzt durch die beiden konkreten Namen `ns1.example.test` und `ns2.example.test`.
- `192.168.53.122` ist **nicht** enthalten (E5: Die Oberfläche bekommt kein Zertifikat dieser CA).
- `127.0.0.1` ist nötig, weil ns1 seinen Agenten darüber anspricht.

**Semantik und Grenzen** (RFC 5280, OpenSSL 3.5, im Trockenlauf belegt):
- **IP:** Jede IP-Adresse im SAN muss in einem der zugelassenen Bereiche liegen. Mit `/255.255.255.255` sind das genau die zwei Adressen.
- **DNS – keine exakte Bindung möglich:** Ein `dNSName`-Constraint lässt den Namen selbst **und alle Namen darunter** zu, d. h. Namen mit zusätzlichen Labels links.
  - `DNS:ns1` erlaubt auch `x.ns1`.
  - `DNS:ns1.example.test` erlaubt auch `x.ns1.example.test`.

  Eine Beschränkung auf genau einen Namen ist technisch nicht möglich. Das ist die engste mögliche Form.
- **Label-Grenze:** Abgelehnt werden `evilns1`, `example.test`, `zone.example.test` und `dnsmanager.example.test`.
- **Weitere Typen:** Namensarten ohne eigenen Eintrag (E-Mail, URI, Verzeichnisname) beschränkt die Liste nicht.
  - Für die Agent-Verbindung ist das ohne Bedeutung: libcurl prüft nur IP- bzw. DNS-SANs gegen die Zieladresse.
  - DNS-Namen sind nur deshalb beschränkt, weil die Liste DNS-Einträge enthält. Ohne jeden DNS-Eintrag wären beliebige DNS-SANs zugelassen.
- **Common Name:** OpenSSL wendet DNS-Constraints auf den Common Name nur an, wenn das Zertifikat keine DNS-SANs hat. Die Agent-Zertifikate haben DNS-SANs, und ihr CN (`ns1`/`ns2`) liegt ohnehin innerhalb.
- **Wirkung:** Ein missbräuchlich ausgestelltes Zertifikat dieser CA wird für jede andere Adresse und jeden anderen Namen abgelehnt, z. B. für die Oberfläche `192.168.53.122`. Für die Agent-Adressen selbst schützen nur die Verwahrung des Schlüssels (E8) und die Kontrolle der Ausstellung.
- **Durchsetzung:** Die Constraints wirken in jeder Prüfung, die die CA als Vertrauensanker nutzt: PHP-cURL der Anwendung, `openssl verify`, `s_client`. Ein Zertifikat mit einem einzigen nicht erlaubten SAN wird vollständig abgelehnt, auch wenn ein anderer SAN passt (Schritt D6).

## 6. Schrittfolge Phase 2 (nach Freigabe der Durchführung)

**Vorbedingungen:**
- E8-Festlegungen getroffen;
- Alarmweg E6 entschieden und vorhanden (Abschnitt 9);
- Snapshot-/Backup-Stand beider VMs geklärt.

Platzhalter:
- `<CA>` = Ausstellungsrechner;
- `<NS>` = ns1 bzw. ns2;
- `<AGENT_IP>` = `127.0.0.1` (ns1) bzw. `192.168.53.125` (ns2).

**Grundregel:** Jedes Prüfskript endet mit Exit-Code 0 („BESTANDEN“) oder 1 („ABBRUCH“). Ein Exit-Code ≠ 0 bedeutet Abbruch des weiteren Ablaufs; die Ursache steht in Abschnitt 4. Bewertet wird nie ein Textvorkommen, sondern Exit-Codes und exakte Werte (Fingerprint, HTTP-Code, Rechte).

**A – CA erstellen** (auf `<CA>`, einmalig):
```bash
cat > ca.cnf <<'EOF'
[req]
distinguished_name = dn
x509_extensions = v3_ca
prompt = no
[dn]
O = Twilight-Networks
CN = DNS-Manager Agent CA 2026
[v3_ca]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
nameConstraints = critical,permitted;IP:127.0.0.1/255.255.255.255,permitted;IP:192.168.53.125/255.255.255.255,permitted;DNS:ns1,permitted;DNS:ns2,permitted;DNS:ns1.example.test,permitted;DNS:ns2.example.test
EOF
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -aes-256-cbc -out dnsmanager-agent-ca.key   # fragt die Passphrase ab
openssl req -x509 -new -config ca.cnf -key dnsmanager-agent-ca.key -sha256 -days 1826 -out dnsmanager-agent-ca.pem
openssl x509 -in dnsmanager-agent-ca.pem -noout -ext basicConstraints,keyUsage,nameConstraints -enddate -fingerprint -sha256
```
Danach:
- Schlüssel auf beide Offline-Datenträger sichern (E8).
- Wiederherstellung einmal prüfen.
- Fingerprint und Ablaufdatum dokumentieren.

**B – Schlüssel und CSR je Server** (auf `<NS>`, als root; nur der CSR verlässt den Host):
```bash
install -d -m 750 -o root -g root /etc/ssl/dnsmanager
( umask 077; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /etc/ssl/dnsmanager/agent.key )
openssl req -new -key /etc/ssl/dnsmanager/agent.key -subj "/O=Twilight-Networks/CN=$(hostname)" -out /root/$(hostname)-agent.csr
```

**C – Ausstellen** (auf `<CA>`).

| Host | SAN |
|---|---|
| ns1 | `IP:127.0.0.1,DNS:ns1,DNS:ns1.example.test` |
| ns2 | `IP:192.168.53.125,DNS:ns2,DNS:ns2.example.test` |

```bash
H=ns1; IP=127.0.0.1; SAN="IP:$IP,DNS:ns1,DNS:ns1.example.test"     # ns2: H=ns2; IP=192.168.53.125; SAN="IP:$IP,DNS:ns2,DNS:ns2.example.test"
printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=%s\nauthorityKeyIdentifier=keyid\n' "$SAN" > $H.ext
openssl x509 -req -in $H-agent.csr -CA dnsmanager-agent-ca.pem -CAkey dnsmanager-agent-ca.key \
  -CAserial dnsmanager-agent-ca.srl -CAcreateserial -sha256 -days 365 -extfile $H.ext -out $H-agent.pem
openssl verify -CAfile dnsmanager-agent-ca.pem -no-CApath -no-CAstore -purpose sslserver -verify_ip $IP $H-agent.pem || echo ABBRUCH
openssl x509 -in $H-agent.pem -noout -fingerprint -sha256     # Fingerprint für D4/D5 notieren
```

**Testzertifikate für den Negativtest D6** (Laufzeit 1 Tag). Die Schlüssel und CSRs werden auf ns1 erzeugt, z. B. in `/root/s42-nc/` (0700):
- `nc-dns`: SAN `IP:127.0.0.1,DNS:ns1,DNS:nicht-erlaubt.invalid`
- `nc-ip`: SAN `IP:127.0.0.1,IP:192.168.53.122`

Ausstellung auf `<CA>` wie oben mit `-days 1`. Nach D6 werden Schlüssel und Zertifikate gelöscht.

Danach entschlüsselte Kopien des CA-Schlüssels vom `<CA>` entfernen.

**D – Vorprüfungen.** Bei Exit-Code ≠ 0 abbrechen.

0. **Ausgangswert der Oberfläche festhalten** (ns1, vor jeder Apache-Änderung):
   ```bash
   echo | openssl s_client -connect 192.168.53.122:443 2>/dev/null | openssl x509 -noout -fingerprint -sha256 | cut -d= -f2 > /root/<x>-update/ui-fp-vorher.txt
   ```
1. **Dateien ablegen:**
   - ns1: `install -m 640 -o root -g www-data dnsmanager-agent-ca.pem /etc/dnsmanager/agent-ca.pem`
   - je Host: `install -m 644 -o root -g root <NS>-agent.pem /etc/ssl/dnsmanager/agent.crt`
   - Schlüsselabgleich je Host:
     ```bash
     [ "$(openssl x509 -in /etc/ssl/dnsmanager/agent.crt -noout -pubkey | sha256sum)" = "$(openssl pkey -in /etc/ssl/dnsmanager/agent.key -pubout | sha256sum)" ] || echo ABBRUCH
     ```
   - Die Agent-CA **nicht** in `/usr/local/share/ca-certificates` ablegen.
2. **Agent-VirtualHost anlegen** (je Host). Neue Site `/etc/apache2/sites-available/dnsmanager-agent-tls.conf`; `default-ssl.conf` bleibt unverändert:
   ```apache
   <VirtualHost <AGENT_IP>:443>
       ServerName <NS>
       DocumentRoot /var/www/html
       ErrorLog ${APACHE_LOG_DIR}/error.log
       CustomLog ${APACHE_LOG_DIR}/access.log combined
       SSLEngine on
       SSLCertificateFile    /etc/ssl/dnsmanager/agent.crt
       SSLCertificateKeyFile /etc/ssl/dnsmanager/agent.key
   </VirtualHost>
   ```
3. **Site aktivieren:**
   ```bash
   a2ensite dnsmanager-agent-tls && apache2ctl configtest && systemctl reload apache2
   ```
   - Reload außerhalb der Minuten :x0/:x5 und ohne laufenden Publish.
   - Solange `VERIFYPEER = false` ist, hat das keine Wirkung auf die Anwendung.
4. **VirtualHost-Auswahl und Auslieferung (E5)**, je Host mit `docs/agent-tls/s42-vhost-check.sh`:
   ```bash
   bash s42-vhost-check.sh 127.0.0.1 192.168.53.122 "$(cat /root/<x>-update/ui-fp-vorher.txt)"   # ns1
   bash s42-vhost-check.sh 192.168.53.125                                                        # ns2
   ```
   Das Skript prüft per Exit-Code:
   - `apache2ctl configtest`;
   - in `apache2ctl -S` ist `<AGENT_IP>:443` der Site `dnsmanager-agent-tls.conf` zugeordnet;
   - das ausgelieferte Zertifikat ist identisch mit `agent.crt` (Fingerprint), ohne SNI und mit verschiedenen SNI-Namen;
   - auf ns1 ist das Zertifikat von `192.168.53.122:443` unverändert (Fingerprint wie D0).
5. **Vorprüfung auf ns1** mit `docs/agent-tls/s42-precheck.sh`:
   ```bash
   AGENTS="127.0.0.1:443|127.0.0.1|<FP ns1 aus C> 192.168.53.125:443|192.168.53.125|<FP ns2 aus C>" \
   UI_FP="$(cat /root/<x>-update/ui-fp-vorher.txt)" bash s42-precheck.sh
   ```
   Das Skript prüft per Exit-Code bzw. exakten Werten:
   - NTP;
   - CA-Datei: Rechte 640 root:www-data, für `www-data` lesbar, jedes Zertifikat `CA:TRUE, pathlen:0`, selbstsigniert gültig, Restlaufzeit ≥ 30 Tage;
   - je Agent:
     - ausgeliefertes Zertifikat = erwarteter Fingerprint;
     - `openssl verify` (Kette, `sslserver`, IP-SAN);
     - Restlaufzeit;
     - Handshake mit `s_client -verify_return_error` (Exit-Code);
     - PHP/libcurl als `www-data` mit der Agent-CA ohne Token: leerer `curlError` **und** HTTP 401;
   - Oberfläche: Fingerprint unverändert und HTTP 200 der Login-Seite über `https://192.168.53.122/`.
6. **Negativtest `nameConstraints` (E7)** auf ns1 mit `docs/agent-tls/s42-nc-negative.sh`:
   ```bash
   bash s42-nc-negative.sh /root/s42-nc
   ```
   Ein temporärer `openssl s_server` auf `127.0.0.1:18443` liefert nacheinander drei Zertifikate aus:
   - das echte Agent-Zertifikat von ns1 → muss angenommen werden;
   - `nc-dns` → muss abgelehnt werden (leerer HTTP-Code, `curlError` gesetzt; erwartet Fehler 47);
   - `nc-ip` → muss abgelehnt werden.

   Die Bewertung erfolgt über den Exit-Code des PHP-Tests. Danach `/root/s42-nc` löschen.
7. **Alarmweg nachweisen (E6)** gemäß Abschnitt 9. Ohne nachgewiesenen Alarm keine Aktivierung.

**E – Aktivierung** (ns1, nur wenn D0–D7 bestanden):
```bash
cp -p /etc/dnsmanager/ui_config.php /root/<x>-update/ui_config.php.vor-s42
```
In `ui_config.php` setzen:
```php
define('CURL_SSL_VERIFYPEER', true);
define('CURL_SSL_VERIFYHOST', 2);
define('AGENT_TLS_CA_FILE', '/etc/dnsmanager/agent-ca.pem');
```
Danach:
- `php -l /etc/dnsmanager/ui_config.php` (Exit-Code 0);
- Rechte unverändert 640 root:www-data;
- `s42-precheck.sh` erneut (Exit-Code 0).

**F – Abnahme lesend (E4).**
1. Server-Status bzw. Systemstatus in der Oberfläche für ns1 und ns2.
2. Den nächsten **regulären** Monitoring-Cron abwarten, keinen manuellen Lauf (F7).
3. Danach gilt:
   - alle `diagnostics`-Einträge mit `checked_at` (UTC) nach der Aktivierung sind `ok`;
   - keine neuen `diagnostic_log`-Wechsel auf `error`;
   - Agent-Zugriffslogs ns1/ns2 mit HTTP 200;
   - keine TLS-Meldungen im PHP-/Apache-Fehlerprotokoll.
4. Bei einem Fehler: Rückweg Stufe 1 (Abschnitt 7).

**G – Abnahme schreibend** (nur im Rahmen der gesondert freigegebenen Abnahme).

1. BIND-Reload je Server über die Oberfläche.
2. Publish auf der freigegebenen Testzone, danach Serial und Antworten auf ns1/ns2 prüfen (`dig @192.168.53.122` / `@192.168.53.125`).

**Vorschlag Testzone: `zone.example.test`** (id 3; Freigabe offen; rein lesend ermittelt am 13.11.2026).
- **Inhalt:** 2 NS-Einträge (ns1/ns2.example.test) und 1 A-Eintrag `test30-zoneadmin` → `192.0.2.33` (TEST-NET-1, Dokumentationsbereich). Als Testzone angelegt.
- **Abhängigkeiten:** keine DynDNS-Konten, `allow_dyndns = 0`, keine Delegation aus `example.test`, keine Abhängigkeit des Betriebs.
- **Zustand:** `changed = 0`, Serial `2026110902` auf ns1 und ns2 identisch.
- **Zuordnung:** Die Zone ist dem Konto `zoneadmin` (id 5) zugeordnet. Das Konto selbst wird nicht verändert.

**Nicht geeignet:**
- `example.test`: Glue-/Hosteinträge von ns1/ns2 sowie DynDNS-Konten `dyndnstest`/`n1race`.
- `53.168.192.in-addr.arpa`: PTR-Einträge der VMs.
- Die IPv6-Reverse-Zone `…8.b.d.0.1.0.0.2.ip6.arpa` wäre ebenfalls unkritisch (Dokumentationspräfix), ist aber nur zweite Wahl, da sie Testeinträge von `example.test` referenziert.

**Wichtig zur Publish-Semantik** (`PublishService`):
- „Publish All“ verteilt nur Zonen mit ausstehender Änderung (`changed ≠ 0`) bzw. bei `bind_dirty` alle Zonen.
- Ein echter Verteiltest von `zone.example.test` braucht daher eine **ausstehende Änderung in dieser Zone**.
- Vorschlag: einen TXT-Eintrag `s42-tls-test` mit einem Testwert anlegen, publizieren, prüfen, den Eintrag wieder entfernen und erneut publizieren.
- Diese Inhaltsänderung bedarf der Freigabe zusammen mit der Testzone.
- **Unmittelbar vor dem Publish** prüfen, dass `bind_dirty = 0` ist und keine andere Zone `changed ≠ 0` hat. Sonst würden weitere Zonen mitverteilt.
- Ein Publish All führt nach Erfolg zusätzlich das Orphan-Cleanup auf den Agenten aus.

## 7. Rückweg

Je nach Lage wird nur die passende Stufe ausgeführt:

1. **Anwendung** (sofort, ohne Neustart): `ui_config.php.vor-s42` zurückspielen, d. h. wieder `VERIFYPEER = false`, `VERIFYHOST = 0`, ohne `AGENT_TLS_CA_FILE`. Danach `php -l`.
   - Agent-Aufrufe laufen wie vor der Umstellung.
   - CA-Datei und Agent-VirtualHost können bestehen bleiben.
2. **Apache:**
   ```bash
   a2dissite dnsmanager-agent-tls && apache2ctl configtest && systemctl reload apache2
   ```
   Danach liefert wieder `default-ssl.conf` (snakeoil). `default-ssl.conf` wird in Phase 2 nicht verändert.
3. **Code:** kein Rücksetzen nötig. Ohne `AGENT_TLS_CA_FILE` verhält sich der Code wie vor PR #68.

**Bei einer Störung nach Schritt E:** zuerst Stufe 1, damit Publish und Monitoring sofort wieder funktionieren. Dann die Ursache anhand von Abschnitt 4 klären. Stufe 2 nur, wenn das Zertifikat selbst ersetzt werden muss.

Bei falscher Konfiguration entsteht kein stiller unsicherer Zustand. Fehler sind sofort in Monitoring, Publish und Reload sichtbar.

## 8. Browser, Arbeitsplätze und DynDNS-Clients (E5)

**Ergebnis der getrennten Variante:**
- Nur ns1 (die Anwendung) vertraut der Agent-CA.
- Das Agent-Zertifikat wird ausschließlich auf den Agent-Adressen ausgeliefert.
- Browser und DynDNS-Clients auf `https://192.168.53.122/` sehen unverändert das bisherige Zertifikat. Phase 2 hat keinen Browser-Effekt; das wird in D4/D5 per Fingerprint nachgewiesen.

**Konsequenzen:**
- Die Agent-CA wird auf keinem Arbeitsplatz importiert. Ein Missbrauch ihres Schlüssels betrifft nur Agent-Verbindungen, und durch E7 nur die zugelassenen Ziele.
- Hinweis: Wer `https://127.0.0.1/` lokal auf ns1 aufruft, z. B. Prüfskripte oder lokale Aufrufe der Oberfläche, erhält künftig das Agent-Zertifikat. Für Benutzerzugriffe ist das ohne Bedeutung.
- Das Zertifikat der Oberfläche wird unabhängig in S40 Teil C gelöst. HSTS erst danach.

## 9. Erneuerung, CA-Wechsel und Ablaufalarm (E6)

**Serverzertifikate** (365 Tage): spätestens 30 Tage vor Ablauf erneuern.
- Schritte B (neuer Schlüssel empfohlen), C, D1, D3–D5, dann Apache reload. An der Anwendung ist nichts zu ändern.
- Eine Änderung von `servers.api_ip` erfordert vorher ein neues Zertifikat mit passendem SAN und gegebenenfalls eine neue CA (`nameConstraints`).

**CA-Wechsel** (spätestens im 5. Jahr; isoliert getestet, Fall C07):
1. neue CA (A);
2. `agent-ca.pem` = alte + neue CA (D1, D5);
3. Serverzertifikate aus der neuen CA;
4. alte CA aus `agent-ca.pem` entfernen (D5).

**Alarmweg – Ausgangslage:**
- Auf ns1/ns2 gibt es kein MTA.
- Der Anwendungs-Mailer ist deaktiviert (Platzhalterwerte).
- Ein externer Alarmweg (Mail, Monitoring-System) existiert derzeit nicht und wird ohne Rücksprache nicht eingerichtet.
- Ein wirksamer und nachprüfbarer Alarm muss daher einen **vorhandenen, beobachteten Kanal** nutzen: die Diagnose der Anwendung. Sie ist für alle Administratoren in der Oberfläche sichtbar, protokolliert Statuswechsel in `diagnostic_log` und sendet bei später aktiviertem Mailer automatisch E-Mails.

**Optionen (entschieden: E6-A):**

| Option | Beschreibung | Bewertung |
|---|---|---|
| **E6-A (entschieden, umgesetzt)** | **Ablaufprüfung im regulären Monitoring-Lauf.** Bei jeder Serverprüfung (`server_status`, alle 5 Minuten) liest die Anwendung die Restlaufzeit des ausgelieferten Agent-Zertifikats sowie jedes Zertifikats in `AGENT_TLS_CA_FILE`. Unter einer Schwelle (Standard 30 Tage, konfigurierbar) setzt sie den Status `warning` mit Meldung, z. B. „Agent-Zertifikat läuft am … ab (noch N Tage)“. | Nutzt den vorhandenen Kanal:<br>– Problemliste der Systemstatus-Seite;<br>– `diagnostic_log`;<br>– Mail, sobald `MAILER_ENABLED` freigegeben ist.<br>Kein Schemawechsel (`server_status`/`warning` vorhanden). Der Cron prüft sich mit, weil ausbleibende Läufe ohnehin sichtbar werden.<br>Umgesetzt im eigenen Code-PR, Details unten. |
| E6-B | **Tägliches Prüfskript ohne Codeänderung:** `logger` mit Priorität `warning`, Exit-Code ≠ 0, Statusdatei und Hinweis in der Anmeldemeldung (`/etc/update-motd.d`). | Ohne Code umsetzbar, aber nur passiv: Der Alarm wird erst bei einer SSH-Anmeldung gesehen. Erfüllt E6 („nicht nur eine Logzeile“) nur eingeschränkt. Nur als zusätzliche Absicherung zu E6-A sinnvoll. |
| E6-C | Externer Kanal (SMTP-Relay für den vorhandenen Mailer oder ein vorhandenes Unternehmens-Monitoring) | Nur nach Rücksprache. Kann mit E6-A kombiniert werden: Der Mailer meldet dann dieselben Diagnosewechsel. |

**Umsetzung E6-A** (`AgentCertificateExpiryCheck`, aufgerufen aus `AgentMonitoringBackend::serverStatus()`):
- **Aktivierung:**
  - `AGENT_TLS_EXPIRY_WARN_DAYS` > 0, z. B. 400 für den Alarmtest;
  - ohne diesen Eintrag 30 Tage, sobald `AGENT_TLS_CA_FILE` gesetzt ist;
  - `0` schaltet die Prüfung aus;
  - ohne beide Einträge: keine Prüfung, Verhalten unverändert.
- **Geprüft werden:**
  - das vom Agenten ausgelieferte Zertifikat, über eine eigene kurze TLS-Verbindung zur Agent-Adresse (ohne SNI, Zeitgrenze 5 s, nur lesen, kein Vertrauen);
  - jedes Zertifikat in `AGENT_TLS_CA_FILE`.
- **Wirkung auf den Status:**
  - `ok` → `warning`; ein vorhandenes `warning` bleibt `warning`, die Meldung wird ergänzt („… – Zertifikatswarnung: Agent-Zertifikat läuft am JJJJ-MM-TT (UTC) ab (noch N Tage)“).
  - **`error` und jeder andere Status bleiben unverändert**, und die Prüfung wird dann nicht ausgeführt.
  - Ist die Restlaufzeit nicht ermittelbar, entsteht eine Warnung.
- **Unverändert:** MonitoringService, Statusprotokollierung (`diagnostic_log`), Benachrichtigungslogik und Datenbankschema.
- **Anzeige:** In der Systemstatus-Seite erscheint die Zeile „Serverstatus“ bei reinen Warnungen als „⚠️ Warnungen“ mit „N Warnung(en) erkannt“ und gelb hinterlegtem Eintrag. Fehler werden unverändert angezeigt.
- **Dashboard:** Es fasst den Serverstatus weder bei Fehlern noch bei Warnungen zusammen und bleibt unverändert (Befund F8).

**Nachweis wirksamer Alarmierung (Voraussetzung für Schritt E):**
1. **Isoliert:** erledigt mit t71 (Code-PR). Wegwerf-CAs, kurze Laufzeiten, MonitoringService von Anfang bis Ende: ok → warning → ok mit `diagnostic_log`-Einträgen und Benachrichtigungen; Fehlerstatus nie verändert.
2. **Auf ns1 nach D4/D5, vor E:** vorübergehend `define('AGENT_TLS_EXPIRY_WARN_DAYS', 400);` setzen, d. h. eine Schwelle über der Laufzeit. Den nächsten regulären Cron-Lauf abwarten. Nachzuweisen ist:
   - `server_status` von ns1 und ns2 steht auf `warning`;
   - es gibt einen `diagnostic_log`-Eintrag ok → warning;
   - der Eintrag ist in der Systemstatus-Seite sichtbar.

   Danach den Eintrag wieder entfernen und den Wechsel zurück auf `ok` im nächsten Lauf nachweisen. Die Prüfung funktioniert auch bei noch abgeschalteter TLS-Verifikation, weil das ausgelieferte Zertifikat gelesen wird.
3. Bei späterer Mail-Freigabe (E6-C): denselben Test mit Empfang der E-Mail wiederholen.

**Ergänzendes Prüfskript (E6-B)**, falls gewünscht, als `/etc/cron.daily/dnsmanager-agent-tls-check` (root 0755). Es braucht keine Geheimnisse, beeinflusst keine Locks und wurde mit einer Wegwerf-CA geprüft.
```bash
#!/bin/bash
LIMIT=$((30*86400)); CA=/etc/dnsmanager/agent-ca.pem; rc=0
warn() { logger -p auth.warning -t dnsmanager-tls "$1"; echo "$1"; rc=1; }
for t in 127.0.0.1 192.168.53.125; do
  c=$(echo | timeout 10 openssl s_client -connect $t:443 2>/dev/null | openssl x509 2>/dev/null)
  [ -z "$c" ] && { warn "Agent $t: kein Zertifikat abrufbar"; continue; }
  echo "$c" | openssl x509 -noout -checkend $LIMIT >/dev/null || warn "Agent $t: Zertifikat läuft in weniger als 30 Tagen ab ($(echo "$c" | openssl x509 -noout -enddate))"
done
d=$(mktemp -d)
awk -v d="$d" '/BEGIN CERTIFICATE/{n++} n{print > (d "/ca" n ".pem")}' "$CA"
ls "$d"/ca*.pem >/dev/null 2>&1 || warn "Agent-CA-Datei $CA enthält kein Zertifikat"
for f in "$d"/ca*.pem; do
  [ -e "$f" ] || continue
  openssl x509 -in "$f" -noout -checkend $LIMIT >/dev/null || warn "Agent-CA läuft in weniger als 30 Tagen ab: $(openssl x509 -in "$f" -noout -subject -enddate | tr '\n' ' ')"
done
rm -rf "$d"
exit $rc
```

## 10. Umsetzungsrisiken

| Nr. | Risiko | Gegenmaßnahme |
|---|---|---|
| R1 | Zertifikat läuft ab → wegen Fail-Closed Ausfall von Publish, Reload und Monitoring | E6: nachgewiesener Alarm vor Aktivierung; Erneuerung ≥ 30 Tage vorher |
| R2 | Verlust des CA-Schlüssels | E8: zwei Offline-Sicherungen, Wiederherstellung geprüft; sonst neue CA mit Übergangsbündel |
| R3 | Kompromittierung des CA-Schlüssels | E8; E7 begrenzt die Wirkung auf die Agent-Ziele; E5: kein Arbeitsplatz-Import |
| R4 | Browser sehen auf ns1 ein anderes Zertifikat | E5; Nachweis per Fingerprint in D4/D5 |
| R5 | Falscher VirtualHost greift | D4: `apache2ctl -S` und Fingerprint je Adresse und SNI |
| R6 | CA-Datei für `www-data` nicht lesbar oder beschädigt | D5 als `www-data`; Fehlerklasse A ist eindeutig sichtbar |
| R7 | `servers.api_ip` wird geändert → SAN passt nicht, gegebenenfalls `nameConstraints` | Abschnitt 9: vorher neues Zertifikat bzw. neue CA |
| R8 | Uhrzeitabweichung | D5 prüft NTP |
| R9 | Apache-Reload während Publish/Cron | Reload außerhalb :x0/:x5, kein laufender Publish, `configtest` vorher |
| R10 | Fehler in `ui_config.php` | Sicherung, `php -l`, Rechte unverändert, Vorprüfung erneut |
| R11 | Server-Schlüssel gelangt nach außen | Erzeugung auf dem Host, 0600 root, nur der CSR verlässt den Host |
| R12 | Agent-CA landet im Systemspeicher | nicht in `/usr/local/share/ca-certificates` ablegen |
| R13 | Prüfungen werten nur Text aus | alle Skripte bewerten Exit-Codes und exakte Werte; Abbruch bei Exit-Code ≠ 0 |
| R14 | Alarm wird nicht wahrgenommen | E6-A über die beobachtete Diagnose; Nachweis vor Aktivierung; Mail nach Rücksprache |
| R15 | Publish-Test verteilt unbeabsichtigt weitere Zonen | unmittelbar vorher `bind_dirty = 0` und keine weitere Zone mit `changed ≠ 0` prüfen |

## 11. Trockenläufe (ns1, 13.11.2026)

Beide Läufe nutzten eine Wegwerf-CA mit 3 Tagen Laufzeit in eigenen `/tmp`-Unterverzeichnissen, die danach gelöscht wurden. Es gab keine Änderung an Apache, `/etc`, `ui_config.php` oder den Agenten; `/tmp` war vorher und nachher 1777.

**Trockenlauf 1:**
- A–C einschließlich `openssl verify -verify_ip`;
- D1-Rechte und Lesbarkeit für `www-data`;
- Schlüsselabgleich mit Gegenprobe;
- `s_client`;
- PHP-Stack mit installiertem Code `b17d946`;
- echte Agenten ohne Token → HTTP 401;
- Überwachungsskript warnt bei kurzer Laufzeit, nicht erreichbarem Ziel und je CA im Bündel.

**Trockenlauf 2:**
- **CA mit der endgültigen Liste aus Abschnitt 5:**
  - DNS-Semantik: `x.ns1` und `x.ns1.example.test` werden angenommen (Grenze dokumentiert);
  - `evilns1`, `example.test`, `zone.example.test` und `dnsmanager.example.test` werden abgelehnt.
- **`s42-precheck.sh`:** korrekt → Exit 0. Jede der folgenden Varianten → „ABBRUCH“, Exit 1:
  - fremdes Zertifikat am Ziel;
  - Zertifikat wie erwartet, aber nicht von der Agent-CA;
  - CA-Datei 0644;
  - Restlaufzeit < 30 Tage;
  - Oberflächen-Zertifikat geändert.
- **`s42-nc-negative.sh`:**
  - Positivkontrolle angenommen;
  - zusätzlicher DNS-SAN bzw. IP-SAN außerhalb → abgelehnt mit „permitted subtree violation (47)“;
  - Exit 0.
  - **Gegenprobe** mit einer CA ohne `nameConstraints`: beide Testzertifikate werden angenommen → „NICHT BESTANDEN“, Exit 1. Der Test erkennt fehlende Beschränkungen also zuverlässig.

**`s42-vhost-check.sh` gegen die heutige Apache-Konfiguration** (nur lesend):
- heute ohne Agent-Site → „ABBRUCH“ bei der `apache2ctl -S`-Zuordnung, Exit 1 (erwartet);
- Logik mit vorübergehend umgelenkten Pfaden: positiv → Exit 0, falscher Fingerprint → Exit 1.

**Nicht trockengelaufen** (Apache-/Konfigurationsänderung): D2–D4 mit echter Agent-Site, E, F, G.

## 12. Nachweis Phase 1 (isolierter Test t70, ns1, 13.11.2026)

**Umgebung:**
- PHP 8.5.4, libcurl 8.18.0, OpenSSL 3.5.5, Ubuntu;
- Code aus eigenem Klon (Basis `ce3b245`, PR `5896a4a`);
- Wegwerf-Test-CAs;
- lokale TLS-Testserver, Aufrufe als `www-data`;
- keine Agenten, kein DNS, keine Datenbank, keine Locks.

**Ergebnisse** (34/34 bestanden):

| Fall | Erwartung | Ergebnis |
|---|---|---|
| C01 / C01P | gültige CA, korrekter IP-SAN (GET/POST) → Erfolg | Erfolg, Token korrekt übertragen |
| C02 | falscher IP-SAN → Ablehnung | SAN-Fehler |
| C03 | abgelaufen → Ablehnung | (10) |
| C04 | fremde CA → Ablehnung | (20) |
| C05 | selbstsigniert → Ablehnung | (18) |
| C06 / C06K | snakeoil → Ablehnung; Kontrolle mit CA-Datei = snakeoil → Erfolg | wie erwartet |
| C07 | Übergangsbündel alt+neu → beide gültig; Kontrollen | wie erwartet |
| C08–C14 | ungültige CA-Konfiguration oder `VERIFYHOST ≠ 2` → Ablehnung | Ablehnung ohne Anfrage am Server |
| C15 | Prüfung aus → bisheriger Modus | wie bisher |
| D (18) / fromConfig | ohne CA-Einstellung: Basis und PR identisch | identisch |
| S01–S06 | Service-Ebene (Reload-, Monitoring-Backend) | wie erwartet |
| Token | kein Token in Fehlermeldungen oder Ausgaben | 0 Treffer |
| Mutationen | ohne `CAPATH = null` bzw. ohne Fail-Closed → Fehlannahmen | vom Test erkannt |

**Nach dem Merge** (13.11.2026):
- ns1 auf `b17d946`, Konfiguration unverändert.
- Monitoring 15:25/15:30: 12/12 `ok`.
- Agenten HTTP 200, keine PHP- oder Apache-Fehler.
