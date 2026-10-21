<?php
declare(strict_types=1);

namespace Wiki\Support;

/**
 * Central place for every filesystem location the application uses.
 * Nothing here is derived from request data.
 *
 * Environment overrides (server-side only): WIKI_ROOT, WIKI_STORAGE_DIR, WIKI_REPOS_DIR.
 */
final class Paths
{
    public readonly string $root;
    public readonly string $storage;
    public readonly string $config;
    public readonly string $state;
    public readonly string $locks;
    public readonly string $cache;
    public readonly string $logs;
    public readonly string $home;
    public readonly string $repos;
    public readonly string $versions;
    public readonly string $staging;
    public readonly string $repoCache;
    public readonly string $pointerFile;
    public readonly string $historyFile;

    public function __construct(?string $root = null)
    {
        $root ??= getenv('WIKI_ROOT') ?: dirname(__DIR__, 2);
        $this->root = rtrim($root, '/');
        $this->storage = rtrim(getenv('WIKI_STORAGE_DIR') ?: $this->root . '/storage', '/');
        $this->repos = rtrim(getenv('WIKI_REPOS_DIR') ?: $this->root . '/repositories', '/');
        $this->config = $this->storage . '/config';
        $this->state = $this->storage . '/state';
        $this->locks = $this->storage . '/locks';
        $this->cache = $this->storage . '/cache';
        $this->logs = $this->storage . '/logs';
        $this->home = $this->storage . '/home';
        $this->versions = $this->repos . '/versions';
        $this->staging = $this->repos . '/staging';
        $this->repoCache = $this->repos . '/repository-cache';
        $this->pointerFile = $this->repos . '/current.json';
        $this->historyFile = $this->repos . '/history.json';
    }

    public function installationFile(): string { return $this->config . '/installation.json'; }
    public function settingsFile(): string { return $this->config . '/settings.json'; }
    public function credentialsFile(): string { return $this->config . '/credentials.json'; }
    public function installedFlag(): string { return $this->state . '/installed.flag'; }
    public function stateFile(): string { return $this->state . '/sync-state.json'; }
    public function syncLock(): string { return $this->locks . '/sync.lock'; }
    public function setupLock(): string { return $this->locks . '/setup.lock'; }
    public function stateLock(): string { return $this->locks . '/state.lock'; }
    public function leaseFile(string $versionId): string { return $this->locks . '/lease-' . $versionId . '.lock'; }
    public function versionDir(string $versionId): string { return $this->versions . '/' . $versionId; }
    public function versionCache(string $versionId): string { return $this->cache . '/' . $versionId; }
    public function syncLog(): string { return $this->logs . '/sync-process.log'; }
    public function appLog(): string { return $this->logs . '/wiki.log'; }

    /** Create the writable directory skeleton (idempotent). */
    public function ensureDirectories(): void
    {
        foreach ([
            $this->config, $this->state, $this->locks, $this->cache, $this->logs, $this->home,
            $this->repos, $this->versions, $this->staging,
        ] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create required directory');
            }
        }
    }
}
