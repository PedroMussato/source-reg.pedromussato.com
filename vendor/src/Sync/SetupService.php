<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Config\Installation;
use Wiki\Config\InstallationStatus;
use Wiki\Config\Settings;
use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\FileLock;
use Wiki\Filesystem\Fs;
use Wiki\Git\GitClient;
use Wiki\Git\GitException;
use Wiki\Security\RepoUrlValidator;
use Wiki\Security\ValidationException;
use Wiki\Support\Clock;
use Wiki\Support\Logger;
use Wiki\Support\Paths;
use Wiki\Support\Redactor;
use Wiki\Support\Time;

/**
 * One-time installation (spec section 5.3).
 *
 * Order of persistence is chosen so a crash at ANY point leaves "not installed":
 *   clone -> validate -> publish version -> pointer -> sync state -> installation.json -> installed.flag
 * installation.json is written only after everything else succeeded. A half-finished setup is
 * discarded (resetPartial) the next time setup runs, under the setup lock.
 */
final class SetupService
{
    public function __construct(
        private Paths $paths,
        private Settings $settings,
        private Clock $clock,
        private Logger $logger,
        private Installation $installation,
        private StateStore $state,
        private VersionStore $versions,
        private GitClient $git,
        private CandidateBuilder $builder,
        private RepoUrlValidator $validator,
    ) {
    }

    /** @throws SetupException */
    public function install(string $rawUrl, string $rawBranch = ''): void
    {
        $this->paths->ensureDirectories();
        $lock = FileLock::tryExclusive($this->paths->setupLock());
        if ($lock === null) {
            throw new SetupException('Setup is already in progress. Please wait a moment and reload.', 409);
        }
        try {
            // Re-check under the lock: a hidden form or disabled button is not a security control.
            $status = $this->installation->status();
            if ($status === InstallationStatus::Installed) {
                throw new SetupException('This wiki has already been set up. The repository cannot be changed.', 409);
            }
            if ($status === InstallationStatus::Corrupt) {
                throw new SetupException('The installation needs recovery by the server administrator.', 503);
            }

            try {
                $valid = $this->validator->validate($rawUrl);
                $branch = trim($rawBranch) === '' ? '' : $this->validator->validateBranch($rawBranch);
            } catch (ValidationException $e) {
                throw new SetupException($e->getMessage(), 422);
            }

            @set_time_limit($this->settings->int('sync_timeout_seconds'));
            $this->resetPartial();
            $this->logger->info('setup_started', ['host' => $valid['host']]);
            $timeout = $this->settings->int('git_op_timeout_seconds');
            try {
                if (!$this->git->available()) {
                    throw new GitException('git_missing', GitException::safeMessageFor('git_missing'));
                }
                if ($branch === '') {
                    $branch = $this->git->defaultBranch($valid['url'], $timeout);
                    $branch = $this->validator->validateBranch($branch);
                }
                $this->git->resetCache();
                $sha = $this->git->fetch($valid['url'], $branch, $timeout);
                $this->builder->build($sha, 'setup-' . bin2hex(random_bytes(4)), $timeout);
                $this->versions->activate($sha, $sha);
                $now = $this->clock->now();
                $s = StateStore::defaults();
                $s['active_version'] = $sha;
                $s['active_commit'] = $sha;
                $s['last_success_at'] = Time::iso($now);       // the initial clone counts as a successful sync
                $s['last_success_commit'] = $sha;
                $s['last_attempt_at'] = Time::iso($now);
                $s['last_attempt_commit'] = $sha;
                $s['last_activation_at'] = Time::iso($now);
                AtomicFile::writeJson($this->paths->stateFile(), $s);
                $this->installation->write($valid['url'], $branch, $now);     // identity becomes permanent here
                AtomicFile::writeText($this->paths->installedFlag(), Time::iso($now) . "\n", 0640);
                $this->logger->info('setup_completed', ['commit' => $sha, 'branch' => $branch]);
            } catch (\Throwable $e) {
                $this->resetPartial();
                $this->logger->error('setup_failed', ['error' => Redactor::redact($e->getMessage()), 'detail' => $e instanceof GitException ? $e->detail : '']);
                throw $this->userFacing($e);
            }
        } finally {
            $lock->release();
        }
    }

    private function userFacing(\Throwable $e): SetupException
    {
        if ($e instanceof SetupException) {
            return $e;
        }
        if ($e instanceof GitException) {
            $hint = match ($e->category) {
                'auth' => ' For private repositories, configure server-side credentials (see the README).',
                'missing_ref' => ' Check the branch name or leave it empty to use the default branch.',
                default => '',
            };
            return new SetupException($e->getMessage() . $hint, 422);
        }
        if ($e instanceof SyncException) {
            return new SetupException($e->getMessage(), 422);
        }
        [$category, $message] = Synchronizer::describe($e);
        return new SetupException($message, 500);
    }

    /**
     * Discard artifacts of an unfinished installation. Refuses to run on a completed one.
     */
    private function resetPartial(): void
    {
        if ($this->installation->status() !== InstallationStatus::NotInstalled) {
            throw new \LogicException('Refusing to reset an installed wiki');
        }
        foreach (Fs::children($this->paths->versions) as $v) {
            Fs::removeTree($this->paths->versions . '/' . $v);
        }
        foreach (Fs::children($this->paths->staging) as $v) {
            Fs::removeTree($this->paths->staging . '/' . $v);
        }
        foreach (Fs::children($this->paths->cache) as $v) {
            Fs::removeTree($this->paths->cache . '/' . $v);
        }
        foreach (Fs::children($this->paths->locks) as $f) {
            if (str_starts_with($f, 'lease-')) {
                @unlink($this->paths->locks . '/' . $f);
            }
        }
        @unlink($this->paths->pointerFile);
        @unlink($this->paths->historyFile);
        @unlink($this->paths->stateFile());
        $this->git->resetCache();
    }
}
