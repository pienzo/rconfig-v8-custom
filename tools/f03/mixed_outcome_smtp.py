"""Hosted-only mixed F02 outcome email probe. Local TLS fixture, never a relay."""

import base64
from email import policy
from email.parser import BytesParser
import os
from pathlib import Path
import socket
import ssl
import subprocess
import sys
import tempfile
import threading

from smtp_tls_probe import certs, openssl, require

ROOT = Path(__file__).resolve().parents[2]


def main():
    require(os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("RUNNER_ENVIRONMENT") == "github-hosted"
            and os.environ.get("APP_ENV") == "testing"
            and os.environ.get("F02_SYNTHETIC_MIXED_RECEIPT") == "1"
            and os.environ.get("F02_SYNTHETIC_MULTI_RECEIPT") == "1"
            and os.environ.get("F02_SYNTHETIC_SFTP_RECEIPT") == "1"
            and os.environ.get("DB_HOST") == "127.0.0.1"
            and os.environ.get("DB_DATABASE") == "v8core_test"
            and os.environ.get("REDIS_HOST") == "127.0.0.1"
            and os.environ.get("REDIS_DB") == "7"
            and os.environ.get("REDIS_CACHE_DB") == "8"
            and len(sys.argv) == 3
            and all(s.isascii() and s.isdecimal() and int(s) > 0 for s in sys.argv[1:]),
            "mixed outcome fixture gate")
    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix="rconfig-mixed-smtp-") as temp:
        folder = Path(temp)
        openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes",
                "-subj", "/CN=synthetic-mixed-ca", "-days", "1",
                "-keyout", folder / "ca.key", "-out", folder / "ca.crt")
        cert, key = certs(folder, "valid")
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.minimum_version = ssl.TLSVersion.TLSv1_2
        ctx.load_cert_chain(cert, key)
        observed = []
        errors = []
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            listener.bind(("127.0.0.1", 0))
            listener.listen(3)
            listener.settimeout(30)

            def serve():
                try:
                    for _ in range(2):
                        conn, _ = listener.accept()
                        with conn:
                            conn.settimeout(10)
                            with ctx.wrap_socket(conn, server_side=True) as tls, tls.makefile("rwb") as stream:
                                def reply(line):
                                    stream.write(line + b"\r\n")
                                    stream.flush()

                                phase = 0
                                reply(b"220 synthetic mixed SMTP")
                                while True:
                                    line = stream.readline(65536)
                                    if not line:
                                        break
                                    command = line.rstrip(b"\r\n")
                                    if command.startswith(b"EHLO ") and phase == 0:
                                        reply(b"250-synthetic\r\n250 AUTH PLAIN")
                                    elif command.startswith(b"AUTH PLAIN ") and phase == 0:
                                        require(base64.b64decode(command.split(b" ", 2)[2], validate=True)
                                                == b"synthetic-user\x00synthetic-user\x00synthetic-password", "AUTH")
                                        phase = 1
                                        reply(b"235 authenticated")
                                    elif command == b"MAIL FROM:<sender@example.test>" and phase == 1:
                                        phase = 2
                                        reply(b"250 ok")
                                    elif command == b"RCPT TO:<recipient@example.test>" and phase == 2:
                                        phase = 3
                                        reply(b"250 ok")
                                    elif command == b"DATA" and phase == 3:
                                        reply(b"354 end with dot")
                                        payload = bytearray()
                                        while True:
                                            chunk = stream.readline(65536)
                                            require(bool(chunk), "complete DATA")
                                            if chunk == b".\r\n":
                                                break
                                            payload.extend(chunk)
                                            require(len(payload) < 65536, "bounded DATA")
                                        msg = BytesParser(policy=policy.default).parsebytes(payload)
                                        subject = str(msg["Subject"])
                                        body = msg.get_body(preferencelist=("plain",)).get_content()
                                        matches = [(name, status) for name, status in
                                                   (("F02-CI-A", "SUCCESS"), ("F02-CI-B", "FAILED"))
                                                   if name in subject and status in subject]
                                        require(len(matches) == 1, "specific device and status")
                                        name, status = matches[0]
                                        require("Dispositivo: " + name in body
                                                and "Origine: external-file" in body
                                                and ("File: backup.cfg" if status == "SUCCESS"
                                                     else "Errore: External backup check failed") in body,
                                                "specific metadata body")
                                        observed.append((name, status))
                                        phase = 4
                                        reply(b"250 queued synthetic only")
                                    elif command == b"QUIT" and phase == 4:
                                        reply(b"221 bye")
                                        break
                                    else:
                                        raise RuntimeError("unexpected SMTP command")
                                require(phase == 4, "complete authenticated transaction")
                except Exception as exc:
                    errors.append(type(exc).__name__)

            worker = threading.Thread(target=serve, daemon=True)
            worker.start()
            result = subprocess.run(
                ["php", "-d", f"openssl.cafile={folder / 'ca.crt'}",
                 str(ROOT / "tools/f03/mixed_outcome_email.php"),
                 str(listener.getsockname()[1]), *sys.argv[1:]],
                cwd=ROOT, env=dict(os.environ, F03_SYNTHETIC_MIXED_EMAIL="1"),
                capture_output=True, text=True, timeout=60,
            )
            worker.join(timeout=12)
            require(result.returncode == 0, "mixed PHP probe exit: "
                    + " ".join(s for s in result.stderr.splitlines() if s.startswith("F03 mixed email probe failed:"))[:240])
            require(not worker.is_alive() and not errors, "SMTP fixture completion")
            require(sorted(observed) == [("F02-CI-A", "SUCCESS"), ("F02-CI-B", "FAILED")],
                    "exact two authenticated messages")
            listener.setblocking(False)
            try:
                extra, _ = listener.accept()
            except BlockingIOError:
                pass
            else:
                extra.close()
                raise RuntimeError("unexpected extra SMTP connection")
            for marker in ("F03_MIXED_SCHEDULE_QUEUE_OK (SYNTHETIC_ONLY)",
                           "F03_MIXED_EMAIL_LEDGER_REPLAY_OK (SYNTHETIC_ONLY)"):
                require(marker in result.stdout.splitlines(), "actual child marker")
                print(marker)
    print("F03_MIXED_REAL_TLS_MESSAGES_OK (SYNTHETIC_ONLY)")


if __name__ == "__main__":
    main()
