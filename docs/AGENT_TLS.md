# Agent-TLS: interne CA, Zertifikate, Umstellung und Rückweg (S42)

Stand: 13.11.2026 · Befund S42 (siehe `docs/ARCHITECTURE_CORE.md`, Abschnitt 6).

**Status:**
- **Phase 1 (dieser Stand):** Code-Option `AGENT_TLS_CA_FILE` in `BindAgentClient` umgesetzt und isoliert getestet.
- Es gibt noch keine produktive CA und keine produktiven Zertifikate. Auf ns1 ist die TLS-Prüfung weiterhin abgeschaltet.
- **Phase 2** braucht eine eigene Freigabe. Dazu gehören:
  - die Verwahrung des CA-Schlüssels;
  - die Ausstellung der Zertifikate;
  - die Umstellung auf ns1/ns2;
  - die Ablaufüberwachung.

Diese Anleitung beschreibt den freigabefähigen Ablauf, führt ihn aber nicht aus.

## 1. Entscheidungen

| Nr. | Inhalt |
|---|---|
| S42-E1 | Variante A: interne CA mit IP-SANs. Die IP-Adressierung (`servers.api_ip`) und die Datenbankstruktur bleiben unverändert. |
| S42-E2 | Eigene CA nur für die Agenten:<br>– CA ca. 5 Jahre, Serverzertifikate je 1 Jahr;<br>– Erneuerung spätestens 30 Tage vor Ablauf;<br>– je Server ein eigener privater Schlüssel;<br>– SANs = tatsächlich genutzte Agent-IPs plus passende DNS-Namen.<br>Der private CA-Schlüssel liegt **nicht** auf ns1 oder ns2. Verwahrung und produktive Ausstellung werden gesondert freigegeben. |
| S42-E3 | Phase 1: optionale, ausschließliche CA-Vertrauensstellung im Code (dieser Stand). |
| S42-E4 | Abnahme nach der späteren Umstellung in dieser Reihenfolge:<br>1. lesende Agent-Aufrufe und Monitoring;<br>2. Publish nur auf einer ausdrücklich freigegebenen, unkritischen Testzone.<br>Die Auswirkung auf den Browserzugriff ist zu beachten (Abschnitt 8). |

## 2. Ist-Zustand (13.11.2026, ns1/ns2)

**Agent-Adressen** (`servers.api_ip`):
- ns1 ruft seinen eigenen Agenten über `127.0.0.1` auf.
- ns2 wird von ns1 über `192.168.53.125` aufgerufen.
- URL: `https://{api_ip}/dnsmanager-agent/api/v1/…`.

**Apache:** Beide Hosts haben je einen HTTPS-VirtualHost `default-ssl.conf` (`*:443`) mit dem *snakeoil*-Zertifikat:
- `/etc/ssl/certs/ssl-cert-snakeoil.pem`;
- selbstsigniert;
- SAN nur `DNS:ns1` bzw. `DNS:ns2`.

Auf ns1 liefert derselbe VirtualHost die **Weboberfläche, die DynDNS-API und den Agenten** aus.

**Anwendung** (`/etc/dnsmanager/ui_config.php` auf ns1):
- `CURL_SSL_VERIFYPEER = false`, `CURL_SSL_VERIFYHOST = 0`;
- `AGENT_TLS_CA_FILE` ist nicht gesetzt.

**Wer ruft die Agenten auf** (ns1, jeweils als `www-data`):
- Apache/PHP: Publish, BIND-Reload, Diagnosen;
- Cron `monitoring_run-cli.php` alle 5 Minuten.

**Warum eine eigene CA-Datei allein nicht reicht:** Das snakeoil-Zertifikat von ns1 liegt im System-Zertifikatsverzeichnis `/etc/ssl/certs`. Bei eingeschalteter Prüfung würde libcurl es deshalb ohne weitere Maßnahmen als vertrauenswürdig ansehen.

## 3. Verhalten der Code-Option

| Konfiguration | Verhalten |
|---|---|
| `AGENT_TLS_CA_FILE` nicht gesetzt | wie bisher (Prüfung gemäß `CURL_SSL_VERIFYPEER`/`-HOST`, bei eingeschalteter Prüfung gegen den systemweiten CA-Bestand) |
| `CURL_SSL_VERIFYPEER = false` (mit oder ohne CA-Datei) | wie bisher: keine Prüfung, die CA-Datei wird nicht verwendet |
| `CURL_SSL_VERIFYPEER = true`, `CURL_SSL_VERIFYHOST = 2`, gültige CA-Datei | Vertrauen **ausschließlich** in die CA-Datei:<br>– `CURLOPT_CAINFO` = CA-Datei;<br>– `CURLOPT_CAPATH` = null, d. h. das systemweite Verzeichnis ist abgeschaltet;<br>– Prüfung von Kette, Laufzeit und IP-/DNS-SAN durch libcurl/OpenSSL. |
| Prüfung an, aber CA-Datei fehlt, ist unlesbar, leer, ohne PEM-Zertifikat oder ein Verzeichnis, **oder** `CURL_SSL_VERIFYHOST ≠ 2` | **kein Verbindungsaufbau** (Fail-Closed). Der Aufruf liefert einen Fehler (`curlError`), den Publish, Reload und Monitoring wie jeden Verbindungsfehler anzeigen.<br>Es gibt nie einen Rückfall auf eine ungeprüfte Verbindung. |
| CA-Datei mit mehreren Zertifikaten | Alle enthaltenen CAs sind vertrauenswürdig. Für den CA-Wechsel: alte + neue CA. |

Fehlermeldungen enthalten den Pfad der CA-Datei, aber nie den Bearer-Token.

## 4. CA erstellen (Phase 2, freigabepflichtig)

**Wo:** auf einem dafür bestimmten Admin-Rechner bzw. einem Offline-Medium – **nicht** auf ns1/ns2.

**Schlüssel:**
- verschlüsselt (AES-256);
- Passphrase im Passwortmanager;
- mindestens zwei getrennte Offline-Sicherungen;
- Wiederherstellung einmal probeweise prüfen.

```bash
# CA-Schlüssel (verschlüsselt) und CA-Zertifikat, Laufzeit ca. 5 Jahre
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -aes-256-cbc -out dnsmanager-agent-ca.key
openssl req -x509 -new -key dnsmanager-agent-ca.key -sha256 -days 1826 \
  -subj "/O=Twilight-Networks/CN=DNS-Manager Agent CA 2026" \
  -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
  -addext "keyUsage=critical,keyCertSign,cRLSign" \
  -addext "subjectKeyIdentifier=hash" \
  -out dnsmanager-agent-ca.pem
openssl x509 -in dnsmanager-agent-ca.pem -noout -subject -enddate -fingerprint -sha256
```

Fingerprint und Ablaufdatum dokumentieren. Nur `dnsmanager-agent-ca.pem` (öffentlich) wird verteilt.

## 5. Serverzertifikate ausstellen (je Host, Phase 2)

Der **private Schlüssel wird auf dem jeweiligen Server erzeugt** und verlässt ihn nicht. Zum CA-Rechner wird nur der CSR übertragen.

**SANs:**

| Host | SAN | Begründung |
|---|---|---|
| ns1 | `IP:127.0.0.1, IP:192.168.53.122, DNS:ns1, DNS:ns1.example.test` | `127.0.0.1` ist die tatsächliche Agent-Adresse von ns1. Die LAN-IP und die Namen dienen der Weboberfläche und Diagnose. |
| ns2 | `IP:192.168.53.125, DNS:ns2, DNS:ns2.example.test` | Agent-Adresse aus Sicht von ns1 |

**Auf dem Server** (Beispiel ns1), als root:
```bash
install -d -m 750 -o root -g root /etc/ssl/dnsmanager
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /etc/ssl/dnsmanager/agent.key
chmod 600 /etc/ssl/dnsmanager/agent.key
openssl req -new -key /etc/ssl/dnsmanager/agent.key -subj "/O=Twilight-Networks/CN=ns1" -out /root/ns1-agent.csr
```

**Auf dem CA-Rechner:**
```bash
cat > ns1.ext <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=IP:127.0.0.1,IP:192.168.53.122,DNS:ns1,DNS:ns1.example.test
authorityKeyIdentifier=keyid
EOF
openssl x509 -req -in ns1-agent.csr -CA dnsmanager-agent-ca.pem -CAkey dnsmanager-agent-ca.key \
  -CAserial dnsmanager-agent-ca.srl -CAcreateserial -sha256 -days 365 -extfile ns1.ext -out ns1-agent.pem
openssl x509 -in ns1-agent.pem -noout -subject -ext subjectAltName -enddate
openssl verify -CAfile dnsmanager-agent-ca.pem ns1-agent.pem
```

Für ns2 gilt dasselbe mit dem ns2-SAN aus der Tabelle oben.

## 6. Installation und Umstellung (Phase 2, Reihenfolge)

**Vorbereitung:**
- Snapshot-/Backup-Stand klären.
- Sichern:
  - `/etc/apache2/sites-available/default-ssl.conf` (ns1, ns2);
  - `/etc/dnsmanager/ui_config.php` (ns1).
- Laufenden Commit festhalten.
- Admin-Browser vorbereiten (Abschnitt 8).

1. **Zertifikat auf ns2 einbauen.**
   - `ns2-agent.pem` nach `/etc/ssl/dnsmanager/agent.crt` (0644).
   - In `default-ssl.conf` eintragen:
     - `SSLCertificateFile /etc/ssl/dnsmanager/agent.crt`
     - `SSLCertificateKeyFile /etc/ssl/dnsmanager/agent.key`
   - `apache2ctl configtest`, dann `systemctl reload apache2`.
   - Ohne Wirkung auf die Anwendung, solange deren Prüfung noch aus ist.
2. **Zertifikat auf ns1 einbauen** (wie Schritt 1).
   - Ab hier sehen Browser auf der ns1-Oberfläche das neue Zertifikat (Abschnitt 8).
3. **CA-Datei auf ns1 ablegen:**
   ```bash
   install -m 640 -o root -g www-data dnsmanager-agent-ca.pem /etc/dnsmanager/agent-ca.pem
   ```
4. **Vorab-Prüfung von ns1 aus**, ausschließlich mit der Agent-CA und ohne Systemverzeichnis:
   ```bash
   for t in 127.0.0.1 192.168.53.125; do
     echo | openssl s_client -connect $t:443 -CAfile /etc/dnsmanager/agent-ca.pem -no-CApath -no-CAstore \
       -verify_ip $t -verify_return_error -brief 2>&1 | grep -E 'Verification|verify error'
   done
   sudo -u www-data test -r /etc/dnsmanager/agent-ca.pem && echo lesbar
   ```
   Erwartet: `Verification: OK` für beide Ziele.
5. **Anwendung umstellen** in `/etc/dnsmanager/ui_config.php`:
   ```php
   define('CURL_SSL_VERIFYPEER', true);
   define('CURL_SSL_VERIFYHOST', 2);
   define('AGENT_TLS_CA_FILE', '/etc/dnsmanager/agent-ca.pem');
   ```
6. **Abnahme lesend (E4):**
   - Server-Status/Diagnosen in der Oberfläche für ns1 und ns2;
   - den nächsten Cron-Lauf des Monitorings abwarten;
   - Monitoring-Ergebnisse und PHP-/Apache-Fehlerprotokoll auf TLS-Fehler prüfen.
7. **Schreibende Abnahme (E4):**
   - BIND-Reload eines Servers;
   - danach Publish **nur** auf einer ausdrücklich freigegebenen, unkritischen Testzone;
   - Serial und Antwort auf ns1/ns2 prüfen.

## 7. Rückweg

**Anwendung** (sofort wirksam, ohne Neustart): in `ui_config.php` zurück auf
```php
define('CURL_SSL_VERIFYPEER', false);
define('CURL_SSL_VERIFYHOST', 0);
```
Dazu `AGENT_TLS_CA_FILE` auskommentieren oder die Datei aus der Sicherung zurückspielen.
- Die Fail-Closed-Logik greift dann nicht mehr. Agent-Aufrufe laufen wie vorher ohne Prüfung.

**Apache:** `default-ssl.conf` aus der Sicherung zurückspielen (snakeoil), `apache2ctl configtest`, `systemctl reload apache2`.
- Nur nötig, wenn das neue Zertifikat selbst Probleme macht, z. B. bei Browsern oder DynDNS-Clients.

**Woran ein Fehler zu erkennen ist:**
- Bei falscher Konfiguration schlagen alle Agent-Aufrufe sichtbar fehl:
  - Monitoring-Status „Verbindungsfehler: Agent-TLS-Konfiguration ungültig …“ bzw. OpenSSL-Prüffehler;
  - Publish-/Reload-Fehlermeldung.
- Es entsteht kein stiller unsicherer Zustand.

**Code:** Ein Zurücksetzen auf einen Commit ohne die Option ist nicht nötig. Ohne `AGENT_TLS_CA_FILE` verhält sich der Code wie vorher.

## 8. Auswirkungen auf Browser und DynDNS-Clients

Auf ns1 nutzen Weboberfläche und DynDNS-API denselben VirtualHost wie der Agent.

**Browser:**
- Nach Schritt 2 erhalten Browser das von der Agent-CA ausgestellte Zertifikat.
- Ohne importierte CA zeigen sie weiterhin eine Warnung (wie heute bei snakeoil).
- Mit importierter CA ist der Zugriff über `https://192.168.53.122/` bzw. `https://ns1.example.test/` warnungsfrei, weil IP- und DNS-SAN vorhanden sind.

**CA-Import:**
- Nur auf den Admin-Arbeitsplätzen, unter Windows in „Vertrauenswürdige Stammzertifizierungsstellen“ des Benutzers bzw. per Gruppenrichtlinie.
- Damit vertrauen diese Rechner allem, was die Agent-CA ausstellt. Deshalb ist eine strikte Verwahrung des CA-Schlüssels nötig.
- Optional kann die CA mit `nameConstraints` auf die genannten IPs/Namen beschränkt werden. Das ist bei der Freigabe von Phase 2 zu entscheiden.

**HSTS:** weiterhin **nicht** aktivieren (S40 Teil C). Browser wenden HSTS nicht auf IP-Adressen an.

**DynDNS-Clients** (derzeit keine aktiven externen Clients erkennbar):
- Künftige Clients brauchen die Agent-CA oder eine andere vertrauenswürdige Zertifikatskette.
- Alternativ kann die Oberfläche später ein eigenes, z. B. öffentlich ausgestelltes Zertifikat erhalten (S40 Teil C). Dann gilt das Agent-Zertifikat nur für den Agentenpfad.
- Das erfordert einen eigenen VirtualHost bzw. Namen und ist nicht Teil von S42.

## 9. Erneuerung, CA-Wechsel und Überwachung

**Serverzertifikate** (1 Jahr): spätestens **30 Tage vor Ablauf** neu ausstellen.
- Dabei möglichst einen neuen Schlüssel erzeugen (Abschnitt 5).
- Danach Apache reload. An der Anwendung ist nichts zu ändern.

**CA-Wechsel** (spätestens im 5. Jahr; isoliert getestet, Fall C07):
1. neue CA erstellen;
2. `agent-ca.pem` auf ns1 = alte + neue CA (Übergangsbündel);
3. Serverzertifikate aus der neuen CA ausstellen und einbauen;
4. danach die alte CA aus `agent-ca.pem` entfernen.

**Ablaufüberwachung** (Phase 2, noch nicht umgesetzt):
- täglich auf ns1 prüfen, ob die Zertifikate beider Agent-Endpunkte und die CA in weniger als 30 Tagen ablaufen;
- Ausgabe per Log/Mail.

Beispiel:
```bash
for t in 127.0.0.1 192.168.53.125; do
  echo | openssl s_client -connect $t:443 2>/dev/null | openssl x509 -noout -checkend $((30*86400)) >/dev/null \
    || logger -p auth.warning "dnsmanager: Agent-Zertifikat $t läuft in weniger als 30 Tagen ab"
done
openssl x509 -in /etc/dnsmanager/agent-ca.pem -noout -checkend $((30*86400)) >/dev/null \
  || logger -p auth.warning "dnsmanager: Agent-CA läuft in weniger als 30 Tagen ab"
```
Bei einem Bündel prüft `openssl x509` nur das erste Zertifikat. Für den Übergang jede CA einzeln prüfen.

## 10. Nachweis Phase 1 (isolierter Test t70, ns1, 13.11.2026)

**Umgebung:**
- PHP 8.5.4, libcurl 8.18.0, OpenSSL 3.5.5, Ubuntu;
- Code aus eigenem Klon, feste SHAs: Basis `ce3b245`, PR `5896a4a`;
- Wegwerf-Test-CAs (3 Tage);
- lokale TLS-Testserver auf `127.0.0.1`/`127.0.1.1`, Aufrufe als `www-data`;
- keine Agenten, keine DNS-Verteilung, keine Datenbank, keine Monitoring-Locks.

**Ergebnisse** (alle bestanden):

| Fall | Erwartung | Ergebnis |
|---|---|---|
| C01 / C01P | gültige CA, korrekter IP-SAN (GET/POST) → Erfolg | Erfolg, Token korrekt übertragen |
| C02 | falscher IP-SAN → Ablehnung | „no alternative certificate subject name matches target ipv4 address“ |
| C03 | abgelaufen → Ablehnung | „certificate has expired (10)“ |
| C04 | fremde CA → Ablehnung | „unable to get local issuer certificate (20)“ |
| C05 | selbstsigniert → Ablehnung | „self-signed certificate (18)“ |
| C06 | snakeoil (systemweit vertraut, Name `ns1` passend) → Ablehnung | „self-signed certificate (18)“ |
| C06K | Kontrolle: CA-Datei = snakeoil → Erfolg | Erfolg. Die Ablehnung in C06 liegt also an der Vertrauensstellung, nicht am Namen. |
| C07A/B, C07N, C07K | Bündel alt+neu → beide gültig; nur neue CA → neue gültig; nur alte CA → neue abgelehnt | wie erwartet |
| C08–C12, C14 | CA-Datei fehlt / unlesbar (root 0600) / leer / ohne PEM / Verzeichnis / PEM-ähnlich ungültig → Ablehnung | Ablehnung. Bei C08–C12 nachweislich keine Anfrage am Server. |
| C13 / C13B | `VERIFYHOST` 0 bzw. 1 mit CA-Datei → Ablehnung | Ablehnung ohne Verbindungsaufbau |
| C15 / C15B | Prüfung aus (+ CA-Datei bzw. fehlende Datei) → bisheriger Modus | Verbindung wie bisher |
| D (18 Fälle) | ohne CA-Einstellung: Basis und PR identisch (Prüfung aus / an mit Host 2 / an mit Host 0 × 6 Ziele) | identisch |
| fromConfig | mit `AGENT_TLS_CA_FILE`: Agent-CA ja, snakeoil/fremd nein. Ohne: Basis und PR identisch. | wie erwartet |
| S01–S06 | Service-Ebene (`LegacyAgentServerControlBackend`, `AgentMonitoringBackend`): Erfolg nur mit gültiger CA, sonst Fehlerstatus | wie erwartet |
| Token | kein Token in Fehlermeldungen, Ergebnissen oder Ausgaben | 0 Treffer |
| Mutation 1 | ohne `CAPATH = null` wird snakeoil angenommen | angenommen → Test erkennt die Mutation; `CAPATH = null` ist nötig |
| Mutation 2 | ohne Fail-Closed-Prüfung wird bei `VERIFYHOST 0` ein falscher SAN angenommen | angenommen → Test erkennt die Mutation |

**Aufräumen:** Testserver beendet, Testverzeichnis gelöscht, `/tmp` weiterhin 1777.
