#!/usr/bin/env python3
# Datei: tests/history/journal_agent.py
# Zweck: Isolierter Testagent des Publish-Journals (AP1/H2d). Führt den ECHTEN Agent-Code des geprüften Commits
#        (agent/api/v1/zones/*.php) über php-cgi aus – mit einer Sandbox-Konfiguration (Zonen-, Conf-Verzeichnis,
#        zones.conf, named.conf und rndc-Platzhalter ausschließlich unter dem Sandbox-Verzeichnis). /etc/bind und die
#        installierten Agenten werden nicht berührt. Zusätzlich lassen sich je Endpunkt Fehlerbilder einspielen
#        (Steuerdatei, JSON: {"<ip>": {"zone_sync.php": "<verhalten>", …}}).
# Verhalten: php (Standard) | delay:<s> (warten, danach echter Agent) | hang (keine Antwort) | close (Verbindung ohne
#            Antwort schließen) | reject (422, Agent-JSON) | garbage (200 ohne Agent-JSON) | html500 | rollback_failed |
#            partial_cleanup
# Aufruf: journal_agent.py <ip> <port> <cert.pem> <key.pem> <Agent-Verzeichnis> <Steuerdatei> <Logdatei>
# Lauscht nur auf 127.0.0.2 … 127.0.0.254; protokolliert Endpunkt, Zone, Verhalten und HTTP-Methode, keine Inhalte
# oder Token.
import http.server
import json
import os
import ssl
import subprocess
import sys
import time

ip, port, cert, key, agent_dir, control, log = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5], sys.argv[6], sys.argv[7]
if not ip.startswith('127.0.0.') or ip == '127.0.0.1':
    sys.exit('nur 127.0.0.2 bis 127.0.0.254 zulässig')
ENDPOINTS = {'zone_sync.php', 'conf_sync.php', 'zone_cleanup.php', 'zone_check.php', 'conf_check.php'}


def behavior(ep):
    try:
        with open(control) as f:
            return json.load(f).get(ip, {}).get(ep, 'php')
    except (OSError, ValueError):
        return 'php'


class H(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def send(self, code, body, ctype='application/json'):
        b = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(b)))
        self.send_header('Connection', 'close')
        self.end_headers()
        try:
            self.wfile.write(b)
        except OSError:
            pass

    def do_POST(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n) if n else b''
        ep = self.path.split('?')[0].split('/')[-1]
        try:
            zone = json.loads(body or b'{}').get('zone_name', '-')
        except ValueError:
            zone = '?'
        b = behavior(ep)
        with open(log, 'a') as f:
            # AS4b: HTTP-Methode am Zeilenende (Unterscheidung lesender Restore-Abgleich GET system/status.php)
            f.write(f"{time.time():.3f} {ip} {ep} zone={zone} {b} {self.command}\n")
        if ep not in ENDPOINTS:
            return self.send(404, '<html>not found</html>', 'text/html')
        if b == 'hang':
            time.sleep(45)
            return
        if b == 'close':
            self.close_connection = True
            self.wfile.flush()
            self.connection.shutdown(2)
            return
        if b == 'reject':
            return self.send(422, json.dumps({'status': 'error', 'message': 'Test-Ablehnung', 'check_output': 'abgelehnt'}))
        if b == 'garbage':
            return self.send(200, '<html>ok</html>', 'text/html')
        if b == 'html500':
            return self.send(500, '<html>Internal Server Error</html>', 'text/html')
        if b == 'rollback_failed':
            return self.send(500, json.dumps({'status': 'error', 'message': 'Rollback fehlgeschlagen (Test)', 'rollback': 'failed'}))
        if b == 'partial_cleanup':
            return self.send(500, json.dumps({'status': 'error', 'message': 'Zonen-Cleanup unvollständig (Test)', 'errors': ['Test'],
                                              'removed_conf': ['x.conf'], 'removed_zone_files': [], 'rndc': ''}))
        if b.startswith('delay:'):
            time.sleep(float(b.split(':', 1)[1]))
        self.run_php(ep, body)

    def run_php(self, ep, body):
        script = os.path.join(agent_dir, 'api', 'v1', 'zones', ep)
        env = {
            'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'REQUEST_METHOD': 'POST', 'CONTENT_TYPE': 'application/json',
            'CONTENT_LENGTH': str(len(body)), 'SCRIPT_FILENAME': script, 'SCRIPT_NAME': self.path, 'REQUEST_URI': self.path,
            'REDIRECT_STATUS': '200', 'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.1',
            'REMOTE_ADDR': '127.0.0.1', 'SERVER_NAME': ip, 'HTTPS': 'on',
            'HTTP_AUTHORIZATION': self.headers.get('Authorization', ''),
        }
        p = subprocess.run(['php-cgi', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' + log + '.php'],
                           input=body, env=env, capture_output=True, timeout=120)
        head, _, out = p.stdout.partition(b'\r\n\r\n')
        code, ctype = 200, 'application/json'
        for line in head.decode(errors='replace').split('\r\n'):
            k, _, v = line.partition(':')
            if k.lower() == 'status':
                code = int(v.strip().split()[0])
            elif k.lower() == 'content-type':
                ctype = v.strip()
        self.send(code, out, ctype)

    do_GET = do_POST

    def log_message(self, *a):
        pass


ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(cert, key)
srv = http.server.ThreadingHTTPServer((ip, port), H)
srv.daemon_threads = True
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
