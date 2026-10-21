<?php
declare(strict_types=1);

namespace Wiki\Support;

/** Removes credentials from any string before it is logged, stored or displayed. */
final class Redactor
{
    /** @var list<string> */
    private static array $secrets = [];

    public static function addSecret(string $secret): void
    {
        if (strlen($secret) >= 4 && !in_array($secret, self::$secrets, true)) {
            self::$secrets[] = $secret;
        }
    }

    public static function redact(string $text): string
    {
        $text = preg_replace('#([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1***@', $text) ?? $text;
        $text = preg_replace('/(authorization:\s*(?:basic|bearer)\s+)\S+/i', '$1***', $text) ?? $text;
        foreach (self::$secrets as $secret) {
            $text = str_replace($secret, '***', $text);
            $text = str_replace(base64_encode($secret), '***', $text);
        }
        return $text;
    }
}
