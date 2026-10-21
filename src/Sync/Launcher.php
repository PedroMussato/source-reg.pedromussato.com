<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Config\Settings;
use Wiki\Filesystem\FileLock;
use Wiki\Support\Clock;
use Wiki\Support\Logger;
use Wiki\Support\Paths;
use Wiki\Support\Time;

/**
 * Called by ordinary page requests. Decides whether a synchronization is due and, if so, starts a
 * DETACHED php CLI process (bin/sync.php) without waiting for it.
 *
 * Concurrency: the request takes the sync lock with a NON-BLOCKING flock, then hands the very same
 * open file description to the child (descriptor 3). The lock therefore stays held for the whole
 * child lifetime and is released by the kernel when the child exits or crashes. A request that
 * cannot get the lock simply skips: it never waits.
 */
final class Launcher
{
    public function __construct(
        private Paths $paths,
        private Settings $settings,
        private Clock $clock,
        private Logger $logger,
        private StateStore $state,
        private VersionStore $versions,
    ) {
    }

    /** True when the child-process mechanism is usable in this PHP configuration. */
    public static function processLaunchSupported(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    /** Is a synchronization running right now? (the lock is authoritative, not the JSON status) */
    public function isRunning(): bool
    {
        $lock = FileLock::tryExclusive($this->paths->syncLock());
        if ($lock === null) {
            return true;
        }
        $lock->release();
        return false;
    }

    /**
     * Never throws; never blocks on a busy lock.
     * @return string disabled|ineligible:<reason>|busy|unsupported|launched|failed
     */
    public function maybeLaunch(): string
    {
        try {
            if ($this->settings->string('sync_mode') !== 'detached') {
                return 'disabled';
            }
            $now = $this->clock->now();
            $interval = $this->settings->int('sync_interval_seconds');

            // Cheap pre-check without any locking.
            $decision = FreshnessPolicy::evaluate($this->state->read(), $now, $interval);
            if (!$decision['eligible']) {
                return 'ineligible:' . $decision['reason'];
            }
            $lock = FileLock::tryExclusive($this->paths->syncLock());
            if ($lock === null) {
                return 'busy';
            }
            // We own the lock. Re-evaluate against fresh state (another request may have just finished a run).
            $state = $this->state->read();
            $decision = FreshnessPolicy::evaluate($state, $now, $interval);
            if (!$decision['eligible']) {
                $lock->release();
                return 'ineligible:' . $decision['reason'];
            }
            if (in_array($state['status'], ['launching', 'running'], true)) {
                // The lock was free although the state says "running": the previous process died.
                $this->logger->warning('sync_interrupted', ['previous_status' => $state['status']]);
                $this->state->update(function (array $s): array {
                    $s['status'] = 'failed';
                    $s['last_error_category'] = 'interrupted';
                    $s['last_error'] = 'The previous synchronization did not finish.';
                    $s['candidate_version'] = null;
                    return $s;
                });
            }
            if (!self::processLaunchSupported()) {
                $this->recordLaunchFailure('launch_unsupported', 'Background processes are not available on this server.', $now);
                $lock->release();
                return 'unsupported';
            }
            $php = $this->phpCli();
            if ($php === null) {
                $this->recordLaunchFailure('launch_failed', 'The PHP command-line binary could not be found.', $now);
                $lock->release();
                return 'failed';
            }

            // Safety net: if the child dies before reporting, the cooldown still prevents a launch storm.
            $this->state->update(function (array $s) use ($now): array {
                $s['status'] = 'launching';
                $s['last_attempt_at'] = Time::iso($now);
                $s['sync_started_at'] = Time::iso($now);
                $s['retry_after'] = Time::iso($now + max($this->settings->int('retry_cooldown_seconds'), 60));
                return $s;
            });

            if (!$this->spawn($php, $lock)) {
                $this->recordLaunchFailure('launch_failed', 'The synchronization process could not be started.', $now);
                $lock->release();
                return 'failed';
            }
            $lock->detach();   // the child's inherited descriptor keeps the lock alive
            $this->logger->info('sync_launched', ['reason' => $decision['reason']]);
            return 'launched';
        } catch (\Throwable $e) {
            $this->logger->error('sync_launch_error', ['error' => $e->getMessage()]);
            return 'failed';
        }
    }

    private function spawn(string $php, FileLock $lock): bool
    {
        $script = dirname(__DIR__, 2) . '/bin/sync.php';
        $env = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => $this->paths->home];
        // getenv() sees the real process environment regardless of php.ini variables_order.
        foreach (getenv() as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'WIKI_')) {
                $env[$k] = (string) $v;
            }
        }
        // "sh -c '\"$0\" \"$@\" &'" backgrounds the child and exits immediately, so proc_close() below
        // returns at once. All arguments are server-controlled constants, passed as an argv array.
        $cmd = ['/bin/sh', '-c', '"$0" "$@" &', $php, '-d', 'display_errors=0', $script, '--lock-fd=3'];
        $logFile = $this->paths->syncLog();
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
            3 => $lock->handle(),
        ];
        $proc = @proc_open($cmd, $spec, $pipes, dirname(__DIR__, 2), $env);
        if (!is_resource($proc)) {
            return false;
        }
        return proc_close($proc) === 0;
    }

    private function recordLaunchFailure(string $category, string $message, int $now): void
    {
        $this->logger->error('sync_launch_failed', ['category' => $category, 'message' => $message]);
        $this->state->update(function (array $s) use ($category, $message, $now): array {
            $s['status'] = 'failed';
            $s['last_attempt_at'] = Time::iso($now);
            $s['last_error_category'] = $category;
            $s['last_error'] = $message;
            $s['retry_after'] = Time::iso($now + $this->settings->int('retry_cooldown_seconds'));
            return $s;
        });
    }

    public function phpCli(): ?string
    {
        $configured = $this->settings->string('php_cli');
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }
        $candidates = [PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php', '/opt/homebrew/bin/php'];
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $candidates[] = $dir . '/php';
            }
        }
        foreach ($candidates as $c) {
            if (is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }
}
