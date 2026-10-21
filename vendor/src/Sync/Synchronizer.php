<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Config\Installation;
use Wiki\Config\Settings;
use Wiki\Git\GitClient;
use Wiki\Git\GitException;
use Wiki\Support\Clock;
use Wiki\Support\Logger;
use Wiki\Support\Redactor;
use Wiki\Support\Time;

/**
 * The synchronization workflow. The CALLER must already hold the synchronization lock.
 *
 *   1 record attempt            8 build candidate in staging (checkout, validate, caches)
 *   2 clean abandoned staging   9 atomically activate (pointer switch)
 *   3 load immutable config    10 record success (timestamp advances only now)
 *   4 fetch configured branch  11 retention cleanup
 *   5 resolve target commit    on any failure: active version + last_success_at are untouched,
 *   6 compare with active          failure + retry cooldown are recorded.
 *   7 no change => success (timestamp advances: "successful check")
 */
final class Synchronizer
{
    public function __construct(
        private Settings $settings,
        private Clock $clock,
        private Logger $logger,
        private Installation $installation,
        private GitClient $git,
        private StateStore $state,
        private VersionStore $versions,
        private CandidateBuilder $builder,
    ) {
    }

    public function run(): SyncResult
    {
        $started = $this->clock->now();
        $deadline = $started + $this->settings->int('sync_timeout_seconds');
        $runId = 'run-' . bin2hex(random_bytes(6));
        $this->logger->info('sync_started', ['run' => $runId]);
        $this->state->update(function (array $s) use ($started): array {
            $s['status'] = 'running';
            $s['last_attempt_at'] = Time::iso($started);
            $s['sync_started_at'] = Time::iso($started);
            $s['candidate_version'] = null;
            $s['last_attempt_commit'] = null;
            return $s;
        });

        try {
            $config = $this->installation->load();
            if ($config === null) {
                throw new SyncException('config', 'The installation configuration is missing or invalid.');
            }
            $this->versions->cleanStaging();                 // we hold the lock: every staging dir is abandoned

            $sha = $this->git->fetch((string) $config['repository_url'], (string) $config['ref'], $this->gitTimeout($deadline));
            $this->state->update(function (array $s) use ($sha): array {
                $s['last_attempt_commit'] = $sha;
                return $s;
            });

            $pointer = $this->versions->pointer();
            $status = 'unchanged';
            if ($pointer === null || $pointer['version'] !== $sha || !$this->versions->exists($sha)) {
                $this->state->update(function (array $s) use ($sha): array {
                    $s['candidate_version'] = $sha;
                    return $s;
                });
                $stats = $this->builder->build($sha, $runId, $this->gitTimeout($deadline));
                $this->versions->activate($sha, $sha);        // <- the single atomic switch
                $status = 'updated';
                $this->logger->info('active_version_changed', ['version' => $sha, 'previous' => $pointer['version'] ?? null] + $stats);
            }

            $now = $this->clock->now();
            $this->state->update(function (array $s) use ($sha, $now, $status): array {
                $s['status'] = 'idle';
                $s['active_version'] = $sha;
                $s['active_commit'] = $sha;
                if ($status === 'updated') {
                    $s['last_activation_at'] = Time::iso($now);
                }
                $s['last_success_at'] = Time::iso($now);
                $s['last_success_commit'] = $sha;
                $s['candidate_version'] = null;
                $s['last_error_category'] = null;
                $s['last_error'] = null;
                $s['retry_after'] = null;
                return $s;
            });
            $this->logger->info('sync_succeeded', ['commit' => $sha, 'result' => $status]);

            // Post-success housekeeping must never turn a success into a failure.
            try {
                $deleted = $this->versions->cleanup($this->settings->int('retain_previous_versions'));
                if ($deleted !== []) {
                    $this->logger->info('old_versions_removed', ['count' => count($deleted)]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('cleanup_failed', ['error' => $e->getMessage()]);
            }
            return new SyncResult($status, $sha);
        } catch (\Throwable $e) {
            return $this->fail($e);
        } finally {
            try {
                $this->versions->cleanStaging();
            } catch (\Throwable) {
            }
        }
    }

    private function gitTimeout(int $deadline): int
    {
        $remaining = $deadline - $this->clock->now();
        if ($remaining <= 5) {
            throw new SyncException('timeout', 'The synchronization exceeded its permitted runtime.');
        }
        return min($this->settings->int('git_op_timeout_seconds'), $remaining);
    }

    private function fail(\Throwable $e): SyncResult
    {
        [$category, $message, $detail] = self::describe($e);
        $now = $this->clock->now();
        $cooldown = $category === 'config'
            ? $this->settings->int('sync_interval_seconds')    // configuration errors are not hammered
            : $this->settings->int('retry_cooldown_seconds');
        try {
            $this->state->update(function (array $s) use ($category, $message, $now, $cooldown): array {
                $s['status'] = 'failed';
                $s['candidate_version'] = null;
                $s['last_error_category'] = $category;
                $s['last_error'] = $message;
                $s['retry_after'] = Time::iso($now + $cooldown);
                return $s;                                      // last_success_at / active_* untouched
            });
        } catch (\Throwable $stateError) {
            $this->logger->error('state_write_failed', ['error' => $stateError->getMessage()]);
        }
        $this->logger->error('sync_failed', ['category' => $category, 'message' => $message, 'detail' => $detail]);
        return new SyncResult('failed', null, $category, $message);
    }

    /** @return array{0:string,1:string,2:string} */
    public static function describe(\Throwable $e): array
    {
        if ($e instanceof SyncException) {
            return [$e->category, $e->getMessage(), $e->detail];
        }
        if ($e instanceof GitException) {
            return [$e->category, $e->getMessage(), $e->detail];
        }
        $raw = Redactor::redact($e->getMessage());
        if (stripos($raw, 'space') !== false || stripos($raw, 'disk') !== false || stripos($raw, 'write failed') !== false) {
            return ['disk_space', 'The server ran out of disk space.', $raw];
        }
        return ['internal', 'An unexpected error occurred during synchronization.', get_class($e) . ': ' . $raw];
    }
}
