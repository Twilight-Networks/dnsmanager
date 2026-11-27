#!/bin/bash
# Datei: scripts/migrate_008_zone_serial_references.sh
# Zweck: Migration 008 (AS5, D20): Tabelle zone_serial_references (Serial-Referenzereignisse je Zone × Server).
#        Preflight, Sicherung, Publish-Lock, Nachprüfung. Muster und Exitcodes wie scripts/migrate_007_journal_agent_results.sh.
#
# Aufruf (als Benutzer mit DDL-Rechten auf die Datenbank, z. B. root über den MariaDB-Socket):
#   bash migrate_008_zone_serial_references.sh --db <name> --check
#   bash migrate_008_zone_serial_references.sh --db <name> --apply --backup-dir <verzeichnis>
#   bash migrate_008_zone_serial_references.sh --db <name> --apply --no-backup  (nur isolierte Testdatenbanken
#                                                                               dnsmgr_tNN_*, technisch erzwungen)
# Optional: --client-opts "<Optionen für mariadb/mysqldump>" (z. B. "--defaults-extra-file=/root/.my.cnf")
#
# Exitcodes:
#   0  Schema entspricht der Erwartung (nach --apply: Migration erfolgreich oder bereits vorhanden)
#   1  --check: Migration erforderlich (zone_serial_references fehlt)
#   2  Abbruch vor jeder DDL ohne Änderung: Migrationen 002–007 fehlen oder weichen ab, zone_serial_references vorhanden
#      aber abweichend, Publish-Lock belegt, Zustand bis zur Prüfung unter dem Lock geändert, Preflight- oder Prüffehler
#   3  Fehler bei Sicherung, DDL-Ausführung oder Nachprüfung; das Skript meldet den danach vorgefundenen Zustand.
#      CREATE TABLE ist atomar (MariaDB ≥ 10.6): Es gibt keinen Teilzustand. Wiederanlauf: Skript erneut ausführen.
#
# Kompatibilität (docs/ARCHITECTURE_HISTORY.md Abschnitt 5k): Der AS4b-Code (156f934) prüft nur seine eigenen Tabellen
# und bleibt auf Schema 008 technisch lauffähig; sein Publish-Betrieb nach einem Rückfall ist dennoch nicht freigegeben
# (Publish-Sperre, docs/AGENT_FENCING.md Abschnitt 11). Der AS5-Code verlangt 008 strikt (fail-closed).
#
# Ablauf:
#   1. Preflight ohne Lock: Migrationen 002–007 exakt wie erwartet (SchemaCheck über web/scripts/schema_history-cli.php),
#      zone_serial_references fehlt oder entspricht exakt der Erwartung. Vollständig ⇒ keine DDL.
#   2. Sicherung (vollständiger mysqldump, 600 in einem 700-Verzeichnis).
#   3. EINE Datenbankverbindung: strikter sql_mode und lock_wait_timeout = 10 s; GET_LOCK('dnsmanager_publish_all', 0) mit
#      Bestätigung; Preflight unter dem Lock (Tabelle weiterhin nicht vorhanden); Kennwerte des Journals; Lock-Besitz vor
#      und nach der DDL; CREATE TABLE; Kennwerte danach; RELEASE_LOCK. Jede verletzte Bedingung bricht mit SIGNAL ab.
#   4. Nachprüfung: vollständige Struktur (SchemaCheck), Kennwerte vor/nach gleich, neue Tabelle leer.
#
# Fremdschlüssel- und CHECK-Prüfungen werden nie abgeschaltet. Das Skript gibt keine Zugangsdaten aus und löscht nichts.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SQL_A="$SCRIPT_DIR/../sql/migrations/008_zone_serial_references.sql"
HELPER="$SCRIPT_DIR/../web/scripts/schema_history-cli.php"
PREV_TABLES="history_change_sets zone_snapshots zone_revisions zone_history_state history_dependencies audit_events publish_runs zone_publish_attempts zone_publish_targets publish_cleanup_results zone_server_verification"
NEW_TABLE="zone_serial_references"
LOCK_NAME="dnsmanager_publish_all"
# Kennwerte des Journals und des Verification Store (Anzahl|Prüfwert über die Schlüsselspalten)
STATS="CONCAT_WS('|', (SELECT COUNT(*) FROM zone_publish_attempts), (SELECT COUNT(*) FROM zone_publish_targets), (SELECT COUNT(*) FROM zone_server_verification), (SELECT IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', zone_id, server_id, last_state, last_method, IFNULL(last_run_id, '~'), IFNULL(last_served_serial, '~'), IFNULL(verified_serial, '~')))), 0) FROM zone_server_verification), (SELECT COUNT(*) FROM history_change_sets))"

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
if [ "$NO_BACKUP" -eq 1 ] && ! [[ "$DB" =~ ^dnsmgr_t[0-9]{2}_[a-z0-9_]+$ ]]; then
    fail "--no-backup ist nur für isolierte Testdatenbanken (dnsmgr_tNN_*) zulässig, nicht für $DB – keine Verbindung, keine Änderung"
fi
[ -f "$SQL_A" ] || fail "Migrationsdatei fehlt: $SQL_A"
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

SIG_SQL="$(php "$HELPER" sql)" || fail "Signaturabfrage nicht verfügbar"

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
    for t in $PREV_TABLES $NEW_TABLE; do
        case "${ST[$t]:-}" in ok|absent|mismatch) ;; *) echo "Kein Prüfergebnis für $t" >&2; return 1 ;; esac
    done
    echo "  Migrationen 002–007: $(for t in $PREV_TABLES; do [ "${ST[$t]}" = ok ] || echo -n "$t=${ST[$t]} "; done | sed 's/^$/ok/')"
    echo "  $NEW_TABLE: ${ST[$NEW_TABLE]}${DT[$NEW_TABLE]:+ – ${DT[$NEW_TABLE]}}"
    return 0
}

echo "Strukturprüfung $DB (Erwartung: web/inc/app/SchemaCheck.php):"
state_all || fail "Strukturprüfung nicht durchführbar – keine Änderung vorgenommen"
for t in $PREV_TABLES; do
    [ "${ST[$t]}" = "ok" ] || fail "Migrationen 002–007 fehlen oder weichen ab ($t: ${ST[$t]}${DT[$t]:+ – ${DT[$t]}}) – keine Änderung vorgenommen; manuell klären"
done
fd="$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'system_status' AND COLUMN_NAME = 'fence_domain'")" || fail "Abfrage fehlgeschlagen: $fd"
[ "$fd" = "1" ] || fail "Migration 007 unvollständig (system_status.fence_domain fehlt) – keine Änderung vorgenommen"
[ "${ST[$NEW_TABLE]}" != "mismatch" ] || fail "$NEW_TABLE vorhanden, aber abweichend (${DT[$NEW_TABLE]}) – keine Änderung vorgenommen; manuell klären"

if [ "$MODE" = "check" ]; then
    if [ "${ST[$NEW_TABLE]}" = "ok" ]; then echo "OK: Schema entspricht Migration 008"; exit 0; fi
    echo "Migration 008 erforderlich ($NEW_TABLE fehlt)"
    exit 1
fi

if [ "${ST[$NEW_TABLE]}" = "ok" ]; then
    echo "Nichts zu tun: Migration 008 bereits vorhanden (keine DDL)"
    exit 0
fi
echo "Plan: $NEW_TABLE anlegen"

if [ "$NO_BACKUP" -eq 0 ]; then
    [ -n "$BACKUP_DIR" ] || fail "--backup-dir angeben (oder --no-backup nur für Testdatenbanken)"
    mkdir -p "$BACKUP_DIR" || fail "Sicherungsverzeichnis nicht anlegbar" 3
    chmod 700 "$BACKUP_DIR" || fail "Rechte des Sicherungsverzeichnisses nicht setzbar" 3
    file="$BACKUP_DIR/${DB}-vor-migration-008-$(date +%Y%m%d-%H%M%S).sql"
    # shellcheck disable=SC2086
    ( umask 077; mysqldump $CLIENT_OPTS --single-transaction --routines --triggers "$DB" > "$file" ) || fail "Sicherung fehlgeschlagen" 3
    for t in zones records zone_publish_targets zone_server_verification system_status; do
        grep -q "CREATE TABLE \`$t\`" "$file" || fail "Sicherung unvollständig ($t fehlt): $file" 3
    done
    tail -1 "$file" | grep -q '^-- Dump completed' || fail "Sicherung ohne Abschlusszeile: $file" 3
    echo "Sicherung: $file ($(wc -c < "$file") Bytes)"
else
    echo "Hinweis: ohne Sicherung (--no-backup)"
fi

WORK="$(mktemp -d)" || fail "Arbeitsverzeichnis nicht anlegbar" 3
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
LOCKED="IS_USED_LOCK('$LOCK_NAME') IS NOT NULL AND IS_USED_LOCK('$LOCK_NAME') = CONNECTION_ID()"
TBL="(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$NEW_TABLE')"
lockcheck() {
    echo "DELIMITER //"
    echo "BEGIN NOT ATOMIC IF NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG008_LOCK_LOST'; END IF; END //"
    echo "DELIMITER ;"
}
{
    echo "SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';"
    echo "SET SESSION lock_wait_timeout = 10;"
    echo "SELECT CONCAT_WS('|', 'SESSION', CONNECTION_ID(), @@SESSION.lock_wait_timeout, @@SESSION.sql_mode);"
    echo "SELECT GET_LOCK('$LOCK_NAME', 0) INTO @mig008_lock;"
    echo "SELECT CONCAT_WS('|', 'LOCK', IFNULL(@mig008_lock, 'NULL'), IFNULL(IS_USED_LOCK('$LOCK_NAME'), 'NULL'), CONNECTION_ID());"
    echo "DELIMITER //"
    echo "BEGIN NOT ATOMIC IF @mig008_lock IS NULL OR @mig008_lock <> 1 OR NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG008_LOCK_BUSY'; END IF; END //"
    echo "BEGIN NOT ATOMIC IF $TBL <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG008_STATE_CHANGED'; END IF; END //"
    echo "DELIMITER ;"
    echo "SELECT CONCAT_WS('|', 'PRE', $STATS);"
    lockcheck
    echo "SELECT 'STEP|a';"
    grep -v '^[[:space:]]*--' "$SQL_A"
    echo "DELIMITER //"
    echo "BEGIN NOT ATOMIC IF $TBL <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG008_STEP_FAILED'; END IF; END //"
    echo "DELIMITER ;"
    lockcheck
    echo "SELECT CONCAT_WS('|', 'POST', $STATS);"
    echo "SELECT CONCAT_WS('|', 'RELEASE', IFNULL(RELEASE_LOCK('$LOCK_NAME'), 'NULL'));"
} > "$WORK/session.sql"

echo "Geschützter Teil (eine Verbindung, Publish-Lock $LOCK_NAME):"
# shellcheck disable=SC2086
mariadb $CLIENT_OPTS -N -B "$DB" < "$WORK/session.sql" > "$WORK/session.out" 2> "$WORK/session.err"
rc=$?
sed 's/^/  /' "$WORK/session.out"
if [ "$rc" -ne 0 ]; then
    err="$(cat "$WORK/session.err")"
    echo "  $err" >&2
    case "$err" in
        *MIG008_LOCK_BUSY*) fail "Publish-Lock $LOCK_NAME belegt (Publish läuft) – keine Änderung vorgenommen; später erneut ausführen" ;;
        *MIG008_STATE_CHANGED*) fail "Zustand hat sich vor der Prüfung unter dem Lock geändert – keine DDL ausgeführt; Skript erneut ausführen" ;;
    esac
    echo "Zustand nach dem Abbruch:" >&2
    state_all >&2 || true
    fail "DDL-Ausführung abgebrochen (z. B. Metadatensperre nach 10 s, Verbindungsabbruch). Skript erneut ausführen; CREATE TABLE ist atomar" 3
fi
grep -q "^SESSION|[0-9]*|10|.*STRICT_ALL_TABLES" "$WORK/session.out" || fail "Nachprüfung: Sitzungsparameter nicht bestätigt" 3
lock_line="$(grep '^LOCK|' "$WORK/session.out")"
IFS='|' read -r _ l_got l_holder l_conn <<< "$lock_line"
[ "$l_got" = "1" ] && [ "$l_holder" = "$l_conn" ] || fail "Nachprüfung: Lock-Besitz nicht bestätigt ($lock_line)" 3
grep -q '^RELEASE|1$' "$WORK/session.out" || fail "Nachprüfung: Lock nicht ordnungsgemäß freigegeben" 3

echo "Nachprüfung:"
state_all || fail "Nachprüfung nicht durchführbar" 3
for t in $PREV_TABLES $NEW_TABLE; do [ "${ST[$t]}" = "ok" ] || fail "Nachprüfung: $t nicht wie erwartet (${ST[$t]})" 3; done
n="$(q "SELECT COUNT(*) FROM \`$DB\`.$NEW_TABLE")" || fail "Abfrage fehlgeschlagen: $n" 3
[ "$n" = "0" ] || fail "Nachprüfung: $NEW_TABLE nicht leer ($n)" 3
pre="$(grep '^PRE|' "$WORK/session.out" | cut -d'|' -f2-)"; post="$(grep '^POST|' "$WORK/session.out" | cut -d'|' -f2-)"
[ -n "$pre" ] && [ "$pre" = "$post" ] || fail "Nachprüfung: Kennwerte unter dem Lock $pre → $post" 3
echo "Journal, Verification Store und Änderungsmengen unverändert (Versuche|Ziele|Beobachtungen|Prüfwert|Änderungsmengen): $post"
echo "OK: Migration 008 angewendet"
exit 0
