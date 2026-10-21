<?php
declare(strict_types=1);

namespace Wiki\Filesystem;

/** Atomic JSON/text persistence: write temp file in the same directory, flush, validate, rename. */
final class AtomicFile
{
    /** @param array<mixed> $data */
    public static function writeJson(string $path, array $data, int $mode = 0640): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::writeText($path, $json . "\n", $mode, static function (string $tmp): void {
            $back = file_get_contents($tmp);
            if ($back === false || json_decode($back, true) === null) {
                throw new \RuntimeException('Atomic write validation failed');
            }
        });
    }

    public static function writeText(string $path, string $contents, int $mode = 0640, ?callable $validate = null): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory for atomic write');
        }
        $tmp = $dir . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(6));
        $h = @fopen($tmp, 'xb');
        if ($h === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }
        try {
            $len = strlen($contents);
            $written = 0;
            while ($written < $len) {
                $n = fwrite($h, substr($contents, $written));
                if ($n === false || $n === 0) {
                    throw new \RuntimeException('Write failed (disk full?)');
                }
                $written += $n;
            }
            fflush($h);
            if (function_exists('fsync')) {
                @fsync($h);
            }
            fclose($h);
            $h = null;
            @chmod($tmp, $mode);
            if ($validate !== null) {
                $validate($tmp);
            }
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('Atomic rename failed');
            }
        } catch (\Throwable $e) {
            if (is_resource($h)) {
                fclose($h);
            }
            @unlink($tmp);
            throw $e;
        }
    }

    /**
     * @return array<mixed>|null null when the file does not exist
     * @throws CorruptFileException when the file exists but is not valid JSON object/array
     */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new CorruptFileException('Unreadable file');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new CorruptFileException('Invalid JSON');
        }
        return $data;
    }
}
