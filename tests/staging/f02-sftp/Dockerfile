# Test fixture only; never deploy this image.
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    openssh-server openssh-client python3 gcc libc6-dev libssl-dev procps util-linux passwd \
    && rm -rf /var/lib/apt/lists/*
COPY f02_safe_digest.c /tmp/f02_safe_digest.c
RUN cc -std=c11 -O2 -Wall -Wextra -Werror /tmp/f02_safe_digest.c -lcrypto -o /usr/local/bin/f02-safe-digest \
    && rm /tmp/f02_safe_digest.c
COPY run.sh /usr/local/bin/f02-sftp-test
COPY handoff.py /usr/local/bin/f02-handoff.py
RUN chmod 0755 /usr/local/bin/f02-sftp-test
ENTRYPOINT ["/usr/local/bin/f02-sftp-test"]
