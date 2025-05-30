#!/bin/bash
# fix_perms.sh
# Zweck: Korrigiert Dateiberechtigungen und Eigentümer im DNS-Manager-Verzeichnis.
#
# Details:
# - Verzeichnis wird sicher auf root:root gesetzt.
# - ui_config.php wird auf Webserver-User gesetzt, fix_perms.sh bleibt root.
# - Skript prüft, ob es als Root ausgeführt wird.
#
# Sicherheit:
# - Schutz sensibler Dateien.
# - Minimierung von Angriffsmöglichkeiten durch restriktive Rechte.
# - Verhindert fehlerhafte Ausführung ohne Root-Rechte.

WEBROOT="/usr/share/dnsmanager"
WEBSERVER_USER="www-data"
WEBSERVER_GROUP="www-data"

# Prüfen ob Skript als root ausgeführt wird
if [[ "$(id -u)" -ne 0 ]]; then
    echo "❌ Dieses Skript muss als root ausgeführt werden." >&2
    exit 1
fi

# Prüfen, ob Webroot existiert
if [[ ! -d "$WEBROOT" ]]; then
    echo "❌ Fehler: Webroot-Verzeichnis $WEBROOT existiert nicht." >&2
    exit 1
fi

echo "⚙️ Setze Berechtigungen im $WEBROOT ..."

# Eigentümer auf root:root setzen
chown -R root:root "$WEBROOT"

# Verzeichnisse auf 755 setzen
find "$WEBROOT" -type d -exec chmod 755 {} \;

# Dateien auf 644 setzen
find "$WEBROOT" -type f -exec chmod 644 {} \;

# Ausnahme: ui_config.php
chown "$WEBSERVER_USER:$WEBSERVER_GROUP" "/etc/dnsmanager/ui_config.php"
chmod 600 "/etc/dnsmanager/ui_config.php"

echo "✅ Berechtigungen erfolgreich angepasst."
