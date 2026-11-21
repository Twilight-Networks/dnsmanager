#!/bin/bash
# Datei: scripts/migrate_007_journal_agent_results.sh
# Zweck: Migration 007 (AS4a): Agent-Ergebnisfelder in zone_publish_targets (007a) und publish_cleanup_results (007b),
#        Fencing-Domäne in system_status (007c). Preflight, Sicherung, Publish-Lock, Nachprüfung. Muster und Exitcodes
#        wie scripts/migrate_006_zone_serial_verification.sh.
#
# Aufruf (als Benutzer mit DDL-Rechten auf die Datenbank, z. B. root über den MariaDB-Socket):
#   bash migrate_007_journal_agent_results.sh --db <name> --check
#   bash migrate_007_journal_agent_results.sh --db <name> --apply --backup-dir <verzeichnis>
#   bash migrate_007_journal_agent_results.sh --db <name> --apply --no-backup  (nur isolierte Testdatenbanken
#                                                                               dnsmgr_tNN_*, technisch erzwungen)
# Optional: --client-opts "<Optionen für mariadb/mysqldump>" (z. B. "--defaults-extra-file=/root/.my.cnf")
#
# Exitcodes:
#   0  Schema entspricht der Erwartung (nach --apply: Migration erfolgreich oder bereits vorhanden)
#   1  --check: Migration erforderlich (mindestens einer der Schritte a/b/c offen)
#   2  Abbruch vor jeder DDL ohne Änderung: Migrationen 002–006 fehlen oder weichen ab, abweichende Struktur,
#      Publish-Lock belegt, Zustand hat sich bis zur Prüfung unter dem Lock geändert, Preflight- oder Prüffehler
#   3  Fehler bei Sicherung, DDL-Ausführung oder Nachprüfung; das Skript meldet den danach vorgefundenen Zustand
#      (möglicher Teilzustand: einzelne Schritte ausgeführt). Wiederanlauf: Skript erneut ausführen.
#
# Kompatibilität (AS4a, docs/ARCHITECTURE_HISTORY.md Abschnitt 5i): Der SchemaCheck dieses Codestands akzeptiert
# zone_publish_targets und publish_cleanup_results je Tabelle exakt vor ODER exakt nach 007 (Brücke). Teilzustände sind
# für diesen Codestand unkritisch; der Code abf8361 (AS3) ist nach 007 NICHT mehr zulässig (fail-closed).
#
# Ablauf:
#   1. Preflight ohne Lock: Migrationen 002–006 exakt wie erwartet (SchemaCheck über web/scripts/schema_history-cli.php;
#      für die zwei Journaltabellen vor oder nach 007), system_status.fence_domain fehlt oder entspricht exakt der
#      Erwartung (Spalte und CHECK). Daraus der Plan: nur die fehlenden Schritte a, b, c. Vollständig ⇒ keine DDL.
#   2. Sicherung (vollständiger mysqldump, 600 in einem 700-Verzeichnis).
#   3. EINE Datenbankverbindung (eine mariadb-Sitzung): strikter sql_mode und lock_wait_timeout = 10 s auf dieser
#      Verbindung; GET_LOCK('dnsmanager_publish_all', 0) mit Bestätigung (Rückgabe 1 und IS_USED_LOCK = CONNECTION_ID());
#      Preflight unter dem Lock (geplanter Ausgangszustand je Schritt unverändert); Kennwerte je Tabelle (Zeilenzahl,
#      Prüfwert über alle bisherigen Spalten); Lock-Besitz vor und nach jeder DDL; Ergebnis jedes Schritts in der
#      Sitzung geprüft; Kennwerte danach; RELEASE_LOCK. SQL-Dateien eingebettet (nicht per source: ein Fehler in einer
#      eingebundenen Datei beendet die Sitzung des mariadb-Clients nicht). Jede verletzte Bedingung bricht mit SIGNAL ab.
#   4. Nachprüfung: vollständige Struktur (SchemaCheck: beide Journaltabellen nach 007, alle übrigen wie bisher),
#      system_status.fence_domain exakt, Kennwerte vor/nach gleich, neue Spalten ohne Werte.
#
# lock_wait_timeout begrenzt das Warten auf Metadatensperren, nicht die Dauer der Tabellenkopie. Der Publish-Lock schließt
# Publish-Läufe aus, nicht andere Fachtransaktionen; diese begrenzt die Metadatensperre. Fremdschlüssel- und
# CHECK-Prüfungen werden nie abgeschaltet. Das Skript gibt keine Zugangsdaten aus und löscht nichts.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SQL_A="$SCRIPT_DIR/../sql/migrations/007a_zone_publish_targets_agent.sql"
SQL_B="$SCRIPT_DIR/../sql/migrations/007b_publish_cleanup_results_agent.sql"
SQL_C="$SCRIPT_DIR/../sql/migrations/007c_system_status_fence_domain.sql"
HELPER="$SCRIPT_DIR/../web/scripts/schema_history-cli.php"
PREV_TABLES="history_change_sets zone_snapshots zone_revisions zone_history_state history_dependencies audit_events publish_runs zone_publish_attempts zone_publish_targets publish_cleanup_results zone_server_verification"
LOCK_NAME="dnsmanager_publish_all"
# Erwartung 007c (erhoben auf MariaDB 11.8; identisch mit sql/migrations/007c_system_status_fence_domain.sql)
SS_COL="char(36)|YES|NULL|ascii|ascii_bin"
SS_CHK="\`fence_domain\` is null or \`fence_domain\` is not null and \`fence_domain\` regexp '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\$'"
# Kennwerte je Tabelle über alle Spalten vor 007 (NULL als ~)
ZPT_STATS="CONCAT_WS('|', COUNT(*), IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', attempt_id, server_id, server_name, agent_protocol, zone_sync, IFNULL(zone_sync_at, '~'), IFNULL(zone_http_status, '~'), IFNULL(zone_error_code, '~'), IFNULL(zone_error_detail, '~'), conf_sync, IFNULL(conf_sync_at, '~'), IFNULL(conf_http_status, '~'), IFNULL(conf_error_code, '~'), IFNULL(conf_error_detail, '~'), IFNULL(conf_hash, '~')))), 0))"
PCR_STATS="CONCAT_WS('|', COUNT(*), IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', run_id, server_id, server_name, agent_protocol, registered_at, status, IFNULL(finished_at, '~'), IFNULL(valid_zone_count, '~'), IFNULL(removed_conf_count, '~'), IFNULL(removed_zone_file_count, '~'), IFNULL(http_status, '~'), IFNULL(error_code, '~'), IFNULL(error_detail, '~')))), 0))"
SS_STATS="CONCAT_WS('|', COUNT(*), IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', id, bind_dirty))), 0))"
ZPT_NEW="zone_protocol zone_effect zone_installed_serial zone_installed_hash zone_reload zone_fence_decision zone_fence_code conf_protocol conf_effect conf_reload conf_fence_decision conf_fence_code conf_verify_state conf_verify_reason conf_verify_file_serial conf_verify_file_hash conf_verify_served_serial"
PCR_NEW="cleanup_protocol cleanup_effect fence_decision fence_code served_absent_count served_still_count served_unknown_count unassigned_count"

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
# --no-backup ist technisch auf isolierte Testdatenbanken beschränkt (Namensschema dnsmgr_tNN_…); geprüft vor jedem
# Datenbankzugriff. Für jede andere Datenbank (z. B. dnsmanager) ist eine Sicherung Pflicht.
if [ "$NO_BACKUP" -eq 1 ] && ! [[ "$DB" =~ ^dnsmgr_t[0-9]{2}_[a-z0-9_]+$ ]]; then
    fail "--no-backup ist nur für isolierte Testdatenbanken (dnsmgr_tNN_*) zulässig, nicht für $DB – keine Verbindung, keine Änderung"
fi
for f in "$SQL_A" "$SQL_B" "$SQL_C"; do [ -f "$f" ] || fail "Migrationsdatei fehlt: $f"; done
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
n="$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'system_status' AND TABLE_TYPE = 'BASE TABLE'")" || fail "Abfrage fehlgeschlagen: $n"
[ "$n" = "1" ] || fail "Tabelle $DB.system_status nicht vorhanden"

SIG_SQL="$(php "$HELPER" sql)" || fail "Signaturabfrage nicht verfügbar"

# Zustand: ST[t] = ok | absent | mismatch; GEN_A/GEN_B = pre | post (nur bei ok); SS = absent | ok | mismatch
declare -A ST DT
GEN_A=""; GEN_B=""; SS=""; SS_DETAIL=""
hascol() { q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = '$1' AND COLUMN_NAME = '$2'"; }
state_all() {
    local out eval_out t s d c k a b
    # shellcheck disable=SC2086
    out="$(mariadb $CLIENT_OPTS -N -B "$DB" -e "$SIG_SQL" 2>&1)" || { echo "Signaturabfrage fehlgeschlagen: $out" >&2; return 1; }
    eval_out="$(printf '%s\n' "$out" | php "$HELPER" evaluate)" || { echo "Bewertung fehlgeschlagen" >&2; return 1; }
    ST=(); DT=()
    while IFS=$'\t' read -r t s d; do
        [ -n "$t" ] || continue
        ST[$t]="$s"; DT[$t]="${d:-}"
    done <<< "$eval_out"
    for t in $PREV_TABLES; do
        case "${ST[$t]:-}" in ok|absent|mismatch) ;; *) echo "Kein Prüfergebnis für $t" >&2; return 1 ;; esac
    done
    echo "  Migrationen 002–006: $(for t in $PREV_TABLES; do [ "${ST[$t]}" = ok ] || echo -n "$t=${ST[$t]} "; done | sed 's/^$/ok/')"
    a="$(hascol zone_publish_targets zone_protocol)" || { echo "Spaltenabfrage fehlgeschlagen: $a" >&2; return 1; }
    b="$(hascol publish_cleanup_results cleanup_protocol)" || { echo "Spaltenabfrage fehlgeschlagen: $b" >&2; return 1; }
    GEN_A="pre"; [ "$a" = "1" ] && GEN_A="post"
    GEN_B="pre"; [ "$b" = "1" ] && GEN_B="post"
    echo "  zone_publish_targets: ${ST[zone_publish_targets]} ($GEN_A 007)"
    echo "  publish_cleanup_results: ${ST[publish_cleanup_results]} ($GEN_B 007)"
    c="$(q "SELECT CONCAT(LOWER(COLUMN_TYPE), '|', IS_NULLABLE, '|', IFNULL(COLUMN_DEFAULT, '~'), '|', IFNULL(CHARACTER_SET_NAME, '~'), '|', IFNULL(COLLATION_NAME, '~')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'system_status' AND COLUMN_NAME = 'fence_domain'")" || { echo "Spaltenabfrage fehlgeschlagen: $c" >&2; return 1; }
    k="$(q "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$DB' AND TABLE_NAME = 'system_status' AND CONSTRAINT_NAME = 'chk_ss_fence_domain'")" || { echo "CHECK-Abfrage fehlgeschlagen: $k" >&2; return 1; }
    if [ -z "$c" ] && [ -z "$k" ]; then
        SS="absent"; SS_DETAIL=""
    elif [ "$c" = "$SS_COL" ] && [ "$k" = "$SS_CHK" ]; then
        SS="ok"; SS_DETAIL=""
    else
        SS="mismatch"; SS_DETAIL="Spalte '${c:-fehlt}', CHECK '${k:-fehlt}'"
    fi
    echo "  system_status.fence_domain: $SS${SS_DETAIL:+ – $SS_DETAIL}"
    return 0
}

# ---- Preflight: vollständige Strukturprüfung VOR jeder DDL-Anweisung ----
echo "Strukturprüfung $DB (Erwartung: web/inc/app/SchemaCheck.php):"
state_all || fail "Strukturprüfung nicht durchführbar – keine Änderung vorgenommen"
for t in $PREV_TABLES; do
    [ "${ST[$t]}" = "ok" ] || fail "Migrationen 002–006 fehlen oder weichen ab ($t: ${ST[$t]}${DT[$t]:+ – ${DT[$t]}}) – keine Änderung vorgenommen; manuell klären"
done
[ "$SS" != "mismatch" ] || fail "system_status.fence_domain vorhanden, aber abweichend ($SS_DETAIL) – keine Änderung vorgenommen; manuell klären"
NEED_A=0; [ "$GEN_A" = "pre" ] && NEED_A=1
NEED_B=0; [ "$GEN_B" = "pre" ] && NEED_B=1
NEED_C=0; [ "$SS" = "absent" ] && NEED_C=1
st() { [ "$1" -eq 1 ] && echo offen || echo erledigt; }

if [ "$MODE" = "check" ]; then
    if [ $((NEED_A + NEED_B + NEED_C)) -eq 0 ]; then echo "OK: Schema entspricht Migration 007"; exit 0; fi
    echo "Migration 007 erforderlich (Schritt a: $(st $NEED_A), Schritt b: $(st $NEED_B), Schritt c: $(st $NEED_C))"
    exit 1
fi

# ---- apply ----
if [ $((NEED_A + NEED_B + NEED_C)) -eq 0 ]; then
    echo "Nichts zu tun: Migration 007 bereits vollständig vorhanden (keine DDL)"
    exit 0
fi
pl() { [ "$1" -eq 1 ] && echo ausführen || echo bereits vorhanden; }
echo "Plan: Schritt a (zone_publish_targets): $(pl $NEED_A), Schritt b (publish_cleanup_results): $(pl $NEED_B), Schritt c (system_status.fence_domain): $(pl $NEED_C)"

if [ "$NO_BACKUP" -eq 0 ]; then
    [ -n "$BACKUP_DIR" ] || fail "--backup-dir angeben (oder --no-backup nur für Testdatenbanken)"
    mkdir -p "$BACKUP_DIR" || fail "Sicherungsverzeichnis nicht anlegbar" 3
    chmod 700 "$BACKUP_DIR" || fail "Rechte des Sicherungsverzeichnisses nicht setzbar" 3
    file="$BACKUP_DIR/${DB}-vor-migration-007-$(date +%Y%m%d-%H%M%S).sql"
    # shellcheck disable=SC2086
    ( umask 077; mysqldump $CLIENT_OPTS --single-transaction --routines --triggers "$DB" > "$file" ) || fail "Sicherung fehlgeschlagen" 3
    for t in zones records zone_publish_targets publish_cleanup_results system_status; do
        grep -q "CREATE TABLE \`$t\`" "$file" || fail "Sicherung unvollständig ($t fehlt): $file" 3
    done
    tail -1 "$file" | grep -q '^-- Dump completed' || fail "Sicherung ohne Abschlusszeile: $file" 3
    echo "Sicherung: $file ($(wc -c < "$file") Bytes)"
else
    echo "Hinweis: ohne Sicherung (--no-backup)"
fi

# ---- geschützter Teil: eine Verbindung, Publish-Lock, Preflight unter dem Lock, DDL, Kennwerte ----
WORK="$(mktemp -d)" || fail "Arbeitsverzeichnis nicht anlegbar" 3
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
LOCKED="IS_USED_LOCK('$LOCK_NAME') IS NOT NULL AND IS_USED_LOCK('$LOCK_NAME') = CONNECTION_ID()"
colsql() { echo "(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1' AND COLUMN_NAME = '$2')"; }
chksql() { echo "(SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '$1' AND CONSTRAINT_NAME = '$2')"; }
lockcheck() {
    echo "DELIMITER //"
    echo "BEGIN NOT ATOMIC IF NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG007_LOCK_LOST'; END IF; END //"
    echo "DELIMITER ;"
}
# Ergebnis eines Schritts: alle neuen Spalten und CHECKs vorhanden
stepcheck() {
    local table="$1" cols="$2" chks="$3" cond="" c
    for c in $cols; do cond="$cond OR $(colsql "$table" "$c") <> 1"; done
    for c in $chks; do cond="$cond OR $(chksql "$table" "$c") <> 1"; done
    echo "DELIMITER //"
    echo "BEGIN NOT ATOMIC IF FALSE$cond THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG007_STEP_FAILED'; END IF; END //"
    echo "DELIMITER ;"
}
ZPT_CHKS="chk_zpt_zone_agent chk_zpt_zone_installed chk_zpt_zone_fence chk_zpt_conf_agent chk_zpt_conf_fence chk_zpt_conf_verify chk_zpt_conf_verify_fmt"
PCR_CHKS="chk_pcr_agent chk_pcr_fence chk_pcr_served"
{
    echo "SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';"
    echo "SET SESSION lock_wait_timeout = 10;"
    echo "SELECT CONCAT_WS('|', 'SESSION', CONNECTION_ID(), @@SESSION.lock_wait_timeout, @@SESSION.sql_mode);"
    echo "SELECT GET_LOCK('$LOCK_NAME', 0) INTO @mig007_lock;"
    echo "SELECT CONCAT_WS('|', 'LOCK', IFNULL(@mig007_lock, 'NULL'), IFNULL(IS_USED_LOCK('$LOCK_NAME'), 'NULL'), CONNECTION_ID());"
    echo "DELIMITER //"
    echo "BEGIN NOT ATOMIC IF @mig007_lock IS NULL OR @mig007_lock <> 1 OR NOT ($LOCKED) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG007_LOCK_BUSY'; END IF; END //"
    # Preflight unter dem Lock: Ausgangszustand je Schritt noch wie geplant (sonst keine DDL)
    echo "BEGIN NOT ATOMIC IF $(colsql zone_publish_targets zone_protocol) <> $((1 - NEED_A)) OR $(colsql publish_cleanup_results cleanup_protocol) <> $((1 - NEED_B)) OR $(colsql system_status fence_domain) <> $((1 - NEED_C)) OR $(chksql system_status chk_ss_fence_domain) <> $((1 - NEED_C)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MIG007_STATE_CHANGED'; END IF; END //"
    echo "DELIMITER ;"
    echo "SELECT CONCAT_WS('|', 'PRE', (SELECT $ZPT_STATS FROM zone_publish_targets), (SELECT $PCR_STATS FROM publish_cleanup_results), (SELECT $SS_STATS FROM system_status));"
    if [ "$NEED_A" -eq 1 ]; then
        lockcheck
        echo "SELECT 'STEP|a';"
        grep -v '^[[:space:]]*--' "$SQL_A"
        stepcheck zone_publish_targets "$ZPT_NEW" "$ZPT_CHKS"
        lockcheck
    fi
    if [ "$NEED_B" -eq 1 ]; then
        lockcheck
        echo "SELECT 'STEP|b';"
        grep -v '^[[:space:]]*--' "$SQL_B"
        stepcheck publish_cleanup_results "$PCR_NEW" "$PCR_CHKS"
        lockcheck
    fi
    if [ "$NEED_C" -eq 1 ]; then
        lockcheck
        echo "SELECT 'STEP|c';"
        grep -v '^[[:space:]]*--' "$SQL_C"
        stepcheck system_status "fence_domain" "chk_ss_fence_domain"
        lockcheck
    fi
    echo "SELECT CONCAT_WS('|', 'POST', (SELECT $ZPT_STATS FROM zone_publish_targets), (SELECT $PCR_STATS FROM publish_cleanup_results), (SELECT $SS_STATS FROM system_status));"
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
        *MIG007_LOCK_BUSY*) fail "Publish-Lock $LOCK_NAME belegt (Publish läuft) – keine Änderung vorgenommen; später erneut ausführen" ;;
        *MIG007_STATE_CHANGED*) fail "Zustand hat sich vor der Prüfung unter dem Lock geändert – keine DDL ausgeführt; Skript erneut ausführen" ;;
    esac
    echo "Zustand nach dem Abbruch:" >&2
    state_all >&2 || true
    done_steps=""
    for s in a b c; do grep -q "^STEP|$s" "$WORK/session.out" && done_steps="$done_steps $s"; done
    echo "Teilzustand: begonnene Schritte:${done_steps:- keine}; vorgefunden: zone_publish_targets $GEN_A 007, publish_cleanup_results $GEN_B 007, system_status.fence_domain $SS (jeder Schritt atomar)." >&2
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
for t in $PREV_TABLES; do [ "${ST[$t]}" = "ok" ] || fail "Nachprüfung: $t nicht wie erwartet (${ST[$t]})" 3; done
[ "$GEN_A" = "post" ] && [ "$GEN_B" = "post" ] && [ "$SS" = "ok" ] || fail "Nachprüfung: Migration 007 unvollständig (a: $GEN_A, b: $GEN_B, c: $SS)" 3
nz="$(q "SELECT (SELECT COUNT(*) FROM \`$DB\`.zone_publish_targets WHERE COALESCE($(echo "$ZPT_NEW" | sed 's/ /, /g')) IS NOT NULL) + (SELECT COUNT(*) FROM \`$DB\`.publish_cleanup_results WHERE COALESCE($(echo "$PCR_NEW" | sed 's/ /, /g')) IS NOT NULL)")" || fail "Abfrage fehlgeschlagen: $nz" 3
pre="$(grep '^PRE|' "$WORK/session.out" | cut -d'|' -f2-)"; post="$(grep '^POST|' "$WORK/session.out" | cut -d'|' -f2-)"
[ -n "$pre" ] && [ "$pre" = "$post" ] || fail "Nachprüfung: Kennwerte unter dem Lock $pre → $post" 3
echo "Journal und Systemstatus unverändert (zone_publish_targets Anzahl|Prüfwert, publish_cleanup_results Anzahl|Prüfwert, system_status Anzahl|Prüfwert): $post"
echo "Neue Spalten mit Wert: $nz (erwartet: nur durch AS4b-Code)"
echo "OK: Migration 007 angewendet"
exit 0
