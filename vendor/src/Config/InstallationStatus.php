<?php
declare(strict_types=1);

namespace Wiki\Config;

enum InstallationStatus: string
{
    case NotInstalled = 'not_installed';
    case Installed = 'installed';
    /** Was installed once, but the immutable configuration is missing or unreadable. */
    case Corrupt = 'corrupt';
}
