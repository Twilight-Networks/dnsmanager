# Agent-TLS: interne CA, Zertifikate, Umstellung und Rückweg (S42)

Stand: 13.11.2026 · Befund S42 (siehe `docs/ARCHITECTURE_CORE.md`, Abschnitt 6).

**Status:**
- **Phase 1 – erledigt:** PR #68, `main` `b17d946`. Code-Option `AGENT_TLS_CA_FILE` in `BindAgentClient`, isoliert getestet (Abschnitt 12).
- **Phase 2 – durchgeführt (13.11.2026):** W0–W6, N1, D0–D7, E, F und G0–G6 auf ns1/ns2 bestanden; Agent-TLS-Prüfung seit 13.11.2026 19:46 produktiv aktiv, Ablaufwarnung aktiv. Offen: W7 (CA-Schlüssel vom Ausstellungsrechner entfernen). Fingerprints: CA `2F:5E:A9:48:…:31:0F`, ns1 `FB:A4:B6:0B:…:B1:54:17`, ns2 `CD:E4:F3:33:…:1A:97:0E`.
- **Projektgrenze:** zwei gültige Agent-Zertifikate, zuverlässige TLS-Prüfung und die bestehende Ablaufwarnung im Systemstatus. Keine weitere Zertifikatsverwaltungsfunktion, kein Mail-Relay oder Mailserver, keine zusätzliche PKI-Infrastruktur.
- **S42 ist technisch abgeschlossen** (13.11.2026). Die Abschnitte 6 bis 9 gelten weiter für Erneuerung, CA-Wechsel und Rückweg.

**Hilfsskripte** (`docs/agent-tls/`, keine Geheimnisse, Ergebnis über Exit-Code):

| Skript | Zweck | Ausführung |
|---|---|---|
| `s42-vhost-check.sh` | VirtualHost-Auswahl und Zertifikatsauslieferung (E5) | je Host |
| `s42-precheck.sh` | Vorprüfung vor der Aktivierung (CA-Datei, Kette, Rechte, Handshake, PHP-Stack, Oberfläche unverändert) | ns1 |
| `s42-ca-ext-check.sh` | Prüfung der Extensions der produktiven Agent-CA: `basicConstraints`, `keyUsage`, exakte `nameConstraints` (D6, E7) | ns1 |
| `s42-nc-negative.sh` | Negativtest der `nameConstraints` mit dem PHP-cURL-Stack. **Nur mit Wegwerf-CA**; mit Trockenlauf 2 erbracht, wird mit der produktiven CA nicht ausgeführt. | – |
| `s42-publish-zone.php` | einmaliger Einzelzonen-Publish der Testzone über `PublishService::publishZone()` (E4) | ns1, nur vorübergehend außerhalb des Webroots; Ausführung durch den Administrator per `sudo -u www-data` |

## 1. Entscheidungen

| Nr. | Inhalt | Stand |
|---|---|---|
| S42-E1 | Interne CA mit IP-SANs. Adressierung (`servers.api_ip`) und Datenbankstruktur bleiben unverändert. | freigegeben |
| S42-E2 | Eigene Agent-CA:<br>– CA ca. 5 Jahre, Serverzertifikate 1 Jahr;<br>– Erneuerung ≥ 30 Tage vor Ablauf;<br>– je Server ein eigener Schlüssel;<br>– SANs = Agent-IPs plus passende DNS-Namen;<br>– CA-Schlüssel nicht auf ns1/ns2. | freigegeben |
| S42-E3 | Phase 1: optionale, ausschließliche CA-Vertrauensstellung im Code | erledigt (#68) |
| S42-E4 | Abnahme in dieser Reihenfolge:<br>1. lesende Agent-Aufrufe und der reguläre Monitoring-Cron;<br>2. BIND-Reload und Publish erst im Rahmen der gesondert freigegebenen Abnahme, Publish nur auf einer ausdrücklich freigegebenen Testzone. | freigegeben. Testzone `zone.example.test` akzeptiert; Änderung nur TXT `s42-tls-test`; gezielter Einzelzonen-Publish (Abschnitt 6 G). |
| S42-E5 | Getrennte, IP-gebundene Apache-VirtualHosts für die Agent-Zertifikate:<br>– ns1 `127.0.0.1:443`, ns2 `192.168.53.125:443`;<br>– die Oberfläche auf ns1 `192.168.53.122:443` bleibt unverändert;<br>– die Agent-CA wird **nicht** auf Admin-Arbeitsplätzen importiert.<br>Vor der Aktivierung sind die tatsächliche VirtualHost-Auswahl, die Zertifikatsauslieferung und der unveränderte Browserzugriff nachzuweisen (Schritt D4/D5). | freigegeben |
| S42-E6 | Die Ablaufüberwachung muss **vor** der TLS-Aktivierung nachweislich alarmieren. Eine bloße Logzeile reicht nicht. Keine neue externe Benachrichtigungsinfrastruktur ohne Rücksprache. | freigegeben mit Ergänzung. Alarmweg **E6-A** entschieden und umgesetzt (Abschnitt 9). |
| S42-E7 | CA mit kritischen `nameConstraints` auf genau die benötigten Agent-IPs und DNS-Namen (Abschnitt 5). Negativtest mit dem PHP-cURL-Stack mit Wegwerf-CA erbracht (Trockenlauf 2, Abschnitt 11). Vor produktiver Verwendung werden die Extensions der produktiven CA geprüft (D6). Aus der produktiven CA werden nur die beiden Agent-Zertifikate ausgestellt. | grundsätzlich freigegeben |
| S42-E8 | CA-Schlüssel:<br>– verschlüsselt;<br>– zwei getrennte Offline-Sicherungen;<br>– Passphrase im Passwortmanager.<br>**Ausstellungsrechner:** der vorhandene Windows-PC von Thomas mit der vorhandenen Git-OpenSSL 3.5.9 (`ucrt64`); keine zusätzliche VM, kein CA-Dienst, keine neue Software. Ablauf W0–W7 in Abschnitt 6. | festgelegt. Aufbewahrungsorte der beiden Datenträger legt Thomas fest. |

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
- E8 festgelegt (Windows-PC, Git-OpenSSL; Aufbewahrungsorte der beiden Datenträger durch Thomas);
- Ablaufwarnung E6-A vorhanden (PR #70);
- Snapshot-/Backup-Stand beider VMs geklärt;
- gesonderte Ausführungsfreigabe.

**Rollen in der gemeinsamen Durchführung:**
- **Windows-Schritte mit Passphrase (W1, W2, W4):** Thomas führt sie selbst in einem eigenen PowerShell-Fenster aus. Die Passphrase wird nur an der Eingabeaufforderung von OpenSSL eingetippt.
- **Schritte ohne Geheimnisse:** Claude kann die Windows-Schritte W0, W3, W5 und W6 sowie die ns1/ns2-Schritte per SSH ausführen.

Platzhalter:
- `<NS>` = ns1 bzw. ns2;
- `<AGENT_IP>` = `127.0.0.1` (ns1) bzw. `192.168.53.125` (ns2).

**Grundregel:** Jedes Prüfskript endet mit Exit-Code 0 („BESTANDEN“) oder 1 („ABBRUCH“). Ein Exit-Code ≠ 0 bedeutet Abbruch des weiteren Ablaufs; die Ursache steht in Abschnitt 4. Bewertet wird nie ein Textvorkommen, sondern Exit-Codes und exakte Werte (Fingerprint, HTTP-Code, Rechte).

### Ausstellung auf dem Windows-PC (E8)

**OpenSSL-Umgebung** (geprüft am 13.11.2026):

| Installation | Bewertung |
|---|---|
| `C:\Program Files\Git\ucrt64\bin\openssl.exe` | **zu verwenden:** OpenSSL 3.5.9, native Windows-Variante, im PATH, gleiche Hauptversion wie ns1 (3.5.5) |
| `C:\Program Files\Git\usr\bin\openssl.exe` | nicht verwenden: MSYS-Variante; Passphrase-Abfrage in Git Bash (mintty) unzuverlässig |
| `C:\Program Files\OpenSSL-Win64` | nicht verwenden: 3.4.1 vom Februar 2025, veraltet |

Trockenläufe mit Wegwerf-Material im Scratchpad (13.11.2026, danach gelöscht) waren ohne Befund:
- verschlüsselter Schlüssel, CA mit kritischen `nameConstraints`/`pathlen:0`, Signieren eines CSR, `verify -verify_ip` (richtige IP Exit 0, falsche Exit 2);
- die PowerShell-Blöcke W0–W5 dieser Anleitung (Fassung mit verbindlichem Abbruch) ausgeführt. Nur für den Trockenlauf wurde eine Test-Passphrase per Umgebungsvariable statt per Eingabeaufforderung übergeben, und die Datenträger wurden durch zwei lokale Verzeichnisse ersetzt.
  - Ergebnis: alle Schritte „BESTANDEN“; zwei Agent-Zertifikate ausgestellt und geprüft;
  - Abbruchproben, jeweils Abbruch des ganzen Blocks ohne „BESTANDEN“:
    - vorhandenes Arbeitsverzeichnis (W0), vorhandener Schlüssel (W1);
    - falsche Passphrase bei der Wiederherstellungsprobe und Kopierfehler auf schreibgeschütztes Ziel (W2);
    - fehlender CSR (W3), vorhandenes Zertifikat (W4);
    - falsches Zertifikat bzw. falsche IP bei `verify` (W5).
  - Der Zweig „Hash weicht ab“ (W2/W5) ließ sich ohne Manipulation nicht auslösen. Er ist wie die übrigen Prüfungen als `throw` umgesetzt.
  - alle PowerShell-Blöcke parsen fehlerfrei.

**Windows-Besonderheiten:**
- Die PEM-Dateien entstehen mit CRLF-Zeilenenden; sie werden auf ns1/ns2 vor der Installation nach LF normalisiert (W6).
- PowerShell 5.1 zeigt reine OpenSSL-Statusmeldungen auf stderr (z. B. „Certificate request self-signature ok“) rot an; maßgeblich ist `$LASTEXITCODE`.
- Befehle mit Passphrase in einem PowerShell-Fenster ausführen, nicht in Git Bash.
- Die Passphrase **nie** per `-pass`/`-passin` auf der Kommandozeile angeben, denn sie landet sonst im PowerShell-Verlauf.

**Verbindlicher Abbruch:** Jeder Schritt W1–W5 ist ein in sich geschlossener Block `& { … }`.
- Nach jedem kritischen OpenSSL-Befehl prüft `Must` den Exit-Code. Hash-Vergleiche und Erwartungen werden ausdrücklich geprüft.
- Bei einer Abweichung bricht `throw` den **ganzen Block** ab, sodass die folgenden Befehle nicht ausgeführt werden.
- Erfolgreich ist ein Schritt nur mit der Schlussmeldung „Wn BESTANDEN“. Erst dann wird der nächste Schritt gestartet.
- Die Blöcke werden jeweils vollständig in das PowerShell-Fenster eingefügt.

**W0 – Vorbereitung** (ohne Geheimnisse). Arbeitsverzeichnis lokal und außerhalb von Benutzerprofil, OneDrive und Synchronisation, Zugriff nur für das eigene Konto:
```powershell
$O = 'C:\Program Files\Git\ucrt64\bin\openssl.exe'
function global:Must([string]$Step) { if ($LASTEXITCODE -ne 0) { throw "ABBRUCH $Step (Exit-Code $LASTEXITCODE)" } }
& {
  & $O version; Must 'W0 OpenSSL'                                         # OpenSSL 3.5.x
  if (Test-Path C:\S42-CA) { throw 'ABBRUCH W0: C:\S42-CA existiert bereits' }
  New-Item -ItemType Directory C:\S42-CA, C:\S42-CA\csr -ErrorAction Stop | Out-Null
  icacls C:\S42-CA /inheritance:r /grant:r "${env:USERNAME}:(OI)(CI)F"; Must 'W0 icacls'
  @'
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
'@ | Set-Content -Encoding ascii C:\S42-CA\ca.cnf -ErrorAction Stop
  'W0 BESTANDEN'
}
```
Außerdem bereitstellen:
- zwei beschriftete Offline-Datenträger;
- eine neue Passphrase im Passwortmanager, zufällig mit mindestens 24 Zeichen.

`$O` und `Must` gelten für das ganze PowerShell-Fenster. Wird das Fenster neu geöffnet, die beiden ersten Zeilen von W0 erneut ausführen.

**W1 – CA erstellen** (Thomas; Passphrase zweimal bei der Erzeugung, einmal beim Selbstsignieren):
```powershell
& {
  if (Test-Path C:\S42-CA\dnsmanager-agent-ca.key) { throw 'ABBRUCH W1: CA-Schlüssel existiert bereits (nicht überschreiben)' }
  & $O genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -aes-256-cbc -out C:\S42-CA\dnsmanager-agent-ca.key; Must 'W1 genpkey'
  if (-not (Select-String -Path C:\S42-CA\dnsmanager-agent-ca.key -Pattern 'BEGIN ENCRYPTED PRIVATE KEY' -Quiet)) { throw 'ABBRUCH W1: Schlüssel nicht verschlüsselt' }
  & $O req -x509 -new -config C:\S42-CA\ca.cnf -key C:\S42-CA\dnsmanager-agent-ca.key -sha256 -days 1826 -out C:\S42-CA\dnsmanager-agent-ca.pem; Must 'W1 req'
  & $O x509 -in C:\S42-CA\dnsmanager-agent-ca.pem -noout -ext basicConstraints,keyUsage,nameConstraints -enddate -fingerprint -sha256; Must 'W1 Anzeige'
  'W1 BESTANDEN'
}
```
Fingerprint und Ablaufdatum der CA notieren. Der Schlüssel liegt ausschließlich verschlüsselt vor; beim Signieren wird er nur im Speicher entschlüsselt.

**W2 – Sicherung auf zwei Offline-Datenträgern** (Thomas; je Datenträger einmal, mit dessen Laufwerksbuchstaben):
```powershell
& {
  param([string]$M)
  New-Item -ItemType Directory $M -Force -ErrorAction Stop | Out-Null
  foreach ($f in 'dnsmanager-agent-ca.key', 'dnsmanager-agent-ca.pem', 'ca.cnf') {
    Copy-Item "C:\S42-CA\$f" $M -ErrorAction Stop
    if ((Get-FileHash "C:\S42-CA\$f" -ErrorAction Stop).Hash -ne (Get-FileHash "$M\$f" -ErrorAction Stop).Hash) { throw "ABBRUCH W2: Kopie $f auf $M weicht ab" }
  }
  & $O pkey -in "$M\dnsmanager-agent-ca.key" -noout; Must "W2 Wiederherstellungsprobe $M (Passphrase)"
  "W2 BESTANDEN für $M"
} 'E:\S42-CA'                                                              # zweiter Datenträger z. B. 'F:\S42-CA'
```
Danach den Datenträger sicher auswerfen. Die beiden Datenträger getrennt aufbewahren (Orte: Thomas). Die Passphrase liegt nur im Passwortmanager.

**N1 – Schlüssel und CSR je Server** (per SSH als root; jeder Server erzeugt seinen eigenen Schlüssel, nur der CSR verlässt den Host):
```bash
install -d -m 750 -o root -g root /etc/ssl/dnsmanager
( umask 077; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /etc/ssl/dnsmanager/agent.key )
openssl req -new -key /etc/ssl/dnsmanager/agent.key -subj "/O=Twilight-Networks/CN=$(hostname)" -out /root/$(hostname)-agent.csr
```

**W3 – CSRs übertragen und prüfen** (öffentliche Daten): `ns1-agent.csr` von ns1 und `ns2-agent.csr` von ns2 per `scp` nach `C:\S42-CA\csr\`.
```powershell
& {
  foreach ($c in 'ns1-agent.csr', 'ns2-agent.csr') {
    if (-not (Test-Path "C:\S42-CA\csr\$c")) { throw "ABBRUCH W3: $c fehlt" }
    & $O req -in "C:\S42-CA\csr\$c" -noout -verify -subject; Must "W3 $c"
  }
  'W3 BESTANDEN'
}
```
Die SANs werden nicht aus dem CSR übernommen, sondern ausschließlich in W4 festgelegt.

**W4 – Ausstellen** (Thomas; je Zertifikat einmal Passphrase). Aus der produktiven CA werden **nur die beiden Agent-Zertifikate** ausgestellt:
```powershell
& {
  function Sign([string]$Name, [string]$San) {
    if (Test-Path "C:\S42-CA\$Name.pem") { throw "ABBRUCH W4: $Name.pem existiert bereits" }
    "basicConstraints=critical,CA:FALSE`nkeyUsage=critical,digitalSignature,keyEncipherment`nextendedKeyUsage=serverAuth`nsubjectAltName=$San`nauthorityKeyIdentifier=keyid" |
      Set-Content -Encoding ascii "C:\S42-CA\$Name.ext" -ErrorAction Stop
    & $O x509 -req -in "C:\S42-CA\csr\$Name.csr" -CA C:\S42-CA\dnsmanager-agent-ca.pem -CAkey C:\S42-CA\dnsmanager-agent-ca.key `
      -CAserial C:\S42-CA\dnsmanager-agent-ca.srl -CAcreateserial -sha256 -days 365 -extfile "C:\S42-CA\$Name.ext" -out "C:\S42-CA\$Name.pem"
    Must "W4 $Name"
  }
  Sign ns1-agent 'IP:127.0.0.1,DNS:ns1,DNS:ns1.example.test'
  Sign ns2-agent 'IP:192.168.53.125,DNS:ns2,DNS:ns2.example.test'
  'W4 BESTANDEN'
}
```

**W5 – Prüfen und Seriennummer sichern** (ohne Geheimnisse; beide Datenträger eingesteckt, Laufwerksbuchstaben anpassen):
```powershell
& {
  param([string[]]$Media)
  foreach ($c in @(@('ns1-agent', '127.0.0.1'), @('ns2-agent', '192.168.53.125'))) {
    & $O verify -CAfile C:\S42-CA\dnsmanager-agent-ca.pem -no-CApath -no-CAstore -purpose sslserver -verify_ip $c[1] "C:\S42-CA\$($c[0]).pem"; Must "W5 verify $($c[0])"
    & $O x509 -in "C:\S42-CA\$($c[0]).pem" -noout -subject -ext subjectAltName -enddate -fingerprint -sha256; Must "W5 Anzeige $($c[0])"
  }
  foreach ($M in $Media) {
    Copy-Item C:\S42-CA\dnsmanager-agent-ca.srl $M -ErrorAction Stop
    if ((Get-FileHash C:\S42-CA\dnsmanager-agent-ca.srl).Hash -ne (Get-FileHash "$M\dnsmanager-agent-ca.srl" -ErrorAction Stop).Hash) { throw "ABBRUCH W5: Seriennummer-Datei auf $M weicht ab" }
  }
  'W5 BESTANDEN'
} -Media 'E:\S42-CA', 'F:\S42-CA'
```
Die Fingerprints der beiden Agent-Zertifikate werden für D4/D5 notiert.

**W6 – Übertragen** (öffentliche Daten, per `scp`):
- nach ns1 `/root/s42-in/`: `dnsmanager-agent-ca.pem`, `ns1-agent.pem`;
- nach ns2 `/root/s42-in/`: `ns2-agent.pem`.

Auf den Servern nach LF normalisieren und Fingerprints mit W5 vergleichen:
```bash
sed -i 's/\r$//' /root/s42-in/*.pem
for f in /root/s42-in/*.pem; do openssl x509 -in $f -noout -fingerprint -sha256; done
```
Danach weiter mit D1, mit den Dateien aus `/root/s42-in/`.

**W7 – Abschluss auf dem Windows-PC** (nach „W2 BESTANDEN“ für beide Datenträger und „W5 BESTANDEN“):
```powershell
Remove-Item C:\S42-CA\dnsmanager-agent-ca.key -ErrorAction Stop
```
Der CA-Schlüssel liegt dann nur noch verschlüsselt auf den beiden Datenträgern. Die übrigen Dateien in `C:\S42-CA` sind öffentlich und können als Arbeitskopie bleiben.

**Spätere Erneuerung:** Den Schlüssel von einem Datenträger nach `C:\S42-CA` kopieren, dann N1 und W3–W6 ausführen (bestehende `*.pem`/`*.ext` vorher umbenennen), die neue Seriennummer-Datei sichern (in W5 enthalten) und W7 wiederholen.

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
6. **Extensions der produktiven CA (E7)** auf ns1 mit `docs/agent-tls/s42-ca-ext-check.sh`:
   ```bash
   bash s42-ca-ext-check.sh /etc/dnsmanager/agent-ca.pem
   ```
   Das Skript prüft per Exit-Code:
   - die CA-Datei enthält genau ein Zertifikat;
   - `basicConstraints` „critical, CA:TRUE, pathlen:0“;
   - `keyUsage` „critical, Certificate Sign, CRL Sign“;
   - `nameConstraints` kritisch und **exakt** die sechs festgelegten Einträge (Abschnitt 5), ohne weitere;
   - das CA-Zertifikat ist selbstsigniert gültig.

   **Wirksamkeit der `nameConstraints`:** Mit Wegwerf-CA und dem echten PHP-cURL-Stack ist sie bereits nachgewiesen (Trockenlauf 2, `s42-nc-negative.sh`, einschließlich Gegenprobe ohne Constraints). Aus der produktiven CA werden dafür keine zusätzlichen Zertifikate ausgestellt.

   Gegenproben mit Wegwerf-CA auf ns1: breite Freigabe `.example.test`, zusätzlich erlaubte IP sowie zwei Zertifikate in der Datei → jeweils Exit 1; korrekte CA → Exit 0.
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

**G – Abnahme schreibend** (nur im Rahmen der gesondert freigegebenen Abnahme; Testzone `zone.example.test`, id 3, akzeptiert).

**G0 – Zustand unmittelbar vorher bestätigen (lesend):**
- `zone.example.test`: `changed = 0`, `allow_dyndns = 0`, keine DynDNS-Konten, Serial in der Datenbank = SOA auf ns1 und ns2 (`dig +short SOA zone.example.test @192.168.53.122` / `@192.168.53.125`);
- keine Delegation aus `example.test` (kein NS-Eintrag `zone` in `example.test`);
- keine andere Zone mit `changed ≠ 0`, `bind_dirty = 0`;
- `s42-publish-zone.php … --dry-run` (siehe G3) bricht nur mit „keine ausstehende Änderung“ ab, d. h. alle übrigen Prüfungen sind bestanden.

**G1 – BIND-Reload** je Server über die Oberfläche (Serverseite). Danach Systemstatus und Agent-Zugriffslog prüfen.

**G2 – TXT-Eintrag anlegen** über die Oberfläche: Zone `zone.example.test`, Name `s42-tls-test`, Typ TXT, Wert `"S42 TLS-Abnahme"`. Danach ist `changed = 1`. Keine weitere Änderung, kein „Publish All“.

**G3 – Einzelzonen-Publish** mit `docs/agent-tls/s42-publish-zone.php`:
```bash
install -d -m 750 -o root -g www-data /usr/local/lib/dnsmanager-s42
install -m 640 -o root -g www-data s42-publish-zone.php /usr/local/lib/dnsmanager-s42/
sudo -u www-data php /usr/local/lib/dnsmanager-s42/s42-publish-zone.php 3 zone.example.test --dry-run    # Exit 0
sudo -u www-data php /usr/local/lib/dnsmanager-s42/s42-publish-zone.php 3 zone.example.test --execute    # Exit 0 = published
```
Das Skript:
- ruft ausschließlich den vorhandenen `PublishService::publishZone()` auf, mit derselben Zonenlogik, Publish-Sperre (`dnsmanager_publish_all`, Wartezeit 5 s) und Flag-Verwaltung wie der DynDNS-Publish;
- führt kein Publish All aus: kein Orphan-Cleanup, kein `bind_dirty`-Neuaufbau, keine anderen Zonen.

**Autorisierung:**
- nur CLI;
- nur als `www-data`, wie Webserver und DynDNS-Publish; so entstehen keine root-eigenen Dateien;
- Plausibilitätsprüfung `SUDO_UID = 0` als Bedienschutz gegen versehentliche Aufrufe. Das ist **kein manipulationssicherer Nachweis**, dass der Aufruf von root stammt: Die Umgebungsvariable kann setzen, wer bereits als `www-data` Code ausführt. Das Skript gewährt `www-data` aber keine zusätzlichen Rechte, denn publizieren kann der Webserver-Benutzer über den `PublishService` ohnehin. Die Schutzgrenze ist die Betriebsweise (siehe unten);
- nur die fest eingetragene Testzone: ID **und** Name müssen in Skript, Argumenten und Datenbank übereinstimmen;
- Ausführung nur mit `--execute`.

**Betriebsweise:**
- Das Skript wird erst unmittelbar vor der freigegebenen Abnahme abgelegt, außerhalb des Webroots (`/usr/local/lib/dnsmanager-s42`).
- Der Administrator führt es per `sudo -u www-data` aus.
- Es ist kein dauerhafter, öffentlich oder regulär nutzbarer Bestandteil des DNS-Managers.
- Unmittelbar nach der Abnahme wird es wieder entfernt (G6).

**Vorprüfung vor jedem Lock:**
- Zone vorhanden, Name passt;
- keine DynDNS-Freigabe und keine DynDNS-Konten;
- die aktiven Ziele der Zone sind **genau** die Test-VM-Agenten `127.0.0.1` und `192.168.53.125`;
- keine weitere aktive Server-Adresse;
- Publish-Sperre frei;
- Änderung ausstehend.

Jede Abweichung führt zum Abbruch mit Exit 2, Aufruf- oder Autorisierungsfehler zu Exit 3.

**Produktionskonfiguration:** Sie wird nur gelesen (`inc/db.php` → `ui_config.php`). Es werden keine Konfigurationswerte, Tokens oder Zugangsdaten ausgegeben. Start und Ende werden über `appLog()` protokolliert.

**G4 – Prüfen:**
- `dig +short TXT s42-tls-test.zone.example.test` an `@192.168.53.122` und `@192.168.53.125` → `"S42 TLS-Abnahme"`;
- SOA-Serial auf beiden = Serial in der Datenbank (vom Skript ausgegeben);
- `changed = 0`;
- Agent-Zugriffslogs: `zone_sync`/`conf_sync` mit HTTP 200 auf beiden Servern, kein `zone_cleanup`.

**G5 – Wiederherstellen:**
1. TXT-Eintrag über die Oberfläche löschen.
2. G3 erneut ausführen (`--dry-run`, dann `--execute`).
3. Prüfen: `s42-tls-test` liefert auf beiden Servern NXDOMAIN bzw. keine Antwort; Serial auf beiden gleich und höher als vor G2; `changed = 0`.

**G6 – Aufräumen:**
```bash
rm -rf /usr/local/lib/dnsmanager-s42
```
Danach Systemstatus, Monitoring-Cron und PHP-Fehlerprotokoll prüfen.

**Isolierter Nachweis t72** (13.11.2026, ns1; eigene Installation aus `main` `407c6ae`, isolierte DB, Agent-Stubs auf `127.0.0.1`). Die Testkopie unterscheidet sich nur in `APP_ROOT` und `EXPECTED_AGENTS`.
- **Abgewiesen ohne jede Wirkung** auf Datenbank und Agenten:
  - mit Exit 3: falscher Zonenname, andere Zone, falsche Argumentzahl, Start als root, Start als `www-data` ohne `SUDO_UID = 0` (Bedienschutz);
  - mit Exit 2: fremdes Ziel, weiterer aktiver Server, DynDNS-Freigabe, belegte Publish-Sperre, keine ausstehende Änderung.
- **`--execute`:**
  - `published`, `changed` 1 → 0, Serial erhöht, Sperre erhalten und freigegeben;
  - je Agent genau `zone_sync` und `conf_sync` für `zone.example.test`, kein `zone_cleanup`;
  - eine weitere ausstehende Zone blieb unberührt;
  - kein Arbeitsverzeichnis übrig.
- **Lesender Probelauf des Originalskripts gegen die Produktionskonfiguration** (`--dry-run`):
  - Ziele `127.0.0.1` und `192.168.53.125` bestätigt;
  - keine weitere ausstehende Zone, Sperre frei;
  - Abbruch erwartungsgemäß mit „keine ausstehende Änderung“;
  - Produktionsdaten unverändert (Prüfsummen).

**Hintergrund Publish-Semantik** (`PublishService`): „Publish All“ verteilt nur Zonen mit ausstehender Änderung (`changed ≠ 0`) bzw. bei `bind_dirty` alle Zonen und führt nach Erfolg zusätzlich das Orphan-Cleanup aus. Deshalb wird für die Abnahme der Einzelzonen-Publish verwendet.

**Nicht geeignet als Testzone:**
- `example.test`: Glue-/Hosteinträge von ns1/ns2 sowie DynDNS-Konten `dyndnstest`/`n1race`;
- `53.168.192.in-addr.arpa`: PTR-Einträge der VMs.

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
- Schlüssel vom Datenträger nach `C:\S42-CA` kopieren, N1 (neuer Server-Schlüssel empfohlen), W3–W6, D1, D3–D5, Apache reload, W7. Die neue Seriennummer-Datei auf beide Datenträger sichern. An der Anwendung ist nichts zu ändern.
- Eine Änderung von `servers.api_ip` erfordert vorher ein neues Zertifikat mit passendem SAN und gegebenenfalls eine neue CA (`nameConstraints`).

**CA-Wechsel** (spätestens im 5. Jahr; isoliert getestet, Fall C07):
1. neue CA (W0–W2, neue Datenträgersicherung);
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
