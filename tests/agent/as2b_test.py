#!/usr/bin/env python3
# Datei: tests/agent/as2b_test.py
# Zweck: Abnahmesuite AS2b (Nachverifikation nach dem Reload, Nachweis der Entfernung, Polling, Zeitbudget) gegen die
#        INSTALLIERTE BIND-Instanz einer Test-VM (CLAUDE.md §5, E112). Wie AS2a läuft der Agent-Code des Prüfstands als
#        www-data über einen temporären PHP-HTTP-Server (php -S) gegen das echte /etc/bind, den echten named und rndc.
#        Hilfsfunktionen (Agent, Sicherung, Rückbau) stammen unverändert aus as2a_test.py. Testzonen tragen das Suffix
#        as2b.test bzw. as2b-solo.test. Vorher wird /etc/bind gesichert; am Ende wird der Ausgangszustand wiederhergestellt
#        und nachgewiesen (Gruppe Z).
#
# Gruppen:
#   V  conf_sync: verified (neue Zone, Änderung, Unterzone, große Zone, nach abgelehntem zone_sync), Antwortvertrag
#   N  conf_sync: nie positiv ohne Nachweis (Datei ungültig/fehlt, andere Serial, SERVFAIL, K strukturell, DNS blockiert,
#      Reload gescheitert, Großbuchstaben)
#   R  zone_cleanup: absent / still_served / unknown (ohne und mit Elternzone, große Zone, weiter konfiguriert, Reload
#      gescheitert, Kontrollzone fehlt, Großbuchstaben, DNS blockiert, viele Zonen), Kontrollabfragen je Runde
#   B  Zeitbudget: Eintrittsschwelle des Vorabnachweises knapp unter/über 20 s, größter Verbrauch, späte Sperre, Sperre
#      während der Nachverifikation gehalten, jeder Aufruf < 25 s
#   M  Mutationsproben MV01–MV16 (Live-Szenario und reine Tests gegen die mutierte Kopie)
#   Z  Rückbau
#
# Aufruf (root): as2b_test.py --src <Checkout> --out <Ergebnisverzeichnis> [--groups VNRBM]

import argparse
import base64
import fcntl
import json
import os
import shutil
import subprocess
import sys
import tarfile
import threading
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import as2a_test as A  # noqa: E402

from as2a_test import (CONFDIR, ZONES, ZONESCONF, check, conf_text, dig, j, managed, put_incfile,  # noqa: E402
                       put_managed_raw, rndc_reload, sh, sha, inc_zone, wait_serial, write_inc, zone_text)


def log(msg):
    """Protokoll dieser Suite (as2b_test.log); check() der Basis-Suite verwendet dieselbe Funktion (A.log)."""
    print(msg, flush=True)
    with open(os.path.join(A.OUT, 'as2b_test.log'), 'a') as f:
        f.write(msg + '\n')


A.log = log

WORK = '/tmp/as2b-run'
LOCK = f'{ZONES}/.dnsmanager-agent.lock'
MSG_CS = 'Zonen-Konfiguration übernommen, zones.conf neu geschrieben.'
MSG_CL = 'Verwaiste Zonen entfernt, zones.conf neu geschrieben.'
TIMINGS = []


def v(r):
    return j(r, 'verification') or {}


def zr(r):
    return {x['zone']: x for x in (j(r, 'zones') or [])}


def classify(kind, r):
    php = os.path.join(A.SRC, 'tests', 'agent', 'as2a_classify.php')
    return sh(['php', php, A.SRC, kind, str(r['status']), r['raw'].decode()], check_rc=False).stdout.strip()


def install(a, name, serial, cs=True):
    """Zone über den Agenten installieren (zone_sync, danach conf_sync) und laden."""
    r = a.zs(name, serial)
    if cs:
        r = a.cs(name)
    wait_serial(name, serial)
    return r


def big_zone(name, serial, n=300000):
    lines = [zone_text(name, serial).rstrip('\n')]
    lines += [f'h{i} IN A 10.{(i >> 16) & 255}.{(i >> 8) & 255}.{i & 255}' for i in range(1, n + 1)]
    return '\n'.join(lines) + '\n'


def hold_lock_for(seconds):
    """Agent-Sperre jetzt nehmen und nach `seconds` in einem Thread freigeben (Restzeit beim Sperrerwerb steuern)."""
    fh = open(LOCK, 'r')
    fcntl.flock(fh, fcntl.LOCK_EX)

    def rel():
        time.sleep(seconds)
        fcntl.flock(fh, fcntl.LOCK_UN)
        fh.close()
    t = threading.Thread(target=rel, daemon=True)
    t.start()
    return t


def dns_block():
    sh('iptables -I INPUT -i lo -p udp --dport 53 -j DROP; iptables -I INPUT -i lo -p tcp --dport 53 -j DROP')


def dns_unblock():
    sh('iptables -D INPUT -i lo -p udp --dport 53 -j DROP; iptables -D INPUT -i lo -p tcp --dport 53 -j DROP', check_rc=False)


def slow_lp(seconds):
    """named-checkconf, das nur für -l und -p verzögert (Vorabnachweis), nicht für den Preflight."""
    p = os.path.join(A.WORK, 'as2a-slow-cc-lp')
    with open(p, 'w') as f:
        f.write(f'#!/bin/sh\ncase "$1" in -l|-p) sleep {seconds};; esac\nexec /usr/bin/named-checkconf "$@"\n')
    os.chmod(p, 0o755)
    return p


def slow_preflight(seconds):
    """named-checkconf, das nur den Preflight (ohne -l/-p) verzögert."""
    p = os.path.join(A.WORK, 'as2a-slow-cc-pre')
    with open(p, 'w') as f:
        f.write(f'#!/bin/sh\ncase "$1" in -l|-p) ;; *) sleep {seconds};; esac\nexec /usr/bin/named-checkconf "$@"\n')
    os.chmod(p, 0o755)
    return p


def keep_list(state, extra=()):
    """valid_zones: Ausgangszonen und alle vorhandenen Testzonen außer den zu entfernenden."""
    present = [f[:-5] for f in os.listdir(CONFDIR) if f.endswith('.conf')]
    return sorted(set(state['zones']) | {z for z in present if z not in extra})


def timing(tid, r):
    TIMINGS.append((tid, r['dt'], v(r).get('rounds'), v(r).get('elapsed_ms'), v(r).get('window_ms')))


# ------------------------------------------------------------------------------------------------------------------
# Gruppe V – conf_sync verifiziert
# ------------------------------------------------------------------------------------------------------------------
def group_v(a, state):
    G = 'V'
    a.config('enforce')
    v1 = 'v1.as2b-solo.test'
    a.zs(v1, 1)
    r = a.cs(v1)
    vv = v(r)
    timing('V01', r)
    check(G, 'V01 neue Zone ⇒ verified', r['status'] == 200 and vv.get('state') == 'verified' and vv.get('file_serial') == 1
          and vv.get('served_serial') == 1 and vv.get('file_sha256') == sha(managed(v1)) and vv.get('zone') == v1
          and vv.get('config_state') == 'yes', json.dumps(vv))
    check(G, 'V01b Antwortvertrag unverändert (Status, message, effect, reload)', j(r, 'status') == 'success' and j(r, 'message') == MSG_CS
          and j(r, 'effect') == 'applied' and (j(r, 'reload') or {}).get('status') == 'ok' and 'cmp' not in vv)
    check(G, 'V01c Klassifizierer unverändert ⇒ ok', classify('conf_sync', r) == 'ok')
    a.zs(v1, 2)
    r = a.cs(v1)
    timing('V02', r)
    check(G, 'V02 Änderung ⇒ verified mit neuer Serial', v(r).get('state') == 'verified' and v(r).get('served_serial') == 2, json.dumps(v(r)))
    # Unterzone unter konfigurierter Elternzone
    par = 'par.as2b.test'
    write_inc([inc_zone(par, put_incfile(par, 7))])
    rndc_reload()
    sub = 'sub.par.as2b.test'
    a.zs(sub, 1)
    r = a.cs(sub)
    check(G, 'V03 Unterzone ⇒ verified', v(r).get('state') == 'verified', json.dumps(v(r)))
    # große Zone (300 000 RR): Laden dauert ≈ 1,6 s ⇒ mehrere Runden
    big = 'big.as2b-solo.test'
    put_managed_raw(big, big_zone(big, 1))
    r = a.cs(big)
    timing('V04', r)
    # named-checkzone der verwalteten Datei (≈ 1,7 s) überlappt mit dem Laden durch BIND; erste Runde danach
    check(G, 'V04 große Zone ⇒ verified nach Ladeabschluss', v(r).get('state') == 'verified' and (v(r).get('rounds') or 0) >= 1
          and (v(r).get('elapsed_ms') or 0) > 500 and r['dt'] < 25, json.dumps({k: v(r).get(k) for k in ('state', 'rounds', 'elapsed_ms', 'reason')}))
    # conf_sync nach abgelehntem zone_sync: geprüft wird gegen die verwaltete Datei (file_sha256), nicht gegen den Versuch
    rz = a.zs(v1, 1)
    r = a.cs(v1)
    check(G, 'V05 nach abgelehntem zone_sync ⇒ verified gegen verwaltete Datei', rz['status'] == 409 and v(r).get('state') == 'verified'
          and v(r).get('file_serial') == 2 and v(r).get('file_sha256') == sha(managed(v1)), f"zs={rz['status']} {json.dumps(v(r))}")
    a.config('observe')
    r = a.cs(v1)
    check(G, 'V06 observe ⇒ ebenfalls verifiziert', v(r).get('state') == 'verified' and r['status'] == 200)
    a.config('enforce')


# ------------------------------------------------------------------------------------------------------------------
# Gruppe N – conf_sync ohne positiven Nachweis
# ------------------------------------------------------------------------------------------------------------------
def group_n(a, state):
    G = 'N'
    a.config('enforce')
    n1 = 'n1.as2b-solo.test'
    put_managed_raw(n1, 'kaputt\n')
    r = a.cs(n1)
    check(G, 'N01 Datei ungültig ⇒ unknown sofort, HTTP/effect unverändert', r['status'] == 200 and j(r, 'effect') == 'applied'
          and v(r).get('state') == 'unknown' and v(r).get('reason') == 'file_invalid' and v(r).get('rounds') == 1, json.dumps(v(r)))
    n3 = 'n3.as2b-solo.test'
    r = a.cs(n3)
    check(G, 'N02 Datei fehlt ⇒ unknown', v(r).get('state') == 'unknown' and v(r).get('reason') == 'file_missing', json.dumps(v(r)))
    # andere Serial ausgeliefert: neue Datei für named nicht lesbar ⇒ BIND liefert weiter die alte Serial
    n2 = 'n2.as2b-solo.test'
    install(a, n2, 3)
    p = put_managed_raw(n2, zone_text(n2, 4))
    os.chmod(p, 0o600)
    r = a.cs(n2)
    timing('N03', r)
    check(G, 'N03 andere Serial ⇒ serial_differs am Fensterende', v(r).get('state') == 'serial_differs' and v(r).get('served_serial') == 3
          and v(r).get('file_serial') == 4 and 9000 <= (v(r).get('elapsed_ms') or 0) <= 10600 and r['status'] == 200 and r['dt'] < 25,
          json.dumps({k: v(r).get(k) for k in ('state', 'served_serial', 'file_serial', 'rounds', 'elapsed_ms')}))
    os.chmod(p, 0o644)
    rndc_reload()
    # SERVFAIL: neue Zone, Datei für named nicht lesbar ⇒ nie verified
    n8 = 'n8.as2b-solo.test'
    p = put_managed_raw(n8, zone_text(n8, 1))
    os.chmod(p, 0o600)
    r = a.cs(n8)
    check(G, 'N04 SERVFAIL ⇒ unknown (nie positiv)', v(r).get('state') == 'unknown' and v(r).get('reason') == 'last_round_unknown'
          and r['dt'] < 25, json.dumps({k: v(r).get(k) for k in ('state', 'reason', 'last_reason', 'served_rcode', 'rounds')}))
    os.chmod(p, 0o644)
    # K strukturell unbestimmbar (zusätzliche Option)
    n4 = 'n4.as2b-solo.test'
    put_managed_raw(n4, zone_text(n4, 1))
    r = a.cs(n4, text=f'zone "{n4}" {{\n    type master;\n    file "{ZONES}/db.{n4}";\n    notify no;\n}};')
    check(G, 'N05 K strukturell ⇒ unknown sofort', v(r).get('state') == 'unknown' and v(r).get('reason') == 'config_zone_options_unsupported'
          and v(r).get('rounds') == 1, json.dumps(v(r)))
    # DNS blockiert ⇒ Timeouts ⇒ unknown, innerhalb der Frist
    dns_block()
    try:
        r = a.cs(n2)
    finally:
        dns_unblock()
    timing('N06', r)
    check(G, 'N06 DNS blockiert ⇒ unknown, < 25 s', v(r).get('state') == 'unknown' and v(r).get('reason') == 'last_round_unknown'
          and r['dt'] < 25 and r['status'] == 200 and A.orphan_count() == 0, f"dt={r['dt']:.1f} {json.dumps(v(r))}")
    # Reload gescheitert ⇒ keine DNS-Abfrage
    sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
    try:
        r = a.cs(n2)
    finally:
        sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
    check(G, 'N07 Reload gescheitert ⇒ unknown ohne Abfrage, Vertrag unverändert', r['status'] == 500 and j(r, 'code') == 'reload_timeout'
          and j(r, 'effect') == 'applied' and v(r).get('state') == 'unknown' and v(r).get('reason') == 'reload_timeout'
          and v(r).get('rounds') == 0, json.dumps(v(r)))
    rndc_reload()
    # Großbuchstaben (E128): verwalteter Pfad passt nicht zum normalisierten Namen ⇒ unknown, nie verified
    up = 'Up.as2b-solo.test'
    put_managed_raw(up, zone_text(up.lower(), 1))
    r = a.cs(up)
    check(G, 'N08 Großbuchstaben ⇒ unknown (K unbestimmbar)', v(r).get('state') == 'unknown' and v(r).get('reason') == 'config_zone_file_mismatch'
          and dig(up.lower())['serial'] == 1, json.dumps(v(r)))


# ------------------------------------------------------------------------------------------------------------------
# Gruppe R – Cleanup
# ------------------------------------------------------------------------------------------------------------------
def group_r(a, state):
    G = 'R'
    a.config('enforce')
    solo = [f'r{i}.as2b-solo.test' for i in (1, 2, 3)]
    for z in solo:
        install(a, z, 1)
    write_inc([inc_zone('par.as2b.test', put_incfile('par.as2b.test', 7))])
    rndc_reload()
    rp = 'rp.par.as2b.test'
    install(a, rp, 1)
    r = a.cl(keep_list(state, solo + [rp]))
    z = zr(r)
    vv = v(r)
    timing('R01', r)
    check(G, 'R01 vier Zonen ⇒ absent', r['status'] == 200 and all(z.get(x, {}).get('served') == 'absent' for x in solo + [rp])
          and all(z.get(x, {}).get('name') == x for x in solo + [rp]), json.dumps(z))
    check(G, 'R01b Gründe: Kontrollzone bzw. Elternzone', all(z[x]['served_reason'] == 'refused_control_ok' for x in solo)
          and z[rp]['served_reason'] == 'parent_negative')
    check(G, 'R01c eine Runde, eine Kontrollabfrage', vv.get('rounds') == 1 and vv.get('control_queries') == 1
          and (vv.get('name_proof') or {}).get('state') == 'ok', json.dumps(vv))
    check(G, 'R01d Antwortvertrag und Klassifizierung unverändert', j(r, 'message') == MSG_CL and j(r, 'effect') == 'applied'
          and classify('zone_cleanup', r) == 'ok' and all(dig(x)['rcode'] in ('REFUSED', 'NXDOMAIN') for x in solo + [rp]))
    # große Zone
    big = 'rbig.as2b-solo.test'
    put_managed_raw(big, big_zone(big, 1))
    a.cs(big)
    wait_serial(big, 1, timeout=10)
    r = a.cl(keep_list(state, [big]))
    check(G, 'R02 große Zone ⇒ absent', zr(r).get(big, {}).get('served') == 'absent', json.dumps(zr(r)))
    # Zone nach dem Cleanup weiter konfiguriert (eigene Konfiguration verweist auf die verwaltete Datei) ⇒ nie absent
    rk = 'rk.as2b-solo.test'
    put_managed_raw(rk, zone_text(rk, 1))
    write_inc([inc_zone(rk, managed(rk))])
    rndc_reload()
    with open(f'{CONFDIR}/{rk}.conf', 'w') as f:
        f.write(conf_text(rk))
    shutil.chown(f'{CONFDIR}/{rk}.conf', 'www-data', 'www-data')
    r = a.cl(keep_list(state, [rk]))
    check(G, 'R03 weiter konfiguriert ⇒ nicht absent', zr(r).get(rk, {}).get('name') == rk
          and zr(r).get(rk, {}).get('served') in ('still_served', 'unknown') and zr(r)[rk]['served_reason'] == 'still_configured', json.dumps(zr(r)))
    write_inc([])
    rndc_reload()
    # Reload gescheitert ⇒ still_served bzw. unknown, nie absent; keine Kontrollabfrage. Der Steuerkanal wird erst nach dem
    # Vorabnachweis blockiert (sobald zones.conf ersetzt ist; der Preflight wird dafür um 1 s verzögert)
    r4 = 'r4.as2b-solo.test'
    install(a, r4, 1)
    keep = keep_list(state, [r4])
    new_zc = ''.join(f'include "{os.path.realpath(os.path.join(CONFDIR, z + ".conf"))}";\n'
                     for z in sorted(x for x in keep if os.path.exists(f'{CONFDIR}/{x}.conf')))
    import hashlib
    a.config('enforce', bind_named_checkconf=slow_preflight(1.0))
    t = A.after_change(ZONESCONF, hashlib.sha256(new_zc.encode()).hexdigest(),
                       lambda: sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP'))
    try:
        r = a.cl(keep)
    finally:
        t.join(timeout=20)
        sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP', check_rc=False)
        a.config('enforce')
    check(G, 'R04 Reload gescheitert ⇒ still_served, nie absent', r['status'] == 500 and j(r, 'code') == 'reload_timeout'
          and zr(r).get(r4, {}).get('served') == 'still_served' and v(r).get('control_queries') == 0 and v(r).get('rounds') == 1
          and r['dt'] < 25, json.dumps([zr(r), v(r)]))
    rndc_reload()
    # Kontrollzone fehlt ⇒ unknown, sofort
    r5 = 'r5.as2b-solo.test'
    install(a, r5, 1)
    a.config('enforce', l3_control_zone='fehlt.as2b-solo.test')
    r = a.cl(keep_list(state, [r5]))
    a.config('enforce')
    check(G, 'R05 Kontrollzone fehlt ⇒ unknown (kein absent aus REFUSED allein), keine Kontrollabfrage gezählt', zr(r).get(r5, {}).get('served') == 'unknown'
          and zr(r)[r5]['served_reason'] == 'control_zone_missing' and v(r).get('rounds') == 1 and v(r).get('control_queries') == 0,
          json.dumps([zr(r), v(r)]))
    # Großbuchstaben ⇒ Name nicht eindeutig ⇒ unknown (keine Ableitung aus dem Dateinamen)
    up = 'Up.as2b-solo.test'
    if not os.path.exists(f'{CONFDIR}/{up}.conf'):
        put_managed_raw(up, zone_text(up.lower(), 1))
        a.cs(up)
    r = a.cl(keep_list(state, [up]))
    check(G, 'R06 Großbuchstaben ⇒ zone_name_unresolved', zr(r).get(up, {}).get('name') is None
          and zr(r)[up]['served'] == 'unknown' and zr(r)[up]['served_reason'] == 'zone_name_unresolved', json.dumps(zr(r)))
    # DNS blockiert
    r7 = 'r7.as2b-solo.test'
    install(a, r7, 1)
    dns_block()
    try:
        r = a.cl(keep_list(state, [r7]))
    finally:
        dns_unblock()
    timing('R07', r)
    check(G, 'R07 DNS blockiert ⇒ unknown, Dateien entfernt, < 25 s', zr(r).get(r7, {}).get('served') == 'unknown'
          and zr(r)[r7]['conf'] == 'removed' and j(r, 'effect') == 'applied' and r['dt'] < 25, f"dt={r['dt']:.1f} {json.dumps(zr(r))}")
    # viele Zonen bei blockiertem DNS: gemeinsames Fenster
    many = [f'm{i:02d}.as2b-solo.test' for i in range(30)]
    for x in many:
        put_managed_raw(x, zone_text(x, 1))
        with open(f'{CONFDIR}/{x}.conf', 'w') as f:
            f.write(conf_text(x))
        shutil.chown(f'{CONFDIR}/{x}.conf', 'www-data', 'www-data')
    a.cs(many[0])   # zones.conf mit allen Includes neu schreiben und laden
    dns_block()
    try:
        r = a.cl(keep_list(state, many))
    finally:
        dns_unblock()
    timing('R08', r)
    check(G, 'R08 30 Zonen, DNS blockiert ⇒ gemeinsames Fenster, < 25 s', all(zr(r).get(x, {}).get('served') == 'unknown' for x in many)
          and r['dt'] < 25 and (v(r).get('elapsed_ms') or 0) <= (v(r).get('window_ms') or 0) + 1600, f"dt={r['dt']:.1f} {json.dumps(v(r))}")
    rndc_reload()


# ------------------------------------------------------------------------------------------------------------------
# Gruppe B – Zeitbudget
# ------------------------------------------------------------------------------------------------------------------
def group_b(a, state):
    G = 'B'
    a.config('enforce')
    for tid, hold, exp in (('B01 Restzeit ≈ 19,6 s beim Sperrerwerb ⇒ Vorabnachweis übersprungen', 4.5, 'skipped'),
                           ('B02 Restzeit ≈ 20,4 s ⇒ Vorabnachweis', 3.7, 'ok')):
        z = f'b{len(TIMINGS)}.as2b-solo.test'
        install(a, z, 1)
        hold_lock_for(hold)
        r = a.cl(keep_list(state, [z]))
        np_ = v(r).get('name_proof') or {}
        exp_served = 'unknown' if exp == 'skipped' else 'absent'
        check(G, tid, np_.get('state') == exp and zr(r).get(z, {}).get('served') == exp_served and r['status'] == 200
              and j(r, 'effect') == 'applied', f"name_proof={json.dumps(np_)} served={zr(r).get(z, {}).get('served')} dt={r['dt']:.1f}")
        timing(tid[:3], r)
    # größter Verbrauch des Vorabnachweises (-l und -px je 2,9 s) knapp über der Schwelle ⇒ Cleanup unbeeinträchtigt
    z = 'b3.as2b-solo.test'
    install(a, z, 1)
    a.config('enforce', bind_named_checkconf=slow_lp(2.9))
    hold_lock_for(3.7)
    r = a.cl(keep_list(state, [z]))
    a.config('enforce')
    np_ = v(r).get('name_proof') or {}
    check(G, 'B03 größter Verbrauch ⇒ Restzeit danach ≥ 9 s, Preflight und Reload regulär', np_.get('state') == 'ok' and np_.get('ms', 0) > 5500
          and np_.get('remaining_ms', 0) >= 9000 and r['status'] == 200 and (j(r, 'reload') or {}).get('status') == 'ok'
          and zr(r).get(z, {}).get('served') == 'absent' and r['dt'] < 25, f"name_proof={json.dumps(np_)} dt={r['dt']:.1f}")
    timing('B03', r)
    # hängender Vorabnachweis ⇒ Abbruch nach der Obergrenze, Cleanup unbeeinträchtigt
    z = 'b4.as2b-solo.test'
    install(a, z, 1)
    a.config('enforce', bind_named_checkconf=slow_lp(30))
    r = a.cl(keep_list(state, [z]))
    a.config('enforce')
    np_ = v(r).get('name_proof') or {}
    check(G, 'B04 hängender Vorabnachweis ⇒ unknown, Cleanup regulär', np_.get('state') == 'undeterminable' and zr(r).get(z, {}).get('served') == 'unknown'
          and r['status'] == 200 and zr(r)[z]['conf'] == 'removed' and r['dt'] < 25 and A.orphan_count() == 0, f"name_proof={json.dumps(np_)} dt={r['dt']:.1f}")
    # späte Sperre in conf_sync ⇒ Fenster durch TW − 1 s begrenzt, Antwort < 25 s
    v1 = 'v1.as2b-solo.test'
    install(a, v1, 1)
    hold_lock_for(15.3)
    dns_block()
    try:
        r = a.cs(v1)
    finally:
        dns_unblock()
    timing('B05', r)
    check(G, 'B05 späte Sperre und DNS blockiert ⇒ Fenster < 10 s, Antwort < 25 s', r['status'] == 200 and (v(r).get('window_ms') or 99999) < 9000
          and r['dt'] < 25 and v(r).get('state') == 'unknown' and v(r).get('reason') == 'last_round_unknown', f"dt={r['dt']:.1f} {json.dumps(v(r))}")
    # Sperre während der Nachverifikation gehalten (E119)
    held = []

    def probe():
        time.sleep(4.0)
        fh = open(LOCK, 'r')
        try:
            fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
            held.append(False)
            fcntl.flock(fh, fcntl.LOCK_UN)
        except OSError:
            held.append(True)
        fh.close()
    dns_block()
    t = threading.Thread(target=probe, daemon=True)
    t.start()
    try:
        r = a.cs(v1)
    finally:
        dns_unblock()
        t.join()
    check(G, 'B06 Agent-Sperre während der Nachverifikation gehalten', held == [True] and (v(r).get('elapsed_ms') or 0) > 5000, f"held={held} {json.dumps(v(r))}")


# ------------------------------------------------------------------------------------------------------------------
# Gruppe M – Mutationsproben
# ------------------------------------------------------------------------------------------------------------------
DV = 'agent/inc/dns_verify.php'
DS = 'agent/inc/dns_state.php'
CS = 'agent/api/v1/zones/conf_sync.php'
MUTATIONS = [
    ('MV01 verified ohne K = ja', DV, [
        ("    if ($k['state'] === 'undeterminable') {", "    if (false) {"),
        ("    if ($k['state'] !== 'yes') {\n        return $r(true, true, 'unknown', 'config_not_configured');", "    if (false) {\n        return $r(true, true, 'unknown', 'config_not_configured');"),
        ("            if ($k['state'] === 'yes') {\n                $d = dnsServedState", "            if (true) {\n                $d = dnsServedState")], None, 'mv_upper', 'DL'),
    ('MV02 Serial-Vergleich entfernt', DV, "        return $d['serial'] === $f['serial'] ? $r(true, true, 'verified', null)",
     "        return true ? $r(true, true, 'verified', null)", 'mv_differs', 'DLL'),
    ('MV03 absent ohne erfolgreichen Reload', DV, [
        ("    if ($reloadOk && $k['state'] === 'no' && $d['state'] === 'not_auth') {", "    if ($k['state'] === 'no' && $d['state'] === 'not_auth') {"),
        ("    if ($pending !== [] && !$reloadOk) {", "    if (false) {")], None, 'mv_reloadfail', 'DRL'),
    ('MV04 REFUSED ohne Kontrollzone als not_auth', DS, "        if ($c['state'] === 'ok') {", "        if (true) {", 'mv_control', 'CL'),
    ('MV05 Nachweis nach dem Reload nicht erneuert', DV, "                $proof = dnsConfigProof($config, true);\n                $kTransient = $proof",
     "                $proof = dnsConfigProof($config, false);\n                $kTransient = $proof", 'mv_absent', 'L'),
    ('MV06 serial_differs aus früherer Runde', DV, "        if ($o['complete']) {\n            $last = $o;",
     "        if ($o['complete']) {\n            $last ??= $o;", None, 'L'),
    ('MV07 Kontrollzonen-Speicher über Runden', DV, "            $cache = [];\n", "            static $cache = [];\n", None, 'L'),
    ('MV08 Kontrollabfrage je Zone', DS, "        if ($round !== null && isset($round['control']) && ($round['proof'] ?? null) === $proof) {",
     "        if (false) {", 'mv_absent', 'C'),
    ('MV09 Zonenname aus dem Dateinamen', DV, "        $out[$base] = count($cands) === 1 ? ['name' => $cands[0], 'reason' => null]",
     "        $out[$base] = true ? ['name' => dnsNormName($base) ?? $base, 'reason' => null]", 'mv_upper_cl', 'N'),
    ('MV10 Fenster ohne Grenze', DV, "    return min($reloadEnd + VERIFY_WINDOW, $tw - VERIFY_RESPONSE_RESERVE);",
     "    return $reloadEnd + 60.0;", 'mv_window', 'W'),
    ('MV11 Abfrage nach gescheitertem Reload (conf_sync)', CS, "    $verification = $reload['status'] === 'ok'", "    $verification = true", 'mv_cs_reloadfail', None),
    ('MV12 Nachverifikation ändert den HTTP-Status', CS, "    $releaseLock();\n    $reloadReport = ['status' => $reload['status'], 'exit_code' => $reload['exit_code']];",
     "    $releaseLock();\n    if ($verification['state'] !== 'verified') { $reload['status'] = 'failed'; }\n    $reloadReport = ['status' => $reload['status'], 'exit_code' => $reload['exit_code']];",
     'mv_invalid', None),
    ('MV13 Sperre vor der Nachverifikation freigegeben', CS, "    $reload = agentReloadTimed($config);\n    // AS2b (E119, E121, E122)",
     "    $reload = agentReloadTimed($config);\n    $releaseLock();\n    // AS2b (E119, E121, E122)", 'mv_lock', None),
    ('MV14 Vorabnachweis ohne Eintrittsschwelle', DV, "    if (agentRemaining() < VERIFY_PREPROOF_MIN_REMAINING) {", "    if (false) {", 'mv_threshold', 'BU'),
    ('MV15 SERVFAIL als Nachweis', DV, "    if ($d['state'] === 'served' && is_int($d['serial']) && is_int($f['serial'])) {",
     "    if ($d['rcode'] === 'SERVFAIL') {\n        return $r(true, true, 'verified', null);\n    }\n    if ($d['state'] === 'served' && is_int($d['serial']) && is_int($f['serial'])) {", 'mv_servfail', 'DL'),
    ('MV16 still_served ohne letzte Runde (R4)', DV, "    if (in_array($lastComplete['state'], ['serial_differs', 'still_served'], true)) {",
     "    if (true) {", None, 'FL'),
    # Review R1: still_served bei K = ja bzw. strukturell sofort endgültig (wie vor der Korrektur)
    ('MV17 still_served sofort endgültig (R1)', DV, "        return $r(!$reloadOk, true, 'still_served', $why);",
     "        return $r($settled, true, 'still_served', $why);", None, 'DRF'),
    # Review R2: Kontrollabfrage auch ohne DNS-Abfrage gezählt (wie vor der Korrektur)
    ('MV18 Kontrollabfrage ohne DNS-Abfrage gezählt (R2)', DS, "                if (in_array($c['state'], DNS_CONTROL_QUERIED_STATES, true)) {",
     "                if (true) {", 'mv_control', 'C'),
]


def mut_scenarios(a, state, name):
    G = 'MS'
    a.config('enforce')
    if name == 'mv_upper':
        up = 'Mu.as2b-solo.test'
        put_managed_raw(up, zone_text(up.lower(), 1))
        r = a.cs(up)
        check(G, 'upper', v(r).get('state') == 'unknown', json.dumps(v(r)))
    elif name == 'mv_differs':
        n = 'md.as2b-solo.test'
        install(a, n, 3)
        p = put_managed_raw(n, zone_text(n, 4))
        os.chmod(p, 0o600)
        try:
            r = a.cs(n)
        finally:
            os.chmod(p, 0o644)
        check(G, 'differs', v(r).get('state') == 'serial_differs', json.dumps(v(r)))
    elif name == 'mv_reloadfail':
        n = 'mr.as2b-solo.test'
        install(a, n, 1)
        sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
        try:
            r = a.cl(keep_list(state, [n]))
        finally:
            sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
        check(G, 'reloadfail', zr(r).get(n, {}).get('served') != 'absent', json.dumps(zr(r)))
    elif name == 'mv_control':
        n = 'mc.as2b-solo.test'
        install(a, n, 1)
        a.config('enforce', l3_control_zone='fehlt.as2b-solo.test')
        r = a.cl(keep_list(state, [n]))
        check(G, 'control', zr(r).get(n, {}).get('served') == 'unknown' and v(r).get('control_queries') == 0, json.dumps([zr(r), v(r)]))
    elif name == 'mv_absent':
        zs = ['ma1.as2b-solo.test', 'ma2.as2b-solo.test']
        for n in zs:
            install(a, n, 1)
        r = a.cl(keep_list(state, zs))
        check(G, 'absent', all(zr(r).get(n, {}).get('served') == 'absent' for n in zs) and v(r).get('control_queries') == 1, json.dumps([zr(r), v(r)]))
    elif name == 'mv_upper_cl':
        up = 'Mu.as2b-solo.test'
        put_managed_raw(up, zone_text(up.lower(), 1))
        a.cs(up)
        r = a.cl(keep_list(state, [up]))
        check(G, 'upper_cl', zr(r).get(up, {}).get('served') == 'unknown', json.dumps(zr(r)))
    elif name == 'mv_window':
        n = 'mw.as2b-solo.test'
        install(a, n, 1)
        dns_block()
        try:
            r = a.cs(n, timeout=40)
        finally:
            dns_unblock()
        check(G, 'window', r['status'] == 200 and r['dt'] < 25, f"dt={r['dt']:.1f}")
    elif name == 'mv_cs_reloadfail':
        n = 'mf.as2b-solo.test'
        install(a, n, 1)
        sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
        try:
            r = a.cs(n)
        finally:
            sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
        check(G, 'cs_reloadfail', v(r).get('rounds') == 0, json.dumps(v(r)))
    elif name == 'mv_invalid':
        n = 'mi.as2b-solo.test'
        put_managed_raw(n, 'kaputt\n')
        r = a.cs(n)
        check(G, 'invalid', r['status'] == 200 and j(r, 'effect') == 'applied', f"http={r['status']}")
    elif name == 'mv_lock':
        n = 'ml.as2b-solo.test'
        install(a, n, 1)
        held = []

        def probe():
            time.sleep(4.0)
            fh = open(LOCK, 'r')
            try:
                fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
                held.append(False)
                fcntl.flock(fh, fcntl.LOCK_UN)
            except OSError:
                held.append(True)
            fh.close()
        dns_block()
        t = threading.Thread(target=probe, daemon=True)
        t.start()
        try:
            a.cs(n)
        finally:
            dns_unblock()
            t.join()
        check(G, 'lock', held == [True], str(held))
    elif name == 'mv_threshold':
        n = 'mt.as2b-solo.test'
        install(a, n, 1)
        hold_lock_for(4.5)
        r = a.cl(keep_list(state, [n]))
        check(G, 'threshold', (v(r).get('name_proof') or {}).get('state') == 'skipped', json.dumps(v(r)))
    elif name == 'mv_servfail':
        n = 'ms.as2b-solo.test'
        p = put_managed_raw(n, zone_text(n, 1))
        os.chmod(p, 0o600)
        try:
            r = a.cs(n)
        finally:
            os.chmod(p, 0o644)
        check(G, 'servfail', v(r).get('state') == 'unknown', json.dumps(v(r)))


def group_m(state):
    G = 'M'
    for mid, rel, old, new, scen, pure in MUTATIONS:
        dst = os.path.join(A.WORK, 'mut')
        if os.path.exists(dst):
            shutil.rmtree(dst)
        shutil.copytree(A.SRC, dst, ignore=shutil.ignore_patterns('.git'))
        p = os.path.join(dst, rel)
        txt = open(p).read()
        pairs = old if isinstance(old, list) else [(old, new)]
        if any(txt.count(o) != 1 for o, _ in pairs):
            check(G, mid, False, 'Mutationsstelle nicht eindeutig')
            continue
        for o, n in pairs:
            txt = txt.replace(o, n)
        with open(p, 'w') as f:
            f.write(txt)
        before = len([r for r in A.RESULTS if not r[2]])
        killed_by = []
        if pure:
            pr = sh(['php', os.path.join(dst, 'tests', 'agent', 'as2b_pure_test.php'), dst, pure], check_rc=False)
            if pr.returncode != 0:
                killed_by.append('rein:' + ','.join(l.split()[1] for l in pr.stdout.splitlines() if l.startswith('FAIL'))[:120])
        if scen:
            a = A.Agent('mutagent', os.path.join(dst, 'agent'))
            try:
                mut_scenarios(a, state, scen)
            except Exception as e:  # noqa: BLE001
                log(f'  Mutation {mid}: Ausnahme {e}')
                killed_by.append('Ausnahme')
            if len([r for r in A.RESULTS if not r[2]]) > before:
                killed_by.append('live:' + scen)
        A.RESULTS[:] = [r for r in A.RESULTS if r[0] != 'MS']
        reset_testzones(A.Agent('cleanagent', os.path.join(A.SRC, 'agent')), state)
        check(G, mid, bool(killed_by), ('getötet ' + ' '.join(killed_by)) if killed_by else 'ÜBERLEBT')


# ------------------------------------------------------------------------------------------------------------------
# Sicherung, Testzonen, Ablauf
# ------------------------------------------------------------------------------------------------------------------
def backup():
    A.BK = '/root/as2b-test-' + time.strftime('%Y%m%d-%H%M%S')
    os.makedirs(A.BK, mode=0o700)
    with tarfile.open(os.path.join(A.BK, 'etc-bind.tar'), 'w') as t:
        t.add(A.BIND)
    state = {
        'sums': A.tree_sums(), 'perms': A.perms([A.BIND, A.CACHE]), 'cache': sorted(os.listdir(A.CACHE)),
        'zones': sorted(f[:-5] for f in os.listdir(CONFDIR) if f.endswith('.conf')),
        'iptables': sh(['iptables-save']).stdout.count('--dport'),
    }
    state['serials'] = A.all_serials(state['zones'] + ['localhost'])
    with open(os.path.join(A.BK, 'state.json'), 'w') as f:
        json.dump(state, f, indent=1)
    log(f'Sicherung {A.BK}: {len(state["sums"])} Dateien, Zonen {state["zones"]}')
    return state


def reset_testzones(agent, state):
    """Alle AS2b-Testzonen entfernen (Cleanup des Agenten, Fallback direkt) und Hilfskonfiguration leeren."""
    dns_unblock()
    sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP', check_rc=False)
    write_inc([])
    for fn in os.listdir(ZONES):
        p = os.path.join(ZONES, fn)
        if 'as2b' in fn and os.path.isfile(p):
            os.chmod(p, 0o644)
    try:
        agent.config('enforce')
        agent.cl(state['zones'])
    except Exception as e:  # noqa: BLE001
        log(f'  Cleanup über Agent: {e}')
    for d in (ZONES, CONFDIR):
        for fn in os.listdir(d):
            p = os.path.join(d, fn)
            if 'as2b' in fn and (os.path.isfile(p) or os.path.islink(p)):
                os.unlink(p)
    inc = sorted(f for f in os.listdir(CONFDIR) if f.endswith('.conf'))
    with open(ZONESCONF, 'w') as f:
        f.write('\n'.join(f'include "{CONFDIR}/{x}";' for x in inc) + '\n')
    rndc_reload()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--src', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--groups', default='VNRBM')
    args = ap.parse_args()
    A.OUT = args.out
    A.SRC = os.path.abspath(args.src)
    A.WORK = WORK
    os.makedirs(A.OUT, exist_ok=True)
    if os.path.exists(WORK):
        shutil.rmtree(WORK)
    os.makedirs(WORK, mode=0o755)
    log(f'### AS2b {time.strftime("%Y-%m-%d %H:%M:%S")} src={A.SRC} Gruppen={args.groups} {sh("named -v").stdout.strip()}')
    state = backup()
    a = A.Agent('agent', os.path.join(A.SRC, 'agent'))
    try:
        A.init_high(a)
        write_inc([])
        local = open(A.LOCAL).read()
        if A.INC not in local:
            with open(A.LOCAL, 'a') as f:
                f.write(f'include "{A.INC}";\n')
        rndc_reload()
        for g in args.groups:
            log(f'--- Gruppe {g}')
            A.CURRENT[0] = g
            try:
                {'V': lambda: group_v(a, state), 'N': lambda: group_n(a, state), 'R': lambda: group_r(a, state),
                 'B': lambda: group_b(a, state), 'M': lambda: group_m(state)}[g]()
            except Exception as e:  # noqa: BLE001
                check(g, 'AUSNAHME', False, repr(e))
            finally:
                reset_testzones(a, state)
        long = [d for d in A.DURATIONS if d[0] != 'M' and d[2] >= 25]
        check('B', 'B99 jeder Aufruf in observe/enforce < 25 s', not long,
              f"max={max([d[2] for d in A.DURATIONS if d[0] != 'M'] or [0]):.1f} {long}")
        log('Zeiten (Fall, Gesamt s, Runden, Nachverifikation ms, Fenster ms): ' + json.dumps(TIMINGS))
    finally:
        try:
            reset_testzones(a, state)
        except Exception as e:  # noqa: BLE001
            log(f'Cleanup: {e}')
        A.restore(state)
        A.verify_restored(state)
    fails = [r for r in A.RESULTS if not r[2]]
    log(f'### Ergebnis: {len(A.RESULTS) - len(fails)} bestanden, {len(fails)} fehlgeschlagen')
    for r in fails:
        log(f'  FAIL {r[0]}/{r[1]} {r[3]}')
    sys.exit(0 if not fails else 1)


if __name__ == '__main__':
    main()
