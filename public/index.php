<?php
declare(strict_types=1);

// Front controller. Only this directory is the web document root.

// PHP built-in server (development only): let it serve real static files itself.
if (PHP_SAPI === 'cli-server') {
    $urlPath = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    $file = $urlPath === '' || str_contains($urlPath, "\0") ? false : realpath(__DIR__ . $urlPath);
    if (
        $file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
        && basename($file) !== 'index.php' && $file[strlen(__DIR__) + 1] !== '.' && !str_contains(substr($file, strlen(__DIR__)), '/.')
    ) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

(new Wiki\Http\App(new Wiki\Application\Services()))->handle();
