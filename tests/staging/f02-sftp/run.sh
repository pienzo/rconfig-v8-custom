#!/bin/sh
# Disposable Docker test only, not an installer or an operational publisher.
set -eu
test "${GITHUB_ACTIONS:-}" = true
test "${F02_SFTP_DISPOSABLE:-}" = 1
test -f /.dockerenv
test "$(id -u)" = 0
test ! -e /sandbox
umask 077
groupadd -g 2001 f02upload
useradd -M -u 2001 -g 2001 -d /incoming -s /usr/sbin/nologin -p '*' f02upload
groupadd -g 2002 f02read
useradd -M -u 2002 -g 2002 -d /nonexistent -s /usr/sbin/nologin f02read
install -d -o root -g root -m 0755 /sandbox /sandbox/quarantine
install -d -o f02upload -g f02upload -m 0700 /sandbox/quarantine/incoming
install -d -o root -g f02read -m 0750 /sandbox/published /sandbox/published/123
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
Subsystem sftp internal-sftp
LogLevel ERROR
CONF
/usr/sbin/sshd -t -f /sandbox/sshd.conf
/usr/sbin/sshd -D -e -f /sandbox/sshd.conf >/sandbox/sshd.log 2>&1 &
listener=$!
cleanup() {
    if test -n "${listener:-}"; then kill "$listener" 2>/dev/null || true; wait "$listener" 2>/dev/null || true; fi
}
trap cleanup EXIT
sftp_batch() {
    sftp -q -b - -P 2222 -i /sandbox/keys/client \
        -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
        -o UserKnownHostsFile=/sandbox/keys/known_hosts -o GlobalKnownHostsFile=/dev/null \
        -o ConnectTimeout=3 f02upload@127.0.0.1
}
ready=0
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if printf 'pwd\n' | sftp_batch >/dev/null 2>&1; then ready=1; break; fi
    kill -0 "$listener"
    sleep 1
done
test "$ready" = 1
printf '%s\n' 'SYNTHETIC-PARTIAL' > /sandbox/partial
printf '%s\n' 'SYNTHETIC-F02-FINAL-CONTENT' > /sandbox/complete
printf 'put /sandbox/partial /incoming/upload.part\n' | sftp_batch
test -s /sandbox/quarantine/incoming/upload.part
test -z "$(ls -A /sandbox/published/123)"
# Even a matching final-looking name in quarantine is not reader-visible.
printf 'put /sandbox/complete /incoming/upload.part\nrename /incoming/upload.part /incoming/backup.cfg\n' | sftp_batch
test ! -e /sandbox/quarantine/incoming/upload.part
test -s /sandbox/quarantine/incoming/backup.cfg
test -z "$(ls -A /sandbox/published/123)"
if printf 'put /sandbox/complete /outside.cfg\n' | sftp_batch >/dev/null 2>&1; then exit 1; fi
if printf 'ls /published\n' | sftp_batch >/dev/null 2>&1; then exit 1; fi
printf '%s\n' 'F02_REAL_SFTP_QUARANTINE_OK (SYNTHETIC_ONLY)'
# Quiesce this dedicated listener and require all uploader processes to exit.
kill "$listener"
wait "$listener" || true
listener=
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if ! pgrep -u 2001 >/dev/null; then break; fi
    sleep 1
done
if pgrep -u 2001 >/dev/null; then exit 1; fi
# Negative inputs in the private quarantine. No writer is running when the
# test publisher opens a candidate; this does NOT model a live uploader.
runuser -u f02upload -- sh -ec '
    cd /sandbox/quarantine/incoming
    ln -s backup.cfg symlink.cfg
    : > empty.cfg
    printf x > alias.cfg
    ln alias.cfg hardlink.cfg
'
if pgrep -u 2001 >/dev/null; then exit 1; fi
python3 /usr/local/bin/f02-handoff.py
runuser -u f02read -- test -r /sandbox/published/123/backup.cfg
runuser -u f02read -- test ! -w /sandbox/published/123/backup.cfg
runuser -u f02read -- test ! -w /sandbox/published/123
runuser -u f02upload -- test ! -x /sandbox/published
runuser -u f02read -- test ! -x /sandbox/quarantine/incoming
printf '%s\n' 'F02_SFTP_ISOLATED_HANDOFF_OK (DESCRIPTOR_ONLY_NOT_BACKUP_SUCCESS)'
