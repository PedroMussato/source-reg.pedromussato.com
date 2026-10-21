<?php
declare(strict_types=1);

namespace Wiki\Sync;

final class SyncResult
{
    public function __construct(
        public readonly string $status,        // updated | unchanged | failed
        public readonly ?string $commit = null,
        public readonly ?string $category = null,
        public readonly ?string $message = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status !== 'failed';
    }
}
