<?php
declare(strict_types=1);

namespace Wiki\Config;

use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Support\Paths;
use Wiki\Support\Time;

/**
 * The immutable installation configuration: repository identity only.
 * Mutable synchronization state lives elsewhere (Sync\StateStore).
 *
 * The only code path that writes this file is {@see write()}, called by Sync\SetupService
 * under the setup lock. No other request handler can reach it.
 */
final class Installation
{
    public const SCHEMA_VERSION = 1;

    /** @var array<string,mixed>|null */
    private ?array $cached = null;

    public function __construct(private Paths $paths)
    {
    }

    public function status(): InstallationStatus
    {
        $file = $this->paths->installationFile();
        if (is_file($file)) {
            return $this->load() === null ? InstallationStatus::Corrupt : InstallationStatus::Installed;
        }
        // Config missing: either never installed, or it was deleted afterwards.
        // The installed flag (written after the config) tells them apart, so a damaged
        // installation can never be silently re-pointed at a different repository.
        return is_file($this->paths->installedFlag()) ? InstallationStatus::Corrupt : InstallationStatus::NotInstalled;
    }

    /** @return array<string,mixed>|null null when missing or invalid */
    public function load(): ?array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        try {
            $data = AtomicFile::readJson($this->paths->installationFile());
        } catch (CorruptFileException) {
            return null;
        }
        if ($data === null || !$this->valid($data)) {
            return null;
        }
        return $this->cached = $data;
    }

    /** @param array<string,mixed> $d */
    private function valid(array $d): bool
    {
        return ($d['schema_version'] ?? null) === self::SCHEMA_VERSION
            && is_string($d['installation_id'] ?? null) && preg_match('/^[0-9a-f]{16,64}$/', $d['installation_id']) === 1
            && is_string($d['repository_url'] ?? null) && $d['repository_url'] !== ''
            && is_string($d['ref'] ?? null) && preg_match('#^[A-Za-z0-9._/\-]{1,200}$#', $d['ref']) === 1
            && is_string($d['created_at'] ?? null) && Time::parse($d['created_at']) !== null;
    }

    public function repositoryUrl(): string
    {
        return (string) ($this->load()['repository_url'] ?? '');
    }

    public function ref(): string
    {
        return (string) ($this->load()['ref'] ?? '');
    }

    /** Writes the config exactly once. Refuses to overwrite. */
    public function write(string $repositoryUrl, string $ref, int $now): void
    {
        $file = $this->paths->installationFile();
        if (is_file($file)) {
            throw new \LogicException('Installation configuration already exists and is immutable');
        }
        $host = parse_url($repositoryUrl, PHP_URL_HOST);
        AtomicFile::writeJson($file, [
            'schema_version' => self::SCHEMA_VERSION,
            'installation_id' => bin2hex(random_bytes(16)),
            'repository_url' => $repositoryUrl,
            'ref' => $ref,
            'created_at' => Time::iso($now),
            'app_version' => WIKI_VERSION,
            'provider_host' => is_string($host) ? $host : null,
        ], 0440);
        $this->cached = null;
    }
}
