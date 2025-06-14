# DNS-Manager

**Produktiv im Einsatz. Aktiv in Entwicklung.**

Der DNS-Manager verwaltet Domains über BIND9 – mit validierter Zonenverteilung, API-basierter Agentenanbindung und einem sauberen Webinterface. Dieses Projekt richtet sich an Administratoren, die mehr wollen als Textdateien und weniger als eine Cloud-Appliance.


## Übersicht

Der **DNS-Manager** ist eine moderne Verwaltungsoberfläche zur Pflege von DNS-Zonen und Resource Records – speziell für Umgebungen mit BIND9.
Die Architektur besteht aus zwei Komponenten:

   - einer Weboberfläche (UI), mit der Zonen, Records, Benutzer und Server verwaltet werden
   - einem Agenten (API), der auf jedem zu verwaltenden Nameserver installiert ist

Der Datenaustausch erfolgt ausschließlich per REST-API – auch auf dem Server, auf dem die Weboberfläche selbst läuft.
Das bedeutet: Auch der Webinterface-Host benötigt den DNS-Manager-Agenten – mit api_ip = 127.0.0.1.

Die Anwendung wird entwickelt, um den Einsatz von BIND in produktiven Umgebungen sicherer, übersichtlicher und benutzerfreundlicher zu gestalten.

Entwickelt von **Twilight-Networks**, 2025.

---

## Architektur

| Komponente            | Aufgabe                                               | Installation auf                                    |
| --------------------- | ----------------------------------------------------- | --------------------------------------------------- |
| **Webinterface (UI)** | Verwaltung von Zonen, Benutzern, Servern              | Nur 1× zentral                                      |
| **Agent (API)**       | Empfängt und verarbeitet Zonendateien & Konfiguration | Auf **jedem** BIND-Server, ggf. auf dem UI-Host selbst |


## Features

### Zonen- und DNS-Record-Verwaltung
- Unterstützung für alle gängigen Record-Typen (A, AAAA, MX, CNAME, TXT, PTR, NS, SRV, NAPTR, SPF, DKIM u. a.)
- Unterstützung von RFC-konformen Spezialtypen wie `URI`, `LOC` und zukünftiger DNSSEC-Unterstützung
- Automatische PTR-Erzeugung bei Forward-Einträgen (optional)
- Unterstützung für Reverse-Zonen mit flexiblem Präfix
- Automatische Prüfung auf Duplikate und formatbedingte Konflikte

### Benutzer- und Rechteverwaltung
- Rollenmodell: `admin` und `zoneadmin`
- Zonen können gezielt einzelnen Benutzern zugewiesen werden
- Änderung von Passwörtern über GUI (auch self-service möglich)

### Zonenveröffentlichung & BIND-Integration
- Zonendateien und BIND-Konfiguration werden aus der Datenbank generiert
- Zonen-Validierung über `named-checkzone` direkt in der Applikation
- Änderungen werden erst übernommen, wenn sie validiert sind
- Verteilung der Zonendateien an alle Nameserver ausschließlich über REST-API (kein lokales Shell-Handling)
- BIND-Reload mit Rückmeldung direkt aus dem Interface

### DynDNS-Support
- Unterstützung für dynamische DNS-Updates über einen eigenen API-Endpunkt (`/api/v1/dyndns/update.php`)
- Kompatibel mit gängigen Routern wie AVM FRITZ!Box, DD-WRT, OpenWRT u. a.
- Verwaltung von DynDNS-Accounts inklusive Subdomain- und IP-Version-Restriktion (IPv4/IPv6)
- Automatische Aktualisierung existierender A- und AAAA-Records oder Neuanlage bei Bedarf
- Zugriff durch Basic Auth mit passwortgeschütztem DynDNS-Benutzer
- Pro Zone separat aktivierbar (Checkbox „DynDNS erlaubt“)

### Sicherheit & Integrität
- Alle schreibenden Operationen erfolgen transaktionssicher (ACID)
- Es können keine ungültigen oder nicht RFC-konformen Zonen geschrieben werden
- Schutz vor inkonsistenter Konfiguration durch Vorab-Prüfung aller Eingaben
- Optionaler Support für Fail2Ban durch Logging fehlgeschlagener Loginversuche

### System- und Statusübersicht
- Anzeige von Systemparametern (z. B. PHP-Version, Dateiberechtigungen, Modulstatus)
- Live-Zonenstatus mit Prüfungen der Konfiguration und Zonendateien
- Diagnose von Remote-Servern und REST-Erreichbarkeit

### UX & Oberfläche
- Responsives, modernes Layout auf Basis von Bootstrap 5
- Mehrsprachigkeit (i18n) - Deutsch, Englisch, Spanisch
- Klar strukturiertes Layout mit flexibler Navigation für Zonen, Records, Benutzer und Server
- REST-konforme API-Struktur (interner Einsatz für Agentenkommunikation)

### Geplant
- DNSSEC-Unterstützung

---

## Systemvoraussetzungen

- **PHP** ≥ 8.1
- **MariaDB** oder **MySQL**-Datenbank
- **Apache2** oder **Nginx** Webserver
- **bind9-utils** (für `named-checkzone`, **auf DNS-Manager-UI zwingend erforderlich**)
- **BIND9** DNS-Server (auf Zielservern)
- **Fail2Ban** (optional für zusätzliche Sicherheit)

---

## Changelog

Die Änderungen pro Version finden Sie in [CHANGELOG.md](CHANGELOG.md).

---

## Installation

🔗 **Zur vollständigen Anleitung inkl. Screenshots, Zonen & Delegationstests:**<br>
[Eigene Domain hosten mit DNS-Manager](https://www.twilight-networks.com/docs/domains_hosten_mit_dnsmanager/)

<br>

### Installation Webinterface (DNS-Manager-UI)

1. **Benötigte Software installieren:**

   Damit das Webinterface und die Zonendatei-Validierung korrekt funktionieren, müssen folgende Pakete und PHP-Module (≥ 8.1) auf dem UI-Server installiert sein:

   ```bash
   apt install bind9-utils
   apt install php8.4 php8.4-cli php8.4-common php8.4-mysql php8.4-mbstring php-json
   ```

2. **Projekt entpacken und platzieren:**

   ```bash
   git clone https://github.com/Twilight-Networks/dnsmanager.git /usr/share/dnsmanager
   ```

3. **Symlink zum Webverzeichnis erstellen:**

   ```bash
   ln -s /usr/share/dnsmanager/web/ /var/www/html/dnsmanager-ui
   ```

4. **Konfigurationsdatei vorbereiten:**

   ```bash
   mkdir /etc/dnsmanager
   cp /usr/share/dnsmanager/web/config/ui_config-sample.php /etc/dnsmanager/ui_config.php
   ln -s /etc/dnsmanager/ui_config.php /usr/share/dnsmanager/web/config/ui_config.php
   chown root:www-data /etc/dnsmanager/ui_config.php
   chmod 640 /etc/dnsmanager/ui_config.php
   ```

5. **Cronjob für Monitoring aktivieren:**

   ```bash
   cp /usr/share/dnsmanager/cron/dnsmanager_monitoring /etc/cron.d/
   ```

6. **Apache-Konfiguration einbinden:**

   ```bash
   cp /usr/share/dnsmanager/apache/dnsmanager.conf /etc/apache2/conf-available/
   a2enconf dnsmanager
   systemctl reload apache2
   ```

7. **Skript für Dateiberechtigungen ausführbar machen und ausführen:**

   ```bash
   chmod +x /usr/share/dnsmanager/scripts/fix_perms.sh
   /usr/share/dnsmanager/scripts/fix_perms.sh
   ```

8. **Datenbank importieren:**

   ```bash
   mysql -u root < /usr/share/dnsmanager/sql/dnsmanager_schema.sql
   ```

9. **Datenbankbenutzer erstellen und Berechtigungen setzen:**

   ```sql
   CREATE USER 'dnsmanager'@'localhost' IDENTIFIED BY 'sicheres_passwort';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER
   ON dnsmanager.* TO 'dnsmanager'@'localhost';
   FLUSH PRIVILEGES;
   ```

10. **Konfigurationsdatei anpassen:**

   Datenbank-Einstellungen ergänzen:

   ```bash
   nano /etc/dnsmanager/ui_config.php
   ```

11. **`sudo`-Rechte für benötigte BIND-Kommandos einrichten:**

   ```bash
   cp /usr/share/dnsmanager/sudoers.d/dnsmanager-ui /etc/sudoers.d/
   chmod 0440 /etc/sudoers.d/dnsmanager-ui
   ```

12. **Webinterface aufrufen und anmelden:**

    ```
    https://example.tld/dnsmanager-ui
    ```

    Melde Dich mit den Standard-Zugangsdaten an:

    - **Benutzername:** `admin`
    - **Passwort:** `admin123`

    > 🔐 Ändere das Passwort des Admin-Kontos nach dem ersten Login umgehend.

---

### Installation Agent (DNS-Manager-API)

1. **Benötigte Software installieren:**

   ```bash
   apt install bind9
   ```

2. **Projekt entpacken und platzieren:**

   ```bash
   git clone https://github.com/Twilight-Networks/dnsmanager.git /usr/share/dnsmanager
   ```

3. **Symlink zum Agentenverzeichnis erstellen:**

   ```bash
   ln -s /usr/share/dnsmanager/agent/ /var/www/html/dnsmanager-agent
   ```

4. **Konfigurationsdatei vorbereiten:**

   ```bash
   mkdir /etc/dnsmanager
   cp /usr/share/dnsmanager/agent/config/api_config-sample.php /etc/dnsmanager/api_config.php
   ln -s /etc/dnsmanager/api_config.php /usr/share/dnsmanager/agent/config/api_config.php
   chown root:www-data /etc/dnsmanager/api_config.php
   chmod 640 /etc/dnsmanager/api_config.php
   ```

5. **API-Token anpassen:**

   ```bash
   cp /usr/share/dnsmanager/agent/config/api_tokens-sample.php /etc/dnsmanager/api_tokens.php
   ln -s /etc/dnsmanager/api_tokens.php /usr/share/dnsmanager/agent/config/api_tokens.php
   nano /etc/dnsmanager/api_tokens.php
   chown root:www-data /etc/dnsmanager/api_tokens.php
   chmod 640 /etc/dnsmanager/api_tokens.php
   ```

6. **Apache-Konfiguration einbinden:**

   ```bash
   cp /usr/share/dnsmanager/apache/dnsmanager-agent.conf /etc/apache2/conf-available/
   a2enconf dnsmanager-agent
   systemctl reload apache2
   ```

7. **`sudo`-Rechte für benötigte BIND-Kommandos einrichten:**

   ```bash
   cp /usr/share/dnsmanager/sudoers.d/dnsmanager-agent /etc/sudoers.d/
   chmod 0440 /etc/sudoers.d/dnsmanager-agent
   ```

8. **Ordner für Zonendateien erstellen und Rechte setzen:**

   ```bash
   mkdir /etc/bind/zones
   chown www-data:www-data /etc/bind/zones
   ```

9. **BIND-Zonenkonfiguration einbinden:**

   ```bash
   nano /etc/bind/named.conf.local
   ```

   Füge am Ende folgende Zeile ein:

   ```
   include "/etc/bind/zones/zones.conf";
   ```

10. **Bind neustarten**

   ```bash
   systemctl reload bind9
   ```

---

## Lizenz

Dieses Projekt steht unter der [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html).
Details siehe [LICENSE](LICENSE).

> Hinweis: Dieses Projekt verwendet zusätzlich Drittanbieter-Komponenten mit abweichenden Lizenzen. Siehe Abschnitt „Drittanbieter-Komponenten“.

---

## Drittanbieter-Komponenten

Diese Software verwendet folgende Drittanbieter-Komponenten:

- **PHPMailer**
  E-Mail-Versand-Bibliothek für Monitoring-Benachrichtigungen
  © 2001–2024 The PHPMailer Authors
  Lizenz: [GNU Lesser General Public License v2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html)
  Quelle: [https://github.com/PHPMailer/PHPMailer](https://github.com/PHPMailer/PHPMailer)

Die PHPMailer-Komponenten befinden sich unter `/web/vendor/phpmailer` und wurden manuell eingebunden.

---

## Sicherheitshinweis

- Nur `/web` bzw. `/agent` darf vom Webserver erreichbar sein.

---

## Autor

- **Twilight Networks**
- Kontakt:  ✉️ [twilight@twlnet.com](mailto:twilight@twlnet.com)
- Web:      🌐 [www.twilight-networks.com](https://www.twilight-networks.com/)
