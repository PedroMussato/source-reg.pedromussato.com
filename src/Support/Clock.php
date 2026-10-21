<?php
declare(strict_types=1);

namespace Wiki\Support;

/** Injectable clock; all freshness logic uses UTC epoch seconds from this interface. */
interface Clock
{
    public function now(): int;
}
