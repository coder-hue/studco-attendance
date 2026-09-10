<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    return rtrim(getenv('APP_URL') ?: '', '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function allow_schoology_extension(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === 'https://learn.sowashco.org' || preg_match('#^chrome-extension://[a-z]{32}$#', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Vary: Origin');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Your session expired. Refresh the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = compact('type', 'message');
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ? AND active = 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_staff(string ...$roles): array
{
    $user = current_user();
    if (!$user) {
        redirect('/admin?view=login');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have access to this action.');
    }
    return $user;
}

function audit(string $action, string $entityType, ?int $entityId = null, array $details = []): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
        substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
    ]);
    queue_discord_audit($action, $entityType, $entityId, $details);
}

function audit_safe_details(array $details): array
{
    $safe = [];
    foreach ($details as $key => $value) {
        $label = (string) $key;
        if (preg_match('/password|passphrase|secret|token|csrf|cookie|session|webhook|answer/i', $label)) {
            $safe[$label] = '[redacted]';
            continue;
        }
        if (is_array($value)) {
            $safe[$label] = audit_safe_details($value);
        } elseif (is_scalar($value) || $value === null) {
            $safe[$label] = $value;
        } else {
            $safe[$label] = '[' . gettype($value) . ']';
        }
    }
    return $safe;
}

function build_discord_audit_embed(string $action, string $entityType, ?int $entityId, array $details): array
{
    $user = current_user();
    $actor = $user ? (string) $user['name'] . ' (' . (string) $user['role'] . ')' : (string) ($details['member_name'] ?? 'Public check-in');
    $safeDetails = audit_safe_details($details);
    unset($safeDetails['member_name']);
    $detailText = $safeDetails ? json_encode($safeDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : 'No additional details';
    $detailText = mb_substr((string) $detailText, 0, 1000);
    $isWarning = (bool) preg_match('/failed|invalid|denied|deleted|archived|closed|corrected/i', $action);
    $isSuccess = (bool) preg_match('/created|recorded|present|opened|login|linked|updated|saved/i', $action);
    return [
        'title' => ucwords(str_replace('_', ' ', $action)),
        'color' => $isWarning ? 15158332 : ($isSuccess ? 3066993 : 3447003),
        'fields' => [
            ['name' => 'Actor', 'value' => mb_substr($actor, 0, 256), 'inline' => true],
            ['name' => 'Target', 'value' => mb_substr($entityType . ($entityId !== null ? ' #' . $entityId : ''), 0, 256), 'inline' => true],
            ['name' => 'Details', 'value' => "```json\n" . $detailText . "\n```", 'inline' => false],
        ],
        'footer' => ['text' => 'StuCo Attendance Audit'],
        'timestamp' => gmdate(DATE_ATOM),
    ];
}

function queue_discord_audit(string $action, string $entityType, ?int $entityId, array $details): void
{
    if (trim((string) getenv('DISCORD_AUDIT_WEBHOOK')) === '') {
        return;
    }
    $GLOBALS['stuco_discord_audit_queue'][] = build_discord_audit_embed($action, $entityType, $entityId, $details);
    if (empty($GLOBALS['stuco_discord_audit_shutdown_registered'])) {
        $GLOBALS['stuco_discord_audit_shutdown_registered'] = true;
        register_shutdown_function('flush_discord_audits');
    }
}

function flush_discord_audits(): void
{
    $webhook = trim((string) getenv('DISCORD_AUDIT_WEBHOOK'));
    $queue = $GLOBALS['stuco_discord_audit_queue'] ?? [];
    if ($webhook === '' || !$queue) {
        return;
    }
    foreach (array_chunk($queue, 10) as $embeds) {
        $payload = json_encode(['embeds' => $embeds], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            continue;
        }
        if (function_exists('curl_init')) {
            $curl = curl_init($webhook);
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2]);
            curl_exec($curl);
            continue;
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 2, 'ignore_errors' => true]]);
        @file_get_contents($webhook, false, $context);
    }
}

function normalize_answer(string $value): string
{
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
}

function app_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) && trim($value) !== '' ? $value : $default;
}

function member_id_from_cookie(): ?int
{
    $value = $_COOKIE['member_id'] ?? null;
    return is_string($value) && ctype_digit($value) ? (int) $value : null;
}

function checkin_device_hash(): string
{
    $token = $_COOKIE['checkin_device'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        $_COOKIE['checkin_device'] = $token;
        $isProduction = (getenv('APP_ENV') ?: 'production') === 'production';
        setcookie('checkin_device', $token, ['expires' => time() + 31536000 * 2, 'path' => '/', 'secure' => $isProduction, 'httponly' => true, 'samesite' => 'Lax']);
    }
    return hash_hmac('sha256', $token, (string) (getenv('APP_KEY') ?: 'stuco-attendance-device'));
}

function member_display_first_name(array $member): string
{
    $nickname = trim((string) ($member['nickname'] ?? ''));
    return $nickname !== '' ? $nickname : (string) ($member['first_name'] ?? '');
}

function member_display_name(array $member): string
{
    return trim(member_display_first_name($member) . ' ' . (string) ($member['last_name'] ?? ''));
}

function member_confirmation_first_name(array $member): string
{
    $confirmationNickname = trim((string) ($member['confirmation_nickname'] ?? ''));
    return $confirmationNickname !== '' ? $confirmationNickname : member_display_first_name($member);
}

function member_confirmation_name(array $member): string
{
    return trim(member_confirmation_first_name($member) . ' ' . (string) ($member['last_name'] ?? ''));
}

function event_checkin_mode(array $event): string
{
    $state = (int) ($event['is_open'] ?? 2);
    if ($state === 0) {
        return 'closed';
    }
    if ($state === 1) {
        return 'open';
    }
    return 'scheduled';
}

function event_checkin_is_open(array $event, ?DateTimeImmutable $now = null): bool
{
    if (!empty($event['locked_at']) || !empty($event['finalized_at'])) {
        return false;
    }
    $mode = event_checkin_mode($event);
    if ($mode === 'open') {
        return true;
    }
    if ($mode === 'closed') {
        return false;
    }
    $now ??= new DateTimeImmutable();
    return $now >= new DateTimeImmutable((string) $event['checkin_opens'])
        && $now <= new DateTimeImmutable((string) $event['checkin_closes']);
}

function event_lane_is_open(array $event, string $lane, ?DateTimeImmutable $now = null): bool
{
    if (($event['attendance_mode'] ?? 'standard') !== 'decorations' || $lane !== 'early') {
        return event_checkin_is_open($event, $now);
    }
    if (!empty($event['locked_at']) || !empty($event['finalized_at'])) {
        return false;
    }
    $state = (int) ($event['early_is_open'] ?? 0);
    if ($state === 1) {
        return true;
    }
    if ($state === 0) {
        return false;
    }
    $now ??= new DateTimeImmutable();
    return $now >= new DateTimeImmutable((string) $event['checkin_opens'])
        && $now <= new DateTimeImmutable((string) $event['checkin_closes']);
}

function event_lane_points(array $event, string $lane): int
{
    if (($event['attendance_mode'] ?? 'standard') === 'decorations') {
        return $lane === 'early' ? 6 : 8;
    }
    return 2;
}

function event_is_archived(array $event, ?DateTimeImmutable $today = null): bool
{
    $today ??= new DateTimeImmutable('today');
    $eventDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($event['event_date'] ?? ''));
    return $eventDate instanceof DateTimeImmutable && $eventDate < $today->modify('-7 days');
}
