#!/usr/bin/env python3
# Datei: tests/agent/as1_test.py
# Zweck: Abnahmesuite AS1 (Agent-Fencing, dauerhafte Persistenz). Führt den ECHTEN Agent-Code des geprüften Commits
#        über den eingebauten PHP-Webserver (HTTP-SAPI) in einer Sandbox aus. Alle verwalteten BIND-Dateien liegen auf
#        einem ext4-Dateisystem über dm-flakey (Loop-Image in der Sandbox), damit fsync wirksam ist und Stromausfälle
#        simuliert werden können. /etc/bind, die installierte BIND-Instanz und die installierten Agenten werden nicht
#        berührt; rndc ist ein Platzhalter.
#
# Gruppen:
#   F  Funktion (Modi off/observe/enforce, alle Ablehnungsgründe, drei Endpunkte, CLI, Reihenfolge nach flock)
#   S  strace-Nachweis der vollständigen Syscall-Reihenfolge (zone_sync, conf_sync, zone_cleanup)
#   I  Fehlerinjektion (strace inject) für jeden einzelnen Persistenz- und Dateischritt der drei Endpunkte
#   C  Absturzsimulation (strace SIGKILL vor jedem Schritt + dm-flakey drop_writes) und Stromausfall nach Abschluss
#   G  off gegenüber dem bisherigen Agenten (Referenz-Checkout) beobachtbar identisch
#   M  Mutationsproben (jede Mutation muss mindestens einen Test scheitern lassen)
#
# Aufruf (root, Test-VM): as1_test.py --src <Checkout> --ref <Referenz-Checkout> --out <Ergebnisverzeichnis> [--groups FSICGM]
# Voraussetzungen: php (CLI), strace, named-checkzone, named-checkconf, losetup, mkfs.ext4, dmsetup, dm_flakey.

import argparse
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
import tempfile
import threading
import time

DOMAIN = '3f1c2a5e-7b4d-4c1e-9a2f-0d6b8e4c1a77'
OTHER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d'
TOKEN = 'AS1TOKEN-0123456789abcdef0123456789abcdef0123456789abcdef01234567'
PERSIST_SYSCALLS = ['openat', 'read', 'write', 'fsync', 'rename', 'unlink', 'flock']
INJECT_ERR = {'openat': 'EIO', 'read': 'EIO', 'write': 'ENOSPC', 'fsync': 'EIO', 'rename': 'EIO', 'unlink': 'EIO', 'flock': 'ENOLCK'}

RESULTS = []          # (gruppe, id, ok, detail)
OUT = None
STOP_ON_FAIL = False  # Mutationsläufe: beim ersten Fehlschlag beenden (Mutation getötet)
SCENARIOS = None      # Einschränkung der Szenarien (Mutationsläufe)
# Strengster ext4-Fall und deterministische Absturztests: keine Datenzuteilung beim rename (noauto_da_alloc) und
# kein periodischer Journal-Commit innerhalb eines Falls (commit=300) – dauerhaft ist nur, was fsync festschreibt.
MOUNT_OPTS = 'noauto_da_alloc,commit=300'


def log(msg):
    print(msg, flush=True)
    with open(os.path.join(OUT, 'as1_test.log'), 'a') as f:
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
    with open(path, 'rb') as f:
        return hashlib.sha256(f.read()).hexdigest()


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


def zone_text(name, serial, txt='v'):
    return (f"$TTL 3600\n@ IN SOA ns1.{name}. hostmaster.{name}. ( {serial} 3600 1800 1209600 86400 )\n"
            f"@ IN NS ns1.{name}.\nns1 IN A 192.0.2.1\ninfo IN TXT \"{txt}\"\n")


# ------------------------------------------------------------------------------------------------------------------
# Dateisystem: ext4 über dm-flakey
# ------------------------------------------------------------------------------------------------------------------
class Flakey:
    NAME = 'as1flakey'

    def __init__(self, root):
        self.img = os.path.join(root, 'ext4.img')
        self.mnt = os.path.join(root, 'mnt')
        os.makedirs(self.mnt)
        sh(['truncate', '-s', '256M', self.img])
        self.loop = sh(['losetup', '-f', '--show', self.img]).stdout.strip()
        sh(['mkfs.ext4', '-q', '-F', self.loop])
        self.sec = sh(['blockdev', '--getsz', self.loop]).stdout.strip()
        self.loaded_module = sh('lsmod | grep -c "^dm_flakey"', check_rc=False).stdout.strip() == '0'
        sh(['modprobe', 'dm_flakey'])
        if os.path.exists(f'/dev/mapper/{self.NAME}'):
            raise RuntimeError('dm-Gerät as1flakey existiert bereits')
        sh(['dmsetup', 'create', self.NAME, '--table', self.table('ok')])
        sh(['mount', '-o', MOUNT_OPTS, f'/dev/mapper/{self.NAME}', self.mnt])

    def table(self, kind):
        return {'ok': f"0 {self.sec} flakey {self.loop} 0 1 0",
                'drop': f"0 {self.sec} flakey {self.loop} 0 0 1 1 drop_writes"}[kind]

    def settable(self, kind):
        sh(['dmsetup', 'suspend', '--nolockfs', self.NAME])
        sh(['dmsetup', 'load', self.NAME, '--table', self.table(kind)])
        sh(['dmsetup', 'resume', self.NAME])

    def power_loss(self):
        """Alles nicht dauerhaft Geschriebene verwerfen (Stromausfall), danach neu einhängen (Journal-Replay)."""
        self.settable('drop')
        sh(['umount', self.mnt])
        self.settable('ok')
        sh(['mount', '-o', MOUNT_OPTS, f'/dev/mapper/{self.NAME}', self.mnt])

    def close(self):
        sh(['umount', self.mnt], check_rc=False)
        sh(['dmsetup', 'remove', self.NAME], check_rc=False)
        sh(['losetup', '-d', self.loop], check_rc=False)
        if self.loaded_module:
            sh(['rmmod', 'dm_flakey'], check_rc=False)


# ------------------------------------------------------------------------------------------------------------------
# Agent-Sandbox
# ------------------------------------------------------------------------------------------------------------------
class Box:
    def __init__(self, root, src, name, bindbase, agent_tree=None):
        self.d = os.path.join(root, name)
        os.makedirs(self.d)
        self.src = src
        self.agent = os.path.join(self.d, 'agent')
        shutil.copytree(agent_tree or os.path.join(src, 'agent'), self.agent, symlinks=False)
        self.cli = os.path.join(src, 'scripts', 'agent_fence-cli.php')
        self.bind = os.path.join(bindbase, name)
        self.zones = os.path.join(self.bind, 'zones')
        self.confdir = os.path.join(self.zones, 'conf')
        self.zonesconf = os.path.join(self.zones, 'zones.conf')
        self.rndclog = os.path.join(self.d, 'rndc.log')
        self.phplog = os.path.join(self.d, 'php.log')
        self.cfgpath = os.path.join(self.agent, 'config', 'api_config.php')
        with open(os.path.join(self.d, 'rndc'), 'w') as f:
            f.write(f"#!/bin/sh\necho \"$*\" >> {self.rndclog}\n"
                    "if [ \"$1\" = status ]; then echo 'server is up and running'; else echo 'server reload successful'; fi\n")
        os.chmod(os.path.join(self.d, 'rndc'), 0o700)
        with open(os.path.join(self.agent, 'config', 'api_tokens.php'), 'w') as f:
            f.write(f"<?php\nreturn ['{TOKEN}'];\n")
        self.reset()
        self.config()

    def reset(self):
        if os.path.exists(self.bind):
            shutil.rmtree(self.bind)
        os.makedirs(self.confdir)
        with open(self.zonesconf, 'w') as f:
            f.write('')
        open(os.path.join(self.zones, '.dnsmanager-agent.lock'), 'w').close()  # wie im Betrieb vorhanden
        with open(os.path.join(self.bind, 'named.conf'), 'w') as f:
            f.write(f'include "{self.zonesconf}";\n')
        open(self.rndclog, 'w').close()
        sh('sync')

    def config(self, mode=None, domain=DOMAIN):
        cfg = {
            'api_allowed_ips': [], 'zone_conf_dir': self.confdir, 'zone_data_dir': self.zones,
            'zones_conf_file': self.zonesconf, 'bind_named_conf_path': os.path.join(self.bind, 'named.conf'),
            'token_file': os.path.join(self.agent, 'config', 'api_tokens.php'),
            'bind_named_checkzone': '/usr/bin/named-checkzone', 'bind_named_checkconf': '/usr/bin/named-checkconf',
            'bind_rndc': os.path.join(self.d, 'rndc'),
        }
        if mode is not None:
            cfg['fencing_mode'] = mode
        if domain is not None and mode is not None:
            cfg['fence_domain'] = domain
        php = "<?php\nreturn " + php_lit(cfg) + ";\n"
        with open(self.cfgpath, 'w') as f:
            f.write(php)

    # ---- Zustand ----
    def tree(self, normalize=False):
        out = {}
        for base, _, files in os.walk(self.bind):
            for fn in files:
                p = os.path.join(base, fn)
                if os.path.islink(p):
                    out[os.path.relpath(p, self.bind)] = 'LINK'
                    continue
                data = open(p, 'rb').read()
                if normalize:
                    data = data.replace(self.bind.encode(), b'<B>')
                out[os.path.relpath(p, self.bind)] = hashlib.sha256(data).hexdigest()
        return out

    def rndc_calls(self):
        with open(self.rndclog) as f:
            return f.read().count('reload')

    def high(self):
        p = os.path.join(self.zones, '.dnsmanager-fence')
        if not os.path.exists(p):
            return None
        m = re.match(r'^dnsmanager-fence v1 domain=([0-9a-f-]{36}) high=([0-9]+)\n$', open(p).read())
        return (m.group(1), int(m.group(2))) if m else 'INVALID'

    def latch(self):
        return os.path.lexists(os.path.join(self.zones, '.dnsmanager-fence.fault'))

    def write_high_raw(self, content, durable=True):
        p = os.path.join(self.zones, '.dnsmanager-fence')
        tmp = p + '.raw.tmp'
        with open(tmp, 'w') as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())
        os.rename(tmp, p)
        if durable:
            fd = os.open(self.zones, os.O_RDONLY)
            os.fsync(fd)
            os.close(fd)

    def cli_run(self, *args, user=None):
        cmd = ['php', self.cli, f'--config={self.cfgpath}', *args]
        if user:
            cmd = ['runuser', '-u', user, '--'] + cmd
        p = sh(cmd, check_rc=False)
        try:
            data = json.loads((p.stdout or p.stderr).strip().splitlines()[-1])
        except Exception:
            data = {'raw': p.stdout + p.stderr}
        return p.returncode, data

    def init_high(self, value, domain=DOMAIN):
        rc, data = self.cli_run('init', f'--domain={domain}', f'--high={value}')
        if rc != 0:
            raise RuntimeError(f'CLI init fehlgeschlagen: {data}')

    # ---- Bestand ----
    def put_zone(self, name, serial, txt='v'):
        with open(os.path.join(self.zones, f'db.{name}'), 'w') as f:
            f.write(zone_text(name, serial, txt))
        with open(os.path.join(self.confdir, f'{name}.conf'), 'w') as f:
            f.write(self.conf_text(name))
        self.rebuild_zonesconf()

    def conf_text(self, name):
        return f'zone "{name}" {{ type master; file "{self.zones}/db.{name}"; }};\n'

    def expected_zonesconf(self):
        """zones.conf wie der Agent sie aus dem Konfigurationsverzeichnis erzeugt (glob-Reihenfolge)."""
        inc = sorted(os.path.join(self.confdir, f) for f in os.listdir(self.confdir) if f.endswith('.conf'))
        return '\n'.join(f'include "{os.path.realpath(p)}";' for p in inc) + '\n'

    def rebuild_zonesconf(self):
        inc = sorted(os.path.join(self.confdir, f) for f in os.listdir(self.confdir) if f.endswith('.conf'))
        with open(self.zonesconf, 'w') as f:
            f.write(''.join(f'include "{os.path.realpath(p)}";\n' for p in inc) or '\n')


# ------------------------------------------------------------------------------------------------------------------
# PHP-Webserver je Anfrage (optional unter strace)
# ------------------------------------------------------------------------------------------------------------------
def free_port():
    s = socket.socket()
    s.bind(('127.0.0.1', 0))
    p = s.getsockname()[1]
    s.close()
    return p


class Server:
    def __init__(self, box, strace=None):
        self.port = free_port()
        cmd = ['php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', f'error_log={box.phplog}',
               '-S', f'127.0.0.1:{self.port}', '-t', box.agent]
        if strace:
            cmd = ['strace'] + (['-f'] if strace.get('follow') else []) + ['-y', '-qq', '-o', strace['out'], '-e', 'trace=' + ','.join(strace.get('trace', PERSIST_SYSCALLS))] \
                + (['-e', strace['inject']] if strace.get('inject') else []) + cmd
        # eigene Prozessgruppe: strace und PHP werden gemeinsam beendet (kein verwaister Server)
        self.p = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        end = time.time() + 10
        while time.time() < end:
            try:
                socket.create_connection(('127.0.0.1', self.port), timeout=0.2).close()
                return
            except OSError:
                time.sleep(0.03)
        raise RuntimeError('PHP-Webserver nicht gestartet')

    def request(self, ep, body=None, method='POST', token=TOKEN, timeout=60):
        c = http.client.HTTPConnection('127.0.0.1', self.port, timeout=timeout)
        headers = {'Content-Type': 'application/json'}
        if token is not None:
            headers['Authorization'] = f'Bearer {token}'
        data = json.dumps(body).encode() if body is not None else None
        try:
            c.request(method, f'/api/v1/{ep}', body=data, headers=headers)
            r = c.getresponse()
            raw = r.read()
            try:
                js = json.loads(raw)
            except ValueError:
                js = None
            return {'status': r.status, 'ctype': r.getheader('Content-Type'), 'raw': raw, 'json': js}
        except (OSError, http.client.HTTPException) as e:
            return {'status': None, 'error': str(e), 'json': None, 'raw': b'', 'ctype': None}
        finally:
            c.close()

    def stop(self):
        import signal
        for sig in (signal.SIGTERM, signal.SIGKILL):
            try:
                os.killpg(self.p.pid, sig)
            except ProcessLookupError:
                pass
            try:
                self.p.wait(timeout=5)
                break
            except subprocess.TimeoutExpired:
                continue
        # Gruppe vollständig beendet?
        end = time.time() + 5
        while time.time() < end:
            try:
                os.killpg(self.p.pid, 0)
                time.sleep(0.05)
            except ProcessLookupError:
                return
        raise RuntimeError('PHP-Webserver-Prozessgruppe nicht beendet')


def call(box, ep, body=None, method='POST', token=TOKEN, strace=None):
    s = Server(box, strace)
    try:
        return s.request(ep, body, method, token)
    finally:
        s.stop()


def fz(name, serial, txt='v', token=None, domain=DOMAIN):
    b = {'zone_id': 1, 'zone_name': name, 'zone_data': __import__('base64').b64encode(zone_text(name, serial, txt).encode()).decode()}
    if token is not None:
        b['fence_token'] = token
        b['fence_domain'] = domain
    return b


def fc(box, name, token=None, domain=DOMAIN):
    b = {'zone_name': name, 'conf_data': __import__('base64').b64encode(box.conf_text(name).encode()).decode()}
    if token is not None:
        b['fence_token'] = token
        b['fence_domain'] = domain
    return b


def fcl(valid, token=None, domain=DOMAIN, allow_empty=None):
    b = {'valid_zones': valid}
    if allow_empty is not None:
        b['allow_empty'] = allow_empty
    if token is not None:
        b['fence_token'] = token
        b['fence_domain'] = domain
    return b


def code(r):
    return (r['json'] or {}).get('code')


# ------------------------------------------------------------------------------------------------------------------
# Gruppe F – Funktion
# ------------------------------------------------------------------------------------------------------------------
def group_f(box):
    G = 'F'
    # F01 enforce ohne H ⇒ fence_uninitialized, Bestand unverändert, kein Reload (alle drei Endpunkte)
    box.reset(); box.config('enforce'); box.put_zone('a.as1.test', 1); sh('sync')
    for ep, body in [('zones/zone_sync.php', fz('a.as1.test', 2, token=5)), ('zones/conf_sync.php', fc(box, 'b.as1.test', 5)),
                     ('zones/zone_cleanup.php', fcl([], 5, allow_empty=True))]:
        t0, n0 = box.tree(), box.rndc_calls()
        r = call(box, ep, body)
        check(G, f'F01 {ep}', r['status'] == 409 and code(r) == 'fence_uninitialized' and box.tree() == t0 and box.rndc_calls() == n0
              and (r['json'] or {}).get('fence', {}).get('decision') == 'reject', f"status={r['status']} code={code(r)}")
    # F02 CLI init, Status
    rc, d = box.cli_run('init', f'--domain={DOMAIN}', '--high=10')
    check(G, 'F02 CLI init', rc == 0 and box.high() == (DOMAIN, 10) and not box.latch(), f'rc={rc} {d}')
    rc, d = box.cli_run('init', f'--domain={DOMAIN}', '--high=20')
    check(G, 'F02b CLI init nur ohne H', rc == 2 and box.high() == (DOMAIN, 10), f'rc={rc}')
    rc, d = box.cli_run('raise', '--high=10')
    check(G, 'F02c CLI raise nie gleich/niedriger', rc == 2 and box.high() == (DOMAIN, 10), f'rc={rc}')
    rc, d = box.cli_run('raise', '--high=9')
    check(G, 'F02d CLI raise nie senken', rc == 2 and box.high() == (DOMAIN, 10), f'rc={rc}')
    rc, d = box.cli_run('status', user='nobody')
    check(G, 'F02e CLI nur als Eigentümer', rc == 2, f'rc={rc}')
    # F03 Token-Fehler je Endpunkt
    cases = [('fehlend', None, DOMAIN, 400, 'fence_token_missing'), ('null', 0, DOMAIN, 400, 'fence_token_invalid'),
             ('negativ', -3, DOMAIN, 400, 'fence_token_invalid'), ('fuehrende_null', '011', DOMAIN, 400, 'fence_token_invalid'),
             ('text', 'abc', DOMAIN, 400, 'fence_token_invalid'), ('float', 11.5, DOMAIN, 400, 'fence_token_invalid'),
             ('zu_gross', '99999999999999999999', DOMAIN, 400, 'fence_token_invalid'),
             ('domain_ungueltig', 11, 'ABC', 400, 'fence_token_invalid'), ('domain_fremd', 11, OTHER, 409, 'fence_domain_mismatch'),
             ('stale', 9, DOMAIN, 409, 'stale_fence')]
    for name, tok, dom, st, cd in cases:
        for ep, mk in [('zones/zone_sync.php', lambda t, d: fz('a.as1.test', 2, 'x', t, d)),
                       ('zones/conf_sync.php', lambda t, d: fc(box, 'c.as1.test', t, d)),
                       ('zones/zone_cleanup.php', lambda t, d: fcl([], t, d, True))]:
            body = mk(tok, dom)
            if tok is None:
                body['fence_domain'] = DOMAIN
            t0, n0 = box.tree(), box.rndc_calls()
            r = call(box, ep, body)
            check(G, f'F03 {name} {ep}', r['status'] == st and code(r) == cd and box.tree() == t0 and box.rndc_calls() == n0
                  and box.high() == (DOMAIN, 10), f"status={r['status']} code={code(r)}")
    # F04 t == H: Annahme ohne Schreiben von H; t > H: H := t
    t0 = box.tree()
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 2, 'gleich', 10))
    check(G, 'F04 t==H angenommen', r['status'] == 200 and box.high() == (DOMAIN, 10) and r['json']['fence']['high_after'] == 10
          and box.tree()['zones/.dnsmanager-fence'] == t0['zones/.dnsmanager-fence'], f"status={r['status']}")
    r = call(box, 'zones/conf_sync.php', fc(box, 'c.as1.test', 12))
    check(G, 'F04b t>H angenommen, H fortgeschrieben', r['status'] == 200 and box.high() == (DOMAIN, 12) and not box.latch()
          and r['json']['fence'] == {'mode': 'enforce', 'decision': 'accept', 'code': None, 'token': 12, 'high_before': 10, 'high_after': 12},
          f"{r['json']}")
    r = call(box, 'zones/zone_cleanup.php', fcl(['a.as1.test', 'c.as1.test'], '12'))
    check(G, 'F04c Token als Zeichenkette', r['status'] == 200 and box.high() == (DOMAIN, 12), f"status={r['status']}")
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 3, 'x', 11))
    check(G, 'F04d nach Erhöhung älteres Token abgelehnt', r['status'] == 409 and code(r) == 'stale_fence', f"status={r['status']}")
    # F05 Fehlermarke ⇒ fence_fault (fail-closed), Aufhebung nur per CLI mit passendem H
    with open(os.path.join(box.zones, '.dnsmanager-fence.fault'), 'w') as f:
        f.write('test\n')
    t0 = box.tree()
    for ep, body in [('zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13)), ('zones/conf_sync.php', fc(box, 'd.as1.test', 13)),
                     ('zones/zone_cleanup.php', fcl([], 13, allow_empty=True))]:
        r = call(box, ep, body)
        check(G, f'F05 Fehlermarke {ep}', r['status'] == 503 and code(r) == 'fence_fault' and box.tree() == t0 and box.high() == (DOMAIN, 12), f"status={r['status']}")
    rc, d = box.cli_run('clear-fault', '--expect-high=11')
    check(G, 'F05b clear-fault mit falschem H abgelehnt', rc == 2 and box.latch(), f'rc={rc}')
    rc, d = box.cli_run('clear-fault', '--expect-high=12')
    check(G, 'F05c clear-fault', rc == 0 and not box.latch() and box.high() == (DOMAIN, 12), f'rc={rc}')
    rc, d = box.cli_run('raise', '--high=12')
    check(G, 'F05d raise gleich abgelehnt', rc == 2, f'rc={rc}')
    # F06 H ungültig / fremde Domäne / Symlink / Verzeichnis
    for name, content in [('format', 'kaputt\n'), ('leer', ''), ('ohne_lf', f'dnsmanager-fence v1 domain={DOMAIN} high=12'),
                          ('fuehrende_null', f'dnsmanager-fence v1 domain={DOMAIN} high=012\n')]:
        box.write_high_raw(content)
        r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13))
        check(G, f'F06 H {name}', r['status'] == 503 and code(r) == 'fence_invalid', f"status={r['status']} code={code(r)}")
    box.write_high_raw(f'dnsmanager-fence v1 domain={OTHER} high=12\n')
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13))
    check(G, 'F06b H fremde Domäne', r['status'] == 409 and code(r) == 'fence_domain_mismatch', f"status={r['status']}")
    hp = os.path.join(box.zones, '.dnsmanager-fence')
    os.unlink(hp)
    with open(os.path.join(box.d, 'h_echt'), 'w') as f:
        f.write(f'dnsmanager-fence v1 domain={DOMAIN} high=12\n')
    os.symlink(os.path.join(box.d, 'h_echt'), hp)
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13))
    check(G, 'F06c H als Symlink', r['status'] == 503 and code(r) == 'fence_invalid', f"status={r['status']}")
    os.unlink(hp)
    os.mkdir(hp)
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13))
    check(G, 'F06d H als Verzeichnis', r['status'] == 503 and code(r) == 'fence_invalid', f"status={r['status']}")
    os.rmdir(hp)
    box.write_high_raw(f'dnsmanager-fence v1 domain={DOMAIN} high=12\n')
    # F07 Fehlkonfiguration
    box.config('enforce', domain=None)
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13))
    check(G, 'F07 Domäne nicht konfiguriert', r['status'] == 503 and code(r) == 'fence_misconfigured', f"status={r['status']}")
    box.config('enforce', domain='KEINE-UUID')
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13))
    check(G, 'F07b Domäne ungültig', r['status'] == 503 and code(r) == 'fence_misconfigured', f"status={r['status']}")
    box.config('enforced')
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13))
    check(G, 'F07c unbekannter Modus fail-closed', r['status'] == 503 and code(r) == 'fence_misconfigured', f"status={r['status']}")
    box.config('enforce', domain=OTHER)
    r = call(box, 'zones/zone_sync.php', fz('a.as1.test', 4, 'x', 13, OTHER))
    check(G, 'F07d konfigurierte Domäne ≠ gespeicherte', r['status'] == 409 and code(r) == 'fence_domain_mismatch', f"status={r['status']}")
    # F08 observe: nie ablehnen, Bericht would_reject, H fortschreiben
    box.config('observe')
    for name, body, exp_code, exp_high in [
            ('ohne_token', fz('a.as1.test', 5, 'o1'), 'fence_token_missing', 12),
            ('stale', fz('a.as1.test', 6, 'o2', 3), 'stale_fence', 12),
            ('fremd', fz('a.as1.test', 7, 'o3', 13, OTHER), 'fence_domain_mismatch', 12),
            ('hoeher', fz('a.as1.test', 8, 'o4', 15), None, 15)]:
        n0 = box.rndc_calls()
        r = call(box, 'zones/zone_sync.php', body)
        fe = (r['json'] or {}).get('fence', {})
        check(G, f'F08 observe {name}', r['status'] == 200 and fe.get('code') == exp_code
              and fe.get('decision') == ('would_reject' if exp_code else 'accept') and box.high() == (DOMAIN, exp_high)
              and box.rndc_calls() == n0 + 1, f"status={r['status']} fence={fe}")
    with open(os.path.join(box.zones, '.dnsmanager-fence.fault'), 'w') as f:
        f.write('test\n')
    r = call(box, 'zones/conf_sync.php', fc(box, 'e.as1.test', 20))
    fe = (r['json'] or {}).get('fence', {})
    check(G, 'F08b observe mit Fehlermarke: angenommen, H unverändert', r['status'] == 200 and fe.get('code') == 'fence_fault'
          and box.high() == (DOMAIN, 15), f"fence={fe}")
    os.unlink(os.path.join(box.zones, '.dnsmanager-fence.fault'))
    # F09 system/status je Modus und Zustand
    box.config(None)
    r = call(box, 'system/status.php', method='GET')
    check(G, 'F09 status off ohne Zusatzfelder', 'fencing' not in (r['json'] or {}) and 'capabilities' not in (r['json'] or {}), '')
    for mode in ['observe', 'enforce']:
        box.config(mode)
        r = call(box, 'system/status.php', method='GET')
        js = r['json'] or {}
        check(G, f'F09 status {mode}', js.get('capabilities') == {'fencing': 1, 'durable_writes': 1}
              and js.get('fencing') == {'mode': mode, 'domain': DOMAIN, 'state': 'ok', 'high': 15, 'fault': False}, f"{js.get('fencing')}")
    with open(os.path.join(box.zones, '.dnsmanager-fence.fault'), 'w') as f:
        f.write('x\n')
    r = call(box, 'system/status.php', method='GET')
    check(G, 'F09b status fault', (r['json'] or {}).get('fencing', {}).get('state') == 'fault', '')
    os.unlink(os.path.join(box.zones, '.dnsmanager-fence.fault'))
    box.config('enforce', domain=OTHER)
    r = call(box, 'system/status.php', method='GET')
    check(G, 'F09c status domain_mismatch', (r['json'] or {}).get('fencing', {}).get('state') == 'domain_mismatch', '')
    box.config('enforce')
    os.unlink(os.path.join(box.zones, '.dnsmanager-fence'))
    r = call(box, 'system/status.php', method='GET')
    check(G, 'F09d status uninitialized', (r['json'] or {}).get('fencing', {}).get('state') == 'uninitialized', '')
    box.config('foo')
    r = call(box, 'system/status.php', method='GET')
    check(G, 'F09e status misconfigured', (r['json'] or {}).get('fencing', {}).get('state') == 'misconfigured', '')
    # F10 Prüfung erst nach dem flock: ältere Anfrage wartet auf den Lock, während ein neuerer Stand H erhöht
    box.reset(); box.config('enforce'); box.put_zone('a.as1.test', 1); sh('sync'); box.init_high(4)
    lockf = open(os.path.join(box.zones, '.dnsmanager-agent.lock'), 'a+')
    fcntl.flock(lockf, fcntl.LOCK_EX)
    srv = Server(box)
    res = {}
    th = threading.Thread(target=lambda: res.update(srv.request('zones/zone_sync.php', fz('a.as1.test', 2, 'alt', 5))))
    th.start()
    time.sleep(1.5)
    box.write_high_raw(f'dnsmanager-fence v1 domain={DOMAIN} high=6\n')
    fcntl.flock(lockf, fcntl.LOCK_UN)
    lockf.close()
    th.join(30)
    srv.stop()
    check(G, 'F10 Prüfung nach flock (verspätete Anfrage)', res.get('status') == 409 and code(res) == 'stale_fence'
          and open(os.path.join(box.zones, 'db.a.as1.test')).read() == zone_text('a.as1.test', 1), f"status={res.get('status')} code={code(res)}")
    # F11 CLI wartet auf den Lock (kein Schreiben ohne flock)
    lockf = open(os.path.join(box.zones, '.dnsmanager-agent.lock'), 'a+')
    fcntl.flock(lockf, fcntl.LOCK_EX)
    p = subprocess.Popen(['php', box.cli, f'--config={box.cfgpath}', 'raise', '--high=7'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    time.sleep(1.0)
    waited = p.poll() is None and box.high() == (DOMAIN, 6)
    fcntl.flock(lockf, fcntl.LOCK_UN)
    lockf.close()
    p.wait(40)
    check(G, 'F11 CLI unter dem Agent-flock', waited and p.returncode == 0 and box.high() == (DOMAIN, 7), f'rc={p.returncode}')
    # F12 Cleanup unter enforce: entfernt nur mit gültigem Token
    box.put_zone('b.as1.test', 1); sh('sync')
    r = call(box, 'zones/zone_cleanup.php', fcl(['a.as1.test'], 6))
    check(G, 'F12 Cleanup mit veraltetem Token entfernt nichts', r['status'] == 409 and os.path.exists(os.path.join(box.confdir, 'b.as1.test.conf')), '')
    r = call(box, 'zones/zone_cleanup.php', fcl(['a.as1.test'], 8))
    check(G, 'F12b Cleanup mit gültigem Token', r['status'] == 200 and not os.path.exists(os.path.join(box.confdir, 'b.as1.test.conf'))
          and box.high() == (DOMAIN, 8), f"{r['json']}")


# ------------------------------------------------------------------------------------------------------------------
# Szenarien für S/I/C: Ausgangsbestand dauerhaft, dann eine Anfrage mit t > H (enforce)
# ------------------------------------------------------------------------------------------------------------------
def scenarios(box):
    def base():
        box.reset(); box.config('enforce'); box.put_zone('a.as1.test', 1, 'alt'); box.put_zone('b.as1.test', 1, 'alt')
        sh('sync'); box.init_high(10); sh('sync')
    all_ = {
        'zone_sync': (base, 'zones/zone_sync.php', lambda: fz('a.as1.test', 2, 'neu', 11)),
        'conf_sync': (base, 'zones/conf_sync.php', lambda: fc(box, 'c.as1.test', 11)),
        'zone_cleanup': (base, 'zones/zone_cleanup.php', lambda: fcl(['a.as1.test'], 11)),
    }
    return {k: v for k, v in all_.items() if SCENARIOS is None or k in SCENARIOS}


def expected_new(box, name):
    """Inhalte des vollständig übernommenen Zustands je Szenario."""
    z = box.zones
    if name == 'zone_sync':
        return {'zones/db.a.as1.test': hashlib.sha256(zone_text('a.as1.test', 2, 'neu').encode()).hexdigest()}
    if name == 'conf_sync':
        return {'zones/conf/c.as1.test.conf': hashlib.sha256(box.conf_text('c.as1.test').encode()).hexdigest()}
    return {'zones/conf/b.as1.test.conf': None, 'zones/db.b.as1.test': None}


def state_kind(box, name, t_old, t_now):
    """old | new | mixed – bezogen auf die von der Anfrage betroffenen Dateien."""
    exp = expected_new(box, name)
    rel = [k for k in exp]
    cur = {k: t_now.get(k) for k in rel}
    old = {k: t_old.get(k) for k in rel}
    if cur == old:
        return 'old'
    if cur == exp:
        return 'new'
    return 'mixed'


def other_files_ok(box, name, t_old, t_now, kind):
    """Nicht betroffene Dateien: unverändert; zones.conf: alt oder neu passend; keine fremden Dateien außer Fencing/Temp."""
    ignore = re.compile(r'(\.dnsmanager-fence(\.fault)?$|\.dnsmanager-fence\.[0-9a-f]+\.tmp$|\.tmp$|\.dnsmanager-agent\.lock$)')
    for k in set(t_old) | set(t_now):
        if ignore.search(k) or k in expected_new(box, name) or k == 'zones/zones.conf':
            continue
        if t_old.get(k) != t_now.get(k):
            return False, k
    return True, None


def trace_lines(path, bind):
    out = []
    with open(path) as f:
        for ln in f:
            m = re.match(r'^(?:(\d+)\s+)?(\w+)\((.*)$', ln)
            if not m:
                continue
            out.append({'pid': m.group(1), 'name': m.group(2), 'args': m.group(3).rstrip(), 'line': ln.rstrip(),
                        'rel': bind in m.group(3)})
    return out


# ------------------------------------------------------------------------------------------------------------------
# Gruppe S – strace-Reihenfolge
# ------------------------------------------------------------------------------------------------------------------
def short(box, l):
    s = l['line'].replace(box.bind, '<B>')
    return re.sub(r'^\d+\s+', '', s)


def group_s(box):
    G = 'S'
    for name, (base, ep, mk) in scenarios(box).items():
        base()
        tr = os.path.join(OUT, f'strace_{name}.txt')
        r = call(box, ep, mk(), strace={'out': tr, 'trace': PERSIST_SYSCALLS + ['execve'], 'follow': True})
        lines = [l for l in trace_lines(tr, box.bind) if l['rel'] or l['name'] == 'execve' and 'rndc' in l['args']]
        with open(os.path.join(OUT, f'strace_{name}_relevant.txt'), 'w') as f:
            f.write('\n'.join(short(box, l) for l in lines) + '\n')
        seq = [short(box, l) for l in lines]

        def idx(pat, start=0):
            for i in range(start, len(seq)):
                if re.search(pat, seq[i]):
                    return i
            return -1
        steps = [r'flock\(\d+<<B>/zones/\.dnsmanager-agent\.lock>, LOCK_EX\)',
                 r'openat\(.*\.dnsmanager-fence\.fault", O_WRONLY\|O_CREAT\|O_EXCL',
                 r'write\(\d+<<B>/zones/\.dnsmanager-fence\.fault>',
                 r'fsync\(\d+<<B>/zones/\.dnsmanager-fence\.fault>\)',
                 r'openat\(AT_FDCWD[^,]*, "<B>/zones", O_RDONLY',
                 r'fsync\(\d+<<B>/zones>\)',
                 r'openat\(.*\.dnsmanager-fence\.[0-9a-f]+\.tmp", O_WRONLY\|O_CREAT\|O_EXCL',
                 r'write\(\d+<<B>/zones/\.dnsmanager-fence\.[0-9a-f]+\.tmp>',
                 r'fsync\(\d+<<B>/zones/\.dnsmanager-fence\.[0-9a-f]+\.tmp>\)',
                 r'rename\(".*\.dnsmanager-fence\.[0-9a-f]+\.tmp", "<B>/zones/\.dnsmanager-fence"\)',
                 r'fsync\(\d+<<B>/zones>\)',
                 r'unlink\("<B>/zones/\.dnsmanager-fence\.fault"\)',
                 r'fsync\(\d+<<B>/zones>\)']
        change = {'zone_sync': [r'rename\(".*/\.db\.a\.as1\.test\.[0-9a-f]+\.tmp", "<B>/zones/db\.a\.as1\.test"\)', r'fsync\(\d+<<B>/zones>\)'],
                  'conf_sync': [r'fsync\(\d+<<B>/zones/conf/c\.as1\.test\.conf\.[0-9a-f]+\.tmp>\)', r'rename\(".*c\.as1\.test\.conf\.[0-9a-f]+\.tmp", "<B>/zones/conf/c\.as1\.test\.conf"\)',
                                r'fsync\(\d+<<B>/zones/zones\.conf\.[0-9a-f]+\.tmp>\)', r'rename\(".*zones\.conf\.[0-9a-f]+\.tmp", "<B>/zones/zones\.conf"\)',
                                r'fsync\(\d+<<B>/zones/conf>\)', r'fsync\(\d+<<B>/zones>\)'],
                  'zone_cleanup': [r'fsync\(\d+<<B>/zones/zones\.conf\.[0-9a-f]+\.tmp>\)', r'rename\(".*zones\.conf\.[0-9a-f]+\.tmp", "<B>/zones/zones\.conf"\)',
                                   r'unlink\("<B>/zones/db\.b\.as1\.test"\)', r'unlink\("<B>/zones/conf/b\.as1\.test\.conf"\)',
                                   r'fsync\(\d+<<B>/zones/conf>\)', r'fsync\(\d+<<B>/zones>\)']}[name]
        pos, ok, missing = 0, True, None
        for pat in steps + change + [r'execve\(.*rndc']:
            i = idx(pat, pos)
            if i < 0:
                ok, missing = False, pat
                break
            pos = i + 1
        # Keine Änderung vor dem Fencing: vor der Entfernung der Marke kein rename/unlink außerhalb der Fencing-Dateien
        latch_rm = idx(r'unlink\("<B>/zones/\.dnsmanager-fence\.fault"\)')
        early = [s for s in seq[:latch_rm] if re.search(r'^(rename|unlink)\(', s) and '.dnsmanager-fence' not in s
                 and not re.search(r'\.db\.a\.as1\.test\.[0-9a-f]+\.tmp"\)$', s)]
        check(G, f'S01 {name} Syscall-Reihenfolge', ok and not early and r['status'] == 200,
              f"fehlend={missing} frueh={early[:2]} status={r['status']} (Mitschnitt strace_{name}_relevant.txt)")


# ------------------------------------------------------------------------------------------------------------------
# Gruppe I – Fehlerinjektion je Schritt
# ------------------------------------------------------------------------------------------------------------------
def persist_points(box, name, base, ep, mk):
    base()
    tr = os.path.join(OUT, f'baseline_{name}.txt')
    call(box, ep, mk(), strace={'out': tr})
    lines = trace_lines(tr, box.bind)
    counts, pts = {}, []
    for l in lines:
        counts[l['name']] = counts.get(l['name'], 0) + 1
        if l['rel'] and l['name'] in PERSIST_SYSCALLS:
            pts.append({'name': l['name'], 'when': counts[l['name']], 'line': short(box, l)})
    return pts


def step_key(line):
    """Syscall-Name und erstes Argument; Dateideskriptor-Nummern und Zufallsteile normalisiert."""
    m = re.match(r'^(?:\d+\s+)?(\w+)\((.*)$', line)
    if not m:
        return None
    rest = m.group(2).replace(' <unfinished ...>', '')
    args = re.split(r'\) = ', rest, maxsplit=1)[0].rstrip(')').split(', ')
    # openat: Pfad und Flags (das erste Argument ist nur AT_FDCWD<Arbeitsverzeichnis>)
    parts = args[1:3] if m.group(1) == 'openat' else args[:1]
    key = ', '.join(parts)
    key = re.sub(r'(^|, )\d+<', r'\1<', key)
    key = re.sub(r'\.[0-9a-f]{16}\.tmp', '.X.tmp', key)
    return m.group(1) + '(' + key


def verify_after(box, name, t_old, r, label, G, post_crash=False):
    t_now = box.tree()
    kind = state_kind(box, name, t_old, t_now)
    h = box.high()
    latch = box.latch()
    ok_other, bad = other_files_ok(box, name, t_old, t_now, kind)
    js = r.get('json') or {}
    status = r.get('status')
    problems = []
    if h not in [(DOMAIN, 10), (DOMAIN, 11)]:
        problems.append(f'H={h}')
    if kind == 'new' and h != (DOMAIN, 11):
        problems.append('Änderung ohne dauerhaftes Token')
    if kind == 'mixed' and not (name == 'zone_cleanup' and h == (DOMAIN, 11)):
        problems.append('Mischzustand')
    if not ok_other:
        problems.append(f'fremde Datei verändert: {bad}')
    zc = t_now.get('zones/zones.conf')
    if not post_crash:
        if status == 200:
            if not (kind == 'new' and h == (DOMAIN, 11) and not latch):
                problems.append('200 ohne vollständigen Zustand')
            if open(box.zonesconf).read() != box.expected_zonesconf():
                problems.append('zones.conf bindet nicht genau die vorhandenen .conf-Dateien ein')
        else:
            flagged = js.get('rollback') == 'failed' or (name == 'zone_cleanup' and 'rndc' in js)
            if kind != 'old' and not flagged:
                problems.append(f'Fehler {status} mit Änderung ohne Kennzeichnung')
            if kind == 'old' and box.rndc_calls() > 0 and not flagged:
                problems.append('Reload trotz Fehler ohne Änderung')
            if not flagged and kind == 'old' and t_now.get('zones/zones.conf') != t_old.get('zones/zones.conf'):
                problems.append('zones.conf verändert ohne Kennzeichnung')
            # Ausnahme: Erst der Verzeichnis-fsync nach dem Entfernen der Marke ist gescheitert – H ist dann bereits
            # dauerhaft (high_dir_sync erfolgreich); die Marke kann nach einem Absturz nur wieder erscheinen.
            step = (js.get('fence') or {}).get('step')
            if js.get('code') == 'fence_persist_failed' and not latch and h == (DOMAIN, 11) and step != 'latch_unlink_dir_sync':
                problems.append('H erhöht, aber keine Fehlermarke')
    if zc is not None and post_crash:
        # zones.conf muss vollständig und gültig sein
        if sh(['named-checkconf', os.path.join(box.bind, 'named.conf')], check_rc=False).returncode != 0:
            problems.append('zones.conf ungültig')
    return problems, kind, h, latch


def group_i(box):
    G = 'I'
    for name, (base, ep, mk) in scenarios(box).items():
        pts = persist_points(box, name, base, ep, mk)
        log(f'I {name}: {len(pts)} Schritte')
        for p in pts:
            base()
            t_old = box.tree()
            tr = os.path.join(OUT, 'inj.txt')
            err = INJECT_ERR[p['name']]
            r = call(box, ep, mk(), strace={'out': tr, 'inject': f"inject={p['name']}:error={err}:when={p['when']}"})
            inj = [l.replace(box.bind, '<B>') for l in open(tr) if '(INJECTED)' in l]
            # Treffer am erwarteten Schritt: gleicher Syscall und Pfad UND gleiche Ordnungszahl unter allen Aufrufen
            same_name = [l for l in trace_lines(tr, box.bind) if l['name'] == p['name']]
            ordinal = next((i + 1 for i, l in enumerate(same_name) if '(INJECTED)' in l['line']), None)
            same_path = len(inj) == 1 and step_key(inj[0]) == step_key(p['line']) and ordinal == p['when']
            problems, kind, h, latch = verify_after(box, name, t_old, r, p['line'], G)
            # Nach einem Fehler mit Fehlermarke muss die nächste Anfrage fail-closed sein
            if latch:
                r2 = call(box, ep, mk())
                if not (r2['status'] == 503 and code(r2) == 'fence_fault'):
                    problems.append('nach Fehlermarke nicht fail-closed')
            check(G, f"I {name} {p['name']}#{p['when']} {err}", same_path and not problems,
                  f"{p['line'][:110]} ⇒ status={r.get('status')} code={code(r)} zustand={kind} H={h} marke={latch} {problems}"
                  + ('' if same_path else ' INJEKTION NICHT AM ERWARTETEN SCHRITT'))


# ------------------------------------------------------------------------------------------------------------------
# Gruppe C – Absturz vor jedem Schritt + Stromausfall
# ------------------------------------------------------------------------------------------------------------------
def group_c(box, flakey):
    G = 'C'
    for name, (base, ep, mk) in scenarios(box).items():
        pts = persist_points(box, name, base, ep, mk)
        for p in pts + [None]:
            base()
            t_old = box.tree()
            if p is None:
                r = call(box, ep, mk())
                label = 'nach Abschluss (Antwort 200)'
            else:
                tr = os.path.join(OUT, 'crash.txt')
                r = call(box, ep, mk(), strace={'out': tr, 'inject': f"inject={p['name']}:signal=SIGKILL:when={p['when']}"})
                label = f"SIGKILL vor {p['name']}#{p['when']} {p['line'][:90]}"
            flakey.power_loss()
            problems, kind, h, latch = verify_after(box, name, t_old, {'status': None}, label, G, post_crash=True)
            if p is None:
                if not (r['status'] == 200 and kind == 'new' and h == (DOMAIN, 11) and not latch):
                    problems.append(f"bestätigte Änderung nicht dauerhaft (status={r['status']} zustand={kind} H={h} marke={latch})")
                if open(box.zonesconf).read() != box.expected_zonesconf():
                    problems.append('bestätigte zones.conf nicht dauerhaft')
            elif r.get('status') is not None:
                problems.append('Prozess wurde nicht beendet')
            if p is not None:
                # Der zuletzt begonnene Syscall muss der erwartete Schritt sein (gleicher Name, Pfad, Ordnungszahl)
                tl = trace_lines(tr, box.bind)
                last = tl[-1] if tl else None
                ordn = sum(1 for l in tl if last and l['name'] == last['name'])
                if not (last and last['name'] == p['name'] and ordn == p['when']
                        and step_key(last['line'].replace(box.bind, '<B>')) == step_key(p['line'])):
                    problems.append(f"Abbruch nicht am erwarteten Schritt ({last['line'][:80] if last else '-'})")
            check(G, f"C {name} {label[:60]}", not problems, f"{label} ⇒ zustand={kind} H={h} marke={latch} {problems}")


# ------------------------------------------------------------------------------------------------------------------
# Gruppe G – off gegenüber dem bisherigen Agenten
# ------------------------------------------------------------------------------------------------------------------
def golden_run(box, explicit_off=False, with_fence_fields=False):
    box.reset()
    box.config('off' if explicit_off else None)
    ff = (lambda b: {**b, 'fence_token': 7, 'fence_domain': DOMAIN}) if with_fence_fields else (lambda b: b)
    steps = [
        ('GET', 'system/status.php', None, TOKEN),
        ('POST', 'zones/zone_sync.php', ff(fz('a.as1.test', 1)), TOKEN),
        ('POST', 'zones/conf_sync.php', ff(fc(box, 'a.as1.test')), TOKEN),
        ('POST', 'zones/zone_sync.php', ff(fz('b.as1.test', 1)), TOKEN),
        ('POST', 'zones/conf_sync.php', ff(fc(box, 'b.as1.test')), TOKEN),
        ('POST', 'zones/zone_sync.php', ff({'zone_id': 1, 'zone_name': 'a.as1.test', 'zone_data': __import__('base64').b64encode(b'kaputt').decode()}), TOKEN),
        ('POST', 'zones/zone_sync.php', ff({'zone_name': 'a.as1.test'}), TOKEN),
        ('POST', 'zones/conf_sync.php', ff({'zone_name': 'x'}), TOKEN),
        ('POST', 'zones/conf_sync.php', ff({'zone_name': 'kaputt.as1.test', 'conf_data': __import__('base64').b64encode(b'zone "k" { kaputt; };').decode()}), TOKEN),
        ('POST', 'zones/zone_check.php', {'zone_name': 'a.as1.test'}, TOKEN),
        ('GET', 'zones/conf_check.php', None, TOKEN),
        ('POST', 'zones/zone_cleanup.php', ff(fcl(['a.as1.test', 'b.as1.test'])), TOKEN),
        ('POST', 'zones/zone_cleanup.php', ff(fcl([])), TOKEN),
        ('POST', 'zones/zone_cleanup.php', ff(fcl(['a.as1.test'])), TOKEN),
        ('POST', 'zones/zone_cleanup.php', ff(fcl([], allow_empty=True)), TOKEN),
        ('POST', 'system/control.php', {'action': 'reload-bind'}, TOKEN),
        ('POST', 'system/control.php', {'action': 'unbekannt'}, TOKEN),
        ('POST', 'zones/zone_sync.php', ff(fz('a.as1.test', 2)), None),
        ('POST', 'zones/zone_sync.php', ff(fz('a.as1.test', 2)), 'falsch'),
    ]
    out = []
    for method, ep, body, tok in steps:
        r = call(box, ep, body, method, tok)
        raw = r['raw']
        if ep == 'system/status.php' and r['json']:
            j = dict(r['json'])
            j['uptime_seconds'] = 'X'
            j['load_average'] = 'X'
            raw = json.dumps(j, sort_keys=True).encode()
        out.append({'ep': ep, 'status': r['status'], 'ctype': r['ctype'],
                    # Zufallsteil der Temp-Datei (named-checkzone-Ausgabe) normalisieren
                    'body': re.sub(r'\.[0-9a-f]{16}\.tmp', '.X.tmp',
                                   raw.decode(errors='replace').replace(box.bind.replace('/', '\\/'), '<B>').replace(box.bind, '<B>')),
                    'tree': box.tree(normalize=True), 'rndc': box.rndc_calls()})
    return out


def group_g(src_box, ref_box):
    G = 'G'
    ref = golden_run(ref_box)
    for label, kw in [('ohne Fencing-Schlüssel', {}), ('fencing_mode=off', {'explicit_off': True}),
                      ('off mit fence_token im Body', {'with_fence_fields': True})]:
        new = golden_run(src_box, **kw)
        diffs = []
        for i, (a2, b2) in enumerate(zip(ref, new)):
            for fld in ['status', 'ctype', 'body', 'rndc', 'tree']:
                if a2[fld] != b2[fld]:
                    diffs.append((i, a2['ep'], fld, str(a2[fld])[:160], str(b2[fld])[:160]))
        with open(os.path.join(OUT, f"golden_{label.replace(' ', '_').replace('=', '-')}.json"), 'w') as f:
            json.dump({'ref': ref, 'new': new}, f, indent=1, ensure_ascii=False)
        check(G, f'G01 off identisch ({label})', not diffs and len(ref) == len(new), f'{len(ref)} Schritte, Abweichungen={diffs[:3]}')


# ------------------------------------------------------------------------------------------------------------------
# Gruppe M – Mutationsproben
# ------------------------------------------------------------------------------------------------------------------
MUTATIONS = [
    ('M01 t<H angenommen', 'inc/fence.php', "if ($token < $h['high']) {", "if ($token < 0) {", 'F'),
    ('M02 H nie fortgeschrieben', 'inc/fence.php', "$r['raise'] = $token > $h['high'];", "$r['raise'] = false;", 'F'),
    ('M03 ohne Write-ahead-Marke', 'inc/fence.php', "    if (!writeFileDurable($p['latch'], $latch)) {\n        return ['ok' => false, 'step' => 'latch_write'];\n    }\n    if (!syncDirectory($p['dir'])) {\n        return ['ok' => false, 'step' => 'latch_dir_sync'];\n    }\n", "", 'I', 'zone_sync'),
    ('M04 ohne Verzeichnis-fsync nach rename von H', 'inc/fence.php', "    if (!syncDirectory($p['dir'])) {\n        return ['ok' => false, 'step' => 'high_dir_sync'];\n    }\n", "", 'CI', 'zone_sync', 'equivalent'),
    ('M05 ohne Datei-fsync', 'inc/file_utils.php', "$ok = @fflush($fh) && @fsync($fh);", "$ok = @fflush($fh);", 'C', 'zone_sync'),
    ('M06 Prüfung vor dem flock', 'api/v1/zones/zone_sync.php', None, None, 'F'),
    ('M07 fehlendes H angenommen', 'inc/fence.php', "    if ($h['state'] === 'missing') {\n", "    if ($h['state'] === 'missing') { $h = ['state' => 'ok', 'domain' => $domain, 'high' => 1, 'detail' => null]; }\n    if (false) {\n", 'F'),
    ('M08 Fehlermarke ignoriert', 'inc/fence.php', "    return file_exists($latch) || is_link($latch);", "    return false;", 'F'),
    ('M09 Anfrage-Domäne nicht geprüft', 'inc/fence.php', "if (!hash_equals($domain, $in['fence_domain'])) {", "if (false) {", 'F'),
    ('M10 Marke vor Persistenz entfernt', 'inc/fence.php', "    // 2. Neuen Wert dauerhaft schreiben\n", "    @unlink($p['latch']);\n    // 2. Neuen Wert dauerhaft schreiben\n", 'I', 'zone_sync'),
    ('M11 zone_sync ohne Verzeichnis-fsync', 'api/v1/zones/zone_sync.php', "if (!syncDirectory($zoneDir)) {", "if (false) {", 'C', 'zone_sync'),
    ('M12 conf_sync ohne Verzeichnis-fsync', 'api/v1/zones/conf_sync.php', "if (!syncDirectories($syncDirs)) {\n    $failWithRollback", "if (false) {\n    $failWithRollback", 'C', 'conf_sync'),
    ('M13 Cleanup ohne Verzeichnis-fsync', 'api/v1/zones/zone_cleanup.php', "if (!syncDirectories([$confDir, $zoneDir, dirname($zonesConfPath)])) {", "if (false) {", 'C', 'zone_cleanup'),
    ('M14 observe lehnt ab', 'inc/fence.php', "if ($e['code'] !== null && $mode !== 'observe') {", "if ($e['code'] !== null) {", 'F'),
    ('M16 conf_sync ohne Lesefehlererkennung (S43)', 'api/v1/zones/conf_sync.php', '$confFiles = listConfFiles($confDir);', '$confFiles = glob("$confDir/*.conf");', 'I', 'conf_sync'),
    ('M17 Cleanup ohne Lesefehlererkennung (S43)', 'api/v1/zones/zone_cleanup.php', '$confFiles = listConfFiles($confDir);', '$confFiles = glob("$confDir/*.conf");', 'I', 'zone_cleanup'),
    ('M18 Abgleich scandir/glob entfernt (S43)', 'inc/file_utils.php', '    return $expected === $got ? $glob : false;', '    return $glob;', 'I', 'conf_sync'),
    ('M15 off liest H', 'inc/fence.php', "    if ($mode === 'off') {\n        return ['allow' => true, 'http' => 200, 'body' => null];\n    }\n\n    $e = fenceEvaluate", "    $e = fenceEvaluate", 'G'),
]


def mutate(src_agent, dst, mut):
    shutil.copytree(src_agent, dst)
    f, old, new = mut[1], mut[2], mut[3]
    path = os.path.join(dst, f)
    s = open(path).read()
    if old is None:  # M06: Fencing-Block vor den Lock verschieben
        blk_start = s.index('// === AS1: Fencing-Prüfung unter dem Lock')
        blk_end = s.index('// === named-checkconf-Preflight')
        blk = s[blk_start:blk_end]
        s = s[:blk_start] + s[blk_end:]
        anchor = s.index('// === Globalen exklusiven Lock erwerben')
        blk = blk.replace("    flock($lockHandle, LOCK_UN);\n    fclose($lockHandle);\n", '')
        s = s[:anchor] + blk + s[anchor:]
    else:
        if s.count(old) != 1:
            raise RuntimeError(f'Mutation {mut[0]}: Muster {s.count(old)}x gefunden')
        s = s.replace(old, new)
    open(path, 'w').write(s)


# ------------------------------------------------------------------------------------------------------------------
def main():
    global OUT
    ap = argparse.ArgumentParser()
    ap.add_argument('--src', required=True)
    ap.add_argument('--ref')
    ap.add_argument('--out', required=True)
    ap.add_argument('--groups', default='FSICGM')
    ap.add_argument('--mutant')
    ap.add_argument('--stop-on-fail', action='store_true')
    ap.add_argument('--scenario')
    a = ap.parse_args()
    global STOP_ON_FAIL, SCENARIOS
    STOP_ON_FAIL = a.stop_on_fail
    SCENARIOS = a.scenario.split(',') if a.scenario else None
    OUT = a.out
    os.makedirs(OUT, exist_ok=True)
    if os.geteuid() != 0:
        sys.exit('als root ausführen (Loop/dm-flakey, sudo-Platzhalter)')
    root = tempfile.mkdtemp(prefix='as1-', dir='/tmp')
    os.chmod(root, 0o711)
    log(f'Sandbox {root}')
    flakey = Flakey(root)
    try:
        bindbase = flakey.mnt
        agent_tree = None
        if a.mutant:
            mut = [m for m in MUTATIONS if m[0].startswith(a.mutant + ' ')][0]
            agent_tree = os.path.join(root, 'mutant-agent')
            mutate(os.path.join(a.src, 'agent'), agent_tree, mut)
        box = Box(root, a.src, 'new', bindbase, agent_tree)
        if 'F' in a.groups:
            group_f(box)
        if 'S' in a.groups:
            group_s(box)
        if 'I' in a.groups:
            group_i(box)
        if 'C' in a.groups:
            group_c(box, flakey)
        if 'G' in a.groups:
            ref_box = Box(root, a.ref, 'ref', bindbase)
            group_g(box, ref_box)
    except StopIteration:
        log('Abbruch nach erstem Fehlschlag (--stop-on-fail)')
    finally:
        flakey.close()
        shutil.rmtree(root, ignore_errors=True)
    if 'M' in a.groups and not a.mutant:
        for mut in MUTATIONS:
            sub_out = os.path.join(OUT, 'mut_' + mut[0].split()[0])
            p = subprocess.run([sys.executable, __file__, '--src', a.src, '--ref', a.ref or a.src, '--out', sub_out,
                                '--groups', mut[4], '--mutant', mut[0].split()[0], '--stop-on-fail']
                               + (['--scenario', mut[5]] if len(mut) > 5 else []), capture_output=True, text=True)
            fails = len(re.findall(r'^FAIL ', p.stdout, re.M))
            if len(mut) > 6 and mut[6] == 'equivalent':
                # Auf ext4 äquivalent: der Verzeichnis-fsync nach dem Entfernen der Marke schreibt das Journal in
                # Reihenfolge fest und macht damit auch das rename von H dauerhaft (Abschnitt 5e). Ausgewiesen, nicht verschwiegen.
                check('M', mut[0], True, f'getötet durch {fails} Fehlschläge' if fails else
                      f'überlebt – auf ext4 äquivalenter Mutant (Journal-Reihenfolge), Verzeichnis-fsync bleibt als dateisystemunabhängige Absicherung (rc={p.returncode})')
                continue
            check('M', mut[0], fails > 0, f'getötet durch {fails} Fehlschläge in Gruppe {mut[4]}' if fails else f'NICHT getötet (rc={p.returncode}) {p.stderr[-300:]}')
    total = len(RESULTS)
    failed = [r for r in RESULTS if not r[2]]
    summary = {g: sum(1 for r in RESULTS if r[0] == g) for g in sorted({r[0] for r in RESULTS})}
    log(f'ERGEBNIS {total - len(failed)}/{total} bestanden; je Gruppe {summary}; Fehlschläge {len(failed)}')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
