<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the web front controller and the CLI tools.
 * Registers the PSR-4 autoloader for Wiki\ and loads the dependency autoloader.
 */

const WIKI_VERSION = '1.0.0';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Wiki\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($vendorAutoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php - run `composer install` (see README).\n");
    http_response_code(500);
    exit(1);
}
require_once $vendorAutoload;

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
