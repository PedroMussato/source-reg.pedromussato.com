<?php
declare(strict_types=1);

namespace Wiki\Routing;

use Wiki\Security\PathGuard;

/**
 * Resolves wiki URLs against ONE repository version (never the live pointer), so a request is
 * served entirely from the version it resolved at its start.
 *
 * Resolution rules (deterministic, documented in README):
 *   "/"             -> root README (or a helpful "no homepage" error)
 *   "/A/B"          -> A/B.md                      (page)
 *   "/A/B/"         -> A/B/README.md, else generated directory overview
 *   "/A/B" when only the directory A/B exists -> 301 to "/A/B/"
 *   "/A/B/" when only A/B.md exists           -> 301 to "/A/B"
 *   "/A/B.md", "/A/README(.md)"              -> 301 to the canonical URL
 *   a file A/B.md and a directory A/B/ may coexist: "/A/B" is the file, "/A/B/" the directory.
 */
final class VersionContent
{
    public function __construct(public readonly string $versionId, public readonly PathGuard $guard)
    {
    }

    /**
     * @param list<string> $segments
     * @return array{type:string,path?:string,location?:string,readme?:?string}
     */
    public function resolve(array $segments, bool $trailingSlash): array
    {
        if ($segments === []) {
            $readme = $this->findReadme('');
            return $readme === null ? ['type' => 'no_home'] : ['type' => 'page', 'path' => $readme];
        }
        if (in_array($segments[0], UrlCodec::RESERVED_ROOTS, true)) {
            return ['type' => 'notfound'];
        }
        $rel = implode('/', $segments);
        if (!PathGuard::validRelative($rel)) {
            return ['type' => 'notfound'];
        }
        $last = $segments[count($segments) - 1];

        if ($trailingSlash) {
            if ($this->guard->isDir($rel)) {
                return ['type' => 'directory', 'path' => $rel, 'readme' => $this->findReadme($rel)];
            }
            if ($this->guard->isFile($rel . '.md')) {
                return ['type' => 'redirect', 'location' => UrlCodec::pageUrl($rel . '.md')];
            }
            return ['type' => 'notfound'];
        }

        // Explicit .md extension in the URL -> canonical URL.
        if (str_ends_with($last, '.md') && $this->guard->isFile($rel)) {
            return ['type' => 'redirect', 'location' => UrlCodec::pageUrl($rel)];
        }
        // README (without extension) -> directory landing page.
        if ($last === 'README' || $last === 'readme' || $last === 'Readme') {
            $dir = implode('/', array_slice($segments, 0, -1));
            if ($this->findReadme($dir) !== null) {
                return ['type' => 'redirect', 'location' => UrlCodec::dirUrl($dir)];
            }
        }
        if ($this->guard->isFile($rel . '.md')) {
            return ['type' => 'page', 'path' => $rel . '.md'];
        }
        if ($this->guard->isDir($rel)) {
            return ['type' => 'redirect', 'location' => UrlCodec::dirUrl($rel)];
        }
        return ['type' => 'notfound'];
    }

    public function findReadme(string $relDir): ?string
    {
        foreach (UrlCodec::README_NAMES as $name) {
            $rel = $relDir === '' ? $name : $relDir . '/' . $name;
            if ($this->guard->isFile($rel)) {
                return $rel;
            }
        }
        return null;
    }
}
