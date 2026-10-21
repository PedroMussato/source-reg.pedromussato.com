<?php
declare(strict_types=1);

namespace Wiki\Git;

final class GitException extends \RuntimeException
{
    public function __construct(public readonly string $category, string $safeMessage, public readonly string $detail = '')
    {
        parent::__construct($safeMessage);
    }

    public static function safeMessageFor(string $category): string
    {
        return match ($category) {
            'timeout' => 'The Git operation timed out.',
            'dns' => 'The repository host could not be resolved.',
            'network' => 'The repository server could not be reached.',
            'auth' => 'Authentication with the repository failed.',
            'not_found' => 'The repository was not found or is not accessible.',
            'missing_ref' => 'The configured branch does not exist in the repository.',
            'git_missing' => 'The Git executable is not available on the server.',
            'disk_space' => 'The server ran out of disk space.',
            'permission' => 'The server does not have permission to write required files.',
            'invalid_checkout' => 'The repository checkout was invalid.',
            default => 'The Git operation failed.',
        };
    }
}
