<?php
declare(strict_types=1);

namespace Wiki\Security;

/**
 * Publication policy: which repository paths are NOT published.
 *
 *  - Always excluded: any path segment named ".git" (case-insensitive).
 *  - Optional <repo root>/.wikiignore: gitignore-like lines (comments with #, trailing / for
 *    directories only, leading / or an inner / anchors to the root, * ? ** globs).
 *    Negation (!) is not supported and is ignored. Max 500 patterns / 64 KiB.
 *  - Other dotfiles/dot-directories are NOT hidden automatically.
 */
final class IgnoreRules
{
    /** @var list<array{regex:string,dirOnly:bool}> */
    private array $rules = [];

    public static function load(string $root): self
    {
        $self = new self();
        $file = $root . '/.wikiignore';
        if (is_file($file) && !is_link($file) && filesize($file) <= 65536) {
            $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];
            foreach (array_slice($lines, 0, 500) as $line) {
                $self->addPattern($line);
            }
        }
        return $self;
    }

    public function addPattern(string $line): void
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === '!') {
            return;
        }
        $dirOnly = str_ends_with($line, '/');
        $line = rtrim($line, '/');
        $anchored = str_starts_with($line, '/') || str_contains(ltrim($line, '/'), '/');
        $line = ltrim($line, '/');
        if ($line === '') {
            return;
        }
        $re = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === '*') {
                if (($line[$i + 1] ?? '') === '*') {
                    $re .= '.*';
                    $i++;
                    if (($line[$i + 1] ?? '') === '/') {
                        $i++;
                        $re .= '(?:/|)'; // "**/" may match zero directories
                    }
                } else {
                    $re .= '[^/]*';
                }
            } elseif ($c === '?') {
                $re .= '[^/]';
            } else {
                $re .= preg_quote($c, '~');
            }
        }
        $regex = $anchored ? '~^' . $re . '$~u' : '~(?:^|/)' . $re . '$~u';
        $this->rules[] = ['regex' => $regex, 'dirOnly' => $dirOnly];
    }

    /** @param string $rel repository-relative path using "/" */
    public function excluded(string $rel, bool $isDir): bool
    {
        $rel = trim($rel, '/');
        if ($rel === '') {
            return false;
        }
        $segments = explode('/', $rel);
        foreach ($segments as $s) {
            if (strcasecmp($s, '.git') === 0) {
                return true;
            }
        }
        if ($this->rules === []) {
            return false;
        }
        // A rule matching any ancestor directory (or the path itself) excludes the path.
        $acc = '';
        foreach ($segments as $i => $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $last = $i === count($segments) - 1;
            foreach ($this->rules as $r) {
                if ($r['dirOnly'] && $last && !$isDir) {
                    continue;
                }
                if (preg_match($r['regex'], $acc) === 1) {
                    return true;
                }
            }
        }
        return false;
    }
}
