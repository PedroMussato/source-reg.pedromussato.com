<?php
declare(strict_types=1);

namespace Wiki\Security;

use Wiki\Config\Settings;

/**
 * Validates and normalizes the repository URL BEFORE any git process is started.
 *
 * Policy (documented in README):
 *  - https:// only by default; ssh:// (or scp-like user@host:path) when `allow_ssh` is enabled.
 *  - no embedded credentials, no query string, no fragment, no whitespace/control characters.
 *  - hostname must resolve only to public addresses (SSRF guard) unless `allow_private_hosts`.
 *  - optional `allowed_hosts` allow-list (exact or *.suffix patterns).
 *  - local paths / file:// are rejected unless the administrator sets `allow_local_urls`
 *    (intended for automated tests only; not reachable through the web form).
 */
final class RepoUrlValidator
{
    public function __construct(private Settings $settings)
    {
    }

    /** @return array{url:string,host:string,scheme:string} */
    public function validate(string $raw, bool $resolveDns = true): array
    {
        $url = trim($raw);
        if ($url === '') {
            throw new ValidationException('Please enter a repository URL.');
        }
        if (strlen($url) > 2048) {
            throw new ValidationException('The repository URL is too long.');
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !mb_check_encoding($url, 'UTF-8')) {
            throw new ValidationException('The repository URL contains invalid characters.');
        }
        if ($url[0] === '-') {
            throw new ValidationException('The repository URL is not valid.');
        }

        if ($this->settings->bool('allow_local_urls') && (str_starts_with($url, 'file://') || str_starts_with($url, '/'))) {
            return ['url' => $url, 'host' => 'localhost', 'scheme' => 'file'];
        }

        // scp-like syntax: git@host:owner/repo.git
        if (preg_match('#^([A-Za-z0-9._-]+)@([A-Za-z0-9.-]+):(?!//)([A-Za-z0-9._~/-]+)$#', $url, $m)) {
            if (!$this->settings->bool('allow_ssh')) {
                throw new ValidationException('SSH repository URLs are not enabled on this server. Use an https:// URL.');
            }
            $url = 'ssh://' . $m[1] . '@' . $m[2] . '/' . ltrim($m[3], '/');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ValidationException('The repository URL is not valid. Use the form https://host/owner/repo.git');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'ssh') {
            if (!$this->settings->bool('allow_ssh')) {
                throw new ValidationException('SSH repository URLs are not enabled on this server. Use an https:// URL.');
            }
        } elseif ($scheme !== 'https') {
            throw new ValidationException('Only https:// repository URLs are supported.');
        }
        if (isset($parts['pass']) || ($scheme === 'https' && isset($parts['user']))) {
            throw new ValidationException('Do not put credentials in the URL. Configure server-side credentials instead.');
        }
        if ($scheme === 'ssh' && isset($parts['user']) && !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $parts['user'])) {
            throw new ValidationException('The repository URL is not valid.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new ValidationException('The repository URL must not contain a query string or fragment.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if (!$this->validHost($host)) {
            throw new ValidationException('The repository host name is not valid.');
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new ValidationException('The repository port is not valid.');
        }
        $path = $parts['path'] ?? '';
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $path = rtrim($path, '/');
        if ($path === '' || $path === '/') {
            throw new ValidationException('The repository URL needs a path such as /owner/repo.git');
        }
        if (!preg_match('#^/[A-Za-z0-9._~%+@/-]+$#', $path) || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            throw new ValidationException('The repository path contains invalid characters.');
        }

        $this->checkAllowedHosts($host);
        if ($resolveDns) {
            $this->checkNotInternal($host);
        }

        $authority = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ($port !== null ? ':' . $port : '');
        $normalized = $scheme . '://' . ($scheme === 'ssh' ? ($parts['user'] ?? 'git') . '@' : '') . $authority . $path;
        return ['url' => $normalized, 'host' => $host, 'scheme' => $scheme];
    }

    /** Branch / ref name validation (rules of git-check-ref-format that matter here). */
    public function validateBranch(string $branch): string
    {
        $branch = trim($branch);
        if (
            $branch === '' || strlen($branch) > 200
            || !preg_match('#^[A-Za-z0-9._/-]+$#', $branch)
            || str_contains($branch, '..') || str_starts_with($branch, '-') || str_starts_with($branch, '/')
            || str_ends_with($branch, '/') || str_ends_with($branch, '.') || str_ends_with($branch, '.lock')
            || str_contains($branch, '//') || str_contains($branch, '/.') || str_starts_with($branch, '.')
        ) {
            throw new ValidationException('The branch name is not valid.');
        }
        return $branch;
    }

    private function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        return strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1;
    }

    private function checkAllowedHosts(string $host): void
    {
        $allowed = $this->settings->list('allowed_hosts');
        if ($allowed === []) {
            return;
        }
        foreach ($allowed as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return;
            }
        }
        throw new ValidationException('This repository host is not permitted on this server.');
    }

    private function checkNotInternal(string $host): void
    {
        if ($this->settings->bool('allow_private_hosts')) {
            return;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new ValidationException('Internal host names are not permitted.');
        }
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            foreach ((array) @gethostbynamel($host) as $ip) {
                $addresses[] = $ip;
            }
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $rec) {
                if (isset($rec['ipv6'])) {
                    $addresses[] = $rec['ipv6'];
                }
            }
            if ($addresses === []) {
                throw new ValidationException('The repository host could not be resolved.');
            }
        }
        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ValidationException('Repositories on internal network addresses are not permitted.');
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {          // IPv4-mapped IPv6
            $ip = substr($ip, 7);
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !in_array($ip, ['::1', '::'], true)
            && !preg_match('/^(fe[89ab][0-9a-f]:|f[cd][0-9a-f]{2}:)/i', $ip)   // link-local / unique-local v6
            && !str_starts_with($ip, '169.254.') && !str_starts_with($ip, '100.64.');
    }
}
