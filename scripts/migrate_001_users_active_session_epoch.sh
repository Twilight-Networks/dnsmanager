#!/bin/bash
# Datei: scripts/migrate_001_users_active_session_epoch.sh
# Zweck: Migration 001 (users.active, users.session_epoch) mit Preflight, Sicherung und Nachprüfung.
#
# Aufruf (als Benutzer mit DDL-Rechten auf die Datenbank, z. B. root über den MariaDB-Socket):
#   bash migrate_001_users_active_session_epoch.sh --db <name> --check
#   bash migrate_001_users_active_session_epoch.sh --db <name> --apply --backup-dir <verzeichnis>
#   bash migrate_001_users_active_session_epoch.sh --db <name> --apply --no-backup     (nur Testdatenbanken)
# Optional: --client-opts "<Optionen für mariadb/mysqldump>" (z. B. "--defaults-extra-file=/root/.my.cnf")
#
# Exitcodes:
#   0  Schema entspricht der Erwartung (nach --apply: Migration erfolgreich oder bereits vorhanden)
#   1  --check: Migration erforderlich (Spalten fehlen)
#   2  Abbruch: abweichende Spaltenstruktur, Preflight- oder Prüffehler (keine Änderung vorgenommen)
#   3  Fehler bei Sicherung oder Migration (Details in der Ausgabe; Wiederanlauf siehe README)
#
# Das Skript gibt keine Zugangsdaten oder Passwort-Hashes aus. Es ändert ausschließlich die Tabelle users
# über die Datei sql/migrations/001_users_active_session_epoch.sql.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SQL_FILE="$SCRIPT_DIR/../sql/migrations/001_users_active_session_epoch.sql"

DB=""; MODE=""; BACKUP_DIR=""; NO_BACKUP=0; CLIENT_OPTS=""
while [ $# -gt 0 ]; do
    case "$1" in
        --db) DB="${2:-}"; shift 2 ;;
        --check) MODE="check"; shift ;;
        --apply) MODE="apply"; shift ;;
        --backup-dir) BACKUP_DIR="${2:-}"; shift 2 ;;
        --no-backup) NO_BACKUP=1; shift ;;
        --client-opts) CLIENT_OPTS="${2:-}"; shift 2 ;;
        *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
    esac
done

fail() { echo "ABBRUCH: $*" >&2; exit "${2:-2}"; }

[ -n "$DB" ] || fail "--db fehlt"
[ -n "$MODE" ] || fail "--check oder --apply angeben"
[[ "$DB" =~ ^[A-Za-z0-9_]+$ ]] || fail "ungültiger Datenbankname"
[ -f "$SQL_FILE" ] || fail "Migrationsdatei fehlt: $SQL_FILE"
command -v mariadb >/dev/null 2>&1 || fail "mariadb-Client nicht gefunden"

# shellcheck disable=SC2086
q() { mariadb $CLIENT_OPTS -N -B -e "$1" 2>&1; }

# ---- Preflight ----
version="$(q 'SELECT VERSION()')" || fail "Datenbankverbindung fehlgeschlagen: $version"
case "$version" in *MariaDB*) ;; *) fail "MariaDB erforderlich (ADD COLUMN IF NOT EXISTS), gefunden: $version" ;; esac
exists="$(q "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$DB'")" || fail "Abfrage fehlgeschlagen: $exists"
[ "$exists" = "1" ] || fail "Datenbank $DB nicht vorhanden"
tbl="$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'users'")" || fail "Abfrage fehlgeschlagen: $tbl"
[ "$tbl" = "1" ] || fail "Tabelle $DB.users nicht vorhanden"

# Spaltenzustand: ok | absent | mismatch:<details>
column_state() {
    local col="$1" type="$2" default="$3" row
    row="$(q "SELECT CONCAT(LOWER(COLUMN_TYPE), '|', IS_NULLABLE, '|', IFNULL(TRIM(BOTH '''' FROM COLUMN_DEFAULT), 'NULL')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'users' AND COLUMN_NAME = '$col'")" || { echo "error:$row"; return; }
    if [ -z "$row" ]; then echo "absent"; return; fi
    if [ "$row" = "$type|NO|$default" ]; then echo "ok"; else echo "mismatch:$row (erwartet $type|NO|$default)"; fi
}
state_all() {
    S_ACTIVE="$(column_state active 'tinyint(1)' 1)"
    S_EPOCH="$(column_state session_epoch 'bigint(20) unsigned' 0)"
    echo "Spaltenzustand $DB.users: active=$S_ACTIVE session_epoch=$S_EPOCH"
}

state_all
case "$S_ACTIVE$S_EPOCH" in *error:*|*mismatch:*) fail "abweichende oder nicht prüfbare Spaltenstruktur – keine Änderung vorgenommen" ;; esac

if [ "$MODE" = "check" ]; then
    if [ "$S_ACTIVE" = "ok" ] && [ "$S_EPOCH" = "ok" ]; then echo "OK: Schema entspricht Migration 001"; exit 0; fi
    echo "Migration 001 erforderlich"; exit 1
fi

# ---- apply ----
if [ "$S_ACTIVE" = "ok" ] && [ "$S_EPOCH" = "ok" ]; then
    echo "Nichts zu tun: Migration 001 bereits vollständig vorhanden (Wiederanlauf ohne Änderung)"
    exit 0
fi

users_before="$(q "SELECT COUNT(*) FROM \`$DB\`.users")" || fail "Zählung fehlgeschlagen: $users_before"

if [ "$NO_BACKUP" -eq 0 ]; then
    [ -n "$BACKUP_DIR" ] || fail "--backup-dir angeben (oder --no-backup nur für Testdatenbanken)"
    mkdir -p "$BACKUP_DIR" || fail "Sicherungsverzeichnis nicht anlegbar" 3
    chmod 700 "$BACKUP_DIR" || fail "Rechte des Sicherungsverzeichnisses nicht setzbar" 3
    file="$BACKUP_DIR/${DB}-vor-migration-001-$(date +%Y%m%d-%H%M%S).sql"
    # shellcheck disable=SC2086
    ( umask 077; mysqldump $CLIENT_OPTS --single-transaction --routines --triggers "$DB" > "$file" ) || fail "Sicherung fehlgeschlagen" 3
    grep -q 'CREATE TABLE `users`' "$file" || fail "Sicherung unvollständig (users fehlt): $file" 3
    echo "Sicherung: $file ($(wc -c < "$file") Bytes)"
else
    echo "Hinweis: ohne Sicherung (--no-backup)"
fi

# shellcheck disable=SC2086
out="$(mariadb $CLIENT_OPTS "$DB" < "$SQL_FILE" 2>&1)" || { echo "$out" >&2; fail "Migration fehlgeschlagen (ALTER ist atomar; erneut ausführbar)" 3; }

# ---- Nachprüfung ----
state_all
[ "$S_ACTIVE" = "ok" ] && [ "$S_EPOCH" = "ok" ] || fail "Nachprüfung: Spaltenstruktur nicht wie erwartet" 3
users_after="$(q "SELECT COUNT(*) FROM \`$DB\`.users")"
inactive="$(q "SELECT COUNT(*) FROM \`$DB\`.users WHERE active <> 1")"
[ "$users_after" = "$users_before" ] || fail "Nachprüfung: Anzahl Benutzer $users_before → $users_after" 3
echo "Benutzer: $users_after (unverändert), deaktiviert: $inactive"
echo "OK: Migration 001 angewendet"
exit 0
