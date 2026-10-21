<?php
declare(strict_types=1);

namespace Wiki\Routing;

/**
 * The single URL encoding strategy:
 *   - repository path segments are UTF-8 and percent-encoded with rawurlencode() per segment;
 *   - "/" separates segments, directories get a trailing slash;
 *   - README.md is the landing page of its directory ("/Guides/README.md" => "/Guides/").
 *   - non-README pages drop the ".md" extension ("/Guides/Installation").
 */
final class UrlCodec
{
    public const README_NAMES = ['README.md', 'readme.md', 'Readme.md'];
    public const RESERVED_ROOTS = ['_wiki', '_setup', '_health'];

    public static function isReadme(string $basename): bool
    {
        return in_array($basename, self::README_NAMES, true);
    }

    public static function encodePath(string $rel): string
    {
        if ($rel === '') {
            return '';
        }
        return implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    /** @param string $relMd repository path of a Markdown file */
    public static function pageUrl(string $relMd): string
    {
        $dir = dirname($relMd);
        $base = basename($relMd);
        $dir = $dir === '.' ? '' : $dir;
        if (self::isReadme($base)) {
            return self::dirUrl($dir);
        }
        $name = substr($base, 0, -3);
        return '/' . self::encodePath(($dir === '' ? '' : $dir . '/') . $name);
    }

    public static function dirUrl(string $relDir): string
    {
        return $relDir === '' ? '/' : '/' . self::encodePath($relDir) . '/';
    }

    public static function assetUrl(string $rel, string $versionId): string
    {
        return '/_wiki/asset/' . self::encodePath($rel) . '?v=' . substr($versionId, 0, 12);
    }

    /**
     * Decode the path component of a request target.
     * Every segment is decoded exactly once; anything that still looks percent-encoded in a
     * dangerous way afterwards (double encoding) is rejected.
     *
     * @return array{segments:list<string>,trailing:bool}|null null = reject (400/404)
     */
    public static function decodeRequestPath(string $requestUri): ?array
    {
        $path = explode('?', $requestUri, 2)[0];
        $path = explode('#', $path, 2)[0];
        if ($path === '' || $path[0] !== '/') {
            return null;
        }
        if (strlen($path) > 2048 || str_contains($path, '//')) {
            return null;                           // empty segments are never valid wiki paths
        }
        $trailing = str_ends_with($path, '/') && $path !== '/';
        $raw = explode('/', trim($path, '/'));
        if ($path === '/') {
            return ['segments' => [], 'trailing' => false];
        }
        $segments = [];
        foreach ($raw as $seg) {
            if ($seg === '') {
                return null;                       // "//" is not a valid wiki path
            }
            $decoded = rawurldecode($seg);
            if (
                !mb_check_encoding($decoded, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $decoded)
                || $decoded === '.' || $decoded === '..'
                || preg_match('/%(?:2e|2f|5c|00|25)/i', $decoded)   // double-encoded . / \ NUL %
            ) {
                return null;
            }
            $segments[] = $decoded;
        }
        return ['segments' => $segments, 'trailing' => $trailing];
    }
}
