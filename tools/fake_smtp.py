#!/usr/bin/env python3
"""Minimaler SMTP-Server nur für Tests (tools/test_mail.php): nimmt eine Nachricht an und schreibt sie in eine Datei.
Aufruf: python3 tools/fake_smtp.py PORT AUSGABEDATEI   (AUTH LOGIN wird akzeptiert, kein TLS)"""
import socket, sys
port, out = int(sys.argv[1]), sys.argv[2]
srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(('127.0.0.1', port)); srv.listen(1)
c, _ = srv.accept(); f = c.makefile('rwb', buffering=0)
def send(s): f.write((s + '\r\n').encode())
log = []
send('220 fake ESMTP')
data = False; buf = []
while True:
    line = f.readline()
    if not line: break
    t = line.decode('utf-8', 'replace').rstrip('\r\n')
    if data:
        if t == '.':
            data = False; log.append('DATA:\n' + '\n'.join(buf)); send('250 queued'); continue
        buf.append(t[1:] if t.startswith('..') else t); continue
    log.append(t)
    u = t.upper()
    if u.startswith('EHLO'): f.write(b'250-fake\r\n250 AUTH LOGIN\r\n')
    elif u == 'AUTH LOGIN': send('334 VXNlcm5hbWU6')
    elif u.startswith(('MAIL', 'RCPT')): send('250 ok')
    elif u == 'DATA': data = True; send('354 go')
    elif u == 'QUIT': send('221 bye'); break
    else:
        if len(log) >= 2 and log[-2].upper() == 'AUTH LOGIN': send('334 UGFzc3dvcmQ6')
        elif len(log) >= 3 and log[-3].upper() == 'AUTH LOGIN': send('235 ok')
        else: send('250 ok')
open(out, 'w').write('\n'.join(log))
