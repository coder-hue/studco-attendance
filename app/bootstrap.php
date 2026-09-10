<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

// The app supports hosts still running PHP 7.4 as well as PHP 8+.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key !== '' && getenv($key) === false) {
            $value = trim($value, "\"'");
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

// Prefer the project root, then support hosts that keep app/ outside the
// domain's document root and place .env inside the site folder.
$envPaths = [APP_ROOT . '/.env', APP_ROOT . '/.env.discord'];
if (PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $envPaths[] = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/.env';
}
foreach (array_unique($envPaths) as $envPath) {
    load_env($envPath);
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Chicago');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('stuco_attendance');
    $isProduction = (getenv('APP_ENV') ?: 'production') === 'production';
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    // The Schoology extension fetches the grade batch from a different site.
    // Production therefore needs a secure cross-site session cookie.
    $sameSite = getenv('SESSION_SAMESITE') ?: (($isProduction || $isHttps) ? 'None' : 'Lax');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isProduction || $isHttps || $sameSite === 'None',
        'samesite' => $sameSite,
        'path' => '/',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
