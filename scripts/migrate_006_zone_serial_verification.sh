#!/bin/bash
# Datei: scripts/migrate_006_zone_serial_verification.sh
# Zweck: Migration 006 (AS3, E79/E87): Tabelle zone_server_verification und zones.soa_serial als INT UNSIGNED, mit
#        Preflight, Sicherung, Publish-Lock und Nachprüfung. Muster und Exitcodes wie scripts/migrate_005_publish_journal.sh.
#
# Aufruf (als Benutzer mit DDL-Rechten auf die Datenbank, z. B. root über den MariaDB-Socket):
#   bash migrate_006_zone_serial_verification.sh --db <name> --check
#   bash migrate_006_zone_serial_verification.sh --db <name> --apply --backup-dir <verzeichnis>
#   bash migrate_006_zone_serial_verification.sh --db <name> --apply --no-backup  (nur isolierte Testdatenbanken
#                                                                                  dnsmgr_tNN_*, technisch erzwungen)
# Optional: --client-opts "<Optionen für mariadb/mysqldump>" (z. B. "--defaults-extra-file=/root/.my.cnf")
#
# Exitcodes:
#   0  Schema entspricht der Erwartung (nach --apply: Migration erfolgreich oder bereits vorhanden)
#   1  --check: Migration erforderlich (Tabelle fehlt und/oder zones.soa_serial noch mit Vorzeichen)
#   2  Abbruch vor jeder DDL ohne Änderung: Migrationen 002–005 fehlen oder weichen ab, abweichende Struktur,
#      negative Serial-Werte, Publish-Lock belegt, Zustand hat sich bis zur Prüfung unter dem Lock geändert,
#      Preflight- oder Prüffehler
#   3  Fehler bei Sicherung, DDL-Ausführung oder Nachprüfung; das Skript meldet den danach vorgefundenen Zustand
#      (möglicher Teilzustand: Tabelle angelegt, Spalte noch mit Vorzeichen). Wiederanlauf: Skript erneut ausführen.
#
# Ablauf:
#   1. Preflight ohne Lock: Migrationen 002–005 exakt wie erwartet (SchemaCheck über web/scripts/schema_history-cli.php),
#      Zustand von zone_server_verification (ok/absent/mismatch) und zones.soa_serial (alt/neu/abweichend), keine
#      negativen Serial-Werte. Daraus der Plan: nur die fehlenden Schritte a (CREATE TABLE) bzw. b (ALTER TABLE).
#      Vollständig migriert ⇒ keine Verbindung für DDL, keine DDL (Exit 0).
#   2. Sicherung (vollständiger mysqldump, 600 in einem 700-Verzeichnis).
#   3. EINE Datenbankverbindung (eine mariadb-Sitzung) für den geschützten Teil:
#      sql_mode (strikt) und lock_wait_timeout = 10 s auf dieser Verbindung; GET_LOCK('dnsmanager_publish_all', 0) mit
#      Bestätigung (Rückgabe 1 und IS_USED_LOCK = CONNECTION_ID(), sonst Abbruch); Preflight unter dem Lock (Spalte und
#      Tabelle noch im geplanten Ausgangszustand, keine negativen Werte, Zeilenzahl und Serial-Kennwerte); Lock-Besitz
#      vor und nach jeder DDL erneut bestätigt; Kennwerte danach; RELEASE_LOCK. Jede verletzte Bedingung bricht die
#      Sitzung mit SIGNAL ab; der Server gibt den Lock mit dem Verbindungsende frei.
#   4. Nachprüfung: vollständige Struktur (SchemaCheck), Zeilenzahl und Serial-Kennwerte vor/nach gleich.
#
# lock_wait_timeout begrenzt das Warten auf Metadatensperren (z. B. offene Transaktionen auf zones), nicht die Dauer der
# Tabellenkopie. Der Publish-Lock schließt Publish-Läufe aus, nicht andere Fachtransaktionen; diese begrenzt die
# Metadatensperre. Fremdschlüssel- und CHECK-Prüfungen werden nie abgeschaltet.
# Das Skript gibt keine Zugangsdaten oder Passwort-Hashes aus. Es löscht nichts.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SQL_A="$SCRIPT_DIR/../sql/migrations/006a_zone_server_verification.sql"
SQL_B="$SCRIPT_DIR/../sql/migrations/006b_zones_soa_serial_unsigned.sql"
HELPER="$SCRIPT_DIR/../web/scripts/schema_history-cli.php"
PREV_TABLES="history_change_sets zone_snapshots zone_revisions zone_history_state history_dependencies audit_events publish_runs zone_publish_attempts zone_publish_targets publish_cleanup_results"
NEW_TABLE="zone_server_verification"
LOCK_NAME="dnsmanager_publish_all"
COL_OLD="int(11)|NO|2025010101"
COL_NEW="int(10) unsigned|NO|2025010101"

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
for f in "$SQL_A" "$SQL_B"; do [ -f "$f" ] || fail "Migrationsdatei fehlt: $f"; done
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
n="$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'zones' AND TABLE_TYPE = 'BASE TABLE'")" || fail "Abfrage fehlgeschlagen: $n"
[ "$n" = "1" ] || fail "Tabelle $DB.zones nicht vorhanden"

SIG_SQL="$(php "$HELPER" sql)" || fail "Signaturabfrage nicht verfügbar"

# Zustand: ST[t] = ok | absent | mismatch für alle History-Tabellen; COL = Spaltenzustand zones.soa_serial
declare -A ST DT
COL=""
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
    echo "  Migrationen 002–005: $(for t in $PREV_TABLES; do [ "${ST[$t]}" = ok ] || echo -n "$t=${ST[$t]} "; done | sed 's/^$/ok/')"
    if [ "${ST[$NEW_TABLE]}" = "mismatch" ]; then
        echo "  $NEW_TABLE: mismatch – ${DT[$NEW_TABLE]}"
    else
        echo "  $NEW_TABLE: ${ST[$NEW_TABLE]}"
    fi
    COL="$(q "SELECT CONCAT(LOWER(COLUMN_TYPE), '|', IS_NULLABLE, '|', IFNULL(TRIM(BOTH '''' FROM COLUMN_DEFAULT), '~')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'zones' AND COLUMN_NAME = 'soa_serial'")" || { echo "Spaltenabfrage fehlgeschlagen: $COL" >&2; return 1; }
    echo "  zones.soa_serial: ${COL:-fehlt}"
    return 0
}

# ---- Preflight: vollständige Strukturprüfung VOR jeder DDL-Anweisung ----
echo "Strukturprüfung $DB (Erwartung: web/inc/app/SchemaCheck.php):"
state_all || fail "Strukturprüfung nicht durchführbar – keine Änderung vorgenommen"
for t in $PREV_TABLES; do
    [ "${ST[$t]}" = "ok" ] || fail "Migrationen 002–005 fehlen oder weichen ab ($t: ${ST[$t]}) – zuerst diese; keine Änderung vorgenommen"
done
[ "${ST[$NEW_TABLE]}" != "mismatch" ] || fail "$NEW_TABLE vorhanden, aber mit abweichender Struktur – keine Änderung vorgenommen; manuell klären"
case "$COL" in
    "$COL_OLD") NEED_ALTER=1 ;;
    "$COL_NEW") NEED_ALTER=0 ;;
    *) fail "zones.soa_serial weder im Ausgangs- ($COL_OLD) noch im Zielzustand ($COL_NEW): '$COL' – keine Änderung vorgenommen; manuell klären" ;;
esac
NEED_CREATE=0; [ "${ST[$NEW_TABLE]}" = "absent" ] && NEED_CREATE=1
if [ "$NEED_ALTER" -eq 1 ]; then
    neg="$(q "SELECT COUNT(*) FROM \`$DB\`.zones WHERE soa_serial < 0")" || fail "Abfrage fehlgeschlagen: $neg"
    [ "$neg" = "0" ] || fail "$neg Zone(n) mit negativer soa_serial – nicht ohne Vorzeichen darstellbar; keine Änderung vorgenommen"
fi

if [ "$MODE" = "check" ]; then
    if [ "$NEED_CREATE" -eq 0 ] && [ "$NEED_ALTER" -eq 0 ]; then echo "OK: Schema entspricht Migration 006"; exit 0; fi
    echo "Migration 006 erforderlich (Schritt a: $([ "$NEED_CREATE" -eq 1 ] && echo offen || echo erledigt), Schritt b: $([ "$NEED_ALTER" -eq 1 ] && echo offen || echo erledigt))"
    exit 1
fi

# ---- apply ----
if [ "$NEED_CREATE" -eq 0 ] && [ "$NEED_ALTER" -eq 0 ]; then
    echo "Nichts zu tun: Migration 006 bereits vollständig vorhanden (keine DDL)"
    exit 0
fi
echo "Plan: Schritt a (CREATE TABLE $NEW_TABLE): $([ "$NEED_CREATE" -eq 1 ] && echo ausführen || echo bereits vorhanden), Schritt b (ALTER zones.soa_serial): $([ "$NEED_ALTER" -eq 1 ] && echo ausführen || echo bereits vorhanden)"

if [ "$NO_BACKUP" -eq 0 ]; then
    [ -n "$BACKUP_DIR" ] || fail "--backup-dir angeben (oder --no-backup nur für Testdatenbanken)"
    mkdir -p "$BACKUP_DIR" || fail "Sicherungsverzeichnis nicht anlegbar" 3
    chmod 700 "$BACKUP_DIR" || fail "Rechte des Sicherungsverzeichnisses nicht setzbar" 3
    file="$BACKUP_DIR/${DB}-vor-migration-006-$(date +%Y%m%d-%H%M%S).sql"
    # shellcheck disable=SC2086
    ( umask 077; mysqldump $CLIENT_OPTS --single-transaction --routines --triggers "$DB" > "$file" ) || fail "Sicherung fehlgeschlagen" 3
    grep -q 'CREATE TABLE `zones`' "$file" || fail "Sicherung unvollständig (zones fehlt): $file" 3
    grep -q 'CREATE TABLE `records`' "$file" || fail "Sicherung unvollständig (records fehlt): $file" 3
    echo "Sicherung: $file ($(wc -c < "$file") Bytes)"
else
    echo "Hinweis: ohne Sicherung (--no-backup)"
fi

# ---- geschützter Teil: eine Verbindung, Publish-Lock, Preflight unter dem Lock, DDL, Kennwerte ----
WORK="$(mktemp -d)" || fail "Arbeitsverzeichnis nicht anlegbar" 3
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
EXPECT_COL="$COL_NEW"; [ "$NEED_ALTER" -eq 1 ] && EXPECT_COL="$COL_OLD"
STATS="CONCAT_WS('|', COUNT(*), IFNULL(SUM(soa_serial), 0), IFNULL(MIN(soa_serial), 0), IFNULL(MAX(soa_serial), 0), IFNULL(BIT_XOR(CRC32(CONCAT(id, ':', soa_serial))), 0))"
LOCKED="IS_USED_LOCK('$LOCK_NAME') IS NOT NULL AND IS_USED_LOCK('$LOCK_NAME') = CONNECTION_ID()"
{
    echo "SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';"
    echo "SET SESSION lock_wait_timeout = 10;"
    echo "SELECT CONCAT_WS('|', 'SESSION', CONNECTION_ID(), @@SESSION.lock_wait_timeout, @@SESSION.sql_mode);"
    echo "SELECT GET_LOCK('$LOCK_NAME', 0) INTO @mig006_lock;"
    echo "SELECT CONCAT_WS('|', 'LOCK', IFNULL(@mig006_lock, 'NULL'), IFNULL(IS_USED_LOCK('$LOCK_NAME'), 'NULL'), CONNECTION_ID());"
    echo "DELIMITER //"
    echo "BEGIN NOT ATOMIC IF @mig006_lock IS NULL OR @mig006_lock <> 1 OR NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG006_LOCK_BUSY'; END IF; END //"
    # Preflight unter dem Lock: Zustand noch wie geplant (sonst keine DDL)
    echo "BEGIN NOT ATOMIC"
    echo "  DECLARE c VARCHAR(255) DEFAULT (SELECT CONCAT(LOWER(COLUMN_TYPE), '|', IS_NULLABLE, '|', IFNULL(TRIM(BOTH '''' FROM COLUMN_DEFAULT), '~')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zones' AND COLUMN_NAME = 'soa_serial');"
    echo "  DECLARE t INT DEFAULT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$NEW_TABLE');"
    echo "  IF c IS NULL OR c <> '$EXPECT_COL' OR t <> $((1 - NEED_CREATE)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG006_STATE_CHANGED'; END IF;"
    echo "  IF (SELECT COUNT(*) FROM zones WHERE soa_serial < 0) > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG006_NEGATIVE_SERIAL'; END IF;"
    echo "END //"
    echo "DELIMITER ;"
    echo "SELECT CONCAT_WS('|', 'PRE', $STATS) FROM zones;"
    if [ "$NEED_CREATE" -eq 1 ]; then
        echo "SELECT 'STEP|a';"
        echo "source $SQL_A"
        echo "DELIMITER //"
        echo "BEGIN NOT ATOMIC IF NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG006_LOCK_LOST'; END IF; END //"
        echo "DELIMITER ;"
    fi
    if [ "$NEED_ALTER" -eq 1 ]; then
        echo "DELIMITER //"
        echo "BEGIN NOT ATOMIC IF NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG006_LOCK_LOST'; END IF; END //"
        echo "DELIMITER ;"
        echo "SELECT 'STEP|b';"
        echo "source $SQL_B"
        echo "DELIMITER //"
        echo "BEGIN NOT ATOMIC IF NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG006_LOCK_LOST'; END IF; END //"
        echo "DELIMITER ;"
    fi
    echo "SELECT CONCAT_WS('|', 'POST', $STATS) FROM zones;"
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
        *MIG006_LOCK_BUSY*) fail "Publish-Lock $LOCK_NAME belegt (Publish läuft) – keine Änderung vorgenommen; später erneut ausführen" ;;
        *MIG006_STATE_CHANGED*) fail "Zustand hat sich vor der Prüfung unter dem Lock geändert – keine DDL ausgeführt; Skript erneut ausführen" ;;
        *MIG006_NEGATIVE_SERIAL*) fail "negative soa_serial unter dem Lock festgestellt – keine DDL ausgeführt" ;;
    esac
    echo "Zustand nach dem Abbruch:" >&2
    state_all >&2 || true
    if grep -q '^STEP|a' "$WORK/session.out" && [ "${ST[$NEW_TABLE]:-}" = "ok" ] && [ "$COL" = "$COL_OLD" ]; then
        echo "Teilzustand: Schritt a ausgeführt ($NEW_TABLE angelegt), Schritt b nicht (zones.soa_serial unverändert)." >&2
    fi
    fail "DDL-Ausführung abgebrochen (z. B. Metadatensperre nach 10 s, Verbindungsabbruch). Skript erneut ausführen; der Preflight erkennt den Teilzustand" 3
fi
grep -q "^SESSION|[0-9]*|10|.*STRICT_ALL_TABLES" "$WORK/session.out" || fail "Nachprüfung: Sitzungsparameter nicht bestätigt" 3
lock_line="$(grep '^LOCK|' "$WORK/session.out")"
IFS='|' read -r _ l_got l_holder l_conn <<< "$lock_line"
[ "$l_got" = "1" ] && [ "$l_holder" = "$l_conn" ] || fail "Nachprüfung: Lock-Besitz nicht bestätigt ($lock_line)" 3
grep -q '^RELEASE|1$' "$WORK/session.out" || fail "Nachprüfung: Lock nicht ordnungsgemäß freigegeben" 3

# ---- Nachprüfung ----
echo "Nachprüfung:"
state_all || fail "Nachprüfung nicht durchführbar" 3
for t in $PREV_TABLES $NEW_TABLE; do [ "${ST[$t]}" = "ok" ] || fail "Nachprüfung: $t nicht wie erwartet (${ST[$t]})" 3; done
[ "$COL" = "$COL_NEW" ] || fail "Nachprüfung: zones.soa_serial ist '$COL' statt '$COL_NEW'" 3
pre="$(grep '^PRE|' "$WORK/session.out" | cut -d'|' -f2-)"; post="$(grep '^POST|' "$WORK/session.out" | cut -d'|' -f2-)"
[ -n "$pre" ] && [ "$pre" = "$post" ] || fail "Nachprüfung: Zeilenzahl bzw. Serial-Kennwerte unter dem Lock $pre → $post" 3
echo "Zonen und Serials unverändert (Anzahl|Summe|Minimum|Maximum|Prüfwert): $post"
echo "OK: Migration 006 angewendet"
exit 0
