<?php
declare(strict_types=1);

namespace Wiki\Sync;

use Wiki\Config\Settings;
use Wiki\Security\ValidationException;

/**
 * Validates a candidate checkout BEFORE it can become active, and neutralises hazards in it:
 * symlinks that escape the tree are deleted, file modes are normalised (no execute bits).
 */
final class CandidateValidator
{
    public function __construct(private Settings $settings)
    {
    }

    /** @return array{files:int,markdown:int,removed_symlinks:int} */
    public function validate(string $dir): array
    {
        $root = realpath($dir);
        if ($root === false || !is_dir($root)) {
            throw new ValidationException('Candidate checkout is missing.');
        }
        $stats = ['files' => 0, 'markdown' => 0, 'removed_symlinks' => 0];
        $this->walk($root, $root, 0, $stats);
        if ($stats['markdown'] === 0) {
            throw new ValidationException('The repository contains no Markdown (.md) files.');
        }
        return $stats;
    }

    /** @param array{files:int,markdown:int,removed_symlinks:int} $stats */
    private function walk(string $root, string $dir, int $depth, array &$stats): void
    {
        if ($depth > $this->settings->int('max_directory_depth')) {
            throw new ValidationException('The repository is nested too deeply.');
        }
        @chmod($dir, 0755);
        $items = @scandir($dir);
        if ($items === false) {
            throw new ValidationException('The checkout could not be read.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                $target = @realpath($path);
                if ($target === false || !($target === $root || str_starts_with($target, $root . '/'))) {
                    @unlink($path);                      // dangling or escaping symlink
                    $stats['removed_symlinks']++;
                }
                $stats['files']++;
                continue;
            }
            if (is_dir($path)) {
                if (strcasecmp($item, '.git') === 0) {
                    continue; // never published; left alone (git does not check these out anyway)
                }
                $this->walk($root, $path, $depth + 1, $stats);
                continue;
            }
            $stats['files']++;
            if ($stats['files'] > $this->settings->int('max_files')) {
                throw new ValidationException('The repository contains too many files.');
            }
            @chmod($path, 0644);
            if (str_ends_with($item, '.md')) {
                $stats['markdown']++;
            }
        }
    }
}
