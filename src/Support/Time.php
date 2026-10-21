<?php
declare(strict_types=1);

namespace Wiki\Support;

/** UTC timestamp helpers. State files store ISO 8601 UTC strings (YYYY-MM-DDTHH:MM:SSZ). */
final class Time
{
    public static function iso(int $epoch): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $epoch);
    }

    public static function parse(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            return null;
        }
        $t = strtotime($value);
        return $t === false ? null : $t;
    }
}
