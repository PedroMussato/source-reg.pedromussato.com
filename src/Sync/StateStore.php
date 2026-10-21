<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Filesystem\FileLock;
use Wiki\Support\Paths;

/**
 * Informational synchronization state (JSON). The OS lock, not this file, is authoritative
 * for "is a sync running". Read-modify-write cycles are serialized by a short state lock and
 * written atomically.
 */
final class StateStore
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private Paths $paths)
    {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'idle',                 // idle | launching | running | failed
            'active_version' => null,
            'active_commit' => null,
            'last_success_at' => null,
            'last_success_commit' => null,
            'last_attempt_at' => null,
            'last_attempt_commit' => null,
            'last_activation_at' => null,
            'candidate_version' => null,
            'sync_started_at' => null,
            'last_error_category' => null,
            'last_error' => null,
            'retry_after' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        $file = $this->paths->stateFile();
        try {
            $data = AtomicFile::readJson($file);
        } catch (CorruptFileException) {
            // Keep the damaged copy for diagnosis, continue from defaults. Version pointer is
            // authoritative for what is served, so nothing visible changes.
            @rename($file, $file . '.corrupt-' . date('YmdHis'));
            $data = null;
        }
        $state = self::defaults();
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (array_key_exists($k, $state)) {
                    $state[$k] = $v;
                }
            }
        }
        return $state;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     * @return array<string,mixed> the new state
     */
    public function update(callable $mutator): array
    {
        $lock = FileLock::exclusiveWithTimeout($this->paths->stateLock(), 5.0);
        if ($lock === null) {
            throw new \RuntimeException('State lock timeout');
        }
        try {
            $state = $mutator($this->read());
            $state['schema_version'] = self::SCHEMA_VERSION;
            AtomicFile::writeJson($this->paths->stateFile(), $state);
            return $state;
        } finally {
            $lock->release();
        }
    }
}
