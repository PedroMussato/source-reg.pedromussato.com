<?php
declare(strict_types=1);

namespace Wiki\Support;

/** Deterministic clock for tests. */
final class FixedClock implements Clock
{
    public function __construct(private int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
