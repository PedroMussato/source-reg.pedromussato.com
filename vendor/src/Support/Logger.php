<?php
declare(strict_types=1);

namespace Wiki\Support;

use Wiki\Config\Settings;

/** JSON-lines operational log with size-based rotation. Never receives credentials (redacted). */
final class Logger
{
    public function __construct(private Paths $paths, private Settings $settings, private ?Clock $clock = null)
    {
        $this->clock ??= new SystemClock();
    }

    /** @param array<string,mixed> $context */
    public function info(string $event, array $context = []): void { $this->write('info', $event, $context); }
    /** @param array<string,mixed> $context */
    public function warning(string $event, array $context = []): void { $this->write('warning', $event, $context); }
    /** @param array<string,mixed> $context */
    public function error(string $event, array $context = []): void { $this->write('error', $event, $context); }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        try {
            $clean = [];
            foreach ($context as $k => $v) {
                $clean[$k] = is_string($v) ? Redactor::redact(mb_substr($v, 0, 2000)) : $v;
            }
            $line = json_encode(
                ['ts' => Time::iso($this->clock->now()), 'level' => $level, 'event' => $event] + $clean,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($line === false) {
                return;
            }
            $file = $this->paths->appLog();
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $this->rotateIfNeeded($file);
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logging must never break a request or a synchronization.
        }
    }

    private function rotateIfNeeded(string $file): void
    {
        $max = $this->settings->int('log_max_bytes');
        clearstatcache(true, $file);
        if (!is_file($file) || (int) @filesize($file) < $max) {
            return;
        }
        $lock = FileLockHelper::tryRotate($this->paths->locks . '/log-rotate.lock');
        if ($lock === null) {
            return;
        }
        try {
            clearstatcache(true, $file);
            if ((int) @filesize($file) < $max) {
                return;
            }
            $keep = max(1, $this->settings->int('log_keep_files'));
            @unlink($file . '.' . $keep);
            for ($i = $keep - 1; $i >= 1; $i--) {
                if (is_file($file . '.' . $i)) {
                    @rename($file . '.' . $i, $file . '.' . ($i + 1));
                }
            }
            @rename($file, $file . '.1');
        } finally {
            $lock->release();
        }
    }
}
