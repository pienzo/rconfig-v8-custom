// SPDX-License-Identifier: GPL-3.0-or-later
// rConfig F02 read-only native file verification. No backup SUCCESS or receipt.
#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <inttypes.h>
#include <limits.h>
#include <linux/openat2.h>
#include <openssl/evp.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/resource.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <time.h>
#include <unistd.h>

#define F02_MAX_BYTES UINT64_C(1073741824)
#define F02_RESOLVE (RESOLVE_BENEATH | RESOLVE_NO_SYMLINKS | RESOLVE_NO_MAGICLINKS | RESOLVE_NO_XDEV)

static void fail(void) {
    fputs("F02_FILE_VERIFICATION_REFUSED\n", stderr);
    exit(3);
}

static bool parse_number(const char *value, uint64_t *out) {
    if (value == NULL || *value == '\0' || *value == '-' || *value == '+') {
        return false;
    }
    for (const char *p = value; *p != '\0'; ++p) {
        if (*p < '0' || *p > '9') return false;
    }
    errno = 0;
    char *end = NULL;
    unsigned long long parsed = strtoull(value, &end, 10);
    if (errno != 0 || *end != '\0') return false;
    *out = (uint64_t)parsed;
    return true;
}

static bool valid_basename(const char *name) {
    size_t size = strlen(name);
    if (size == 0 || size > 255 || strcmp(name, ".") == 0 || strcmp(name, "..") == 0) return false;
    for (size_t i = 0; i < size; ++i) {
        unsigned char c = (unsigned char)name[i];
        if (c < 0x20 || c == 0x7f || c == '/' || c == '\\') return false;
    }
    return true;
}

static int confined_open(int parent, const char *component, uint64_t flags) {
    struct open_how how = {
        .flags = flags,
        .mode = 0,
        .resolve = F02_RESOLVE,
    };
    return (int)syscall(SYS_openat2, parent, component, &how, sizeof(how));
}

static bool valid_file(const struct stat *s, uint64_t min_bytes, uint64_t max_bytes) {
    return S_ISREG(s->st_mode) && s->st_nlink == 1
        && s->st_size > 0 && (uint64_t)s->st_size >= min_bytes
        && (uint64_t)s->st_size <= max_bytes;
}

static bool same_identity(const struct stat *a, const struct stat *b) {
    return a->st_dev == b->st_dev && a->st_ino == b->st_ino
        && a->st_mode == b->st_mode && a->st_uid == b->st_uid
        && a->st_gid == b->st_gid && a->st_nlink == b->st_nlink
        && a->st_size == b->st_size
        && a->st_mtim.tv_sec == b->st_mtim.tv_sec
        && a->st_mtim.tv_nsec == b->st_mtim.tv_nsec
        && a->st_ctim.tv_sec == b->st_ctim.tv_sec
        && a->st_ctim.tv_nsec == b->st_ctim.tv_nsec;
}

static bool expected_identity(const struct stat *s, const uint64_t expected[5]) {
    return (uintmax_t)s->st_dev == (uintmax_t)expected[0]
        && (uintmax_t)s->st_ino == (uintmax_t)expected[1]
        && s->st_size >= 0 && (uint64_t)s->st_size == expected[2]
        && s->st_mtim.tv_sec >= 0 && (uint64_t)s->st_mtim.tv_sec == expected[3]
        && s->st_ctim.tv_sec >= 0 && (uint64_t)s->st_ctim.tv_sec == expected[4];
}

static bool within_budget(const struct timespec *started) {
    struct timespec now;
    if (clock_gettime(CLOCK_MONOTONIC, &now) != 0) return false;
    return now.tv_sec - started->tv_sec < 6;
}

int main(int argc, char **argv) {
    // ROOT, DEVICE_ID, BASENAME, EXPECTED_DEV, EXPECTED_INODE,
    // EXPECTED_SIZE, EXPECTED_MTIME, EXPECTED_CTIME, MIN_BYTES, MAX_BYTES
    if (argc != 11) return 2;

    const char *root = argv[1];
    const char *device_arg = argv[2];
    const char *filename = argv[3];
    size_t root_len = strlen(root);
    uint64_t device = 0, expected[5] = {0}, min_bytes = 0, max_bytes = 0;

    if (root_len < 2 || root_len >= PATH_MAX || root[0] != '/' || root[root_len - 1] == '/'
        || !valid_basename(filename) || !parse_number(device_arg, &device)
        || device < 1 || device > INT_MAX || argv[2][0] == '0') return 2;
    for (int i = 0; i < 5; ++i) {
        if (!parse_number(argv[i + 4], &expected[i])) return 2;
    }
    if (!parse_number(argv[9], &min_bytes) || !parse_number(argv[10], &max_bytes)
        || min_bytes < 1 || max_bytes < min_bytes || max_bytes > F02_MAX_BYTES) return 2;

    // The deployer must protect ROOT and its ancestor directories.
    // The final ROOT component is explicitly no-follow. No privileged mode.
    struct rlimit memory = {.rlim_cur = 256UL * 1024UL * 1024UL, .rlim_max = 256UL * 1024UL * 1024UL};
    if (setrlimit(RLIMIT_AS, &memory) != 0) fail();
    alarm(8);
    int rootfd = open(root, O_PATH | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (rootfd < 0) fail();

    char device_name[32];
    if (snprintf(device_name, sizeof(device_name), "%" PRIu64, device) >= (int)sizeof(device_name)) fail();
    int dirfd = confined_open(rootfd, device_name, O_PATH | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (dirfd < 0) fail();

    int fd = confined_open(dirfd, filename, O_RDONLY | O_NONBLOCK | O_NOFOLLOW | O_CLOEXEC);
    if (fd < 0) fail();
    struct stat before, after, named;
    if (fstat(fd, &before) != 0 || !valid_file(&before, min_bytes, max_bytes)
        || !expected_identity(&before, expected)) fail();

    struct timespec started;
    if (clock_gettime(CLOCK_MONOTONIC, &started) != 0) fail();

    EVP_MD_CTX *context = EVP_MD_CTX_new();
    if (context == NULL || EVP_DigestInit_ex(context, EVP_sha256(), NULL) != 1) fail();

    unsigned char buffer[65536];
    uint64_t bytes = 0;
    while (bytes < (uint64_t)before.st_size) {
        if (!within_budget(&started)) fail();
        size_t wanted = (size_t)((uint64_t)before.st_size - bytes);
        if (wanted > sizeof(buffer)) wanted = sizeof(buffer);
        ssize_t n = read(fd, buffer, wanted);
        if (n <= 0 || EVP_DigestUpdate(context, buffer, (size_t)n) != 1) fail();
        bytes += (uint64_t)n;
    }
    if (!within_budget(&started)) fail();
    if (read(fd, buffer, 1) != 0) fail();

    unsigned char digest[EVP_MAX_MD_SIZE];
    unsigned int digest_length = 0;
    if (EVP_DigestFinal_ex(context, digest, &digest_length) != 1 || digest_length != 32) fail();
    EVP_MD_CTX_free(context);

    // Both descriptor and still-visible basename must refer to the same file.
    if (fstat(fd, &after) != 0 || fstatat(dirfd, filename, &named, AT_SYMLINK_NOFOLLOW) != 0
        || !same_identity(&before, &after) || !same_identity(&after, &named)
        || !valid_file(&after, min_bytes, max_bytes) || !within_budget(&started)) fail();

    char hex[65];
    for (unsigned int i = 0; i < digest_length; ++i) {
        snprintf(hex + 2 * i, 3, "%02x", digest[i]);
    }
    hex[64] = '\0';
    printf("{\"version\":1,\"state\":\"descriptor_checked_only\",\"sha256\":\"%s\","
           "\"device\":%" PRIuMAX ",\"inode\":%" PRIuMAX ",\"size\":%" PRIu64 ","
           "\"mtime\":%" PRIuMAX ",\"ctime\":%" PRIuMAX ","
           "\"mtime_ns\":%ld,\"ctime_ns\":%ld}\n",
           hex, (uintmax_t)after.st_dev, (uintmax_t)after.st_ino, bytes,
           (uintmax_t)after.st_mtim.tv_sec, (uintmax_t)after.st_ctim.tv_sec,
           after.st_mtim.tv_nsec, after.st_ctim.tv_nsec);

    close(fd);
    close(dirfd);
    close(rootfd);
    return 0;
}
