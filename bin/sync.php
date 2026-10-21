#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Synchronization worker. Started detached by the web application (never a permanent service),
 * or manually:   php bin/sync.php [--force]
 *
 * Exit codes: 0 success (updated or unchanged) | 1 failed | 2 another sync holds the lock |
 *             3 not installed | 4 lock handover failed
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Wiki\Application\Services;
use Wiki\Config\InstallationStatus;
use Wiki\Filesystem\FileLock;
use Wiki\Support\Time;

$opts = getopt('', ['lock-fd::', 'force']);
$services = new Services();
$services->paths->ensureDirectories();
set_time_limit(0);
ignore_user_abort(true);

if ($services->installation->status() !== InstallationStatus::Installed) {
    fwrite(STDERR, "Not installed.\n");
    exit(3);
}

// Detach from the launching request's session so a killed worker/pool does not take us along.
if (function_exists('posix_setsid')) {
    @posix_setsid();
}
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    @pcntl_signal(SIGHUP, SIG_IGN);
}

// Lock: either inherited from the launcher (fd) or acquired here for manual runs.
if (isset($opts['lock-fd'])) {
    $lock = FileLock::adopt((int) $opts['lock-fd'], $services->paths->syncLock());
    if ($lock === null) {
        $services->logger->error('sync_lock_handover_failed');
        exit(4);
    }
} else {
    $lock = FileLock::tryExclusive($services->paths->syncLock());
    if ($lock === null) {
        fwrite(STDERR, "Another synchronization is already running.\n");
        exit(2);
    }
}

if (isset($opts['force'])) {
    $services->state->update(function (array $s): array {
        $s['retry_after'] = null;
        return $s;
    });
}

// Watchdog: a hard upper bound on the whole run; the OS releases the lock when we exit.
if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
    $limit = $services->settings->int('sync_timeout_seconds') + 60;
    pcntl_signal(SIGALRM, static function () use ($services): void {
        $services->logger->error('sync_watchdog_timeout');
        try {
            $now = $services->clock->now();
            $services->state->update(function (array $s) use ($now, $services): array {
                $s['status'] = 'failed';
                $s['last_error_category'] = 'timeout';
                $s['last_error'] = 'The synchronization exceeded its permitted runtime.';
                $s['candidate_version'] = null;
                $s['retry_after'] = Time::iso($now + $services->settings->int('retry_cooldown_seconds'));
                return $s;
            });
        } catch (\Throwable) {
        }
        exit(1);
    });
    pcntl_alarm($limit);
}

$result = $services->synchronizer()->run();
$lock->release();
fwrite(STDOUT, sprintf("[%s] sync %s%s\n", Time::iso(time()), $result->status, $result->message ? ': ' . $result->message : ''));
exit($result->ok() ? 0 : 1);
