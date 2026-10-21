<?php
declare(strict_types=1);

namespace Wiki\Security;

/**
 * Confines every page and asset lookup to one repository version root.
 *
 * Defence in depth:
 *  1. syntactic validation of the relative path (no NUL/control chars, no backslashes,
 *     no "." / ".." / empty segments, valid UTF-8, length limits, no ".git");
 *  2. canonical resolution with realpath(), which also resolves symlinks;
 *  3. containment check against the canonical root using a separator-terminated comparison
 *     (not a bare string prefix);
 *  4. publication policy applied again to the canonical (post-symlink) path.
 */
final class PathGuard
{
    public readonly string $root;
    private IgnoreRules $ignore;

    public function __construct(string $root, ?IgnoreRules $ignore = null)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException('Repository root does not exist');
        }
        $this->root = $real;
        $this->ignore = $ignore ?? IgnoreRules::load($real);
    }

    public function ignoreRules(): IgnoreRules
    {
        return $this->ignore;
    }

    public static function validRelative(string $rel): bool
    {
        if ($rel === '') {
            return true;
        }
        if (strlen($rel) > 1024 || !mb_check_encoding($rel, 'UTF-8')) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $rel) || $rel[0] === '/') {
            return false;
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || strlen($seg) > 255) {
                return false;
            }
            // Windows-style and drive-letter tricks.
            if (preg_match('/^[A-Za-z]:/', $seg) && str_contains($seg, ':')) {
                return false;
            }
        }
        return true;
    }

    /** @return string|null canonical absolute path inside the root, or null */
    public function resolve(string $rel): ?string
    {
        if (!self::validRelative($rel)) {
            return null;
        }
        $real = @realpath($rel === '' ? $this->root : $this->root . '/' . $rel);
        if ($real === false || !$this->contains($real)) {
            return null;
        }
        $isDir = is_dir($real);
        $relReal = $this->relativeOf($real);
        if (($relReal !== '' && $this->ignore->excluded($relReal, $isDir)) || ($rel !== '' && $this->ignore->excluded($rel, $isDir))) {
            return null;
        }
        return $real;
    }

    public function contains(string $realPath): bool
    {
        return $realPath === $this->root || str_starts_with($realPath, $this->root . DIRECTORY_SEPARATOR);
    }

    public function relativeOf(string $realPath): string
    {
        if ($realPath === $this->root) {
            return '';
        }
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($this->root) + 1));
    }

    public function isFile(string $rel): bool
    {
        $p = $this->resolve($rel);
        return $p !== null && is_file($p);
    }

    public function isDir(string $rel): bool
    {
        $p = $this->resolve($rel);
        return $p !== null && is_dir($p);
    }
}
