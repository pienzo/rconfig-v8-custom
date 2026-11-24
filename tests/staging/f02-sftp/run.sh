#!/bin/sh
# Disposable Docker test only, not an installer or an operational publisher.
set -eu
test "${GITHUB_ACTIONS:-}" = true
test "${F02_SFTP_DISPOSABLE:-}" = 1
test -f /.dockerenv
test "$(id -u)" = 0
test ! -e /sandbox
umask 077
live="${F02_SFTP_LIVE_LOCK:-0}"
multi="${F02_SFTP_MULTI_DEVICE:-0}"
abort="${F02_SFTP_INTERRUPTED:-0}"
manifest="${F02_SFTP_MANIFEST:-0}"
crash="${F02_SFTP_MANIFEST_CRASH:-0}"
case "$crash" in 0|1) ;; *) exit 2 ;; esac
if test "$crash" = 1; then test "$manifest" = 1; fi
case "$manifest" in 0|1) ;; *) exit 2 ;; esac
if test "$manifest" = 1; then test "$live" = 1; test "$multi" = 0; test "$abort" = 0; fi
case "$abort" in 0|1) ;; *) exit 2 ;; esac
if test "$abort" = 1; then test "$live" = 1; test "$multi" = 0; fi
case "$multi" in 0|1) ;; *) exit 2 ;; esac
if test "$multi" = 1; then test "$live" = 1; fi
case "$live" in 0|1) ;; *) exit 2 ;; esac
if test "$live" = 1; then test "${F02_SFTP_EXPORT:-0}" = 0; fi
published=/sandbox/published
device=123
reader_gid=2002
if test "${F02_SFTP_EXPORT:-}" = 1; then
    published=/handoff-export
    device="${F02_SFTP_DEVICE:?}"
    reader_gid="${F02_SFTP_READER_GID:?}"
    case "$device" in ''|*[!0-9]*|0*) exit 2 ;; esac
    case "$reader_gid" in ''|*[!0-9]*|0*) exit 2 ;; esac
    test "$reader_gid" -ge 1000
    test "$reader_gid" -ne 2001
    test -d "$published" && test ! -L "$published"
    test "$(stat -c '%u:%g:%a' "$published")" = "0:$reader_gid:750"
    test -z "$(ls -A "$published")"
fi
export F02_SFTP_PUBLISHED="$published" F02_SFTP_DEVICE="$device" F02_SFTP_READER_GID="$reader_gid"
groupadd -g 2001 f02upload
upload_shell=/usr/sbin/nologin
if test "$live" = 1; then upload_shell=/bin/sh; fi
useradd -M -u 2001 -g 2001 -d /incoming -s "$upload_shell" -p '*' f02upload
groupadd -g "$reader_gid" f02read
useradd -M -u 2002 -g "$reader_gid" -d /nonexistent -s /usr/sbin/nologin f02read
install -d -o root -g root -m 0755 /sandbox /sandbox/quarantine
install -d -o f02upload -g f02upload -m 0700 /sandbox/quarantine/incoming
install -d -o root -g f02read -m 0750 "$published" "$published/$device"
install -d -o root -g root -m 0700 /sandbox/keys
# AuthorizedKeysFile and its ancestors must be readable by sshd during the
# unprivileged key lookup; only the PUBLIC client key goes in this directory.
install -d -o root -g root -m 0755 /sandbox/authorized
install -d -o root -g root -m 0755 /run/sshd
ssh-keygen -q -t ed25519 -N '' -f /sandbox/keys/host
ssh-keygen -q -t ed25519 -N '' -f /sandbox/keys/client
install -o root -g root -m 0644 /sandbox/keys/client.pub /sandbox/authorized/f02upload
printf '[127.0.0.1]:2222 %s\n' "$(cat /sandbox/keys/host.pub)" > /sandbox/keys/known_hosts
cat > /sandbox/sshd.conf <<'CONF'
Port 2222
ListenAddress 127.0.0.1
HostKey /sandbox/keys/host
PidFile /sandbox/sshd.pid
AuthorizedKeysFile /sandbox/authorized/%u
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthenticationMethods publickey
PermitRootLogin no
UsePAM no
AllowUsers f02upload
ChrootDirectory /sandbox/quarantine
ForceCommand internal-sftp -d /incoming
DisableForwarding yes
PermitTTY no
PermitUserRC no
PermitUserEnvironment no
Subsystem sftp internal-sftp
LogLevel ERROR
CONF
if test "$live" = 1; then
    # Only in this isolated test: external SFTP server and minimal runtime
    # inside the root-controlled jail. No interactive shell is exposed.
    jail=/sandbox/quarantine
    for binary in /bin/sh /usr/bin/flock /usr/lib/openssh/sftp-server; do
        install -D -o root -g root -m 0755 "$binary" "$jail$binary"
        deps="$(ldd "$binary")"
        case "$deps" in *'not found'*) exit 1 ;; esac
        printf '%s\n' "$deps" | awk '/=> \// {print $3} /^[[:space:]]*\// {print $1}' |
        while IFS= read -r dep; do
            install -D -o root -g root -m 0755 "$dep" "$jail$dep"
        done
    done
    # External sftp-server resolves its own UID after entering the jail.
    # Provide only synthetic public identity records, never /etc/shadow.
    install -d -o root -g root -m 0755 "$jail/etc"
    getent passwd f02upload > "$jail/etc/passwd"
    getent group f02upload > "$jail/etc/group"
    printf 'passwd: files\ngroup: files\n' > "$jail/etc/nsswitch.conf"
    chmod 0444 "$jail/etc/passwd" "$jail/etc/group" "$jail/etc/nsswitch.conf"
    # OpenSSH sftp-server-main.c calls sanitise_stdfd(), which opens
    # /dev/null O_RDWR even if stdin/stdout/stderr are already open.
    # Only this harmless character device is needed in the disposable jail.
    install -d -o root -g root -m 0755 "$jail/dev"
    mknod -m 0666 "$jail/dev/null" c 1 3
    test "$(stat -c '%t:%T' "$jail/dev/null")" = 1:3
    test -c "$jail/dev/null"
    chroot --userspec=2001:2001 --groups=2001 "$jail" /usr/lib/openssh/sftp-server -Q requests > /sandbox/jailed-requests
    grep -Fxq open /sandbox/jailed-requests
    grep -Fxq close /sandbox/jailed-requests
    printf '%s\n' 'F02_SFTP_JAILED_SUBSYSTEM_PREFLIGHT_OK (SYNTHETIC_ONLY)'
    install -o root -g root -m 0444 /dev/null "$jail/session.lock"
    cat > "$jail/session-lock.sh" <<'WRAPPER'
#!/bin/sh
set -eu
# Keep the descriptor in this parent shell until its SFTP child exits.
# The client cannot replace the lock inode or execute another command.
exec 9</session.lock
/usr/bin/flock -x 9
status=0
/usr/lib/openssh/sftp-server -d /incoming || status=$?
exit "$status"
WRAPPER
    chmod 0555 "$jail/session-lock.sh"
    runuser -u f02upload -- test ! -w "$jail"
    runuser -u f02upload -- test ! -w "$jail/session.lock"
    sed -i 's@^ForceCommand .*@ForceCommand /session-lock.sh@' /sandbox/sshd.conf
fi
if test "$multi" = 1; then
    # Separate synthetic principal, key, jail, numeric device and lock.
    groupadd -g 2003 f02uploadb
    useradd -M -u 2003 -g 2003 -d /incoming -s /bin/sh -p '*' f02uploadb
    cp -a /sandbox/quarantine /sandbox/quarantine-b
    chown 2003:2003 /sandbox/quarantine-b/incoming
    getent passwd f02uploadb > /sandbox/quarantine-b/etc/passwd
    getent group f02uploadb > /sandbox/quarantine-b/etc/group
    chmod 0444 /sandbox/quarantine-b/etc/passwd /sandbox/quarantine-b/etc/group
    test "$(stat -c '%d:%i' /sandbox/quarantine/session.lock)" != "$(stat -c '%d:%i' /sandbox/quarantine-b/session.lock)"
    install -d -o root -g f02read -m 0750 "$published/124"
    ssh-keygen -q -t ed25519 -N '' -f /sandbox/keys/client-b
    install -o root -g root -m 0644 /sandbox/keys/client-b.pub /sandbox/authorized/f02uploadb
    sed -i 's/^AllowUsers f02upload$/AllowUsers f02upload f02uploadb/' /sandbox/sshd.conf
    cat >> /sandbox/sshd.conf <<'MATCH'

Match User f02uploadb
    ChrootDirectory /sandbox/quarantine-b
MATCH
    chroot --userspec=2003:2003 --groups=2003 /sandbox/quarantine-b /usr/lib/openssh/sftp-server -Q requests > /sandbox/jailed-requests-b
    grep -Fxq open /sandbox/jailed-requests-b
    grep -Fxq close /sandbox/jailed-requests-b
fi
/usr/sbin/sshd -t -f /sandbox/sshd.conf
/usr/sbin/sshd -D -e -f /sandbox/sshd.conf >/sandbox/sshd.log 2>&1 &
listener=$!
stage=listener-start
cleanup() {
    status=$?
    if test "$status" -ne 0; then
        printf 'F02 SFTP fixture failed: stage=%s exit=%s\n' "$stage" "$status" >&2
        # All files are synthetic. Only bounded error diagnostics, never keys,
        # environment values, protocol traces or file contents.
        for log in /sandbox/sshd.log /sandbox/ready-output /sandbox/session-output /sandbox/second-output; do
            if test -f "$log"; then
                grep -Ei 'couldn.t open /dev/null|permission denied|no such file|not found|no user found|connection closed|connection reset|connection refused|broken pipe|subsystem request failed|flock:|fatal:|error:' "$log" | tail -n 8 >&2 || true
            fi
        done
    fi
    if test -n "${listener:-}"; then kill "$listener" 2>/dev/null || true; wait "$listener" 2>/dev/null || true; fi
}
trap cleanup EXIT
sftp_batch() {
    sftp -q -b - -P 2222 -i "${2:-/sandbox/keys/client}" \
        -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
        -o UserKnownHostsFile=/sandbox/keys/known_hosts -o GlobalKnownHostsFile=/dev/null \
        -o ConnectTimeout=3 "${1:-f02upload}@127.0.0.1"
}
stage=authenticated-readiness
ready=0
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if printf 'pwd\n' | sftp_batch >/sandbox/ready-output 2>&1; then ready=1; break; fi
    kill -0 "$listener"
    sleep 1
done
test "$ready" = 1
stage=partial-upload
printf '%s\n' 'SYNTHETIC-PARTIAL' > /sandbox/partial
printf '%s\n' 'SYNTHETIC-F02-FINAL-CONTENT' > /sandbox/complete
if test "$manifest" = 1; then
    stage=explicit-completion-manifest
    F02_SFTP_MANIFEST_PROBE=1 python3 /usr/local/bin/f02-handoff.py
    kill -0 "$listener"
    runuser -u f02read -- test -r "$published/$device/backup.cfg"
    runuser -u f02read -- test ! -w "$published/$device/backup.cfg"
    runuser -u f02upload -- test ! -x "$published"
    printf '%s\n' 'F02_SFTP_MANIFEST_LIVE_LISTENER_OK (SYNTHETIC_ONLY)'
    exit 0
fi
if test "$abort" = 1; then
    stage=interrupted-transfer
    F02_SFTP_ABORT_PROBE=1 python3 /usr/local/bin/f02-handoff.py
    kill -0 "$listener"
    # Remove only the known synthetic aborted entry over a new SFTP session.
    printf 'rm /incoming/backup.cfg\n' | sftp_batch
    test ! -e /sandbox/quarantine/incoming/backup.cfg
    test -z "$(ls -A "$published/$device")"
    printf '%s\n' 'F02_SFTP_ABORT_RECONNECT_OK (SYNTHETIC_ONLY)'
fi
stage=partial-upload
printf 'put /sandbox/partial /incoming/upload.part\n' | sftp_batch
test -s /sandbox/quarantine/incoming/upload.part
test -z "$(ls -A "$published/$device")"
stage=first-session-rename
# Even a matching final-looking name in quarantine is not reader-visible.
if test "$live" = 1; then
    # Keep a real SFTP session open after the client has renamed its file.
    # A final-looking name is not enough to permit publication.
    mkfifo /sandbox/session-input
    exec 8<>/sandbox/session-input
    sftp_batch </sandbox/session-input >/sandbox/session-output 2>&1 &
    first=$!
    printf 'put /sandbox/complete /incoming/upload.part\nrename /incoming/upload.part /incoming/backup.cfg\n' >&8
    for attempt in 1 2 3 4 5 6 7 8 9 10; do
        if test -s /sandbox/quarantine/incoming/backup.cfg; then break; fi
        kill -0 "$first"
        sleep 1
    done
    test -s /sandbox/quarantine/incoming/backup.cfg
    kill -0 "$first"
    stage=active-session-lock
    exec 7</sandbox/quarantine/session.lock
    status=0
    flock -n -E 73 7 || status=$?
    test "$status" = 73
    test -z "$(ls -A "$published/$device")"
    printf '%s\n' 'F02_SFTP_ACTIVE_SESSION_PUBLICATION_REFUSED_OK (SYNTHETIC_ONLY)'
    stage=first-session-close
    printf 'bye\n' >&8
    wait "$first"
    exec 8>&-
    rm /sandbox/session-input
else
    printf 'put /sandbox/complete /incoming/upload.part\nrename /incoming/upload.part /incoming/backup.cfg\n' | sftp_batch
fi
test ! -e /sandbox/quarantine/incoming/upload.part
test -s /sandbox/quarantine/incoming/backup.cfg
test -z "$(ls -A "$published/$device")"
if printf 'put /sandbox/complete /outside.cfg\n' | sftp_batch >/dev/null 2>&1; then exit 1; fi
if printf 'ls /published\n' | sftp_batch >/dev/null 2>&1; then exit 1; fi
printf '%s\n' 'F02_REAL_SFTP_QUARANTINE_OK (SYNTHETIC_ONLY)'
if test "$live" = 1; then
    # Successful batch exit is known to the test controller, not inferred
    # merely from a rename. Acquire the same lock before reading any bytes.
    stage=publisher-lock
    flock -w 10 7
    kill -0 "$listener"
else
    # Original quiesced proof: stop this dedicated listener.
    kill "$listener"
    wait "$listener" || true
    listener=
    for attempt in 1 2 3 4 5 6 7 8 9 10; do
        if ! pgrep -u 2001 >/dev/null; then break; fi
        sleep 1
    done
    if pgrep -u 2001 >/dev/null; then exit 1; fi
fi
# Negative inputs created by the trusted test controller before publishing.
# All SFTP writes are excluded by the held lock or stopped listener.
runuser -u f02upload -- sh -ec '
    cd /sandbox/quarantine/incoming
    ln -s backup.cfg symlink.cfg
    : > empty.cfg
    printf x > alias.cfg
    ln alias.cfg hardlink.cfg
'
if test "$live" = 1; then
    # A second authenticated session must wait on the same inode. Close the
    # inherited publisher descriptor in the child before it runs the client.
    (
        exec 7<&-
        printf 'put /sandbox/partial /incoming/backup.cfg\n' | sftp_batch
    ) >/sandbox/second-output 2>&1 &
    second=$!
    stage=second-session-waiting
    for attempt in 1 2 3 4 5 6 7 8 9 10; do
        if pgrep -u 2001 -x flock >/dev/null; then break; fi
        kill -0 "$second"
        sleep 1
    done
    pgrep -u 2001 -x flock >/dev/null
    kill -0 "$second"
    cmp -s /sandbox/complete /sandbox/quarantine/incoming/backup.cfg
    printf '%s\n' 'F02_SFTP_RECONNECT_BLOCKED_DURING_PUBLISH_OK (SYNTHETIC_ONLY)'
else
    if pgrep -u 2001 >/dev/null; then exit 1; fi
fi
if test "$multi" = 1; then
    stage=independent-device-upload
    # A reconnect is waiting while A publisher holds its lock. B must
    # authenticate and finish within a bounded interval before A releases.
    printf '%s\n' 'SYNTHETIC-F02-SECOND-DEVICE-DISTINCT-CONTENT' > /sandbox/complete-b
    if printf 'pwd\n' | sftp_batch f02uploadb /sandbox/keys/client >/dev/null 2>&1; then exit 1; fi
    if printf 'pwd\n' | sftp_batch f02upload /sandbox/keys/client-b >/dev/null 2>&1; then exit 1; fi
    if printf 'get /incoming/backup.cfg /sandbox/unexpected-b-read\n' | sftp_batch f02uploadb /sandbox/keys/client-b >/dev/null 2>&1; then exit 1; fi
    test ! -e /sandbox/unexpected-b-read
    if printf 'ls /../quarantine/incoming\n' | sftp_batch f02uploadb /sandbox/keys/client-b >/dev/null 2>&1; then exit 1; fi
    # Direct client rather than inherited publisher lock descriptor.
    (
        exec 7<&-
        printf 'put /sandbox/complete-b /incoming/upload.part\nrename /incoming/upload.part /incoming/backup.cfg\n' |
            timeout 15 sftp -q -b - -P 2222 -i /sandbox/keys/client-b \
                -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
                -o UserKnownHostsFile=/sandbox/keys/known_hosts -o GlobalKnownHostsFile=/dev/null \
                -o ConnectTimeout=3 f02uploadb@127.0.0.1
    )
    kill -0 "$second"
    pgrep -u 2001 -x flock >/dev/null
    cmp -s /sandbox/complete /sandbox/quarantine/incoming/backup.cfg
    cmp -s /sandbox/complete-b /sandbox/quarantine-b/incoming/backup.cfg
    test -z "$(ls -A "$published/124")"
    printf '%s\n' 'F02_SFTP_MULTI_DEVICE_INDEPENDENT_TRANSFER_OK (SYNTHETIC_ONLY)'
    stage=independent-device-publication
    exec 6</sandbox/quarantine-b/session.lock
    flock -w 10 6
    runuser -u f02uploadb -- sh -ec '
        cd /sandbox/quarantine-b/incoming
        ln -s backup.cfg symlink.cfg
        : > empty.cfg
        printf x > alias.cfg
        ln alias.cfg hardlink.cfg
    '
    F02_SFTP_BATCH_CONFIRMED=1 F02_SFTP_SECOND_DEVICE=1 F02_SFTP_DEVICE=124 python3 /usr/local/bin/f02-handoff.py
    before_b="$(stat -c '%d:%i:%s:%y:%z' "$published/124/backup.cfg")"
    flock -u 6
    exec 6<&-
    kill -0 "$second"
    pgrep -u 2001 -x flock >/dev/null
    cmp -s /sandbox/complete /sandbox/quarantine/incoming/backup.cfg
    test -z "$(ls -A "$published/$device")"
fi
stage=protected-publication
F02_SFTP_BATCH_CONFIRMED=1 python3 /usr/local/bin/f02-handoff.py
if test "$live" = 1; then
    before="$(stat -c '%d:%i:%s:%y:%z' "$published/$device/backup.cfg")"
    flock -u 7
    exec 7<&-
    stage=second-session-resume
    wait "$second"
    kill -0 "$listener"
    cmp -s /sandbox/partial /sandbox/quarantine/incoming/backup.cfg
    cmp -s /sandbox/complete "$published/$device/backup.cfg"
    test "$(stat -c '%d:%i:%s:%y:%z' "$published/$device/backup.cfg")" = "$before"
    printf '%s\n' 'F02_SFTP_LIVE_LISTENER_IMMUTABLE_HANDOFF_OK (SYNTHETIC_ONLY)'
    if test "$multi" = 1; then
        stage=multi-device-final-isolation
        cmp -s /sandbox/complete-b "$published/124/backup.cfg"
        test "$(stat -c '%d:%i:%s:%y:%z' "$published/124/backup.cfg")" = "$before_b"
        test "$(stat -c '%d:%i' "$published/$device/backup.cfg")" != "$(stat -c '%d:%i' "$published/124/backup.cfg")"
        runuser -u f02upload -- test ! -x /sandbox/quarantine-b/incoming
        runuser -u f02uploadb -- test ! -x /sandbox/quarantine/incoming
        runuser -u f02uploadb -- test ! -x "$published"
        runuser -u f02read -- test -r "$published/124/backup.cfg"
        runuser -u f02read -- test ! -w "$published/124/backup.cfg"
        runuser -u f02read -- test ! -w "$published/124"
        runuser -u f02read -- test ! -x /sandbox/quarantine-b/incoming
        printf '%s\n' 'F02_SFTP_MULTI_DEVICE_ISOLATED_PUBLICATION_OK (SYNTHETIC_ONLY)'
    fi
fi
if test "$abort" = 1; then
    printf '%s\n' 'F02_SFTP_ABORT_RECOVERY_PUBLICATION_OK (SYNTHETIC_ONLY)'
fi
stage=final-permissions
runuser -u f02read -- test -r "$published/$device/backup.cfg"
runuser -u f02read -- test ! -w "$published/$device/backup.cfg"
runuser -u f02read -- test ! -w "$published/$device"
runuser -u f02upload -- test ! -x "$published"
runuser -u f02read -- test ! -x /sandbox/quarantine/incoming
printf '%s\n' 'F02_SFTP_ISOLATED_HANDOFF_OK (DESCRIPTOR_ONLY_NOT_BACKUP_SUCCESS)'
