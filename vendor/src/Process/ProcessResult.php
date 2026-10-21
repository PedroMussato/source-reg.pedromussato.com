<?php
declare(strict_types=1);

namespace Wiki\Process;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly bool $truncated,
        public readonly bool $started = true,
    ) {
    }

    public function ok(): bool
    {
        return $this->started && !$this->timedOut && $this->exitCode === 0;
    }
}
