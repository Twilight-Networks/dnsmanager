#!/usr/bin/env python3
# Datei: tests/ui/agent_stub.py
# Zweck: Agent-Platzhalter der UI-Golden-Basis (U0). Beantwortet jede Agent-Anfrage mit HTTP 200 und JSON-Erfolg,
#        damit Publish-, Reload- und DynDNS-Fälle ohne echten Agenten deterministisch laufen. Lauscht nur auf der
#        angegebenen Loopback-Adresse (127.0.0.2/127.0.0.3), protokolliert Endpunkt und Zonennamen, keine Inhalte/Token.
# Aufruf: agent_stub.py <ip> <port> <cert.pem> <key.pem> <logdatei>
import http.server
import json
import ssl
import sys

ip, port, cert, key, log = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5]
if not ip.startswith('127.0.0.') or ip == '127.0.0.1':
    sys.exit('nur 127.0.0.2 bis 127.0.0.254 zulässig')


class H(http.server.BaseHTTPRequestHandler):
    def _answer(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n) if n else b''
        ep = self.path.split('?')[0].split('/')[-1]
        try:
            zone = json.loads(body or b'{}').get('zone_name', '-')
        except Exception:
            zone = '?'
        with open(log, 'a') as f:
            f.write(f"{ip} {self.command} {ep} zone={zone}\n")
        # Antwortformat wie der echte Agent: system/control meldet 'ok', die Zonen-Endpunkte 'success'
        resp = {'status': 'ok' if ep == 'control.php' else 'success', 'message': 'ui-golden ok', 'check_output': 'OK'}
        b = json.dumps(resp).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    do_GET = _answer
    do_POST = _answer

    def log_message(self, *a):
        pass


ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(cert, key)
srv = http.server.ThreadingHTTPServer((ip, port), H)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
