<?php
declare(strict_types=1);

namespace Wiki\Filesystem;

final class Fs
{
    /** Recursively delete a directory without ever following symlinks. */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                @chmod($child, 0755);
                self::removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @chmod($path, 0755);
        @rmdir($path);
    }

    /** @return list<string> child names (no dots), sorted bytewise for determinism */
    public static function children(string $dir): array
    {
        $items = @scandir($dir) ?: [];
        $out = array_values(array_filter($items, static fn ($i) => $i !== '.' && $i !== '..'));
        sort($out, SORT_STRING);
        return $out;
    }

    public static function freeBytes(string $path): ?int
    {
        $f = @disk_free_space($path);
        return $f === false ? null : (int) $f;
    }
}
