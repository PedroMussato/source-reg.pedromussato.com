<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Filesystem\FileLock;
use Wiki\Filesystem\Fs;
use Wiki\Support\Clock;
use Wiki\Support\Paths;
use Wiki\Support\Time;

/**
 * Immutable repository versions + the atomic "active" pointer.
 *
 * Pointer: repositories/current.json, replaced with write-temp-then-rename (atomic on POSIX and
 * independent of symlink support). Versions: repositories/versions/<commit sha>/.
 * Readers resolve the pointer once per request and hold a shared lease on that version, so
 * cleanup can never delete a version that is being served.
 */
final class VersionStore
{
    public const ID_PATTERN = '/^[0-9a-f]{40}([0-9a-f]{24})?$/';

    public function __construct(private Paths $paths, private Clock $clock)
    {
    }

    public static function validId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** @return array{version:string,commit:string,activated_at:string}|null */
    public function pointer(): ?array
    {
        try {
            $p = AtomicFile::readJson($this->paths->pointerFile);
        } catch (CorruptFileException) {
            return null;
        }
        if (!is_array($p) || !is_string($p['version'] ?? null) || !self::validId($p['version'])) {
            return null;
        }
        return ['version' => $p['version'], 'commit' => (string) ($p['commit'] ?? $p['version']), 'activated_at' => (string) ($p['activated_at'] ?? '')];
    }

    public function exists(string $id): bool
    {
        return self::validId($id) && is_dir($this->paths->versionDir($id));
    }

    /** Active version id whose directory exists, or null. */
    public function activeId(): ?string
    {
        $p = $this->pointer();
        return $p !== null && $this->exists($p['version']) ? $p['version'] : null;
    }

    /** @return list<array{id:string,commit:string,activated_at:string}> newest first */
    public function history(): array
    {
        try {
            $h = AtomicFile::readJson($this->paths->historyFile);
        } catch (CorruptFileException) {
            $h = null;
        }
        $out = [];
        foreach ((array) ($h['versions'] ?? []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && self::validId($row['id'])) {
                $out[] = ['id' => $row['id'], 'commit' => (string) ($row['commit'] ?? $row['id']), 'activated_at' => (string) ($row['activated_at'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * Atomically make an existing, fully prepared version the active one.
     * Caller MUST hold the synchronization lock.
     */
    public function activate(string $id, string $commit): void
    {
        if (!$this->exists($id)) {
            throw new \RuntimeException('Cannot activate a version that does not exist');
        }
        $now = Time::iso($this->clock->now());
        $history = array_values(array_filter($this->history(), static fn ($r) => $r['id'] !== $id));
        array_unshift($history, ['id' => $id, 'commit' => $commit, 'activated_at' => $now]);
        $history = array_slice($history, 0, 50);
        // Order matters: history first (informational), pointer last (the atomic switch).
        AtomicFile::writeJson($this->paths->historyFile, ['versions' => $history]);
        AtomicFile::writeJson($this->paths->pointerFile, ['version' => $id, 'commit' => $commit, 'activated_at' => $now]);
    }

    /** Shared lease held for the duration of a request that serves this version. */
    public function lease(string $id): ?FileLock
    {
        if (!self::validId($id)) {
            return null;
        }
        try {
            return FileLock::tryShared($this->paths->leaseFile($id));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> ids present on disk */
    public function onDisk(): array
    {
        return array_values(array_filter(Fs::children($this->paths->versions), static fn ($n) => self::validId($n)));
    }

    /**
     * Retention: keep the active version and the previous N activated ones. A version is deleted
     * only when nobody holds a lease on it (exclusive non-blocking lock succeeds).
     *
     * @return list<string> deleted ids
     */
    public function cleanup(int $keepPrevious): array
    {
        $active = $this->pointer()['version'] ?? null;
        $keep = [];
        if ($active !== null) {
            $keep[$active] = true;
        }
        $kept = 0;
        foreach ($this->history() as $row) {
            if ($row['id'] === $active) {
                continue;
            }
            if ($kept < $keepPrevious && $this->exists($row['id'])) {
                $keep[$row['id']] = true;
                $kept++;
            }
        }
        if ($active === null) {
            return [];   // never delete anything when we cannot tell what is active
        }
        $deleted = [];
        foreach ($this->onDisk() as $id) {
            if (isset($keep[$id])) {
                continue;
            }
            $lease = FileLock::tryExclusive($this->paths->leaseFile($id));
            if ($lease === null) {
                continue;   // in use by an in-flight request
            }
            try {
                Fs::removeTree($this->paths->versionDir($id));
                Fs::removeTree($this->paths->versionCache($id));
                $deleted[] = $id;
            } finally {
                $lease->release();
                @unlink($this->paths->leaseFile($id));
            }
        }
        // Drop history rows for versions that no longer exist.
        $rows = array_values(array_filter($this->history(), fn ($r) => $this->exists($r['id'])));
        AtomicFile::writeJson($this->paths->historyFile, ['versions' => $rows]);
        return $deleted;
    }

    /** Remove every abandoned staging artifact. Caller must hold the sync (or setup) lock. */
    public function cleanStaging(): int
    {
        $n = 0;
        foreach (Fs::children($this->paths->staging) as $name) {
            Fs::removeTree($this->paths->staging . '/' . $name);
            $n++;
        }
        return $n;
    }

    /**
     * Pointer references a missing/invalid version: restore the newest retained version that
     * still exists. Caller must hold the sync lock. Returns the restored id or null.
     */
    public function restoreFromHistory(): ?string
    {
        foreach ($this->history() as $row) {
            if ($this->exists($row['id']) && $this->looksComplete($row['id'])) {
                $this->activate($row['id'], $row['commit']);
                return $row['id'];
            }
        }
        // History may be lost as well: fall back to the newest complete version on disk.
        $best = null;
        $bestTime = -1;
        foreach ($this->onDisk() as $id) {
            $t = (int) @filemtime($this->paths->versionDir($id));
            if ($t > $bestTime && $this->looksComplete($id)) {
                $best = $id;
                $bestTime = $t;
            }
        }
        if ($best !== null) {
            $this->activate($best, $best);
        }
        return $best;
    }

    private function looksComplete(string $id): bool
    {
        return is_dir($this->paths->versionDir($id)) && is_readable($this->paths->versionDir($id));
    }
}
