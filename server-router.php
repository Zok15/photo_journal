<?php

declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$publicPath = __DIR__.'/public';
$requested = realpath($publicPath.$uri);

// Let the built-in server serve existing public assets directly.
if ($requested !== false && str_starts_with($requested, $publicPath) && is_file($requested)) {
    return false;
}

require $publicPath.'/index.php';

