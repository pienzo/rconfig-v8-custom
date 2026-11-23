<?php

namespace App\Services\ExternalBackups;

/**
 * Filesystem identity of an already checked directory.
 *
 * Directory enumeration can update atime without a replacement. Ignore access
 * time (including the duplicate numeric lstat field) but preserve inode,
 * ownership, mode and change/modify timestamps.
 */
final class ExternalBackupDirectoryIdentity
{
    /**
     * @param  array<string|int, int>  $before
     * @param  array<string|int, int>  $after
     */
    public static function same(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if (! isset($before[$field], $after[$field]) || $before[$field] !== $after[$field]) {
                return false;
            }
        }

        return true;
    }
}
