<?php
declare(strict_types=1);

namespace Wiki\Filesystem;

/**
 * Advisory OS-level lock (flock) on a dedicated lock file.
 * The kernel releases the lock when the last descriptor referring to the open file
 * description closes - including when the process crashes - so a stale JSON "running"
 * status can never wedge the system.
 */
final class FileLock
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle, private string $path)
    {
        $this->handle = $handle;
    }

    /** Non-blocking exclusive acquire. Returns null when somebody else holds the lock. */
    public static function tryExclusive(string $path): ?self
    {
        return self::open($path, LOCK_EX | LOCK_NB);
    }

    /** Non-blocking shared acquire (used for version leases). */
    public static function tryShared(string $path): ?self
    {
        return self::open($path, LOCK_SH | LOCK_NB);
    }

    /** Blocking exclusive acquire with a timeout; only for very short critical sections. */
    public static function exclusiveWithTimeout(string $path, float $timeoutSeconds): ?self
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $lock = self::open($path, LOCK_EX | LOCK_NB);
            if ($lock !== null) {
                return $lock;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        return null;
    }

    private static function open(string $path, int $operation): ?self
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $h = @fopen($path, 'c+');
        if ($h === false) {
            throw new \RuntimeException('Cannot open lock file');
        }
        if (!flock($h, $operation)) {
            fclose($h);
            return null;
        }
        return new self($h, $path);
    }

    /** Adopt a lock descriptor inherited from the parent process (the launcher). */
    public static function adopt(int $fd, string $expectedPath): ?self
    {
        $stream = @fopen('php://fd/' . $fd, 'c+');
        if ($stream === false) {
            return null;
        }
        // Sanity check: the inherited descriptor must really be the sync lock file.
        $a = @fstat($stream);
        $b = @stat($expectedPath);
        if ($a === false || $b === false || $a['ino'] !== $b['ino'] || $a['dev'] !== $b['dev']) {
            fclose($stream);
            return null;
        }
        // Same open file description => re-locking is idempotent and confirms we own it.
        if (!flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);
            return null;
        }
        return new self($stream, $expectedPath);
    }

    /** @return resource */
    public function handle()
    {
        return $this->handle;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /** Close our descriptor without unlocking (child process keeps the lock alive). */
    public function detach(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
