#!/usr/bin/env python3
"""Disposable CI harness: restore the actual Core schema and Redis 7 AOF.

Runs only with fixed GitHub-hosted runner identity, fixed DB names/ports,
private /tmp fixture and anonymous values. It is NOT an operator backup tool.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time

SOURCE_DB = "rconfig_ci_core_restore_source"
TARGET_DB = "rconfig_ci_core_restore_target"
SOURCE_REDIS = "rconfig-ci-core-aof-source"
TARGET_REDIS = "rconfig-ci-core-aof-target"
ORIGINAL = "snapshot-A"
CHANGED = "snapshot-B"


class Refused(Exception):
    pass


def ensure(condition, why):
    if not condition:
        raise Refused(why)


def run(argv, *, output=None, input_file=None):
    completed = subprocess.run(
        argv, stdout=output if output is not None else subprocess.PIPE,
        stdin=input_file, stderr=subprocess.PIPE, check=False, timeout=120
    )
    ensure(completed.returncode == 0, "isolated command failed")
    return completed.stdout if output is None else b""


def maria(port, db, sql):
    return run(["mariadb", "--protocol=tcp", "-h", "127.0.0.1",
                "-P", str(port), "-u", "root", "-D", db,
                "--batch", "--skip-column-names", "-e", sql]).decode().strip()


def redis(port, index, *args):
    return run(["redis-cli", "--raw", "-h", "127.0.0.1",
                "-p", str(port), "-n", str(index), *args]).decode().strip()


def info(path):
    stat = path.lstat()
    return {"mode": stat.st_mode & 0o777, "uid": stat.st_uid, "gid": stat.st_gid}


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def copytree_exact(original, target):
    ensure(original.is_dir() and not original.is_symlink() and not target.exists(),
           "private source/target path")
    shutil.copytree(original, target, copy_function=shutil.copy2)
    # shutil.copytree does not preserve owners; the hosted root helper must.
    for here, dirs, files in os.walk(original):
        rel = Path(here).relative_to(original)
        pairs = [(Path(here), target / rel)]
        pairs.extend((Path(here) / n, target / rel / n) for n in dirs + files)
        for source, dest in pairs:
            ensure(not source.is_symlink(), "symlinks forbidden in protected snapshot")
            st = source.stat()
            os.chown(dest, st.st_uid, st.st_gid)
            os.chmod(dest, st.st_mode & 0o777)


def key_from_env(path):
    # Core's .env.example (copied into .env.testing) can contain UTF-8
    # comments; only the matched APP_KEY line must be strict ASCII/base64.
    text = path.read_text(encoding="utf-8")
    values = [line.split("=", 1)[1] for line in text.splitlines()
              if line.startswith("APP_KEY=")]
    ensure(len(values) == 1
           and re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=", values[0]) is not None,
           "stable APP_KEY missing")
    return values[0]


def private_env_valid(path, key_hash):
    ensure(path.is_file() and not path.is_symlink()
           and info(path) == {"mode": 0o640, "uid": 0, "gid": 33},
           "protected root:33 mode0640 .env")
    key = key_from_env(path)
    ensure(hashlib.sha256(key.encode()).hexdigest() == key_hash,
           "APP_KEY changed across recovery")
    content = path.read_text(encoding="ascii")
    ensure(content.count("MAIL_MAILER=log\n") == 1
           and content.count("RCONFIG_F02_QUEUE_ENABLED=false\n") == 1,
           "unsafe synthetic mail/F02 config")


def sql_state(port, db):
    return maria(port, db,
        "SELECT name FROM users WHERE id=1")


def source_installed(root):
    storage = root / "source-storage"
    attempt = storage / ".staging-bootstrap-attempt"
    complete = storage / ".staging-bootstrap-complete"
    ensure(attempt.is_dir() and not attempt.is_symlink()
           and info(attempt)["mode"] == 0o700,
           "one-shot attempt record missing")
    ensure(complete.is_file() and not complete.is_symlink()
           and complete.read_bytes() == b"staging-bootstrap-v1"
           and info(complete)["mode"] == 0o640,
           "one-shot completion marker missing")


def state_source(root, generation):
    ensure(sql_state(3306, SOURCE_DB) ==
           ("Staging administrator" if generation == ORIGINAL else "Synthetic changed state"),
           "source Core seeded admin generation")
    ensure(redis(6387, 0, "GET", "ci:core-state") == generation
           and redis(6387, 1, "GET", "ci:core-cache") == generation,
           "source Redis AOF DB0/DB1 generation")
    expected = b"synthetic-Core-generation-A\n" if generation == ORIGINAL else b"synthetic-Core-generation-B\n"
    cfg = root / "source-storage/app/rconfig/data/fixture.cfg"
    ensure(cfg.is_file() and not cfg.is_symlink() and cfg.read_bytes() == expected
           and info(cfg)["mode"] == 0o440, "source protected device fixture")
    source_installed(root)


def snapshot_ok(root, snap=None):
    snap = snap or (root / "snapshot")
    manifest = snap / "manifest.json"
    trust = root / "trusted-manifest.sha256"
    ensure(snap.is_dir() and not snap.is_symlink()
           and manifest.is_file() and not manifest.is_symlink()
           and trust.is_file() and info(trust)["mode"] == 0o600
           and info(manifest)["mode"] == 0o600,
           "private snapshot/manifest/trust anchor")
    ensure(sha(manifest) == trust.read_text().strip(),
           "snapshot manifest integrity")
    try:
        data = json.loads(manifest.read_bytes())
    except ValueError as e:
        raise Refused("manifest decoding") from e
    ensure(data.get("generation") == ORIGINAL
           and data.get("kind") == "core-mariadb10.11-redis7-aof"
           and isinstance(data.get("files"), dict)
           and data["files"], "manifest identity")
    found = set()
    for file in snap.rglob("*"):
        ensure(not file.is_symlink(), "symlink in protected snapshot")
        if file.is_file() and file != manifest:
            found.add(str(file.relative_to(snap)))
    ensure(found == set(data["files"]), "missing or unexpected snapshot component")
    for relative, record in data["files"].items():
        file = snap / relative
        ensure(file.is_file() and info(file) == record["meta"]
               and sha(file) == record["sha256"], "modified snapshot component")
    ensure(any(n.startswith("redis-data/appendonlydir/")
               and n.endswith(".aof.manifest") for n in found),
           "Redis multipart AOF manifest missing")
    ensure("core.sql" in found and "private/.env" in found
           and "storage/.staging-bootstrap-complete" in found
           and "storage/.staging-bootstrap-attempt/completion-marker.ready" in found,
           "Core SQL, env, or completion marker missing")
    private_env_valid(snap / "private/.env",
                      (root / "key.sha256").read_text().strip())


def expect_refusal(fn, reason):
    try:
        fn()
    except Refused:
        return
    raise Refused("negative case unexpectedly accepted: " + reason)


def negative_checks(root):
    snapshot = root / "snapshot"
    names = ["core.sql", "private/.env", "storage/app/rconfig/data/fixture.cfg"]
    aof = sorted(snapshot.glob("redis-data/appendonlydir/*.aof*"))
    ensure(aof, "AOF files not present")
    names.append(str(aof[0].relative_to(snapshot)))
    for n, file in enumerate(names):
        candidate = root / ("damaged-fixture-" + str(n))
        copytree_exact(snapshot, candidate)
        changed = candidate / file
        old_mode = info(changed)["mode"]
        os.chmod(changed, 0o600)
        with changed.open("ab") as out:
            out.write(b"changed")
        os.chmod(changed, old_mode)
        expect_refusal(lambda c=candidate: snapshot_ok(root, c), "tampered " + str(n))
        shutil.rmtree(candidate)
    missing = root / "missing-fixture"
    copytree_exact(snapshot, missing)
    (missing / "private/.env").unlink()
    expect_refusal(lambda: snapshot_ok(root, missing), "missing app key/config")
    shutil.rmtree(missing)
    wrong = root / "wrong-manifest"
    copytree_exact(snapshot, wrong)
    with (wrong / "manifest.json").open("ab") as out:
        out.write(b"changed")
    expect_refusal(lambda: snapshot_ok(root, wrong), "modified manifest")
    shutil.rmtree(wrong)


def create_private_env(root, runner_uid):
    path = root / "source-private"
    ensure(not path.exists(), "private source .env must not preexist")
    path.mkdir(mode=0o700)
    os.chown(path, 0, 0)
    php_env = Path(os.environ["APP_TEST_ENV"])
    ensure(php_env.is_file() and not php_env.is_symlink()
           and php_env.name == ".env.testing", "runner test-only app .env")
    key = key_from_env(php_env)
    content = (
        "APP_ENV=testing\nAPP_DEBUG=false\nAPP_KEY=" + key + "\n"
        "MAIL_MAILER=log\nRCONFIG_F02_QUEUE_ENABLED=false\n"
        "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n"
        "DB_DATABASE=" + SOURCE_DB + "\nDB_PORT=3306\n"
        "REDIS_HOST=127.0.0.1\nREDIS_PORT=6387\n"
        "REDIS_DB=0\nREDIS_CACHE_DB=1\n"
    )
    private = path / ".env"
    private.write_text(content)
    os.chown(private, 0, 33)
    private.chmod(0o640)
    (root / "key.sha256").write_text(hashlib.sha256(key.encode()).hexdigest())
    (root / "key.sha256").chmod(0o600)
    private_env_valid(private, (root / "key.sha256").read_text().strip())


def wait_redis(port):
    for _ in range(50):
        p = subprocess.run(["redis-cli", "--raw", "-h", "127.0.0.1",
                            "-p", str(port), "PING"],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if p.returncode == 0 and p.stdout.strip() == b"PONG":
            return
        time.sleep(0.2)
    raise Refused("isolated Redis not ready")


def prepare(root):
    source_installed(root)
    ensure(sql_state(3306, SOURCE_DB) == "Staging administrator",
           "Core real install not sealed")
    ensure(redis(6387, 0, "DBSIZE") == "0"
           and redis(6387, 1, "DBSIZE") == "0", "nonempty Redis before fixtures")

    runner_uid = int(os.environ["RUNNER_UID"])
    runner_gid = int(os.environ["RUNNER_GID"])
    create_private_env(root, runner_uid)
    folder = root / "source-storage/app/rconfig/data"
    folder.mkdir(parents=True, exist_ok=False, mode=0o750)
    for item in [root / "source-storage/app",
                 root / "source-storage/app/rconfig", folder]:
        item.chmod(0o750)
        os.chown(item, runner_uid, runner_gid)
    data = folder / "fixture.cfg"
    data.write_bytes(b"synthetic-Core-generation-A\n")
    os.chown(data, runner_uid, 33)
    data.chmod(0o440)

    redis(6387, 0, "SET", "ci:core-state", ORIGINAL)
    redis(6387, 1, "SET", "ci:core-cache", ORIGINAL)
    state_source(root, ORIGINAL)
    print("CI: real Core MariaDB10.11 install and AOF DB0/DB1 fixtures ready")


def backup(root):
    ensure(not (root / "snapshot").exists(), "no clobber snapshot")
    source_installed(root)
    state_source(root, ORIGINAL)
    # Source-writer admission: completed one-shot attempt is allowed, an
    # incomplete attempt without its marker is not.
    orphan = root / "negative-incomplete"
    orphan.mkdir(mode=0o700)
    (orphan / ".staging-bootstrap-attempt").mkdir(mode=0o700)
    expect_refusal(
        lambda: ensure((orphan / ".staging-bootstrap-complete").is_file(),
                       "refuse unfinished bootstrap"), "incomplete bootstrap"
    )
    shutil.rmtree(orphan)

    # Core migration already completed and no Apache/Horizon/scheduler is
    # running. Freeze Redis by stopping it before copying its AOF files.
    run(["docker", "stop", SOURCE_REDIS])
    snap = root / "snapshot"
    snap.mkdir(mode=0o700)
    (snap / "private").mkdir(mode=0o700)
    sqlfile = snap / "core.sql"
    with sqlfile.open("xb") as out:
        run(["mariadb-dump", "--protocol=tcp", "-h", "127.0.0.1",
             "-P", "3306", "-u", "root", "--single-transaction", "--quick",
             "--skip-lock-tables", "--no-tablespaces", "--hex-blob", SOURCE_DB],
            output=out)
    sqlfile.chmod(0o600)
    copytree_exact(root / "redis-source-data", snap / "redis-data")
    copytree_exact(root / "source-storage", snap / "storage")
    cfg = snap / "private/.env"
    shutil.copy2(root / "source-private/.env", cfg)
    os.chown(cfg, 0, 33)
    cfg.chmod(0o640)

    entries = {}
    for file in snap.rglob("*"):
        ensure(not file.is_symlink(), "symlink in snapshot source")
        if file.is_file():
            entries[str(file.relative_to(snap))] = {"sha256": sha(file), "meta": info(file)}
    manifest = snap / "manifest.json"
    manifest.write_text(json.dumps({
        "generation": ORIGINAL, "kind": "core-mariadb10.11-redis7-aof",
        "files": entries
    }, sort_keys=True) + "\n")
    manifest.chmod(0o600)
    trust = root / "trusted-manifest.sha256"
    trust.write_text(sha(manifest))
    trust.chmod(0o600)
    snapshot_ok(root)
    negative_checks(root)
    run(["docker", "start", SOURCE_REDIS])
    wait_redis(6387)
    state_source(root, ORIGINAL)
    # Only a nonsecret fixed signal is visible to the unprivileged writer;
    # the root-owned 0700 snapshot itself must remain inaccessible.
    ready = root / "snapshot.ready"
    ensure(not ready.exists(), "single-use snapshot signal")
    ready.write_text("verified-snapshot\n")
    os.chown(ready, int(os.environ["RUNNER_UID"]), int(os.environ["RUNNER_GID"]))
    ready.chmod(0o600)
    print("CI: quiesced full Core SQL + stopped Redis AOF + protected storage/.env snapshot verified")


def damage(root):
    ensure((root / "snapshot.ready").read_text() == "verified-snapshot\n"
           and not (root / "writer.ready").exists(), "single-use fault point")
    state_source(root, ORIGINAL)
    maria(3306, SOURCE_DB,
          "UPDATE users SET name='Synthetic changed state' WHERE id=1")
    redis(6387, 0, "SET", "ci:core-state", CHANGED)
    redis(6387, 1, "SET", "ci:core-cache", CHANGED)
    data = root / "source-storage/app/rconfig/data/fixture.cfg"
    data.chmod(0o600)
    data.write_bytes(b"synthetic-Core-generation-B\n")
    data.chmod(0o440)
    state_source(root, CHANGED)
    (root / "writer.ready").write_text("changed\n")
    # Supervising workflow SIGKILLs this *direct Python* child only.
    time.sleep(120)
    raise Refused("expected SIGKILL was not delivered")


def recovered_ok(root):
    ensure(sql_state(3307, TARGET_DB) == "Staging administrator",
           "Core admin not recovered")
    ensure(maria(3307, TARGET_DB,
                 "SELECT mail_driver FROM settings WHERE id=1") == "log",
           "Core DB mail settings not recovered")
    ensure(redis(6388, 0, "GET", "ci:core-state") == ORIGINAL
           and redis(6388, 1, "GET", "ci:core-cache") == ORIGINAL
           and redis(6388, 0, "DBSIZE") == "1"
           and redis(6388, 1, "DBSIZE") == "1",
           "Redis AOF DB0 and DB1 not recovered")
    storage = root / "recovered-storage"
    ensure(storage.is_dir()
           and info(storage)["mode"] == 0o750, "recovered storage directory")
    marker = storage / ".staging-bootstrap-complete"
    attempt = storage / ".staging-bootstrap-attempt"
    ensure(marker.is_file() and marker.read_bytes() == b"staging-bootstrap-v1"
           and info(marker)["mode"] == 0o640
           and attempt.is_dir() and info(attempt)["mode"] == 0o700,
           "Core protected completion records not recovered")
    data = storage / "app/rconfig/data/fixture.cfg"
    ensure(data.is_file() and data.read_bytes() == b"synthetic-Core-generation-A\n"
           and info(data) == {"mode": 0o440,
                              "uid": int(os.environ["RUNNER_UID"]), "gid": 33},
           "private Core config fixture not recovered")
    env = root / "recovered-private/.env"
    private_env_valid(env, (root / "key.sha256").read_text().strip())
    ensure(env.read_bytes() == (root / "snapshot/private/.env").read_bytes(),
           "protected .env bytes changed during restore")
    state_source(root, CHANGED)


def recover(root):
    ensure((root / "writer.ready").read_text() == "changed\n",
           "writer did not reach SIGKILL checkpoint")
    state_source(root, CHANGED)
    snapshot_ok(root)
    ensure(maria(3307, TARGET_DB,
                 "SELECT COUNT(*) FROM information_schema.tables "
                 "WHERE table_schema=DATABASE()") == "0", "recovery SQL not empty")
    ensure(not (root / "recovered-storage").exists()
           and not (root / "recovered-redis-data").exists(),
           "recovery target must be new")

    with (root / "snapshot/core.sql").open("rb") as dump:
        run(["mariadb", "--protocol=tcp", "-h", "127.0.0.1",
             "-P", "3307", "-u", "root", "-D", TARGET_DB], input_file=dump)
    copytree_exact(root / "snapshot/storage", root / "recovered-storage")
    copytree_exact(root / "snapshot/redis-data", root / "recovered-redis-data")

    runner_uid = int(os.environ["RUNNER_UID"])
    runner_gid = int(os.environ["RUNNER_GID"])
    # CI Redis uses a nonroot numeric UID with private writable AOF files.
    for node in [root / "recovered-redis-data"] + list(
            (root / "recovered-redis-data").rglob("*")):
        os.chown(node, runner_uid, runner_gid)
    protected = root / "recovered-private"
    protected.mkdir(mode=0o700)
    env = protected / ".env"
    shutil.copy2(root / "snapshot/private/.env", env)
    os.chown(env, 0, 33)
    env.chmod(0o640)

    run(["docker", "run", "--detach", "--name", TARGET_REDIS,
         "--user", str(runner_uid) + ":" + str(runner_gid),
         "--publish", "127.0.0.1:6388:6379",
         "--mount", "type=bind,source=" + str(root / "recovered-redis-data") + ",target=/data",
         "redis:7-alpine", "redis-server", "--dir", "/data", "--appendonly", "yes",
         "--appendfsync", "always", "--save", ""])
    wait_redis(6388)
    recovered_ok(root)

    # Change a syntactically valid APP_KEY on the recovered private env and
    # prove the independent gate refuses it, without printing the key.
    original = env.read_bytes()
    expected_key = key_from_env(env)
    replacement_key = "base64:" + base64.b64encode(bytes(32)).decode()
    ensure(replacement_key != expected_key, "changed key not distinct")
    env.write_bytes(original.replace(expected_key.encode(), replacement_key.encode()))
    try:
        expect_refusal(lambda: recovered_ok(root), "changed APP_KEY after recovery")
    finally:
        env.write_bytes(original)
        os.chown(env, 0, 33)
        env.chmod(0o640)
    recovered_ok(root)
    print("CI: migrated Core SQL, sealed admin, Redis AOF DB0/DB1, protected storage/.env restored")


def verify(root):
    snapshot_ok(root)
    recovered_ok(root)
    print("CI: independent final Core ledger/marker, AOF and private APP_KEY recovery verified")


def main():
    ensure(len(sys.argv) == 2
           and sys.argv[1] in ("prepare", "backup", "damage", "recover", "verify"),
           "expected one CI-only phase")
    ensure(os.environ.get("GITHUB_ACTIONS") == "true"
           and os.environ.get("RUNNER_OS") == "Linux"
           and os.environ.get("CI") == "true"
           and os.environ.get("STAGING_CORE_RESTORE_CI") == "disposable-v1",
           "not a GitHub-hosted synthetic CI run")
    root_name = os.environ.get("STAGING_CORE_RESTORE_ROOT", "")
    ensure(re.fullmatch(r"/tmp/rconfig-core-restore-[A-Za-z0-9]{10}",
                        root_name) is not None, "not a dedicated CI temp root")
    root = Path(root_name)
    ensure(root.is_dir() and not root.is_symlink() and root.resolve() == root
           and info(root)["mode"] == 0o700,
           "private temp root absent/insecure")
    ensure(os.environ.get("RUNNER_UID", "").isdigit()
           and os.environ.get("RUNNER_GID", "").isdigit(),
           "missing numeric runner identity")
    phase = sys.argv[1]
    # Only the fault-injection child operates as the unprivileged runner.
    ensure(os.geteuid() == (int(os.environ["RUNNER_UID"]) if phase == "damage" else 0),
           "unexpected phase user")
    {
        "prepare": prepare, "backup": backup,
        "damage": damage, "recover": recover, "verify": verify
    }[phase](root)


if __name__ == "__main__":
    try:
        main()
    except Refused as exc:
        # These reasons originate only from constant strings in this CI
        # harness. Never include command stderr, SQL, APP_KEY or file bytes.
        print("Disposable full Core/AOF recovery refused: " + str(exc),
              file=sys.stderr)
        sys.exit(1)
    except (OSError, KeyError, UnicodeError, ValueError,
            subprocess.TimeoutExpired) as exc:
        print("Disposable full Core/AOF recovery refused (" +
              type(exc).__name__ + "; no private data displayed)",
              file=sys.stderr)
        sys.exit(1)
