# Agent-TLS: interne CA, Zertifikate, Umstellung und Rückweg (S42)

Stand: 13.11.2026 · Befund S42 (siehe `docs/ARCHITECTURE_CORE.md`, Abschnitt 6).

**Status:**
- **Phase 1 – erledigt:** PR #68, `main` `b17d946`. Code-Option `AGENT_TLS_CA_FILE` in `BindAgentClient`, isoliert getestet (Abschnitt 11).
- **Phase 2 – vorbereitet, nicht freigegeben:** CA-Schlüsselverwahrung, Ausstellung, Umstellung ns1/ns2, Ablaufüberwachung.
- Bis zur Freigabe gilt:
  - keine produktive CA erzeugen;
  - keine Zertifikate austauschen;
  - die TLS-Prüfung bleibt auf ns1 abgeschaltet.
- S42 bleibt offen, bis Phase 2 produktiv abgenommen ist.

## 1. Entscheidungen

**Getroffen:**

| Nr. | Inhalt |
|---|---|
| S42-E1 | Variante A: interne CA mit IP-SANs. Die IP-Adressierung (`servers.api_ip`) und die Datenbankstruktur bleiben unverändert. |
| S42-E2 | Eigene CA nur für die Agenten:<br>– CA ca. 5 Jahre, Serverzertifikate je 1 Jahr;<br>– Erneuerung spätestens 30 Tage vor Ablauf;<br>– je Server ein eigener privater Schlüssel;<br>– SANs = tatsächlich genutzte Agent-IPs plus passende DNS-Namen.<br>Der CA-Schlüssel liegt nicht auf ns1/ns2. |
| S42-E3 | Phase 1: optionale, ausschließliche CA-Vertrauensstellung im Code (erledigt). |
| S42-E4 | Abnahme in dieser Reihenfolge:<br>1. lesende Agent-Aufrufe und der reguläre Monitoring-Cron;<br>2. Publish nur auf einer ausdrücklich freigegebenen Testzone.<br>Die Auswirkung auf den Browserzugriff ist zu beachten. |

**Für Phase 2 vorgeschlagen (Freigabe offen):**

| Nr. | Vorschlag | Begründung |
|---|---|---|
| S42-E5 | **Agent-CA und Arbeitsplatz-Vertrauen trennen:**<br>– Das Agent-Zertifikat wird nur über eigene, IP-gebundene VirtualHosts ausgeliefert: ns1 `127.0.0.1:443`, ns2 `192.168.53.125:443`.<br>– Die Oberfläche auf `192.168.53.122:443` behält vorerst ihr bisheriges Zertifikat. Ihr Zertifikat kommt später mit S40 Teil C.<br>– Die Agent-CA wird **nicht** auf Arbeitsplätzen importiert. | Abschnitt 8. Kein Browser-Effekt in Phase 2. Eine kompromittierte Agent-CA hätte keine Wirkung auf Arbeitsplätze. Möglich, weil Apache auf ns1 getrennt auf `127.0.0.1:443` und `192.168.53.122:443` lauscht und ns1 seinen eigenen Agenten über `127.0.0.1` aufruft. Rückweg: Site deaktivieren. |
| S42-E6 | **Ablaufüberwachung ist Voraussetzung für die Aktivierung**, nicht Nacharbeit. | Wegen Fail-Closed führt ein abgelaufenes Zertifikat zum Ausfall von Publish, Reload und Monitoring. |
| S42-E7 | **CA mit `nameConstraints`** auf genau die Agent-Ziele beschränken: `IP:127.0.0.1/32`, `IP:192.168.53.125/32`, `DNS:ns1`, `DNS:ns2`, `DNS:.example.test`. Schlüssel RSA 3072. | Ein missbrauchter CA-Schlüssel kann dann keine Zertifikate für andere Ziele ausstellen. Wird von OpenSSL clientseitig durchgesetzt. Ein Fehler wird in der Vorprüfung (Schritt D) sichtbar. |
| S42-E8 | **Verwahrung des CA-Schlüssels:**<br>– verschlüsselte Datei auf zwei getrennten Offline-Datenträgern;<br>– Passphrase im Passwortmanager (Zugriff Thomas plus Vertretung);<br>– Ausstellung auf einem bestimmten Admin-Rechner, Schlüssel nur dort temporär entschlüsselt, danach entfernt.<br>Konkreter Ort und Rechner: von Thomas festzulegen. | Vorgabe aus E2. Ohne zweite Sicherung wäre eine Erneuerung nur mit neuer CA möglich. |

## 2. Ist-Zustand (13.11.2026, nach Update auf `b17d946`)

**Agent-Adressen** (`servers.api_ip`):
- ns1 ruft seinen eigenen Agenten über `127.0.0.1` auf.
- ns2 wird von ns1 über `192.168.53.125` aufgerufen.
- URL: `https://{api_ip}/dnsmanager-agent/api/v1/…` (`REMOTE_API_BASE`).

**Apache lauscht:**
- ns1 auf `127.0.0.1:443` und `192.168.53.122:443`;
- ns2 auf `127.0.0.1:443` und `192.168.53.125:443`.

Beide Hosts haben je einen VirtualHost `default-ssl.conf` (`*:443`, `DocumentRoot /var/www/html`) mit dem snakeoil-Zertifikat:
- selbstsigniert;
- SAN nur `DNS:ns1` bzw. `DNS:ns2`.

Auf ns1 liefert dieser VirtualHost heute Oberfläche, DynDNS-API und Agent aus.

**Anwendung** (`/etc/dnsmanager/ui_config.php`, root:www-data 0640):
- `CURL_SSL_VERIFYPEER = false`, `CURL_SSL_VERIFYHOST = 0`;
- `AGENT_TLS_CA_FILE` ist nicht gesetzt.

**Wer ruft die Agenten auf** (ns1, jeweils als `www-data`):
- Apache/PHP: Publish, BIND-Reload, Diagnosen;
- Cron `monitoring_run-cli.php` alle 5 Minuten. `diagnostics.checked_at` wird in **UTC** geschrieben.

**Umgebung:** kein `open_basedir`, NTP auf ns1 und ns2 synchron.

**Warum eine eigene CA-Datei allein nicht reicht:** Das snakeoil-Zertifikat von ns1 liegt im System-Zertifikatsverzeichnis. libcurl würde ihm ohne `CURLOPT_CAPATH = null` vertrauen.

## 3. Verhalten der Code-Option

| Konfiguration | Verhalten |
|---|---|
| `AGENT_TLS_CA_FILE` nicht gesetzt | wie bisher |
| `CURL_SSL_VERIFYPEER = false` | wie bisher: keine Prüfung, die CA-Datei wird nicht verwendet |
| `VERIFYPEER = true`, `VERIFYHOST = 2`, gültige CA-Datei | Vertrauen **ausschließlich** in die CA-Datei:<br>– `CURLOPT_CAINFO` = CA-Datei, `CURLOPT_CAPATH = null`;<br>– Prüfung von Kette, Laufzeit, Verwendungszweck und IP-/DNS-SAN durch OpenSSL. |
| Prüfung an, CA-Konfiguration ungültig | **kein Verbindungsaufbau** (Fail-Closed, Abschnitt 4) |
| CA-Datei mit mehreren CAs | alle enthaltenen CAs sind vertrauenswürdig (CA-Wechsel) |

## 4. Fehlerklassen: Konfigurationsfehler vs. Zertifikatsfehler

Alle Fehler erscheinen als `curlError`. Die Aufrufer zeigen sie wie jeden Verbindungsfehler an:
- Monitoring: Diagnose `error`, Meldung „Verbindungsfehler: …“;
- BIND-Reload: Fehlermeldung;
- Publish: Fehler je Server.

Der Bearer-Token ist nie Teil der Meldung.

**A – Konfigurationsfehler vor dem Verbindungsaufbau.** Es geht keine Anfrage an den Agenten, und der HTTP-Code ist 0.

| Meldung (Beginn) | Ursache | Behebung |
|---|---|---|
| `Agent-TLS-Konfiguration ungültig: CURL_SSL_VERIFYHOST muss bei AGENT_TLS_CA_FILE 2 sein` | `VERIFYHOST` ist 0 oder 1 | `define('CURL_SSL_VERIFYHOST', 2);` |
| `… CA-Datei <Pfad> nicht vorhanden oder nicht lesbar` | Pfad falsch, Datei fehlt, ist ein Verzeichnis, oder Rechte erlauben `www-data` kein Lesen | Datei/Rechte prüfen (root:www-data 0640) |
| `… CA-Datei <Pfad> enthält kein PEM-Zertifikat` | Datei leer, DER statt PEM, falsche Datei (z. B. Schlüssel) | PEM-CA-Zertifikat ablegen |
| `error adding trust anchors from file: <Pfad>` (von libcurl) | PEM-Rahmen vorhanden, Inhalt aber beschädigt; oder die Datei ist nach der Prüfung verschwunden | Datei neu ablegen, `openssl x509 -in … -noout` prüfen |

**B – Zertifikatsfehler während der TLS-Verifikation.** Die Verbindung wird aufgebaut, OpenSSL lehnt das Serverzertifikat ab, und es geht keine HTTP-Anfrage an den Agenten.

| Meldung (Beispiel) | Bedeutung | Typische Ursache |
|---|---|---|
| `SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)` | Aussteller nicht in der CA-Datei | Server liefert noch snakeoil oder ein Zertifikat einer anderen CA; falsche CA-Datei; nach CA-Wechsel alte CA entfernt, bevor die Server umgestellt waren |
| `… self-signed certificate (18)` | selbstsigniertes Zertifikat | Server liefert noch snakeoil; falscher VirtualHost greift |
| `… certificate has expired (10)` / `certificate is not yet valid (9)` | Laufzeit | Erneuerung versäumt / Uhrzeit falsch |
| `SSL: no alternative certificate subject name matches target ipv4 address '…'` | SAN passt nicht zur Agent-Adresse | `servers.api_ip` geändert oder SAN falsch ausgestellt |
| `… permitted subtree violation (47)` | Name verletzt die `nameConstraints` der CA (nur bei E7) | SAN außerhalb der zugelassenen Ziele. Passt zugleich der SAN nicht zur Zieladresse, meldet libcurl gegebenenfalls zuerst den SAN-Fehler. |
| `… unsupported certificate purpose (26)` | falscher Verwendungszweck | `extendedKeyUsage=serverAuth` fehlt |

**C – Fehler nach erfolgreicher TLS-Prüfung** (wie bisher): HTTP 401/403 wegen Token oder IP-Freigabeliste, 5xx usw. Sie belegen, dass die TLS-Prüfung bestanden wurde.

## 5. Umsetzungsrisiken

| Nr. | Risiko | Gegenmaßnahme |
|---|---|---|
| R1 | Zertifikat läuft ab → wegen Fail-Closed Ausfall von Publish, Reload und Monitoring | E6: Überwachung vor Aktivierung; Erneuerung ≥ 30 Tage vorher; Kalendereintrag |
| R2 | Verlust des CA-Schlüssels → keine Erneuerung möglich | E8: zwei Offline-Sicherungen; Wiederherstellung einmal prüfen; sonst Neuaufbau mit Übergangsbündel |
| R3 | Kompromittierung des CA-Schlüssels | Offline-Verwahrung (E8); `nameConstraints` (E7); kein Import auf Arbeitsplätzen (E5) |
| R4 | Browser sehen auf ns1 plötzlich ein anderes Zertifikat | E5: Agent-Zertifikat nur auf `127.0.0.1:443`; Oberfläche unverändert |
| R5 | Falscher VirtualHost greift (z. B. `*:443` statt der IP-Site) | Vorprüfung D3 je Zieladresse mit `openssl s_client`; `apache2ctl -S` |
| R6 | CA-Datei für `www-data` nicht lesbar oder beschädigt | Vorprüfung D2 als `www-data`; Fehlerklasse A ist sofort eindeutig sichtbar |
| R7 | `servers.api_ip` wird später geändert → SAN passt nicht mehr | Hinweis in Abschnitt 9: Adressänderung erfordert ein neues Zertifikat |
| R8 | Uhrzeitabweichung → „not yet valid“/„expired“ | NTP-Prüfung in D1 (derzeit synchron) |
| R9 | Apache-Reload während eines Publish/Cron-Laufs | Reload außerhalb der Minuten :x0/:x5; kein laufender Publish; `apache2ctl configtest` vorher |
| R10 | Tippfehler in `ui_config.php` → PHP-Fehler in Oberfläche und Cron | Sicherung, `php -l` nach der Änderung, Rechte root:www-data 0640 beibehalten |
| R11 | Privater Server-Schlüssel gelangt nach außen | Schlüssel wird auf dem Server erzeugt, nur der CSR verlässt den Host; 0600 root |
| R12 | Agent-CA landet im Systemspeicher (`/usr/local/share/ca-certificates`) | **Nicht** dort ablegen. Die Anwendung nutzt ausschließlich `/etc/dnsmanager/agent-ca.pem`. |
| R13 | `openssl x509 -checkend` prüft bei Bündeln nur das erste Zertifikat | Überwachungsskript prüft jedes Zertifikat einzeln (Abschnitt 9) |

## 6. Schrittfolge Phase 2 (nach Freigabe E5–E8)

**Trockenlauf** (13.11.2026, ns1, Wegwerf-CA mit 3 Tagen Laufzeit in `/tmp`, danach gelöscht; keine Änderung an Apache, `/etc` oder `ui_config.php`). Ohne Befund geprüft:
- A–C: CA mit `nameConstraints`, verschlüsselter Schlüssel, Ausstellung;
- `openssl verify` mit `-verify_ip`;
- Zertifikat außerhalb der `nameConstraints` → „permitted subtree violation (47)“;
- D2: CA-Datei 0640 root:www-data, für `www-data` lesbar;
- D3: Schlüssel passt; Gegenprobe mit fremdem Schlüssel erkannt;
- D5: `s_client` → `Verification: OK`;
- D6: PHP/libcurl als `www-data` mit installiertem Code `b17d946` → HTTP 200, leerer `curlError`;
- Überwachungsskript: warnt bei kurzer Laufzeit, bei nicht erreichbarem Ziel und für jede CA im Bündel.

Nicht trockengelaufen (Apache-/Konfigurationsänderung): D4, E, F, G.

Platzhalter:
- `<CA>` = Admin-Rechner für die Ausstellung (E8);
- `<NS>` = ns1 bzw. ns2;
- `<AGENT_IP>` = `127.0.0.1` (ns1) bzw. `192.168.53.125` (ns2).

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
nameConstraints = critical,permitted;IP:127.0.0.1/255.255.255.255,permitted;IP:192.168.53.125/255.255.255.255,permitted;DNS:ns1,permitted;DNS:ns2,permitted;DNS:.example.test
EOF
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -aes-256-cbc -out dnsmanager-agent-ca.key   # fragt die Passphrase ab
openssl req -x509 -new -config ca.cnf -key dnsmanager-agent-ca.key -sha256 -days 1826 -out dnsmanager-agent-ca.pem
openssl x509 -in dnsmanager-agent-ca.pem -noout -text | grep -A2 -E "Basic Constraints|Name Constraints|Not After"
openssl x509 -in dnsmanager-agent-ca.pem -noout -fingerprint -sha256
```
Danach:
- Schlüssel auf beide Offline-Datenträger sichern (E8).
- Fingerprint und Ablaufdatum dokumentieren.

Ohne E7 entfällt die Zeile `nameConstraints`.

**B – Schlüssel und CSR je Server** (auf `<NS>`, als root):
```bash
install -d -m 750 -o root -g root /etc/ssl/dnsmanager
( umask 077; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /etc/ssl/dnsmanager/agent.key )
openssl req -new -key /etc/ssl/dnsmanager/agent.key -subj "/O=Twilight-Networks/CN=$(hostname)" -out /root/$(hostname)-agent.csr
```
Nur der CSR wird zum `<CA>` übertragen.

**C – Ausstellen** (auf `<CA>`).

SANs mit E5:

| Host | SAN |
|---|---|
| ns1 | `IP:127.0.0.1,DNS:ns1,DNS:ns1.example.test` |
| ns2 | `IP:192.168.53.125,DNS:ns2,DNS:ns2.example.test` |

Ohne E5 (gemeinsamer VirtualHost auf ns1) zusätzlich `IP:192.168.53.122` für ns1. Das widerspricht aber E7 bzw. muss dort ergänzt werden.

```bash
H=ns1; IP=127.0.0.1; SAN="IP:$IP,DNS:ns1,DNS:ns1.example.test"     # ns2: H=ns2; IP=192.168.53.125; SAN="IP:$IP,DNS:ns2,DNS:ns2.example.test"
printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=%s\nauthorityKeyIdentifier=keyid\n' "$SAN" > $H.ext
openssl x509 -req -in $H-agent.csr -CA dnsmanager-agent-ca.pem -CAkey dnsmanager-agent-ca.key \
  -CAserial dnsmanager-agent-ca.srl -CAcreateserial -sha256 -days 365 -extfile $H.ext -out $H-agent.pem
openssl verify -CAfile dnsmanager-agent-ca.pem -no-CApath -no-CAstore -purpose sslserver -verify_ip $IP $H-agent.pem
```
Danach entschlüsselte Kopien des CA-Schlüssels vom `<CA>` entfernen.

**D – Vorprüfungen vor jeder Umschaltung.** Die Prüfung ist nicht bestanden, wenn eine einzige Prüfung fehlschlägt.

1. **Uhrzeit:** `timedatectl | grep synchronized` auf ns1 und ns2 → `yes`.
2. **Dateien auf ns1** (CA-Datei, als root und als `www-data`):
   ```bash
   install -m 640 -o root -g www-data dnsmanager-agent-ca.pem /etc/dnsmanager/agent-ca.pem
   stat -c '%a %U:%G' /etc/dnsmanager/agent-ca.pem                    # 640 root:www-data
   sudo -u www-data openssl x509 -in /etc/dnsmanager/agent-ca.pem -noout -subject -enddate -fingerprint -sha256
   grep -c 'BEGIN CERTIFICATE' /etc/dnsmanager/agent-ca.pem            # 1 (beim CA-Wechsel 2)
   ```
3. **Zertifikat, Schlüssel und Kette je Server:**
   ```bash
   install -m 644 -o root -g root <NS>-agent.pem /etc/ssl/dnsmanager/agent.crt
   stat -c '%a %U:%G %n' /etc/ssl/dnsmanager /etc/ssl/dnsmanager/*     # 750 / 600 key / 644 crt
   [ "$(openssl x509 -in /etc/ssl/dnsmanager/agent.crt -noout -pubkey | sha256sum)" = \
     "$(openssl pkey -in /etc/ssl/dnsmanager/agent.key -pubout | sha256sum)" ] && echo "Schlüssel passt"
   openssl verify -CAfile dnsmanager-agent-ca.pem /etc/ssl/dnsmanager/agent.crt
   openssl x509 -in /etc/ssl/dnsmanager/agent.crt -noout -ext subjectAltName,extendedKeyUsage -dates
   ```
   Für das Agent-Zertifikat wird keine Zwischenkette benötigt, weil es direkt von der CA ausgestellt ist (`pathlen:0`). `SSLCertificateFile` enthält nur das Serverzertifikat.
4. **Agent-VirtualHost (E5)** je Server, zunächst nur anlegen und testen. Die neue Site `/etc/apache2/sites-available/dnsmanager-agent-tls.conf`:
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
   Dann:
   ```bash
   a2ensite dnsmanager-agent-tls && apache2ctl configtest && apache2ctl -S 2>&1 | grep -E '443|dnsmanager'
   systemctl reload apache2      # außerhalb der Minuten :x0/:x5, kein laufender Publish
   ```
   Der speziellere IP-VirtualHost hat für `<AGENT_IP>` Vorrang vor `default-ssl.conf` (`*:443`). Die Oberfläche auf `192.168.53.122:443` behält das bisherige Zertifikat. Solange `VERIFYPEER = false` ist, hat das keine Wirkung auf die Anwendung.
5. **TLS-Prüfung von ns1 aus**, ausschließlich mit der Agent-CA (OpenSSL):
   ```bash
   for t in 127.0.0.1 192.168.53.125; do
     echo | openssl s_client -connect $t:443 -CAfile /etc/dnsmanager/agent-ca.pem -no-CApath -no-CAstore \
       -verify_ip $t -verify_return_error -brief 2>&1 | grep -E 'Verification|verify error'
   done
   echo | openssl s_client -connect 192.168.53.122:443 2>/dev/null | openssl x509 -noout -issuer   # Oberfläche: unverändert
   ```
   Erwartet: `Verification: OK` für beide Agent-Ziele. Die Oberfläche zeigt weiterhin den bisherigen Aussteller.
6. **Prüfung mit dem tatsächlichen Anwendungs-Stack** (PHP/libcurl als `www-data`, ohne Token):
   ```bash
   sudo -u www-data php -r 'require "/usr/share/dnsmanager/web/inc/deploy/BindAgentClient.php";
     foreach (["127.0.0.1", "192.168.53.125"] as $ip) {
       $r = (new BindAgentClient("/dnsmanager-agent/api/v1", true, 2, "/etc/dnsmanager/agent-ca.pem"))->get($ip, "/system/status.php", "", 5, 10);
       printf("%s HTTP %d curlError=[%s]\n", $ip, $r["httpCode"], $r["curlError"]); }'
   ```
   Erwartet: leerer `curlError` und HTTP 401 „Fehlender Authorization-Header“ (Fehlerklasse C: TLS bestanden, Token absichtlich leer). So antworten beide Agenten heute ohne Token, geprüft am 13.11.2026. Ein nicht leerer `curlError` bedeutet Abbruch; die Ursache steht in Abschnitt 4.
7. **Ablaufüberwachung aktiv** (E6, Abschnitt 9) und einmal manuell ausgeführt.

**E – Aktivierung** (ns1).
1. Sichern:
   ```bash
   cp -p /etc/dnsmanager/ui_config.php /root/<x>-update/ui_config.php.vor-s42
   ```
2. In `ui_config.php` setzen:
   ```php
   define('CURL_SSL_VERIFYPEER', true);
   define('CURL_SSL_VERIFYHOST', 2);
   define('AGENT_TLS_CA_FILE', '/etc/dnsmanager/agent-ca.pem');
   ```
3. `php -l /etc/dnsmanager/ui_config.php` ausführen und prüfen, dass die Rechte unverändert root:www-data 0640 sind.

**F – Abnahme lesend (E4).**
1. Server-Status und Diagnosen in der Oberfläche für ns1 und ns2.
2. Den nächsten regulären Monitoring-Cron abwarten (kein manueller Lauf, F7). Danach gilt:
   - `diagnostics` für beide Server mit `checked_at` (UTC) nach der Umstellung `ok`;
   - keine neuen `diagnostic_log`-Wechsel auf `error`;
   - Agent-Zugriffslog auf ns1/ns2 mit HTTP 200.
3. PHP-/Apache-Fehlerprotokoll ohne TLS-Meldungen.

**G – Abnahme schreibend (E4).**
1. BIND-Reload je Server über die Oberfläche.
2. Publish **nur** auf einer von Thomas ausdrücklich freigegebenen Testzone.
3. Serial und Antworten auf ns1/ns2 prüfen (`dig @192.168.53.122` / `@192.168.53.125`).

## 7. Rückweg

Je nach Lage wird nur die passende Stufe ausgeführt:

1. **Anwendung** (sofort, ohne Neustart): `ui_config.php` aus der Sicherung zurückspielen, d. h. wieder `VERIFYPEER = false`, `VERIFYHOST = 0`, ohne `AGENT_TLS_CA_FILE`. Danach `php -l`.
   - Agent-Aufrufe laufen wie vor der Umstellung.
   - Die CA-Datei kann liegen bleiben.
2. **Apache (E5):**
   ```bash
   a2dissite dnsmanager-agent-tls && apache2ctl configtest && systemctl reload apache2
   ```
   Danach liefert wieder `default-ssl.conf` (snakeoil). `default-ssl.conf` wird in Phase 2 nicht verändert.
3. **Code:** kein Rücksetzen nötig. Ohne `AGENT_TLS_CA_FILE` verhält sich der Code wie vor PR #68.

**Reihenfolge bei einer Störung nach Schritt E:** zuerst Stufe 1, damit Publish und Monitoring sofort wieder funktionieren. Dann die Ursache anhand der Fehlerklasse (Abschnitt 4) klären. Stufe 2 nur, wenn das Zertifikat selbst ersetzt werden muss.

Bei falscher Konfiguration entsteht kein stiller unsicherer Zustand. Fehler sind sofort in Monitoring, Publish und Reload sichtbar.

## 8. Browser, Arbeitsplätze und DynDNS-Clients – Bewertung der Trennung

**Variante getrennt (E5, empfohlen):**
- Nur ns1 (die Anwendung) vertraut der Agent-CA.
- Das Agent-Zertifikat wird ausschließlich auf den Agent-Adressen ausgeliefert: `127.0.0.1` auf ns1, `192.168.53.125` auf ns2.
- Browser und DynDNS-Clients auf `https://192.168.53.122/` sehen unverändert das bisherige Zertifikat. Phase 2 hat damit keinen Browser-Effekt.
- Die Agent-CA wird nirgends sonst importiert. Ein Missbrauch ihres Schlüssels bedroht nur die Agent-Verbindungen, mit E7 zusätzlich nur die zugelassenen Ziele.
- Das Zertifikat der Oberfläche wird unabhängig in S40 Teil C gelöst (Unternehmens-PKI oder öffentliche CA per ACME DNS-01). Dafür gibt es eigene Freigaben; HSTS erst danach.
- Kosten: eine zusätzliche, kleine Apache-Site je Host.

**Variante gemeinsam:**
- Der VirtualHost `default-ssl.conf` auf ns1 bekommt das Agent-Zertifikat mit zusätzlichem SAN `IP:192.168.53.122`.
- Alle Admin-Arbeitsplätze müssen die Agent-CA als Stammzertifizierungsstelle importieren, sonst sehen sie weiterhin eine Warnung.
- Damit vertrauen Arbeitsplätze einer CA, deren Schlüssel nur für Maschinenvertrauen gedacht war. Ohne `nameConstraints` gilt dieses Vertrauen für beliebige Namen.
- Der Zertifikatswechsel wirkt sofort im Browser, und DynDNS-Clients brauchen die Agent-CA.

**Empfehlung:** getrennt (E5). HSTS bleibt in beiden Varianten deaktiviert, bis S40 Teil C abgeschlossen ist.

## 9. Erneuerung, CA-Wechsel und Überwachung

**Serverzertifikate** (365 Tage): spätestens 30 Tage vor Ablauf erneuern.
- Schritte B (neuer Schlüssel empfohlen), C, D3–D6, dann `systemctl reload apache2`. An der Anwendung ist nichts zu ändern.
- Eine Änderung von `servers.api_ip` erfordert ebenfalls ein neues Zertifikat mit passendem SAN, **vor** der Adressänderung.

**CA-Wechsel** (spätestens im 5. Jahr; isoliert getestet, Fall C07):
1. neue CA (A);
2. `agent-ca.pem` = alte + neue CA, danach D2;
3. Serverzertifikate aus der neuen CA (B, C, D3–D6);
4. alte CA aus `agent-ca.pem` entfernen, erneut D2 und D6.

**Ablaufüberwachung** (E6) als tägliches Skript auf ns1, z. B. `/etc/cron.daily/dnsmanager-agent-tls-check` (root 0755). Meldungen gehen per `logger` nach `auth.log`/Journal. Eine Mail-Weiterleitung ist gesondert festzulegen.
```bash
#!/bin/bash
# Warnt, wenn ein Agent-Zertifikat oder eine Agent-CA in weniger als 30 Tagen abläuft oder nicht prüfbar ist.
LIMIT=$((30*86400)); CA=/etc/dnsmanager/agent-ca.pem
warn() { logger -p auth.warning -t dnsmanager-tls "$1"; }
for t in 127.0.0.1 192.168.53.125; do
  c=$(echo | timeout 10 openssl s_client -connect $t:443 2>/dev/null | openssl x509 2>/dev/null)
  [ -z "$c" ] && { warn "Agent $t: kein Zertifikat abrufbar"; continue; }
  echo "$c" | openssl x509 -noout -checkend $LIMIT >/dev/null || warn "Agent $t: Zertifikat läuft in weniger als 30 Tagen ab ($(echo "$c" | openssl x509 -noout -enddate))"
done
# jede CA im Bündel einzeln prüfen
d=$(mktemp -d)
awk -v d="$d" '/BEGIN CERTIFICATE/{n++} n{print > (d "/ca" n ".pem")}' "$CA"
ls "$d"/ca*.pem >/dev/null 2>&1 || warn "Agent-CA-Datei $CA enthält kein Zertifikat"
for f in "$d"/ca*.pem; do
  [ -e "$f" ] || continue
  openssl x509 -in "$f" -noout -checkend $LIMIT >/dev/null || warn "Agent-CA läuft in weniger als 30 Tagen ab: $(openssl x509 -in "$f" -noout -subject -enddate | tr '\n' ' ')"
done
rm -rf "$d"
```
Das Skript braucht keine Geheimnisse und beeinflusst keine Locks. Es ist vor der Aktivierung einmal manuell auszuführen und mit einem Testzertifikat kurzer Laufzeit auf Auslösung zu prüfen.

## 10. Was Phase 2 nicht ändert

- Datenbankstruktur und `servers.api_ip`;
- Agent-Code und Agent-Token;
- `default-ssl.conf`;
- Firewall und Fail2Ban;
- HSTS.

## 11. Nachweis Phase 1 (isolierter Test t70, ns1, 13.11.2026)

**Umgebung:**
- PHP 8.5.4, libcurl 8.18.0, OpenSSL 3.5.5, Ubuntu;
- Code aus eigenem Klon, feste SHAs: Basis `ce3b245`, PR `5896a4a`;
- Wegwerf-Test-CAs (3 Tage);
- lokale TLS-Testserver auf `127.0.0.1`/`127.0.1.1`, Aufrufe als `www-data`;
- keine Agenten, keine DNS-Verteilung, keine Datenbank, keine Monitoring-Locks.

**Ergebnisse** (alle bestanden, 34/34):

| Fall | Erwartung | Ergebnis |
|---|---|---|
| C01 / C01P | gültige CA, korrekter IP-SAN (GET/POST) → Erfolg | Erfolg, Token korrekt übertragen |
| C02 | falscher IP-SAN → Ablehnung | „no alternative certificate subject name matches target ipv4 address“ |
| C03 | abgelaufen → Ablehnung | „certificate has expired (10)“ |
| C04 | fremde CA → Ablehnung | „unable to get local issuer certificate (20)“ |
| C05 | selbstsigniert → Ablehnung | „self-signed certificate (18)“ |
| C06 | snakeoil (systemweit vertraut, Name `ns1` passend) → Ablehnung | „self-signed certificate (18)“ |
| C06K | Kontrolle: CA-Datei = snakeoil → Erfolg | Erfolg. Die Ablehnung in C06 liegt also an der Vertrauensstellung. |
| C07A/B, C07N, C07K | Bündel alt+neu → beide gültig; nur neue CA → neue gültig; nur alte CA → neue abgelehnt | wie erwartet |
| C08–C12, C14 | CA-Datei fehlt / unlesbar / leer / ohne PEM / Verzeichnis / PEM-ähnlich ungültig → Ablehnung | Ablehnung. Bei C08–C13B nachweislich keine Anfrage am Server. |
| C13 / C13B | `VERIFYHOST` 0 bzw. 1 mit CA-Datei → Ablehnung | Ablehnung ohne Verbindungsaufbau |
| C15 / C15B | Prüfung aus → bisheriger Modus | Verbindung wie bisher |
| D (18 Fälle) | ohne CA-Einstellung: Basis und PR identisch | identisch |
| fromConfig | mit `AGENT_TLS_CA_FILE`: Agent-CA ja, snakeoil/fremd nein. Ohne: Basis und PR identisch. | wie erwartet |
| S01–S06 | Service-Ebene (`LegacyAgentServerControlBackend`, `AgentMonitoringBackend`): Erfolg nur mit gültiger CA | wie erwartet |
| Token | kein Token in Fehlermeldungen, Ergebnissen oder Ausgaben | 0 Treffer |
| Mutation 1 | ohne `CAPATH = null` wird snakeoil angenommen | angenommen → `CAPATH = null` ist nötig |
| Mutation 2 | ohne Fail-Closed-Prüfung wird bei `VERIFYHOST 0` ein falscher SAN angenommen | angenommen → Prüfung ist nötig |

**Nach dem Merge (13.11.2026):**
- ns1 auf `b17d946` aktualisiert, Konfiguration unverändert.
- Reguläre Monitoring-Läufe 15:25 und 15:30: 12/12 Diagnosen `ok`.
- Agenten ns1/ns2 HTTP 200.
- Keine PHP- oder Apache-Fehler.
