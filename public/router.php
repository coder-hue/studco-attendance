<?php
declare(strict_types=1);

// Router for PHP's built-in development server. Apache serves /checkin through
// its DirectoryIndex configuration in production.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if (is_file($file)) {
    return false;
}
if ($path === '/checkin' || $path === '/checkin/') {
    require __DIR__ . '/checkin/index.php';
    return true;
}
if ($path === '/admin' || $path === '/admin/') {
    require __DIR__ . '/admin/index.php';
    return true;
}
if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}
http_response_code(404);
echo 'Not found';
