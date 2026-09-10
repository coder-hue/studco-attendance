<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = db();
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    throw new RuntimeException('Could not read database/schema.sql');
}
$pdo->exec($schema);

$nicknameColumn = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'nickname'")->fetchColumn();
if ((int) $nicknameColumn === 0) {
    $pdo->exec('ALTER TABLE members ADD COLUMN nickname VARCHAR(80) NULL AFTER first_name');
}
$confirmationNicknameColumn = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'confirmation_nickname'")->fetchColumn();
if ((int) $confirmationNicknameColumn === 0) {
    $pdo->exec('ALTER TABLE members ADD COLUMN confirmation_nickname VARCHAR(80) NULL AFTER nickname');
}
$deviceHashColumn = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'checkin_device_hash'")->fetchColumn();
if ((int) $deviceHashColumn === 0) {
    $pdo->exec('ALTER TABLE attendance ADD COLUMN checkin_device_hash CHAR(64) NULL AFTER check_in_time');
}
$eventColumns = [
    'early_public_token' => "ALTER TABLE events ADD COLUMN early_public_token CHAR(32) NULL UNIQUE AFTER public_token",
    'attendance_mode' => "ALTER TABLE events ADD COLUMN attendance_mode VARCHAR(32) NOT NULL DEFAULT 'standard' AFTER early_public_token",
    'early_is_open' => "ALTER TABLE events ADD COLUMN early_is_open TINYINT(1) NOT NULL DEFAULT 0 AFTER is_open",
];
foreach ($eventColumns as $column => $alterSql) {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = ?");
    $exists->execute([$column]);
    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec($alterSql);
    }
}

if (in_array('--migrate-only', $argv ?? [], true)) {
    fwrite(STDOUT, "Database schema is up to date.\n");
    exit(0);
}

$email = trim((string) getenv('ADMIN_EMAIL'));
$password = (string) getenv('ADMIN_PASSWORD');
$name = trim((string) (getenv('ADMIN_NAME') ?: 'Student Council Advisor'));
if ($email === '' || strlen($password) < 12) {
    fwrite(STDERR, "Set ADMIN_EMAIL and an ADMIN_PASSWORD of at least 12 characters in .env.\n");
    exit(1);
}

$stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role)
    VALUES (?, ?, ?, 'advisor')
    ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = 'advisor', active = 1");
$stmt->execute([$name, mb_strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
fwrite(STDOUT, "Database installed. Advisor account ready for {$email}.\n");
