#!/usr/bin/env python3
# Datei: tests/agent/as2a_test.py
# Zweck: Abnahmesuite AS2a gegen die INSTALLIERTE BIND-Instanz (named) einer Test-VM (ns2/ns1). Der Agent-Code des
#        Prüfstands läuft als www-data über einen temporären PHP-HTTP-Server (php -S, kein eigener DNS-Server) mit einer
#        Testkonfiguration, die auf das echte /etc/bind, den echten named und rndc zeigt (CLAUDE.md §5). Testzonen tragen
#        das Suffix as2a.test bzw. as2a-solo.test; Konfigurationsvarianten liegen in /etc/bind/named.conf.as2a.
#        Vorher wird /etc/bind vollständig gesichert; am Ende wird der Ausgangszustand wiederhergestellt und nachgewiesen.
#
# Gruppen:
#   E  Vorher-Prüfung nach E103 (Zeilen 1–11, je mit Nachweis "Bestand und DNS unverändert" bei Ablehnung)
#   K  Konfigurationsnachweis (Pfad, Typ, Optionen, Views, allow-new-zones, NZD im directory und new-zones-directory,
#      Zonenzahl, Schlüssel nicht in Ausgaben)
#   D  ausgelieferter Zustand (Elternzone, Delegation, Eltern-ACL, Kontrollzone, SERVFAIL, Timeout)
#   O  Modus observe (Serial-Gründe would_reject, Zustandsfehler weiter abgelehnt)
#   C  Reload, Effekt und Rollback (named gestoppt, Steuerkanal blockiert, Verzeichnis-fsync, conf_sync-Rollback,
#      Cleanup-Teilzustand)
#   T  Fristen (langsames named-checkconf/-checkzone, belegte Agent-Sperre, keine verwaisten Prozesse)
#   G  Modus off gegenüber dem Agenten des Basis-Commits (Golden, einschließlich Fehler- und Timeoutpfaden)
#   M  Mutationsproben
#
# Aufruf (root): as2a_test.py --src <Checkout> --ref <Checkout Basis-Commit> --out <Ergebnisverzeichnis> [--groups EKDOCTGM]

import argparse
import base64
import fcntl
import hashlib
import http.client
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tarfile
import time

TOKEN = 'AS2ATOKEN-0123456789abcdef0123456789abcdef0123456789abcdef0123456'
DOMAIN = '5b2e7c1d-3a4f-4e6b-9c8d-7f1e2a3b4c5d'
HIGH = 1000
BIND = '/etc/bind'
ZONES = '/etc/bind/zones'
CONFDIR = '/etc/bind/zones/conf'
ZONESCONF = '/etc/bind/zones/zones.conf'
INC = '/etc/bind/named.conf.as2a'
INCDIR = '/etc/bind/as2a'
LOCAL = '/etc/bind/named.conf.local'
OPTIONS = '/etc/bind/named.conf.options'
NAMEDCONF = '/etc/bind/named.conf'
CACHE = '/var/cache/bind'
SECRET = 'QVMyYVRFU1RTRUNSRVQtbmljaHQtaW4tQXVzZ2FiZW4tMDEyMzQ1Njc4OWFiY2RlZg=='
WWW = 'www-data'

RESULTS = []
OUT = None
WORK = '/tmp/as2a-run'
BK = None
DURATIONS = []
CURRENT = ['-']
STOP_ON_FAIL = False


def log(msg):
    print(msg, flush=True)
    with open(os.path.join(OUT, 'as2a_test.log'), 'a') as f:
        f.write(msg + '\n')


def check(group, tid, ok, detail=''):
    RESULTS.append((group, tid, bool(ok), detail))
    log(f"{'PASS' if ok else 'FAIL'} {group}/{tid} {detail}")
    if STOP_ON_FAIL and not ok:
        raise StopIteration('erster Fehlschlag')
    return ok


def sh(cmd, check_rc=True, **kw):
    p = subprocess.run(cmd, shell=isinstance(cmd, str), capture_output=True, text=True, **kw)
    if check_rc and p.returncode != 0:
        raise RuntimeError(f"Befehl fehlgeschlagen ({p.returncode}): {cmd}\n{p.stdout}\n{p.stderr}")
    return p


def sha(path):
    if os.path.islink(path):
        return 'LINK:' + os.readlink(path)
    if os.path.isdir(path):
        return 'DIR'
    try:
        with open(path, 'rb') as f:
            return hashlib.sha256(f.read()).hexdigest()
    except FileNotFoundError:
        return None


def orphan_count():
    """Laufende Prozesse externer Programme bzw. der Verzögerungs-Wrapper (ohne den eigenen Prozess)."""
    n = 0
    for pid in os.listdir('/proc'):
        if not pid.isdigit() or int(pid) == os.getpid():
            continue
        try:
            argv = open(f'/proc/{pid}/cmdline', 'rb').read().split(b'\0')
        except OSError:
            continue
        base = os.path.basename(argv[0].decode(errors='replace')) if argv and argv[0] else ''
        if base in ('rndc', 'named-checkconf', 'named-checkzone', 'dig') or any(b'as2a-slow' in a for a in argv):
            n += 1
    return n


def php_lit(v):
    if isinstance(v, dict):
        return '[' + ', '.join(f"{php_lit(k)} => {php_lit(x)}" for k, x in v.items()) + ']'
    if isinstance(v, list):
        return '[' + ', '.join(php_lit(x) for x in v) + ']'
    if isinstance(v, bool):
        return 'true' if v else 'false'
    if isinstance(v, int):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "\\'") + "'"


def zone_text(name, serial, txt='v', extra=()):
    return (f"$TTL 300\n@ IN SOA ns1.{name}. hostmaster.{name}. ( {serial} 3600 1200 1209600 300 )\n"
            f"@ IN NS ns1.{name}.\nns1 IN A 192.0.2.1\ninfo IN TXT \"{txt}\"\n" + ''.join(e + '\n' for e in extra))


def conf_text(name):
    # Format des Generators (writeZoneConfFile, Slave-Modus deaktiviert)
    return f'zone "{name}" {{\n    type master;\n    file "{ZONES}/db.{name}";\n    \n}};'


def managed(name):
    return f'{ZONES}/db.{name}'


# ------------------------------------------------------------------------------------------------------------------
# DNS und BIND
# ------------------------------------------------------------------------------------------------------------------
def dig(name, rtype='SOA'):
    p = sh(['dig', '+norec', '+time=1', '+tries=1', '+nocookie', '@127.0.0.1', name, rtype], check_rc=False)
    m = re.search(r'status: ([A-Z]+)', p.stdout)
    f = re.search(r'flags: ([a-z ]*);', p.stdout)
    serial = None
    for line in p.stdout.splitlines():
        parts = line.split()
        if len(parts) >= 7 and not line.startswith(';') and parts[3] == 'SOA' and parts[0].rstrip('.').lower() == name:
            serial = int(parts[6])
    return {'rcode': m.group(1) if m else 'NONE', 'aa': bool(f and 'aa' in f.group(1).split()), 'serial': serial, 'raw': p.stdout}


def wait_serial(name, serial, timeout=6.0):
    end = time.time() + timeout
    while time.time() < end:
        d = dig(name)
        if d['rcode'] == 'NOERROR' and d['aa'] and d['serial'] == serial:
            return True
        time.sleep(0.1)
    return False


def rndc_reload():
    sh(['rndc', 'reload'])
    time.sleep(1.2)


def reload_count(since):
    p = sh(['journalctl', '-u', 'named', f'--since=@{since:.3f}', '-o', 'cat'], check_rc=False)
    return p.stdout.count("received control channel command 'reload'")


def named_running():
    return sh(['systemctl', 'is-active', 'named'], check_rc=False).stdout.strip() == 'active'


def write_inc(lines):
    with open(INC, 'w') as f:
        f.write('\n'.join(lines) + '\n')
    os.chmod(INC, 0o644)


def inc_zone(name, file, extra=''):
    return f'zone "{name}" {{ type master; file "{file}"; {extra} }};'


def put_incfile(name, serial, txt='inc', extra=()):
    os.makedirs(INCDIR, exist_ok=True)
    os.chmod(INCDIR, 0o755)
    p = f'{INCDIR}/db.{name}'
    with open(p, 'w') as f:
        f.write(zone_text(name, serial, txt, extra))
    os.chmod(p, 0o644)
    return p


def put_managed_raw(name, content, owner=True):
    p = managed(name)
    with open(p, 'w') as f:
        f.write(content)
    os.chmod(p, 0o644)
    if owner:
        shutil.chown(p, WWW, WWW)
    return p


def snapshot(names):
    """Zustand für den Nachweis "Bestand unverändert": verwaltete Dateien, Konfiguration, ausgelieferte SOA."""
    s = {'zonesconf': sha(ZONESCONF)}
    for n in names:
        s[n] = (sha(managed(n)), sha(f'{CONFDIR}/{n}.conf'), dig(n)['rcode'], dig(n)['serial'])
    return s


# ------------------------------------------------------------------------------------------------------------------
# Agent unter php -S (als www-data)
# ------------------------------------------------------------------------------------------------------------------
class Agent:
    def __init__(self, name, agent_tree):
        self.d = os.path.join(WORK, name)
        if os.path.exists(self.d):
            shutil.rmtree(self.d)
        shutil.copytree(agent_tree, os.path.join(self.d, 'agent'), symlinks=False)
        self.root = os.path.join(self.d, 'agent')
        self.cfg = os.path.join(self.root, 'config', 'api_config.php')
        self.phplog = os.path.join(self.d, 'php.log')
        open(self.phplog, 'w').close()
        shutil.chown(self.phplog, WWW, WWW)
        with open(os.path.join(self.root, 'config', 'api_tokens.php'), 'w') as f:
            f.write(f"<?php\nreturn ['{TOKEN}'];\n")
        sh(['chmod', '-R', 'a+rX', self.d])
        self.mode = None
        self.overrides = {}
        self.config('enforce')

    def config(self, mode=None, **over):
        self.mode = mode
        self.overrides = over
        cfg = {
            'api_allowed_ips': [], 'zone_conf_dir': CONFDIR, 'zone_data_dir': ZONES, 'zones_conf_file': ZONESCONF,
            'bind_named_conf_path': NAMEDCONF, 'token_file': os.path.join(self.root, 'config', 'api_tokens.php'),
            'bind_named_checkzone': '/usr/bin/named-checkzone', 'bind_named_checkconf': '/usr/bin/named-checkconf',
            'bind_rndc': '/usr/sbin/rndc', 'bind_dig': '/usr/bin/dig',
        }
        if mode is not None:
            cfg['fencing_mode'] = mode
            cfg['fence_domain'] = DOMAIN
        cfg.update(over)
        with open(self.cfg, 'w') as f:
            f.write("<?php\nreturn " + php_lit(cfg) + ";\n")
        os.chmod(self.cfg, 0o644)

    def start(self, strace=None):
        s = socket.socket()
        s.bind(('127.0.0.1', 0))
        self.port = s.getsockname()[1]
        s.close()
        cmd = ['setpriv', '--reuid=www-data', '--regid=www-data', '--init-groups', 'php', '-d', 'display_errors=0',
               '-d', 'log_errors=1', '-d', f'error_log={self.phplog}', '-S', f'127.0.0.1:{self.port}', '-t', self.root]
        if strace:
            cmd = ['strace', '-qq', '-o', strace['out']] + [a for i in strace['inject'] for a in ('-e', i)] \
                + [a for p in strace.get('paths', []) for a in ('-P', p)] + cmd
        self.p = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        end = time.time() + 10
        while time.time() < end:
            try:
                socket.create_connection(('127.0.0.1', self.port), timeout=0.2).close()
                return
            except OSError:
                time.sleep(0.05)
        raise RuntimeError('php -S nicht gestartet')

    def stop(self):
        import signal
        for sig in (signal.SIGTERM, signal.SIGKILL):
            try:
                os.killpg(self.p.pid, sig)
            except ProcessLookupError:
                return
            try:
                self.p.wait(timeout=5)
                return
            except subprocess.TimeoutExpired:
                continue

    def req(self, ep, body, timeout=40, strace=None):
        self.start(strace)
        t0 = time.time()
        try:
            c = http.client.HTTPConnection('127.0.0.1', self.port, timeout=timeout)
            c.request('POST', f'/api/v1/{ep}', body=json.dumps(body).encode(),
                      headers={'Content-Type': 'application/json', 'Authorization': f'Bearer {TOKEN}'})
            r = c.getresponse()
            raw = r.read()
            try:
                js = json.loads(raw)
            except ValueError:
                js = None
            res = {'status': r.status, 'json': js or {}, 'raw': raw}
        except (OSError, http.client.HTTPException) as e:
            res = {'status': None, 'json': {}, 'raw': b'', 'error': str(e)}
        finally:
            dt = time.time() - t0
            DURATIONS.append((CURRENT[0], ep, dt))
            res_dt = dt
            self.stop()
        res['dt'] = res_dt
        return res

    def fence(self, body):
        if self.mode in ('observe', 'enforce'):
            body['fence_token'] = HIGH
            body['fence_domain'] = DOMAIN
        return body

    def zs(self, name, serial, txt='v', extra=(), repair=None, raw=None, **kw):
        data = raw if raw is not None else zone_text(name, serial, txt, extra)
        b = {'zone_id': 1, 'zone_name': name, 'zone_data': base64.b64encode(data.encode()).decode()}
        if repair is not None:
            b['repair'] = repair
        return self.req('zones/zone_sync.php', self.fence(b), **kw)

    def cs(self, name, text=None, **kw):
        b = {'zone_name': name, 'conf_data': base64.b64encode((text or conf_text(name)).encode()).decode()}
        return self.req('zones/conf_sync.php', self.fence(b), **kw)

    def cl(self, valid, **kw):
        return self.req('zones/zone_cleanup.php', self.fence({'valid_zones': valid}), **kw)

    def status(self, timeout=40):
        self.start()
        t0 = time.time()
        try:
            c = http.client.HTTPConnection('127.0.0.1', self.port, timeout=timeout)
            c.request('GET', '/api/v1/system/status.php', headers={'Authorization': f'Bearer {TOKEN}'})
            r = c.getresponse()
            res = {'status': r.status, 'json': json.loads(r.read() or b'{}')}
        except (OSError, http.client.HTTPException, ValueError) as e:
            res = {'status': None, 'json': {}, 'error': str(e)}
        finally:
            dt = time.time() - t0
            DURATIONS.append((CURRENT[0], 'system/status.php', dt))
            self.stop()
        res['dt'] = dt
        return res


def after_change(path, digest, action, timeout=15):
    """Thread: führt action aus, sobald path den Inhalt digest hat (nach dem rename, vor dem Reload)."""
    import threading

    def run():
        end = time.time() + timeout
        while time.time() < end:
            if sha(path) == digest:
                action()
                log(f'  (Fenster: Aktion nach Übernahme ausgeführt, {time.strftime("%H:%M:%S")})')
                return
            time.sleep(0.02)
    t = threading.Thread(target=run, daemon=True)
    t.start()
    return t


def zs_reload_blocked(a, name, serial, how='drop953'):
    """zone_sync, bei dem der Reload nach erfolgter Übernahme scheitert: rename wird per strace um 2 s verzögert, in
    diesem Fenster wird der Steuerkanal blockiert (drop953) bzw. named gestoppt (stop)."""
    new = hashlib.sha256(zone_text(name, serial).encode()).hexdigest()
    act = {'drop953': lambda: sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP'),
           'stop': lambda: sh('systemctl stop named')}[how]
    t = after_change(managed(name), new, act)
    try:
        delay = 2000000 if how == 'drop953' else 8000000   # systemctl stop named braucht mehrere Sekunden
        # strace -P erfasst rename nicht über den Zielpfad (gemessen) ⇒ alle rename-Aufrufe verzögern; zone_sync führt
        # mit Token = H genau ein rename aus (Temp-Datei → Zonendatei)
        r = a.zs(name, serial, strace={'out': os.path.join(WORK, 'rb.strace'),
                                       'inject': ['trace=rename,renameat,renameat2', f'inject=rename,renameat,renameat2:delay_exit={delay}']})
    finally:
        t.join(timeout=20)
        if how == 'drop953':
            sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP', check_rc=False)
        else:
            sh('systemctl start named')
            time.sleep(3)
    return r


def j(r, key, default=None):
    return (r.get('json') or {}).get(key, default)


def sc(r, key):
    return (j(r, 'serial_check') or {}).get(key)


# ------------------------------------------------------------------------------------------------------------------
# Sicherung, Ausgangszustand, Rückbau
# ------------------------------------------------------------------------------------------------------------------
def tree_sums():
    out = {}
    for base in (BIND,):
        for d, _, files in os.walk(base):
            for fn in files:
                p = os.path.join(d, fn)
                out[p] = 'LINK' if os.path.islink(p) else sha(p)
    return out


def perms(paths):
    out = {}
    for base in paths:
        for d, dirs, files in os.walk(base):
            for fn in dirs + files:
                p = os.path.join(d, fn)
                st = os.lstat(p)
                out[p] = (oct(st.st_mode), st.st_uid, st.st_gid)
    return out


def all_serials(names):
    return {n: dig(n)['serial'] for n in names}


def backup():
    global BK
    BK = '/root/as2a-test-' + time.strftime('%Y%m%d-%H%M%S')
    os.makedirs(BK, mode=0o700)
    with tarfile.open(os.path.join(BK, 'etc-bind.tar'), 'w') as t:
        t.add(BIND)
    state = {
        'sums': tree_sums(), 'perms': perms([BIND, CACHE]), 'cache': sorted(os.listdir(CACHE)),
        'zones': sorted(f[:-5] for f in os.listdir(CONFDIR) if f.endswith('.conf')),
        'iptables': sh(['iptables-save']).stdout.count('--dport'),
    }
    state['serials'] = all_serials(state['zones'] + ['localhost'])
    with open(os.path.join(BK, 'state.json'), 'w') as f:
        json.dump(state, f, indent=1)
    log(f'Sicherung {BK}: {len(state["sums"])} Dateien, Zonen {state["zones"]}')
    return state


def restore(state):
    """Ausgangszustand wiederherstellen und nachweisen (auch nach Abbruch)."""
    for rule in ('udp', 'tcp'):
        for port in ('53', '953'):
            while sh(['iptables', '-D', 'INPUT', '-i', 'lo', '-p', rule, '--dport', port, '-j', 'DROP'], check_rc=False).returncode == 0:
                pass
    for p in [os.path.join(d, f) for d, _, fs in os.walk(ZONES) for f in fs]:
        sh(['chattr', '-i', p], check_rc=False)
    if not named_running():
        sh(['systemctl', 'start', 'named'], check_rc=False)
        time.sleep(3)
    # Dateien wie in der Sicherung: geänderte zurückspielen, zusätzliche entfernen
    with tarfile.open(os.path.join(BK, 'etc-bind.tar')) as t:
        members = {'/' + m.name: m for m in t.getmembers()}
        for path, digest in state['sums'].items():
            if tree_sums_one(path) != digest:
                t.extract(members[path], '/', filter='fully_trusted')
        for d, _, files in os.walk(BIND):
            for fn in files:
                p = os.path.join(d, fn)
                if p not in state['sums']:
                    os.unlink(p)
    if os.path.isdir(INCDIR):
        shutil.rmtree(INCDIR)
    for p, (mode, uid, gid) in state['perms'].items():
        if os.path.lexists(p) and p.startswith(BIND) and not os.path.islink(p):
            os.chmod(p, int(mode, 8) & 0o7777)
            os.chown(p, uid, gid)
    sh('rndc reload', check_rc=False)
    time.sleep(2)
    for fn in os.listdir(CACHE):
        if fn not in state['cache'] and (fn.startswith('tmp-') or re.search(r'\.(nz[df]|mkeys)', fn) or fn.startswith('as2a')):
            p = os.path.join(CACHE, fn)
            if os.path.isdir(p):
                shutil.rmtree(p)
            else:
                os.unlink(p)


def tree_sums_one(p):
    if not os.path.lexists(p):
        return None
    return 'LINK' if os.path.islink(p) else sha(p)


def verify_restored(state):
    G = 'Z'
    check(G, 'Z01 /etc/bind sha256-gleich', tree_sums() == state['sums'])
    cur = perms([BIND, CACHE])
    check(G, 'Z02 Rechte /etc/bind', {k: v for k, v in cur.items() if k.startswith(BIND)} == {k: v for k, v in state['perms'].items() if k.startswith(BIND)})
    check(G, 'Z03 /var/cache/bind Bestand', sorted(os.listdir(CACHE)) == state['cache'], str(sorted(set(os.listdir(CACHE)) ^ set(state['cache']))))
    check(G, 'Z04 Serials unverändert', all_serials(state['zones'] + ['localhost']) == state['serials'])
    check(G, 'Z05 iptables', sh(['iptables-save']).stdout.count('--dport') == state['iptables'])
    check(G, 'Z06 named-checkconf', sh(['named-checkconf'], check_rc=False).returncode == 0)
    check(G, 'Z07 keine verwaisten Prozesse', orphan_count() == 0)
    check(G, 'Z08 keine Fencing-Dateien', not any(os.path.lexists(f'{ZONES}/{f}') for f in ('.dnsmanager-fence', '.dnsmanager-fence.fault')))


def reset_testzones(agent, state):
    """Alle Testzonen entfernen (über den Cleanup des Agenten, Fallback direkt) und Hilfskonfiguration leeren."""
    write_inc([])
    for d, _, files in os.walk(ZONES):
        for fn in files:
            sh(['chattr', '-i', os.path.join(d, fn)], check_rc=False)
    r = agent.cl(state['zones'])
    for fn in os.listdir(ZONES):
        if 'as2a' in fn:
            p = os.path.join(ZONES, fn)
            if os.path.islink(p) or os.path.isfile(p):
                os.unlink(p)
            elif os.path.isdir(p):
                os.chmod(p, 0o755)
                shutil.rmtree(p)
    for fn in os.listdir(CONFDIR):
        if 'as2a' in fn:
            os.unlink(os.path.join(CONFDIR, fn))
    rndc_reload()
    return r


def init_high(agent):
    p = sh(['setpriv', '--reuid=www-data', '--regid=www-data', '--init-groups', 'php', os.path.join(SRC, 'scripts', 'agent_fence-cli.php'),
            f'--config={agent.cfg}', 'init', f'--domain={DOMAIN}', f'--high={HIGH}'], check_rc=False)
    if p.returncode != 0 and 'init nur ohne' not in (p.stdout + p.stderr):
        raise RuntimeError('CLI init: ' + p.stdout + p.stderr)


SRC = None


# ------------------------------------------------------------------------------------------------------------------
# Gruppe E – Vorher-Prüfung (E103)
# ------------------------------------------------------------------------------------------------------------------
def unchanged(G, tid, before, names, r, exp_code, exp_http):
    after = snapshot(names)
    ok = r['status'] == exp_http and j(r, 'code') == exp_code and j(r, 'effect') == 'none' and before == after
    return check(G, tid, ok, f"http={r['status']} code={j(r, 'code')} effect={j(r, 'effect')} row={sc(r, 'row')} "
                            f"reason={sc(r, 'config_reason') or sc(r, 'served_reason')} unverändert={before == after} dt={r['dt']:.1f}")


def group_e(a, state):
    G = 'E'
    a.config('enforce')
    n1 = 'n1.as2a-solo.test'
    # E06: neue Zone (Datei fehlt, nicht konfiguriert, nicht ausgeliefert) ⇒ Installation; danach conf_sync
    r = a.zs(n1, 10)
    check(G, 'E06a Neuinstallation', r['status'] == 200 and j(r, 'effect') == 'applied' and sc(r, 'row') == 6
          and sha(managed(n1)) == hashlib.sha256(zone_text(n1, 10).encode()).hexdigest()
          and (j(r, 'installed') or {}).get('serial') == 10, f"http={r['status']} row={sc(r, 'row')} {j(r, 'message')}")
    r = a.cs(n1)
    check(G, 'E06b conf_sync', r['status'] == 200 and j(r, 'effect') == 'applied' and (j(r, 'reload') or {}).get('exit_code') == 0, f"http={r['status']}")
    check(G, 'E06c ausgeliefert', wait_serial(n1, 10))
    # E10: Datei gültig, konfiguriert, ausgeliefert
    r = a.zs(n1, 11)
    check(G, 'E10a newer', r['status'] == 200 and sc(r, 'row') == 10 and sc(r, 'cmp_file') == 'newer' and wait_serial(n1, 11))
    ino = os.stat(managed(n1)).st_ino
    since = time.time()
    r = a.zs(n1, 11)
    check(G, 'E10b idempotent', r['status'] == 200 and j(r, 'effect') == 'unchanged' and os.stat(managed(n1)).st_ino == ino
          and reload_count(since) == 1, f"effect={j(r, 'effect')}")
    b = snapshot([n1])
    unchanged(G, 'E10c gleiche Serial, anderer Inhalt', b, [n1], a.zs(n1, 11, txt='anders'), 'serial_conflict', 409)
    unchanged(G, 'E10d older', b, [n1], a.zs(n1, 9), 'stale_serial', 409)
    unchanged(G, 'E10e undefined (2^31)', b, [n1], a.zs(n1, 11 + 2 ** 31), 'serial_undefined', 409)
    # Datei neuer als ausgeliefert (nicht geladen)
    put_managed_raw(n1, zone_text(n1, 20))
    b = snapshot([n1])
    unchanged(G, 'E10f older als Datei, newer als ausgeliefert', b, [n1], a.zs(n1, 15), 'stale_serial', 409)
    r = a.zs(n1, 21)
    check(G, 'E10g newer als beide', r['status'] == 200 and wait_serial(n1, 21))
    # E09 (Fall B): Datei gelöscht, Zone geladen
    os.unlink(managed(n1))
    b = snapshot([n1])
    since = time.time()
    unchanged(G, 'E09a Fall B ältere Serial', b, [n1], a.zs(n1, 12), 'stale_serial', 409)
    check(G, 'E09b kein Reload, Zone weiter mit 21', reload_count(since) == 0 and dig(n1)['serial'] == 21)
    unchanged(G, 'E09c gleiche Serial ohne Datei', b, [n1], a.zs(n1, 21), 'serial_conflict', 409)
    r = a.zs(n1, 22)
    check(G, 'E09d newer', r['status'] == 200 and sc(r, 'row') == 9 and wait_serial(n1, 22))
    # E07: Datei vorhanden, nicht konfiguriert (Rest einer gelöschten Zone)
    n2 = 'n2.as2a-solo.test'
    put_managed_raw(n2, zone_text(n2, 50))
    b = snapshot([n2])
    unchanged(G, 'E07a older als Datei', b, [n2], a.zs(n2, 40), 'stale_serial', 409)
    unchanged(G, 'E07b gleiche Serial, anders', b, [n2], a.zs(n2, 50, txt='anders'), 'serial_conflict', 409)
    r = a.zs(n2, 50)
    check(G, 'E07c idempotent', r['status'] == 200 and j(r, 'effect') == 'unchanged' and sc(r, 'row') == 7)
    r = a.zs(n2, 60)
    check(G, 'E07d newer', r['status'] == 200 and sc(r, 'row') == 7 and sha(managed(n2)) == hashlib.sha256(zone_text(n2, 60).encode()).hexdigest())
    # E08: ungültige, lesbare Datei, nicht konfiguriert
    n3 = 'n3.as2a-solo.test'
    put_managed_raw(n3, 'kein gültiger Zoneninhalt\n')
    h3 = sha(managed(n3))
    b = snapshot([n3])
    unchanged(G, 'E08a ohne Reparatur', b, [n3], a.zs(n3, 5), 'zone_state_invalid', 409)
    unchanged(G, 'E08b falscher Hash', b, [n3], a.zs(n3, 5, repair={'expect_file': '0' * 64, 'expect_served': 'not_auth'}), 'repair_mismatch', 409)
    unchanged(G, 'E08c falsches expect_served', b, [n3], a.zs(n3, 5, repair={'expect_file': h3, 'expect_served': 4}), 'repair_mismatch', 409)
    r = a.zs(n3, 5, repair={'expect_file': h3, 'expect_served': 'not_auth'})
    check(G, 'E08d passende Reparatur', r['status'] == 200 and sc(r, 'row') == 8)
    unchanged(G, 'E08e Reparatur bei gültiger Datei', snapshot([n3]), [n3], a.zs(n3, 6, repair={'expect_file': sha(managed(n3)), 'expect_served': 'not_auth'}), 'repair_mismatch', 409)
    # E11: ungültige Datei, konfiguriert und ausgeliefert (22)
    put_managed_raw(n1, 'kaputt\n')
    h1 = sha(managed(n1))
    b = snapshot([n1])
    unchanged(G, 'E11a ohne Reparatur', b, [n1], a.zs(n1, 23), 'zone_state_invalid', 409)
    unchanged(G, 'E11b Reparatur, N älter als ausgeliefert', b, [n1], a.zs(n1, 21, repair={'expect_file': h1, 'expect_served': 22}), 'stale_serial', 409)
    unchanged(G, 'E11c Reparatur mit falscher ausgelieferter Serial', b, [n1], a.zs(n1, 23, repair={'expect_file': h1, 'expect_served': 21}), 'repair_mismatch', 409)
    r = a.zs(n1, 23, repair={'expect_file': h1, 'expect_served': 22})
    check(G, 'E11d Reparatur', r['status'] == 200 and sc(r, 'row') == 11 and wait_serial(n1, 23))
    # E01: Datei nicht lesbar bzw. keine reguläre Datei
    n4 = 'n4.as2a-solo.test'
    os.symlink(managed(n1), managed(n4))
    b = snapshot([n4])
    unchanged(G, 'E01a Symlink', b, [n4], a.zs(n4, 1), 'zone_state_unreadable', 503)
    os.unlink(managed(n4))
    os.mkdir(managed(n4))
    unchanged(G, 'E01b Verzeichnis', snapshot([n4]), [n4], a.zs(n4, 1), 'zone_state_unreadable', 503)
    os.rmdir(managed(n4))
    put_managed_raw(n4, zone_text(n4, 1), owner=False)
    os.chmod(managed(n4), 0o000)
    unchanged(G, 'E01c nicht lesbar', snapshot([n4]), [n4], a.zs(n4, 2), 'zone_state_unreadable', 503)
    os.unlink(managed(n4))
    # E03: konfiguriert, Datei fehlt, nicht geladen (SERVFAIL) ⇒ unknown, keine Reparatur
    m1 = 'm1.as2a-solo.test'
    r = a.cs(m1)
    check(G, 'E03a conf_sync ohne Datei', r['status'] == 200 and dig(m1)['rcode'] == 'SERVFAIL', f"dig={dig(m1)['rcode']}")
    b = snapshot([m1])
    r = a.zs(m1, 1)
    unchanged(G, 'E03b SERVFAIL ⇒ served_state_unknown', b, [m1], r, 'served_state_unknown', 503)
    check(G, 'E03c Zeile 3, Grund', sc(r, 'row') == 3 and sc(r, 'served_reason') == 'configured_not_served' and sc(r, 'served_rcode') == 'SERVFAIL')
    unchanged(G, 'E03d keine Reparatur über SERVFAIL', b, [m1], a.zs(m1, 1, repair={'expect_file': 'missing', 'expect_served': 'not_auth'}), 'served_state_unknown', 503)


# ------------------------------------------------------------------------------------------------------------------
# Gruppe D – ausgelieferter Zustand
# ------------------------------------------------------------------------------------------------------------------
def group_d(a, state):
    G = 'D'
    a.config('enforce')
    par = 'par.as2a.test'
    pf = put_incfile(par, 7, extra=['dlg IN NS ns.dlg', 'ns.dlg IN A 192.0.2.7'])
    write_inc([inc_zone(par, pf)])
    rndc_reload()
    # Elternzone liefert NXDOMAIN ⇒ not_auth ⇒ Neuinstallation zulässig
    r = a.zs('x.par.as2a.test', 1)
    check(G, 'D01 Elternzone NXDOMAIN ⇒ Installation', r['status'] == 200 and sc(r, 'served_reason') == 'parent_negative', f"{r['status']} {sc(r, 'served_reason')}")
    r = a.zs('dlg.par.as2a.test', 1)
    check(G, 'D02 Delegation (Referral) ⇒ Installation', r['status'] == 200 and sc(r, 'served_reason') == 'parent_referral', f"{r['status']} {sc(r, 'served_reason')}")
    # Elternzone verweigert per allow-query ⇒ REFUSED ⇒ unknown
    write_inc([inc_zone(par, pf, 'allow-query { none; };')])
    rndc_reload()
    c = 'y.par.as2a.test'
    unchanged(G, 'D03 Eltern-ACL REFUSED ⇒ unknown', snapshot([c]), [c], a.zs(c, 1), 'served_state_unknown', 503)
    # Kontrollzone fehlt bzw. mit eigener ACL ⇒ REFUSED ohne Elternzone nicht nachweisbar
    write_inc([inc_zone('ctl.as2a-solo.test', put_incfile('ctl.as2a-solo.test', 1), 'allow-query { any; };')])
    rndc_reload()
    s = 'solo.as2a-solo.test'
    a.config('enforce', l3_control_zone='fehlt.as2a-solo.test')
    r = a.zs(s, 1)
    unchanged(G, 'D04 Kontrollzone fehlt', snapshot([s]), [s], r, 'served_state_unknown', 503)
    check(G, 'D04b Grund', sc(r, 'served_reason') == 'control_zone_missing', str(sc(r, 'served_reason')))
    a.config('enforce', l3_control_zone='ctl.as2a-solo.test')
    r = a.zs(s, 1)
    check(G, 'D05 Kontrollzone mit eigener ACL', r['status'] == 503 and sc(r, 'served_reason') == 'control_zone_own_acl', str(sc(r, 'served_reason')))
    a.config('enforce')
    st = a.status()
    check(G, 'D06 system/status Kontrollzone ok', (st['json'].get('verification') or {}).get('control_zone') == 'ok'
          and st['json'].get('capabilities', {}).get('serial_rule') == 1, json.dumps(st['json'].get('verification')))
    a.config('enforce', l3_control_zone='fehlt.as2a-solo.test')
    st = a.status()
    check(G, 'D07 system/status Kontrollzone fehlt', (st['json'].get('verification') or {}).get('control_zone') == 'missing' and st['status'] == 200)
    a.config('enforce')
    # Timeout (DNS-Port blockiert) ⇒ unknown
    sh('iptables -I INPUT -i lo -p udp --dport 53 -j DROP; iptables -I INPUT -i lo -p tcp --dport 53 -j DROP')
    try:
        r = a.zs(s, 1)
    finally:
        sh('iptables -D INPUT -i lo -p udp --dport 53 -j DROP; iptables -D INPUT -i lo -p tcp --dport 53 -j DROP')
    check(G, 'D08 Timeout ⇒ served_state_unknown', r['status'] == 503 and j(r, 'code') == 'served_state_unknown'
          and j(r, 'effect') == 'none' and r['dt'] < 25, f"{sc(r, 'served_reason')} dt={r['dt']:.1f}")
    write_inc([])
    rndc_reload()


# ------------------------------------------------------------------------------------------------------------------
# Gruppe K – Konfigurationsnachweis
# ------------------------------------------------------------------------------------------------------------------
def k_case(G, tid, a, name, reason, serial=1):
    b = snapshot([name])
    r = a.zs(name, serial)
    unchanged(G, tid, b, [name], r, 'served_state_unknown', 503)
    return check(G, tid + ' Grund', sc(r, 'config_reason') == reason, f"{sc(r, 'config_reason')}")


def group_k(a, state):
    G = 'K'
    a.config('enforce')
    n1 = 'n1.as2a-solo.test'
    # M1: file zeigt auf fremde Datei (gleiche Serial, anderer Inhalt)
    fp = 'fp.as2a-solo.test'
    put_managed_raw(fp, zone_text(fp, 100, 'verwaltet'))
    write_inc([inc_zone(fp, put_incfile(fp, 100, 'fremd'))])
    rndc_reload()
    k_case(G, 'K01 fremder Dateipfad', a, fp, 'zone_file_mismatch', serial=101)
    write_inc([inc_zone('rel.as2a-solo.test', 'as2a-rel.db')])
    with open(f'{CACHE}/as2a-rel.db', 'w') as f:
        f.write(zone_text('rel.as2a-solo.test', 3))
    rndc_reload()
    k_case(G, 'K02 relativer Pfad (aufgelöst, fremd)', a, 'rel.as2a-solo.test', 'zone_file_mismatch')
    write_inc([f'zone "sec.as2a-solo.test" {{ type secondary; primaries {{ 192.0.2.53; }}; file "{ZONES}/db.sec.as2a-solo.test"; }};'])
    rndc_reload()
    k_case(G, 'K03 Typ secondary', a, 'sec.as2a-solo.test', 'zone_type_unsupported')
    o1 = 'opt.as2a-solo.test'
    put_managed_raw(o1, zone_text(o1, 1))
    write_inc([inc_zone(o1, managed(o1), 'notify no;')])
    rndc_reload()
    k_case(G, 'K04 zusätzliche Option', a, o1, 'zone_options_unsupported', serial=2)
    write_inc([inc_zone(o1, managed(o1), 'allow-query { 127.0.0.1; };')])
    rndc_reload()
    k_case(G, 'K05 eigene allow-query (Kind-ACL)', a, o1, 'zone_options_unsupported', serial=2)
    write_inc([inc_zone(o1, f'{ZONES}/../zones/db.{o1}')])
    rndc_reload()
    k_case(G, 'K06 Pfad mit ..', a, o1, 'zone_file_mismatch', serial=2)
    write_inc([])
    os.unlink(managed(o1))
    rndc_reload()
    # Fall F: Konfiguration ohne Reload geändert (Zonenzahl weicht ab)
    write_inc([inc_zone('f1.as2a-solo.test', put_incfile('f1.as2a-solo.test', 1))])
    k_case(G, 'K07 Zonenzahl weicht ab', a, n1, 'zone_count_mismatch', serial=30)
    # Zonen vertauscht ohne Reload: Zahl gleich, Zone nicht konfiguriert, aber ausgeliefert (Zeile 4)
    rndc_reload()
    write_inc([inc_zone('f2.as2a-solo.test', put_incfile('f2.as2a-solo.test', 1))])
    b = snapshot(['f1.as2a-solo.test'])
    r = a.zs('f1.as2a-solo.test', 2)
    unchanged(G, 'K08 Widerspruch K=nein, ausgeliefert (Zeile 4)', b, ['f1.as2a-solo.test'], r, 'served_state_unknown', 503)
    check(G, 'K08b Zeile', sc(r, 'row') == 4, str(sc(r, 'row')))
    write_inc([])
    rndc_reload()
    # allow-new-zones
    opts = open(OPTIONS).read()
    try:
        with open(OPTIONS, 'w') as f:
            f.write(opts.replace('recursion no;', 'recursion no;\n        allow-new-zones yes;'))
        rndc_reload()
        k_case(G, 'K09 allow-new-zones', a, n1, 'allow_new_zones', serial=30)
    finally:
        with open(OPTIONS, 'w') as f:
            f.write(opts)
        rndc_reload()
    # Befund: allow-new-zones legt die NZD-Datenbank an, auch ohne addzone; sie bleibt nach dem Abschalten liegen
    created = [f for f in os.listdir(CACHE) if re.search(r'\.nz[df]', f) and f not in state['cache']]
    k_case(G, 'K09b liegen gebliebene NZD nach allow-new-zones', a, n1, 'nzd_present', serial=30)
    check(G, 'K09c NZD von BIND angelegt', created != [], str(created))
    for f in created:
        os.unlink(os.path.join(CACHE, f))
    rndc_reload()
    # NZD im directory
    nzd = f'{CACHE}/as2a-test.nzd'
    open(nzd, 'w').close()
    try:
        k_case(G, 'K10 NZD im directory', a, n1, 'nzd_present', serial=30)
    finally:
        os.unlink(nzd)
    # new-zones-directory: leer ⇒ kein Befund; mit NZD ⇒ unbestimmbar (Suche nicht nur im directory)
    nzdir = f'{CACHE}/as2a-nzd'
    os.makedirs(nzdir, exist_ok=True)
    shutil.chown(nzdir, 'bind', 'bind')
    try:
        with open(OPTIONS, 'w') as f:
            f.write(opts.replace('recursion no;', f'recursion no;\n        new-zones-directory "{nzdir}";'))
        ok = sh(['named-checkconf'], check_rc=False).returncode == 0
        rndc_reload()
        r = a.zs(n1, 30)
        check(G, 'K11 new-zones-directory leer ⇒ Prüfung läuft weiter', ok and r['status'] == 200 and sc(r, 'config_state') == 'yes',
              f"checkconf={ok} {r['status']} {sc(r, 'config_reason')}")
        open(f'{nzdir}/_default.nzf', 'w').close()
        k_case(G, 'K12 NZF im new-zones-directory', a, n1, 'nzd_present', serial=31)
        with open(OPTIONS, 'w') as f:
            f.write(opts.replace('recursion no;', 'recursion no;\n        new-zones-directory "as2a-nzd";'))
        rndc_reload()
        k_case(G, 'K13 relatives new-zones-directory', a, n1, 'nzd_present', serial=31)
    finally:
        with open(OPTIONS, 'w') as f:
            f.write(opts)
        shutil.rmtree(nzdir, ignore_errors=True)
        rndc_reload()
    # Views
    nc = open(NAMEDCONF).read()
    try:
        with open(NAMEDCONF, 'w') as f:
            f.write('include "/etc/bind/named.conf.options";\nview "as2a" {\n  match-clients { any; };\n'
                    '  include "/etc/bind/named.conf.local";\n  include "/etc/bind/named.conf.root-hints";\n};\n')
        rndc_reload()
        k_case(G, 'K14 Views', a, n1, 'views_present', serial=31)
    finally:
        with open(NAMEDCONF, 'w') as f:
            f.write(nc)
        rndc_reload()
        for fn in os.listdir(CACHE):
            if fn.startswith('as2a.mkeys') or fn.startswith('tmp-'):
                p = os.path.join(CACHE, fn)
                if os.path.isfile(p) and fn not in state['cache']:
                    os.unlink(p)
    # Schlüssel: named-checkconf -px maskiert, keine Ausgabe/Protokollierung des Geheimnisses
    write_inc([f'key "as2a-key" {{ algorithm hmac-sha256; secret "{SECRET}"; }};'])
    rndc_reload()
    r = a.zs(n1, 32)
    leak = SECRET.encode() in r['raw'] or SECRET in open(a.phplog).read()
    check(G, 'K15 Geheimnis nicht in Antwort oder Log', r['status'] == 200 and not leak, f"http={r['status']}")
    write_inc([])
    rndc_reload()


# ------------------------------------------------------------------------------------------------------------------
# Gruppe O – observe
# ------------------------------------------------------------------------------------------------------------------
def group_o(a, state):
    G = 'O'
    n1 = 'n1.as2a-solo.test'
    a.config('observe')
    cur = dig(n1)['serial']
    r = a.zs(n1, cur - 1)
    check(G, 'O01 observe: older ⇒ would_reject, Übernahme', r['status'] == 200 and sc(r, 'decision') == 'would_reject'
          and sc(r, 'code') == 'stale_serial' and wait_serial(n1, cur - 1), f"{r['status']} {sc(r, 'decision')}")
    m1 = 'm1.as2a-solo.test'
    b = snapshot([m1])
    unchanged(G, 'O02 observe: SERVFAIL weiter abgelehnt', b, [m1], a.zs(m1, 2), 'served_state_unknown', 503)
    put_managed_raw('n5.as2a-solo.test', 'kaputt\n')
    b = snapshot(['n5.as2a-solo.test'])
    unchanged(G, 'O03 observe: ungültige Datei weiter abgelehnt', b, ['n5.as2a-solo.test'], a.zs('n5.as2a-solo.test', 1), 'zone_state_invalid', 409)
    os.unlink(managed('n5.as2a-solo.test'))
    a.config('enforce')
    r = a.zs(n1, cur + 1)
    check(G, 'O04 enforce danach regulär', r['status'] == 200 and wait_serial(n1, cur + 1))


# ------------------------------------------------------------------------------------------------------------------
# Gruppe C – Reload, Effekt, Rollback, Cleanup-Teilzustand
# ------------------------------------------------------------------------------------------------------------------
def group_c(a, state):
    G = 'C'
    a.config('enforce')
    n1 = 'n1.as2a-solo.test'
    cur = dig(n1)['serial']
    # C1 named gestoppt ⇒ reload_failed, Datei bleibt (vorwärts), nach Start geladen
    sh('systemctl stop named')
    try:
        # Vorher-Prüfung braucht den laufenden BIND ⇒ Abbruch vor jeder Änderung
        b = {'f': sha(managed(n1))}
        r = a.zs(n1, cur + 1)
        check(G, 'C01 named gestoppt vor der Prüfung ⇒ keine Änderung', r['status'] == 503 and j(r, 'effect') == 'none'
              and sha(managed(n1)) == b['f'], f"{r['status']} {j(r, 'code')} {sc(r, 'config_reason') or sc(r, 'served_reason')}")
    finally:
        sh('systemctl start named')
        time.sleep(3)
    # C1b named nach der Übernahme gestoppt ⇒ reload_failed, effect applied, kein Rollback; nach Start vorwärts geladen
    r = zs_reload_blocked(a, n1, cur + 1, 'stop')
    check(G, 'C01b reload_failed nach Übernahme', r['status'] == 500 and j(r, 'code') == 'reload_failed' and j(r, 'effect') == 'applied'
          and j(r, 'rollback') == 'not_attempted' and j(r, 'bind_state') == 'unknown'
          and sha(managed(n1)) == hashlib.sha256(zone_text(n1, cur + 1).encode()).hexdigest(), f"{r['status']} {j(r, 'code')} {j(r, 'effect')}")
    check(G, 'C01c nach Start vorwärts geladen', wait_serial(n1, cur + 1))
    cur += 1
    # C2 Steuerkanal vor dem Reload blockiert ⇒ Frist, reload_timeout, effect applied, keine verwaisten Prozesse
    r = zs_reload_blocked(a, n1, cur + 1, 'drop953')
    orphans = str(orphan_count())
    check(G, 'C02 reload_timeout', r['status'] == 500 and j(r, 'code') == 'reload_timeout' and j(r, 'effect') == 'applied'
          and j(r, 'rollback') == 'not_attempted' and j(r, 'bind_state') == 'unknown' and orphans == '0' and r['dt'] < 12,
          f"http={r['status']} code={j(r, 'code')} effect={j(r, 'effect')} dt={r['dt']:.1f} verwaist={orphans}")
    rndc_reload()
    check(G, 'C02c nächster Reload lädt vorwärts', wait_serial(n1, cur + 1))
    # C2d Steuerkanal schon vor der Prüfung blockiert ⇒ rndc status (Zonenzahl) scheitert ⇒ Abbruch ohne Änderung
    b = snapshot([n1])
    sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
    try:
        r = a.zs(n1, cur + 2)
    finally:
        sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
    check(G, 'C02d rndc status blockiert ⇒ keine Änderung', r['status'] == 503 and j(r, 'effect') == 'none' and snapshot([n1]) == b
          and sc(r, 'config_reason') == 'rndc_status_timeout', f"{sc(r, 'config_reason')} dt={r['dt']:.1f}")
    cur = dig(n1)['serial']
    # C3 Verzeichnis-fsync nach rename scheitert ⇒ persist_unconfirmed, rollback not_attempted, effect unknown
    tr = os.path.join(WORK, 'c3.strace')
    r = a.zs(n1, cur + 1, strace={'out': tr, 'inject': ['inject=fsync:error=EIO:when=1'], 'paths': [ZONES]})
    check(G, 'C03 persist_unconfirmed', r['status'] == 500 and j(r, 'code') == 'persist_unconfirmed' and j(r, 'rollback') == 'not_attempted'
          and j(r, 'effect') == 'unknown', f"{r['status']} {j(r, 'code')} {j(r, 'rollback')} {j(r, 'effect')}")
    rndc_reload()
    cur = dig(n1)['serial']
    # C4 conf_sync: Preflight scheitert ⇒ Rollback ok ⇒ effect none, Bestand unverändert
    b = snapshot([n1])
    r = a.cs(n1, text='zone "n1.as2a-solo.test" { type master; file ; };')
    check(G, 'C04 conf_sync Rollback ⇒ none', r['status'] == 422 and j(r, 'rollback') == 'ok' and j(r, 'effect') == 'none'
          and snapshot([n1]) == b, f"{r['status']} {j(r, 'rollback')} {j(r, 'effect')}")
    # C5 conf_sync, Reload scheitert (Steuerkanal blockiert) ⇒ effect applied, kein Rollback
    sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
    try:
        r = a.cs(n1)
    finally:
        sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
    check(G, 'C05 conf_sync reload_timeout', r['status'] == 500 and j(r, 'code') == 'reload_timeout' and j(r, 'effect') == 'applied'
          and j(r, 'rollback') == 'not_attempted' and r['dt'] < 25, f"{r['status']} {j(r, 'code')} dt={r['dt']:.1f}")
    rndc_reload()
    # C6 Cleanup mit fünf Zonen: drei entfernt, eine bleibt (immutable), eine unbekannt (stat EIO) ⇒ partial
    zs5 = [f'cz{i}.as2a-solo.test' for i in range(1, 6)]
    for z in zs5:
        a.zs(z, 1)
        a.cs(z)
    keep = state['zones'] + [n1, 'm1.as2a-solo.test', 'n2.as2a-solo.test', 'n3.as2a-solo.test', 'x.par.as2a.test', 'dlg.par.as2a.test']
    keep = [z for z in keep if os.path.exists(f'{CONFDIR}/{z}.conf')]
    sh(['chattr', '+i', managed(zs5[3])])
    tr = os.path.join(WORK, 'c6.strace')
    try:
        r = a.cl(keep, strace={'out': tr, 'inject': ['inject=access,faccessat,faccessat2:error=EIO'], 'paths': [managed(zs5[4])]})
    finally:
        sh(['chattr', '-i', managed(zs5[3])])
    zr = {x['zone']: x for x in (j(r, 'zones') or [])}
    check(G, 'C06 Cleanup-Teilzustand ⇒ partial', r['status'] == 500 and j(r, 'effect') == 'partial' and j(r, 'code') == 'cleanup_incomplete'
          and all(zr.get(z, {}).get('conf') == 'removed' for z in zs5[:3]) and zr.get(zs5[3], {}).get('file') == 'kept'
          and zr.get(zs5[4], {}).get('file') == 'unknown', f"{r['status']} {j(r, 'effect')} {json.dumps(zr)}")
    php = os.path.join(SRC, 'tests', 'agent', 'as2a_classify.php')
    cls = sh(['php', php, SRC, 'zone_cleanup', str(r['status']), r['raw'].decode()], check_rc=False).stdout.strip()
    check(G, 'C06b Klassifizierer ⇒ partial', cls == 'partial', cls)
    r = a.cl(keep)
    check(G, 'C07 zweiter Cleanup ⇒ applied', r['status'] == 200 and j(r, 'effect') == 'applied', f"{r['status']} {j(r, 'effect')}")
    r = a.cl(keep)
    check(G, 'C08 Cleanup ohne Verwaiste ⇒ unchanged', r['status'] == 200 and j(r, 'effect') == 'unchanged')


# ------------------------------------------------------------------------------------------------------------------
# Gruppe T – Fristen
# ------------------------------------------------------------------------------------------------------------------
def slow_wrapper(real, seconds):
    p = os.path.join(WORK, f'as2a-slow-{os.path.basename(real)}')
    with open(p, 'w') as f:
        f.write(f'#!/bin/sh\nsleep {seconds}\nexec {real} "$@"\n')
    os.chmod(p, 0o755)
    return p


def group_t(a, state):
    G = 'T'
    n1 = 'n1.as2a-solo.test'
    cur = dig(n1)['serial']
    b = snapshot([n1])
    a.config('enforce', bind_named_checkconf=slow_wrapper('/usr/bin/named-checkconf', 20))
    r = a.zs(n1, cur + 1)
    orphans = str(orphan_count())
    check(G, 'T01 langsames named-checkconf (Nachweis) ⇒ Abbruch ohne Änderung', r['status'] == 503 and j(r, 'effect') == 'none'
          and snapshot([n1]) == b and r['dt'] < 8 and orphans == '0', f"{j(r, 'code')} {sc(r, 'config_reason')} dt={r['dt']:.1f} verwaist={orphans}")
    r = a.cs(n1)
    check(G, 'T02 conf_sync: Preflight-Frist ⇒ Rollback, none', r['status'] == 503 and j(r, 'code') == 'check_timeout'
          and j(r, 'rollback') == 'ok' and j(r, 'effect') == 'none' and snapshot([n1]) == b, f"{r['status']} {j(r, 'code')} {j(r, 'rollback')} dt={r['dt']:.1f}")
    a.config('enforce', bind_named_checkzone=slow_wrapper('/usr/bin/named-checkzone', 30))
    r = a.zs(n1, cur + 1)
    check(G, 'T03 langsames named-checkzone ⇒ check_timeout', r['status'] == 503 and j(r, 'code') == 'check_timeout' and j(r, 'effect') == 'none'
          and 7 < r['dt'] < 10 and orphan_count() == 0, f"dt={r['dt']:.1f}")
    a.config('enforce')
    # Agent-Sperre belegt ⇒ lock_timeout nach höchstens TW − 8 s
    fh = open(f'{ZONES}/.dnsmanager-agent.lock', 'r')
    fcntl.flock(fh, fcntl.LOCK_EX)
    try:
        r = a.zs(n1, cur + 1)
    finally:
        fcntl.flock(fh, fcntl.LOCK_UN)
        fh.close()
    check(G, 'T04 Sperre belegt ⇒ lock_timeout', r['status'] == 503 and j(r, 'code') == 'lock_timeout' and j(r, 'effect') == 'none'
          and 15 < r['dt'] < 18 and snapshot([n1]) == b, f"dt={r['dt']:.1f}")
    t_lock_reserve(G, a, n1, cur + 1, b)
    t_status(G, a)


def t_lock_reserve(G, a, n1, serial, b):
    """Review AS2a, Befund 2: Anfragefrist so weit fortgeschritten, dass die Reserve (8 s) unterschritten ist, während die
    Sperre frei ist. Der erste fsync (Temp-Datei, vor named-checkzone und Sperre) wird per strace um 17 s verzögert;
    danach Restzeit ≈ 7 s < 8 s. Erwartet: lock_timeout ohne Änderung – auch bei freier Sperre."""
    r = a.zs(n1, serial, strace={'out': os.path.join(WORK, 't05.strace'),
                                 'inject': ['trace=fsync', 'inject=fsync:delay_exit=17000000:when=1']})
    check(G, 'T05 Reserve unterschritten bei freier Sperre ⇒ lock_timeout', r['status'] == 503 and j(r, 'code') == 'lock_timeout'
          and j(r, 'effect') == 'none' and snapshot([n1]) == b and 16.5 < r['dt'] < 20, f"http={r['status']} code={j(r, 'code')} dt={r['dt']:.1f}")


def t_status(G, a):
    """Review AS2a, Befund 1: system/status in observe/enforce mit Prozessfristen."""
    for mode in ('enforce', 'observe'):
        a.config(mode)
        r = a.status()
        bind = r['json'].get('bind') or {}
        check(G, f'T06 status {mode} regulär', r['status'] == 200 and 'timeouts' not in bind and bind.get('named_running') is True
              and r['dt'] < 5, f"http={r['status']} dt={r['dt']:.1f}")
    a.config('enforce')
    # rndc-Steuerkanal blockiert
    sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
    try:
        r = a.status()
    finally:
        sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
    to = (r['json'].get('bind') or {}).get('timeouts') or []
    check(G, 'T07 status: rndc blockiert ⇒ status_timeout innerhalb der Frist', r['status'] == 503 and r['json'].get('code') == 'status_timeout'
          and 'rndc_status' in to and r['dt'] < 25 and orphan_count() == 0, f"http={r['status']} timeouts={to} dt={r['dt']:.1f}")
    # DNS (UDP/TCP 53) blockiert
    sh('iptables -I INPUT -i lo -p udp --dport 53 -j DROP; iptables -I INPUT -i lo -p tcp --dport 53 -j DROP')
    try:
        r = a.status()
    finally:
        sh('iptables -D INPUT -i lo -p udp --dport 53 -j DROP; iptables -D INPUT -i lo -p tcp --dport 53 -j DROP')
    to = (r['json'].get('bind') or {}).get('timeouts') or []
    check(G, 'T08 status: DNS blockiert ⇒ Fehlerzustand innerhalb der Frist', r['status'] == 503 and r['dt'] < 25 and orphan_count() == 0
          and (r['json'].get('bind') or {}).get('dns_query_localhost_ok') is False
          and (r['json'].get('verification') or {}).get('control_zone') == 'not_served', f"http={r['status']} timeouts={to} dt={r['dt']:.1f}")
    # beide blockiert
    sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP; iptables -I INPUT -i lo -p udp --dport 53 -j DROP; iptables -I INPUT -i lo -p tcp --dport 53 -j DROP')
    try:
        r = a.status()
    finally:
        sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP; iptables -D INPUT -i lo -p udp --dport 53 -j DROP; iptables -D INPUT -i lo -p tcp --dport 53 -j DROP')
    to = (r['json'].get('bind') or {}).get('timeouts') or []
    check(G, 'T09 status: rndc und DNS blockiert ⇒ status_timeout < 25 s', r['status'] == 503 and r['json'].get('code') == 'status_timeout'
          and r['dt'] < 25 and orphan_count() == 0, f"timeouts={to} dt={r['dt']:.1f}")
    # künstlich verzögertes named-checkconf (Konfigurationsnachweis)
    a.config('enforce', bind_named_checkconf=slow_wrapper('/usr/bin/named-checkconf', 30))
    r = a.status()
    to = (r['json'].get('bind') or {}).get('timeouts') or []
    check(G, 'T10 status: named-checkconf verzögert ⇒ status_timeout', r['status'] == 503 and 'config_proof' in to
          and r['dt'] < 25 and orphan_count() == 0, f"timeouts={to} dt={r['dt']:.1f}")
    a.config('enforce')


# ------------------------------------------------------------------------------------------------------------------
# Gruppe G – off gegen den Basis-Commit
# ------------------------------------------------------------------------------------------------------------------
def group_g(src_agent, ref_agent, state):
    G = 'G'
    src_agent.config(None)
    ref_agent.config(None)
    g1 = 'g1.as2a-solo.test'

    def setup_none():
        for p in (managed(g1), f'{CONFDIR}/{g1}.conf'):
            if os.path.exists(p):
                os.unlink(p)
        sh(['php', '-r', '1;'])

    def run(tid, prep, act, post=None):
        out = []
        for ag in (ref_agent, src_agent):
            prep()
            res = act(ag)
            files = (sha(managed(g1)), sha(f'{CONFDIR}/{g1}.conf'), sha(ZONESCONF))
            body = res['json']
            # zufälliger Temp-Name je Anfrage (.db.<zone>.<16 Hex>.tmp) ist keine Abweichung
            dump = re.sub(r'\.[0-9a-f]{16}\.tmp', '.<zufall>.tmp', json.dumps(body, sort_keys=True))
            out.append((res['status'], dump, files))
            if post:
                post()
        same = out[0] == out[1]
        check(G, tid, same, '' if same else f"ref={out[0][:2]} src={out[1][:2]}")

    def rebuild():
        a = src_agent
        # zones.conf auf den Stand ohne g1 bringen
        if os.path.exists(f'{CONFDIR}/{g1}.conf'):
            os.unlink(f'{CONFDIR}/{g1}.conf')
        if os.path.exists(managed(g1)):
            os.unlink(managed(g1))
        inc = sorted(f for f in os.listdir(CONFDIR) if f.endswith('.conf'))
        with open(ZONESCONF, 'w') as f:
            f.write('\n'.join(f'include "{CONFDIR}/{x}";' for x in inc) + '\n')
        rndc_reload()

    run('G01 zone_sync neu', rebuild, lambda ag: ag.zs(g1, 5))
    run('G02 conf_sync', lambda: (rebuild(), put_managed_raw(g1, zone_text(g1, 5))), lambda ag: ag.cs(g1))
    run('G03 zone_sync ungültig (422)', rebuild, lambda ag: ag.zs(g1, 5, raw='kaputt\n'))
    run('G04 zone_sync ältere Serial (off: keine Prüfung)', lambda: (rebuild(), put_managed_raw(g1, zone_text(g1, 9))), lambda ag: ag.zs(g1, 3))
    run('G05 conf_sync Preflight-Fehler (Rollback)', rebuild, lambda ag: ag.cs(g1, text='zone "x" { type master; file ; };'))
    run('G06 Cleanup', lambda: (rebuild(), put_managed_raw(g1, zone_text(g1, 5)), open(f'{CONFDIR}/{g1}.conf', 'w').write(conf_text(g1))),
        lambda ag: ag.cl(state['zones'] + [z[:-5] for z in os.listdir(CONFDIR) if z.endswith('.conf') and z != f'{g1}.conf']))
    # Fehlerpfad: Steuerkanal blockiert (off: keine Frist – beide warten auf rndc)
    def blocked(ag):
        sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
        try:
            return ag.zs(g1, 5, timeout=300)
        finally:
            sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
    run('G07 rndc blockiert (off unverändert, ohne Frist)', rebuild, blocked)
    # Fehlerpfad: named gestoppt
    def stopped(ag):
        sh('systemctl stop named')
        try:
            return ag.zs(g1, 5)
        finally:
            sh('systemctl start named')
            time.sleep(3)
    run('G08 named gestoppt (off unverändert)', rebuild, stopped)
    # Fehlerpfad: langsames named-checkconf (off: keine Frist)
    def slow(ag):
        ag.config(None, bind_named_checkconf=slow_wrapper('/usr/bin/named-checkconf', 4))
        try:
            return ag.zs(g1, 5)
        finally:
            ag.config(None)
    run('G09 langsames named-checkconf (off ohne Frist)', rebuild, slow)
    # Fehlerpfad: Verzeichnis-fsync scheitert
    def fs(ag):
        return ag.zs(g1, 5, strace={'out': os.path.join(WORK, 'g10.strace'), 'inject': ['inject=fsync:error=EIO:when=1'], 'paths': [ZONES]})
    run('G10 persist_unconfirmed (off: rollback=failed wie bisher)', rebuild, fs)
    rebuild()
    st_src, st_ref = src_agent.status(), ref_agent.status()
    norm = lambda s: {k: v for k, v in s['json'].items() if k not in ('uptime_seconds', 'load_average')}
    check(G, 'G11 system/status off unverändert', st_src['status'] == st_ref['status'] and norm(st_src) == norm(st_ref))


# ------------------------------------------------------------------------------------------------------------------
# Gruppe M – Mutationsproben
# ------------------------------------------------------------------------------------------------------------------
MUTATIONS = [
    ('M01 Halbkreis als newer', 'agent/inc/serial.php', "        return 'undefined';", "        return 'newer';", 'mut_e10e'),
    ('M02 Vorher-Prüfung entfernt', 'agent/inc/dns_state.php',
     "    return dnsPreTransferCheck($config, $mode, $zoneName, $newSerial, $newBytes, $repair);",
     "    return ['allow' => true, 'idempotent' => false, 'http' => 200, 'code' => null, 'message' => null, 'report' => []];", 'mut_fallb'),
    # M03 entfernt beide Schichten der Pfadprüfung (Pfadvergleich und realpath des Verzeichnisses)
    ('M03 Pfadprüfung entfernt', 'agent/inc/dns_state.php',
     "    if ($file === null || $file !== $managed || $realDir === false || realpath(dirname($file)) !== $realDir) {",
     "    if (false) {", 'mut_k01'),
    ('M04 NZD-Prüfung entfernt', 'agent/inc/dns_state.php', "                return $fail('nzd_present');", "                break;", 'mut_k10'),
    ('M05 Zonenzahl ignoriert', 'agent/inc/dns_state.php', "        return $fail('zone_count_mismatch');", "        ;", 'mut_k07'),
    # M06 wertet SERVFAIL in der Vorher-Prüfung als "nicht geladen, Übernahme zulässig" (Zeilen 3 und 5 übergangen)
    ('M06 SERVFAIL als nicht geladen', 'agent/inc/dns_state.php', [
        ("    if ($d['state'] === 'unknown') {", "    if ($d['state'] === 'unknown' && $d['rcode'] !== 'SERVFAIL') {"),
        ("    if ($k['state'] === 'yes' && $d['state'] !== 'served') {", "    if ($k['state'] === 'yes' && $d['state'] !== 'served' && $d['rcode'] !== 'SERVFAIL') {")],
     None, 'mut_e03'),
    ('M07 REFUSED der Elternzone akzeptiert', 'agent/inc/dns_state.php', "        $out['reason'] = $r['rcode'] === 'REFUSED' ? 'refused_with_parent' : 'parent_answer_unexpected';",
     "        if ($r['rcode'] === 'REFUSED') { return ['state' => 'not_auth'] + $out; }\n        $out['reason'] = 'x';", 'mut_d03'),
    ('M08 Rollback nach Reload-Fehler', 'agent/api/v1/zones/zone_sync.php', "    if ($reload['status'] !== 'ok') {\n        $codeMap",
     "    if ($reload['status'] !== 'ok') {\n        @rename($finalPath, $finalPath . '.x'); @unlink($finalPath . '.x');\n        $codeMap", 'mut_c02'),
    ('M09 Teilzustand als none', 'agent/inc/dns_state.php', "        return $rest ? 'partial' : 'applied';", "        return $rest ? 'none' : 'applied';", 'mut_c06'),
    ('M10 rndc ohne Frist', 'agent/inc/proc.php', "const AGENT_CAP_RNDC_RELOAD = 5.0;", "const AGENT_CAP_RNDC_RELOAD = 60.0;", 'mut_c02'),
    ('M11 Kontrollzone mit eigener ACL akzeptiert', 'agent/inc/dns_state.php', "        return ['state' => 'own_acl', 'zone' => $zone];", "        ;", 'mut_d05'),
    ('M12 Reparatur ohne Hashprüfung', 'agent/inc/dns_state.php', "&& is_string($repair['expect_file']) && hash_equals((string)$f['sha256'], $repair['expect_file'])",
     "&& is_string($repair['expect_file'])", 'mut_e08'),
    # Review AS2a: system/status mit unbeschränktem rndc-Aufruf (wie vor der Korrektur)
    ('M13 status: rndc ohne Frist', 'agent/api/v1/system/status.php',
     "    $p = $run('rndc_status', [AGENT_SUDO, '-n', $rndcBinary, 'status'], AGENT_CAP_RNDC_STATUS);",
     "    $p = ['status' => 'ok', 'output' => (string)shell_exec('sudo -n ' . escapeshellcmd($rndcBinary) . ' status 2>&1')];", 'mut_s07'),
    # Review AS2a: Reserve erst nach dem Sperrversuch geprüft (wie vor der Korrektur)
    ('M14 Sperrfrist nach flock geprüft', 'agent/inc/proc.php', [
        ("        if (agentRemaining() <= $reserve) {\n            fclose($h);\n            return ['handle' => null, 'reason' => 'timeout'];\n        }\n        $wouldBlock = 0;",
         "        $wouldBlock = 0;"),
        ("            return ['handle' => null, 'reason' => 'flock'];\n        }\n        usleep(50000);",
         "            return ['handle' => null, 'reason' => 'flock'];\n        }\n        if (agentRemaining() <= $reserve) {\n            fclose($h);\n            return ['handle' => null, 'reason' => 'timeout'];\n        }\n        usleep(50000);")],
     None, 'mut_t05'),
]


def mut_scenarios(a, state, name):
    """Kurzszenarien je Mutation (jede muss mit der Mutation mindestens einen Fehlschlag liefern)."""
    G = 'MS'
    a.config('enforce')
    n = 'mut.as2a-solo.test'
    if name == 'mut_e10e':
        a.zs(n, 10); a.cs(n); wait_serial(n, 10)
        b = snapshot([n]); unchanged(G, 'e10e', b, [n], a.zs(n, 10 + 2 ** 31), 'serial_undefined', 409)
    elif name == 'mut_fallb':
        a.zs(n, 10); a.cs(n); wait_serial(n, 10); a.zs(n, 20); wait_serial(n, 20)
        os.unlink(managed(n)); b = snapshot([n]); unchanged(G, 'fallb', b, [n], a.zs(n, 5), 'stale_serial', 409)
    elif name == 'mut_k01':
        fp = 'mfp.as2a-solo.test'
        put_managed_raw(fp, zone_text(fp, 100, 'verwaltet')); write_inc([inc_zone(fp, put_incfile(fp, 100, 'fremd'))]); rndc_reload()
        k_case(G, 'k01', a, fp, 'zone_file_mismatch', serial=101)
    elif name == 'mut_k10':
        a.zs(n, 10); a.cs(n); wait_serial(n, 10)
        p = f'{CACHE}/as2a-mut.nzd'; open(p, 'w').close()
        try:
            k_case(G, 'k10', a, n, 'nzd_present', serial=11)
        finally:
            os.unlink(p)
    elif name == 'mut_k07':
        a.zs(n, 10); a.cs(n); wait_serial(n, 10)
        write_inc([inc_zone('mf1.as2a-solo.test', put_incfile('mf1.as2a-solo.test', 1))])
        k_case(G, 'k07', a, n, 'zone_count_mismatch', serial=11)
    elif name == 'mut_e03':
        m = 'mm1.as2a-solo.test'; a.cs(m); b = snapshot([m]); unchanged(G, 'e03', b, [m], a.zs(m, 1), 'served_state_unknown', 503)
    elif name == 'mut_d03':
        par = 'mpar.as2a.test'; write_inc([inc_zone(par, put_incfile(par, 7), 'allow-query { none; };')]); rndc_reload()
        c = 'y.mpar.as2a.test'; unchanged(G, 'd03', snapshot([c]), [c], a.zs(c, 1), 'served_state_unknown', 503)
    elif name == 'mut_c02':
        a.zs(n, 10); a.cs(n); wait_serial(n, 10)
        r = zs_reload_blocked(a, n, 11, 'drop953')
        check(G, 'c02', j(r, 'code') == 'reload_timeout' and r['dt'] < 10
              and sha(managed(n)) == hashlib.sha256(zone_text(n, 11).encode()).hexdigest(), f"dt={r['dt']:.1f}")
    elif name == 'mut_c06':
        zs = ['mc1.as2a-solo.test', 'mc2.as2a-solo.test']
        for z in zs:
            a.zs(z, 1); a.cs(z)
        sh(['chattr', '+i', managed(zs[1])])
        try:
            keep = [z[:-5] for z in os.listdir(CONFDIR) if z.endswith('.conf') and z[:-5] not in zs]
            r = a.cl(keep)
        finally:
            sh(['chattr', '-i', managed(zs[1])])
        check(G, 'c06', j(r, 'effect') == 'partial', str(j(r, 'effect')))
    elif name == 'mut_d05':
        write_inc([inc_zone('mctl.as2a-solo.test', put_incfile('mctl.as2a-solo.test', 1), 'allow-query { any; };')]); rndc_reload()
        a.config('enforce', l3_control_zone='mctl.as2a-solo.test')
        r = a.zs('msolo.as2a-solo.test', 1)
        check(G, 'd05', r['status'] == 503 and sc(r, 'served_reason') == 'control_zone_own_acl', str(sc(r, 'served_reason')))
    elif name == 'mut_s07':
        sh('iptables -I INPUT -i lo -p tcp --dport 953 -j DROP')
        try:
            r = a.status(timeout=30)
        finally:
            sh('iptables -D INPUT -i lo -p tcp --dport 953 -j DROP')
        check(G, 's07', r['status'] == 503 and r['dt'] < 25, f"http={r['status']} dt={r['dt']:.1f}")
    elif name == 'mut_t05':
        a.zs(n, 10); a.cs(n); wait_serial(n, 10)
        b = snapshot([n])
        t_lock_reserve(G, a, n, 11, b)
    elif name == 'mut_e08':
        m = 'mr.as2a-solo.test'; put_managed_raw(m, 'kaputt\n'); b = snapshot([m])
        unchanged(G, 'e08', b, [m], a.zs(m, 5, repair={'expect_file': '0' * 64, 'expect_served': 'not_auth'}), 'repair_mismatch', 409)


def group_m(state, src_tree):
    G = 'M'
    global STOP_ON_FAIL
    for mid, rel, old, new, scen in MUTATIONS:
        dst = os.path.join(WORK, 'mut')
        if os.path.exists(dst):
            shutil.rmtree(dst)
        shutil.copytree(src_tree, dst)
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
        a = Agent('mutagent', os.path.join(dst, 'agent'))
        before = len([r for r in RESULTS if not r[2]])
        try:
            mut_scenarios(a, state, scen)
        except Exception as e:  # noqa: BLE001
            log(f'  Mutation {mid}: Ausnahme {e}')
        killed = len([r for r in RESULTS if not r[2]]) > before
        # Fehlschläge des Mutationslaufs zählen nicht als Suite-Fehlschläge
        RESULTS[:] = [r for r in RESULTS if r[0] != 'MS']
        reset_testzones(Agent('cleanagent', os.path.join(SRC, 'agent')), state)
        check(G, mid, killed, 'getötet' if killed else 'ÜBERLEBT')


# ------------------------------------------------------------------------------------------------------------------
def main():
    global OUT, SRC, STOP_ON_FAIL
    ap = argparse.ArgumentParser()
    ap.add_argument('--src', required=True)
    ap.add_argument('--ref')
    ap.add_argument('--out', required=True)
    ap.add_argument('--groups', default='EKDOCTGM')
    args = ap.parse_args()
    OUT = args.out
    SRC = os.path.abspath(args.src)
    os.makedirs(OUT, exist_ok=True)
    if os.path.exists(WORK):
        shutil.rmtree(WORK)
    os.makedirs(WORK, mode=0o755)
    log(f'### AS2a {time.strftime("%Y-%m-%d %H:%M:%S")} src={SRC} ref={args.ref} Gruppen={args.groups} {sh("named -v").stdout.strip()}')
    state = backup()
    a = Agent('agent', os.path.join(SRC, 'agent'))
    try:
        init_high(a)
        write_inc([])
        local = open(LOCAL).read()
        if INC not in local:
            with open(LOCAL, 'a') as f:
                f.write(f'include "{INC}";\n')
        rndc_reload()
        for g in args.groups:
            log(f'--- Gruppe {g}')
            CURRENT[0] = g
            try:
                if g == 'E':
                    group_e(a, state)
                elif g == 'D':
                    group_d(a, state)
                elif g == 'K':
                    group_k(a, state)
                elif g == 'O':
                    group_o(a, state)
                elif g == 'C':
                    group_c(a, state)
                elif g == 'T':
                    group_t(a, state)
                elif g == 'G':
                    ref = Agent('refagent', os.path.join(os.path.abspath(args.ref), 'agent'))
                    group_g(Agent('offagent', os.path.join(SRC, 'agent')), ref, state)
                elif g == 'M':
                    group_m(state, SRC)
            except Exception as e:  # noqa: BLE001
                check(g, 'AUSNAHME', False, repr(e))
        long = [d for d in DURATIONS if d[0] not in ('G', 'M') and d[2] >= 25]
        check('T', 'T99 jeder Aufruf in observe/enforce < 25 s', not long, f"max={max([d[2] for d in DURATIONS if d[0] not in ('G', 'M')] or [0]):.1f} {long}")
    finally:
        try:
            reset_testzones(a, state)
        except Exception as e:  # noqa: BLE001
            log(f'Cleanup über Agent: {e}')
        restore(state)
        verify_restored(state)
    fails = [r for r in RESULTS if not r[2]]
    log(f'### Ergebnis: {len(RESULTS) - len(fails)} bestanden, {len(fails)} fehlgeschlagen')
    for r in fails:
        log(f'  FAIL {r[0]}/{r[1]} {r[3]}')
    sys.exit(0 if not fails else 1)


if __name__ == '__main__':
    main()
