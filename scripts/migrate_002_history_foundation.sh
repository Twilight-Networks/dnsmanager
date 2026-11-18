#!/bin/bash
# Datei: scripts/migrate_002_history_foundation.sh
# Zweck: Migration 002 (History-Grundtabellen, AP1/H2a) mit Preflight, Sicherung und Nachprüfung.
#
# Aufruf (als Benutzer mit DDL-Rechten auf die Datenbank, z. B. root über den MariaDB-Socket):
#   bash migrate_002_history_foundation.sh --db <name> --check
#   bash migrate_002_history_foundation.sh --db <name> --apply --backup-dir <verzeichnis>
#   bash migrate_002_history_foundation.sh --db <name> --apply --no-backup     (nur isolierte Testdatenbanken
#                                                                             dnsmgr_tNN_*, technisch erzwungen)
# Optional: --client-opts "<Optionen für mariadb/mysqldump>" (z. B. "--defaults-extra-file=/root/.my.cnf")
#
# Exitcodes:
#   0  Schema entspricht der Erwartung (nach --apply: Migration erfolgreich oder bereits vorhanden)
#   1  --check: Migration erforderlich (mindestens eine Tabelle fehlt, keine abweichend)
#   2  Abbruch vor jeder Änderung: abweichende Struktur einer vorhandenen History-Tabelle, Preflight- oder Prüffehler
#   3  Fehler bei Sicherung, DDL-Ausführung oder Nachprüfung (Wiederanlauf siehe README)
#
# Ablauf (E26):
#   1. Preflight: Alle vier History-Tabellen werden vollständig gegen die Erwartung aus
#      web/inc/app/SchemaCheck.php geprüft (über web/scripts/schema_history-cli.php), BEVOR eine DDL-Anweisung
#      ausgeführt wird. Jede vorhandene, aber abweichende Tabelle ⇒ Exit 2 ohne Änderung.
#   2. Sicherung (vollständiger mysqldump, 600 in einem 700-Verzeichnis).
#   3. DDL: sql/migrations/002_history_foundation.sql (CREATE TABLE IF NOT EXISTS in Abhängigkeitsreihenfolge,
#      jede Anweisung atomar). Bricht die Ausführung technisch ab, bleiben bereits angelegte Tabellen bestehen; der
#      Wiederanlauf prüft sie im Preflight und legt nur die fehlenden an (deterministisch, Exit 3 bis dahin).
#   4. Nachprüfung: alle vier Tabellen exakt wie erwartet; Zeilenzahl bereits vorhandener History-Tabellen
#      unverändert. Prüfsummen der Fachtabellen werden vorher/nachher nur informativ ausgegeben: Die Migration
#      schreibt keine Fachtabellen; eine Abweichung stammt von unabhängigen Schreibvorgängen im laufenden Betrieb.
#
# Das Skript gibt keine Zugangsdaten oder Passwort-Hashes aus. Es ändert keine bestehenden Tabellen und löscht nichts.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SQL_FILE="$SCRIPT_DIR/../sql/migrations/002_history_foundation.sql"
HELPER="$SCRIPT_DIR/../web/scripts/schema_history-cli.php"
HISTORY_TABLES="history_change_sets zone_snapshots zone_revisions zone_history_state"
BUSINESS_TABLES="zones,records,zone_servers,servers,users,user_zones,dyndns_accounts,system_status"

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
# --no-backup ist technisch auf isolierte Testdatenbanken beschränkt (Namensschema dnsmgr_tNN_…); geprüft
# vor jedem Datenbankzugriff. Für jede andere Datenbank (z. B. dnsmanager) ist eine Sicherung Pflicht.
if [ "$NO_BACKUP" -eq 1 ] && ! [[ "$DB" =~ ^dnsmgr_t[0-9]{2}_[a-z0-9_]+$ ]]; then
    fail "--no-backup ist nur für isolierte Testdatenbanken (dnsmgr_tNN_*) zulässig, nicht für $DB – keine Verbindung, keine Änderung"
fi
[ -f "$SQL_FILE" ] || fail "Migrationsdatei fehlt: $SQL_FILE"
[ -f "$HELPER" ] || fail "Prüfwerkzeug fehlt: $HELPER"
command -v mariadb >/dev/null 2>&1 || fail "mariadb-Client nicht gefunden"
command -v php >/dev/null 2>&1 || fail "php (CLI) nicht gefunden"

# shellcheck disable=SC2086
q() { mariadb $CLIENT_OPTS -N -B -e "$1" 2>&1; }

# ---- Preflight: Umgebung ----
version="$(q 'SELECT VERSION()')" || fail "Datenbankverbindung fehlgeschlagen: $version"
case "$version" in *MariaDB*) ;; *) fail "MariaDB erforderlich, gefunden: $version" ;; esac
major="${version%%.*}"; rest="${version#*.}"; minor="${rest%%.*}"
if ! [[ "$major" =~ ^[0-9]+$ && "$minor" =~ ^[0-9]+$ ]] || [ "$major" -lt 10 ] || { [ "$major" -eq 10 ] && [ "$minor" -lt 6 ]; }; then
    fail "MariaDB ab 10.6 erforderlich (atomares DDL), gefunden: $version"
fi
exists="$(q "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$DB'")" || fail "Abfrage fehlgeschlagen: $exists"
[ "$exists" = "1" ] || fail "Datenbank $DB nicht vorhanden"
for t in zones records zone_servers users; do
    n="$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = '$t' AND TABLE_TYPE = 'BASE TABLE'")" || fail "Abfrage fehlgeschlagen: $n"
    [ "$n" = "1" ] || fail "Tabelle $DB.$t nicht vorhanden"
done
# zone_revisions.zone_id und zone_history_state.zone_id übernehmen den Typ von zones.id (ohne Fremdschlüssel)
zid="$(q "SELECT LOWER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'zones' AND COLUMN_NAME = 'id'")" || fail "Abfrage fehlgeschlagen: $zid"
[ "$zid" = "int(11)" ] || fail "zones.id hat Typ '$zid' statt int(11) – keine Änderung vorgenommen"

SIG_SQL="$(php "$HELPER" sql)" || fail "Signaturabfrage nicht verfügbar"

# Zustand aller History-Tabellen: ST[t] = ok | absent | mismatch, DT[t] = Detail
declare -A ST DT
state_all() {
    local out eval_out t s d
    # shellcheck disable=SC2086
    out="$(mariadb $CLIENT_OPTS -N -B "$DB" -e "$SIG_SQL" 2>&1)" || { echo "Signaturabfrage fehlgeschlagen: $out" >&2; return 1; }
    eval_out="$(printf '%s\n' "$out" | php "$HELPER" evaluate)" || { echo "Bewertung fehlgeschlagen" >&2; return 1; }
    ST=(); DT=()
    while IFS=$'\t' read -r t s d; do
        [ -n "$t" ] || continue
        ST[$t]="$s"; DT[$t]="${d:-}"
    done <<< "$eval_out"
    for t in $HISTORY_TABLES; do
        case "${ST[$t]:-}" in ok|absent|mismatch) ;; *) echo "Kein Prüfergebnis für $t" >&2; return 1 ;; esac
        if [ "${ST[$t]}" = "mismatch" ]; then
            echo "  $t: mismatch – ${DT[$t]}"
        else
            echo "  $t: ${ST[$t]}"
        fi
    done
    return 0
}
count_state() { local want="$1" n=0 t; for t in $HISTORY_TABLES; do [ "${ST[$t]}" = "$want" ] && n=$((n + 1)); done; echo "$n"; }

# ---- Preflight: vollständige Strukturprüfung VOR jeder DDL-Anweisung ----
echo "Strukturprüfung $DB (Erwartung: web/inc/app/SchemaCheck.php):"
state_all || fail "Strukturprüfung nicht durchführbar – keine Änderung vorgenommen"
if [ "$(count_state mismatch)" -gt 0 ]; then
    fail "vorhandene History-Tabelle(n) mit abweichender Struktur – keine Änderung vorgenommen; manuell klären"
fi
n_ok="$(count_state ok)"; n_absent="$(count_state absent)"

if [ "$MODE" = "check" ]; then
    if [ "$n_absent" -eq 0 ]; then echo "OK: Schema entspricht Migration 002"; exit 0; fi
    echo "Migration 002 erforderlich ($n_absent von 4 Tabellen fehlen)"; exit 1
fi

# ---- apply ----
if [ "$n_absent" -eq 0 ]; then
    echo "Nichts zu tun: Migration 002 bereits vollständig vorhanden (Wiederanlauf ohne Änderung)"
    exit 0
fi
[ "$n_ok" -gt 0 ] && echo "Wiederanlauf: $n_ok Tabelle(n) bereits korrekt vorhanden, $n_absent werden angelegt"

# Zeilenzahl bereits vorhandener History-Tabellen (müssen unverändert bleiben)
declare -A ROWS_BEFORE
for t in $HISTORY_TABLES; do
    if [ "${ST[$t]}" = "ok" ]; then
        ROWS_BEFORE[$t]="$(q "SELECT COUNT(*) FROM \`$DB\`.\`$t\`")" || fail "Zählung $t fehlgeschlagen" 3
    fi
done
checksums() {
    local list="" t
    for t in ${BUSINESS_TABLES//,/ }; do list="$list${list:+, }\`$DB\`.\`$t\`"; done
    q "CHECKSUM TABLE $list EXTENDED"
}
sum_before="$(checksums)" || fail "Prüfsummen der Fachtabellen nicht ermittelbar: $sum_before" 3

if [ "$NO_BACKUP" -eq 0 ]; then
    [ -n "$BACKUP_DIR" ] || fail "--backup-dir angeben (oder --no-backup nur für Testdatenbanken)"
    mkdir -p "$BACKUP_DIR" || fail "Sicherungsverzeichnis nicht anlegbar" 3
    chmod 700 "$BACKUP_DIR" || fail "Rechte des Sicherungsverzeichnisses nicht setzbar" 3
    file="$BACKUP_DIR/${DB}-vor-migration-002-$(date +%Y%m%d-%H%M%S).sql"
    # shellcheck disable=SC2086
    ( umask 077; mysqldump $CLIENT_OPTS --single-transaction --routines --triggers "$DB" > "$file" ) || fail "Sicherung fehlgeschlagen" 3
    grep -q 'CREATE TABLE `zones`' "$file" || fail "Sicherung unvollständig (zones fehlt): $file" 3
    grep -q 'CREATE TABLE `records`' "$file" || fail "Sicherung unvollständig (records fehlt): $file" 3
    echo "Sicherung: $file ($(wc -c < "$file") Bytes)"
else
    echo "Hinweis: ohne Sicherung (--no-backup)"
fi

# shellcheck disable=SC2086
out="$(mariadb $CLIENT_OPTS "$DB" < "$SQL_FILE" 2>&1)" || {
    echo "$out" >&2
    fail "DDL-Ausführung abgebrochen. Bereits angelegte Tabellen bleiben bestehen; Skript erneut ausführen (Preflight prüft sie und legt nur fehlende an)" 3
}

# ---- Nachprüfung ----
echo "Nachprüfung:"
state_all || fail "Nachprüfung nicht durchführbar" 3
[ "$(count_state ok)" -eq 4 ] || fail "Nachprüfung: Struktur nicht wie erwartet" 3
for t in "${!ROWS_BEFORE[@]}"; do
    after="$(q "SELECT COUNT(*) FROM \`$DB\`.\`$t\`")" || fail "Zählung $t fehlgeschlagen" 3
    [ "$after" = "${ROWS_BEFORE[$t]}" ] || fail "Nachprüfung: Zeilen in $t ${ROWS_BEFORE[$t]} → $after" 3
done
sum_after="$(checksums)" || fail "Prüfsummen der Fachtabellen nicht ermittelbar" 3
if [ "$sum_before" = "$sum_after" ]; then
    echo "Fachtabellen ($BUSINESS_TABLES): Prüfsummen unverändert"
else
    echo "Hinweis: Prüfsummen der Fachtabellen haben sich während der Migration geändert. Migration 002 schreibt keine"
    echo "         Fachtabellen; die Änderung stammt von einem unabhängigen Schreibvorgang (z. B. Oberfläche, DynDNS, Publish)."
fi
echo "OK: Migration 002 angewendet"
exit 0
