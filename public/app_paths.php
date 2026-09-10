<?php
declare(strict_types=1);

// Supports both deployment layouts:
// 1) project/public is the domain document root (recommended)
// 2) the contents of public are uploaded into the domain root, with app/ beside them
$appRoot = is_dir(__DIR__ . '/../app') ? __DIR__ . '/../app' : __DIR__ . '/app';
if (!is_file($appRoot . '/bootstrap.php')) {
    http_response_code(500);
    exit('Application files are missing. Upload the app folder beside the public files.');
}
define('APP_CODE_ROOT', $appRoot);
