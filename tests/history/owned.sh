# Datei: tests/history/owned.sh (wird von den Bash-Suiten eingebunden: source owned.sh)
# Zweck: Eigentumsnachweis für Testressourcen der History-Tests (AP1/H2a, Review S2).
#
# - Lauf-Kennung H2A_RUN (8 Hex-Zeichen; von run.sh vorgegeben, sonst je Suite zufällig) ist Teil jedes Namens:
#   Datenbanken dnsmgr_t21_r<RUN>_<suffix>, DB-Benutzer h2a<RUN>.
# - Eigentum entsteht nur durch erfolgreiches Anlegen: CREATE DATABASE bzw. CREATE USER ohne IF NOT EXISTS (scheitert,
#   wenn der Name bereits existiert), erst danach Eintrag ins Register der Suite und ggf. ins gemeinsame Register des
#   Laufs (H2A_REGISTRY, von run.sh gesetzt).
# - Aufgeräumt werden ausschließlich registrierte Ressourcen. Fremde Datenbanken oder Benutzer – auch solche, die
#   während des Laufs mit ähnlichem oder gleichem Präfix entstehen – werden nie entfernt.

H2A_RUN="${H2A_RUN:-$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
[[ "$H2A_RUN" =~ ^[0-9a-f]{8}$ ]] || { echo "ABBRUCH: ungültige Lauf-Kennung H2A_RUN"; exit 2; }
H2A_PREFIX="dnsmgr_t21_r${H2A_RUN}_"
H2A_USER="h2a${H2A_RUN}"
OWNED=""

# owned_init <Registerdatei der Suite>
owned_init() { OWNED="$1"; : > "$OWNED"; chmod 600 "$OWNED"; }

owned_register() { # <db|user> <Name>
    echo "$1 $2" >> "$OWNED"
    if [ -n "${H2A_REGISTRY:-}" ]; then echo "$1 $2" >> "$H2A_REGISTRY"; fi
}

# owned_create_db <Name>: legt die Datenbank an (Fehler, falls vorhanden) und registriert sie
owned_create_db() {
    [[ "$1" =~ ^dnsmgr_t21_r[0-9a-f]{8}_[a-z0-9_]+$ ]] || { echo "ABBRUCH: Datenbankname $1 außerhalb des Laufpräfixes" >&2; return 1; }
    mariadb -e "CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || { echo "ABBRUCH: Datenbank $1 nicht anlegbar (vorhanden?) – nicht übernommen" >&2; return 1; }
    owned_register db "$1"
}

# owned_create_user <Name> <Passwort>: legt den Benutzer @localhost an (Fehler, falls vorhanden) und registriert ihn
owned_create_user() {
    mariadb -e "CREATE USER '$1'@'localhost' IDENTIFIED BY '$2'" || { echo "ABBRUCH: Benutzer $1 nicht anlegbar (vorhanden?) – nicht übernommen" >&2; return 1; }
    owned_register user "$1"
}

# owned_cleanup [Registerdatei]: entfernt ausschließlich die registrierten Ressourcen
owned_cleanup() {
    local reg="${1:-$OWNED}" kind name
    [ -n "$reg" ] && [ -f "$reg" ] || return 0
    while read -r kind name; do
        case "$kind" in
            db)   [[ "$name" =~ ^dnsmgr_t21_r[0-9a-f]{8}_[a-z0-9_]+$ ]] && mariadb -e "DROP DATABASE IF EXISTS \`$name\`" ;;
            user) [[ "$name" =~ ^h2a[0-9a-f]{8}$ ]] && mariadb -e "DROP USER IF EXISTS '$name'@'localhost'" ;;
        esac
    done < "$reg"
}
