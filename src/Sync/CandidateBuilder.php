<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Config\Settings;
use Wiki\Filesystem\Fs;
use Wiki\Git\GitClient;
use Wiki\Navigation\NavBuilder;
use Wiki\Search\SearchIndex;
use Wiki\Security\PathGuard;
use Wiki\Security\ValidationException;
use Wiki\Support\Logger;
use Wiki\Support\Paths;

/**
 * Prepares a complete, validated, immutable version directory from a commit WITHOUT touching the
 * active version: checkout in staging -> validate -> build navigation + search caches ->
 * atomic rename into repositories/versions/<sha>.
 */
final class CandidateBuilder
{
    public function __construct(
        private Paths $paths,
        private Settings $settings,
        private Logger $logger,
        private GitClient $git,
        private VersionStore $versions,
        private CandidateValidator $validator,
        private NavBuilder $nav,
        private SearchIndex $search,
    ) {
    }

    /** @return array{reused:bool,files:int,markdown:int,removed_symlinks:int} */
    public function build(string $sha, string $runId, int $gitTimeout): array
    {
        if ($this->versions->exists($sha)) {
            return ['reused' => true, 'files' => 0, 'markdown' => 0, 'removed_symlinks' => 0];
        }
        $free = Fs::freeBytes($this->paths->repos);
        if ($free !== null && $free < $this->settings->int('min_free_bytes')) {
            throw new SyncException('disk_space', 'Not enough free disk space to prepare a new version.');
        }
        $work = $this->paths->staging . '/' . $runId;
        $tree = $work . '/tree';
        try {
            if (!@mkdir($work, 0750, true) && !is_dir($work)) {
                throw new SyncException('permission', 'The server cannot create staging directories.');
            }
            $this->git->checkout($sha, $tree, $gitTimeout);
            try {
                $stats = $this->validator->validate($tree);
            } catch (ValidationException $e) {
                throw new SyncException('validation', $e->getMessage());
            }
            $this->prepareCaches($sha, $tree);
            if (is_dir($this->paths->versionDir($sha))) {        // lost a race with ourselves; nothing to do
                return ['reused' => true] + $stats;
            }
            if (!@rename($tree, $this->paths->versionDir($sha))) {
                throw new SyncException('permission', 'The new version could not be published.');
            }
            return ['reused' => false] + $stats;
        } finally {
            Fs::removeTree($work);
        }
    }

    /** Version-dependent caches are generated BEFORE activation; failures are non-fatal. */
    private function prepareCaches(string $sha, string $tree): void
    {
        try {
            Fs::removeTree($this->paths->versionCache($sha));
            $guard = new PathGuard($tree);
            $nav = $this->nav->forVersion($sha, $guard);
            try {
                $this->search->build($sha, $guard, $nav['pages']);
            } catch (\Throwable $e) {
                $this->logger->warning('search_index_failed', ['version' => $sha, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('cache_generation_failed', ['version' => $sha, 'error' => $e->getMessage()]);
        }
    }
}
