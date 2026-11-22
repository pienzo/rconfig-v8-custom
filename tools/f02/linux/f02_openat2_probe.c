// SPDX-License-Identifier: GPL-3.0-or-later
// F02 reference probe ONLY: not an rConfig production file opener.
// Do not interpret a successful probe as an accepted backup or file receipt.
#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <linux/openat2.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <unistd.h>

#define F02_MAX_FILE_BYTES (100 * 1024 * 1024)
#define F02_RESOLVE_FLAGS (RESOLVE_BENEATH | RESOLVE_NO_SYMLINKS | RESOLVE_NO_MAGICLINKS | RESOLVE_NO_XDEV)

static bool safe_basename(const char *name) {
    size_t len = strlen(name);
    if (len == 0 || len > 255 || strcmp(name, ".") == 0 || strcmp(name, "..") == 0) {
        return false;
    }
    for (size_t i = 0; i < len; ++i) {
        unsigned char c = (unsigned char)name[i];
        if (c < 0x20 || c == 0x7f || c == '/' || c == '\\') {
            return false;
        }
    }
    return true;
}

static bool parse_device(const char *text, char out[32]) {
    if (*text == '\0' || strlen(text) > 10) {
        return false;
    }
    for (const char *p = text; *p != '\0'; ++p) {
        if (*p < '0' || *p > '9') {
            return false;
        }
    }
    errno = 0;
    char *end = NULL;
    unsigned long value = strtoul(text, &end, 10);
    if (errno != 0 || *end != '\0' || value < 1 || value > INT_MAX) {
        return false;
    }
    int n = snprintf(out, 32, "%lu", value);
    return n > 0 && n < 32;
}

static int open_beneath(int dirfd, const char *name, uint64_t flags) {
    const struct open_how how = {
        .flags = flags,
        .mode = 0,
        .resolve = F02_RESOLVE_FLAGS,
    };
    // Requires Linux >= 5.6 and a kernel with openat2 support.
    // If unavailable or denied by seccomp, FAIL CLOSED. Never use openat() fallback.
    return (int)syscall(SYS_openat2, dirfd, name, &how, sizeof(how));
}

int main(int argc, char **argv) {
    if (argc != 4) {
        fputs("usage: f02_openat2_probe ABSOLUTE_TRUSTED_ROOT DEVICE_ID BASENAME\n", stderr);
        return 2;
    }

    const char *root_path = argv[1];
    const char *filename = argv[3];
    char device[32];
    const size_t root_len = strlen(root_path);

    // The real product must source this root from administrator-controlled config.
    // Parent directories of that root are outside the protection of this probe.
    if (root_len < 2 || root_len >= PATH_MAX || root_path[0] != '/' || root_path[root_len - 1] == '/'
        || !parse_device(argv[2], device) || !safe_basename(filename)) {
        fputs("probe refused\n", stderr);
        return 2;
    }

    // Root is an already trusted absolute path. Never follow its final symlink.
    int rootfd = open(root_path, O_PATH | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (rootfd < 0) {
        fputs("probe refused\n", stderr);
        return 3;
    }

    // Resolve the numeric device subdirectory under a stable root descriptor.
    int devfd = open_beneath(rootfd, device, O_PATH | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (devfd < 0) {
        close(rootfd);
        fputs("probe refused\n", stderr);
        return 3;
    }

    // O_NONBLOCK prevents a malicious FIFO from causing an opening-time hang.
    int fd = open_beneath(devfd, filename, O_RDONLY | O_NONBLOCK | O_NOFOLLOW | O_CLOEXEC);
    if (fd < 0) {
        close(devfd);
        close(rootfd);
        fputs("probe refused\n", stderr);
        return 3;
    }

    struct stat statbuf;
    bool accepted_descriptor = fstat(fd, &statbuf) == 0
        && S_ISREG(statbuf.st_mode) && statbuf.st_nlink == 1
        && statbuf.st_size > 0 && statbuf.st_size <= F02_MAX_FILE_BYTES;

    close(fd);
    close(devfd);
    close(rootfd);

    if (!accepted_descriptor) {
        fputs("probe refused\n", stderr);
        return 3;
    }

    // No contents, hash, path or metadata are disclosed by this prototype.
    puts("PROBE_OPENED_ONLY_NOT_BACKUP_SUCCESS");
    return 0;
}
