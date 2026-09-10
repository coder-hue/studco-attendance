<?php
declare(strict_types=1);

function page_start(string $title, string $bodyClass = ''): void
{
    $flashes = consume_flashes();
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#172554">
    <title><?= e($title) ?> · StuCo Attendance</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="<?= e($bodyClass) ?>">
<header class="topbar">
    <a class="brand" href="/"><span class="brand-mark">S</span><span>StuCo Attendance</span></a>
    <?php if (current_user()): ?>
        <a class="top-link" href="/admin">Admin</a>
    <?php endif; ?>
</header>
<main class="shell">
<?php foreach ($flashes as $flash): ?>
    <div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
<?php endforeach; ?>
<?php
}

function page_end(string $script = ''): void
{
    ?></main>
<?php if ($script !== ''): ?><script src="<?= e($script) ?>" defer></script><?php endif; ?>
</body>
</html><?php
}

function status_badge(string $status): string
{
    $label = ucfirst($status);
    return '<span class="badge status-' . e($status) . '">' . e($label) . '</span>';
}
