<?php
declare(strict_types=1);

require __DIR__ . '/app_paths.php';
require APP_CODE_ROOT . '/bootstrap.php';

header('Cache-Control: no-store');
$action = (string) ($_GET['action'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$payload = json_decode((string) file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : [];

if ($method === 'POST' && $action === 'login') {
    $username = mb_strtolower(trim((string) ($payload['username'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
    $stmt->execute([$username]);
    $account = $stmt->fetch();
    if (!$account || !password_verify($password, (string) $account['password_hash'])) {
        audit('mobile_login_failed','user',null,['username'=>$username]);
        json_response(['error' => 'User or password is incorrect.'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $account['id'];
    audit('mobile_login', 'user', (int) $account['id']);
    json_response(['ok' => true, 'user' => ['id' => (int) $account['id'], 'name' => $account['name'], 'role' => $account['role']]]);
}

$user = current_user();
if (!$user || !in_array($user['role'], ['officer', 'advisor'], true)) {
    json_response(['error' => 'Sign in required.'], 401);
}

if ($method === 'GET' && $action === 'session') {
    json_response(['ok' => true, 'user' => ['id' => (int) $user['id'], 'name' => $user['name'], 'role' => $user['role']]]);
}

if ($method === 'POST' && $action === 'logout') {
    audit('mobile_logout', 'user', (int) $user['id']);
    $_SESSION = [];
    session_destroy();
    json_response(['ok' => true]);
}

if ($method === 'GET' && $action === 'events') {
    $events = db()->query("SELECT e.id,e.public_token,e.early_public_token,e.attendance_mode,e.title,e.event_date,e.start_time,e.end_time,e.checkin_opens,e.checkin_closes,e.is_open,e.early_is_open,e.locked_at,e.finalized_at,
        (SELECT COUNT(*) FROM members m WHERE m.active=1) member_count,
        (SELECT COUNT(*) FROM attendance a WHERE a.event_id=e.id AND a.status='present') present_count
        FROM events e ORDER BY e.event_date DESC,e.id DESC LIMIT 100")->fetchAll();
    json_response(['events' => array_map(static fn(array $event): array => [
        'id' => (int) $event['id'],
        'title' => $event['title'],
        'date' => $event['event_date'],
        'start_time' => substr((string) $event['start_time'], 0, 5),
        'end_time' => substr((string) $event['end_time'], 0, 5),
        'is_open' => event_checkin_is_open($event),
        'early_is_open' => event_lane_is_open($event, 'early'),
        'attendance_mode' => $event['attendance_mode'],
        'checkin_mode' => event_checkin_mode($event),
        'is_archived' => event_is_archived($event),
        'is_finalized' => $event['finalized_at'] !== null,
        'checkin_url' => base_url('/checkin?event='.$event['id'].'&token='.$event['public_token']),
        'early_checkin_url' => $event['early_public_token'] ? base_url('/checkin?event='.$event['id'].'&token='.$event['early_public_token']) : null,
        'member_count' => (int) $event['member_count'],
        'present_count' => (int) $event['present_count'],
    ], $events)]);
}

if ($method === 'POST' && $action === 'create_event') {
    $eventDate = trim((string) ($payload['event_date'] ?? ''));
    $title = mb_substr(trim((string) ($payload['title'] ?? '')), 0, 160);
    $openTime = trim((string) ($payload['open_time'] ?? '07:35'));
    $closeTime = trim((string) ($payload['close_time'] ?? '07:50'));
    $requestedMode = (string)($payload['attendance_mode'] ?? 'standard');
    $attendanceMode = in_array($requestedMode,['standard','decorations','dance_shifts'],true) ? $requestedMode : 'standard';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || !preg_match('/^\d{2}:\d{2}$/', $openTime) || !preg_match('/^\d{2}:\d{2}$/', $closeTime) || $closeTime <= $openTime) {
        json_response(['error' => 'Choose a valid date and check-in window.'], 422);
    }
    if ($title === '') {
        json_response(['error' => 'Enter an event name.'], 422);
    }
    $publicToken = bin2hex(random_bytes(16));
    $earlyToken = $attendanceMode === 'decorations' ? bin2hex(random_bytes(16)) : null;
    $stmt = db()->prepare("INSERT INTO events (public_token,early_public_token,attendance_mode,title,description,location,term,event_date,start_time,end_time,checkin_opens,checkin_closes,max_points,required,question,answer_hash,schoology_assignment,is_open,early_is_open,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$publicToken,$earlyToken,$attendanceMode,$title,null,null,'Attendance',$eventDate,$openTime.':00',$closeTime.':00',$eventDate.' '.$openTime.':00',$eventDate.' '.$closeTime.':00',$attendanceMode==='decorations'?8:2,1,'',password_hash('',PASSWORD_DEFAULT),null,$attendanceMode==='dance_shifts'?1:2,$attendanceMode==='decorations'?2:0,$user['id']]);
    $id = (int) db()->lastInsertId();
    audit('created', 'event', $id, ['source' => 'ios_admin', 'title' => $title, 'event_date' => $eventDate, 'attendance_mode' => $attendanceMode]);
    json_response(['ok' => true, 'created' => true, 'event_id' => $id]);
}

$eventId = filter_var($_GET['event_id'] ?? $payload['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (in_array($action, ['event', 'toggle_event', 'finalize_event', 'update_event', 'delete_event', 'set_attendance'], true) && !$eventId) {
    json_response(['error' => 'Event not found.'], 404);
}

if ($method === 'POST' && $action === 'toggle_event') {
    $state = (string) ($payload['state'] ?? '');
    $states = ['closed' => 0, 'open' => 1, 'scheduled' => 2];
    if (!array_key_exists($state, $states)) {
        json_response(['error' => 'Choose open, closed, or scheduled.'], 422);
    }
    $lane = ($payload['lane'] ?? 'full') === 'early' ? 'early' : 'full';
    $column = $lane === 'early' ? 'early_is_open' : 'is_open';
    db()->prepare("UPDATE events SET {$column} = ? WHERE id = ? AND locked_at IS NULL AND finalized_at IS NULL")->execute([$states[$state], $eventId]);
    audit($state === 'scheduled' ? 'scheduled_checkin' : ($state === 'open' ? 'opened_checkin' : 'closed_checkin'), 'event', (int) $eventId, ['source' => 'ios_admin', 'lane' => $lane]);
}

if ($method === 'POST' && $action === 'finalize_event') {
    db()->prepare('UPDATE events SET is_open=0,early_is_open=0,finalized_at=NOW() WHERE id=?')->execute([$eventId]);
    audit('finalized', 'event', (int) $eventId, ['source' => 'ios_admin']);
}

if ($method === 'POST' && $action === 'update_event') {
    $eventDate = trim((string) ($payload['event_date'] ?? ''));
    $title = mb_substr(trim((string) ($payload['title'] ?? '')), 0, 160);
    $openTime = trim((string) ($payload['open_time'] ?? ''));
    $closeTime = trim((string) ($payload['close_time'] ?? ''));
    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || !preg_match('/^\d{2}:\d{2}$/', $openTime) || !preg_match('/^\d{2}:\d{2}$/', $closeTime) || $closeTime <= $openTime) {
        json_response(['error' => 'Choose a valid date and check-in window.'], 422);
    }
    db()->prepare('UPDATE events SET title=?,event_date=?,start_time=?,end_time=?,checkin_opens=?,checkin_closes=? WHERE id=?')
        ->execute([$title,$eventDate,$openTime.':00',$closeTime.':00',$eventDate.' '.$openTime.':00',$eventDate.' '.$closeTime.':00',$eventId]);
    audit('updated', 'event', (int) $eventId, ['source'=>'ios_admin','title'=>$title,'event_date'=>$eventDate,'open_time'=>$openTime,'close_time'=>$closeTime]);
}

if ($method === 'POST' && $action === 'delete_event') {
    audit('deleted', 'event', (int) $eventId, ['source' => 'ios_admin']);
    db()->prepare('DELETE FROM events WHERE id = ?')->execute([$eventId]);
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'set_attendance') {
    $memberId = filter_var($payload['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = (string) ($payload['status'] ?? '');
    if (!$memberId || !in_array($status, ['present', '6', '8', 'missing'], true)) {
        json_response(['error' => 'Choose a valid member and attendance status.'], 422);
    }
    $member = db()->prepare('SELECT id FROM members WHERE id = ? AND active = 1');
    $member->execute([$memberId]);
    if (!$member->fetchColumn()) {
        json_response(['error' => 'Member not found.'], 404);
    }
    if ($status === 'missing') {
        db()->prepare('DELETE FROM attendance WHERE member_id = ? AND event_id = ?')->execute([$memberId, $eventId]);
    } else {
        $points = $status === 'present' ? 2 : (int) $status;
        $stmt = db()->prepare("INSERT INTO attendance(member_id,event_id,status,points,check_in_time,updated_by) VALUES(?,?,'present',?,NOW(),?) ON DUPLICATE KEY UPDATE status='present',points=VALUES(points),check_in_time=NOW(),updated_by=VALUES(updated_by)");
        $stmt->execute([$memberId, $eventId, $points, $user['id']]);
    }
    audit('attendance_updated', 'attendance', null, ['source' => 'ios_admin', 'event_id' => (int) $eventId, 'member_id' => (int) $memberId, 'status' => $status]);
}

if (($method === 'GET' && $action === 'event') || ($method === 'POST' && in_array($action, ['toggle_event', 'finalize_event', 'update_event', 'set_attendance'], true))) {
    $eventStmt = db()->prepare('SELECT * FROM events WHERE id = ?');
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();
    if (!$event) {
        json_response(['error' => 'Event not found.'], 404);
    }
    $attendanceStmt = db()->prepare("SELECT m.id,CONCAT(COALESCE(NULLIF(m.nickname,''),m.first_name),' ',m.last_name) name,a.check_in_time,a.points,
        CASE WHEN a.status='present' THEN 'present' ELSE 'missing' END status
        FROM members m LEFT JOIN attendance a ON a.member_id=m.id AND a.event_id=?
        WHERE m.active=1 ORDER BY CASE WHEN a.status='present' THEN 0 ELSE 1 END,m.last_name,m.first_name");
    $attendanceStmt->execute([$eventId]);
    $roster = $attendanceStmt->fetchAll();
    $present = count(array_filter($roster, static fn(array $row): bool => $row['status'] === 'present'));
    json_response([
        'event' => [
            'id' => (int) $event['id'], 'title' => $event['title'], 'date' => $event['event_date'],
            'start_time' => substr((string) $event['start_time'], 0, 5), 'end_time' => substr((string) $event['end_time'], 0, 5),
            'is_open' => event_checkin_is_open($event), 'early_is_open' => event_lane_is_open($event,'early'), 'attendance_mode' => $event['attendance_mode'], 'checkin_mode' => event_checkin_mode($event), 'is_archived' => event_is_archived($event), 'is_finalized' => $event['finalized_at'] !== null,
            'checkin_url' => base_url('/checkin?event='.$event['id'].'&token='.$event['public_token']),
            'early_checkin_url' => $event['early_public_token'] ? base_url('/checkin?event='.$event['id'].'&token='.$event['early_public_token']) : null,
            'member_count' => count($roster), 'present_count' => $present,
        ],
        'roster' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'name' => $row['name'], 'status' => $row['status'],
            'points' => $row['points'] !== null ? (int)$row['points'] : null,
            'check_in_time' => $row['check_in_time'] ? (new DateTimeImmutable((string) $row['check_in_time']))->format(DATE_ATOM) : null,
        ], $roster),
    ]);
}

json_response(['error' => 'Not found.'], 404);
