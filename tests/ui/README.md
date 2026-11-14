# UI-Golden-Basis (U0)

Versionierte Regressionsbasis für die UI-Modernisierung (Pakete U1–U6). Sie hält das Verhalten der Weboberfläche des
Stands `main` `1e37515` fest und weist bei jedem UI-PR nach, dass Web-Verträge und Berechtigungen unverändert bleiben.

## Was geprüft wird

| Bereich | Fälle | Vertrag (Abweichung = Fehler) | Nur informativ |
|---|---|---|---|
| Seiten (GET) je Rolle anonym, admin, zoneadmin | 23 Seiten × 3 Rollen | HTTP-Status, Weiterleitung, Sicherheitsheader, Logzeilen, PHP-Meldungen, **Seitenvertrag**: alle Formulare mit Methode, Ziel, Kodierung (`enctype`; ohne Attribut `application/x-www-form-urlencoded`) und Feldnamen/-typen sowie alle Linkziele (`href` in doppelten oder einfachen Anführungszeichen) | normalisierter Seiteninhalt (Prüfsumme, Zeilenzahl, Titel) |
| Actions | 40 Fälle für alle 20 Actions | Status, Weiterleitung, Header, Toasts, Logzeilen, **Datenbankzustand**, PHP-Meldungen, Fehlertext | – |
| Login/Logout | 7 Fälle | Status, Weiterleitung, Hinweistexte, Session-Benutzername, Folgeanfrage, Logzeilen, Datenbankzustand | – |
| DynDNS-API | 2 Fälle | Status, Antwort (`good`/`badauth`), Logzeilen, Datenbankzustand | – |

Der Seiteninhalt ändert sich mit der Modernisierung beabsichtigt und wird deshalb nur gemeldet. Formularziele,
Formularkodierung, Feldnamen, Linkziele, Zugriffsschutz und das Verhalten der Actions sind dagegen Vertrag: Abweichungen müssen je
Fall-ID in einer Ausnahmedatei freigegeben werden (siehe unten) und sind damit im PR dokumentiert.

**Formulare** werden wie von einem Browser **ohne JavaScript** abgesendet: Felder aus der ausgelieferten Seite
übernehmen (Checkbox/Radio nur angehakt, Select mit gewählter bzw. erster Option, deaktivierte Felder nicht) und nur
die im Fall genannten Werte setzen. Für Fälle ohne zugängliches Formular (fehlende Berechtigung, Löschknöpfe) werden
die Felder direkt mit dem CSRF-Token der Session gesendet. Ein Fall (`record/add_admin_txt`) bildet das Verhalten von
`record_add_form.js` nach; der Gegenfall `record/add_admin_txt_ohne_js` hält den Ist-Zustand ohne JavaScript fest.

## Testumgebung

- **System:** dedizierte Test-VM ns1 (192.168.53.122), als root. Getestet mit PHP 8.5.4 (php-cgi) und MariaDB 11.8.6.
- **Voraussetzungen:** `php`, `php-cgi`, `sudo`, `python3`, `openssl`, `git`, `mariadb` mit Root-Zugriff über den
  Unix-Socket, Lesezugriff auf GitHub, `127.0.0.2:443` und `127.0.0.3:443` frei, für Screenshots zusätzlich
  `127.0.0.1:18601–18603` frei.
- **Isolation:**
  - eigener Klon und `git archive` des zu prüfenden Commits nach `/tmp/dnsmgr-uig/app`;
  - Konfiguration aus `web/config/ui_config-sample.php`;
  - eigene Datenbanken `dnsmgr_uig_tpl`/`dnsmgr_uig_app` und eigener DB-Benutzer `uigweb`;
  - Log in eine Datei, Sprache `de`;
  - die installierte Anwendung unter `/usr/share/dnsmanager`, ihre Datenbank `dnsmanager` und `/etc/dnsmanager` werden nicht berührt.
- **Anpassungen nur in der Testkopie:** Die Update-Quelle (`CurlUpdateFeed`) zeigt auf eine lokale Datei. Agent-Aufrufe
  gehen an Platzhalter auf `127.0.0.2`/`127.0.0.3:443` (`agent_stub.py`, immer Erfolg), nie an echte Agenten.
- **Serverweite Sperre (F7):** Die Publish-/Topologie-Fälle belegen `dnsmanager_publish_all` jeweils nur kurz. `run.sh`
  bricht ab, wenn die Sperre beim Start belegt ist.
- **Testdaten:** `fixture.php` (Dokumentationsbereiche `.test`, `192.0.2.0/24`, `2001:db8::/32`). Passwörter werden je
  Lauf zufällig erzeugt und nur in `/tmp/dnsmgr-uig/creds.php` (0600) abgelegt; sie erscheinen in keinem Ergebnis.
- **Normalisierung:**
  - CSRF-Token, Zeitstempel und Session-Zeiten;
  - Passwort-Hashes, Tokens und SOA-Serials nur als „wie Fixture“/„neu“;
  - Zeitstempelspalten entfallen;
  - Asset-Versionen (`?v=`) und Pfade der Testkopie.

  Zwei Läufe desselben Commits sind dadurch identisch.

## Reproduzierbarer Lauf

Harness immer aus einem festen Commit dieses Repositorys verwenden. Die Anwendung wird über ihren vollen SHA
angegeben:

```bash
git clone -q https://github.com/Twilight-Networks/dnsmanager.git /root/dnsmgr-uig-harness
git -C /root/dnsmgr-uig-harness checkout -q --detach <Harness-SHA>
cd /root/dnsmgr-uig-harness/tests/ui

# Basis erzeugen (Ergebnis unter /root/dnsmgr-uig-results/)
bash run.sh record <App-SHA>

# Gegen die versionierte Basis vergleichen (Exit 0 = keine Vertragsabweichung)
bash run.sh compare <App-SHA> baseline/golden-1e37515.json [ausnahmen.json]
```

`run.sh` räumt am Ende vollständig auf: Datenbanken, DB-Benutzer, Platzhalter, `/tmp/dnsmgr-uig`. Die Ergebnisdateien
bleiben unter `OUT_DIR` (Standard `/root/dnsmgr-uig-results`, 0700/0600).

**Ausnahmedatei für beabsichtigte Abweichungen** (je UI-PR, Fall-ID → Liste der freigegebenen Schlüssel):

```json
{ "page/admin/zones": ["contract"], "record/add_admin_txt_ohne_js": ["toasts", "log", "db"] }
```

Jede Ausnahme braucht im PR eine Fall-ID-genaue Begründung und die Freigabe im Review.

## Screenshots

1. Auf ns1: `MAX_MINUTES=30 bash run.sh serve <App-SHA>`.
   - Startet die Testkopie je Rolle mit dem PHP-Entwicklungsserver ausschließlich auf `127.0.0.1`: admin 18601, zoneadmin 18602, anonym 18603.
   - Zusätzlich je Rolle ein Server **ohne JavaScript** (18611–18613): Der Router entfernt alle `<script>`-Elemente (`UIG_NOJS=1`), der Browser rendert die Seite wie mit abgeschaltetem JavaScript. Edge headless erzeugt mit abgeschaltetem JavaScript selbst keine Screenshots.
   - Die Sessions entstehen per regulärem Login. Der Router `serve_router.php` setzt die Session-ID serverseitig, weil das Session-Cookie `Secure` ist.
2. Auf dem Windows-Arbeitsplatz:

   ```bash
   powershell -File tests/ui/screenshots.ps1 -SshTarget root@192.168.53.122 -KeyFile $HOME/.ssh/claude_dnsmanager_test -OutDir docs/ui/screenshots/u0
   ```

   Das Skript nutzt einen SSH-Tunnel und Edge headless mit temporärem Profil. Es gibt keine Anmeldedaten im Browser.
   - `-ColorScheme dark`: Farbschema-Vorgabe des Browsers dunkel (Dateisuffix `-dark`);
   - `-NoJs`: Server ohne JavaScript (Suffix `-nojs`);
   - `-Only name1,name2`: nur ausgewählte Aufnahmen.

   Mobil-Aufnahmen entstehen mit 492 CSS-Pixeln Breite. Das ist die Mindestbreite von Edge headless; schmalere Fenster beschneiden nur das Bild. Die Breite liegt im selben Bootstrap-Bereich (xs, unter 576 px) wie ein 390-px-Telefon. Echte 375 px werden im Browser geprüft.
3. Beenden auf ns1 mit `touch /tmp/dnsmgr-uig/stop`; danach wird wie üblich aufgeräumt.

## CSP Report-Only (U2)

`UIG_CSP_REPORT_ONLY=1 MAX_MINUTES=30 bash run.sh serve <App-SHA>` aktiviert `CSP_REPORT_ONLY` **ausschließlich in der
generierten Konfiguration der Testkopie** unter `/tmp/dnsmgr-uig`; die installierte Anwendung bleibt unberührt.
- Die Seiten senden dann `Content-Security-Policy-Report-Only` mit der Zielrichtlinie, und Verstoßberichte gehen an
  `csp_report.php` der Testkopie (Log `event=csp_violation`).
- Nur im Modus `serve` zulässig; Golden-Läufe (`record`/`compare`) laufen ohne CSP-Header, damit der Headervertrag
  unverändert bleibt.
- Beim Beenden werden die CSP-Logzeilen nach `$OUT_DIR/csp-<SHA>-<Zeit>.log` gesichert und gezählt; der
  Ratenbegrenzungsstatus des Endpunkts (`/tmp/dnsmanager-csp-report-*.json`) wird entfernt.

## Rollenmatrix

```bash
php matrix.php /root/dnsmgr-uig-results/<ergebnis>.json
```

Die Ausgabe ist die Grundlage von `docs/UI_ROLE_MATRIX.md`.

## Grenzen

- Kein JavaScript: Modals, Offcanvas, clientseitige Prüfungen und dynamische Felder werden nicht ausgeführt. Geprüft
  wird bewusst der serverseitige Rückfall (Vorgabe der UI-Modernisierung). JS-Verhalten wird bei Bedarf wie im
  TXT-Fall ausdrücklich nachgebildet.
- Optik, Layout, Barrierefreiheit und CSP-Verstöße sind nicht Teil dieser Basis. Dafür dienen die Screenshots, die
  Sichtprüfung durch Thomas und die Prüfungen der jeweiligen Pakete (ab U2 CSP Report-Only).
- Keine Nebenläufigkeits- oder Lasttests.
