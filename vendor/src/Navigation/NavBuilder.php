<?php
declare(strict_types=1);

namespace Wiki\Navigation;

use Wiki\Config\Settings;
use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Filesystem\Fs;
use Wiki\Routing\UrlCodec;
use Wiki\Security\PathGuard;
use Wiki\Support\Paths;

/**
 * Builds the sidebar tree from the filesystem of ONE repository version.
 *
 * Sorting: directories first, then natural case-insensitive order (strnatcasecmp), then a bytewise
 * comparison as deterministic tie-break. Directory enumeration order of the OS is never used.
 * Directories without any Markdown page (recursively) are not listed; non-Markdown files are never
 * listed. Symlinked directories are not followed.
 *
 * Node shapes:
 *   dir:  {type:"dir", name, path, url, landing:bool, children:[...]}
 *   page: {type:"page", name, path, url}
 */
final class NavBuilder
{
    public const CACHE_VERSION = 2;

    public function __construct(private Paths $paths, private Settings $settings)
    {
    }

    /**
     * @return array{tree:list<array<string,mixed>>,pages:list<array{path:string,url:string,name:string}>,truncated:bool,root_readme:?string}
     */
    public function forVersion(string $versionId, PathGuard $guard): array
    {
        $file = $this->paths->versionCache($versionId) . '/nav.json';
        try {
            $cached = AtomicFile::readJson($file);
        } catch (CorruptFileException) {
            $cached = null;   // corrupt cache: discard and rebuild, source is untouched
        }
        if (is_array($cached) && ($cached['cache_version'] ?? null) === self::CACHE_VERSION && ($cached['version'] ?? null) === $versionId) {
            return $cached['data'];
        }
        $data = $this->build($guard);
        try {
            AtomicFile::writeJson($file, ['cache_version' => self::CACHE_VERSION, 'version' => $versionId, 'data' => $data]);
        } catch (\Throwable) {
            // caches are disposable; serving continues without one
        }
        return $data;
    }

    /** @return array{tree:list<array<string,mixed>>,pages:list<array{path:string,url:string,name:string}>,truncated:bool,root_readme:?string} */
    public function build(PathGuard $guard): array
    {
        $state = ['count' => 0, 'truncated' => false, 'pages' => []];
        $rootReadme = null;
        foreach (UrlCodec::README_NAMES as $n) {
            if (is_file($guard->root . '/' . $n) && !$guard->ignoreRules()->excluded($n, false)) {
                $rootReadme = $n;
                break;
            }
        }
        $tree = $this->scan($guard, '', 0, $state);
        if ($rootReadme !== null) {
            array_unshift($state['pages'], ['path' => $rootReadme, 'url' => '/', 'name' => 'Home']);
        }
        return ['tree' => $tree, 'pages' => $state['pages'], 'truncated' => $state['truncated'], 'root_readme' => $rootReadme];
    }

    /**
     * @param array{count:int,truncated:bool,pages:list<array{path:string,url:string,name:string}>} $state
     * @return list<array<string,mixed>>
     */
    private function scan(PathGuard $guard, string $rel, int $depth, array &$state): array
    {
        if ($depth > $this->settings->int('max_directory_depth')) {
            $state['truncated'] = true;
            return [];
        }
        $abs = $rel === '' ? $guard->root : $guard->root . '/' . $rel;
        $dirs = [];
        $pages = [];
        $landing = false;
        foreach (Fs::children($abs) as $name) {
            if ($state['count']++ > $this->settings->int('max_files')) {
                $state['truncated'] = true;
                break;
            }
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            $childAbs = $abs . '/' . $name;
            $isLink = is_link($childAbs);
            if ($isLink) {
                $real = @realpath($childAbs);
                if ($real === false || !$guard->contains($real) || is_dir($real)) {
                    continue;   // dangling, escaping, or a directory symlink (not followed)
                }
                $isDir = false;
            } else {
                $isDir = is_dir($childAbs);
            }
            if ($guard->ignoreRules()->excluded($childRel, $isDir)) {
                continue;
            }
            if ($isDir) {
                $children = $this->scan($guard, $childRel, $depth + 1, $state);
                $hasLanding = $this->hasReadme($guard, $childRel);
                if ($children !== [] || $hasLanding) {
                    $dirs[] = ['type' => 'dir', 'name' => $name, 'path' => $childRel, 'url' => UrlCodec::dirUrl($childRel), 'landing' => $hasLanding, 'children' => $children];
                }
            } elseif (str_ends_with($name, '.md')) {
                if (UrlCodec::isReadme($name)) {
                    if ($rel !== '') {
                        $landing = true;
                        $state['pages'][] = ['path' => $childRel, 'url' => UrlCodec::dirUrl($rel), 'name' => basename($rel)];
                    }
                    continue;
                }
                $page = ['type' => 'page', 'name' => substr($name, 0, -3), 'path' => $childRel, 'url' => UrlCodec::pageUrl($childRel)];
                $pages[] = $page;
                $state['pages'][] = ['path' => $childRel, 'url' => $page['url'], 'name' => $page['name']];
            }
        }
        $sorter = static function (array $a, array $b): int {
            $c = strnatcasecmp($a['name'], $b['name']);
            return $c !== 0 ? $c : strcmp($a['name'], $b['name']);
        };
        usort($dirs, $sorter);
        usort($pages, $sorter);
        return array_merge($dirs, $pages);
    }

    private function hasReadme(PathGuard $guard, string $relDir): bool
    {
        foreach (UrlCodec::README_NAMES as $n) {
            $p = $guard->root . '/' . $relDir . '/' . $n;
            if (is_file($p) && !$guard->ignoreRules()->excluded($relDir . '/' . $n, false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>|null children of the directory at $relDir ("" = root)
     */
    public static function childrenOf(array $tree, string $relDir): ?array
    {
        if ($relDir === '') {
            return $tree;
        }
        foreach ($tree as $node) {
            if ($node['type'] !== 'dir') {
                continue;
            }
            if ($node['path'] === $relDir) {
                return $node['children'];
            }
            if (str_starts_with($relDir, $node['path'] . '/')) {
                return self::childrenOf($node['children'], $relDir);
            }
        }
        return null;
    }
}
