<?php
declare(strict_types=1);

namespace Wiki\Sync;

/** A setup failure whose message is safe to show on the setup page. */
final class SetupException extends \RuntimeException
{
    public function __construct(string $userMessage, public readonly int $httpStatus = 422)
    {
        parent::__construct($userMessage);
    }
}
