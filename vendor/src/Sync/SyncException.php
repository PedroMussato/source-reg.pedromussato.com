<?php
declare(strict_types=1);

namespace Wiki\Sync;

/** category is machine readable; the message is safe for visitors and the state file. */
final class SyncException extends \RuntimeException
{
    public function __construct(public readonly string $category, string $safeMessage, public readonly string $detail = '')
    {
        parent::__construct($safeMessage);
    }
}
