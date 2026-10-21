<?php
declare(strict_types=1);

namespace Wiki\Http;

/** Security headers and CSRF protection (stateless double-submit cookie + Origin check). */
final class Security
{
    public const CSRF_COOKIE = 'wiki_csrf';

    public static function isHttps(bool $trustProxy): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return $trustProxy && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** @return array<string,string> */
    public static function headers(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data: https:; "
                . "font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
    }

    /** Returns the token to embed in the form; sets the cookie when absent. */
    public static function issueCsrfToken(bool $secure): string
    {
        $existing = $_COOKIE[self::CSRF_COOKIE] ?? '';
        if (is_string($existing) && preg_match('/^[0-9a-f]{64}$/', $existing)) {
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        setcookie(self::CSRF_COOKIE, $token, [
            'expires' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict',
        ]);
        $_COOKIE[self::CSRF_COOKIE] = $token;
        return $token;
    }

    public static function validCsrf(string $submitted): bool
    {
        $cookie = $_COOKIE[self::CSRF_COOKIE] ?? '';
        return is_string($cookie) && preg_match('/^[0-9a-f]{64}$/', $cookie) === 1 && hash_equals($cookie, $submitted);
    }

    /** Same-origin check for state-changing requests. A missing Origin falls back to Referer, then to the CSRF token alone. */
    public static function sameOrigin(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
            $v = $_SERVER[$h] ?? '';
            if (is_string($v) && $v !== '') {
                $oh = parse_url($v, PHP_URL_HOST);
                $op = parse_url($v, PHP_URL_PORT);
                $originHost = strtolower((string) $oh) . ($op ? ':' . $op : '');
                return $oh !== null && $oh !== false && $originHost === $host;
            }
        }
        return true;
    }
}
