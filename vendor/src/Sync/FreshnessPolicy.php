<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Support\Time;

/** Pure decision logic for "should a visitor request start a synchronization now?". */
final class FreshnessPolicy
{
    /** Tolerated forward clock skew before a "future" success timestamp is distrusted. */
    public const SKEW_TOLERANCE = 300;

    /**
     * @param array<string,mixed> $state
     * @return array{eligible:bool,reason:string}
     */
    public static function evaluate(array $state, int $now, int $intervalSeconds): array
    {
        $retryAfter = Time::parse($state['retry_after'] ?? null);
        if ($retryAfter !== null && $now < $retryAfter) {
            return ['eligible' => false, 'reason' => 'cooldown'];
        }
        $last = Time::parse($state['last_success_at'] ?? null);
        if ($last === null) {
            return ['eligible' => true, 'reason' => 'no_success_recorded'];
        }
        if ($last > $now + self::SKEW_TOLERANCE) {
            // The clock moved backwards (or the timestamp is bogus). Never stall forever.
            return ['eligible' => true, 'reason' => 'clock_skew'];
        }
        $elapsed = $now - $last;
        if ($elapsed >= $intervalSeconds) {          // exactly 24h old => eligible
            return ['eligible' => true, 'reason' => 'stale'];
        }
        return ['eligible' => false, 'reason' => 'fresh'];
    }
}
