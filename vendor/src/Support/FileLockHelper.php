<?php
declare(strict_types=1);

namespace Wiki\Support;

use Wiki\Filesystem\FileLock;

final class FileLockHelper
{
    public static function tryRotate(string $path): ?FileLock
    {
        try {
            return FileLock::tryExclusive($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
