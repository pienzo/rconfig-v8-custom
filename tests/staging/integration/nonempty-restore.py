#!/usr/bin/env python3
"""Disposable GitHub Actions-only nonempty SQL/Redis/storage/.env restore probe.

This is a CI evidence harness, not an operator backup or recovery utility.
All database names, ports, fixtures and namespace paths are fixed and ephemeral.
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

SOURCE_DB = "rconfig_ci_snapshot_source"
RESTORED_DB = "rconfig_ci_snapshot_restored"
REDIS_RESTORED_CONTAINER = "rconfig-ci-snapshot-redis-restored"
FILES = ("database.sql", "redis/dump.rdb", "config/.env", "storage/device.cfg")
BEFORE = "generation-A"
AFTER = "generation-B"


class Refused(Exception):
    pass


def require(ok, reason):
    if not ok:
        raise Refused(reason)


def command(argv, *, stdin=None, stdout=None, label="test command"):
    result = subprocess.run(argv, stdin=stdin, stdout=stdout or subprocess.PIPE,
                            stderr=subprocess.PIPE, check=False, timeout=100)
    require(result.returncode == 0, label + " failed")
    return result.stdout if stdout is None else b""


def sql(port, db, query):
    return command(["mariadb", "--protocol=tcp", "--host=127.0.0.1",
                    "--port=" + str(port), "--user=root", "--batch",
                    "--skip-column-names", "--database=" + db, "--execute=" + query],
                   label="isolated MariaDB query").decode().strip()


def redis(port, db, *args):
    return command(["redis-cli", "--raw", "-h", "127.0.0.1", "-p", str(port),
                    "-n", str(db), *args], label="isolated Redis command").decode().strip()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def mode(path):
    return path.stat().st_mode & 0o777


def read_key(path):
    content = path.read_text(encoding="ascii")
    lines = [v[8:] for v in content.splitlines() if v.startswith("APP_KEY=")]
    require(len(lines) == 1 and re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=", lines[0]) is not None,
            "APP_KEY syntax")
    return lines[0]


def snapshot_verified(path):
    require(path.is_dir() and not path.is_symlink(), "missing snapshot")
    m = path / "manifest.json"
    require(m.is_file() and not m.is_symlink() and mode(m) == 0o600,
            "missing/private manifest")
    try:
        manifest = json.loads(m.read_bytes())
    except (ValueError, OSError) as exc:
        raise Refused("snapshot manifest invalid") from exc
    require(manifest.get("version") == 1 and manifest.get("generation") == BEFORE
            and set(manifest.get("files", {})) == set(FILES), "manifest content")
    for name in FILES:
        f = path / name
        record = manifest["files"][name]
        require(f.is_file() and not f.is_symlink()
                and f.stat().st_size > 0
                and mode(f) == record.get("mode")
                and digest(f) == record.get("sha256"), "component integrity: " + name)
    require(mode(path / "config/.env") == 0o600
            and mode(path / "storage/device.cfg") == 0o440
            and mode(path / "redis/dump.rdb") == 0o600, "private snapshot file mode")
    require("MAIL_MAILER=log" in (path / "config/.env").read_text(),
            "log mailer missing")
    return True


def expected_refusal(call, reason):
    try:
        call()
    except Refused:
        return
    raise Refused("negative case was accepted: " + reason)


def sql_generation(port, db):
    return sql(port, db, "SELECT generation,proof FROM snapshot_fixture WHERE id=1")


def assert_source_baseline(root):
    require(sql_generation(3306, SOURCE_DB) == BEFORE + "\tfixture-only",
            "source MariaDB sentinel")
    require(redis(6379, 0, "GET", "ci:state") == BEFORE
            and redis(6379, 1, "GET", "ci:cache") == BEFORE, "source Redis sentinels")
    require(redis(6379, 0, "DBSIZE") == "1"
            and redis(6379, 1, "DBSIZE") == "1", "dedicated source Redis DB0/DB1")
    require((root / "source/storage/device.cfg").read_bytes() == b"synthetic-generation-A\n",
            "source storage sentinel")


def assert_source_damaged(root):
    require(sql_generation(3306, SOURCE_DB) == AFTER + "\tfixture-only",
            "source MariaDB postcrash state")
    require(redis(6379, 0, "GET", "ci:state") == AFTER
            and redis(6379, 1, "GET", "ci:cache") == AFTER, "source Redis postcrash state")
    require((root / "source/storage/device.cfg").read_bytes() == b"synthetic-generation-B\n",
            "source storage postcrash state")
    claim = root / "source/storage/.staging-bootstrap-attempt"
    require(claim.is_dir() and not claim.is_symlink() and mode(claim) == 0o700,
            "failed bootstrap attempt retained")
    require(not (root / "source/storage/.staging-bootstrap-complete").exists(),
            "false completion marker")


def restore_assertions(root):
    recover = root / "recovered"
    require(sql_generation(3307, RESTORED_DB) == BEFORE + "\tfixture-only",
            "restored MariaDB nonempty sentinel")
    require(redis(6380, 0, "GET", "ci:state") == BEFORE
            and redis(6380, 1, "GET", "ci:cache") == BEFORE
            and redis(6380, 0, "DBSIZE") == "1"
            and redis(6380, 1, "DBSIZE") == "1", "restored Redis RDB DB0/DB1")
    restored_env = recover / "config/.env"
    require(restored_env.is_file() and not restored_env.is_symlink()
            and mode(restored_env) == 0o600, "restored private .env")
    require(hashlib.sha256(read_key(restored_env).encode()).hexdigest()
            == (root / "key.identity").read_text(), "APP_KEY changed")
    require(restored_env.read_bytes() == (root / "snapshot/config/.env").read_bytes(),
            "restored .env bytes")
    restored_file = recover / "storage/device.cfg"
    require(restored_file.is_file() and not restored_file.is_symlink()
            and mode(restored_file) == 0o440
            and restored_file.read_bytes() == b"synthetic-generation-A\n",
            "restored storage content/mode")
    require(mode(recover / "storage") == 0o750, "restored storage directory mode")
    require(not (recover / "storage/.staging-bootstrap-attempt").exists()
            and not (recover / "storage/.staging-bootstrap-complete").exists(),
            "stale bootstrap markers restored")
    assert_source_damaged(root)


def main(phase, root):
    source = root / "source"
    snapshot = root / "snapshot"
    attempt = source / "storage/.staging-bootstrap-attempt"

    if phase == "prepare":
        require(not source.exists() and not snapshot.exists()
                and not (root / "recovered").exists(), "fresh isolated fixture required")
        require(sql(3306, SOURCE_DB, "SELECT COUNT(*) FROM information_schema.tables "
                    "WHERE table_schema=DATABASE()") == "0", "nonempty source SQL")
        require(sql(3307, RESTORED_DB, "SELECT COUNT(*) FROM information_schema.tables "
                    "WHERE table_schema=DATABASE()") == "0", "nonempty restore SQL")
        require(redis(6379, 0, "DBSIZE") == "0"
                and redis(6379, 1, "DBSIZE") == "0", "nonempty source Redis")

        (source / "storage").mkdir(parents=True, mode=0o750)
        source.chmod(0o700)
        (source / "config").mkdir(mode=0o700)
        (source / "storage/device.cfg").write_bytes(b"synthetic-generation-A\n")
        (source / "storage/device.cfg").chmod(0o440)
        key = "base64:" + base64.b64encode(os.urandom(32)).decode()
        env = source / "config/.env"
        env.write_text("APP_ENV=testing\nAPP_DEBUG=false\nAPP_KEY=" + key
                       + "\nMAIL_MAILER=log\nRCONFIG_F02_QUEUE_ENABLED=false\n"
                       + "DB_DATABASE=" + SOURCE_DB + "\nREDIS_DB=0\nREDIS_CACHE_DB=1\n")
        env.chmod(0o600)
        (root / "key.identity").write_text(hashlib.sha256(key.encode()).hexdigest())
        (root / "key.identity").chmod(0o600)

        # An in-progress attempt MUST deny snapshot admission. This negative
        # check touches only the temporary CI fixture and immediately resets it.
        attempt.mkdir(mode=0o700)
        expected_refusal(lambda: require(not attempt.exists(), "bootstrap in progress"),
                         "attempt marker")
        attempt.rmdir()
        require(not attempt.exists(), "fixture admission reset")

        sql(3306, SOURCE_DB, "CREATE TABLE snapshot_fixture ("
            "id INT PRIMARY KEY, generation VARCHAR(32) NOT NULL,"
            "proof VARCHAR(64) NOT NULL) ENGINE=InnoDB")
        sql(3306, SOURCE_DB, "INSERT INTO snapshot_fixture "
            "VALUES (1,'generation-A','fixture-only')")
        redis(6379, 0, "SET", "ci:state", BEFORE)
        redis(6379, 1, "SET", "ci:cache", BEFORE)
        assert_source_baseline(root)

        # Writers are quiesced: a single CI job has sole access to the
        # synthetic sources; no app/Horizon/scheduler exists in this workflow.
        # Never claim this serial dump+RDB+file copy is atomic with live writers.
        (snapshot / "redis").mkdir(parents=True, mode=0o700)
        snapshot.chmod(0o700)
        (snapshot / "config").mkdir(mode=0o700)
        (snapshot / "storage").mkdir(mode=0o750)
        sql_dump = snapshot / "database.sql"
        with sql_dump.open("xb") as output:
            command(["mariadb-dump", "--protocol=tcp", "--host=127.0.0.1",
                     "--port=3306", "--user=root", "--single-transaction",
                     "--quick", "--skip-lock-tables", "--no-tablespaces",
                     "--hex-blob", "--default-character-set=utf8mb4", SOURCE_DB],
                    stdout=output, label="quiesced MariaDB SQL dump")
        sql_dump.chmod(0o600)
        command(["redis-cli", "-h", "127.0.0.1", "-p", "6379",
                 "--rdb", str(snapshot / "redis/dump.rdb")],
                label="quiesced Redis RDB snapshot")
        (snapshot / "redis/dump.rdb").chmod(0o600)
        shutil.copy2(env, snapshot / "config/.env")
        shutil.copy2(source / "storage/device.cfg", snapshot / "storage/device.cfg")
        manifest = {"version": 1, "generation": BEFORE,
                    "files": {name: {"mode": mode(snapshot / name),
                                     "sha256": digest(snapshot / name)}
                              for name in FILES}}
        m = snapshot / "manifest.json"
        m.write_text(json.dumps(manifest, sort_keys=True) + "\n")
        m.chmod(0o600)
        snapshot_verified(snapshot)

        # Every corrupted/missing component must be rejected BEFORE any
        # restore resources are created. Copies remain in the throwaway root.
        for name in FILES:
            candidate = root / ("tampered-" + str(FILES.index(name)))
            shutil.copytree(snapshot, candidate, copy_function=shutil.copy2)
            with (candidate / name).open("ab") as file:
                file.write(b"tamper")
            expected_refusal(lambda c=candidate: snapshot_verified(c),
                             "modified " + name)
            shutil.rmtree(candidate)
        missing = root / "missing-component"
        shutil.copytree(snapshot, missing, copy_function=shutil.copy2)
        (missing / "redis/dump.rdb").unlink()
        expected_refusal(lambda: snapshot_verified(missing), "missing Redis")
        shutil.rmtree(missing)
        require(not (root / "recovered").exists(), "negative tests wrote recovery")
        print("CI snapshot: nonempty MariaDB, Redis DB0/DB1 and protected files captured; corruption refused")
        return

    snapshot_verified(snapshot)
    if phase == "damage":
        require(not attempt.exists() and not (root / "crash.ready").exists(),
                "single-use crash scenario")
        attempt.mkdir(mode=0o700)
        sql(3306, SOURCE_DB, "UPDATE snapshot_fixture SET generation='generation-B' WHERE id=1")
        redis(6379, 0, "SET", "ci:state", AFTER)
        redis(6379, 1, "SET", "ci:cache", AFTER)
        file = source / "storage/device.cfg"
        file.chmod(0o600)
        file.write_bytes(b"synthetic-generation-B\n")
        file.chmod(0o440)
        assert_source_damaged(root)
        signal = root / "crash.ready"
        signal.write_text("modified\n")
        signal.chmod(0o600)
        # The CI supervisor sends SIGKILL to ONLY this Python child.
        time.sleep(120)
        raise Refused("SIGKILL was not delivered")

    require((root / "crash.ready").read_text() == "modified\n", "crash signal absent")
    assert_source_damaged(root)
    if phase == "restore":
        recovered = root / "recovered"
        require(not recovered.exists(), "restore target already exists")
        require(sql(3307, RESTORED_DB, "SELECT COUNT(*) FROM information_schema.tables "
                    "WHERE table_schema=DATABASE()") == "0", "restore DB not empty")
        snapshot_verified(snapshot)
        with (snapshot / "database.sql").open("rb") as dump:
            command(["mariadb", "--protocol=tcp", "--host=127.0.0.1",
                     "--port=3307", "--user=root", "--database=" + RESTORED_DB],
                    stdin=dump, label="isolated MariaDB restore")
        (recovered / "config").mkdir(parents=True, mode=0o700)
        (recovered / "storage").mkdir(mode=0o750)
        shutil.copy2(snapshot / "config/.env", recovered / "config/.env")
        shutil.copy2(snapshot / "storage/device.cfg", recovered / "storage/device.cfg")

        # A separate Redis 7 process loads the captured RDB; the source
        # remains on port 6379. Use the runner UID to read private mode0600 RDB.
        command(["docker", "run", "--detach", "--rm", "--name",
                 REDIS_RESTORED_CONTAINER, "--user", str(os.getuid()) + ":" + str(os.getgid()),
                 "--publish", "127.0.0.1:6380:6379",
                 "--mount", "type=bind,source=" + str(snapshot / "redis")
                 + ",target=/data,readonly", "redis:7-alpine",
                 "redis-server", "--save", "", "--appendonly", "no",
                 "--dir", "/data", "--dbfilename", "dump.rdb"],
                label="isolated Redis RDB restore start")
        for _ in range(40):
            check = subprocess.run(["redis-cli", "-h", "127.0.0.1",
                                    "-p", "6380", "PING"],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if check.returncode == 0 and check.stdout.strip() == b"PONG":
                break
            time.sleep(0.25)
        else:
            raise Refused("restored Redis not ready")
        restore_assertions(root)

        # Independently detect a changed APP_KEY even if the SQL and Redis
        # snapshots are otherwise intact. No real credentials are involved.
        restored_env = recovered / "config/.env"
        original = restored_env.read_bytes()
        restored_env.write_bytes(original.replace(
            read_key(restored_env).encode(),
            ("base64:" + base64.b64encode(bytes(32)).decode()).encode()))
        expected_refusal(lambda: restore_assertions(root), "changed APP_KEY")
        shutil.copy2(snapshot / "config/.env", restored_env)
        restore_assertions(root)
        print("CI restore: independent MariaDB/Redis RDB/storage/.env generation-A restored; source remains generation-B")
        return

    require(phase == "verify", "unknown phase")
    restore_assertions(root)
    print("CI postflight: nonempty recovered state, modes and APP_KEY verified independently; original failed claim retained")


if __name__ == "__main__":
    try:
        require(len(sys.argv) == 2 and sys.argv[1] in
                ("prepare", "damage", "restore", "verify"), "expected CI-only phase")
        require(os.environ.get("GITHUB_ACTIONS") == "true"
                and os.environ.get("RUNNER_OS") == "Linux"
                and os.environ.get("STAGING_RESTORE_CI") == "isolated-v1"
                and os.environ.get("CI") == "true", "not a hosted disposable CI run")
        raw = os.environ.get("STAGING_RESTORE_ROOT", "")
        require(re.fullmatch(r"/tmp/rconfig-restore-[A-Za-z0-9]{10}", raw) is not None,
                "outside exact CI temp path")
        root = Path(raw)
        require(root.is_dir() and not root.is_symlink() and root.resolve() == root
                and root.stat().st_uid == os.getuid() and mode(root) == 0o700,
                "not a protected CI-owned temp root")
        main(sys.argv[1], root)
    except (Refused, OSError, ValueError, subprocess.TimeoutExpired):
        print("CI nonempty restore test refused (private state not displayed)",
              file=sys.stderr)
        sys.exit(1)
