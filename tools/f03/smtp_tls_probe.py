"""Local-only synthetic SMTP protocol fixture; no mail relay or delivery."""

import base64
import os
from pathlib import Path
import socket
import ssl
import subprocess
import tempfile
import threading

ROOT = Path(__file__).resolve().parents[2]


def require(condition, stage):
    if not condition:
        raise RuntimeError(f"SMTP TLS probe failed: {stage}")


def openssl(*args):
    subprocess.run(
        ["openssl", *map(str, args)], check=True,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20,
    )


def certs(folder, name):
    key = folder / f"{name}.key"
    csr = folder / f"{name}.csr"
    cert = folder / f"{name}.crt"
    ext = folder / f"{name}.ext"
    san = "IP:127.0.0.1" if name == "valid" else "DNS:wrong.example.test"
    ext.write_text(f"subjectAltName={san}\nextendedKeyUsage=serverAuth\n")
    openssl("req", "-new", "-newkey", "rsa:2048", "-nodes",
            "-subj", "/CN=synthetic-smtp", "-keyout", key, "-out", csr)
    openssl("x509", "-req", "-in", csr, "-CA", folder / "ca.crt",
            "-CAkey", folder / "ca.key", "-CAcreateserial", "-days", "1",
            "-extfile", ext, "-out", cert)
    return cert, key


def scenario(folder, mode, cert, key, trust):
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.bind(("127.0.0.1", 0))
    listener.listen(1)
    listener.settimeout(15)
    port = listener.getsockname()[1]
    observed = {"auth": 0, "mail": 0, "data": 0, "tls": False}
    errors = []

    def serve():
        try:
            with listener:
                conn, _ = listener.accept()
                with conn:
                    conn.settimeout(12)
                    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
                    ctx.load_cert_chain(cert, key)
                    try:
                        tls = ctx.wrap_socket(conn, server_side=True)
                    except ssl.SSLError:
                        if mode in {"untrusted", "wrong-name"}:
                            return
                        raise
                    with tls, tls.makefile("rwb") as stream:
                        observed["tls"] = True

                        def reply(line):
                            stream.write(line + b"\r\n")
                            stream.flush()

                        reply(b"220 synthetic SMTP")
                        while True:
                            line = stream.readline(65536)
                            if not line:
                                break
                            command = line.rstrip(b"\r\n")
                            if command.startswith(b"EHLO "):
                                if mode == "helo":
                                    reply(b"500 EHLO unsupported")
                                elif mode == "no-auth":
                                    reply(b"250 synthetic")
                                else:
                                    reply(b"250-synthetic\r\n250 AUTH PLAIN")
                            elif command.startswith(b"HELO "):
                                reply(b"250 synthetic")
                            elif command.startswith(b"AUTH PLAIN "):
                                observed["auth"] += 1
                                expected = b"synthetic-user\x00synthetic-user\x00synthetic-password"
                                valid = base64.b64decode(command.split(b" ", 2)[2], validate=True) == expected
                                reply(b"235 authenticated" if valid and mode != "bad-auth" else b"535 authentication failed")
                            elif command.startswith(b"MAIL FROM:"):
                                observed["mail"] += 1
                                require(command == b"MAIL FROM:<sender@example.test>", "synthetic sender")
                                reply(b"250 ok")
                            elif command.startswith(b"RCPT TO:"):
                                require(command == b"RCPT TO:<recipient@example.test>", "synthetic recipient")
                                reply(b"250 ok")
                            elif command == b"DATA":
                                reply(b"354 end with dot")
                                payload = bytearray()
                                while True:
                                    chunk = stream.readline(65536)
                                    require(bool(chunk), "complete DATA")
                                    if chunk == b".\r\n":
                                        break
                                    payload.extend(chunk)
                                    require(len(payload) < 65536, "bounded DATA")
                                require(b"Synthetic SMTP test" in payload and b"Synthetic body only" in payload, "synthetic message")
                                observed["data"] += 1
                                reply(b"250 queued as SYNTHETIC")
                            elif command in {b"RSET", b"NOOP"}:
                                reply(b"250 ok")
                            elif command == b"QUIT":
                                reply(b"221 bye")
                                break
                            else:
                                raise RuntimeError("Unexpected SMTP command")
        except Exception as exc:
            errors.append(type(exc).__name__)

    worker = threading.Thread(target=serve, daemon=True)
    worker.start()
    result = subprocess.run(
        ["php", "-d", f"openssl.cafile={trust}", str(ROOT / "tools/f03/smtp_tls_probe.php"), str(port)],
        cwd=ROOT, capture_output=True, text=True, timeout=25,
    )
    worker.join(timeout=16)
    require(not worker.is_alive() and not errors, mode + " fixture completion")
    require(result.returncode == 0, mode + " PHP probe exit")
    expected = "ACCEPTED" if mode == "valid" else "UNCERTAIN"
    require(result.stdout.strip() == expected, mode + " sanitized outcome")
    if mode == "valid":
        require(observed == {"auth": 1, "mail": 1, "data": 1, "tls": True}, "authenticated delivery")
    else:
        require(observed["mail"] == 0 and observed["data"] == 0, mode + " no envelope")
        if mode in {"no-auth", "helo"}:
            require(observed["tls"] and observed["auth"] == 0, mode + " authentication absent")
        elif mode == "bad-auth":
            require(observed["tls"] and observed["auth"] == 1, mode + " authentication rejected")
        else:
            require(observed["auth"] == 0, mode + " no credentials on failed TLS")
    print("F03 SMTP TLS " + mode + " OK")


def main():
    require(os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("APP_ENV") == "testing"
            and os.environ.get("F03_SYNTHETIC_SMTP_TLS") == "1", "hosted-only gate")
    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix="rconfig-smtp-") as temp:
        folder = Path(temp)
        for name in ("ca", "other-ca"):
            openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes",
                    "-subj", "/CN=synthetic-test-" + name, "-days", "1",
                    "-keyout", folder / (name + ".key"), "-out", folder / (name + ".crt"))
        cert, key = certs(folder, "valid")
        wrong_cert, wrong_key = certs(folder, "wrong")
        for mode in ("valid", "no-auth", "helo", "bad-auth"):
            scenario(folder, mode, cert, key, folder / "ca.crt")
        scenario(folder, "untrusted", cert, key, folder / "other-ca.crt")
        scenario(folder, "wrong-name", wrong_cert, wrong_key, folder / "ca.crt")
    print("F03 REAL_SMTP_TLS_AUTH_REQUIRED_OK (SYNTHETIC_ONLY)")


if __name__ == "__main__":
    main()
